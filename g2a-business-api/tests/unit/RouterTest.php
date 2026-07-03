<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Router;

class RouterTest extends TestCase {
	public function test_every_controller_class_exists() {
		foreach ( Router::controllers() as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Router advertised {$class} but it cannot be autoloaded."
			);
		}
	}

	public function test_every_controller_registers_register_routes() {
		foreach ( Router::controllers() as $class ) {
			$this->assertTrue(
				method_exists( $class, 'register_routes' ),
				"{$class} must implement register_routes()."
			);
		}
	}
}
