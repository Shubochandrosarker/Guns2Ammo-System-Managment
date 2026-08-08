<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\REST\System_Controller;

class SystemControllerConfirmTest extends TestCase {
	public function test_confirmed_is_false_when_body_missing_flag() {
		$req = new \WP_REST_Request( array(), array() );
		$this->assertFalse( System_Controller::confirmed( $req ) );
	}

	public function test_confirmed_is_false_when_flag_is_falsy() {
		$this->assertFalse( System_Controller::confirmed( new \WP_REST_Request( array(), array( 'confirm' => false ) ) ) );
		$this->assertFalse( System_Controller::confirmed( new \WP_REST_Request( array(), array( 'confirm' => 0 ) ) ) );
	}

	public function test_confirmed_is_true_when_flag_is_truthy() {
		$this->assertTrue( System_Controller::confirmed( new \WP_REST_Request( array(), array( 'confirm' => true ) ) ) );
		$this->assertTrue( System_Controller::confirmed( new \WP_REST_Request( array(), array( 'confirm' => 1 ) ) ) );
	}
}
