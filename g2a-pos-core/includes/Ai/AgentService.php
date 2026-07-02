<?php

namespace G2A\POS\Ai;

use G2A\POS\Database\AiAuditRepository;
use G2A\POS\Database\AiConversationRepository;
use G2A\POS\Database\AiPendingActionRepository;

/**
 * Top-level orchestration. One `respond()` call per user turn:
 *
 *   1. Pull conversation history.
 *   2. Pull RAG citations relevant to the new user message.
 *   3. Call the LLM via Gateway with the tool registry exposed.
 *   4. For each requested tool call:
 *        - silent  → execute, append result
 *        - preview → execute (read-only) + return preview to UI
 *        - confirm → enqueue PendingAction, do NOT execute yet
 *   5. Persist the assistant message + tool call rows + audit row.
 */
final class AgentService {

	public static function respond( int $conversation_id, int $user_id, string $user_message ): array {
		$conv_repo = new AiConversationRepository();
		$audit     = new AiAuditRepository();

		$conv_repo->add_message(
			$conversation_id,
			array(
				'role'    => 'user',
				'content' => $user_message,
			)
		);
		$audit->record( 'user_message', array( 'text' => $user_message ), $conversation_id );

		$citations     = BrainService::retrieve( $user_message, 4 );
		$context_block = '';
		if ( $citations ) {
			$bits = array();
			foreach ( $citations as $c ) {
				$excerpt = mb_substr( (string) $c['text_content'], 0, 600 );
				$bits[]  = "[{$c['source_label']}] {$excerpt}";
			}
			$context_block = "\n\nKnowledge-base context (cite if used):\n" . implode( "\n\n", $bits );
		}

		$history  = $conv_repo->messages( $conversation_id, 20 );
		$messages = array(
			array(
				'role'    => 'system',
				'content' => self::system_prompt( $context_block ),
			),
		);
		foreach ( $history as $h ) {
			$messages[] = array(
				'role'    => $h['role'],
				'content' => (string) $h['content'],
			);
		}

		$tools    = ToolRegistry::specs();
		$response = Gateway::chat( $messages, $tools );

		// Surface a failed LLM call to the operator instead of an empty bubble:
		// put the real reason (bad model/key/credits, see Gateway) into the
		// assistant message and the response envelope.
		$llm_ok  = ! empty( $response['ok'] );
		$llm_err = $llm_ok ? null : (string) ( $response['error'] ?? 'agent_error' );
		$content = (string) ( $response['content'] ?? '' );
		if ( ! $llm_ok && $content === '' ) {
			$content = '⚠️ The AI service returned an error: ' . $llm_err;
		}

		$assistant_msg    = array(
			'role'              => 'assistant',
			'content'           => $content,
			'tool_calls'        => $response['tool_calls'] ?? array(),
			'citations'         => $citations,
			'prompt_tokens'     => $response['prompt_tokens'] ?? null,
			'completion_tokens' => $response['completion_tokens'] ?? null,
			'latency_ms'        => $response['latency_ms'] ?? null,
		);
		$assistant_msg_id = $conv_repo->add_message( $conversation_id, $assistant_msg );

		$tool_outcomes = array();
		$pending       = array();
		foreach ( ( $response['tool_calls'] ?? array() ) as $call ) {
			$name     = (string) ( $call['function']['name'] ?? '' );
			$args_raw = $call['function']['arguments'] ?? '{}';
			$args     = is_array( $args_raw ) ? $args_raw : ( json_decode( (string) $args_raw, true ) ?: array() );
			if ( ! $name || ! ToolRegistry::has( $name ) ) {
				$tool_outcomes[] = array(
					'name'  => $name,
					'ok'    => false,
					'error' => 'unknown_tool',
				);
				continue;
			}
			$policy       = ActionPolicy::for( $name );
			$tool_call_id = $conv_repo->record_tool_call(
				$conversation_id,
				$assistant_msg_id,
				array(
					'tool_name'             => $name,
					'arguments'             => $args,
					'status'                => $policy === ActionPolicy::CONFIRM ? 'awaiting_confirmation' : 'running',
					'requires_confirmation' => $policy === ActionPolicy::CONFIRM ? 1 : 0,
				)
			);

			if ( $policy === ActionPolicy::CONFIRM ) {
				$preview = array( 'summary' => self::preview_for( $name, $args ) );
				$pid     = ( new AiPendingActionRepository() )->enqueue(
					$conversation_id,
					$tool_call_id,
					$name,
					$args,
					$preview,
					$user_id
				);
				$audit->record(
					'tool_pending',
					array(
						'name'       => $name,
						'args'       => $args,
						'pending_id' => $pid,
					),
					$conversation_id,
					$name
				);
				$pending[] = array(
					'id'        => $pid,
					'tool_name' => $name,
					'preview'   => $preview['summary'],
					'arguments' => $args,
				);
				continue;
			}

			$started = microtime( true );
			$result  = ToolRegistry::execute( $name, $args );
			$latency = (int) ( ( microtime( true ) - $started ) * 1000 );
			$conv_repo->update_tool_call(
				$tool_call_id,
				array(
					'result'        => $result,
					'status'        => ! empty( $result['ok'] ) ? 'completed' : 'failed',
					'error_message' => $result['error'] ?? null,
					'latency_ms'    => $latency,
				)
			);
			$audit->record(
				'tool_executed',
				array(
					'name'       => $name,
					'ok'         => ! empty( $result['ok'] ),
					'latency_ms' => $latency,
				),
				$conversation_id,
				$name
			);
			$tool_outcomes[] = array(
				'name'   => $name,
				'ok'     => ! empty( $result['ok'] ),
				'result' => $result,
			);
		}

		return array(
			'message_id'      => $assistant_msg_id,
			'ok'              => $llm_ok,
			'error'           => $llm_err,
			'content'         => $content,
			'tool_outcomes'   => $tool_outcomes,
			'pending_actions' => $pending,
			'citations'       => $citations,
			'latency_ms'      => $response['latency_ms'] ?? null,
			'model'           => $response['model'] ?? null,
		);
	}

