<?php
/**
 * Listens for `messageistic_trigger` actions and runs matching automations.
 *
 * Trigger context shape: [ 'contact_id' => int, 'extras' => array, ...arbitrary trigger data ].
 * Conditions and actions are simple JSON shapes — kept intentionally small.
 *
 * @package Messageistic\Automations
 */

namespace Messageistic\Automations;

use Messageistic\Database\Automation_Repository;
use Messageistic\Database\Automation_Step_Repository;
use Messageistic\Database\Conversation_Repository;
use Messageistic\Database\Contact_Repository;
use Messageistic\Database\Template_Repository;
use Messageistic\Helpers\Logger;
use Messageistic\Messaging\SMS_Service;
use Messageistic\Messaging\Template_Parser;

defined( 'ABSPATH' ) || exit;

final class Automation_Manager {

    public const RUN_HOOK = 'messageistic_run_automation';
    public const STEP_HOOK = 'messageistic_run_automation_step';

    public static function register(): void {
        add_action( 'messageistic_trigger', [ self::class, 'on_trigger' ], 10, 2 );
        add_action( self::RUN_HOOK, [ self::class, 'execute' ], 10, 2 );
        add_action( self::STEP_HOOK, [ self::class, 'execute_step' ], 10, 1 );
    }

    public static function on_trigger( string $trigger_key, array $context ): void {
        $automations = Automation_Repository::active_for_trigger( $trigger_key );
        foreach ( $automations as $automation ) {
            if ( ! self::conditions_pass( $automation, $context ) ) {
                continue;
            }
            $steps = Automation_Step_Repository::for_automation( (int) $automation['id'] );
            if ( $steps ) {
                $contact_id = (int) ( $context['contact_id'] ?? 0 );
                $contact = $contact_id ? Contact_Repository::find( $contact_id ) : null;
                if ( $contact && ( empty( $automation['location_id'] ) || (int) $automation['location_id'] === (int) ( $contact['location_id'] ?? 0 ) ) ) {
                    $context['_automation_steps'] = array_map( static function ( array $step ): array {
                        $step['config'] = is_array( $step['config'] ?? null ) ? $step['config'] : ( json_decode( (string) ( $step['config'] ?? '{}' ), true ) ?: [] );
                        return $step;
                    }, $steps );
                    $run_id = Automation_Step_Repository::start_run( (int) $automation['id'], (int) ( $automation['version'] ?? 1 ), $contact_id, ! empty( $contact['location_id'] ) ? (int) $contact['location_id'] : null, $context );
                    self::schedule_step( $run_id );
                }
                continue;
            }

            $delay = (int) $automation['delay_seconds'];
            if ( $delay > 0 ) {
                if ( function_exists( 'as_schedule_single_action' ) ) {
                    as_schedule_single_action( time() + $delay, self::RUN_HOOK, [ (int) $automation['id'], $context ], 'messageistic' );
                } else {
                    wp_schedule_single_event( time() + $delay, self::RUN_HOOK, [ (int) $automation['id'], $context ] );
                }
                continue;
            }
            self::execute( (int) $automation['id'], $context );
        }
    }

    public static function execute( int $automation_id, array $context ): void {
        $automation = Automation_Repository::find( $automation_id );
        if ( ! $automation || empty( $automation['is_active'] ) ) {
            return;
        }
        if ( ! self::conditions_pass( $automation, $context ) ) {
            return;
        }
        $contact_id = (int) ( $context['contact_id'] ?? 0 );
        if ( ! $contact_id ) {
            return;
        }
        $contact = Contact_Repository::find( $contact_id );
        if ( ! $contact ) {
            return;
        }

        $body = '';
        if ( ! empty( $automation['template_id'] ) ) {
            $template = Template_Repository::find( (int) $automation['template_id'] );
            if ( $template ) {
                $body = (string) $template['body'];
            }
        }
        if ( '' === $body ) {
            $actions = json_decode( (string) $automation['actions'], true );
            $body    = (string) ( $actions['body'] ?? '' );
        }
        if ( '' === $body ) {
            return;
        }

        $rendered = Template_Parser::render( $body, $contact, (array) ( $context['extras'] ?? [] ) );
        $service  = new SMS_Service();
        $actions  = json_decode( (string) $automation['actions'], true );
        $result   = $service->send( [ 'contact_id' => $contact_id, 'body' => $rendered, 'automation_id' => $automation_id, 'template_id' => (int) ( $automation['template_id'] ?? 0 ), 'template_extras' => (array) ( $context['extras'] ?? [] ), 'classification' => $actions['classification'] ?? 'transactional', 'idempotency_key' => 'automation:' . $automation_id . ':contact:' . $contact_id . ':event:' . (string) ( $context['event_id'] ?? md5( wp_json_encode( $context ) ) ) ] );

        Automation_Repository::bump_run( $automation_id );

        if ( is_wp_error( $result ) ) {
            Logger::log( 'automation.failed', [
                'level'         => 'error',
                'contact_id'    => $contact_id,
                'error_message' => $result->get_error_message(),
                'context'       => [ 'automation_id' => $automation_id ],
            ] );
            return;
        }
        Logger::log( 'automation.ran', [
            'contact_id' => $contact_id,
            'context'    => [ 'automation_id' => $automation_id ],
        ] );
    }

