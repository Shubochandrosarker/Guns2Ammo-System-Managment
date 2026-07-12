<?php
/**
 * Verifyistic_Webhooks — multi-connection webhook manager.
 *
 * Stores an unlimited list of outbound webhook "connections" so verification
 * data can fan out to several platforms at once (Zapier, Make, n8n, a CRM,
 * Google Sheets Apps Script, a custom endpoint, etc.). Each connection has its
 * own URL, HMAC secret, enabled flag and event subscription.
 *
 * Backward compatible: the legacy single verifyistic_webhook_url / _secret /
 * _enabled options are auto-migrated into a connection named "Primary".
 *
 * Every send is recorded in {prefix}verifyistic_webhook_deliveries; failed
 * deliveries are retried by single cron events with capped backoff.
 *
 * @package Verifyistic
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Verifyistic_Webhooks {

	const OPTION       = 'verifyistic_webhooks';
	const TABLE        = 'verifyistic_webhook_deliveries';
	const CRON_RETRY   = 'verifyistic_webhook_retry';
	const MAX_ATTEMPTS = 5;

	/**
	 * Hook the retry cron handler. Called once from plugin boot.
	 */
	public static function init() {
		add_action( self::CRON_RETRY, array( __CLASS__, 'attempt_delivery' ), 10, 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Schema                                                             */
	/* ------------------------------------------------------------------ */

	public static function create_table() {
		global $wpdb;
		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id  VARCHAR(40)  NOT NULL DEFAULT '',
			connection_name VARCHAR(120) NOT NULL DEFAULT '',
			log_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			event          VARCHAR(40)  NOT NULL DEFAULT '',
			url            TEXT         NOT NULL,
			payload        LONGTEXT     NOT NULL,
			status         VARCHAR(20)  NOT NULL DEFAULT 'pending',
			attempts       SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 0,
			last_code      SMALLINT(5)  NOT NULL DEFAULT 0,
			last_error     TEXT         NOT NULL,
			next_attempt   DATETIME     DEFAULT NULL,
			created_at     DATETIME     NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at     DATETIME     NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY connection_id (connection_id),
			KEY status (status),
			KEY log_id (log_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ------------------------------------------------------------------ */
	/* Connections CRUD                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Return all configured connections (migrating the legacy single webhook
	 * the first time it's read).
	 *
	 * @return array[] List of normalized connection arrays.
	 */
	public static function get_connections() {
		$raw = get_option( self::OPTION, null );

		if ( null === $raw || '' === $raw ) {
			$migrated = self::migrate_legacy();
			return $migrated;
		}

		$list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		return array_values( array_filter( array_map( array( __CLASS__, 'normalize_connection' ), $list ) ) );
	}

	/**
	 * Persist a list of connections.
	 *
	 * @param array $list Raw connection rows.
	 */
	public static function save_connections( $list ) {
		$clean = array();
		foreach ( (array) $list as $row ) {
			$conn = self::normalize_connection( $row );
			if ( $conn && '' !== $conn['url'] ) {
				$clean[] = $conn;
			}
		}
		update_option( self::OPTION, $clean );
		return $clean;
	}

	/**
	 * Normalize + sanitize one connection row.
	 *
	 * @return array|null
	 */
	public static function normalize_connection( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$url = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
		$events = array();
		if ( ! empty( $row['events'] ) ) {
			$src = is_array( $row['events'] ) ? $row['events'] : explode( ',', (string) $row['events'] );
			foreach ( $src as $ev ) {
				$ev = sanitize_key( trim( $ev ) );
				if ( in_array( $ev, array( 'passed', 'failed', 'declined' ), true ) ) {
					$events[] = $ev;
				}
			}
		}
		return array(
			'id'       => ! empty( $row['id'] ) ? sanitize_key( $row['id'] ) : 'wh_' . substr( md5( $url . microtime( true ) . wp_rand() ), 0, 12 ),
			'name'     => isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '',
			'url'      => $url,
			'secret'   => isset( $row['secret'] ) ? sanitize_text_field( $row['secret'] ) : '',
			'platform' => isset( $row['platform'] ) ? sanitize_text_field( $row['platform'] ) : '',
			'enabled'  => empty( $row['enabled'] ) ? 0 : 1,
			'events'   => array_values( array_unique( $events ) ), // empty = all events
		);
	}

	/**
	 * One-time migration of the legacy single-webhook options into a
	 * connection list.
	 *
	 * @return array[]
	 */
	private static function migrate_legacy() {
		$legacy_url = esc_url_raw( (string) get_option( 'verifyistic_webhook_url', '' ) );
		$list       = array();
		if ( $legacy_url ) {
			$list[] = self::normalize_connection( array(
				'name'     => __( 'Primary', 'verifyistic' ),
				'url'      => $legacy_url,
				'secret'   => (string) get_option( 'verifyistic_webhook_secret', '' ),
				'platform' => 'legacy',
				'enabled'  => (int) get_option( 'verifyistic_webhook_enabled', 0 ) ? 1 : 0,
				'events'   => array(),
			) );
		}
		update_option( self::OPTION, $list );
		return $list;
	}

	/* ------------------------------------------------------------------ */
	/* Dispatch                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Fan a payload out to every enabled connection subscribed to $event_key.
	 *
	 * @param string $event_key One of: passed | failed | declined.
	 * @param array  $payload   JSON payload (the event name is added/kept).
	 * @param int    $log_id    Source verification log id (0 if none).
	 * @return int Number of connections the payload was dispatched to.
	 */
	public static function dispatch( $event_key, array $payload, $log_id = 0 ) {
		$event_key = sanitize_key( $event_key );
		$count     = 0;

		foreach ( self::get_connections() as $conn ) {
			if ( empty( $conn['enabled'] ) || empty( $conn['url'] ) ) {
				continue;
			}
			if ( ! empty( $conn['events'] ) && ! in_array( $event_key, $conn['events'], true ) ) {
				continue; // not subscribed
			}
			$delivery_id = self::record_delivery( $conn, $event_key, $payload, (int) $log_id );
			if ( $delivery_id ) {
				self::attempt_delivery( $delivery_id );
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Insert a 'pending' delivery row.
	 *
	 * @return int delivery id
	 */
	private static function record_delivery( $conn, $event_key, $payload, $log_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'connection_id'   => $conn['id'],
				'connection_name' => $conn['name'],
				'log_id'          => $log_id,
				'event'           => $event_key,
				'url'             => $conn['url'],
				'payload'         => wp_json_encode( $payload ),
				'status'          => 'pending',
				'attempts'        => 0,
				'last_error'      => '',
				'next_attempt'    => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Try to deliver one queued delivery. Cron callback + inline caller.
	 *
	 * @param int $delivery_id
	 */
	public static function attempt_delivery( $delivery_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $delivery_id ) );
		if ( ! $row || 'success' === $row->status || 'failed' === $row->status ) {
			return;
		}

		// Resolve the current secret for this connection (so rotating the
		// secret applies to in-flight retries too).
		$secret = '';
		foreach ( self::get_connections() as $conn ) {
			if ( $conn['id'] === $row->connection_id ) {
				$secret = $conn['secret'];
				break;
			}
		}

		$payload = json_decode( (string) $row->payload, true );
		$payload = is_array( $payload ) ? $payload : array();
		$result  = Verifyistic_Webhook::send( $row->url, $payload, $secret );

		$attempts = (int) $row->attempts + 1;
		$now      = current_time( 'mysql' );

		if ( ! is_wp_error( $result ) ) {
			$wpdb->update(
				$table,
				array( 'status' => 'success', 'attempts' => $attempts, 'last_code' => (int) wp_remote_retrieve_response_code( $result ), 'last_error' => '', 'next_attempt' => null, 'updated_at' => $now ),
				array( 'id' => (int) $row->id ),
				array( '%s', '%d', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( (int) $row->log_id ) {
				Verifyistic_DB::mark_webhook_sent( (int) $row->log_id );
			}
			return;
		}

		// Failure path — schedule a backed-off retry or mark dead.
		$error = $result->get_error_message();
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$wpdb->update(
				$table,
				array( 'status' => 'failed', 'attempts' => $attempts, 'last_error' => $error, 'next_attempt' => null, 'updated_at' => $now ),
				array( 'id' => (int) $row->id ),
				array( '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$delay_min = self::backoff_minutes( $attempts );
		$next_ts   = time() + ( $delay_min * 60 );
		$wpdb->update(
			$table,
			array( 'status' => 'pending', 'attempts' => $attempts, 'last_error' => $error, 'next_attempt' => gmdate( 'Y-m-d H:i:s', $next_ts ), 'updated_at' => $now ),
			array( 'id' => (int) $row->id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		wp_schedule_single_event( $next_ts, self::CRON_RETRY, array( (int) $row->id ) );
	}

	/**
	 * Capped exponential backoff (minutes) keyed by attempt number.
	 */
	private static function backoff_minutes( $attempt ) {
		$schedule = array( 1 => 2, 2 => 5, 3 => 15, 4 => 60 );
		return isset( $schedule[ $attempt ] ) ? $schedule[ $attempt ] : 60;
	}

	/* ------------------------------------------------------------------ */
	/* Reporting                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Recent delivery rows for the admin dashboard.
	 */
	public static function recent_deliveries( $limit = 25 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", absint( $limit ) ) );
	}
}
