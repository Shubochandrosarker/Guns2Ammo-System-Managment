<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Leads\Lead_Categorizer;

class LeadCategorizerTest extends TestCase {
	/**
	 * @dataProvider formistic_cases
	 */
	public function test_from_formistic( string $form_name, array $fields, string $expected ) {
		$this->assertSame( $expected, Lead_Categorizer::from_formistic( $form_name, $fields ) );
	}

	public function formistic_cases(): array {
		return array(
			'nfa - suppressor keyword'      => array( 'Contact Us', array( 'Message' => 'Asking about a suppressor purchase' ), Lead_Categorizer::CATEGORY_NFA ),
			'nfa - sbr keyword'              => array( 'General', array( 'Message' => 'Do you build SBRs?' ), Lead_Categorizer::CATEGORY_NFA ),
			'nfa - trust keyword'            => array( 'General', array( 'Message' => 'Need help with an NFA trust' ), Lead_Categorizer::CATEGORY_NFA ),
			'ffl - transfer keyword'         => array( 'FFL Transfer Form', array(), Lead_Categorizer::CATEGORY_FFL_TRANSFER ),
			'ffl - ffl keyword in message'   => array( 'Contact', array( 'Message' => 'Can I do an FFL here?' ), Lead_Categorizer::CATEGORY_FFL_TRANSFER ),
			'ccw - concealed keyword'        => array( 'Contact', array( 'Message' => 'Concealed carry question' ), Lead_Categorizer::CATEGORY_CCW_CLASS ),
			'ccw - class keyword'            => array( 'Class Signup', array(), Lead_Categorizer::CATEGORY_CCW_CLASS ),
			'ccw - training keyword'         => array( 'Contact', array( 'Message' => 'Do you offer training courses?' ), Lead_Categorizer::CATEGORY_CCW_CLASS ),
			'membership - defender keyword'  => array( 'Contact', array( 'Message' => 'Interested in the Defender membership' ), Lead_Categorizer::CATEGORY_MEMBERSHIP ),
			'membership - patriot keyword'   => array( 'Contact', array( 'Message' => 'Tell me about the Patriot plan' ), Lead_Categorizer::CATEGORY_MEMBERSHIP ),
			'membership - guardian keyword'  => array( 'Contact', array( 'Message' => 'Guardian tier pricing?' ), Lead_Categorizer::CATEGORY_MEMBERSHIP ),
			'range enquiry - lane keyword'   => array( 'Contact', array( 'Message' => 'Can I book a lane for Saturday?' ), Lead_Categorizer::CATEGORY_RANGE_ENQUIRY ),
			'range enquiry - reservation'    => array( 'Contact', array( 'Message' => 'Making a reservation for 4 people' ), Lead_Categorizer::CATEGORY_RANGE_ENQUIRY ),
			'general - no keyword match'     => array( 'Contact', array( 'Message' => 'What are your hours today?' ), Lead_Categorizer::CATEGORY_GENERAL ),
			'general - empty everything'     => array( '', array(), Lead_Categorizer::CATEGORY_GENERAL ),
			// Ordering: nfa checked before ffl/ccw/membership/range.
			'ordering - nfa beats ffl'        => array( 'FFL Transfer', array( 'Message' => 'suppressor trust transfer' ), Lead_Categorizer::CATEGORY_NFA ),
			// Ordering: ffl_transfer is checked before ccw_class, so
			// ambiguous "ccw transfer" text resolves to ffl_transfer.
			'ordering - ffl beats ccw'        => array( 'CCW Transfer Question', array(), Lead_Categorizer::CATEGORY_FFL_TRANSFER ),
			// Ordering: ccw_class is checked before membership.
			'ordering - ccw beats membership' => array( 'Contact', array( 'Message' => 'CCW class for Patriot members' ), Lead_Categorizer::CATEGORY_CCW_CLASS ),
			// Ordering: membership is checked before range_enquiry.
			'ordering - membership beats range' => array( 'Contact', array( 'Message' => 'book a membership tour of the range' ), Lead_Categorizer::CATEGORY_MEMBERSHIP ),
			'case insensitive'                => array( 'CONTACT', array( 'Message' => 'SUPPRESSOR QUESTION' ), Lead_Categorizer::CATEGORY_NFA ),
			'non-scalar field values ignored' => array( 'Contact', array( 'Attachments' => array( 'a', 'b' ), 'Message' => 'lane booking' ), Lead_Categorizer::CATEGORY_RANGE_ENQUIRY ),
		);
	}

	/**
	 * @dataProvider booking_type_cases
	 */
	public function test_from_booking_type( string $category, string $name, string $expected ) {
		$this->assertSame( $expected, Lead_Categorizer::from_booking_type( $category, $name ) );
	}

	public function booking_type_cases(): array {
		return array(
			'lane category'              => array( 'lane', 'Lane Booking', Lead_Categorizer::CATEGORY_LANE_BOOKING ),
			'class category - ccw name'  => array( 'class', 'CCW Class', Lead_Categorizer::CATEGORY_CCW_CLASS ),
			'class category - non-ccw'   => array( 'class', 'Intro to Handguns', Lead_Categorizer::CATEGORY_CCW_CLASS ),
			'event category'             => array( 'event', 'Ladies Night', Lead_Categorizer::CATEGORY_EVENT_BOOKING ),
			'unknown category fallback'  => array( 'membership', 'Member Lane Booking', Lead_Categorizer::CATEGORY_RANGE_ENQUIRY ),
			'empty category fallback'    => array( '', '', Lead_Categorizer::CATEGORY_RANGE_ENQUIRY ),
			'category is case-normalized' => array( 'LANE', 'Lane Booking', Lead_Categorizer::CATEGORY_LANE_BOOKING ),
		);
	}

	public function test_from_membership_is_always_new_member() {
		$this->assertSame( Lead_Categorizer::CATEGORY_NEW_MEMBER, Lead_Categorizer::from_membership() );
	}
}
