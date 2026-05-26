<?php
/**
 * Import page — bring members and payment history in from Paid Memberships
 * Pro (PMPro) and other legacy membership plugins via CSV upload.
 *
 * Two-step flow: upload + dry-run preview, then confirm + commit.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;
use function WordPressistic\Memberistic\memberistic_current_user_can;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Import_Page {

	/** Capability required to run imports. */
	const CAP = 'manage_memberistic_settings';

	/** Transient prefix for parsed-CSV staging. */
	const STAGE_PREFIX = 'memberistic_import_';

	/**
	 * Legacy PMPro level name (lowercased) => Memberistic plan slug.
	 * Mirrors the agreed Guns 2 Ammo migration mapping.
	 */
	private static function level_map() {
		return apply_filters(
			'memberistic_import_level_map',
			array(
				'bronze'                                   => 'defender',
				'bronze yearly'                            => 'defender',
				'trigger membership (annually)'            => 'defender',
				'silver'                                   => 'patriot',
				'silver yearly'                            => 'patriot',
				'silver - additional member'               => 'patriot',
				'trigger pro membership (monthly)'         => 'patriot',
				'trigger pro membership (annual)'          => 'patriot',
				'gold'                                     => 'guardian',
				'gold - yearly'                            => 'guardian',
				'gold - additional member'                 => 'guardian',
				'trigger pro plus membership (monthly)'    => 'guardian',
				'trigger pro plus membership (annually)'   => 'guardian',
				'trigger pro master membership (monthly)'  => 'guardian',
				'membership guns up trigger happy family (annually)' => 'guardian',
				'membership guns up trigger happy family (monthly)'  => 'guardian',
			)
		);
	}

	/**
	 * Resolve a legacy level name to a plan slug, with keyword fallback.
	 *
	 * Returns `no-plan` (a sentinel slug that the importer auto-creates) for
	 * rows we genuinely cannot match — that way every CSV row is importable,
	 * including historic members who never had a plan.
	 *
	 * @param string $level Legacy level name.
	 * @return string Plan slug.
	 */
	private static function plan_slug_for_level( $level ) {
		$level = strtolower( trim( (string) $level ) );
		if ( '' === $level ) {
			return 'no-plan';
		}
		$map = self::level_map();
		if ( isset( $map[ $level ] ) ) {
			return $map[ $level ];
		}
		// Keyword fallback for unmapped levels.
		if ( false !== strpos( $level, 'happy family' ) || false !== strpos( $level, 'plus' ) || false !== strpos( $level, 'master' ) || false !== strpos( $level, 'gold' ) ) {
			return 'guardian';
		}
		if ( false !== strpos( $level, 'silver' ) || false !== strpos( $level, 'trigger pro' ) ) {
			return 'patriot';
		}
		if ( false !== strpos( $level, 'bronze' ) || false !== strpos( $level, 'defender' ) || false !== strpos( $level, 'trigger membership' ) ) {
			return 'defender';
		}
		return 'no-plan';
	}

	/**
	 * Ensure the sentinel "No Plan" plan exists, returning its row. Used for
	 * historic / orphan imports where the source row has no real plan.
	 *
	 * @return array<string, mixed> Plan row.
	 */
	private static function ensure_no_plan() {
		$plan = Plans_Repository::get_by_slug( 'no-plan' );
		if ( $plan ) {
			return $plan;
		}

		$id = Plans_Repository::create(
			array(
				'name'            => __( 'No Plan', 'memberistic' ),
				'slug'            => 'no-plan',
				'description'     => __( 'Sentinel plan used by the importer for historic/orphan members and Instore-only orders. Hidden from the public plan grid.', 'memberistic' ),
				'monthly_price'   => 0,
				'annual_price'    => 0,
				'included_people' => 1,
				'benefits'        => wp_json_encode( array() ),
				'settings'        => wp_json_encode( array( 'hidden_from_public' => true ) ),
				'is_featured'     => 0,
				'sort_order'      => 999,
				'status'          => 'inactive',
			)
		);

		return $id ? Plans_Repository::get( (int) $id ) : null;
	}

	/**
	 * Derive the membership status to import a row at, based on its expiry
	 * date and (for orphan-imported orders) the "Instore / No Plan" flag.
	 */
	private static function status_for_row( $end_iso, $plan_slug ) {
		$end_ts = $end_iso ? strtotime( $end_iso ) : 0;

		if ( 'no-plan' === $plan_slug ) {
			return 'needs_review';
		}
		if ( $end_ts && $end_ts < time() ) {
			return 'expired';
		}
		return 'active';
	}

	/**
	 * Header alias groups — maps many possible CSV header names to one key.
	 */
	private static function aliases() {
		return array(
			'first_name'   => array( 'first_name', 'firstname', 'first name' ),
			'last_name'    => array( 'last_name', 'lastname', 'last name' ),
			'full_name'    => array( 'full_name', 'name', 'display_name', 'full name' ),
			'email'        => array( 'email', 'user_email', 'e-mail' ),
			'username'     => array( 'username', 'user_login', 'login' ),
			'phone'        => array( 'phone', 'billing_phone', 'telephone', 'phone_number' ),
			'level'        => array( 'legacy_level', 'level', 'membership', 'membership_level', 'level_name', 'membership level' ),
			'plan'         => array( 'memberistic_plan', 'plan' ),
			'billing_cycle'=> array( 'billing_cycle', 'cycle', 'billing' ),
			'stripe_sub'   => array( 'stripe_subscription_id', 'subscription_transaction_id', 'subscription_id' ),
			'joined'       => array( 'joined', 'join_date', 'joindate' ),
			'start_date'   => array( 'start_date', 'startdate', 'start date' ),
			'renewal_date' => array( 'next_payment_date', 'renewal_date', 'next payment date', 'next_payment' ),
			'expires'      => array( 'expires', 'expiration', 'expiration date', 'enddate', 'end_date' ),
			'is_linked'    => array( 'is_linked_member', 'is_linked', 'linked', 'additional_member' ),
			'legacy_id'    => array( 'legacy_id', 'id', 'user_id' ),
			// Payments-only.
			'order_id'     => array( 'legacy_order_id', 'order_id', 'id' ),
			'order_code'   => array( 'order_code', 'code' ),
			'total'        => array( 'total', 'amount', 'billing_amount' ),
			'status'       => array( 'status' ),
			'gateway'      => array( 'gateway', 'payment_gateway' ),
			'txn_id'       => array( 'payment_transaction_id', 'transaction_id', 'gateway_transaction_id' ),
			'date'         => array( 'date', 'timestamp', 'paid_at' ),
		);
	}

	/**
	 * Render the Import admin page (dispatch by step).
	 */
	public static function render() {
		if ( ! memberistic_current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}

		$step = isset( $_POST['memberistic_import_step'] ) ? sanitize_key( wp_unslash( $_POST['memberistic_import_step'] ) ) : 'start';

		if ( 'preview' === $step && check_admin_referer( 'memberistic_import_upload' ) ) {
			self::render_preview();
			return;
		}
		if ( 'commit' === $step && check_admin_referer( 'memberistic_import_commit' ) ) {
			self::render_result();
			return;
		}
		self::render_start();
	}

	/**
	 * Step 1 — the upload form.
	 *
	 * @param string $error Optional error message to show.
	 */
	private static function render_start( $error = '' ) {
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Import Members &amp; Payments', 'memberistic' ); ?></h1>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Import from Paid Memberships Pro (PMPro)', 'memberistic' ); ?></h2>
				<p><?php esc_html_e( 'Upload a CSV export of your PMPro members (or a payment/orders export) to bring your existing membership base into Memberistic. The importer auto-detects columns, maps legacy membership levels to Memberistic plans, and shows a dry-run preview before anything is written.', 'memberistic' ); ?></p>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'memberistic_import_upload' ); ?>
					<input type="hidden" name="memberistic_import_step" value="preview">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="memberistic_import_type"><?php esc_html_e( 'What are you importing?', 'memberistic' ); ?></label></th>
							<td>
								<select name="memberistic_import_type" id="memberistic_import_type">
									<option value="members"><?php esc_html_e( 'Members list (memberships + people)', 'memberistic' ); ?></option>
									<option value="payments"><?php esc_html_e( 'Payment / order history', 'memberistic' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="memberistic_import_file"><?php esc_html_e( 'CSV file', 'memberistic' ); ?></label></th>
							<td>
								<input type="file" name="memberistic_import_file" id="memberistic_import_file" accept=".csv,text/csv" required>
								<p class="description"><?php esc_html_e( 'Accepts a raw PMPro members/orders export, or a pre-mapped CSV with a memberistic_plan column.', 'memberistic' ); ?></p>
							</td>
						</tr>
					</table>
					<p>
						<label><input type="checkbox" name="memberistic_skip_duplicates" value="1" checked> <?php esc_html_e( 'Skip rows whose email already has a membership', 'memberistic' ); ?></label>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload &amp; Preview', 'memberistic' ); ?></button></p>
				</form>
			</div>
			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Membership level mapping', 'memberistic' ); ?></h2>
				<p><?php esc_html_e( 'Legacy PMPro levels are mapped to Memberistic plans as follows. Levels are matched case-insensitively; "Additional Member" levels are imported as linked people.', 'memberistic' ); ?></p>
				<table class="widefat striped memberistic-table">
					<thead><tr><th><?php esc_html_e( 'Legacy PMPro level', 'memberistic' ); ?></th><th><?php esc_html_e( 'Memberistic plan', 'memberistic' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( self::level_map() as $level => $slug ) : ?>
						<tr><td><?php echo esc_html( ucwords( $level ) ); ?></td><td><?php echo esc_html( ucfirst( $slug ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 2 — parse the uploaded CSV and show a dry-run preview.
	 */
	private static function render_preview() {
		$type = isset( $_POST['memberistic_import_type'] ) ? sanitize_key( wp_unslash( $_POST['memberistic_import_type'] ) ) : 'members';
		$type = in_array( $type, array( 'members', 'payments' ), true ) ? $type : 'members';
		$skip = ! empty( $_POST['memberistic_skip_duplicates'] );

		if ( empty( $_FILES['memberistic_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['memberistic_import_file']['tmp_name'] ) ) {
			self::render_start( __( 'No file was uploaded. Please choose a CSV file.', 'memberistic' ) );
			return;
		}

		$rows = self::parse_csv( $_FILES['memberistic_import_file']['tmp_name'] );
		if ( empty( $rows ) ) {
			self::render_start( __( 'The CSV file was empty or could not be read.', 'memberistic' ) );
			return;
		}

		$analysis = ( 'payments' === $type )
			? self::analyze_payments( $rows )
			: self::analyze_members( $rows, $skip );

		// Stage the parsed rows for the commit step.
		$token = wp_generate_password( 20, false, false );
		set_transient( self::STAGE_PREFIX . $token, array( 'type' => $type, 'skip' => $skip, 'rows' => $rows ), HOUR_IN_SECONDS );
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Import Preview', 'memberistic' ); ?></h1>
			<div class="memberistic-card">
				<h2><?php echo esc_html( 'payments' === $type ? __( 'Payment history — dry run', 'memberistic' ) : __( 'Members — dry run', 'memberistic' ) ); ?></h2>
				<p><?php esc_html_e( 'Nothing has been imported yet. Review the summary below, then confirm to commit.', 'memberistic' ); ?></p>
				<table class="widefat striped memberistic-table">
					<tbody>
					<?php foreach ( $analysis['summary'] as $label => $value ) : ?>
						<tr><th style="width:280px;"><?php echo esc_html( $label ); ?></th><td><strong><?php echo esc_html( (string) $value ); ?></strong></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! empty( $analysis['warnings'] ) ) : ?>
					<div class="notice notice-warning inline" style="margin:14px 0;"><ul style="list-style:disc;margin-left:20px;">
						<?php foreach ( $analysis['warnings'] as $w ) : ?>
							<li><?php echo esc_html( $w ); ?></li>
						<?php endforeach; ?>
					</ul></div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $analysis['sample'] ) ) : ?>
			<div class="memberistic-card">
				<h2><?php esc_html_e( 'Sample rows', 'memberistic' ); ?></h2>
				<table class="widefat striped memberistic-table">
					<thead><tr><?php foreach ( $analysis['sample_head'] as $h ) : ?><th><?php echo esc_html( $h ); ?></th><?php endforeach; ?></tr></thead>
					<tbody>
					<?php foreach ( $analysis['sample'] as $srow ) : ?>
						<tr><?php foreach ( $srow as $cell ) : ?><td><?php echo esc_html( (string) $cell ); ?></td><?php endforeach; ?></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<div class="memberistic-card">
				<form method="post">
					<?php wp_nonce_field( 'memberistic_import_commit' ); ?>
					<input type="hidden" name="memberistic_import_step" value="commit">
					<input type="hidden" name="memberistic_import_token" value="<?php echo esc_attr( $token ); ?>">
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm &amp; Import', 'memberistic' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-import' ) ); ?>"><?php esc_html_e( 'Cancel', 'memberistic' ); ?></a>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 3 — commit the staged rows.
	 */
	private static function render_result() {
		$token = isset( $_POST['memberistic_import_token'] ) ? sanitize_text_field( wp_unslash( $_POST['memberistic_import_token'] ) ) : '';
		$stage = $token ? get_transient( self::STAGE_PREFIX . $token ) : false;

		if ( ! $stage || empty( $stage['rows'] ) ) {
			self::render_start( __( 'The import session expired. Please upload the file again.', 'memberistic' ) );
			return;
		}
		delete_transient( self::STAGE_PREFIX . $token );

		$result = ( 'payments' === $stage['type'] )
			? self::commit_payments( $stage['rows'] )
			: self::commit_members( $stage['rows'], ! empty( $stage['skip'] ) );
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Import Complete', 'memberistic' ); ?></h1>
			<div class="memberistic-card">
				<div class="notice notice-success inline" style="margin:0 0 14px;"><p><?php esc_html_e( 'The import finished. Results are below.', 'memberistic' ); ?></p></div>
				<table class="widefat striped memberistic-table">
					<tbody>
					<?php foreach ( $result['summary'] as $label => $value ) : ?>
						<tr><th style="width:280px;"><?php echo esc_html( $label ); ?></th><td><strong><?php echo esc_html( (string) $value ); ?></strong></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! empty( $result['errors'] ) ) : ?>
					<div class="notice notice-warning inline" style="margin:14px 0;"><ul style="list-style:disc;margin-left:20px;">
						<?php foreach ( array_slice( $result['errors'], 0, 50 ) as $e ) : ?>
							<li><?php echo esc_html( $e ); ?></li>
						<?php endforeach; ?>
					</ul></div>
				<?php endif; ?>
				<p style="margin-top:14px;">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-members' ) ); ?>"><?php esc_html_e( 'View Members', 'memberistic' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-import' ) ); ?>"><?php esc_html_e( 'Import Another File', 'memberistic' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Parsing + analysis                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Parse a CSV file into an array of header-keyed associative rows.
	 *
	 * @param string $path Temp file path.
	 * @return array<int, array<string, string>>
	 */
	private static function parse_csv( $path ) {
		$rows = array();
		$fh   = fopen( $path, 'r' );
		if ( ! $fh ) {
			return $rows;
		}
		$header = fgetcsv( $fh );
		if ( ! is_array( $header ) ) {
			fclose( $fh );
			return $rows;
		}
		$header = array_map(
			static function ( $h ) {
				return strtolower( trim( (string) $h ) );
			},
			$header
		);
		$cols = count( $header );
		while ( ( $line = fgetcsv( $fh ) ) !== false ) {
			if ( array( null ) === $line ) {
				continue; // blank line
			}
			$line = array_pad( array_slice( $line, 0, $cols ), $cols, '' );
			$rows[] = array_combine( $header, $line );
			if ( count( $rows ) >= 5000 ) {
				break; // safety cap
			}
		}
		fclose( $fh );
		return $rows;
	}

	/**
	 * Pull a value from a row using the alias map for a logical key.
	 *
	 * @param array  $row Row data.
	 * @param string $key Logical key.
	 * @return string
	 */
	private static function val( $row, $key ) {
		$aliases = self::aliases();
		foreach ( $aliases[ $key ] ?? array() as $alias ) {
			if ( isset( $row[ $alias ] ) && '' !== trim( (string) $row[ $alias ] ) ) {
				return trim( (string) $row[ $alias ] );
			}
		}
		return '';
	}

	/**
	 * Derive a normalized member record from a raw CSV row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function map_member_row( $row ) {
		$name = self::val( $row, 'full_name' );
		if ( '' === $name ) {
			$name = trim( self::val( $row, 'first_name' ) . ' ' . self::val( $row, 'last_name' ) );
		}
		$email = sanitize_email( self::val( $row, 'email' ) );
		$level = self::val( $row, 'level' );

		// Prefer an explicit memberistic_plan column if it names a real plan.
		$plan_col = strtolower( self::val( $row, 'plan' ) );
		$slug     = in_array( $plan_col, array( 'defender', 'patriot', 'guardian' ), true )
			? $plan_col
			: self::plan_slug_for_level( $level );

		$cycle = strtolower( self::val( $row, 'billing_cycle' ) );
		if ( ! in_array( $cycle, array( 'monthly', 'annual' ), true ) ) {
			$cycle = ( false !== stripos( $level, 'year' ) || false !== stripos( $level, 'annual' ) ) ? 'annual' : 'monthly';
		}

		$linked_flag = strtolower( self::val( $row, 'is_linked' ) );
		$is_linked   = in_array( $linked_flag, array( 'yes', '1', 'true', 'y' ), true )
			|| false !== stripos( $level, 'additional member' );

		return array(
			'name'       => $name,
			'email'      => $email,
			'phone'      => self::val( $row, 'phone' ),
			'username'   => self::val( $row, 'username' ),
			'plan_slug'  => $slug,
			'cycle'      => $cycle,
			'is_linked'  => $is_linked,
			'level'      => $level,
			'legacy_id'  => self::val( $row, 'legacy_id' ),
			'stripe_sub' => self::val( $row, 'stripe_sub' ),
			'start'      => self::val( $row, 'start_date' ) ?: self::val( $row, 'joined' ),
			'renewal'    => self::val( $row, 'renewal_date' ),
			'expires'    => self::val( $row, 'expires' ),
		);
	}

	/**
	 * Build the members dry-run analysis.
	 *
	 * The importer is intentionally permissive: it imports every row in the
	 * file — including rows with no email, no recognised plan, or an
	 * already-expired membership. Status is auto-derived per row so an admin
	 * can review historic / churned members alongside active ones.
	 *
	 * @param array $rows Raw rows.
	 * @param bool  $skip Skip duplicates flag.
	 * @return array
	 */
	private static function analyze_members( $rows, $skip ) {
		$by_plan   = array( 'defender' => 0, 'patriot' => 0, 'guardian' => 0, 'no-plan' => 0 );
		$linked    = 0;
		$no_email  = 0;
		$expired   = 0;
		$dupes     = 0;
		$warnings  = array();
		$sample    = array();

		$now = time();

		foreach ( $rows as $row ) {
			$m         = self::map_member_row( $row );
			$plan_slug = $m['plan_slug'];
			if ( $m['is_linked'] ) {
				$linked++;
			} else {
				$by_plan[ $plan_slug ] = ( $by_plan[ $plan_slug ] ?? 0 ) + 1;
			}
			if ( '' === $m['email'] ) {
				$no_email++;
			} elseif ( $skip && Memberships_Repository::get_by_person_email( $m['email'] ) ) {
				$dupes++;
			}

			$end_ts = $m['expires'] ? strtotime( $m['expires'] ) : 0;
			if ( $end_ts && $end_ts < $now ) {
				$expired++;
			}

			if ( count( $sample ) < 8 ) {
				$sample[] = array(
					$m['name'] ?: __( '(no name)', 'memberistic' ),
					$m['email'] ?: __( '(no email)', 'memberistic' ),
					$m['level'] ?: __( '(no level)', 'memberistic' ),
					ucfirst( str_replace( '-', ' ', $plan_slug ) ),
					$m['cycle'],
					$m['is_linked'] ? __( 'linked', 'memberistic' ) : __( 'primary', 'memberistic' ),
					( $end_ts && $end_ts < $now ) ? __( 'expired', 'memberistic' ) : __( 'active/historic', 'memberistic' ),
				);
			}
		}

		if ( $no_email > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: row count */
				_n( '%d row has no email address. It will still be imported using the full name as the only contact field.', '%d rows have no email address. They will still be imported using the full name as the only contact field.', $no_email, 'memberistic' ),
				$no_email
			);
		}
		if ( ! empty( $by_plan['no-plan'] ) ) {
			$warnings[] = sprintf(
				/* translators: %d: row count */
				__( '%d row(s) could not be matched to a known plan. They will be imported under a "No Plan" sentinel plan so you can review and re-assign later.', 'memberistic' ),
				(int) $by_plan['no-plan']
			);
		}
		if ( $expired > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: row count */
				_n( '%d row has an expiry date in the past — it will be imported with status = expired so you keep historic members on file.', '%d rows have an expiry date in the past — they will be imported with status = expired so you keep historic members on file.', $expired, 'memberistic' ),
				$expired
			);
		}
		if ( $linked > 0 ) {
			$warnings[] = __( 'Linked / "Additional Member" rows attach to a primary membership of the same plan that still has open capacity. If none is available they import as their own membership flagged for review — spot-check these afterwards.', 'memberistic' );
		}

		$summary = array(
			__( 'Total rows in file', 'memberistic' )      => count( $rows ),
			__( 'Primary memberships', 'memberistic' )     => array_sum( $by_plan ),
			__( '— Defender', 'memberistic' )              => $by_plan['defender'],
			__( '— Patriot', 'memberistic' )               => $by_plan['patriot'],
			__( '— Guardian', 'memberistic' )              => $by_plan['guardian'],
			__( '— No Plan / Unknown', 'memberistic' )     => $by_plan['no-plan'],
			__( 'Linked members', 'memberistic' )          => $linked,
			__( 'Expired (kept as expired)', 'memberistic' ) => $expired,
			__( 'Rows with no email', 'memberistic' )      => $no_email,
		);
		if ( $skip ) {
			$summary[ __( 'Duplicate emails (will skip)', 'memberistic' ) ] = $dupes;
		}

		return array(
			'summary'     => $summary,
			'warnings'    => $warnings,
			'sample'      => $sample,
			'sample_head' => array( __( 'Name', 'memberistic' ), __( 'Email', 'memberistic' ), __( 'Legacy level', 'memberistic' ), __( 'Plan', 'memberistic' ), __( 'Cycle', 'memberistic' ), __( 'Type', 'memberistic' ), __( 'Lifecycle', 'memberistic' ) ),
		);
	}

	/**
	 * Build the payments dry-run analysis.
	 *
	 * Every row is imported. Rows whose email matches an existing membership
	 * attach to it. Rows whose email does NOT match create a stub Instore
	 * member (No Plan, status = needs_review) so no payment is ever lost.
	 * Rows with no email at all attach to a shared "Instore Walk-in"
	 * sentinel membership.
	 *
	 * @param array $rows Raw rows.
	 * @return array
	 */
	private static function analyze_payments( $rows ) {
		$matched         = 0;
		$orphan_email    = 0;
		$orphan_no_email = 0;
		$total           = 0.0;
		$sample          = array();

		foreach ( $rows as $row ) {
			$email  = sanitize_email( self::val( $row, 'email' ) );
			$amount = (float) preg_replace( '/[^0-9.\-]/', '', self::val( $row, 'total' ) );
			$total += $amount;
			if ( $email && Memberships_Repository::get_by_person_email( $email ) ) {
				$matched++;
				$disposition = __( 'matched', 'memberistic' );
			} elseif ( $email ) {
				$orphan_email++;
				$disposition = __( 'creates Instore member', 'memberistic' );
			} else {
				$orphan_no_email++;
				$disposition = __( 'attaches to Instore Walk-in', 'memberistic' );
			}
			if ( count( $sample ) < 8 ) {
				$sample[] = array( $email ?: __( '(no email)', 'memberistic' ), number_format( $amount, 2 ), self::val( $row, 'status' ), self::val( $row, 'gateway' ), self::val( $row, 'date' ), $disposition );
			}
		}

		$warnings = array();
		if ( $orphan_email > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: row count */
				__( '%d payment(s) reference an email that has no matching member yet. A stub Instore member will be created so the payment is preserved — review and merge later.', 'memberistic' ),
				$orphan_email
			);
		}
		if ( $orphan_no_email > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: row count */
				__( '%d payment(s) have no email at all. They will attach to a single shared "Instore Walk-in" membership under the No Plan sentinel.', 'memberistic' ),
				$orphan_no_email
			);
		}

		return array(
			'summary' => array(
				__( 'Total rows in file', 'memberistic' )            => count( $rows ),
				__( 'Matched to a membership', 'memberistic' )       => $matched,
				__( 'Orphan w/ email (Instore created)', 'memberistic' )   => $orphan_email,
				__( 'Orphan w/o email (Walk-in)', 'memberistic' )    => $orphan_no_email,
				__( 'Total amount in file', 'memberistic' )          => number_format( $total, 2 ),
			),
			'warnings'    => $warnings,
			'sample'      => $sample,
			'sample_head' => array( __( 'Email', 'memberistic' ), __( 'Amount', 'memberistic' ), __( 'Status', 'memberistic' ), __( 'Gateway', 'memberistic' ), __( 'Date', 'memberistic' ), __( 'Disposition', 'memberistic' ) ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Commit                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Import members. Primary rows first, then linked rows attach to a
	 * primary membership of the same plan with open capacity.
	 *
	 * Every row in the file is imported. Historic / expired members keep
	 * status = expired. Rows with an unrecognised plan attach to the
	 * "No Plan" sentinel plan and import with status = needs_review.
	 * Rows with no email still import — useful for legacy / paper waivers.
	 *
	 * @param array $rows Raw rows.
	 * @param bool  $skip Skip duplicates.
	 * @return array
	 */
	private static function commit_members( $rows, $skip ) {
		$plans = array();
		foreach ( array( 'defender', 'patriot', 'guardian' ) as $slug ) {
			$plans[ $slug ] = Plans_Repository::get_by_slug( $slug );
		}
		// Lazy-load the No Plan sentinel only if we actually see one.
		$plans['no-plan'] = null;

		$created       = 0;
		$linked        = 0;
		$expired_count = 0;
		$no_plan_count = 0;
		$skipped       = 0;
		$errors        = array();
		// Capacity pool per plan: membership_id => slots_left.
		$pool = array( 'defender' => array(), 'patriot' => array(), 'guardian' => array(), 'no-plan' => array() );
		$linked_rows = array();

		foreach ( $rows as $i => $row ) {
			$m = self::map_member_row( $row );

			$slug = $m['plan_slug'];
			if ( 'no-plan' === $slug && empty( $plans['no-plan'] ) ) {
				$plans['no-plan'] = self::ensure_no_plan();
			}
			$plan = $plans[ $slug ] ?? null;
			if ( ! $plan ) {
				// Fall back to No Plan if the resolved tier is missing.
				if ( empty( $plans['no-plan'] ) ) {
					$plans['no-plan'] = self::ensure_no_plan();
				}
				$plan = $plans['no-plan'];
				$slug = 'no-plan';
			}
			if ( ! $plan ) {
				$errors[] = sprintf( __( 'Row %1$d (%2$s): no plan available and the No-Plan sentinel could not be created.', 'memberistic' ), $i + 2, $m['email'] ?: $m['name'] );
				$skipped++;
				continue;
			}

			if ( $m['is_linked'] ) {
				$linked_rows[] = array_merge( $m, array( 'plan_slug' => $slug ) );
				continue;
			}

			if ( $skip && $m['email'] && Memberships_Repository::get_by_person_email( $m['email'] ) ) {
				$skipped++;
				continue;
			}

			$membership_id = self::create_membership( $m, $plan );
			if ( ! $membership_id ) {
				$errors[] = sprintf( __( 'Row %1$d (%2$s): could not create membership.', 'memberistic' ), $i + 2, $m['email'] ?: $m['name'] );
				$skipped++;
				continue;
			}
			$created++;

			if ( 'no-plan' === $slug ) {
				$no_plan_count++;
			}
			$end_ts = $m['expires'] ? strtotime( $m['expires'] ) : 0;
			if ( $end_ts && $end_ts < time() ) {
				$expired_count++;
			}

			$capacity = max( 1, (int) $plan['included_people'] );
			$pool[ $slug ][ $membership_id ] = $capacity - 1; // primary takes one slot
		}

		// Attach linked members.
		foreach ( $linked_rows as $m ) {
			$slug   = $m['plan_slug'];
			$target = 0;
			if ( ! empty( $pool[ $slug ] ) ) {
				foreach ( $pool[ $slug ] as $mid => $slots_left ) {
					if ( $slots_left > 0 ) {
						$target = $mid;
						break;
					}
				}
			}
			if ( $target ) {
				$ok = People_Repository::create(
					array(
						'membership_id' => $target,
						'role'          => 'linked',
						'full_name'     => $m['name'] ?: ( $m['email'] ?: __( 'Linked member', 'memberistic' ) ),
						'email'         => $m['email'],
						'phone'         => $m['phone'],
						'wp_user_id'    => self::wp_user_id_for( $m['email'] ),
						'status'        => 'active',
						'notes'         => sprintf( __( 'Imported linked member (legacy level: %s).', 'memberistic' ), $m['level'] ),
					)
				);
				if ( $ok ) {
					$pool[ $slug ][ $target ]--;
					$linked++;
				} else {
					$errors[] = sprintf( __( 'Linked member %s could not be attached.', 'memberistic' ), $m['email'] ?: $m['name'] );
				}
			} else {
				// No open seat — import as its own membership flagged for review.
				$plan = $plans[ $slug ] ?? null;
				if ( ! $plan ) {
					$plans['no-plan'] = $plans['no-plan'] ?: self::ensure_no_plan();
					$plan             = $plans['no-plan'];
				}
				$mid = self::create_membership( $m, $plan, __( 'Imported from a PMPro "Additional Member" level — review and merge into the correct household membership.', 'memberistic' ) );
				if ( $mid ) {
					$linked++;
				} else {
					$errors[] = sprintf( __( 'Linked member %s could not be imported.', 'memberistic' ), $m['email'] ?: $m['name'] );
				}
			}
		}

		return array(
			'summary' => array(
				__( 'Primary memberships created', 'memberistic' ) => $created,
				__( '— of which expired', 'memberistic' )          => $expired_count,
				__( '— of which No Plan / Unknown', 'memberistic' ) => $no_plan_count,
				__( 'Linked members imported', 'memberistic' )     => $linked,
				__( 'Rows skipped (duplicate)', 'memberistic' )    => $skipped,
				__( 'Errors', 'memberistic' )                      => count( $errors ),
			),
			'errors'  => $errors,
		);
	}

	/**
	 * Create a membership + its primary person from a mapped row.
	 *
	 * @param array       $m    Mapped member row.
	 * @param array       $plan Plan record.
	 * @param string      $note Optional membership note.
	 * @return int Membership ID, or 0 on failure.
	 */
	private static function create_membership( $m, $plan, $note = '' ) {
		$start   = Memberships_Repository::sanitize_datetime( $m['start'] );
		$renewal = Memberships_Repository::sanitize_datetime( $m['renewal'] ?: $m['expires'] );
		$end     = Memberships_Repository::sanitize_datetime( $m['expires'] );

		$plan_slug = isset( $plan['slug'] ) ? (string) $plan['slug'] : '';
		$status    = self::status_for_row( $end, $plan_slug );

		$notes = trim( sprintf( __( 'Imported from PMPro (legacy ID %1$s, level %2$s).', 'memberistic' ), $m['legacy_id'] ?: '—', $m['level'] ?: '—' ) . ( $note ? ' ' . $note : '' ) );

		$display_name = $m['name'] ?: ( $m['email'] ?: __( 'Imported member', 'memberistic' ) );

		$membership_id = Memberships_Repository::create(
			array(
				'plan_id'                => (int) $plan['id'],
				'billing_cycle'          => $m['cycle'],
				'status'                 => $status,
				'start_date'             => $start,
				'renewal_date'           => $renewal,
				'end_date'               => $end,
				'payment_source'         => ( 'no-plan' === $plan_slug ) ? 'instore' : 'import',
				'stripe_subscription_id' => $m['stripe_sub'],
				'primary_user_id'        => self::wp_user_id_for( $m['email'] ),
				'notes'                  => $notes,
			)
		);

		if ( ! $membership_id ) {
			return 0;
		}

		People_Repository::create(
			array(
				'membership_id' => $membership_id,
				'role'          => 'primary',
				'full_name'     => $display_name,
				'email'         => $m['email'],
				'phone'         => $m['phone'],
				'wp_user_id'    => self::wp_user_id_for( $m['email'] ),
				'status'        => 'active',
			)
		);

		return (int) $membership_id;
	}

	/**
	 * Import payment/order history. Matches by email, else creates a stub
	 * "Instore" member, else attaches to a shared Walk-in membership. No
	 * row in the input file is ever silently dropped.
	 *
	 * @param array $rows Raw rows.
	 * @return array
	 */
	private static function commit_payments( $rows ) {
		$imported          = 0;
		$matched           = 0;
		$created_instore   = 0;
		$attached_walkin   = 0;
		$errors            = array();
		$walkin_membership = null;
		$no_plan           = null;

		foreach ( $rows as $i => $row ) {
			$email = sanitize_email( self::val( $row, 'email' ) );

			// Resolve a membership for this row.
			$membership = $email ? Memberships_Repository::get_by_person_email( $email ) : null;
			$disposition = '';

			if ( ! $membership ) {
				if ( ! $no_plan ) {
					$no_plan = self::ensure_no_plan();
				}
				if ( ! $no_plan ) {
					$errors[] = sprintf( __( 'Row %1$d: could not create No-Plan sentinel.', 'memberistic' ), $i + 2 );
					continue;
				}

				if ( $email ) {
					// Orphan email — create one stub Instore member per orphan email.
					$display_name = trim(
						self::val( $row, 'full_name' ) ?:
						( self::val( $row, 'first_name' ) . ' ' . self::val( $row, 'last_name' ) )
					) ?: $email;

					$mid = Memberships_Repository::create(
						array(
							'plan_id'        => (int) $no_plan['id'],
							'billing_cycle'  => 'monthly',
							'status'         => 'needs_review',
							'payment_source' => 'instore',
							'notes'          => __( 'Auto-created from orphan order import — please review and merge if a real member exists.', 'memberistic' ),
						)
					);
					if ( ! $mid ) {
						$errors[] = sprintf( __( 'Row %1$d (%2$s): could not create stub Instore member.', 'memberistic' ), $i + 2, $email );
						continue;
					}
					People_Repository::create(
						array(
							'membership_id' => $mid,
							'role'          => 'primary',
							'full_name'     => $display_name,
							'email'         => $email,
							'phone'         => self::val( $row, 'phone' ),
							'wp_user_id'    => self::wp_user_id_for( $email ),
							'status'        => 'active',
						)
					);
					$membership = Memberships_Repository::get( (int) $mid );
					$created_instore++;
					$disposition = 'orphan_email';
				} else {
					// No email — single shared Walk-in membership.
					if ( ! $walkin_membership ) {
						$walkin_membership = self::ensure_walkin_membership( $no_plan );
					}
					if ( ! $walkin_membership ) {
						$errors[] = sprintf( __( 'Row %1$d: could not create Instore Walk-in membership.', 'memberistic' ), $i + 2 );
						continue;
					}
					$membership = $walkin_membership;
					$attached_walkin++;
					$disposition = 'walkin';
				}
			} else {
				$matched++;
				$disposition = 'matched';
			}

			$amount = (float) preg_replace( '/[^0-9.\-]/', '', self::val( $row, 'total' ) );
			$status = strtolower( self::val( $row, 'status' ) );
			$status = in_array( $status, array( 'success', 'completed', 'paid' ), true ) ? 'completed'
				: ( in_array( $status, array( 'refunded', 'refund' ), true ) ? 'refunded'
					: ( in_array( $status, array( 'failed', 'error', 'declined' ), true ) ? 'failed' : 'pending' ) );

			$ok = Payments_Repository::create(
				array(
					'membership_id'          => (int) $membership['id'],
					'amount'                 => $amount,
					'payment_gateway'        => self::val( $row, 'gateway' ) ?: ( 'matched' === $disposition ? 'manual' : 'instore' ),
					'gateway_transaction_id' => self::val( $row, 'txn_id' ) ?: self::val( $row, 'order_code' ) ?: self::val( $row, 'order_id' ),
					'status'                 => $status,
					'paid_at'                => self::val( $row, 'date' ),
				)
			);
			if ( $ok ) {
				$imported++;
			} else {
				$errors[] = sprintf( __( 'Row %1$d (%2$s): payment could not be saved.', 'memberistic' ), $i + 2, $email ?: __( '(no email)', 'memberistic' ) );
			}
		}

		return array(
			'summary' => array(
				__( 'Payments imported', 'memberistic' )                 => $imported,
				__( '— matched to existing members', 'memberistic' )     => $matched,
				__( '— attached to new Instore members', 'memberistic' ) => $created_instore,
				__( '— attached to Walk-in', 'memberistic' )             => $attached_walkin,
				__( 'Errors', 'memberistic' )                            => count( $errors ),
			),
			'errors'  => $errors,
		);
	}

	/**
	 * Return (creating if missing) the shared "Instore Walk-in" membership.
	 * Used as a catch-all for imported orders with no customer email.
	 *
	 * @param array<string, mixed> $no_plan No-Plan sentinel plan row.
	 * @return array<string, mixed>|null
	 */
	private static function ensure_walkin_membership( $no_plan ) {
		global $wpdb;

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Memberships_Repository::table() . ' WHERE plan_id = %d AND payment_source = %s AND notes LIKE %s LIMIT 1',
				(int) $no_plan['id'],
				'instore',
				'%' . $wpdb->esc_like( '[walkin]' ) . '%'
			),
			ARRAY_A
		);
		if ( $existing ) {
			return $existing;
		}

		$mid = Memberships_Repository::create(
			array(
				'plan_id'        => (int) $no_plan['id'],
				'billing_cycle'  => 'monthly',
				'status'         => 'needs_review',
				'payment_source' => 'instore',
				'notes'          => '[walkin] ' . __( 'Catch-all Instore Walk-in membership — payments here came from order imports without an email address.', 'memberistic' ),
			)
		);
		if ( ! $mid ) {
			return null;
		}
		People_Repository::create(
			array(
				'membership_id' => $mid,
				'role'          => 'primary',
				'full_name'     => __( 'Instore Walk-in', 'memberistic' ),
				'status'        => 'active',
			)
		);
		return Memberships_Repository::get( (int) $mid );
	}

	/**
	 * Look up a WP user ID by email (links imported members to accounts).
	 *
	 * @param string $email Email address.
	 * @return int User ID or 0.
	 */
	private static function wp_user_id_for( $email ) {
		if ( ! $email ) {
			return 0;
		}
		$user = get_user_by( 'email', $email );
		return $user ? (int) $user->ID : 0;
	}
}
