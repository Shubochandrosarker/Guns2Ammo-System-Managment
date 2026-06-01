<?php
/**
 * Admin Dealers view — list + search ATF-synced dealers.
 *
 * @package WpisticFFL
 */
defined( 'ABSPATH' ) || exit;

global $wpdb;
$d_table = \WpisticFFL\DB::table( 'dealers' );

$per_page     = 30;
$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
$offset       = ( $current_page - 1 ) * $per_page;
$search       = sanitize_text_field( $_GET['s'] ?? '' );
$state_filter = strtoupper( sanitize_text_field( $_GET['state'] ?? '' ) );
$type_filter  = sanitize_text_field( $_GET['type'] ?? '' );

$where  = 'WHERE 1=1';
$values = [];

if ( $search ) {
	$like     = '%' . $wpdb->esc_like( $search ) . '%';
	$where   .= ' AND (business_name LIKE %s OR license_number LIKE %s OR premise_city LIKE %s OR premise_zip LIKE %s)';
	$values   = array_merge( $values, [ $like, $like, $like, $like ] );
}
if ( $state_filter ) {
	$where   .= ' AND premise_state = %s';
	$values[] = $state_filter;
}
if ( $type_filter ) {
	$where   .= ' AND lic_type = %s';
	$values[] = $type_filter;
}

$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$d_table} {$where}", ...$values ) );
$values[] = $per_page;
$values[] = $offset;
$dealers = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$d_table} {$where} ORDER BY is_preferred DESC, business_name ASC LIMIT %d OFFSET %d", ...$values ) );

$lic_types = [
	'01' => 'Dealer', '02' => 'Pawnbroker', '03' => 'Collector',
	'06' => 'Mfr Ammo', '07' => 'Mfr Firearms', '08' => 'Importer',
	'09' => 'Dealer Dest.', '10' => 'Mfr Dest.', '11' => 'Imp. Dest.',
];
?>

