<?php
/**
 * Frontend account template — member dashboard.
 *
 * Sidebar + tabbed layout (Dashboard, Membership Details, Billing,
 * Additional Members, Booking History, Digital Member Card) styled to the
 * Guns 2 Ammo tactical-dark design system.
 *
 * @package Memberistic
 */

use WordPressistic\Memberistic\Database\Plans_Repository;
use function WordPressistic\Memberistic\memberistic_format_price;
use function WordPressistic\Memberistic\memberistic_get_page_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$renewal_url = memberistic_get_page_url( 'renewal_page_id', 'memberistic-renewal', home_url( '/' ) );
$plans_url   = memberistic_get_page_url( 'plans_page_id', 'memberistic-memberships', home_url( '/' ) );
$support_url = home_url( '/get-support/' );
$lane_url    = home_url( '/book-a-lane/' );
?>
<div class="memberistic-frontend memberistic-account memberistic-acct">
<?php if ( ! $current ) : ?>
	<div class="memberistic-acct-empty">
		<h3><?php esc_html_e( 'No active membership found', 'memberistic' ); ?></h3>
		<p><?php esc_html_e( 'No membership is linked to this account yet. If you joined online, sign in with the same email you used at checkout.', 'memberistic' ); ?></p>
		<a class="memberistic-acct-cta memberistic-acct-cta--primary" href="<?php echo esc_url( $plans_url ); ?>"><?php esc_html_e( 'View Membership Plans', 'memberistic' ); ?></a>
	</div>
