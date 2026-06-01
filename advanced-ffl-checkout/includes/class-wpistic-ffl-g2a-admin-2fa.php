<?php
/**
 * G2A — TOTP 2FA for admin staff who have access to transfer data.
 *
 * Opt-in, RFC 6238 (Google Authenticator / 1Password / Authy compatible).
 * Staff enrolls from their profile page → scans QR (rendered via Google
 * Charts API as a fallback when no QR lib is bundled) → enters first code
 * to confirm → on every subsequent admin login they're challenged for a
 * 6-digit code before the WP admin loads.
 *
 * Storage: per-user meta. Secret stored base32-encoded but encrypted with
 * a derived key from the site's NONCE_SALT — leaks of `wp_usermeta` alone
 * don't expose the OTP secrets.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Admin_2FA {

	const META_SECRET   = '_wpistic_ffl_totp_secret_enc';
	const META_ENABLED  = '_wpistic_ffl_totp_enabled';
	const META_BACKUP   = '_wpistic_ffl_totp_backup_codes';
	const CHALLENGE_KEY = '_wpistic_ffl_totp_challenge_until';
	const PERIOD        = 30;
	const DIGITS        = 6;

	public function __construct() {
		add_action( 'show_user_profile',  [ $this, 'render_profile_section' ] );
		add_action( 'edit_user_profile',  [ $this, 'render_profile_section' ] );
		add_action( 'personal_options_update', [ $this, 'handle_profile_update' ] );
		add_action( 'edit_user_profile_update', [ $this, 'handle_profile_update' ] );

		// Challenge before admin page renders.
		add_action( 'admin_init', [ $this, 'enforce_admin_challenge' ], 1 );

		// AJAX for the enrollment flow.
		add_action( 'wp_ajax_wpistic_ffl_totp_enroll', [ __CLASS__, 'ajax_enroll' ] );
		add_action( 'wp_ajax_wpistic_ffl_totp_verify', [ __CLASS__, 'ajax_verify_challenge' ] );
	}

	// ── Profile UI ──────────────────────────────────────────────────────────

	public function render_profile_section( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$enabled = (bool) get_user_meta( $user->ID, self::META_ENABLED, true );
		$nonce   = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		?>
		<h2 id="wpistic-ffl-2fa">🔐 <?php esc_html_e( 'FFL Admin Two-Factor (TOTP)', 'advanced-ffl-checkout' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></label></th>
				<td id="wpistic-ffl-totp-status">
					<?php if ( $enabled ) : ?>
						<span style="color:#16A34A;font-weight:700;">✓ <?php esc_html_e( 'Enabled', 'advanced-ffl-checkout' ); ?></span>
						<button type="button" class="button" id="wpistic-ffl-totp-disable" style="margin-left:12px;"><?php esc_html_e( 'Disable', 'advanced-ffl-checkout' ); ?></button>
					<?php else : ?>
						<span style="color:#666;">— <?php esc_html_e( 'Not enabled', 'advanced-ffl-checkout' ); ?></span>
						<button type="button" class="button button-primary" id="wpistic-ffl-totp-start" style="margin-left:12px;"><?php esc_html_e( 'Enable TOTP', 'advanced-ffl-checkout' ); ?></button>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<div id="wpistic-ffl-totp-enroll" style="display:none;background:#fff;border:1px solid #ccd0d4;padding:18px;border-radius:10px;max-width:560px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Scan with your authenticator', 'advanced-ffl-checkout' ); ?></h3>
			<div id="wpistic-ffl-totp-qr" style="text-align:center;margin:12px 0;"></div>
			<p><strong><?php esc_html_e( 'Secret:', 'advanced-ffl-checkout' ); ?></strong> <code id="wpistic-ffl-totp-secret"></code></p>
			<p><label><?php esc_html_e( 'Enter the 6-digit code from your authenticator to confirm:', 'advanced-ffl-checkout' ); ?>
				<input type="text" id="wpistic-ffl-totp-code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="off" style="margin-left:8px;font-family:ui-monospace,monospace;letter-spacing:.3em;width:120px;text-align:center;"></label>
				<button type="button" id="wpistic-ffl-totp-confirm" class="button button-primary"><?php esc_html_e( 'Confirm + enable', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-totp-msg" style="margin-left:10px;font-weight:600;"></span>
			</p>
			<p><strong><?php esc_html_e( 'Backup codes:', 'advanced-ffl-checkout' ); ?></strong> <span id="wpistic-ffl-totp-backup" style="font-family:ui-monospace,monospace;"></span><br><em style="color:#666;font-size:12px;"><?php esc_html_e( 'Save these somewhere safe — each can be used once if you lose your authenticator.', 'advanced-ffl-checkout' ); ?></em></p>
		</div>
		<script>
		(function(){
			var start = document.getElementById('wpistic-ffl-totp-start');
			var disable = document.getElementById('wpistic-ffl-totp-disable');
			var panel = document.getElementById('wpistic-ffl-totp-enroll');
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var uid = <?php echo (int) $user->ID; ?>;

			function call(stage, code) {
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_totp_enroll');
				fd.append('nonce', nonce);
				fd.append('uid', uid);
				fd.append('stage', stage);
				if (code) fd.append('code', code);
				return fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json());
			}

			if (start) start.addEventListener('click', function(){
				call('begin').then(function(j){
					if (!j || !j.success) return;
					document.getElementById('wpistic-ffl-totp-secret').textContent = j.data.secret;
					document.getElementById('wpistic-ffl-totp-qr').innerHTML = '<img alt="QR" src="' + j.data.qr_url + '" width="220" height="220">';
					document.getElementById('wpistic-ffl-totp-backup').textContent = j.data.backup_codes.join('  ');
					panel.style.display = 'block';
				});
			});
			document.getElementById('wpistic-ffl-totp-confirm').addEventListener('click', function(){
				var code = document.getElementById('wpistic-ffl-totp-code').value;
				var msg = document.getElementById('wpistic-ffl-totp-msg');
				call('confirm', code).then(function(j){
					if (j && j.success) { msg.textContent = '✓ Enabled — refresh to confirm.'; msg.style.color = '#16A34A'; setTimeout(function(){location.reload();}, 1500); }
					else { msg.textContent = '✗ ' + (j && j.data && j.data.message ? j.data.message : 'Failed'); msg.style.color = '#DC2626'; }
				});
			});
			if (disable) disable.addEventListener('click', function(){
				if (!confirm('Disable TOTP 2FA for this account?')) return;
				call('disable').then(function(){ location.reload(); });
			});
		})();
		</script>
		<?php
	}

	public function handle_profile_update( int $user_id ): void {
		// No-op — actual enrollment goes through AJAX.
	}

	// ── Enrollment AJAX ─────────────────────────────────────────────────────

	public static function ajax_enroll(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		// phpcs:disable WordPress.Security.NonceVerification
		$uid   = (int) ( $_POST['uid'] ?? 0 );
		$stage = sanitize_key( wp_unslash( $_POST['stage'] ?? '' ) );
		$code  = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['code'] ?? '' ) );
		// phpcs:enable
		if ( ! current_user_can( 'edit_user', $uid ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		if ( 'begin' === $stage ) {
			$secret = self::generate_secret();
			set_transient( 'wpistic_ffl_totp_pending_' . $uid, $secret, 30 * MINUTE_IN_SECONDS );
			$user = get_userdata( $uid );
			$label = rawurlencode( get_bloginfo( 'name' ) . ':' . $user->user_email );
			$issuer = rawurlencode( get_bloginfo( 'name' ) );
			$uri = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&period=" . self::PERIOD . '&digits=' . self::DIGITS;
			// Use a public QR generator that takes the otpauth URI in the URL.
			// goqr.me is widely-deployed and supports HTTPS.
			$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode( $uri );
			$backup = self::generate_backup_codes( 8 );
			set_transient( 'wpistic_ffl_totp_pending_backup_' . $uid, $backup, 30 * MINUTE_IN_SECONDS );
			wp_send_json_success( [ 'secret' => $secret, 'qr_url' => $qr_url, 'backup_codes' => $backup ] );
		}

		if ( 'confirm' === $stage ) {
			$secret = get_transient( 'wpistic_ffl_totp_pending_' . $uid );
			if ( ! $secret ) {
				wp_send_json_error( [ 'message' => 'Enrollment session expired — start again.' ], 400 );
			}
			if ( ! self::verify_code( $secret, $code ) ) {
				wp_send_json_error( [ 'message' => 'Code did not match.' ], 400 );
			}
			update_user_meta( $uid, self::META_SECRET, self::encrypt( $secret ) );
			update_user_meta( $uid, self::META_ENABLED, 1 );
			$backup = (array) get_transient( 'wpistic_ffl_totp_pending_backup_' . $uid );
			$hashed = array_map( fn( $c ) => hash( 'sha256', $c ), $backup );
			update_user_meta( $uid, self::META_BACKUP, $hashed );
			delete_transient( 'wpistic_ffl_totp_pending_' . $uid );
			delete_transient( 'wpistic_ffl_totp_pending_backup_' . $uid );
			// Mark this session as already challenged.
			set_transient( self::session_key( $uid ), 1, 12 * HOUR_IN_SECONDS );
			wp_send_json_success();
		}

		if ( 'disable' === $stage ) {
			delete_user_meta( $uid, self::META_SECRET );
			delete_user_meta( $uid, self::META_ENABLED );
			delete_user_meta( $uid, self::META_BACKUP );
			wp_send_json_success();
		}

		wp_send_json_error( [ 'message' => 'Unknown stage' ], 400 );
	}

	// ── Challenge enforcement ───────────────────────────────────────────────

	public function enforce_admin_challenge(): void {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}
		if ( ! get_user_meta( $user->ID, self::META_ENABLED, true ) ) {
			return;
		}
		if ( get_transient( self::session_key( $user->ID ) ) ) {
			return;
		}
		// Allow the verify endpoint itself to render the form.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'admin_page_wpistic-ffl-totp-challenge' === $screen->id ) {
			return;
		}
		// Render the challenge page in place of admin content.
		add_action( 'admin_notices', [ $this, 'render_challenge' ], 1 );
		add_action( 'all_admin_notices', [ $this, 'render_challenge' ], 1 );
	}

	public function render_challenge(): void {
		static $rendered = false;
		if ( $rendered ) return; $rendered = true;
		?>
		<div style="position:fixed;inset:0;background:rgba(15,14,18,0.96);z-index:100000;display:flex;align-items:center;justify-content:center;font-family:-apple-system,Segoe UI,Roboto,sans-serif;">
			<div style="background:#26252C;color:#F7F7F9;padding:36px 32px;border-radius:14px;max-width:380px;text-align:center;border:1px solid #38363F;">
				<h2 style="margin:0 0 8px;color:#DCB45F;">🔐 Two-Factor Required</h2>
				<p style="color:#A7A6AE;margin:0 0 18px;">Enter the 6-digit code from your authenticator app, or a backup code.</p>
				<input type="text" id="wpistic-ffl-totp-challenge" maxlength="10" autocomplete="one-time-code" inputmode="numeric" style="width:200px;padding:12px;font-family:ui-monospace,monospace;font-size:20px;letter-spacing:.3em;text-align:center;background:#1A191E;color:#DCB45F;border:1.5px solid #DCB45F;border-radius:8px;">
				<div style="margin-top:14px;"><button type="button" id="wpistic-ffl-totp-submit" style="background:#DCB45F;color:#0F0E12;border:none;padding:10px 28px;font-weight:800;border-radius:8px;cursor:pointer;">Verify</button></div>
				<p id="wpistic-ffl-totp-err" style="margin:12px 0 0;color:#E8802F;min-height:18px;font-size:13px;"></p>
			</div>
			<script>
			(function(){
				var input = document.getElementById('wpistic-ffl-totp-challenge');
				var btn = document.getElementById('wpistic-ffl-totp-submit');
				var err = document.getElementById('wpistic-ffl-totp-err');
				input.focus();
				function submit(){
					var c = input.value.replace(/\D/g,'').slice(0,10);
					if (c.length < 6) { err.textContent = 'Need 6 digits (or 10-char backup code).'; return; }
					var fd = new FormData();
					fd.append('action','wpistic_ffl_totp_verify');
					fd.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wpistic_ffl_admin_nonce' ) ); ?>);
					fd.append('code', input.value);
					fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
						.then(r=>r.json()).then(function(j){
							if (j && j.success) { location.reload(); }
							else { err.textContent = (j && j.data && j.data.message) || 'Code did not match.'; }
						});
				}
				btn.addEventListener('click', submit);
				input.addEventListener('keydown', function(e){ if (e.key === 'Enter') submit(); });
			})();
			</script>
		</div>
		<?php
	}

	public static function ajax_verify_challenge(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			wp_send_json_error( [ 'message' => 'Not logged in' ], 401 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$code = preg_replace( '/[^0-9A-Za-z]/', '', (string) wp_unslash( $_POST['code'] ?? '' ) );
		// phpcs:enable

		// Try TOTP first
		$enc = (string) get_user_meta( $user->ID, self::META_SECRET, true );
		$secret = $enc ? self::decrypt( $enc ) : '';
		if ( $secret && strlen( $code ) === self::DIGITS && self::verify_code( $secret, $code ) ) {
			set_transient( self::session_key( $user->ID ), 1, 12 * HOUR_IN_SECONDS );
			wp_send_json_success();
		}
		// Fallback: one-time backup code
		$backup = (array) get_user_meta( $user->ID, self::META_BACKUP, true );
		$h      = hash( 'sha256', strtoupper( $code ) );
		$idx    = array_search( $h, $backup, true );
		if ( false !== $idx ) {
			unset( $backup[ $idx ] );
			update_user_meta( $user->ID, self::META_BACKUP, array_values( $backup ) );
			set_transient( self::session_key( $user->ID ), 1, 12 * HOUR_IN_SECONDS );
			wp_send_json_success();
		}
		wp_send_json_error( [ 'message' => 'Invalid code.' ], 403 );
	}

	private static function session_key( int $uid ): string {
		return self::CHALLENGE_KEY . '_' . $uid;
	}

	// ── TOTP cryptography ───────────────────────────────────────────────────

	private static function generate_secret(): string {
		// 20 random bytes → base32 (no padding) — RFC 4226 recommended length.
		try {
			$raw = random_bytes( 20 );
		} catch ( \Throwable $e ) {
			$raw = openssl_random_pseudo_bytes( 20 );
		}
		return self::base32_encode( $raw );
	}

	private static function generate_backup_codes( int $count ): array {
		$out = [];
		for ( $i = 0; $i < $count; $i++ ) {
			try {
				$bytes = random_bytes( 5 );
			} catch ( \Throwable $e ) {
				$bytes = openssl_random_pseudo_bytes( 5 );
			}
			$out[] = strtoupper( substr( bin2hex( $bytes ), 0, 10 ) );
		}
		return $out;
	}

	/**
	 * RFC 6238: HOTP( K, T ) where T = floor( time() / period ).
	 * Accept the previous + current + next window to allow ±30s clock skew.
	 */
	public static function verify_code( string $base32_secret, string $code ): bool {
		$code = preg_replace( '/\D/', '', $code );
		if ( strlen( $code ) !== self::DIGITS ) {
			return false;
		}
		$key = self::base32_decode( $base32_secret );
		$t   = (int) floor( time() / self::PERIOD );
		foreach ( [ -1, 0, 1 ] as $offset ) {
			$expected = self::hotp( $key, $t + $offset );
			if ( hash_equals( $expected, $code ) ) {
				return true;
			}
		}
		return false;
	}

	private static function hotp( string $key, int $counter ): string {
		$bin = pack( 'N*', 0 ) . pack( 'N*', $counter );
		$hmac = hash_hmac( 'sha1', $bin, $key, true );
		$offset = ord( substr( $hmac, -1 ) ) & 0x0F;
		$truncated = ( ( ord( $hmac[ $offset ] ) & 0x7F ) << 24 )
			| ( ( ord( $hmac[ $offset + 1 ] ) & 0xFF ) << 16 )
			| ( ( ord( $hmac[ $offset + 2 ] ) & 0xFF ) << 8 )
			| ( ord( $hmac[ $offset + 3 ] ) & 0xFF );
		$otp = $truncated % ( 10 ** self::DIGITS );
		return str_pad( (string) $otp, self::DIGITS, '0', STR_PAD_LEFT );
	}

	private static function base32_encode( string $data ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits = '';
		foreach ( str_split( $data ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$bits = str_pad( $bits, ceil( strlen( $bits ) / 5 ) * 5, '0', STR_PAD_RIGHT );
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= $alphabet[ bindec( $chunk ) ];
		}
		return $out;
	}

	private static function base32_decode( string $b32 ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32  = strtoupper( preg_replace( '/[^A-Z2-7]/', '', $b32 ) );
		$bits = '';
		foreach ( str_split( $b32 ) as $c ) {
			$pos = strpos( $alphabet, $c );
			if ( false === $pos ) continue;
			$bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	// ── Secret encryption at rest ───────────────────────────────────────────

	private static function key(): string {
		$salt = defined( 'NONCE_SALT' ) ? NONCE_SALT : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'fallback' );
		return hash( 'sha256', 'wpistic-ffl-totp:' . $salt, true );
	}

	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( $plain ); // fallback — better than nothing
		}
		$iv  = openssl_random_pseudo_bytes( 16 );
		$ct  = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $ct );
	}

	private static function decrypt( string $b64 ): string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return base64_decode( $b64, true ) ?: '';
		}
		$raw = base64_decode( $b64, true );
		if ( false === $raw || strlen( $raw ) < 17 ) return '';
		$iv = substr( $raw, 0, 16 );
		$ct = substr( $raw, 16 );
		return openssl_decrypt( $ct, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv ) ?: '';
	}
}