	public static function confirm_action( int $pending_id, string $decision, int $actor_id ): array {
		$repo  = new AiPendingActionRepository();
		$audit = new AiAuditRepository();
		$row   = $repo->find( $pending_id );
		if ( ! $row ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}
		if ( $row['status'] !== 'pending' ) {
			return array(
				'ok'    => false,
				'error' => 'already_decided',
			);
		}

		$ok = $repo->decide( $pending_id, $decision, $actor_id );
		if ( ! $ok ) {
			return array(
				'ok'    => false,
				'error' => 'decide_failed',
			);
		}

		$conv_repo = new AiConversationRepository();
		if ( $decision !== 'approve' ) {
			$conv_repo->update_tool_call(
				(int) $row['tool_call_id'],
				array(
					'status'    => 'denied',
					'denied_at' => current_time( 'mysql' ),
				)
			);
			$audit->record(
				'tool_denied',
				array(
					'pending_id' => $pending_id,
					'tool'       => $row['tool_name'],
				),
				(int) $row['conversation_id'],
				(string) $row['tool_name']
			);
			return array(
				'ok'     => true,
				'status' => 'denied',
			);
		}

		$started = microtime( true );
		$result  = ToolRegistry::execute( (string) $row['tool_name'], (array) $row['arguments'] );
		$latency = (int) ( ( microtime( true ) - $started ) * 1000 );
		$conv_repo->update_tool_call(
			(int) $row['tool_call_id'],
			array(
				'result'        => $result,
				'status'        => ! empty( $result['ok'] ) ? 'completed' : 'failed',
				'confirmed_by'  => $actor_id,
				'confirmed_at'  => current_time( 'mysql' ),
				'error_message' => $result['error'] ?? null,
				'latency_ms'    => $latency,
			)
		);
		$audit->record(
			'tool_approved_and_executed',
			array(
				'pending_id' => $pending_id,
				'tool'       => $row['tool_name'],
				'ok'         => ! empty( $result['ok'] ),
				'latency_ms' => $latency,
			),
			(int) $row['conversation_id'],
			(string) $row['tool_name']
		);
		return array(
			'ok'     => true,
			'status' => 'approved',
			'result' => $result,
		);
	}

	private static function system_prompt( string $context_block ): string {
		return 'You are G2A POS Agent, an in-store assistant for a US firearms dealer. ' .
				'Be concise. NEVER guess prices, stock, customer info, or compliance rules — ' .
				'always call a tool. For any action that emails a customer, voids a sale, ' .
				'modifies the bound book, or submits NICS, the system will show a confirmation ' .
				"popup before firing — you don't need to ask the user 'are you sure' yourself. " .
				'When you use information from the knowledge base, cite it inline like [source_label]. ' .
				'If a question is ambiguous, ask one short clarifying question.' .
				$context_block;
	}

	private static function preview_for( string $name, array $args ): string {
		switch ( $name ) {
			case 'cart.email_invoice':
				return 'Send invoice ' . ( $args['order_id'] ?? '?' ) . ' to ' . ( $args['to'] ?? '?' );
			case 'cart.create_invoice':
				return 'Render PDF invoice for order #' . ( $args['order_id'] ?? '?' );
			case 'compliance.submit_nics':
				return 'Submit NICS for 4473 #' . ( $args['form_id'] ?? '?' );
			default:
				return $name . '(' . wp_json_encode( $args ) . ')';
		}
	}
}
