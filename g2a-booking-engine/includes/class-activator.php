<?php
/**
 * Activation handler.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Activator {

	public static function activate() {
		// Force-flush PHP OPcache for every plugin file. Without this, hosts
		// that run OPcache (most cPanel / Plesk / managed-WP shared hosts) keep
		// executing the OLD bytecode after a plugin update — which manifests
		// as "I uploaded the new zip but the booking page still looks like
		// v1.1.13". Reset on activate guarantees the next request compiles
		// fresh files.
		self::reset_opcache();

		require_once G2AB_PATH . 'includes/class-installer.php';
		( new G2AB_Installer() )->install();
		update_option( 'g2ab_db_version', G2AB_DB_VERSION );
		update_option( 'g2ab_plugin_version', G2AB_VERSION );

		self::set_default_options();
		self::register_roles_and_caps();
		self::seed_initial_data();
		self::apply_client_defaults_existing();
		self::merge_default_active_modules();
		self::schedule_events();

		set_transient( 'g2ab_activation_redirect', 1, 30 );
		flush_rewrite_rules();
		do_action( 'g2ab_activated' );
	}

	/**
	 * Invalidate OPcache for every PHP file in the plugin directory.
	 *
	 * Safe to call when OPcache isn't enabled (the function check no-ops).
	 */
	private static function reset_opcache() {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}
		try {
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( G2AB_PATH, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iter as $file ) {
				if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
					@opcache_invalidate( $file->getPathname(), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
			// Belt and braces — reset the whole cache too.
			if ( function_exists( 'opcache_reset' ) ) {
				@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		} catch ( \Throwable $e ) {
			// non-fatal; OPcache may have restrict_api set so we can't touch it.
		}
	}

	private static function set_default_options() {
		$defaults = array(
			'g2ab_remove_data_on_uninstall' => 0,
			'g2ab_currency'                 => 'USD',
			'g2ab_timezone'                 => wp_timezone_string(),
			'g2ab_default_buffer_minutes'   => 0,
			'g2ab_reservation_hold_minutes' => 15,
			'g2ab_require_account'          => 0,
			'g2ab_allow_guest_booking'      => 1,
			'g2ab_min_booking_lead_minutes' => 30,
			'g2ab_max_booking_advance_days' => 60,
			'g2ab_send_confirmation_email'  => 1,
			'g2ab_send_reminder_email'      => 1,
			'g2ab_reminder_hours_before'    => 24,
			'g2ab_admin_notification_email' => get_option( 'admin_email' ),
			'g2ab_payment_gateway_default'  => 'pay_in_store',
			'g2ab_stripe_enabled'           => 0,
			'g2ab_stripe_test_mode'         => 1,
			'g2ab_stripe_publishable_key'   => '',
			'g2ab_stripe_secret_key'        => '',
			'g2ab_stripe_webhook_secret'    => '',
			'g2ab_brand_color_primary'      => '#4A5D3A',
			'g2ab_brand_color_accent'       => '#D2691E',
			'g2ab_business_name'            => 'Guns 2 Ammo',
			'g2ab_business_phone'           => '',
			'g2ab_business_address'         => 'Mesa, Arizona',
			'g2ab_lane_hourly_price'        => 20,
			'g2ab_lane_extra_shooter_fee'   => 15,
			'g2ab_ladies_tuesday_lane_price'=> 0,
			'g2ab_form_title'               => 'Book Your Lane',
			'g2ab_form_subtitle'            => 'Guns 2 Ammo Range Booking',
			'g2ab_form_description'         => 'Members receive included lane time with qualifying plans plus discounted rentals and priority booking access.',
		);
		foreach ( $defaults as $key => $value ) {
			add_option( $key, $value );
		}
	}

	public static function sync_client_defaults() {
		self::apply_client_defaults_existing();
		self::seed_default_events();
	}

	private static function register_roles_and_caps() {
		$caps = array(
			'read'                  => true,
			'manage_g2ab_bookings'  => true,
			'delete_g2ab_bookings'  => true,
			'manage_g2ab_resources' => true,
			'manage_g2ab_forms'     => true,
			'manage_g2ab_payments'  => true,
			'manage_g2ab_settings'  => true,
			'view_g2ab_reports'     => true,
		);

		if ( null === get_role( 'g2ab_manager' ) ) {
			add_role( 'g2ab_manager', __( 'G2A Booking Manager', 'g2a-booking' ), $caps );
		}
		if ( null === get_role( 'g2ab_staff' ) ) {
			add_role( 'g2ab_staff', __( 'G2A Booking Staff', 'g2a-booking' ), array(
				'read' => true, 'manage_g2ab_bookings' => true, 'view_g2ab_reports' => true,
			) );
		}
		if ( null === get_role( 'g2ab_instructor' ) ) {
			add_role( 'g2ab_instructor', __( 'G2A Instructor', 'g2a-booking' ), array(
				'read' => true, 'manage_g2ab_bookings' => true,
			) );
		}
		if ( null === get_role( 'g2ab_booking_user' ) ) {
			add_role( 'g2ab_booking_user', __( 'G2A Booking User', 'g2a-booking' ), array(
				'read' => true,
			) );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( $caps ) as $cap ) {
				if ( 'read' === $cap ) continue;
				$admin->add_cap( $cap );
			}
		}
	}

	public static function seed_initial_data() {
		global $wpdb;
		$now = current_time( 'mysql' );

		$resources_table     = $wpdb->prefix . 'g2ab_resources';
		$booking_types_table = $wpdb->prefix . 'g2ab_booking_types';
		$forms_table         = $wpdb->prefix . 'g2ab_forms';
		$form_fields_table   = $wpdb->prefix . 'g2ab_form_fields';
		$rules_table         = $wpdb->prefix . 'g2ab_availability_rules';

		// Lanes 1-6.
		for ( $i = 1; $i <= 6; $i++ ) {
			$slug = 'lane-' . $i;
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$resources_table} WHERE slug = %s", $slug ) );
			if ( $exists ) continue;
			$wpdb->insert( $resources_table, array(
				'name'        => sprintf( __( 'Lane %d', 'g2a-booking' ), $i ),
				'slug'        => $slug,
				'type'        => 'lane',
				'capacity'    => 3,
				'description' => __( 'Indoor shooting range lane.', 'g2a-booking' ),
				'settings'    => wp_json_encode( array( 'allowed_calibers' => array( 'pistol', 'rifle' ) ) ),
				'sort_order'  => $i,
				'is_active'   => 1,
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
		}

		$classroom_slug = 'training-classroom';
		$classroom_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$resources_table} WHERE slug = %s", $classroom_slug ) );
		if ( ! $classroom_exists ) {
			$wpdb->insert( $resources_table, array(
				'name'        => __( 'Training Classroom', 'g2a-booking' ),
				'slug'        => $classroom_slug,
				'type'        => 'classroom',
				'capacity'    => 12,
				'description' => __( 'Default classroom for CCW and training bookings.', 'g2a-booking' ),
				'settings'    => wp_json_encode( array( 'equipment' => array( 'projector', 'tables', 'chairs' ) ) ),
				'sort_order'  => 100,
				'is_active'   => 1,
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
		}

		// Booking types.
		$types = array(
			array( 'name' => __( 'Lane Booking', 'g2a-booking' ), 'slug' => 'lane-booking', 'category' => 'lane', 'duration_min' => 60, 'buffer_after' => 10, 'base_price' => 20.00, 'deposit_amount' => 0.00, 'payment_modes' => 'full,in_store', 'requires_waiver' => 1, 'members_only' => 0, 'member_discount' => 0.00 ),
			array( 'name' => __( 'Ladies Tuesday', 'g2a-booking' ), 'slug' => 'ladies-tuesday', 'category' => 'lane', 'duration_min' => 60, 'buffer_after' => 10, 'base_price' => 0.00, 'deposit_amount' => 0.00, 'payment_modes' => 'free', 'requires_waiver' => 1, 'members_only' => 0, 'member_discount' => 0.00 ),
			array( 'name' => __( 'Arizona CCW Course', 'g2a-booking' ), 'slug' => 'ccw-class', 'category' => 'class', 'duration_min' => 240, 'buffer_after' => 0, 'base_price' => 99.00, 'deposit_amount' => 25.00, 'payment_modes' => 'full,deposit', 'requires_waiver' => 1, 'members_only' => 0, 'member_discount' => 0.00 ),
			array( 'name' => __( 'Member Lane Booking', 'g2a-booking' ), 'slug' => 'member-lane-booking', 'category' => 'membership', 'duration_min' => 60, 'buffer_after' => 10, 'base_price' => 0.00, 'deposit_amount' => 0.00, 'payment_modes' => 'free', 'requires_waiver' => 1, 'members_only' => 1, 'member_discount' => 0.00 ),
		);
		foreach ( $types as $bt ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$booking_types_table} WHERE slug = %s", $bt['slug'] ) );
			if ( $exists ) continue;
			$default_settings = array();
			if ( 'ccw-class' === $bt['slug'] ) {
				$default_settings = array(
					'event_instructor'  => 'G2A Certified Instructor',
					'event_total_seats' => 12,
					'event_start_time'  => '09:00',
					'event_end_time'    => '17:00',
					'event_weekdays'    => array( 2, 6 ),
				);
			}
			$bt['settings']   = wp_json_encode( $default_settings );
			$bt['is_active']  = 1;
			$bt['created_at'] = $now;
			$bt['updated_at'] = $now;
			$wpdb->insert( $booking_types_table, $bt );
		}

		// Default form.
		$form_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$forms_table} WHERE slug = %s", 'default-lane-booking' ) );
		if ( ! $form_id ) {
			$wpdb->insert( $forms_table, array(
				'name'           => __( 'Default Lane Booking Form', 'g2a-booking' ),
				'slug'           => 'default-lane-booking',
				'description'    => __( 'Out-of-the-box lane booking form.', 'g2a-booking' ),
				'schema_version' => 1,
				'settings'       => wp_json_encode( array( 'submit_label' => 'Reserve My Lane' ) ),
				'is_active'      => 1,
				'created_at'     => $now,
				'updated_at'     => $now,
			) );
			$form_id = (int) $wpdb->insert_id;

			$fields = array(
				array( 'field_key' => 'customer_name', 'field_type' => 'text', 'label' => __( 'Full Name', 'g2a-booking' ), 'placeholder' => 'John Doe', 'is_required' => 1, 'sort_order' => 1 ),
				array( 'field_key' => 'customer_email', 'field_type' => 'email', 'label' => __( 'Email', 'g2a-booking' ), 'placeholder' => 'you@example.com', 'is_required' => 1, 'sort_order' => 2 ),
				array( 'field_key' => 'customer_phone', 'field_type' => 'phone', 'label' => __( 'Phone', 'g2a-booking' ), 'placeholder' => '(555) 555-5555', 'is_required' => 1, 'sort_order' => 3 ),
				array( 'field_key' => 'party_size', 'field_type' => 'number', 'label' => __( 'Number of Shooters', 'g2a-booking' ), 'validation' => wp_json_encode( array( 'min' => 1, 'max' => 3 ) ), 'is_required' => 1, 'sort_order' => 4 ),
				array( 'field_key' => 'experience_level', 'field_type' => 'dropdown', 'label' => __( 'Experience Level', 'g2a-booking' ), 'options' => wp_json_encode( array(
					array( 'value' => 'beginner', 'label' => __( 'Beginner', 'g2a-booking' ) ),
					array( 'value' => 'intermediate', 'label' => __( 'Intermediate', 'g2a-booking' ) ),
					array( 'value' => 'expert', 'label' => __( 'Expert', 'g2a-booking' ) ),
				) ), 'is_required' => 1, 'sort_order' => 5 ),
				array( 'field_key' => 'waiver_acceptance', 'field_type' => 'waiver', 'label' => __( 'I have read and accept the range safety waiver.', 'g2a-booking' ), 'is_required' => 1, 'sort_order' => 6 ),
			);
			foreach ( $fields as $field ) {
				$field['form_id']    = $form_id;
				$field['validation'] = $field['validation'] ?? wp_json_encode( array() );
				$field['options']    = $field['options']    ?? wp_json_encode( array() );
				$wpdb->insert( $form_fields_table, $field );
			}
		}

		// Business hours defaults from client doc.
		$rule_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rules_table} WHERE rule_type = 'business_hours'" );
		if ( 0 === $rule_count ) {
			$hours_map = array(
				1 => array( '10:00:00', '18:00:00' ), // Mon
				2 => array( '10:00:00', '18:00:00' ), // Tue
				3 => array( '10:00:00', '18:00:00' ), // Wed
				4 => array( '10:00:00', '18:00:00' ), // Thu
				5 => array( '10:00:00', '19:00:00' ), // Fri
				6 => array( '10:00:00', '19:00:00' ), // Sat
				0 => array( '12:00:00', '18:00:00' ), // Sun
			);
			foreach ( $hours_map as $dow => $times ) {
				$wpdb->insert( $rules_table, array(
					'rule_type'   => 'business_hours',
					'day_of_week' => $dow,
					'start_time'  => $times[0],
					'end_time'    => $times[1],
					'priority'    => 10,
					'is_active'   => 1,
					'created_at'  => $now,
				) );
			}
		}

		self::seed_default_events();
	}

	public static function seed_default_events() {
		$defaults = array(
			array(
				'title'        => 'Arizona CCW Course',
				'type'         => 'ccw',
				'date'         => wp_date( 'Y-m-d', strtotime( '+7 days', current_time( 'timestamp' ) ) ),
				'time'         => '09:00 AM',
				'end'          => '01:00 PM',
				'location'     => 'Guns 2 Ammo - Training Classroom',
				'price'        => '$99',
				'instructor'   => 'G2A Certified Instructor',
				'seats'        => 12,
				'booking_slug' => 'ccw-class',
			),
			array(
				'title'        => 'Ladies Tuesday Free Lane Hour',
				'type'         => 'ladies-day',
				'date'         => wp_date( 'Y-m-d', strtotime( 'next tuesday', current_time( 'timestamp' ) ) ),
				'time'         => '10:00 AM',
				'end'          => '06:00 PM',
				'location'     => 'Guns 2 Ammo - Range Lanes',
				'price'        => 'Free 1-hour lane time',
				'instructor'   => '',
				'seats'        => 18,
				'booking_slug' => 'ladies-tuesday',
			),
		);

		foreach ( $defaults as $event ) {
			$existing = get_page_by_title( $event['title'], OBJECT, 'g2a_events' );
			if ( $existing ) {
				continue;
			}
			$post_id = wp_insert_post(
				array(
					'post_title'   => $event['title'],
					'post_type'    => 'g2a_events',
					'post_status'  => 'publish',
					'post_content' => '',
				),
				true
			);
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}
			update_post_meta( $post_id, '_g2ab_event_date', $event['date'] );
			update_post_meta( $post_id, '_g2ab_event_time', $event['time'] );
			update_post_meta( $post_id, '_g2ab_event_end', $event['end'] );
			update_post_meta( $post_id, '_g2ab_event_location', $event['location'] );
			update_post_meta( $post_id, '_g2ab_event_price', $event['price'] );
			update_post_meta( $post_id, '_g2ab_event_type', $event['type'] );
			update_post_meta( $post_id, '_g2ab_event_instructor', $event['instructor'] );
			update_post_meta( $post_id, '_g2ab_event_seats', (int) $event['seats'] );
			update_post_meta( $post_id, '_g2ab_event_booking_type_slug', $event['booking_slug'] );
			update_post_meta( $post_id, '_g2ab_event_cta_label', 'Book Now' );
			update_post_meta( $post_id, '_g2ab_event_cta_url', add_query_arg( 'type', $event['booking_slug'], home_url( '/book-a-lane/' ) ) );
		}
	}

	public static function apply_client_defaults_existing() {
		global $wpdb;
		$now = current_time( 'mysql' );
		$booking_types_table = $wpdb->prefix . 'g2ab_booking_types';
		$rules_table         = $wpdb->prefix . 'g2ab_availability_rules';

		update_option( 'g2ab_lane_hourly_price', 20 );
		update_option( 'g2ab_lane_extra_shooter_fee', 15 );
		update_option( 'g2ab_ladies_tuesday_lane_price', 0 );
		update_option( 'g2ab_form_subtitle', 'Guns 2 Ammo Range Booking' );
		update_option( 'g2ab_form_description', 'Members receive included lane time with qualifying plans plus discounted rentals and priority booking access.' );

		$wpdb->update( $booking_types_table, array( 'base_price' => 20.00, 'updated_at' => $now ), array( 'slug' => 'lane-booking' ), array( '%f', '%s' ), array( '%s' ) );

		$exists_ladies = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$booking_types_table} WHERE slug = %s LIMIT 1", 'ladies-tuesday' ) );
		if ( ! $exists_ladies ) {
			$wpdb->insert( $booking_types_table, array(
				'name'           => 'Ladies Tuesday',
				'slug'           => 'ladies-tuesday',
				'category'       => 'lane',
				'duration_min'   => 60,
				'buffer_before'  => 0,
				'buffer_after'   => 10,
				'base_price'     => 0.00,
				'deposit_amount' => 0.00,
				'payment_modes'  => 'free',
				'requires_waiver'=> 1,
				'members_only'   => 0,
				'member_discount'=> 0.00,
				'capacity_mode'  => 'booking_count',
				'settings'       => wp_json_encode( array() ),
				'is_active'      => 1,
				'created_at'     => $now,
				'updated_at'     => $now,
			) );
		}

		$hours_map = array(
			1 => array( '10:00:00', '18:00:00' ),
			2 => array( '10:00:00', '18:00:00' ),
			3 => array( '10:00:00', '18:00:00' ),
			4 => array( '10:00:00', '18:00:00' ),
			5 => array( '10:00:00', '19:00:00' ),
			6 => array( '10:00:00', '19:00:00' ),
			0 => array( '12:00:00', '18:00:00' ),
		);
		foreach ( $hours_map as $dow => $times ) {
			$rule_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$rules_table} WHERE rule_type = 'business_hours' AND day_of_week = %d ORDER BY id ASC LIMIT 1",
				$dow
			) );
			if ( $rule_id ) {
				$wpdb->update( $rules_table, array(
					'start_time' => $times[0],
					'end_time'   => $times[1],
					'is_active'  => 1,
				), array( 'id' => $rule_id ), array( '%s', '%s', '%d' ), array( '%d' ) );
			}
		}
	}

	/**
	 * Ensure every module manifest with default_active=true is present in the
	 * g2ab_addons_active option. Idempotent: only adds missing IDs, never
	 * removes user-deactivated ones. Makes new modules in plugin updates
	 * activate automatically without manual toggling in the Addons UI.
	 *
	 * @since 1.1.2
	 */
	private static function merge_default_active_modules() {
		$dir = G2AB_PATH . 'includes/modules/';
		if ( ! is_dir( $dir ) ) return;

		$active = get_option( 'g2ab_addons_active', null );
		if ( ! is_array( $active ) ) $active = array();

		$folders = glob( $dir . '*', GLOB_ONLYDIR );
		if ( empty( $folders ) ) return;

		$changed = false;
		foreach ( $folders as $folder ) {
			$manifest_file = $folder . '/module.php';
			if ( ! file_exists( $manifest_file ) ) continue;

			try {
				$manifest = include $manifest_file;
			} catch ( \Throwable $e ) {
				error_log(
					sprintf(
						'[G2AB] Failed loading module manifest during activation: %s in %s',
						$e->getMessage(),
						$manifest_file
					)
				);
				continue;
			}

			if ( ! is_array( $manifest ) || empty( $manifest['id'] ) || empty( $manifest['default_active'] ) ) {
				continue;
			}

			$module_id = sanitize_key( $manifest['id'] );
			if ( $module_id && ! in_array( $module_id, $active, true ) ) {
				$active[] = $module_id;
				$changed  = true;
			}
		}

		if ( $changed ) {
			update_option( 'g2ab_addons_active', array_values( array_unique( $active ) ) );
		}
	}

	private static function schedule_events() {
		$events = array(
			// Hold-expiry runs every 5 minutes so abandoned online checkouts free
			// the slot before the next customer gives up.
			'g2ab_cleanup_expired_reservations' => 'every_five_minutes',
			'g2ab_send_booking_reminders'       => 'hourly',
			'g2ab_daily_reports'                => 'daily',
		);

		foreach ( $events as $hook => $recurrence ) {
			$next = wp_next_scheduled( $hook );
			if ( $next && wp_get_schedule( $hook ) !== $recurrence ) {
				// Old schedule (likely 'hourly' from <1.2.4) — replace it.
				wp_unschedule_event( $next, $hook );
				$next = false;
			}
			if ( ! $next ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
			}
		}
	}
}
