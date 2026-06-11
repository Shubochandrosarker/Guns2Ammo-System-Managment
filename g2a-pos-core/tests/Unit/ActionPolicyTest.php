<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ActionPolicyTest extends TestCase
{
    public function setUp(): void
    {
        $r = new \ReflectionClass(ToolRegistry::class);
        $p = $r->getProperty('tools');
        $p->setAccessible(true);
        $p->setValue(null, []);
        $GLOBALS['__wp_options']['g2a_pos_ai_policy_overrides'] = [];
    }

    public function test_returns_registry_policy_when_no_override(): void
    {
        ToolRegistry::register('test.read', ['description' => 'read'],
            static fn() => ['ok' => true], 'public', ActionPolicy::SILENT);
        $this->assertSame(ActionPolicy::SILENT, ActionPolicy::for('test.read'));
        $this->assertFalse(ActionPolicy::requires_confirmation('test.read'));
    }

    public function test_override_option_wins(): void
    {
        ToolRegistry::register('test.send', ['description' => 'send'],
            static fn() => ['ok' => true], 'public', ActionPolicy::CONFIRM);
        $GLOBALS['__wp_options']['g2a_pos_ai_policy_overrides'] = [
            'test.send' => ActionPolicy::SILENT,
        ];
        $this->assertSame(ActionPolicy::SILENT, ActionPolicy::for('test.send'));
        $this->assertFalse(ActionPolicy::requires_confirmation('test.send'));
    }

    public function test_confirm_policy_requires_confirmation(): void
    {
        ToolRegistry::register('test.boom', ['description' => 'boom'],
            static fn() => ['ok' => true], 'public', ActionPolicy::CONFIRM);
        $this->assertTrue(ActionPolicy::requires_confirmation('test.boom'));
    }
}
