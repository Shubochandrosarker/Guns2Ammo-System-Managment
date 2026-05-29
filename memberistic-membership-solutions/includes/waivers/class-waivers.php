<?php
/**
 * Waivers — fully dynamic, all-member electronic waiver system.
 *
 * Generalises the corporate waiver engine to EVERY member (not just group
 * members). One tokenised public page lets anyone sign from any device with
 * no login; signatures are stored on the canonical memberistic_people rows
 * (waiver_status / waiver_signed_at / waiver_expires_at), mirrored onto
 * corporate group_members when the corporate module is present, and expire
 * automatically after a configurable validity window.
 *
 * Surfaces:
 *  - Public sign page:        GET /?memberistic_waiver=TOKEN
 *  - Member account CTA:      Waiver_Service::waiver_url( $user_id )
 *  - Email merge tag:         {waiver_url}
 *  - Admin console:           Memberistic → Waivers (bulk mark, email, settings)
 *  - Daily auto-expiry:       Waiver_Service::expire_due() (via Scheduler)
 *
 * Settings (options):
 *  - memberistic_waiver_text          Editable waiver body.
 *  - memberistic_waiver_validity_days Validity window in days (default 365).
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Emails\Email_Service;
use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_get_brand_label;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical waiver engine. Static, stateless.
 */
final class Waiver_Service {

	/** Per-user token meta key. Shared with the legacy corporate flow so
	 *  any waiver links already emailed keep resolving. */
	const TOKEN_META = 'memberistic_waiver_token';

	/** Public query var. */
	const QUERY = 'memberistic_waiver';

	/** Default validity window (days). */
	const DEFAULT_VALIDITY = 365;

	/**
	 * Validity window in days. Admin-configurable, filterable.
	 */
	public static function validity_days() {
		$days = (int) get_option( 'memberistic_waiver_validity_days', self::DEFAULT_VALIDITY );
		if ( $days <= 0 ) {
			$days = self::DEFAULT_VALIDITY;
		}
		return (int) apply_filters( 'memberistic_waiver_validity_days', $days );
	}

	/**
	 * The waiver body. Admin-editable via the Waivers settings, filterable.
	 * NOT legal advice — the range should have counsel review the final text.
	 */
	public static function waiver_text() {
		$saved = (string) get_option( 'memberistic_waiver_text', '' );
		if ( '' === trim( $saved ) ) {
			$brand = memberistic_get_brand_label();
			$saved = sprintf(
				/* translators: 1,2: business/brand name */
				__( 'I acknowledge that the use of firearms and the shooting range at %1$s involves inherent risks. I confirm I am legally permitted to handle firearms, will follow all posted range rules and staff instructions at all times, and assume responsibility for my own safety and conduct on the premises. I release %2$s, its owners, staff, and affiliates from liability for injury or loss arising from my voluntary participation, to the extent permitted by law.', 'memberistic' ),
				$brand,
				$brand
			);
		}
		return (string) apply_filters( 'memberistic_waiver_text', $saved );
	}

	public static function get_or_create_token( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		$t = (string) get_user_meta( $user_id, self::TOKEN_META, true );
		if ( '' === $t ) {
			$t = wp_generate_password( 40, false, false );
			update_user_meta( $user_id, self::TOKEN_META, $t );
		}
		return $t;
	}

	public static function waiver_url( $user_id ) {
		$t = self::get_or_create_token( $user_id );
		return $t ? add_query_arg( self::QUERY, $t, home_url( '/' ) ) : home_url( '/account/' );
	}

