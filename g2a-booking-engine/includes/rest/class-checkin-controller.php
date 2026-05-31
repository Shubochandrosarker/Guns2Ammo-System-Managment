<?php
/**
 * Public-facing check-in endpoints (the routes the MEMBER's phone hits
 * after scanning the dashboard QR). Pairs with the staff console's
 * pending/poll/finalize flow.
 *
 * Endpoints:
 *   POST /checkin/initiate?station=<token>   submit identity
 *   GET  /checkin/status?rid=<request_id>    poll terminal status
 *
 * Storage: each pending request lives in a transient keyed by station
 * AND by request id, with a 5-minute TTL. Staff confirm/decline writes
 * the terminal status; this controller reads it.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_REST_Checkin_Controller {

	const NS = G2AB_REST_NAMESPACE;

	public function register_routes() {
		register_rest_route( self::NS, '/checkin/initiate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'initiate' ),
			'permission_callback' => array( $this, 'permission_member' ),
			'args'                => array(
				'station'   => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
				'mode'      => array( 'required' => true,  'sanitize_callback' => 'sanitize_key' ),
				'email'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_email' ),
				'last_name' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( self::NS, '/checkin/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'status' ),
			'permission_callback' => array( $this, 'permission_member' ),
			'args'                => array(
				'rid' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	/**
	 * Public route — rate-limit by IP, require wp_rest nonce, no auth.
	 */
	public function permission_member( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Invalid nonce. Refresh the page.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		$ip   = function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key  = 'g2ab_rlci_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 30 ) {
			return new WP_Error( 'g2ab_rate_limited', __( 'Too many requests. Try again shortly.', 'g2a-booking' ), array( 'status' => 429 ) );
		}
		static $seen = array();
		$rid = spl_object_id( $request );
		if ( ! isset( $seen[ $rid ] ) ) {
			$seen[ $rid ] = true;
			set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
		}
		return true;
	}

	/**
	 * Member POSTs identity to be queued at the front desk.
	 */
	public function initiate( WP_REST_Request $request ) {
		$station = (string) $request->get_param( 'station' );
		if ( ! G2AB_REST_Staff_Controller::verify_station_token( $station ) ) {
			return new WP_Error( 'g2ab_bad_station', __( 'Station QR is no longer valid.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$mode = sanitize_key( $request->get_param( 'mode' ) );

		// Resolve the requester. Two paths:
		//  (1) mode=user — trust the current WP user (must be logged in)
		//  (2) mode=guest — match email + last name against people / archive
		$resolved = null;
		if ( 'user' === $mode && is_user_logged_in() ) {
			$u = wp_get_current_user();
			$resolved = self::resolve_by_email( $u->user_email, '' );
			if ( ! $resolved ) {
				// Fall back to a minimal "identified by login" record so the
				// staff can still confirm — they'll see the user but no waiver
				// (status=missing).
				$resolved = array(
					'source'     => 'wp_user',
					'person_id'  => 0,
					'name'       => $u->display_name ?: $u->user_login,
					'email'      => $u->user_email,
					'status'     => __( 'No waiver on file', 'g2a-booking' ),
					'state'      => 'missing',
					'signed_at'  => '',
					'expires_at' => '',
					'tier'       => '',
				);
			}
		} elseif ( 'guest' === $mode ) {
			$email = sanitize_email( (string) $request->get_param( 'email' ) );
			$last  = trim( (string) $request->get_param( 'last_name' ) );
			if ( ! is_email( $email ) || strlen( $last ) < 2 ) {
				return new WP_Error( 'g2ab_invalid', __( 'Please enter your email and last name.', 'g2a-booking' ), array( 'status' => 400 ) );
			}
			$resolved = self::resolve_by_email( $email, $last );
			if ( ! $resolved ) {
				// Still queue the request — staff can take a walk-in instead of
				// turning the person away.
				$resolved = array(
					'source'     => 'unknown',
					'person_id'  => 0,
					'name'       => trim( $last ),
					'email'      => $email,
					'status'     => __( 'No record found — walk-in', 'g2a-booking' ),
					'state'      => 'missing',
					'signed_at'  => '',
					'expires_at' => '',
					'tier'       => '',
				);
			}
		} else {
			return new WP_Error( 'g2ab_invalid_mode', __( 'Invalid request.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$rid = wp_generate_uuid4();
		$record = array_merge( $resolved, array(
			'request_id'   => $rid,
			'station'      => $station,
			'status'       => 'pending',
			'submitted_at' => current_time( 'mysql' ),
			'submitter_ip' => function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : '',
		) );

		// One pending request per station — newer replaces older so a member
		// who fat-fingered the form can re-submit cleanly.
		set_transient( 'g2ab_ci_pending_' . md5( $station ), $rid, 5 * MINUTE_IN_SECONDS );
		set_transient( 'g2ab_ci_request_' . $rid, $record, 5 * MINUTE_IN_SECONDS );

		return rest_ensure_response( array(
			'request_id' => $rid,
			'status'     => 'pending',
			'message'    => __( 'Front desk has been notified.', 'g2a-booking' ),
		) );
	}

	/**
	 * Member polls for the terminal status of their request.
	 */
	public function status( WP_REST_Request $request ) {
		$rid = (string) $request->get_param( 'rid' );
		if ( '' === $rid || ! preg_match( '/^[a-f0-9-]{36}$/i', $rid ) ) {
			return new WP_Error( 'g2ab_bad_rid', __( 'Invalid request id.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$record = get_transient( 'g2ab_ci_request_' . $rid );
		if ( ! is_array( $record ) ) {
			return rest_ensure_response( array( 'status' => 'expired', 'message' => __( 'Request expired. Please scan again.', 'g2a-booking' ) ) );
		}
		// Never reveal status/email/etc. back to the requester beyond a yes/no.
		$resp = array( 'status' => $record['status'] );
		if ( 'confirmed' === $record['status'] ) {
			$resp['message'] = isset( $record['confirm_message'] ) ? (string) $record['confirm_message'] : __( "You're checked in.", 'g2a-booking' );
		} elseif ( 'declined' === $record['status'] ) {
			$resp['message'] = isset( $record['decline_message'] ) ? (string) $record['decline_message'] : __( 'See the front desk.', 'g2a-booking' );
		}
		return rest_ensure_response( $resp );
	}

	/**
	 * Find a person by email (people first, archive second). Optionally
	 * cross-check the last name when called via the guest flow.
	 *
	 * @return array|null Normalized record (same shape as waiver_lookup results)
	 */
	public static function resolve_by_email( $email, $last_name = '' ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) return null;
		global $wpdb;
		$people  = $wpdb->prefix . 'memberistic_people';
		$archive = $wpdb->prefix . 'memberistic_waivers_archive';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $people ) ) === $people ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, full_name, email, waiver_status, waiver_signed_at, waiver_expires_at, membership_id
				 FROM {$people} WHERE LOWER(email) = %s LIMIT 1",
				$email
			), ARRAY_A );
			if ( $row ) {
				$ok_last = '' === $last_name || self::name_matches_last( (string) $row['full_name'], $last_name );
				if ( $ok_last ) {
					$status = G2AB_REST_Staff_Controller::resolve_waiver_status( $row );
					return array(
						'source'     => 'member',
						'person_id'  => (int) $row['id'],
						'name'       => (string) $row['full_name'],
						'email'      => (string) $row['email'],
						'status'     => $status['label'],
						'state'      => $status['state'],
						'signed_at'  => $row['waiver_signed_at'] ? wp_date( 'M j, Y', strtotime( $row['waiver_signed_at'] ) ) : '',
						'expires_at' => $row['waiver_expires_at'] ? wp_date( 'M j, Y', strtotime( $row['waiver_expires_at'] ) ) : '',
						'tier'       => G2AB_REST_Staff_Controller::membership_tier_label( (int) $row['membership_id'] ),
					);
				}
			}
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $archive ) ) === $archive ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, first_name, last_name, email, signed_at, is_current
				 FROM {$archive}
				 WHERE LOWER(email) = %s
				 ORDER BY is_current DESC, signed_at DESC
				 LIMIT 1",
				$email
			), ARRAY_A );
			if ( $row ) {
				$ok_last = '' === $last_name || 0 === strcasecmp( trim( $last_name ), trim( (string) $row['last_name'] ) );
				if ( $ok_last ) {
					return array(
						'source'     => 'archive',
						'person_id'  => 0,
						'archive_id' => (int) $row['id'],
						'name'       => trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ),
						'email'      => (string) $row['email'],
						'status'     => (int) $row['is_current'] === 1 ? __( 'Current (archived)', 'g2a-booking' ) : __( 'Expired (archived)', 'g2a-booking' ),
						'state'      => (int) $row['is_current'] === 1 ? 'current' : 'expired',
						'signed_at'  => $row['signed_at'] ? wp_date( 'M j, Y', strtotime( $row['signed_at'] ) ) : '',
						'expires_at' => '',
						'tier'       => '',
					);
				}
			}
		}
		return null;
	}

	private static function name_matches_last( $full_name, $last_name ) {
		$bits = preg_split( '/\s+/', trim( (string) $full_name ) );
		if ( ! $bits ) return false;
		$last_stored = strtolower( (string) end( $bits ) );
		return $last_stored === strtolower( trim( $last_name ) );
	}
}
