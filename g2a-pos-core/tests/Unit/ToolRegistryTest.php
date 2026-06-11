<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    public function setUp(): void
    {
        $r = new \ReflectionClass(ToolRegistry::class);
        $p = $r->getProperty('tools');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    public function test_register_and_lookup_silent_tool(): void
    {
        ToolRegistry::register('test.echo', ['description' => 'echo'],
            static fn(array $a) => ['ok' => true, 'echo' => $a['x'] ?? null],
            'public', ActionPolicy::SILENT);

        $this->assertTrue(ToolRegistry::has('test.echo'));
        $this->assertSame(ActionPolicy::SILENT, ToolRegistry::policy_for('test.echo'));
        $this->assertSame('public', ToolRegistry::capability_for('test.echo'));

        $res = ToolRegistry::execute('test.echo', ['x' => 42]);
        $this->assertTrue($res['ok']);
        $this->assertSame(42, $res['echo']);
    }

    public function test_unknown_tool_returns_error(): void
    {
        $res = ToolRegistry::execute('does.not.exist', []);
        $this->assertFalse($res['ok']);
        $this->assertSame('unknown_tool', $res['error']);
    }

    public function test_tool_exception_is_captured(): void
    {
        ToolRegistry::register('test.boom', ['description' => 'boom'],
            static function () { throw new \RuntimeException('kaboom'); },
            'public', ActionPolicy::SILENT);
        $res = ToolRegistry::execute('test.boom', []);
        $this->assertFalse($res['ok']);
        $this->assertSame('kaboom', $res['error']);
    }

    public function test_specs_are_well_formed_function_definitions(): void
    {
        ToolRegistry::register('test.something', [
            'description' => 'tests stuff',
            'parameters' => ['type' => 'object', 'properties' => []],
        ], static fn() => ['ok' => true], 'public');
        $specs = ToolRegistry::specs();
        $this->assertNotEmpty($specs);
        $first = $specs[0];
        $this->assertSame('function', $first['type']);
        $this->assertSame('test.something', $first['function']['name']);
        $this->assertSame('tests stuff', $first['function']['description']);
    }
}