	public static function user_by_token( $token ) {
		global $wpdb;
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return 0;
		}
		$uid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::TOKEN_META,
				$token
			)
		);
		return $uid ?: 0;
	}

	/**
	 * Whether a person row's waiver is signed AND not past its expiry.
	 *
	 * @param array<string,mixed> $person People row.
	 */
	public static function is_current( $person ) {
		if ( empty( $person['waiver_status'] ) || 'signed' !== $person['waiver_status'] ) {
			return false;
		}
		if ( empty( $person['waiver_expires_at'] ) ) {
			return true;
		}
		return strtotime( (string) $person['waiver_expires_at'] ) > current_time( 'timestamp' );
	}

	/**
	 * Current waiver status for a WP user (resolved via their primary person row).
	 */
	public static function status_for_user( $user_id ) {
		$membership = Memberships_Repository::get_by_user_id( (int) $user_id );
		if ( $membership ) {
			$person = People_Repository::get_primary_by_membership( (int) $membership['id'] );
			if ( $person && ! empty( $person['waiver_status'] ) ) {
				return (string) $person['waiver_status'];
			}
		}
		return 'missing';
	}

	/**
	 * Record a signature for a WP user. Updates every person row tied to that
	 * user, stamps signed/expiry, logs activity, and mirrors onto corporate
	 * group_members when the corporate module is present.
	 *
	 * @param int    $user_id    WP user id.
	 * @param string $typed_name Typed signature.
	 * @param array  $meta       Optional context (ip, source).
	 * @return bool
	 */
	public static function mark_signed( $user_id, $typed_name = '', $meta = array() ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		$now     = current_time( 'mysql' );
		$expires = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( self::validity_days() * DAY_IN_SECONDS ) );

		// Update every person record tied to this user across all memberships.
		global $wpdb;
		$people_table = People_Repository::table();
		$person_rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, membership_id FROM {$people_table} WHERE wp_user_id = %d", $user_id ),
			ARRAY_A
		);
		foreach ( (array) $person_rows as $pr ) {
			People_Repository::update(
				(int) $pr['id'],
				array(
					'waiver_status'     => 'signed',
					'waiver_signed_at'  => $now,
					'waiver_expires_at' => $expires,
				)
			);
		}

		// Mirror onto corporate group_members + log, if corporate is present.
		if ( class_exists( '\WordPressistic\Memberistic\Corporate\Corporate_Members_Repository' ) ) {
			$corp_table = \WordPressistic\Memberistic\Corporate\Corporate_Members_Repository::table();
			$corp_rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, group_id FROM {$corp_table} WHERE user_id = %d AND status != 'removed'", $user_id ),
				ARRAY_A
			);
			foreach ( (array) $corp_rows as $cr ) {
				\WordPressistic\Memberistic\Corporate\Corporate_Members_Repository::update_waiver( (int) $cr['id'], 'signed' );
			}
		}

		$signer = $typed_name ?: ( get_userdata( $user_id ) ? get_userdata( $user_id )->display_name : ( '#' . $user_id ) );
		update_user_meta( $user_id, 'memberistic_waiver_signed_name', sanitize_text_field( $typed_name ) );
		update_user_meta( $user_id, 'memberistic_waiver_signed_at', $now );
		update_user_meta( $user_id, 'memberistic_waiver_signed_ip', isset( $meta['ip'] ) ? sanitize_text_field( (string) $meta['ip'] ) : '' );

		$membership = Memberships_Repository::get_by_user_id( $user_id );
		if ( $membership ) {
			Activity_Repository::log(
				array(
					'membership_id' => (int) $membership['id'],
					'activity_type' => 'waiver_signed',
					'title'         => sprintf( /* translators: %s: signer */ __( 'Waiver signed by %s', 'memberistic' ), $signer ),
				)
			);
		}
		return true;
	}

	/**
	 * Mark a single person row signed (used for linked members / staff action
	 * on members who have no WP user). Mirrors to the user if one is linked.
	 *
	 * @param int    $person_id  People row id.
	 * @param string $typed_name Optional signer name.
	 * @return bool
	 */
	public static function mark_person_signed( $person_id, $typed_name = '' ) {
		$person = People_Repository::get( (int) $person_id );
		if ( ! $person ) {
			return false;
		}
		if ( ! empty( $person['wp_user_id'] ) ) {
			return self::mark_signed( (int) $person['wp_user_id'], $typed_name, array( 'source' => 'staff' ) );
		}
		$now     = current_time( 'mysql' );
		$expires = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( self::validity_days() * DAY_IN_SECONDS ) );
		People_Repository::update(
			(int) $person_id,
			array(
				'waiver_status'     => 'signed',
				'waiver_signed_at'  => $now,
				'waiver_expires_at' => $expires,
			)
		);
		if ( ! empty( $person['membership_id'] ) ) {
			Activity_Repository::log(
				array(
					'membership_id' => (int) $person['membership_id'],
					'person_id'     => (int) $person_id,
					'activity_type' => 'waiver_signed',
					'title'         => __( 'Waiver marked signed by staff', 'memberistic' ),
				)
			);
		}
		return true;
	}

	/**
	 * Bulk-mark active members' waivers as signed (the "everyone signed in
	 * store" backlog). Only touches active people without a current signed
	 * waiver. Returns the number updated.
	 *
	 * @param string $source Audit source label.
	 */
	public static function bulk_mark_active_signed( $source = 'bulk_instore' ) {
		global $wpdb;
		$table   = People_Repository::table();
		$now     = current_time( 'mysql' );
		$expires = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( self::validity_days() * DAY_IN_SECONDS ) );

		$updated = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET waiver_status = 'signed', waiver_signed_at = %s, waiver_expires_at = %s WHERE status = 'active' AND waiver_status IN ( 'missing', 'expired', 'needs_review' )",
				$now,
				$expires
			)
		);

		// Mirror to corporate members — correlated to the people rows we just
		// signed (via the shared wp user), so we never flip a group member
		// whose canonical person row wasn't actually backlogged.
		if ( $updated > 0 && class_exists( '\WordPressistic\Memberistic\Corporate\Corporate_Members_Repository' ) ) {
			$corp = \WordPressistic\Memberistic\Corporate\Corporate_Members_Repository::table();
			$wpdb->query(
				"UPDATE {$corp} c
				 INNER JOIN {$table} p ON p.wp_user_id = c.user_id AND p.waiver_status = 'signed'
				 SET c.waiver_status = 'signed'
				 WHERE c.status = 'active' AND c.waiver_status IN ( 'missing', 'expired', 'needs_review' )"
			);
		}
		return $updated;
	}

	/**
	 * Daily expiry sweep: flip signed waivers past their expiry to 'expired'.
	 * Returns the number expired. Called from the Scheduler.
	 */
	public static function expire_due() {
		global $wpdb;
		$table = People_Repository::table();
		$now   = current_time( 'mysql' );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET waiver_status = 'expired' WHERE waiver_status = 'signed' AND waiver_expires_at IS NOT NULL AND waiver_expires_at <> '0000-00-00 00:00:00' AND waiver_expires_at < %s",
				$now
			)
		);
	}

	/**
	 * Counts of active people by waiver bucket.
	 *
	 * @return array{signed:int,missing:int,expired:int,needs_review:int,total:int}
	 */
	public static function counts() {
		global $wpdb;
		$table = People_Repository::table();
		$rows  = $wpdb->get_results( "SELECT waiver_status AS s, COUNT(*) AS c FROM {$table} WHERE status = 'active' GROUP BY waiver_status", ARRAY_A ) ?: array();
		$out   = array( 'signed' => 0, 'missing' => 0, 'expired' => 0, 'needs_review' => 0, 'total' => 0 );
		foreach ( $rows as $r ) {
			$s = (string) $r['s'];
			$out[ $s ] = ( $out[ $s ] ?? 0 ) + (int) $r['c'];
			$out['total'] += (int) $r['c'];
		}
		return $out;
	}

	/**
	 * List active people for the admin table, optionally filtered by waiver
	 * status. Joins the primary membership for context.
	 *
	 * @param string $status One of signed|missing|expired|needs_review|'' (all).
	 * @param int    $limit
	 * @param int    $offset
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_people( $status = '', $limit = 100, $offset = 0 ) {
		global $wpdb;
		$people = People_Repository::table();
		$mem    = Memberships_Repository::table();
		$limit  = max( 1, min( 500, (int) $limit ) );
		$offset = max( 0, (int) $offset );

		$where      = array( "p.status = 'active'" );
		$where_args = array();
		if ( in_array( $status, array( 'signed', 'missing', 'expired', 'needs_review' ), true ) ) {
			$where[]      = 'p.waiver_status = %s';
			$where_args[] = $status;
		}
		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$sql = "SELECT p.id, p.full_name, p.email, p.role, p.wp_user_id, p.waiver_status, p.waiver_signed_at, p.waiver_expires_at, m.status AS membership_status, m.membership_uuid
			FROM {$people} p LEFT JOIN {$mem} m ON m.id = p.membership_id
			{$where_sql} ORDER BY p.full_name ASC LIMIT %d OFFSET %d";
		$args = array_merge( $where_args, array( $limit, $offset ) );

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Email the waiver link to every active member missing a current waiver.
	 * Returns the number of emails dispatched.
	 */
	public static function email_all_missing() {
		$rows = People_Repository::get_active_missing_waiver();
		$sent = 0;
		foreach ( (array) $rows as $row ) {
			if ( Email_Service::send_membership_email( (int) $row['membership_id'], 'waiver_missing' ) ) {
				$sent++;
			}
		}
		return $sent;
	}
}

