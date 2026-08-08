<?php
/**
 * Plans repository.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

use function WordPressistic\Memberistic\memberistic_db_formats;
use function WordPressistic\Memberistic\memberistic_sanitize_price;
use function WordPressistic\Memberistic\memberistic_sanitize_text;
use function WordPressistic\Memberistic\memberistic_sanitize_textarea;
use function WordPressistic\Memberistic\memberistic_validate_status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plans_Repository {
	/**
	 * Get table name.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_plans';
	}

	/**
	 * Get all plans.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;

		$table      = self::table();
		$where      = array();
		$where_args = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]      = 'status = %s';
			$where_args[] = sanitize_key( (string) $args['status'] );
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$sql       = "SELECT * FROM {$table}{$where_sql} ORDER BY sort_order ASC, id ASC";

		if ( $where_args ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a plan by ID.
	 */
	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get a plan by slug.
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s LIMIT 1', sanitize_title( $slug ) ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Create a plan.
	 *
	 * @param array<string, mixed> $data Plan data.
	 */
	public static function create( $data ) {
		global $wpdb;

		$data              = self::sanitize_data( $data );
		$data['created_at'] = current_time( 'mysql' );

		$inserted = $wpdb->insert( self::table(), $data, memberistic_db_formats( $data ) );

		if ( false === $inserted ) {
			return false;
		}

		$plan_id = (int) $wpdb->insert_id;
		do_action( 'memberistic_plan_created', $plan_id );

		return $plan_id;
	}

	/**
	 * Update a plan.
	 *
	 * @param int                  $id   Plan ID.
	 * @param array<string, mixed> $data Plan data.
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$data               = self::sanitize_data( $data );
		$data['updated_at'] = current_time( 'mysql' );

		$updated = $wpdb->update( self::table(), $data, array( 'id' => $id ), memberistic_db_formats( $data ), array( '%d' ) );

		if ( false !== $updated ) {
			do_action( 'memberistic_plan_updated', $id );
			return true;
		}

		return false;
	}

	/**
	 * Delete a plan if safe.
	 */
	public static function delete( $id ) {
		global $wpdb;

		if ( self::has_active_memberships( $id ) ) {
			return false;
		}

		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Check slug existence.
	 */
	public static function exists_by_slug( $slug ) {
		return null !== self::get_by_slug( $slug );
	}

	/**
	 * Seed the three default G2A membership tiers on first install.
	 *
	 * Tiers match the canonical spec:
	 *   Defender — $29.99/mo or $299.99/yr (1 person)
	 *   Patriot  — $39.99/mo or $449.99/yr (2 people)
	 *   Guardian — $59.99/mo or $649.99/yr (4 people)
	 *
	 * Integrators can extend or override via the `memberistic_default_plans`
	 * filter.
	 */
	public static function seed_default_plans() {
		$defaults = array(
			array(
				'name'            => 'Defender',
				'slug'            => 'defender',
				'description'     => 'Personal member access with full range benefits.',
				'monthly_price'   => 29.99,
				'annual_price'    => 299.99,
				'included_people' => 1,
				'benefits'        => wp_json_encode(
					array(
						'Free lane bookings for the primary member',
						'Member discount on instructor sessions',
						'Priority booking window',
						'Single waiver on file',
					)
				),
				'is_featured'     => 0,
				'sort_order'      => 10,
				'status'          => 'active',
			),
			array(
				'name'            => 'Patriot',
				'slug'            => 'patriot',
				'description'     => 'Two-person plan for couples and shooting partners.',
				'monthly_price'   => 39.99,
				'annual_price'    => 449.99,
				'included_people' => 2,
				'benefits'        => wp_json_encode(
					array(
						'Primary member + 1 linked member',
						'Free lane bookings for both members',
						'Shared activity history at the front desk',
						'Independent waivers per person',
					)
				),
				'is_featured'     => 1,
				'sort_order'      => 20,
				'status'          => 'active',
			),
			array(
				'name'            => 'Guardian',
				'slug'            => 'guardian',
				'description'     => 'Family plan covering up to four people.',
				'monthly_price'   => 59.99,
				'annual_price'    => 649.99,
				'included_people' => 4,
				'benefits'        => wp_json_encode(
					array(
						'Primary member + up to 3 linked members',
						'Best per-person value',
						'Independent waivers and check-in history per person',
						'Free family lane bookings',
					)
				),
				'is_featured'     => 0,
				'sort_order'      => 30,
				'status'          => 'active',
			),
		);

		$plans = apply_filters( 'memberistic_default_plans', $defaults );

		if ( ! is_array( $plans ) || empty( $plans ) ) {
			return;
		}

		foreach ( $plans as $plan ) {
			if ( empty( $plan['slug'] ) || self::exists_by_slug( (string) $plan['slug'] ) ) {
				continue;
			}

			self::create( $plan );
		}
	}

	/**
	 * Check active memberships for a plan.
	 */
	public static function has_active_memberships( $plan_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'memberistic_memberships';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE plan_id = %d AND status IN ('active','past_due','paused','comped','trial')", $plan_id )
		);

		return $count > 0;
	}

	/**
	 * Sanitize plan data.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed>
	 */
	private static function sanitize_data( $data ) {
		$clean = array();

		if ( array_key_exists( 'name', $data ) ) {
			$clean['name'] = memberistic_sanitize_text( $data['name'] );
		}
		if ( array_key_exists( 'slug', $data ) ) {
			$clean['slug'] = sanitize_title( wp_unslash( (string) $data['slug'] ) );
		}
		if ( array_key_exists( 'description', $data ) ) {
			$clean['description'] = memberistic_sanitize_textarea( $data['description'] );
		}
		if ( array_key_exists( 'monthly_price', $data ) ) {
			$clean['monthly_price'] = memberistic_sanitize_price( $data['monthly_price'] );
		}
		if ( array_key_exists( 'annual_price', $data ) ) {
			$clean['annual_price'] = memberistic_sanitize_price( $data['annual_price'] );
		}
		if ( array_key_exists( 'included_people', $data ) ) {
			$clean['included_people'] = max( 1, absint( $data['included_people'] ) );
		}
		if ( array_key_exists( 'benefits', $data ) ) {
			$clean['benefits'] = wp_json_encode( json_decode( (string) $data['benefits'], true ) ?: array() );
		}
		if ( array_key_exists( 'settings', $data ) ) {
			$clean['settings'] = wp_json_encode( json_decode( (string) $data['settings'], true ) ?: array() );
		}
		if ( array_key_exists( 'is_featured', $data ) ) {
			$clean['is_featured'] = absint( $data['is_featured'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$clean['sort_order'] = absint( $data['sort_order'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$clean['status'] = memberistic_validate_status( $data['status'], 'active' );
		}

		return $clean;
	}
}