<?php else :
	$user        = wp_get_current_user();
	$display     = $user->display_name ? $user->display_name : ( $current['full_name'] ?: __( 'Member', 'memberistic' ) );
	$first       = strtok( (string) $display, ' ' );
	$initials    = strtoupper( substr( $first, 0, 1 ) . substr( (string) strrchr( ' ' . $display, ' ' ), 1, 1 ) );
	$plan        = ! empty( $current['plan_id'] ) ? Plans_Repository::get( (int) $current['plan_id'] ) : null;
	$benefits    = $plan ? json_decode( (string) $plan['benefits'], true ) : array();
	$benefits    = is_array( $benefits ) ? $benefits : array();
	$is_annual   = 'annual' === $current['billing_cycle'];
	$price       = $plan ? (float) ( $is_annual ? $plan['annual_price'] : $plan['monthly_price'] ) : 0;
	$people_max  = (int) ( $current['included_people'] ?? ( $plan['included_people'] ?? 1 ) );
	$people_have = (int) ( $current['people_count'] ?? count( $people ) );
	$currency    = ! empty( $payments[0]['currency'] ) ? $payments[0]['currency'] : 'USD';
	$pay_method  = ! empty( $payments[0]['payment_method'] ) ? ucwords( str_replace( '_', ' ', $payments[0]['payment_method'] ) ) : __( 'Card on file', 'memberistic' );
	$member_id   = 'G2A-' . ( $current['start_date'] ? gmdate( 'Y', strtotime( $current['start_date'] ) ) : gmdate( 'Y' ) ) . '-' . str_pad( (string) $current['id'], 4, '0', STR_PAD_LEFT );
	$since       = $current['start_date'] ? date_i18n( 'M Y', strtotime( $current['start_date'] ) ) : '—';
	$renew       = $current['renewal_date'] ? date_i18n( 'M j, Y', strtotime( $current['renewal_date'] ) ) : '—';
	$status      = (string) $current['status'];
	$status_ok   = in_array( $status, array( 'active', 'comped', 'trial' ), true );
	$member_email = $user->user_email ? $user->user_email : ( $current['email'] ?? '' );
	// Dynamic QR payload — encodes this member's verification details, so a
	// scan at the range desk reveals their name, email and membership level.
	$qr_payload  = implode( "\n", array(
		'MEMBERS HUB — MEMBER VERIFICATION',
		'Name: ' . $display,
		'Email: ' . $member_email,
		'Membership: ' . $current['plan_name'] . ' (' . ucfirst( (string) $current['billing_cycle'] ) . ')',
		'Member ID: ' . $member_id,
		'Status: ' . ucfirst( $status ),
		'Renews: ' . $renew,
	) );
	// Dynamic QR — encodes a short verification URL (NOT the PII).
	// Staff scan -> open the URL -> see CURRENT membership status +
	// the member's profile photo on a server-rendered card. The QR
	// payload itself contains no name, email, or member id.
	$verify_url  = class_exists( '\WordPressistic\Memberistic\Utilities\Verification' ) && $user->ID
		? \WordPressistic\Memberistic\Utilities\Verification::verify_url( $user->ID )
		: '';
	$qr_svg      = ( $verify_url && class_exists( '\WordPressistic\Memberistic\Utilities\QR' ) )
		? \WordPressistic\Memberistic\Utilities\QR::svg( $verify_url, 260, '#ffffff', '#0f2044' )
		: '';
	$photo_id    = class_exists( '\WordPressistic\Memberistic\Utilities\Verification' ) && $user->ID
		? \WordPressistic\Memberistic\Utilities\Verification::get_profile_image_id( $user->ID )
		: 0;
	$photo_url   = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : '';
	?>
	<div class="memberistic-acct-statusbar">
		<span class="memberistic-acct-statuspill <?php echo $status_ok ? 'is-ok' : 'is-warn'; ?>">
			<span class="memberistic-acct-dot"></span><?php echo esc_html( strtoupper( $status_ok ? __( 'Active Member', 'memberistic' ) : $status ) ); ?>
		</span>
	</div>

	<?php if ( in_array( $status, array( 'past_due', 'expired' ), true ) ) : ?>
		<div class="memberistic-acct-banner">
			<?php if ( 'past_due' === $status ) : ?>
				<?php esc_html_e( 'Your membership payment is past due — please update your payment method.', 'memberistic' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Your membership has expired.', 'memberistic' ); ?>
				<a href="<?php echo esc_url( $renewal_url ); ?>"><?php esc_html_e( 'Renew now', 'memberistic' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="memberistic-acct-shell">
		<aside class="memberistic-acct-side">
			<div class="memberistic-acct-id">
				<span class="memberistic-acct-avatar" data-mem-avatar>
					<?php if ( $photo_url ) : ?>
						<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $display ); ?>">
					<?php else : ?>
						<?php echo esc_html( $initials ? $initials : 'M' ); ?>
					<?php endif; ?>
				</span>
				<div>
					<strong><?php echo esc_html( $display ); ?></strong>
					<span><?php printf( esc_html__( 'Member since %s', 'memberistic' ), esc_html( $since ) ); ?></span>
					<div class="memberistic-acct-photo-actions" style="margin-top:6px;">
						<button type="button" class="memberistic-acct-photo-btn" data-mem-photo-trigger><?php echo $photo_id ? esc_html__( 'Change photo', 'memberistic' ) : esc_html__( 'Add photo', 'memberistic' ); ?></button>
						<?php if ( $photo_id ) : ?>
							<button type="button" class="memberistic-acct-photo-btn" data-mem-photo-remove><?php esc_html_e( 'Remove', 'memberistic' ); ?></button>
						<?php endif; ?>
						<input type="file" accept="image/*" data-mem-photo-input hidden>
					</div>
				</div>
			</div>
			<div class="memberistic-acct-planpill"><?php echo esc_html( $current['plan_name'] ); ?> <?php esc_html_e( 'Plan', 'memberistic' ); ?></div>
			<nav class="memberistic-acct-nav">
				<a href="#dashboard" data-tab="dashboard" class="is-active"><span class="memberistic-acct-ic">▦</span><?php esc_html_e( 'Dashboard', 'memberistic' ); ?></a>
				<a href="#details"   data-tab="details"><span class="memberistic-acct-ic">◇</span><?php esc_html_e( 'Membership Details', 'memberistic' ); ?></a>
				<a href="#billing"   data-tab="billing"><span class="memberistic-acct-ic">▭</span><?php esc_html_e( 'Billing &amp; Payments', 'memberistic' ); ?></a>
				<a href="#members"   data-tab="members"><span class="memberistic-acct-ic">⚇</span><?php esc_html_e( 'Additional Members', 'memberistic' ); ?></a>
				<a href="#bookings"  data-tab="bookings"><span class="memberistic-acct-ic">◷</span><?php esc_html_e( 'Booking History', 'memberistic' ); ?></a>
				<a href="#card"      data-tab="card"><span class="memberistic-acct-ic">▤</span><?php esc_html_e( 'Digital Member Card', 'memberistic' ); ?></a>
				<span class="memberistic-acct-navsep"></span>
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="memberistic-acct-signout"><span class="memberistic-acct-ic">⤶</span><?php esc_html_e( 'Sign Out', 'memberistic' ); ?></a>
			</nav>
		</aside>

		<div class="memberistic-acct-main">

			<!-- DASHBOARD -->
			<section class="memberistic-acct-view is-active" data-panel="dashboard">
				<header class="memberistic-acct-welcome">
					<h2><?php printf( esc_html__( 'Welcome back, %s.', 'memberistic' ), esc_html( $first ) ); ?></h2>
					<p><?php esc_html_e( 'Your range access is active and your member card is ready. Lane availability is good for today.', 'memberistic' ); ?></p>
				</header>
				<div class="memberistic-acct-stats">
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Plan', 'memberistic' ); ?></span><strong><?php echo esc_html( $current['plan_name'] ); ?></strong></div>
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Members', 'memberistic' ); ?></span><strong><?php echo esc_html( $people_have . '/' . $people_max ); ?></strong></div>
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Next Renewal', 'memberistic' ); ?></span><strong><?php echo esc_html( $renew ); ?></strong></div>
					<div class="memberistic-acct-stat"><span><?php esc_html_e( 'Member Since', 'memberistic' ); ?></span><strong><?php echo esc_html( $since ); ?></strong></div>
				</div>
				<div class="memberistic-acct-actions">
					<a class="memberistic-acct-action" href="<?php echo esc_url( $lane_url ); ?>">
						<span class="memberistic-acct-ic">◷</span>
						<span><strong><?php esc_html_e( 'Book A Lane', 'memberistic' ); ?></strong><small><?php esc_html_e( 'Reserve online 24/7', 'memberistic' ); ?></small></span>
					</a>
					<div class="memberistic-acct-action is-static">
						<span class="memberistic-acct-ic">◴</span>
						<span><strong><?php esc_html_e( 'Range Hours', 'memberistic' ); ?></strong><small><?php esc_html_e( 'Mon–Thu 10–6 · Sat 9–8 · Sun 12–6', 'memberistic' ); ?></small></span>
					</div>
					<a class="memberistic-acct-action" href="#members" data-tab="members">
						<span class="memberistic-acct-ic">⚇</span>
						<span><strong><?php esc_html_e( 'Manage Members', 'memberistic' ); ?></strong><small><?php esc_html_e( 'Add, edit or remove people', 'memberistic' ); ?></small></span>
					</a>
					<a class="memberistic-acct-action" href="#billing" data-tab="billing">
						<span class="memberistic-acct-ic">▭</span>
						<span><strong><?php esc_html_e( 'Update Payment', 'memberistic' ); ?></strong><small><?php echo esc_html( $pay_method ); ?></small></span>
					</a>
				</div>
			</section>

			<!-- MEMBERSHIP DETAILS -->
			<section class="memberistic-acct-view" data-panel="details">
				<div class="memberistic-acct-block">
					<div class="memberistic-acct-blockhd">
						<h2><?php esc_html_e( 'Plan Details', 'memberistic' ); ?></h2>
						<span class="memberistic-acct-tag"><?php echo esc_html( $current['plan_name'] ); ?></span>
					</div>
					<?php if ( $benefits ) : ?>
						<ul class="memberistic-acct-benefits">
							<?php foreach ( $benefits as $benefit ) : ?>
								<li><?php echo esc_html( $benefit ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="memberistic-acct-muted"><?php esc_html_e( 'Unlimited range time and full member benefits.', 'memberistic' ); ?></p>
					<?php endif; ?>
					<div class="memberistic-acct-ctas">
						<a class="memberistic-acct-cta memberistic-acct-cta--primary" href="<?php echo esc_url( $plans_url ); ?>"><?php esc_html_e( 'Change Plan', 'memberistic' ); ?></a>
						<a class="memberistic-acct-cta memberistic-acct-cta--ghost" href="<?php echo esc_url( $renewal_url ); ?>"><?php echo esc_html( $is_annual ? __( 'Switch to Monthly', 'memberistic' ) : __( 'Switch to Annual', 'memberistic' ) ); ?></a>
					</div>
				</div>
				<div class="memberistic-acct-block memberistic-acct-block--danger">
					<h3><?php esc_html_e( 'Cancel Membership', 'memberistic' ); ?></h3>
					<p class="memberistic-acct-muted"><?php esc_html_e( 'Cancel anytime. Access remains active until the end of your current billing period.', 'memberistic' ); ?></p>
					<a class="memberistic-acct-cta memberistic-acct-cta--ghost" href="<?php echo esc_url( $support_url ); ?>"><?php esc_html_e( 'Request Cancellation', 'memberistic' ); ?></a>
				</div>
			</section>

			<!-- BILLING -->
			<section class="memberistic-acct-view" data-panel="billing">
				<div class="memberistic-acct-block">
					<h2><?php esc_html_e( 'Next Charge', 'memberistic' ); ?></h2>
					<div class="memberistic-acct-charge">
						<div>
							<span class="memberistic-acct-amount"><?php echo esc_html( memberistic_format_price( $price, $currency ) ); ?></span>
							<span class="memberistic-acct-muted"><?php printf( esc_html__( 'on %s', 'memberistic' ), esc_html( $renew ) ); ?></span>
						</div>
						<div>
							<span class="memberistic-acct-mini"><?php esc_html_e( 'Method', 'memberistic' ); ?></span>
							<strong><?php echo esc_html( $pay_method ); ?></strong>
						</div>
						<a class="memberistic-acct-cta memberistic-acct-cta--ghost" href="<?php echo esc_url( $renewal_url ); ?>"><?php esc_html_e( 'Update Payment Method', 'memberistic' ); ?></a>
					</div>
				</div>
				<div class="memberistic-acct-block">
					<h2><?php esc_html_e( 'Billing History', 'memberistic' ); ?></h2>
					<?php if ( empty( $payments ) ) : ?>
						<p class="memberistic-acct-muted"><?php esc_html_e( 'No payments are recorded yet.', 'memberistic' ); ?></p>
					<?php else : ?>
						<div class="memberistic-acct-tablewrap">
							<table class="memberistic-acct-table">
								<thead><tr>
									<th><?php esc_html_e( 'Date', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Amount', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( $payments as $payment ) :
										$pstatus = strtolower( (string) $payment['status'] );
										?>
										<tr>
											<td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $payment['paid_at'] ?: $payment['created_at'] ) ) ); ?></td>
											<td><?php echo esc_html( memberistic_format_price( $payment['amount'], $payment['currency'] ) ); ?></td>
											<td><span class="memberistic-acct-pill memberistic-acct-pill--<?php echo esc_attr( in_array( $pstatus, array( 'completed', 'success', 'paid' ), true ) ? 'ok' : 'warn' ); ?>"><?php echo esc_html( ucfirst( $pstatus ) ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<!-- ADDITIONAL MEMBERS -->
			<section class="memberistic-acct-view" data-panel="members">
				<div class="memberistic-acct-block">
					<div class="memberistic-acct-blockhd">
						<h2><?php esc_html_e( 'People On This Membership', 'memberistic' ); ?></h2>
						<span class="memberistic-acct-tag"><?php echo esc_html( $people_have . ' / ' . $people_max ); ?></span>
					</div>
					<?php if ( empty( $people ) ) : ?>
						<p class="memberistic-acct-muted"><?php esc_html_e( 'No people are linked to this membership yet.', 'memberistic' ); ?></p>
					<?php else : ?>
						<div class="memberistic-acct-tablewrap">
							<table class="memberistic-acct-table">
								<thead><tr>
									<th><?php esc_html_e( 'Name', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Role', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Waiver', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( $people as $person ) : ?>
										<tr>
											<td><?php echo esc_html( $person['full_name'] ); ?></td>
											<td><?php echo esc_html( ucwords( (string) $person['role'] ) ); ?></td>
											<td><span class="memberistic-acct-pill memberistic-acct-pill--<?php echo esc_attr( 'signed' === $person['waiver_status'] ? 'ok' : 'warn' ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $person['waiver_status'] ) ) ); ?></span></td>
											<td><span class="memberistic-acct-pill memberistic-acct-pill--<?php echo esc_attr( 'active' === $person['status'] ? 'ok' : 'warn' ); ?>"><?php echo esc_html( ucfirst( (string) $person['status'] ) ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
					<div class="memberistic-acct-ctas">
						<a class="memberistic-acct-cta memberistic-acct-cta--ghost" href="<?php echo esc_url( $support_url ); ?>"><?php esc_html_e( 'Add or Update a Member', 'memberistic' ); ?></a>
					</div>
				</div>
			</section>

			<!-- BOOKING HISTORY -->
			<section class="memberistic-acct-view" data-panel="bookings">
				<div class="memberistic-acct-block">
					<h2><?php esc_html_e( 'Booking &amp; Check-In History', 'memberistic' ); ?></h2>
					<?php if ( empty( $bookings ) && empty( $checkins ) ) : ?>
						<p class="memberistic-acct-muted"><?php esc_html_e( 'No booking or check-in records yet.', 'memberistic' ); ?></p>
					<?php else : ?>
						<div class="memberistic-acct-tablewrap">
							<table class="memberistic-acct-table">
								<thead><tr>
									<th><?php esc_html_e( 'Type', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Resource', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
									<th><?php esc_html_e( 'Date', 'memberistic' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( $bookings as $booking ) : ?>
										<tr>
											<td><?php echo esc_html( ! empty( $booking['booking_type_name'] ) ? $booking['booking_type_name'] : __( 'Lane Booking', 'memberistic' ) ); ?></td>
											<td><?php echo esc_html( $booking['resource_name'] ?? '—' ); ?></td>
											<td><span class="memberistic-acct-pill"><?php echo esc_html( ucfirst( (string) ( $booking['status'] ?? 'pending' ) ) ); ?></span></td>
											<td><?php echo esc_html( ! empty( $booking['start_at'] ) ? date_i18n( 'M j, Y', strtotime( $booking['start_at'] ) ) : '—' ); ?></td>
										</tr>
									<?php endforeach; ?>
									<?php foreach ( $checkins as $checkin ) : ?>
										<tr>
											<td><?php esc_html_e( 'Range Check-In', 'memberistic' ); ?></td>
											<td><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $checkin['checkin_type'] ?? '' ) ) ) ); ?></td>
											<td><span class="memberistic-acct-pill memberistic-acct-pill--ok"><?php echo esc_html( ucfirst( (string) ( $checkin['status'] ?? '' ) ) ); ?></span></td>
											<td><?php echo esc_html( ! empty( $checkin['checked_in_at'] ) ? date_i18n( 'M j, Y', strtotime( $checkin['checked_in_at'] ) ) : '—' ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<!-- DIGITAL MEMBER CARD -->
			<section class="memberistic-acct-view" data-panel="card">
				<div class="memberistic-acct-block memberistic-acct-block--center">
					<h2><?php esc_html_e( 'Your Digital Member Card', 'memberistic' ); ?></h2>
					<div class="memberistic-acct-pass" id="memberistic-acct-pass">
						<div class="memberistic-acct-pass__top">
							<span class="memberistic-acct-pass__brand"><?php echo esc_html( strtoupper( memberistic_get_brand_label() ) ); ?></span>
							<span class="memberistic-acct-pass__plan"><?php echo esc_html( strtoupper( $current['plan_name'] ) ); ?></span>
						</div>
						<?php if ( $photo_url ) : ?>
							<div class="memberistic-acct-pass__photo">
								<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $display ); ?>">
							</div>
						<?php endif; ?>
						<div class="memberistic-acct-pass__name"><?php echo esc_html( strtoupper( $display ) ); ?></div>
						<div class="memberistic-acct-pass__body">
							<div class="memberistic-acct-pass__meta">
								<span class="memberistic-acct-mini"><?php esc_html_e( 'Member ID', 'memberistic' ); ?></span>
								<strong><?php echo esc_html( $member_id ); ?></strong>
								<span class="memberistic-acct-mini"><?php esc_html_e( 'Member Since', 'memberistic' ); ?></span>
								<strong><?php echo esc_html( $since ); ?></strong>
								<span class="memberistic-acct-mini"><?php esc_html_e( 'Renews', 'memberistic' ); ?></span>
								<strong><?php echo esc_html( $renew ); ?></strong>
							</div>
							<div class="memberistic-acct-pass__qr" role="img" aria-label="<?php esc_attr_e( 'Member verification QR code', 'memberistic' ); ?>">
								<?php
								// Primary: a fetched PNG from a well-tested QR
								// generator (api.qrserver.com). The payload here
								// is ONLY the verify URL — a random 32-char
								// token, no PII — so handing it to a 3rd-party
								// generator is safe.
								//
								// We also render the in-process SVG underneath
								// as a backup that browsers fall back to via the
								// <img> onerror handler, so the card still scans
								// even if the external service is blocked.
								if ( $verify_url ) :
									$qr_remote = add_query_arg(
										array(
											'size'   => '320x320',
											'data'   => rawurlencode( $verify_url ),
											'margin' => '0',
											'ecc'    => 'M',
										),
										'https://api.qrserver.com/v1/create-qr-code/'
									);
									?>
									<img class="memberistic-acct-pass__qr-img"
									     src="<?php echo esc_url( $qr_remote ); ?>"
									     alt=""
									     width="320" height="320"
									     style="width:100%;height:100%;display:block;"
									     onerror="this.style.display='none';var n=this.nextElementSibling;if(n)n.style.display='block';">
									<span class="memberistic-acct-pass__qr-svg" style="display:none;width:100%;height:100%;"><?php
										echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated SVG with hard-coded markup
									?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<p class="memberistic-acct-mini memberistic-acct-passnote"><?php esc_html_e( 'Show at the range desk · or scan the QR', 'memberistic' ); ?></p>
					<div class="memberistic-acct-ctas memberistic-acct-ctas--center">
						<button type="button" class="memberistic-acct-cta memberistic-acct-cta--primary" data-print-card><?php esc_html_e( 'Download / Print Card', 'memberistic' ); ?></button>
					</div>
				</div>
			</section>

		</div>
	</div>
<?php endif; ?>
</div>

<style>
.memberistic-acct{--ma-card:#26252C;--ma-line:rgba(255,255,255,.09);--ma-line2:rgba(255,255,255,.17);--ma-brass:#C9A84C;--ma-brass2:#E3C06A;--ma-ember:#E8802F;--ma-white:#F4F4F6;--ma-fog:#CBCAD2;--ma-silver:#8E8D96;
	font-family:var(--font-body,"DM Sans",-apple-system,Segoe UI,sans-serif);}
.memberistic-acct *{box-sizing:border-box;}
.memberistic-acct .memberistic-acct-statusbar{margin:0 0 22px;}
.memberistic-acct-statuspill{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;
	font-family:var(--font-mono,"Space Mono",monospace);font-size:10px;letter-spacing:.18em;}
.memberistic-acct-statuspill.is-ok{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.4);color:#6FE49A!important;}
.memberistic-acct-statuspill.is-warn{background:rgba(232,128,47,.1);border:1px solid rgba(232,128,47,.4);color:#F0A565!important;}
.memberistic-acct-dot{width:7px;height:7px;border-radius:50%;background:currentColor;}
.memberistic-acct-banner{background:rgba(232,128,47,.12);border:1px solid rgba(232,128,47,.4);color:#F0A565!important;
	padding:12px 16px;border-radius:4px;margin-bottom:18px;font-size:14px;}
.memberistic-acct-banner a{color:var(--ma-brass2)!important;}

.memberistic-acct-shell{display:grid;grid-template-columns:280px 1fr;gap:22px;align-items:start;}
@media(max-width:900px){.memberistic-acct-shell{grid-template-columns:1fr;}}

.memberistic-acct-side{background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;border-radius:4px;padding:22px;}
.memberistic-acct-id{display:flex;gap:13px;align-items:center;padding-bottom:18px;border-bottom:1px solid var(--ma-line);}
.memberistic-acct-avatar{width:48px;height:48px;border-radius:50%;flex:0 0 48px;display:grid;place-items:center;
	background:linear-gradient(135deg,var(--ma-brass),#A8862F);color:#1A191E!important;
	font-family:var(--font-display,"Bebas Neue",sans-serif);font-size:21px;letter-spacing:.04em;}
.memberistic-acct-id strong{display:block;color:var(--ma-white)!important;font-size:15px;font-weight:700;}
.memberistic-acct-id span{display:block;color:var(--ma-silver)!important;font-size:11px;
	font-family:var(--font-mono,monospace);letter-spacing:.08em;text-transform:uppercase;margin-top:3px;}
.memberistic-acct-planpill{margin:16px 0 18px;padding:9px 14px;border:1px solid var(--ma-brass)!important;border-radius:2px;
	color:var(--ma-brass2)!important;font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.2em;
	text-transform:uppercase;text-align:center;}
.memberistic-acct-nav{display:flex;flex-direction:column;gap:2px;}
.memberistic-acct-nav a{display:flex;align-items:center;gap:11px;padding:11px 12px;text-decoration:none;
	color:var(--ma-fog)!important;font-size:13px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;
	border-left:2px solid transparent;border-radius:2px;transition:background .15s,color .15s,border-color .15s;}
.memberistic-acct-nav a:hover{background:rgba(255,255,255,.04);color:var(--ma-white)!important;}
.memberistic-acct-nav a.is-active{background:rgba(201,168,76,.08);color:var(--ma-brass2)!important;border-left-color:var(--ma-brass);}
.memberistic-acct-nav a.is-active .memberistic-acct-ic{color:var(--ma-brass2)!important;}
.memberistic-acct-ic{font-size:13px;color:var(--ma-silver)!important;width:16px;text-align:center;}
.memberistic-acct-navsep{height:1px;background:var(--ma-line);margin:10px 0;}
.memberistic-acct-signout{color:var(--ma-ember)!important;}
.memberistic-acct-signout:hover{color:#F4A062!important;}

.memberistic-acct-main{min-width:0;}
.memberistic-acct-view{display:none;}
.memberistic-acct-view.is-active{display:block;animation:ma-fade .25s ease;}
@keyframes ma-fade{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}

.memberistic-acct-welcome h2{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	font-size:clamp(34px,4vw,52px);color:var(--ma-white)!important;margin:0;line-height:1;letter-spacing:.02em;}
.memberistic-acct-welcome p{color:var(--ma-fog)!important;margin:10px 0 24px;font-size:15px;line-height:1.6;max-width:60ch;}

.memberistic-acct-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px;}
@media(max-width:680px){.memberistic-acct-stats{grid-template-columns:1fr 1fr;}}
.memberistic-acct-stat{background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;padding:18px;border-radius:4px;}
.memberistic-acct-stat span{display:block;font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.18em;
	text-transform:uppercase;color:var(--ma-silver)!important;margin-bottom:8px;}
.memberistic-acct-stat strong{display:block;font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	font-size:26px;color:var(--ma-white)!important;line-height:1;letter-spacing:.02em;}

.memberistic-acct-actions{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:680px){.memberistic-acct-actions{grid-template-columns:1fr;}}
.memberistic-acct-action{display:flex;align-items:center;gap:14px;padding:18px;text-decoration:none;
	background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;border-radius:4px;
	transition:border-color .15s,transform .15s;}
.memberistic-acct-action:hover{border-color:var(--ma-brass)!important;transform:translateY(-2px);}
.memberistic-acct-action.is-static{cursor:default;}
.memberistic-acct-action.is-static:hover{border-color:var(--ma-line)!important;transform:none;}
.memberistic-acct-action .memberistic-acct-ic{font-size:20px;color:var(--ma-brass2)!important;width:24px;}
.memberistic-acct-action strong{display:block;color:var(--ma-white)!important;font-size:15px;font-weight:700;}
.memberistic-acct-action small{display:block;color:var(--ma-silver)!important;font-size:12px;margin-top:3px;}

.memberistic-acct-block{background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;
	border-radius:4px;padding:26px;margin-bottom:16px;}
.memberistic-acct-block--center{text-align:center;}
.memberistic-acct-block--danger{border-color:rgba(232,128,47,.3)!important;}
.memberistic-acct-block--danger h3{color:var(--ma-ember)!important;}
.memberistic-acct-block h2,.memberistic-acct-block h3{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	color:var(--ma-white)!important;margin:0 0 14px;letter-spacing:.02em;font-size:28px;line-height:1;}
.memberistic-acct-blockhd{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px;}
.memberistic-acct-blockhd h2{margin:0;}
.memberistic-acct-tag{font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.16em;text-transform:uppercase;
	color:#1A191E!important;background:var(--ma-brass)!important;padding:5px 11px;border-radius:2px;}
.memberistic-acct-muted{color:var(--ma-silver)!important;font-size:14px;line-height:1.6;margin:0 0 16px;}

.memberistic-acct-benefits{list-style:none;margin:0 0 20px;padding:0;display:grid;gap:11px;}
.memberistic-acct-benefits li{position:relative;padding-left:24px;color:var(--ma-fog)!important;font-size:14px;line-height:1.5;}
.memberistic-acct-benefits li::before{content:"\25C6";position:absolute;left:0;top:1px;color:var(--ma-brass2)!important;font-size:10px;}

.memberistic-acct-ctas{display:flex;gap:12px;flex-wrap:wrap;}
.memberistic-acct-ctas--center{justify-content:center;}
.memberistic-acct-cta{display:inline-block;padding:13px 22px;border-radius:2px;text-decoration:none;cursor:pointer;
	font-family:var(--font-condensed,"Barlow Condensed",sans-serif)!important;font-weight:600;font-size:13px;
	letter-spacing:.1em;text-transform:uppercase;border:1px solid transparent;}
.memberistic-acct-cta--primary{background:var(--ma-ember)!important;color:#fff!important;}
.memberistic-acct-cta--primary:hover{background:#F4933F!important;}
.memberistic-acct-cta--ghost{background:transparent!important;border-color:var(--ma-brass)!important;color:var(--ma-brass2)!important;}
.memberistic-acct-cta--ghost:hover{background:rgba(201,168,76,.08)!important;color:var(--ma-white)!important;}

.memberistic-acct-charge{display:flex;flex-wrap:wrap;gap:24px;align-items:center;justify-content:space-between;}
.memberistic-acct-amount{display:block;font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	font-size:48px;color:var(--ma-brass2)!important;line-height:1;}
.memberistic-acct-mini{display:block;font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.16em;
	text-transform:uppercase;color:var(--ma-silver)!important;margin-bottom:4px;}
.memberistic-acct-charge strong{color:var(--ma-white)!important;font-size:15px;}

.memberistic-acct-tablewrap{overflow-x:auto;}
.memberistic-acct-table{width:100%;border-collapse:collapse;}
.memberistic-acct-table th{text-align:left;font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.14em;
	text-transform:uppercase;color:var(--ma-silver)!important;padding:10px 12px;border-bottom:1px solid var(--ma-line2);}
.memberistic-acct-table td{padding:13px 12px;border-bottom:1px solid var(--ma-line);color:var(--ma-fog)!important;font-size:14px;}
.memberistic-acct-table tr:last-child td{border-bottom:0;}
.memberistic-acct-pill{display:inline-block;padding:3px 9px;border-radius:2px;font-family:var(--font-mono,monospace);
	font-size:10px;letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.07);color:var(--ma-fog)!important;}
.memberistic-acct-pill--ok{background:rgba(74,222,128,.12);color:#6FE49A!important;}
.memberistic-acct-pill--warn{background:rgba(232,128,47,.14);color:#F0A565!important;}

.memberistic-acct-pass{max-width:420px;margin:18px auto 0;text-align:left;border-radius:8px;padding:22px;
	background:linear-gradient(135deg,#2E2C25,#1C1B1F 70%);border:1px solid var(--ma-brass)!important;
	box-shadow:0 18px 50px rgba(0,0,0,.45);}
.memberistic-acct-pass__top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
.memberistic-acct-pass__brand{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	color:var(--ma-white)!important;font-size:20px;letter-spacing:.06em;}
.memberistic-acct-pass__plan{font-family:var(--font-mono,monospace);font-size:9px;letter-spacing:.16em;
	border:1px solid var(--ma-brass)!important;color:var(--ma-brass2)!important;padding:4px 9px;border-radius:2px;}
.memberistic-acct-pass__name{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;
	color:var(--ma-white)!important;font-size:30px;letter-spacing:.03em;line-height:1;margin-bottom:16px;}
.memberistic-acct-pass__body{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;}
.memberistic-acct-pass__meta strong{display:block;color:var(--ma-white)!important;font-size:13px;margin-bottom:10px;}
.memberistic-acct-pass__meta strong:last-child{margin-bottom:0;}
.memberistic-acct-pass__qr{width:96px;height:96px;border-radius:4px;background:#fff;padding:5px;flex:0 0 96px;}
.memberistic-acct-passnote{margin:16px 0 18px;text-align:center;}

.memberistic-acct-empty{background:var(--ma-card)!important;border:1px solid var(--ma-line)!important;border-radius:4px;
	padding:40px;text-align:center;}
.memberistic-acct-empty h3{font-family:var(--font-display,"Bebas Neue",sans-serif)!important;color:var(--ma-white)!important;
	font-size:28px;margin:0 0 10px;}
.memberistic-acct-empty p{color:var(--ma-silver)!important;margin:0 0 20px;}

@media print{
	/* Hide EVERYTHING outside the digital card so the printed page is
	   ONLY the branded member card — no nav, no admin bar, no header,
	   no footer. */
	body > *:not(.memberistic-acct){display:none!important;}
	#wpadminbar,header,footer,nav,.g2a-news,.g2a-foot,
	.memberistic-acct-side,.memberistic-acct-statusbar,.memberistic-acct-passnote,
	.memberistic-acct-ctas,.memberistic-acct-block > h2,
	.memberistic-acct-view:not([data-panel="card"]){display:none!important;}
	.memberistic-acct,.memberistic-acct-shell,.memberistic-acct-main,
	.memberistic-acct-view[data-panel="card"]{display:block!important;background:#fff!important;padding:0!important;margin:0!important;}
	.memberistic-acct-block{padding:0!important;background:#fff!important;border:0!important;}
	/* Re-anchor the card so it prints centered without surrounding chrome. */
	.memberistic-acct-pass{margin:24px auto!important;box-shadow:none!important;
		background:linear-gradient(135deg,#1A191E,#2A2934)!important;
		color:#fff!important;-webkit-print-color-adjust:exact!important;
		print-color-adjust:exact!important;}
	.memberistic-acct-pass *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}
	@page{margin:12mm;}
}
/* Profile photo: avatar slot, digital-card hero photo, upload controls */
.memberistic-acct-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
.memberistic-acct-pass__photo{width:84px;height:84px;border-radius:50%;overflow:hidden;margin:14px auto 6px;border:3px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);}
.memberistic-acct-pass__photo img{width:100%;height:100%;object-fit:cover;display:block;}
.memberistic-acct-photo-actions{display:flex;gap:8px;flex-wrap:wrap;}
.memberistic-acct-photo-btn{background:none;border:1px solid rgba(255,255,255,.2);color:inherit;font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:4px 10px;border-radius:4px;cursor:pointer;}
.memberistic-acct-photo-btn:hover{background:rgba(255,255,255,.06);}
.memberistic-acct-photo-msg{font-size:12px;color:var(--ma-muted,#8A95A5);margin-top:6px;display:none;}
.memberistic-acct-photo-msg.is-err{color:#E8802F;display:block;}
.memberistic-acct-photo-msg.is-ok{color:#9DE05B;display:block;}
@media print{ .memberistic-acct-photo-actions{display:none!important;} }
</style>
<script>
(function(){
	var root=document.querySelector('.memberistic-acct');
	if(!root)return;
	var tabs=root.querySelectorAll('[data-tab]');
	var panels=root.querySelectorAll('[data-panel]');
	function show(name){
		var found=false;
		panels.forEach(function(p){var on=p.getAttribute('data-panel')===name;p.classList.toggle('is-active',on);if(on)found=true;});
		if(!found){show('dashboard');return;}
		root.querySelectorAll('.memberistic-acct-nav a[data-tab]').forEach(function(a){
			a.classList.toggle('is-active',a.getAttribute('data-tab')===name);
		});
	}
	tabs.forEach(function(t){
		t.addEventListener('click',function(e){
			var name=t.getAttribute('data-tab');
			e.preventDefault();
			if(history.replaceState){history.replaceState(null,'','#'+name);}
			show(name);
			window.scrollTo({top:root.getBoundingClientRect().top+window.pageYOffset-90,behavior:'smooth'});
		});
	});
	var initial=(location.hash||'').replace('#','');
	show(initial||'dashboard');
	var printBtn=root.querySelector('[data-print-card]');
	if(printBtn){printBtn.addEventListener('click',function(){window.print();});}

	/* ──────────────────────────────────────────────────────────
	 * Profile photo upload
	 * Picks a file via the hidden <input type=file>, POSTs to
	 * /wp-json/memberistic/v1/profile/image, swaps the avatar
	 * + card photo on success. Reload guarantees the digital
	 * card + verification page also pick up the new photo.
	 * ────────────────────────────────────────────────────────── */
	var photoTrigger = root.querySelector('[data-mem-photo-trigger]');
	var photoInput   = root.querySelector('[data-mem-photo-input]');
	var photoRemove  = root.querySelector('[data-mem-photo-remove]');
	function showMsg(text, kind) {
		var msg = root.querySelector('.memberistic-acct-photo-msg');
		if (!msg) {
			msg = document.createElement('div');
			msg.className = 'memberistic-acct-photo-msg';
			var actions = root.querySelector('.memberistic-acct-photo-actions');
			if (actions && actions.parentNode) actions.parentNode.appendChild(msg);
		}
		msg.textContent = text;
		msg.className = 'memberistic-acct-photo-msg is-' + (kind || 'ok');
	}
	function nonce() {
		return (window.wpApiSettings && window.wpApiSettings.nonce) || '';
	}
	function restRoot() {
		return (window.wpApiSettings && window.wpApiSettings.root) || (location.origin + '/wp-json/');
	}
	if (photoTrigger && photoInput) {
		photoTrigger.addEventListener('click', function(){ photoInput.click(); });
		photoInput.addEventListener('change', function(){
			if (!photoInput.files || !photoInput.files[0]) return;
			var file = photoInput.files[0];
			if (file.size > 5 * 1024 * 1024) { showMsg('Image too large — please pick something under 5 MB.', 'err'); return; }
			var fd = new FormData();
			fd.append('file', file);
			showMsg('Uploading…', 'ok');
			fetch(restRoot() + 'memberistic/v1/profile/image', {
				method: 'POST',
				credentials: 'include',
				headers: nonce() ? { 'X-WP-Nonce': nonce() } : {},
				body: fd
			}).then(function(r){ return r.json().then(function(j){ return {ok:r.ok, body:j}; }); })
			  .then(function(res){
				if (!res.ok || !res.body || !res.body.success) {
					showMsg((res.body && (res.body.message || res.body.code)) || 'Upload failed.', 'err');
					return;
				}
				showMsg('Photo updated. Reloading…', 'ok');
				setTimeout(function(){ location.reload(); }, 600);
			  }).catch(function(){ showMsg('Network error. Try again.', 'err'); });
		});
	}
	if (photoRemove) {
		photoRemove.addEventListener('click', function(){
			if (!confirm('Remove your profile photo?')) return;
			fetch(restRoot() + 'memberistic/v1/profile/image', {
				method: 'DELETE',
				credentials: 'include',
				headers: nonce() ? { 'X-WP-Nonce': nonce() } : {}
			}).then(function(){ location.reload(); });
		});
	}
})();
</script>
