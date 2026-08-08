<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Ai\DefaultKnowledgePack;
use PHPUnit\Framework\TestCase;

final class DefaultKnowledgePackTest extends TestCase
{
    /** @return string One blob of every document body for containment checks. */
    private function corpus(): string
    {
        return implode("\n", array_column(DefaultKnowledgePack::documents(), 'body'));
    }

    public function test_every_document_is_well_formed(): void
    {
        $docs = DefaultKnowledgePack::documents();
        $this->assertNotEmpty($docs);
        $labels = [];
        foreach ($docs as $doc) {
            $this->assertNotSame('', trim($doc['label']));
            $this->assertGreaterThan(100, strlen($doc['body']), "Body of '{$doc['label']}' should be real prose");
            $this->assertSame('public', $doc['scope']);
            $this->assertStringContainsString(DefaultKnowledgePack::TAG, $doc['tags']);
            $labels[] = $doc['label'];
        }
        $this->assertSame($labels, array_unique($labels), 'Labels must be stable and unique');
    }

    public function test_membership_facts_are_present(): void
    {
        $corpus = $this->corpus();
        // Tier pricing (monthly + annual) and headcounts.
        $this->assertStringContainsString('Defender: $29.99 per month or $299.99 per year', $corpus);
        $this->assertStringContainsString('Patriot: $39.99 per month or $449.99 per year', $corpus);
        $this->assertStringContainsString('Guardian: $59.99 per month or $649.99 per year', $corpus);
        $this->assertStringContainsString('1 person', $corpus);
        $this->assertStringContainsString('2 people', $corpus);
        $this->assertStringContainsString('4 people', $corpus);
        $this->assertStringContainsString('no contracts', $corpus);
        // Guest pricing matrix.
        $this->assertStringContainsString('$15 per extra shooter per hour', $corpus);
        $this->assertStringContainsString('$10 per extra shooter per hour', $corpus);
    }

    public function test_hours_and_identity_facts_are_present(): void
    {
        $corpus = $this->corpus();
        $this->assertStringContainsString('6030 E Main St, Suite 103, Mesa, AZ 85205', $corpus);
        $this->assertStringContainsString('(602) 715-2677', $corpus);
        $this->assertStringContainsString('sales@guns2ammo.com', $corpus);
        $this->assertStringContainsString('2014', $corpus);
        $this->assertStringContainsString('4.7 stars from 556 Google reviews', $corpus);
        $this->assertStringContainsString('Sunday 12pm–6pm', $corpus);
        $this->assertStringContainsString('Monday–Thursday 10am–6pm', $corpus);
        $this->assertStringContainsString('Friday 10am–7pm', $corpus);
        $this->assertStringContainsString('Saturday 10am–7pm', $corpus);
        $this->assertStringContainsString('6 indoor shooting lanes', $corpus);
    }

    public function test_training_and_instructor_facts_are_present(): void
    {
        $corpus = $this->corpus();
        $this->assertStringContainsString('$85', $corpus);
        $this->assertStringContainsString('$149.99', $corpus);
        $this->assertStringContainsString('no live fire', $corpus);
        $this->assertStringContainsString('$35 per firearm', $corpus);
        $this->assertStringContainsString('$95', $corpus);
        $this->assertStringContainsString('$295', $corpus);
        $this->assertStringContainsString('Alen Olson', $corpus);
        $this->assertStringContainsString('Nicholas Steigert', $corpus);
        $this->assertStringContainsString('Mesa Police', $corpus);
        $this->assertStringContainsString('3rd Battalion, 7th Marines', $corpus);
        $this->assertStringContainsString('NRA', $corpus);
    }

    public function test_facility_and_booking_facts_are_present(): void
    {
        $corpus = $this->corpus();
        $this->assertStringContainsString('Stripe', $corpus);
        $this->assertStringContainsString('QR code', $corpus);
        $this->assertStringContainsString('membership-tier pricing', $corpus);
    }
}
