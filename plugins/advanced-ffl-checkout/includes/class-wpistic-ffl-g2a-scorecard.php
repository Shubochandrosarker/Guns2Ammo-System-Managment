<?php
/**
 * G2A — Per-dealer scorecard (HTML → browser-print PDF).
 *
 * Operations-grade report card for a single dealer:
 *   • total transfers handled in window
 *   • current pipeline (in-flight, delivered-pending-pickup, completed)
 *   • portal confirmation rate
 *   • average days from shipped → received
 *   • issue / not-my-shipment count
 *   • recent transfer list
 *
 * Generated on the fly (no library needed) — dealer + admin can print to
 * PDF via browser. Useful for quarterly ATF compliance reviews and as a
 * deliverable when courting larger dealer partners.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Scorecard {

	const QUERY_VAR = 'g2a_scorecard';

	public function __construct() {
		add_action( 'init',              [ $this, 'register_rewrites' ] );
		add_filter( 'query_vars',         [ $this, 'add_query_var' ] );
		add_action( 'template_redirect',  [ $this, 'maybe_render' ] );
	}

	public function register_rewrites(): void {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([0-9]+)' );
		add_rewrite_rule( '^ffl-scorecard/([0-9]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public function add_query_var( array $v ): array {
		$v[] = self::QUERY_VAR;
		return $v;
	}

	public function maybe_render(): void {
		$id = (int) get_query_var( self::QUERY_VAR );
		if ( ! $id ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_safe_redirect( wp_login_url( home_url() ) );
			exit;
		}
		global $wpdb;
		$dealer = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
		if ( ! $dealer ) {
			status_header( 404 );
			wp_die( 'Dealer not found.' );
		}
		self::render( $dealer );
		exit;
	}

	private static function render( object $d ): void {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );

		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT
				COUNT(*) AS total,
				SUM( CASE WHEN status IN ('shipped_to_dealer','shipped') THEN 1 ELSE 0 END ) AS in_flight,
				SUM( CASE WHEN status = 'received_by_dealer' THEN 1 ELSE 0 END ) AS pending_pickup,
				SUM( CASE WHEN status = 'transferred' THEN 1 ELSE 0 END ) AS completed,
				SUM( CASE WHEN status = 'issue_reported' THEN 1 ELSE 0 END ) AS issues,
				AVG( CASE WHEN dealer_received_date IS NOT NULL AND shipped_date IS NOT NULL
				          THEN DATEDIFF(dealer_received_date, shipped_date) END ) AS avg_ship_to_arrival
			 FROM " . DB::table( 'transfers' ) . " WHERE dealer_id = %d AND created_at >= %s",
			$d->id, $since
		) );

		// Portal confirmation rate from analytics_events
		$issued    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . DB::table( 'analytics_events' ) . " WHERE event_type = 'token_issued' AND dealer_id = %d AND created_at >= %s", $d->id, $since ) ); // phpcs:ignore
		$confirmed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . DB::table( 'analytics_events' ) . " WHERE event_type = 'confirmed' AND dealer_id = %d AND created_at >= %s", $d->id, $since ) ); // phpcs:ignore
		$conf_rate = $issued > 0 ? round( $confirmed / $issued * 100, 1 ) : null;

		$recent = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT transfer_ref, status, item_description, item_make, item_model, created_at, dealer_received_date
			 FROM ' . DB::table( 'transfers' ) . ' WHERE dealer_id = %d ORDER BY created_at DESC LIMIT 15',
			$d->id
		) );

		$theme = Theming::settings();
		$store = $theme['business_name'] ?: get_bloginfo( 'name' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dealer Scorecard — <?php echo esc_html( $d->business_name ); ?></title>
<style>
@page { size: Letter; margin: 0.5in; }
body { font-family: "Helvetica Neue", Arial, sans-serif; color: #1A191E; font-size: 11pt; line-height: 1.5; margin: 24px; }
.cover { background: linear-gradient(140deg, #1A191E, #38363F); color: #F7F7F9; padding: 28px 32px; border-radius: 10px; margin: 0 0 22px; }
.cover h1 { margin: 0 0 4px; color: #DCB45F; font-size: 22pt; letter-spacing: -.01em; }
.cover small { display: block; color: #A7A6AE; font-size: 9pt; letter-spacing: .1em; text-transform: uppercase; }
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 18px 0; }
.kpi { background: #FAF8F4; border: 1px solid #E5E4DE; padding: 14px; border-radius: 8px; text-align: center; }
.kpi .v { font-size: 22pt; font-weight: 800; color: #1A191E; }
.kpi .l { font-size: 9pt; text-transform: uppercase; letter-spacing: .08em; color: #666; margin-top: 4px; }
h2 { font-size: 14pt; margin: 22px 0 8px; border-bottom: 2px solid #DCB45F; padding-bottom: 4px; }
table { width: 100%; border-collapse: collapse; margin: 0 0 18px; font-size: 10pt; }
th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #E5E4DE; }
th { background: #1A191E; color: #DCB45F; font-size: 9pt; text-transform: uppercase; letter-spacing: .06em; }
tr:nth-child(even) td { background: #FAF8F4; }
.btn-print { background: #DCB45F; color: #0F0E12; padding: 8px 16px; border: none; border-radius: 6px; font-weight: 800; cursor: pointer; }
@media print { .no-print { display: none; } body { margin: 0; } }
</style>
</head>
<body>

<div class="no-print" style="text-align:right;margin-bottom:12px;">
	<button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<section class="cover">
	<small>Dealer Scorecard · 90-day window · <?php echo esc_html( date_i18n( 'F j, Y' ) ); ?></small>
	<h1><?php echo esc_html( $d->business_name ); ?></h1>
	<div style="margin-top:6px;font-size:10pt;color:#D8D7DD;font-family:ui-monospace,monospace;">FFL #<?php echo esc_html( $d->license_number ); ?> · <?php echo esc_html( trim( $d->premise_city . ', ' . $d->premise_state ) ); ?></div>
	<div style="margin-top:6px;font-size:10pt;color:#A7A6AE;">Generated by <?php echo esc_html( $store ); ?> · Advanced FFL Checkout</div>
</section>

<section class="kpis">
	<div class="kpi"><div class="v"><?php echo (int) $row->total; ?></div><div class="l">Total transfers</div></div>
	<div class="kpi"><div class="v"><?php echo (int) $row->in_flight; ?></div><div class="l">In flight</div></div>
	<div class="kpi"><div class="v"><?php echo (int) $row->completed; ?></div><div class="l">Completed</div></div>
	<div class="kpi"><div class="v"><?php echo (int) $row->issues; ?></div><div class="l">Issues reported</div></div>
</section>

<section class="kpis">
	<div class="kpi"><div class="v"><?php echo $row->avg_ship_to_arrival !== null ? number_format( (float) $row->avg_ship_to_arrival, 1 ) : '—'; ?></div><div class="l">Avg days ship → arrival</div></div>
	<div class="kpi"><div class="v"><?php echo (int) $issued; ?></div><div class="l">Portal tokens issued</div></div>
	<div class="kpi"><div class="v"><?php echo (int) $confirmed; ?></div><div class="l">Portal confirmations</div></div>
	<div class="kpi"><div class="v"><?php echo null === $conf_rate ? '—' : esc_html( $conf_rate . '%' ); ?></div><div class="l">Confirmation rate</div></div>
</section>

<h2>Recent transfers</h2>
<table>
	<thead><tr><th>Ref</th><th>Status</th><th>Item</th><th>Created</th><th>Arrived</th></tr></thead>
	<tbody>
		<?php if ( empty( $recent ) ) : ?>
			<tr><td colspan="5"><em>No transfers in the window.</em></td></tr>
		<?php else : foreach ( $recent as $r ) : ?>
			<tr>
				<td><code><?php echo esc_html( $r->transfer_ref ); ?></code></td>
				<td><?php echo esc_html( str_replace( '_', ' ', (string) $r->status ) ); ?></td>
				<td><?php echo esc_html( trim( $r->item_make . ' ' . $r->item_model ) ?: $r->item_description ?: '—' ); ?></td>
				<td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $r->created_at ) ) ); ?></td>
				<td><?php echo $r->dealer_received_date ? esc_html( date_i18n( 'M j, Y', strtotime( $r->dealer_received_date ) ) ) : '—'; ?></td>
			</tr>
		<?php endforeach; endif; ?>
	</tbody>
</table>

<p style="margin-top:24px;font-size:9pt;color:#666;border-top:1px solid #ccc;padding-top:10px;">
	Scorecard generated automatically from the FFL transfer + dealer-portal audit log. All metrics rolling 90-day window.
	Confirmation rate = portal "confirmed" events / portal "tokens issued" events for this dealer.
</p>

</body>
</html>
		<?php
	}
}
