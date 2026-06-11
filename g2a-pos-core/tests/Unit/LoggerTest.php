<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Support\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    public function test_error_writes_to_php_error_log(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'g2a-log-');
        $previous = ini_set('error_log', $tmp);
        try {
            Logger::error('boom', ['k' => 'v']);
            $contents = file_get_contents($tmp);
            $this->assertIsString($contents);
            $this->assertStringContainsString('[G2A POS][ERROR] boom', $contents);
            $this->assertStringContainsString('"k":"v"', $contents);
        } finally {
            ini_set('error_log', $previous);
            @unlink($tmp);
        }
    }

    public function test_exception_includes_class_and_line(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'g2a-log-');
        $previous = ini_set('error_log', $tmp);
        try {
            Logger::exception('boot', new \RuntimeException('nope'));
            $contents = (string) file_get_contents($tmp);
            $this->assertStringContainsString('boot: nope', $contents);
            $this->assertStringContainsString('RuntimeException', $contents);
        } finally {
            ini_set('error_log', $previous);
            @unlink($tmp);
        }
    }
}
