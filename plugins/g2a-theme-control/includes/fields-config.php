<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'__default__' => [
		'Hero' => [
			[ 'id' => 'hero_title', 'label' => 'Hero Title', 'type' => 'text' ],
			[ 'id' => 'hero_subtitle', 'label' => 'Hero Subtitle', 'type' => 'textarea' ],
			[ 'id' => 'hero_cta_text', 'label' => 'Hero CTA Text', 'type' => 'text' ],
			[ 'id' => 'hero_cta_url', 'label' => 'Hero CTA URL', 'type' => 'text', 'sanitize' => 'url' ],
		],
		'Content' => [
			[ 'id' => 'content_intro', 'label' => 'Content Intro', 'type' => 'textarea' ],
			[ 'id' => 'content_body', 'label' => 'Content Body', 'type' => 'textarea' ],
		],
		'Pricing' => [
			[ 'id' => 'pricing_title', 'label' => 'Pricing Section Title', 'type' => 'text' ],
			[ 'id' => 'pricing_description', 'label' => 'Pricing Description', 'type' => 'textarea' ],
		],
		'Events' => [
			[ 'id' => 'event_shortcode', 'label' => 'Event Shortcode', 'type' => 'text' ],
		],
		'Sections' => [
			[ 'id' => 'section_blocks', 'label' => 'Section Blocks', 'type' => 'repeater', 'fields' => [
				[ 'id' => 'section_key', 'label' => 'Section Key', 'type' => 'text' ],
				[ 'id' => 'section_heading', 'label' => 'Section Heading', 'type' => 'text' ],
				[ 'id' => 'section_subheading', 'label' => 'Section Subheading', 'type' => 'text' ],
				[ 'id' => 'section_body', 'label' => 'Section Body', 'type' => 'text' ],
				[ 'id' => 'section_shortcode', 'label' => 'Section Shortcode', 'type' => 'text' ],
			] ],
		],
	],
	'front-page.php' => [
		'Hero' => [
			[ 'id' => 'hero_trust_items', 'label' => 'Hero Trust Items (comma separated)', 'type' => 'text' ],
		],
		'Content' => [
			[ 'id' => 'ca_ccw_clarity_text', 'label' => 'California CCW Clarity Text', 'type' => 'textarea' ],
		],
		'Events' => [
			[ 'id' => 'mg_callout_text', 'label' => 'Machine Gun Callout Text', 'type' => 'text' ],
			[ 'id' => 'mg_fourth_weapon_name', 'label' => 'Machine Gun 4th Weapon Name', 'type' => 'text' ],
			[ 'id' => 'mg_fourth_weapon_caliber', 'label' => 'Machine Gun 4th Weapon Caliber', 'type' => 'text' ],
			[ 'id' => 'mg_fourth_weapon_desc', 'label' => 'Machine Gun 4th Weapon Description', 'type' => 'textarea' ],
		],
	],
	'page-templates/template-book-a-lane.php' => [
		'Hero' => [
			[ 'id' => 'lane_hero_title', 'label' => 'Hero Title', 'type' => 'text' ],
		],
		'Content' => [
			[ 'id' => 'lane_member_discount_label', 'label' => 'Member Discount Label', 'type' => 'text' ],
			[ 'id' => 'lane_membership_pitch', 'label' => 'Membership Promo Text', 'type' => 'textarea' ],
			[ 'id' => 'lane_hours_text', 'label' => 'Hours Summary Text', 'type' => 'textarea' ],
			[ 'id' => 'lane_pricing_text', 'label' => 'Pricing Summary Text', 'type' => 'textarea' ],
		],
	],
	'page-templates/template-pricing.php' => [
		'Pricing' => [
			[ 'id' => 'pricing_walkin_lane_fee', 'label' => 'Walk-In Lane Fee Text', 'type' => 'text' ],
			[ 'id' => 'pricing_additional_shooter', 'label' => 'Additional Shooter Fee Text', 'type' => 'text' ],
			[ 'id' => 'pricing_unlimited_text', 'label' => 'Unlimited Access Text', 'type' => 'text' ],
			[ 'id' => 'pricing_ladies_tuesday_text', 'label' => 'Ladies Tuesday Pricing Text', 'type' => 'textarea' ],
		],
	],
	'page-templates/template-machine-gun.php' => [
		'Hero' => [
			[ 'id' => 'mg_hero_intro', 'label' => 'Hero Intro Text', 'type' => 'textarea' ],
		],
		'Content' => [
			[ 'id' => 'mg_ccw_remove_text', 'label' => 'CCW Banner Replacement Text', 'type' => 'text' ],
			[ 'id' => 'mg_inventory_items', 'label' => 'Machine Gun Inventory', 'type' => 'repeater', 'fields' => [
				[ 'id' => 'name',        'label' => 'Weapon Name',           'type' => 'text' ],
				[ 'id' => 'image',       'label' => 'Image (Media Library)', 'type' => 'image' ],
				[ 'id' => 'caliber',     'label' => 'Caliber',               'type' => 'text' ],
				[ 'id' => 'rpm',         'label' => 'Rate of Fire (RPM)',    'type' => 'text' ],
				[ 'id' => 'magazine',    'label' => 'Magazine Capacity',     'type' => 'text' ],
				[ 'id' => 'category',    'label' => 'Category (SMG / Rifle / Pistol)', 'type' => 'text' ],
				[ 'id' => 'price',       'label' => 'Starting Price',        'type' => 'text' ],
				[ 'id' => 'description', 'label' => 'Short Description',     'type' => 'textarea' ],
				[ 'id' => 'is_featured', 'label' => 'Show in Hero Row (1 = yes)', 'type' => 'text' ],
				[ 'id' => 'link',        'label' => 'Detail Page URL (optional)', 'type' => 'url' ],
			] ],
		],
	],
	'page-templates/template-ladies-tuesday.php' => [
		'Hero' => [
			[ 'id' => 'ladies_hero_subtitle', 'label' => 'Hero Subtitle', 'type' => 'textarea' ],
		],
		'Content' => [
			[ 'id' => 'ladies_offer_text', 'label' => 'Offer Text', 'type' => 'text' ],
			[ 'id' => 'ladies_no_female_rso_note', 'label' => 'No Female RSO Note', 'type' => 'textarea' ],
			[ 'id' => 'ladies_upcoming_events_shortcode', 'label' => 'Upcoming Tuesday Events Shortcode', 'type' => 'text' ],
		],
	],
	'page-templates/template-ccw.php' => [
		'Hero' => [
			[ 'id' => 'ccw_hero_title', 'label' => 'CCW Hero Title', 'type' => 'text' ],
			[ 'id' => 'ccw_hero_subtitle', 'label' => 'CCW Hero Subtitle', 'type' => 'textarea' ],
			[ 'id' => 'ccw_event_shortcode', 'label' => 'CCW Event Shortcode', 'type' => 'text' ],
		],
		'Content' => [
			[ 'id' => 'ccw_ca_qualification_text', 'label' => 'California Qualification Text', 'type' => 'textarea' ],
		],
	],
];
