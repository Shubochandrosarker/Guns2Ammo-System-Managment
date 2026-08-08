<?php

namespace G2A\POS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use G2A\POS\Integrations\RangeMembership;
use PHPUnit\Framework\TestCase;

final class RangeMembershipTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        // Reset internal cache between tests via reflection.
        $ref = new \ReflectionClass(RangeMembership::class);
        foreach (['providers', 'active', 'resolved'] as $name) {
            $p = $ref->getProperty($name);
            $p->setAccessible(true);
            $p->setValue(null, $name === 'resolved' ? false : null);
        }
    }
    protected function tearDown(): void { Monkey\tearDown(); }

    public function test_filter_override_wins(): void
    {
        Filters\expectApplied('g2a_pos_membership_lookup')
            ->andReturn([
                'active' => true,
                'plan' => 'Range Premium',
                'plan_slug' => 'premium',
                'expires_at' => '2027-01-01 00:00:00',
                'member_id' => 'WPISTIC-42',
            ]);
        $res = RangeMembership::status_for_customer(99);
        $this->assertSame('Range Premium', $res['plan']);
        $this->assertSame('filter', $res['source']);
        $this->assertTrue($res['active']);
    }

    public function test_returns_null_with_no_provider_and_no_filter(): void
    {
        Filters\expectApplied('g2a_pos_membership_lookup')->andReturn(null);
        // No providers detect — short-circuit.
        $ref = new \ReflectionClass(RangeMembership::class);
        $p = $ref->getProperty('providers');
        $p->setAccessible(true);
        $p->setValue(null, []);
        $r = $ref->getProperty('resolved'); $r->setAccessible(true); $r->setValue(null, true);
        $a = $ref->getProperty('active'); $a->setAccessible(true); $a->setValue(null, null);

        $this->assertNull(RangeMembership::status_for_customer(99));
    }

    public function test_bookings_filter_override(): void
    {
        Filters\expectApplied('g2a_pos_membership_bookings')
            ->andReturn([['id' => 1, 'title' => 'Intro to handgun safety', 'starts_at' => '2026-06-05 10:00:00']]);
        $bookings = RangeMembership::bookings_for_customer(7);
        $this->assertCount(1, $bookings);
        $this->assertSame('Intro to handgun safety', $bookings[0]['title']);
    }

    public function test_no_customer_returns_null(): void
    {
        $this->assertNull(RangeMembership::status_for_customer(null));
        $this->assertSame([], RangeMembership::bookings_for_customer(0));
    }
}
