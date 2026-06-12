<?php
/**
 * Lightweight customizer: business identity + social links + hero image overrides.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'customize_register', function ( $wp_customize ) {
	$wp_customize->add_section( 'g2a_business', [
		'title'    => __( 'Guns 2 Ammo  Business Info', 'guns2ammo' ),
		'priority' => 30,
	] );

	$fields = [
		'g2a_phone'        => [ 'label' => 'Phone',         'default' => '(602) 715-2677' ],
		'g2a_email'        => [ 'label' => 'Email',         'default' => 'sales@guns2ammo.com' ],
		'g2a_addr1'        => [ 'label' => 'Address Line',  'default' => '6030 E Main St, Suite 103' ],
		'g2a_addr2'        => [ 'label' => 'City/State/Zip','default' => 'Mesa, AZ 85205' ],
		'g2a_lat'          => [ 'label' => 'Latitude',      'default' => '33.4152' ],
		'g2a_lng'          => [ 'label' => 'Longitude',     'default' => '-111.7066' ],
		'g2a_rating'       => [ 'label' => 'Rating',        'default' => '4.7' ],
		'g2a_review_count' => [ 'label' => 'Reviews',       'default' => '449+' ],
		'g2a_social_fb'    => [ 'label' => 'Facebook URL',  'default' => '' ],
		'g2a_social_ig'    => [ 'label' => 'Instagram URL', 'default' => '' ],
		'g2a_social_x'     => [ 'label' => 'X URL',         'default' => '' ],
		'g2a_social_yt'    => [ 'label' => 'YouTube URL',   'default' => '' ],
		'g2a_meta_home'    => [ 'label' => 'Home meta description', 'default' => '' ],
		'g2a_og_image'     => [ 'label' => 'Default OG image URL',  'default' => '' ],
		'g2a_ffl_license'  => [ 'label' => 'FFL License #',         'default' => '' ],
		'g2a_google_places_api_key' => [ 'label' => 'Google Places API Key (live reviews)', 'default' => '' ],
		'g2a_google_place_id'       => [ 'label' => 'Google Place ID (live reviews)',       'default' => '' ],
	];

	foreach ( $fields as $id => $f ) {
		$wp_customize->add_setting( $id, [ 'default' => $f['default'], 'sanitize_callback' => 'sanitize_text_field' ] );
		$wp_customize->add_control( $id, [ 'label' => $f['label'], 'section' => 'g2a_business', 'type' => 'text' ] );
	}
} );
