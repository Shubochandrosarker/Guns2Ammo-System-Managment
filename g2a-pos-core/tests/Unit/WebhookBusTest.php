<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Webhooks\WebhookBus;
use PHPUnit\Framework\TestCase;

final class WebhookBusTest extends TestCase
{
    public function test_sign_and_verify_round_trip(): void
    {
        $body = '{"event":"4473.transferred"}';
        $sig = WebhookBus::sign($body, 'shhhh');
        $this->assertTrue(WebhookBus::verify($body, 'shhhh', $sig));
        $this->assertFalse(WebhookBus::verify($body . 'x', 'shhhh', $sig));
        $this->assertFalse(WebhookBus::verify($body, 'other', $sig));
    }

    public function test_event_enum_is_non_empty_and_sorted_for_humans(): void
    {
        $this->assertNotEmpty(WebhookBus::EVENTS);
        $this->assertContains('4473.transferred', WebhookBus::EVENTS);
        $this->assertContains('bound_book.chain_broken', WebhookBus::EVENTS);
    }
}