    public static function execute_step( int $run_id ): void {
        $run = Automation_Step_Repository::find_run( $run_id );
        if ( ! $run || 'running' !== $run['status'] ) {
            return;
        }
        $context = json_decode( (string) $run['context'], true );
        $context = is_array( $context ) ? $context : [];
        $steps = ! empty( $context['_automation_steps'] ) && is_array( $context['_automation_steps'] )
            ? $context['_automation_steps']
            : Automation_Step_Repository::for_automation( (int) $run['automation_id'] );
        $index = (int) $run['current_step'];
        if ( ! isset( $steps[ $index ] ) ) {
            Automation_Step_Repository::advance( $run_id, $index, 'completed' );
            Automation_Repository::bump_run( (int) $run['automation_id'] );
            return;
        }
        $step = $steps[ $index ];
        $config = json_decode( (string) $step['config'], true );
        $contact = Contact_Repository::find( (int) $run['contact_id'] );
        if ( ! $contact ) {
            Automation_Step_Repository::advance( $run_id, $index, 'failed' );
            return;
        }
        if ( 'wait' === $step['step_type'] ) {
            Automation_Step_Repository::advance( $run_id, $index + 1 );
            self::schedule_step( $run_id, max( 1, (int) $step['delay_seconds'] ) );
            return;
        }
        if ( 'send_sms' === $step['step_type'] ) {
            $template = Template_Repository::find( (int) $step['template_id'] );
            if ( ! $template ) {
                Automation_Step_Repository::advance( $run_id, $index, 'failed' );
                return;
            }
            $result = ( new SMS_Service() )->send( [
                'contact_id' => (int) $run['contact_id'],
                'location_id' => (int) ( $run['location_id'] ?? 0 ),
                'template_id' => (int) $step['template_id'],
                'template_extras' => (array) ( $context['extras'] ?? [] ),
                'classification' => (string) $template['classification'],
                'automation_id' => (int) $run['automation_id'],
                'idempotency_key' => 'journey:' . $run_id . ':step:' . $index,
            ] );
            if ( is_wp_error( $result ) ) {
                Automation_Step_Repository::advance( $run_id, $index, 'failed' );
                Logger::log( 'automation.step_failed', [ 'level' => 'error', 'contact_id' => (int) $run['contact_id'], 'error_message' => $result->get_error_message(), 'context' => [ 'run_id' => $run_id, 'step' => $index ] ] );
                return;
            }
        } elseif ( 'add_tag' === $step['step_type'] ) {
            $tags = array_filter( array_map( 'trim', explode( ',', (string) $contact['tags'] ) ) );
            $tags[] = sanitize_text_field( (string) ( $config['tag'] ?? '' ) );
            Contact_Repository::update( (int) $contact['id'], [ 'tags' => implode( ',', array_unique( array_filter( $tags ) ) ) ] );
        } elseif ( 'assign_inbox' === $step['step_type'] ) {
            $conversation_id = Conversation_Repository::get_or_create( (int) $contact['id'] );
            Conversation_Repository::update_workflow( $conversation_id, [ 'assigned_user_id' => (int) ( $config['user_id'] ?? 0 ), 'priority' => sanitize_key( (string) ( $config['priority'] ?? 'normal' ) ) ] );
        } elseif ( 'condition' === $step['step_type'] ) {
            if ( 'has_tag' === ( $config['condition'] ?? '' ) && false === strpos( (string) $contact['tags'], (string) ( $config['value'] ?? '' ) ) ) {
                Automation_Step_Repository::advance( $run_id, $index, 'completed' );
                return;
            }
        }
        Automation_Step_Repository::advance( $run_id, $index + 1 );
        self::schedule_step( $run_id, max( 0, (int) $step['delay_seconds'] ) );
    }

    private static function schedule_step( int $run_id, int $delay = 0 ): void {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + $delay, self::STEP_HOOK, [ $run_id ], 'messageistic' );
        } else {
            wp_schedule_single_event( time() + $delay, self::STEP_HOOK, [ $run_id ] );
        }
    }

    /**
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $context
     */
    private static function conditions_pass( array $automation, array $context ): bool {
        $conditions = json_decode( (string) $automation['conditions'], true );
        if ( ! is_array( $conditions ) || empty( $conditions ) ) {
            return true;
        }
        $contact_id = (int) ( $context['contact_id'] ?? 0 );
        $contact    = $contact_id ? Contact_Repository::find( $contact_id ) : null;

        foreach ( $conditions as $cond ) {
            switch ( $cond['kind'] ?? '' ) {
                case 'has_consent':
                    if ( ! $contact || empty( $contact['sms_consent'] ) ) {
                        return false;
                    }
                    break;
                case 'not_opted_out':
                    if ( ! $contact || ! empty( $contact['opt_out'] ) ) {
                        return false;
                    }
                    break;
                case 'customer_type':
                    if ( ! $contact || (string) $contact['customer_type'] !== (string) ( $cond['value'] ?? '' ) ) {
                        return false;
                    }
                    break;
                case 'has_tag':
                    if ( ! $contact || false === strpos( (string) $contact['tags'], (string) ( $cond['value'] ?? '' ) ) ) {
                        return false;
                    }
                    break;
                case 'context_equals':
                    $field = sanitize_key( (string) ( $cond['field'] ?? '' ) );
                    $actual = $context[ $field ] ?? ( $context['extras'][ $field ] ?? null );
                    if ( (string) $actual !== (string) ( $cond['value'] ?? '' ) ) {
                        return false;
                    }
                    break;
            }
        }
        return true;
    }
}