/**
 * Public, tokenised waiver signing page. Supersedes the corporate-only
 * handler (which is no longer registered) and serves every member.
 */
final class Waiver_Public {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	public static function maybe_handle() {
		if ( empty( $_GET[ Waiver_Service::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token   = sanitize_text_field( wp_unslash( $_GET[ Waiver_Service::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = Waiver_Service::user_by_token( $token );
		if ( ! $user_id ) {
			self::render( __( 'Waiver not found', 'memberistic' ), '<p>' . esc_html__( 'This waiver link is invalid or has expired. Please contact the range.', 'memberistic' ) . '</p>', 404 );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			self::render( __( 'Waiver not found', 'memberistic' ), '<p>' . esc_html__( 'This waiver link is invalid. Please contact the range.', 'memberistic' ) . '</p>', 404 );
		}

		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			if ( ! isset( $_POST['memberistic_waiver_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['memberistic_waiver_nonce'] ) ), 'memberistic_waiver_' . $token ) ) {
				self::render( __( 'Session expired', 'memberistic' ), '<p>' . esc_html__( 'Please reload the page and try again.', 'memberistic' ) . '</p>', 400 );
			}
			$typed = isset( $_POST['signature_name'] ) ? sanitize_text_field( wp_unslash( $_POST['signature_name'] ) ) : '';
			$agree = ! empty( $_POST['agree'] );
			if ( ! $agree || '' === $typed ) {
				self::render_form( $user, $token, __( 'Please type your full name and check the agreement box.', 'memberistic' ) );
			}
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			Waiver_Service::mark_signed( $user_id, $typed, array( 'ip' => $ip, 'source' => 'self_serve' ) );
			$account = home_url( '/account/' );
			self::render(
				__( 'Waiver complete', 'memberistic' ),
				'<p>' . esc_html__( 'Thank you — your waiver is on file. Show your member QR at the range desk on your next visit.', 'memberistic' ) . '</p>'
				. '<p><a class="btn" href="' . esc_url( $account ) . '">' . esc_html__( 'Go to my account', 'memberistic' ) . '</a></p>',
				200
			);
		}

		// Already current?
		$membership = Memberships_Repository::get_by_user_id( $user_id );
		$person     = $membership ? People_Repository::get_primary_by_membership( (int) $membership['id'] ) : null;
		if ( $person && Waiver_Service::is_current( $person ) ) {
			$exp = ! empty( $person['waiver_expires_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $person['waiver_expires_at'] ) ) : '';
			$msg = $exp
				? sprintf( /* translators: %s: date */ esc_html__( 'Your waiver is signed and on file (valid through %s). Thank you!', 'memberistic' ), esc_html( $exp ) )
				: esc_html__( 'Your waiver is signed and on file. Thank you!', 'memberistic' );
			self::render( __( 'Waiver already on file', 'memberistic' ), '<p>' . $msg . '</p>', 200 );
		}

		self::render_form( $user, $token );
	}

	private static function render_form( $user, $token, $error = '' ) {
		$text = Waiver_Service::waiver_text();
		ob_start();
		if ( $error ) {
			echo '<p style="color:#E8802F;font-weight:600;">' . esc_html( $error ) . '</p>';
		}
		echo '<div style="text-align:left;background:#11161D;border:1px solid #2A323D;border-radius:8px;padding:18px;max-height:280px;overflow:auto;color:#CBCAD2;line-height:1.6;font-size:14px;white-space:pre-wrap;">' . esc_html( $text ) . '</div>';
		echo '<form method="post" style="margin-top:18px;text-align:left;">';
		wp_nonce_field( 'memberistic_waiver_' . $token, 'memberistic_waiver_nonce' );
		echo '<label style="display:block;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8A95A5;margin-bottom:6px;">' . esc_html__( 'Type your full legal name', 'memberistic' ) . '</label>';
		echo '<input type="text" name="signature_name" required value="' . esc_attr( $user->display_name ) . '" style="width:100%;padding:12px 14px;background:#0F1115;border:1px solid #2A323D;border-radius:4px;color:#fff;font-size:16px;margin-bottom:14px;box-sizing:border-box;">';
		echo '<label style="display:flex;gap:10px;align-items:flex-start;color:#CBCAD2;font-size:14px;cursor:pointer;"><input type="checkbox" name="agree" value="1" required style="width:20px;height:20px;margin-top:2px;"> <span>' . esc_html__( 'I have read and agree to the waiver above, and I am signing electronically.', 'memberistic' ) . '</span></label>';
		echo '<button type="submit" style="margin-top:18px;width:100%;padding:14px;background:#E8802F;color:#111;border:0;border-radius:4px;font-weight:700;font-size:15px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;">' . esc_html__( 'Sign Waiver', 'memberistic' ) . '</button>';
		echo '</form>';
		self::render( __( 'Range Waiver', 'memberistic' ), ob_get_clean(), 200 );
	}

	private static function render( $title, $html, $code = 200 ) {
		nocache_headers();
		status_header( (int) $code );
		header( 'Content-Type: text/html; charset=utf-8' );
		$brand = memberistic_get_brand_label();
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html( $brand ) . ' — ' . esc_html( $title ) . '</title>';
		echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0F1115;color:#fff;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;}'
			. '.b{max-width:520px;width:100%;background:#1A1F26;border:1px solid #2A323D;border-radius:12px;padding:32px;text-align:center;box-sizing:border-box;}'
			. '.brand{font-family:"Bebas Neue",Impact,sans-serif;letter-spacing:.18em;color:#C9A84C;text-transform:uppercase;font-size:13px;margin-bottom:14px;}'
			. 'h1{font-size:22px;margin:0 0 16px;}a.btn{display:inline-block;margin-top:8px;padding:12px 22px;background:#E8802F;color:#111;text-decoration:none;border-radius:4px;font-weight:700;}</style>';
		echo '</head><body><div class="b"><div class="brand">' . esc_html( $brand ) . '</div><h1>' . esc_html( $title ) . '</h1>' . $html . '</div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}

/**
 * Admin console: Memberistic → Waivers.
 */
final class Waiver_Admin_Page {

	const CAP = 'edit_memberistic_members';

	public static function handle_actions() {
		if ( empty( $_POST['memberistic_waiver_action'] ) ) {
			return;
		}
		if ( ! memberistic_current_user_can( self::CAP ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['memberistic_waiver_action'] ) );

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'memberistic_waivers' ) ) {
			return;
		}

		$notice = '';
		switch ( $action ) {
			case 'save_settings':
				$text  = isset( $_POST['waiver_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['waiver_text'] ) ) : '';
				$days  = isset( $_POST['waiver_validity_days'] ) ? absint( $_POST['waiver_validity_days'] ) : Waiver_Service::DEFAULT_VALIDITY;
				update_option( 'memberistic_waiver_text', $text );
				update_option( 'memberistic_waiver_validity_days', $days > 0 ? $days : Waiver_Service::DEFAULT_VALIDITY );
				$notice = __( 'Waiver settings saved.', 'memberistic' );
				break;
			case 'bulk_sign':
				$n      = Waiver_Service::bulk_mark_active_signed();
				$notice = sprintf( /* translators: %d: count */ __( 'Marked %d member(s) as signed.', 'memberistic' ), $n );
				break;
			case 'email_missing':
				$n      = Waiver_Service::email_all_missing();
				$notice = sprintf( /* translators: %d: count */ __( 'Sent the waiver link to %d member(s).', 'memberistic' ), $n );
				break;
			case 'mark_one':
				$pid = isset( $_POST['person_id'] ) ? absint( $_POST['person_id'] ) : 0;
				if ( $pid && Waiver_Service::mark_person_signed( $pid ) ) {
					$notice = __( 'Waiver marked signed.', 'memberistic' );
				}
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                     => 'memberistic-waivers',
					'memberistic_notice'       => rawurlencode( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render() {
		if ( ! memberistic_current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}

		$counts = Waiver_Service::counts();
		$filter = isset( $_GET['waiver_status'] ) ? sanitize_key( wp_unslash( $_GET['waiver_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = Waiver_Service::list_people( $filter, 200, 0 );
		$notice = isset( $_GET['memberistic_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['memberistic_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Waivers', 'memberistic' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Waiver status (active members)', 'memberistic' ); ?></h2>
				<p style="font-size:15px;">
					<strong style="color:#16794a;"><?php echo (int) $counts['signed']; ?></strong> <?php esc_html_e( 'signed', 'memberistic' ); ?>
					&nbsp;·&nbsp; <strong style="color:#b45309;"><?php echo (int) $counts['missing']; ?></strong> <?php esc_html_e( 'missing', 'memberistic' ); ?>
					&nbsp;·&nbsp; <strong style="color:#b45309;"><?php echo (int) $counts['expired']; ?></strong> <?php esc_html_e( 'expired', 'memberistic' ); ?>
					&nbsp;·&nbsp; <strong><?php echo (int) $counts['total']; ?></strong> <?php esc_html_e( 'total', 'memberistic' ); ?>
				</p>
				<form method="post" style="display:inline-block;margin-right:10px;" onsubmit="return confirm('<?php echo esc_js( __( 'Mark every active member with a missing or expired waiver as signed? Use this only for members who signed on paper in store.', 'memberistic' ) ); ?>');">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="bulk_sign">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Mark all missing/expired as signed (in-store backlog)', 'memberistic' ); ?></button>
				</form>
				<form method="post" style="display:inline-block;">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="email_missing">
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Email waiver link to all missing', 'memberistic' ); ?></button>
				</form>
			</div>

			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Waiver document', 'memberistic' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'memberistic_waivers' ); ?>
					<input type="hidden" name="memberistic_waiver_action" value="save_settings">
					<p>
						<label for="waiver_text" style="font-weight:600;display:block;margin-bottom:6px;"><?php esc_html_e( 'Waiver text shown to members when signing', 'memberistic' ); ?></label>
						<textarea id="waiver_text" name="waiver_text" rows="8" class="large-text" style="width:100%;"><?php echo esc_textarea( Waiver_Service::waiver_text() ); ?></textarea>
					</p>
					<p>
						<label for="waiver_validity_days" style="font-weight:600;"><?php esc_html_e( 'Validity (days before a signed waiver expires)', 'memberistic' ); ?></label><br>
						<input type="number" min="1" id="waiver_validity_days" name="waiver_validity_days" value="<?php echo esc_attr( (string) Waiver_Service::validity_days() ); ?>" class="small-text">
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save waiver settings', 'memberistic' ); ?></button></p>
				</form>
			</div>

			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Members', 'memberistic' ); ?></h2>
				<p>
					<?php
					$base = admin_url( 'admin.php?page=memberistic-waivers' );
					foreach ( array( '' => __( 'All', 'memberistic' ), 'missing' => __( 'Missing', 'memberistic' ), 'expired' => __( 'Expired', 'memberistic' ), 'signed' => __( 'Signed', 'memberistic' ) ) as $k => $label ) {
						$url = $k ? add_query_arg( 'waiver_status', $k, $base ) : $base;
						printf( '<a href="%s" class="button%s">%s</a> ', esc_url( $url ), ( $filter === $k ? ' button-primary' : '' ), esc_html( $label ) );
					}
					?>
				</p>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Member', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Email', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Role', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Waiver', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Signed', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Action', 'memberistic' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="7"><?php esc_html_e( 'No members match this filter.', 'memberistic' ); ?></td></tr>
						<?php else : foreach ( $rows as $r ) :
							$ws = (string) $r['waiver_status'];
							?>
							<tr>
								<td><?php echo esc_html( $r['full_name'] ?: '—' ); ?></td>
								<td><?php echo esc_html( $r['email'] ?: '—' ); ?></td>
								<td><?php echo esc_html( ucfirst( (string) $r['role'] ) ); ?></td>
								<td><span style="font-weight:600;color:<?php echo 'signed' === $ws ? '#16794a' : '#b45309'; ?>;"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $ws ) ) ); ?></span></td>
								<td><?php echo esc_html( $r['waiver_signed_at'] ? date_i18n( get_option( 'date_format' ), strtotime( (string) $r['waiver_signed_at'] ) ) : '—' ); ?></td>
								<td><?php echo esc_html( $r['waiver_expires_at'] ? date_i18n( get_option( 'date_format' ), strtotime( (string) $r['waiver_expires_at'] ) ) : '—' ); ?></td>
								<td>
									<?php if ( 'signed' !== $ws ) : ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'memberistic_waivers' ); ?>
										<input type="hidden" name="memberistic_waiver_action" value="mark_one">
										<input type="hidden" name="person_id" value="<?php echo (int) $r['id']; ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Mark signed', 'memberistic' ); ?></button>
									</form>
									<?php endif; ?>
									<?php if ( ! empty( $r['wp_user_id'] ) ) : ?>
										<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( Waiver_Service::waiver_url( (int) $r['wp_user_id'] ) ); ?>"><?php esc_html_e( 'Sign link', 'memberistic' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Showing up to 200 rows. Use the filters above to narrow the list.', 'memberistic' ); ?></p>
			</div>
		</div>
		<?php
	}
}
