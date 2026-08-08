<?php
require __DIR__ . '/bootstrap.php';

use Messageistic\Compliance\Message_Classification;
use Messageistic\Providers\SMSGate_Provider;
use Messageistic\Compliance\Provider_Eligibility;
use Messageistic\Compliance\Pilot_Policy;
use Messageistic\Compliance\Promotion_Policy;

$pilot_settings = [
    'pilot_mode_enabled'       => 1,
    'business_name'            => 'Pilot Business',
    'pilot_location_name'      => 'Main Store',
    'pilot_location_state'     => 'TX',
    'pilot_country'            => 'US',
    'pilot_provider'           => 'ottertext',
    'pilot_sender'             => '+15551234567',
    'pilot_approval_reference' => 'approval-123',
    'pilot_daily_limit'        => 100,
];
$pilot_context = [
    'classification'    => 'transactional',
    'provider'          => 'ottertext',
    'sender'            => '+15551234567',
    'template_id'       => 10,
    'template_approved' => true,
    'provider_settings' => [],
];

$checks = [
    Message_Classification::normalize( 'unknown' ) === 'transactional',
    Message_Classification::requires_marketing_consent( 'marketing' ),
    ! Message_Classification::requires_marketing_consent( 'transactional' ),
    Provider_Eligibility::check( 'ottertext', 'transactional', [ 'industry' => 'firearms' ] ) instanceof WP_Error,
    Provider_Eligibility::check( 'twilio', 'transactional', [ 'industry' => 'firearms', 'approved_firearm_providers' => 'twilio' ] ) instanceof WP_Error,
    true === Provider_Eligibility::check( 'ottertext', 'transactional', [ 'industry' => 'firearms', 'approved_firearm_providers' => 'ottertext' ] ),
    true === Pilot_Policy::evaluate( $pilot_settings, $pilot_context ),
    Pilot_Policy::evaluate( $pilot_settings, array_merge( $pilot_context, [ 'classification' => 'marketing' ] ) ) instanceof WP_Error,
    Pilot_Policy::evaluate( $pilot_settings, array_merge( $pilot_context, [ 'template_approved' => false ] ) ) instanceof WP_Error,
    Pilot_Policy::evaluate( array_merge( $pilot_settings, [ 'pilot_provider' => 'twilio' ] ), array_merge( $pilot_context, [ 'provider' => 'twilio', 'provider_settings' => [ 'messaging_service_sid' => 'MG123' ] ] ) ) instanceof WP_Error,
    Promotion_Policy::evaluate( 'marketing', 'ottertext', [] ) instanceof WP_Error,
    true === Promotion_Policy::evaluate( 'transactional', 'ottertext', [] ),
    true === Promotion_Policy::evaluate( 'marketing', 'ottertext', [ 'promotional_campaigns_enabled' => 1, 'promotional_approved_provider' => 'ottertext', 'promotional_provider_approval_reference' => 'provider-123', 'promotional_legal_review_reference' => 'legal-123', 'promotional_approved_at' => '2026-06-10' ] ),
    Promotion_Policy::evaluate( 'marketing', 'jasmin', [ 'promotional_campaigns_enabled' => 1, 'promotional_approved_provider' => 'ottertext', 'promotional_provider_approval_reference' => 'provider-123', 'promotional_legal_review_reference' => 'legal-123', 'promotional_approved_at' => '2026-06-10' ] ) instanceof WP_Error,
    // Local gateway app (SMSGate) state mapping.
    SMSGate_Provider::map_state( 'Pending' ) === 'queued',
    SMSGate_Provider::map_state( 'Processed' ) === 'queued',
    SMSGate_Provider::map_state( 'Sent' ) === 'sent',
    SMSGate_Provider::map_state( 'Delivered' ) === 'delivered',
    SMSGate_Provider::map_state( 'Failed' ) === 'failed',
    SMSGate_Provider::map_state( 'whatever' ) === 'unknown',
    // Local gateway app webhook envelope parsing.
    ( static function (): bool {
        $event = SMSGate_Provider::parse_webhook( [
            'event'   => 'sms:received',
            'payload' => [ 'messageId' => 'abc123', 'message' => 'Hello there', 'phoneNumber' => '+15550001111', 'receivedAt' => '2026-06-10T10:00:00Z' ],
        ] );
        return 'received' === $event['status'] && 'abc123' === $event['provider_message_id'] && '+15550001111' === $event['raw']['from'] && 'Hello there' === $event['raw']['body'];
    } )(),
    'optout' === SMSGate_Provider::parse_webhook( [ 'event' => 'sms:received', 'payload' => [ 'messageId' => 'm1', 'message' => 'stop', 'phoneNumber' => '+15550001111' ] ] )['status'],
    'optin' === SMSGate_Provider::parse_webhook( [ 'event' => 'sms:received', 'payload' => [ 'messageId' => 'm2', 'message' => 'START', 'phoneNumber' => '+15550001111' ] ] )['status'],
    'sent' === SMSGate_Provider::parse_webhook( [ 'event' => 'sms:sent', 'payload' => [ 'messageId' => 'm3', 'phoneNumber' => '+15550001111', 'sentAt' => '2026-06-10T10:00:01Z' ] ] )['status'],
    'delivered' === SMSGate_Provider::parse_webhook( [ 'event' => 'sms:delivered', 'payload' => [ 'messageId' => 'm3', 'phoneNumber' => '+15550001111', 'deliveredAt' => '2026-06-10T10:00:05Z' ] ] )['status'],
    'failed' === SMSGate_Provider::parse_webhook( [ 'event' => 'sms:failed', 'payload' => [ 'messageId' => 'm3', 'phoneNumber' => '+15550001111', 'reason' => 'no signal' ] ] )['status'],
    'm3' === SMSGate_Provider::parse_webhook( [ 'event' => 'sms:delivered', 'payload' => [ 'messageId' => 'm3', 'phoneNumber' => '+15550001111' ] ] )['provider_message_id'],
];
foreach ( $checks as $index => $passed ) {
    if ( ! $passed ) {
        fwrite( STDERR, 'Compliance check failed: ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo count( $checks ) . " compliance checks passed.\n";
