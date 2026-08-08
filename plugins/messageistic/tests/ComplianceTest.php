<?php

use Messageistic\Compliance\Message_Classification;
use Messageistic\Compliance\Provider_Eligibility;
use Messageistic\Compliance\Pilot_Policy;
use Messageistic\Compliance\Promotion_Policy;
use PHPUnit\Framework\TestCase;

final class ComplianceTest extends TestCase {
    public function test_unknown_classification_fails_to_transactional(): void {
        self::assertSame( 'transactional', Message_Classification::normalize( 'unknown' ) );
    }

    public function test_marketing_requires_marketing_consent(): void {
        self::assertTrue( Message_Classification::requires_marketing_consent( 'marketing' ) );
        self::assertFalse( Message_Classification::requires_marketing_consent( 'transactional' ) );
    }

    public function test_firearm_provider_must_be_explicitly_approved(): void {
        $result = Provider_Eligibility::check( 'ottertext', 'transactional', [ 'industry' => 'firearms' ] );
        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'messageistic_provider_not_approved', $result->get_error_code() );
    }

    public function test_twilio_remains_blocked_even_when_listed(): void {
        $result = Provider_Eligibility::check( 'twilio', 'transactional', [ 'industry' => 'firearms', 'approved_firearm_providers' => 'twilio' ] );
        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'messageistic_provider_firearm_restricted', $result->get_error_code() );
    }

    public function test_approved_provider_allows_transactional_firearm_traffic(): void {
        self::assertTrue( Provider_Eligibility::check( 'ottertext', 'transactional', [ 'industry' => 'firearms', 'approved_firearm_providers' => 'ottertext' ] ) );
    }

    public function test_pilot_rejects_non_transactional_messages(): void {
        $result = Pilot_Policy::evaluate( $this->pilot_settings(), $this->pilot_context( [ 'classification' => 'marketing' ] ) );
        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'messageistic_pilot_transactional_only', $result->get_error_code() );
    }

    public function test_pilot_rejects_testing_provider(): void {
        $settings = $this->pilot_settings();
        $settings['pilot_provider'] = 'testing';
        $result = Pilot_Policy::evaluate( $settings, $this->pilot_context( [ 'provider' => 'testing' ] ) );
        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'messageistic_pilot_live_provider_required', $result->get_error_code() );
    }

    public function test_pilot_requires_approved_template_and_sender(): void {
        $result = Pilot_Policy::evaluate( $this->pilot_settings(), $this->pilot_context( [ 'template_approved' => false ] ) );
        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'messageistic_pilot_template_unapproved', $result->get_error_code() );
    }

    public function test_pilot_rejects_twilio_sender_pool(): void {
        $settings = $this->pilot_settings();
        $settings['pilot_provider'] = 'twilio';
        $result = Pilot_Policy::evaluate( $settings, $this->pilot_context( [
            'provider' => 'twilio',
            'provider_settings' => [ 'messaging_service_sid' => 'MG123' ],
        ] ) );
        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'messageistic_pilot_sender_pool', $result->get_error_code() );
    }

    public function test_valid_controlled_pilot_context_passes(): void {
        self::assertTrue( Pilot_Policy::evaluate( $this->pilot_settings(), $this->pilot_context() ) );
    }


    public function test_promotions_require_provider_and_legal_approval(): void {
        self::assertInstanceOf( WP_Error::class, Promotion_Policy::evaluate( 'marketing', 'ottertext', [] ) );
        self::assertTrue( Promotion_Policy::evaluate( 'marketing', 'ottertext', [
            'promotional_campaigns_enabled' => 1,
            'promotional_approved_provider' => 'ottertext',
            'promotional_provider_approval_reference' => 'provider-123',
            'promotional_legal_review_reference' => 'legal-123',
            'promotional_approved_at' => '2026-06-10',
        ] ) );
    }

    private function pilot_settings(): array {
        return [
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
    }

    private function pilot_context( array $overrides = [] ): array {
        return array_merge( [
            'classification'    => 'transactional',
            'provider'          => 'ottertext',
            'sender'            => '+15551234567',
            'template_id'       => 10,
            'template_approved' => true,
            'provider_settings' => [],
        ], $overrides );
    }

}