<div class="wrap wpistic-ffl-admin">
	<div class="wpistic-ffl-page-header">
		<h1 class="wpistic-ffl-page-title"><?php esc_html_e( ' G2A - FFL Dealers Lists', 'advanced-ffl-checkout' ); ?></h1>
		<span class="wpistic-ffl-subtitle">
			<?php echo esc_html( number_format( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$d_table} WHERE is_active = 1" ) ) ); ?>
			<?php esc_html_e( 'active dealers', 'advanced-ffl-checkout' ); ?>
		</span>
	</div>

	<!-- Filter bar -->
	<form method="get" class="wpistic-ffl-filter-form">
		<input type="hidden" name="page" value="wpistic-ffl-dealers">
		<div class="wpistic-ffl-filter-row">
			<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Business name, ZIP, license…', 'advanced-ffl-checkout' ); ?>" class="wpistic-ffl-search-input">
			<input type="text" name="state" value="<?php echo esc_attr( $state_filter ); ?>" placeholder="State (e.g. TX)" maxlength="2" class="wpistic-ffl-state-input">
			<select name="type" class="wpistic-ffl-select">
				<option value=""><?php esc_html_e( 'All Types', 'advanced-ffl-checkout' ); ?></option>
				<?php foreach ( $lic_types as $code => $label ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $type_filter, $code ); ?>><?php echo esc_html( $code . ' — ' . $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button wpistic-ffl-filter-btn"><?php esc_html_e( 'Filter', 'advanced-ffl-checkout' ); ?></button>
			<?php if ( $search || $state_filter || $type_filter ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-dealers' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'advanced-ffl-checkout' ); ?></a>
			<?php endif; ?>
		</div>
	</form>

	<div class="wpistic-ffl-card wpistic-ffl-mt">
		<div class="wpistic-ffl-table-meta"><?php printf( esc_html__( '%d dealers found', 'advanced-ffl-checkout' ), $total ); ?></div>
		<table class="wpistic-ffl-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Business Name', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'License', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Type', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Address', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Portal Email', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Fee', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Recommended', 'advanced-ffl-checkout' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( $dealers ) : ?>
				<?php foreach ( $dealers as $d ) :
					$expiry_ts    = strtotime( $d->lic_xprdte );
					$expiry_warn  = $expiry_ts && $expiry_ts < strtotime( '+30 days' );
					$expiry_class = $expiry_warn ? ' wpistic-ffl-expiry-warn' : '';
				?>
				<tr class="<?php echo $d->is_preferred ? 'wpistic-ffl-preferred-row' : ''; ?>">
					<td>
						<?php if ( $d->is_preferred ) : ?><span class="wpistic-ffl-star" title="Preferred">⭐</span> <?php endif; ?>
						<strong><?php echo esc_html( $d->business_name ); ?></strong>
					</td>
					<td><code><?php echo esc_html( $d->license_number ); ?></code></td>
					<td><span class="wpistic-ffl-type-badge"><?php echo esc_html( $lic_types[ $d->lic_type ] ?? $d->lic_type ); ?></span></td>
					<td>
						<div><?php echo esc_html( $d->premise_street ); ?></div>
						<small><?php echo esc_html( $d->premise_city . ', ' . $d->premise_state . ' ' . $d->premise_zip ); ?></small>
					</td>
					<td><?php echo $d->phone ? esc_html( $d->phone ) : '—'; ?></td>
					<td>
						<input
							type="email"
							class="wpistic-ffl-dealer-email"
							data-dealer-id="<?php echo (int) $d->id; ?>"
							value="<?php echo esc_attr( (string) ( $d->email ?? '' ) ); ?>"
							placeholder="<?php esc_attr_e( 'dealer@example.com', 'advanced-ffl-checkout' ); ?>"
							style="width:200px;font-size:12px;"
						>
					</td>
					<td>
						<?php if ( $d->transfer_fee > 0 ) : ?>
						<span class="wpistic-ffl-fee-badge">$<?php echo esc_html( number_format( $d->transfer_fee, 2 ) ); ?></span>
						<?php else : ?>
						<span class="wpistic-ffl-fee-free">Free</span>
						<?php endif; ?>
					</td>
					<td class="<?php echo esc_attr( $expiry_class ); ?>"><?php echo $d->lic_xprdte ? esc_html( date_i18n( 'M j, Y', $expiry_ts ) ) : '—'; ?></td>
					<td>
						<?php if ( $d->is_active ) : ?>
						<span class="wpistic-ffl-status status-complete">Active</span>
						<?php else : ?>
						<span class="wpistic-ffl-status status-cancelled">Inactive</span>
						<?php endif; ?>
					</td>
					<td>
						<label class="wpistic-ffl-toggle" title="<?php esc_attr_e( 'Mark this dealer as Recommended (shows gold star + sorted to top on checkout)', 'advanced-ffl-checkout' ); ?>">
							<input type="checkbox" class="wpistic-ffl-preferred-toggle" data-dealer-id="<?php echo (int) $d->id; ?>" <?php checked( $d->is_preferred, 1 ); ?>>
							<span class="wpistic-ffl-toggle-slider">
								<span class="wpistic-ffl-toggle-star">⭐</span>
							</span>
						</label>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="10" class="wpistic-ffl-empty">
					<?php esc_html_e( 'No dealers found. Run the ATF sync from the Dashboard to import dealer data.', 'advanced-ffl-checkout' ); ?>
				</td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total > $per_page ) : ?>
		<div class="wpistic-ffl-pagination">
			<?php echo paginate_links( [
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $current_page,
				'total'   => (int) ceil( $total / $per_page ),
			] ); ?>
		</div>
		<?php endif; ?>
	</div>
</div>

<style>
.wpistic-ffl-toggle { display:inline-flex; align-items:center; cursor:pointer; user-select:none; }
.wpistic-ffl-toggle input { position:absolute; opacity:0; pointer-events:none; }
.wpistic-ffl-toggle-slider {
	position:relative; display:inline-flex; align-items:center; justify-content:flex-start;
	width:54px; height:26px; padding:2px; background:#d1d5db; border-radius:999px;
	transition:background 0.2s ease;
}
.wpistic-ffl-toggle-slider::before {
	content:""; position:absolute; top:3px; left:3px; width:20px; height:20px;
	background:#fff; border-radius:50%; box-shadow:0 1px 3px rgba(0,0,0,0.2);
	transition:transform 0.2s ease;
}
.wpistic-ffl-toggle-star { position:absolute; right:8px; font-size:12px; opacity:0; transition:opacity 0.2s ease; }
.wpistic-ffl-toggle input:checked + .wpistic-ffl-toggle-slider { background:linear-gradient(135deg, #FCD34D, #F59E0B); }
.wpistic-ffl-toggle input:checked + .wpistic-ffl-toggle-slider::before { transform:translateX(28px); }
.wpistic-ffl-toggle input:checked + .wpistic-ffl-toggle-slider .wpistic-ffl-toggle-star { opacity:0; }
.wpistic-ffl-toggle:hover .wpistic-ffl-toggle-slider { opacity:0.92; }
</style>

<script>
// v1.1.2 — defer until wp_localize_script has output the global (runs at end of body → DOMContentLoaded is safe)
document.addEventListener('DOMContentLoaded', function() {
	const globals = window.wpistic_ffl_admin || {};
	const nonce = globals.nonce || '';
	const ajax  = globals.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php';

	if (!nonce) {
		console.warn('[wpistic-ffl] Admin nonce not available — toggle will fail. Please reload the page.');
	}

	// v1.2.0 — inline edit for dealer portal email
	document.querySelectorAll('.wpistic-ffl-dealer-email').forEach(function(input) {
		const initial = input.value;
		input.addEventListener('blur', function() {
			if (input.value === initial) return;
			const dealerId = input.dataset.dealerId;
			input.disabled = true;
			const data = new FormData();
			data.append('action', 'wpistic_ffl_update_dealer_email');
			data.append('nonce', nonce);
			data.append('dealer_id', dealerId);
			data.append('email', input.value);
			fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(r => r.json())
				.then(json => {
					input.disabled = false;
					if (!json || !json.success) {
						alert((json && json.data && json.data.message) || 'Could not save email.');
						input.value = initial;
					} else {
						input.style.transition = 'background 0.6s ease';
						input.style.background = '#DCFCE7';
						setTimeout(function() { input.style.background = ''; }, 1200);
					}
				})
				.catch(function() {
					input.disabled = false;
					input.value = initial;
					alert('Network error.');
				});
		});
	});

	document.querySelectorAll('.wpistic-ffl-preferred-toggle').forEach(function(cb) {
		cb.addEventListener('change', function() {
			const dealerId = cb.dataset.dealerId;
			const value    = cb.checked ? 1 : 0;
			cb.disabled = true;
			const data = new FormData();
			data.append('action', 'wpistic_ffl_toggle_preferred');
			data.append('nonce', nonce);
			data.append('dealer_id', dealerId);
			data.append('value', String(value));
			fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(r => r.json())
				.then(json => {
					cb.disabled = false;
					if (!json || !json.success) {
						alert('Could not update. Please reload and try again.');
						cb.checked = !cb.checked;
					} else {
						// Visual feedback — briefly highlight the row
						const row = cb.closest('tr');
						if (row) {
							row.style.transition = 'background 0.6s ease';
							row.style.background = value ? '#FEF3C7' : '#F9FAFB';
							setTimeout(function() { row.style.background = ''; }, 1200);
						}
					}
				})
				.catch(function() {
					cb.disabled = false;
					cb.checked = !cb.checked;
					alert('Network error.');
				});
		});
	});
});
</script>
