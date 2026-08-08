<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\REST\Content_Controller;

class ContentControllerParamsTest extends TestCase {
	public function test_posts_allow_status() {
		$this->assertSame(
			array( 'per_page', 'page', 'search', 'status' ),
			Content_Controller::allowed_params( 'posts' )
		);
	}

	public function test_pages_allow_status() {
		$this->assertSame(
			array( 'per_page', 'page', 'search', 'status' ),
			Content_Controller::allowed_params( 'pages' )
		);
	}

	public function test_media_does_not_allow_status() {
		$this->assertSame(
			array( 'per_page', 'page', 'search' ),
			Content_Controller::allowed_params( 'media' )
		);
	}

	public function test_categories_and_tags_do_not_allow_status() {
		$this->assertSame( array( 'per_page', 'page', 'search' ), Content_Controller::allowed_params( 'categories' ) );
		$this->assertSame( array( 'per_page', 'page', 'search' ), Content_Controller::allowed_params( 'tags' ) );
	}

	public function test_build_query_args_forwards_only_whitelisted_keys() {
		$raw = array(
			'per_page' => 20,
			'page'     => 2,
			'search'   => 'ccw',
			'status'   => 'draft',
			'author'   => 5, // not whitelisted — must be dropped.
		);
		$this->assertSame(
			array( 'per_page' => 20, 'page' => 2, 'search' => 'ccw' ),
			Content_Controller::build_query_args( 'media', $raw )
		);
		$this->assertSame(
			array( 'per_page' => 20, 'page' => 2, 'search' => 'ccw', 'status' => 'draft' ),
			Content_Controller::build_query_args( 'posts', $raw )
		);
	}

	public function test_build_query_args_drops_empty_strings() {
		$raw = array( 'search' => '', 'per_page' => 20 );
		$this->assertSame(
			array( 'per_page' => 20 ),
			Content_Controller::build_query_args( 'tags', $raw )
		);
	}

	public function test_build_query_args_handles_missing_keys() {
		$this->assertSame( array(), Content_Controller::build_query_args( 'categories', array() ) );
	}
}
