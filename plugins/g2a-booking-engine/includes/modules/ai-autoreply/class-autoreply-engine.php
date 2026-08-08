<?php
/**
 * AI Auto-Reply Engine.
 *
 * Provides:
 *   - generate_draft( $customer_message, $context ) → AI-generated reply
 *   - REST endpoint POST /ai/draft for the React dashboard / admin AJAX
 *   - Knowledge base storage in option g2ab_ai_kb (FAQ-style entries)
 *   - System prompt template that injects business context + KB
 *
 * @package G2AB\Modules\AIAutoReply
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_AutoReply_Engine {

	const OPTION_KB           = 'g2ab_ai_knowledge_base';
	const OPTION_SYSTEM_PROMPT = 'g2ab_ai_system_prompt';
	const OPTION_AUTO_SEND     = 'g2ab_ai_auto_send';
	const OPTION_TONE          = 'g2ab_ai_tone';

	private $client;

	public function __construct() {
		$this->client = new G2AB_OpenRouter_Client();
	}

	public function client() { return $this->client; }

	/**
	 * Generate a reply draft from a customer message.
	 *
	 * @param string $customer_message The incoming message.
	 * @param array  $context Optional booking context.
	 * @return string|WP_Error
	 */
	public function generate_draft( $customer_message, $context = array() ) {
		if ( empty( $customer_message ) ) return new WP_Error( 'empty', 'Customer message is empty.' );

		$system = $this->build_system_prompt( $context );
		$messages = array(
			array( 'role' => 'user', 'content' => $customer_message ),
		);

		return $this->client->chat( $messages, array(
			'system'      => $system,
			'temperature' => 0.4,
			'max_tokens'  => 500,
		) );
	}

	/**
	 * Build the system prompt with business identity + KB + tone.
	 */
	public function build_system_prompt( $context = array() ) {
		$biz_name  = g2ab_business_name();
		$biz_addr  = g2ab_business_address();
		$biz_phone = g2ab_business_phone();
		$biz_hours = get_option( 'g2ab_business_hours_summary', 'Mon-Sat 9 AM - 9 PM, Sun 10 AM - 6 PM' );

		$tone = get_option( self::OPTION_TONE, 'professional_friendly' );
		$tone_instruction = $this->tone_instructions()[ $tone ] ?? '';

		$kb = $this->get_knowledge_base();
		$kb_section = '';
		if ( ! empty( $kb ) ) {
			$kb_section = "\n\nKNOWLEDGE BASE (use these facts to answer questions):\n";
			foreach ( $kb as $i => $item ) {
				$kb_section .= sprintf( "%d. Q: %s\n   A: %s\n", $i + 1, $item['question'], $item['answer'] );
			}
		}

		$custom = (string) get_option( self::OPTION_SYSTEM_PROMPT, '' );

		$prompt = "You are a customer support assistant for {$biz_name}, a firearms range and retail business located at {$biz_addr}. Phone: {$biz_phone}. Hours: {$biz_hours}.\n\n";
		$prompt .= "Your job: write a clear, helpful reply to the customer's message below. Be accurate, safety-conscious, and concise. {$tone_instruction}\n\n";
		$prompt .= "Rules:\n";
		$prompt .= "- Never invent prices, hours, or policies that aren't in the knowledge base.\n";
		$prompt .= "- If the customer asks something you can't confirm, say you'll check with the team and follow up.\n";
		$prompt .= "- Sign off as the {$biz_name} team — never claim to be a specific person.\n";
		$prompt .= "- Output the email body only. No subject line. No salutation block beyond a one-line greeting.\n";
		$prompt .= $kb_section;

		if ( ! empty( $custom ) ) {
			$prompt .= "\n\nADDITIONAL INSTRUCTIONS FROM ADMIN:\n" . $custom;
		}

		return $prompt;
	}

	private function tone_instructions() {
		return array(
			'professional_friendly' => 'Tone: professional but warm. Like a senior staff member who knows the regulars.',
			'tactical_brief'        => 'Tone: tactical, brief, no fluff. Treat customers like experienced shooters.',
			'casual_friendly'       => 'Tone: casual and friendly. Use contractions. Keep it conversational.',
			'formal'                => 'Tone: formal and polite. Use full sentences. No contractions.',
		);
	}

	public function tone_options() { return $this->tone_instructions(); }

	/**
	 * Knowledge base CRUD — stored as array of {question, answer} entries.
	 */
	public function get_knowledge_base() {
		$kb = get_option( self::OPTION_KB, array() );
		return is_array( $kb ) ? $kb : array();
	}

	public function save_knowledge_base( $entries ) {
		$clean = array();
		foreach ( (array) $entries as $entry ) {
			$q = isset( $entry['question'] ) ? sanitize_text_field( $entry['question'] ) : '';
			$a = isset( $entry['answer'] ) ? wp_kses_post( $entry['answer'] ) : '';
			if ( $q && $a ) $clean[] = array( 'question' => $q, 'answer' => $a );
		}
		update_option( self::OPTION_KB, $clean );
	}

	public function default_kb() {
		return array(
			array(
				'question' => 'What ID do I need to bring?',
				'answer'   => 'Government-issued photo ID. For new shooters, also bring proof of address. For minors, a parent or guardian must be present with their own ID.',
			),
			array(
				'question' => 'Can I rent a firearm?',
				'answer'   => 'Yes, we have a full rental selection from .22LR to .308. Rental fees are charged at the counter and are not part of online booking. You must be at least 21 to rent a handgun.',
			),
			array(
				'question' => 'Do I need to bring my own ammo?',
				'answer'   => 'You can bring your own factory ammunition (no reloads). We also sell ammo at competitive prices on-site. If you are renting, ammunition must be purchased from us.',
			),
			array(
				'question' => 'What is your cancellation policy?',
				'answer'   => 'Free cancellation up to 24 hours before your reservation. Cancellations within 24 hours forfeit the deposit. No-shows are charged the full amount.',
			),
			array(
				'question' => 'Do you offer CCW classes?',
				'answer'   => 'Yes, we run Arizona CCW certification classes monthly. The course is 8 hours including range time and is required for an Arizona CCW permit. See our Training page for the schedule.',
			),
		);
	}
}
