<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\BridGistic\Classifier;

class ClassifierTest extends TestCase {
	private Classifier $classifier;

	protected function setUp(): void {
		$this->classifier = new Classifier();
	}

	/**
	 * @dataProvider read_queries
	 */
	public function test_read_queries( string $query ) {
		$this->assertSame( Classifier::CATEGORY_READ, $this->classifier->classify( $query ) );
	}

	public function read_queries(): array {
		return array(
			array( 'show this week\'s booking revenue' ),
			array( 'find members expiring this month' ),
			array( 'analyze why store sales dropped' ),
			array( 'what is the CCW class conversion rate' ),
			array( '' ),
		);
	}

	/**
	 * @dataProvider draft_queries
	 */
	public function test_draft_queries( string $query ) {
		$this->assertSame( Classifier::CATEGORY_DRAFT, $this->classifier->classify( $query ) );
	}

	public function draft_queries(): array {
		return array(
			array( 'draft a customer follow-up email' ),
			array( 'prepare a weekly business report' ),
			array( 'write a renewal reminder for expired members' ),
			// Draft verbs override action verbs — "draft a refund email" is a draft.
			array( 'draft a refund email to Priya S.' ),
			array( 'compose a message to Ladies Tuesday attendees' ),
			array( 'suggest an SEO fix for the CCW page' ),
		);
	}

	/**
	 * @dataProvider action_queries
	 */
	public function test_action_queries( string $query ) {
		$this->assertSame( Classifier::CATEGORY_ACTION, $this->classifier->classify( $query ) );
	}

	public function action_queries(): array {
		return array(
			array( 'send follow-up email to expired members' ),
			array( 'create a task to improve the CCW page' ),
			array( 'update the membership renewal reminder' ),
			array( 'refund order #4128' ),
			array( 'cancel booking 92841' ),
			array( 'schedule the weekly business report' ),
		);
	}

	public function test_classifier_is_case_insensitive() {
		$this->assertSame( Classifier::CATEGORY_ACTION, $this->classifier->classify( 'SEND FOLLOW-UP EMAIL' ) );
		$this->assertSame( Classifier::CATEGORY_DRAFT,  $this->classifier->classify( 'DRAFT a message' ) );
	}
}
