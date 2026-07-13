<?php
/**
 * Transfer detail view.
 *
 * @package WpisticFFL
 */
defined( 'ABSPATH' ) || exit;

global $wpdb;

// Define table names (in case they're not available from parent scope)
$t_table = \WpisticFFL\DB::table( 'transfers' );
$d_table = \WpisticFFL\DB::table( 'dealers' );

// Keys MUST match TypeScript TransferStatus union in fflData.ts exactly.
$status_labels = [
	'pending_selection'  => [ 'label' => 'Awaiting Dealer',   'class' => 'status-new' ],
	'dealer_selected'    => [ 'label' => 'Dealer Selected',   'class' => 'status-new' ],
	'shipped_to_dealer'  => [ 'label' => 'Shipped to Dealer', 'class' => 'status-shipped' ],
	'received_by_dealer' => [ 'label' => 'At Dealer',         'class' => 'status-at-dealer' ],
	'background_check'   => [ 'label' => 'NICS Pending',      'class' => 'status-pending' ],
	'approved'           => [ 'label' => 'NICS Approved',     'class' => 'status-approved' ],
	'delayed'            => [ 'label' => 'NICS Delayed',      'class' => 'status-delayed' ],
	'denied'             => [ 'label' => 'NICS Denied',       'class' => 'status-denied' ],
	'transferred'        => [ 'label' => 'Transferred',       'class' => 'status-complete' ],
	'cancelled'          => [ 'label' => 'Cancelled',         'class' => 'status-cancelled' ],
];

// Handle status update
if ( isset( $_POST['update_status'] ) && check_admin_referer( 'wpistic_ffl_update_transfer' ) ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'You do not have permission to update transfer status.', 'advanced-ffl-checkout' ) . '</p></div>';
	} else {
		$new_status = sanitize_text_field( $_POST['status'] ?? '' );
		$notes      = sanitize_textarea_field( $_POST['notes'] ?? '' );

		if ( $new_status && array_key_exists( $new_status, $status_labels ) ) {
			$old_status = $transfer->status;

			// Update transfer status
			$result = $wpdb->update(
				$t_table,
				[ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ],
				[ 'id' => $transfer_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);

			if ( false === $result ) {
				// Database update failed
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to update transfer status. Database error.', 'advanced-ffl-checkout' ) . '</p></div>';
			} else {
				// Log the event
				$wpdb->insert(
					\WpisticFFL\DB::table( 'events' ),
					[
						'transfer_id' => $transfer_id,
						'event_type'  => 'status_change',
						'old_status'  => $old_status,
						'new_status'  => $new_status,
						'notes'       => $notes,
						'actor'       => wp_get_current_user()->user_login,
						'actor_ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
					],
					[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
				);

				// Send email notifications
				\WpisticFFL\Mailer::send_status_update( $transfer_id, $new_status );

				// Refresh data
				$transfer = $wpdb->get_row( $wpdb->prepare(
					"SELECT t.*, d.business_name AS dealer_name, d.license_number AS dealer_license,
					        d.premise_street AS dealer_street, d.premise_city AS dealer_city,
					        d.premise_state AS dealer_state, d.premise_zip AS dealer_zip, d.phone AS dealer_phone
					 FROM {$t_table} t LEFT JOIN {$d_table} d ON d.id = t.dealer_id WHERE t.id = %d",
					$transfer_id
				) );
				$events = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM " . \WpisticFFL\DB::table( 'events' ) . " WHERE transfer_id = %d ORDER BY created_at ASC",
					$transfer_id
				) );

				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Transfer status updated successfully.', 'advanced-ffl-checkout' ) . '</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid status selected.', 'advanced-ffl-checkout' ) . '</p></div>';
		}
	}
}
?>

<div class="wrap wpistic-ffl-admin">
	<div class="wpistic-ffl-page-header">
		<h1 class="wpistic-ffl-page-title">
			<?php esc_html_e( 'G2A - Transfer Details', 'advanced-ffl-checkout' ); ?>
			<span class="wpistic-ffl-transfer-ref">#<?php echo esc_html( $transfer->transfer_ref ); ?></span>
		</h1>
		<span style="display:flex;gap:8px;">
			<?php if ( $transfer ) : ?>
				<a href="<?php echo esc_url( home_url( '/ffl-4473-draft/' . (int) $transfer->id . '/' ) ); ?>" class="button" target="_blank">📄 <?php esc_html_e( '4473 Draft', 'advanced-ffl-checkout' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/ffl-4473-draft/' . (int) $transfer->id . '/pdf/' ) ); ?>" class="button" target="_blank">⬇ <?php esc_html_e( '4473 PDF', 'advanced-ffl-checkout' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers' ) ); ?>" class="button">&larr; <?php esc_html_e( 'Back to Transfers', 'advanced-ffl-checkout' ); ?></a>
		</span>
	</div>

	<?php if ( ! $transfer ) : ?>
		<div class="wpistic-ffl-card">
			<p><?php esc_html_e( 'Transfer not found.', 'advanced-ffl-checkout' ); ?></p>
		</div>
	<?php else : ?>

	<!-- Transfer Status -->
	<div class="wpistic-ffl-card">
		<h3><?php esc_html_e( 'Status Update', 'advanced-ffl-checkout' ); ?></h3>
		<form method="post" action="<?php echo esc_url( add_query_arg( $_GET ) ); ?>">
			<?php wp_nonce_field( 'wpistic_ffl_update_transfer' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Current Status', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<?php $s = $status_labels[ $transfer->status ] ?? [ 'label' => $transfer->status, 'class' => 'status-new' ]; ?>
						<span class="wpistic-ffl-status <?php echo esc_attr( $s['class'] ); ?>"><?php echo esc_html( $s['label'] ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Update Status', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<select name="status" id="status" required>
							<option value=""><?php esc_html_e( 'Select new status...', 'advanced-ffl-checkout' ); ?></option>
							<?php foreach ( $status_labels as $key => $info ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $info['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Notes', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<textarea name="notes" rows="3" placeholder="<?php esc_attr_e( 'Optional notes about this status change...', 'advanced-ffl-checkout' ); ?>"></textarea>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="update_status" class="button button-primary" value="<?php esc_attr_e( 'Update Status', 'advanced-ffl-checkout' ); ?>">
			</p>
		</form>
	</div>

	<div class="wpistic-ffl-grid">
		<!-- Transfer Information -->
		<div class="wpistic-ffl-card">
			<h3><?php esc_html_e( 'Transfer Information', 'advanced-ffl-checkout' ); ?></h3>
			<table class="wpistic-ffl-details-table">
				<tr>
					<th><?php esc_html_e( 'Transfer Reference', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( $transfer->transfer_ref ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Order ID', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<?php if ( $transfer->order_id ) : ?>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $transfer->order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $transfer->order_id ); ?></a>
						<?php else : ?>—<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Created', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( date_i18n( 'M j, Y g:i A', strtotime( $transfer->created_at ) ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last Updated', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( date_i18n( 'M j, Y g:i A', strtotime( $transfer->updated_at ) ) ); ?></td>
				</tr>
			</table>
		</div>

		<!-- Customer Information -->
		<div class="wpistic-ffl-card">
			<h3><?php esc_html_e( 'Customer Information', 'advanced-ffl-checkout' ); ?></h3>
			<table class="wpistic-ffl-details-table">
				<tr>
					<th><?php esc_html_e( 'Name', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( $transfer->customer_name ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Email', 'advanced-ffl-checkout' ); ?></th>
					<td><a href="mailto:<?php echo esc_attr( $transfer->customer_email ); ?>"><?php echo esc_html( $transfer->customer_email ); ?></a></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Phone', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( $transfer->customer_phone ?: '—' ); ?></td>
				</tr>
			</table>
		</div>
	</div>

	<div class="wpistic-ffl-grid">
		<!-- Item Information -->
		<div class="wpistic-ffl-card">
			<h3><?php esc_html_e( 'Item Information', 'advanced-ffl-checkout' ); ?></h3>
			<table class="wpistic-ffl-details-table">
				<tr>
					<th><?php esc_html_e( 'Description', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( $transfer->item_description ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'SKU', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( $transfer->item_sku ?: '—' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Serial Number', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( $transfer->item_serial ?: '—' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Make/Model', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( trim( $transfer->item_make . ' ' . $transfer->item_model ) ?: '—' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Caliber/Type', 'advanced-ffl-checkout' ); ?></th>
					<td><?php echo esc_html( trim( $transfer->item_caliber . ' ' . $transfer->item_type ) ?: '—' ); ?></td>
				</tr>
			</table>
		</div>

		<!-- Dealer Information -->
		<div class="wpistic-ffl-card">
			<h3><?php esc_html_e( 'Dealer Information', 'advanced-ffl-checkout' ); ?></h3>
			<?php if ( $transfer->dealer_name ) : ?>
				<table class="wpistic-ffl-details-table">
					<tr>
						<th><?php esc_html_e( 'Business Name', 'advanced-ffl-checkout' ); ?></th>
						<td><?php echo esc_html( $transfer->dealer_name ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'License Number', 'advanced-ffl-checkout' ); ?></th>
						<td><?php echo esc_html( $transfer->dealer_license ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Address', 'advanced-ffl-checkout' ); ?></th>
						<td>
							<?php echo esc_html( $transfer->dealer_street ); ?><br>
							<?php echo esc_html( $transfer->dealer_city . ', ' . $transfer->dealer_state . ' ' . $transfer->dealer_zip ); ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Phone', 'advanced-ffl-checkout' ); ?></th>
						<td><?php echo esc_html( $transfer->dealer_phone ?: '—' ); ?></td>
					</tr>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No dealer assigned.', 'advanced-ffl-checkout' ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<!-- Additional Details -->
	<div class="wpistic-ffl-card">
		<h3><?php esc_html_e( 'Additional Details', 'advanced-ffl-checkout' ); ?></h3>
		<table class="wpistic-ffl-details-table">
			<tr>
				<th><?php esc_html_e( 'Shipment Carrier', 'advanced-ffl-checkout' ); ?></th>
				<td><?php echo esc_html( $transfer->shipment_carrier ?: '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tracking Number', 'advanced-ffl-checkout' ); ?></th>
				<td><?php echo esc_html( $transfer->shipment_tracking ?: '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Shipped Date', 'advanced-ffl-checkout' ); ?></th>
				<td><?php echo esc_html( $transfer->shipped_date ? date_i18n( 'M j, Y', strtotime( $transfer->shipped_date ) ) : '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Dealer Received Date', 'advanced-ffl-checkout' ); ?></th>
				<td><?php echo esc_html( $transfer->dealer_received_date ? date_i18n( 'M j, Y', strtotime( $transfer->dealer_received_date ) ) : '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'NICS Transaction #', 'advanced-ffl-checkout' ); ?></th>
				<td><?php echo esc_html( $transfer->nics_transaction_number ?: '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'NICS Check Date', 'advanced-ffl-checkout' ); ?></th>
				<td><?php echo esc_html( $transfer->nics_check_date ? date_i18n( 'M j, Y', strtotime( $transfer->nics_check_date ) ) : '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Transfer Date', 'advanced-ffl-checkout' ); ?></th>
				<td><?php echo esc_html( $transfer->transfer_date ? date_i18n( 'M j, Y', strtotime( $transfer->transfer_date ) ) : '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Form 4473 Reference', 'advanced-ffl-checkout' ); ?></th>
				<td><?php echo esc_html( $transfer->form_4473_ref ?: '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Staff Notes', 'advanced-ffl-checkout' ); ?></th>
				<td><?php echo wp_kses_post( wpautop( $transfer->staff_notes ?: '—' ) ); ?></td>
			</tr>
		</table>
	</div>

	<!-- Event Log -->
	<div class="wpistic-ffl-card">
		<h3><?php esc_html_e( 'Event Log', 'advanced-ffl-checkout' ); ?></h3>
		<?php if ( $events ) : ?>
			<table class="wpistic-ffl-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date/Time', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Event', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Actor', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Details', 'advanced-ffl-checkout' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( 'M j, Y g:i A', strtotime( $event->created_at ) ) ); ?></td>
						<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $event->event_type ) ) ); ?></td>
						<td><?php echo esc_html( $event->actor ); ?></td>
						<td>
							<?php if ( $event->old_status && $event->new_status ) : ?>
								<?php echo esc_html( $status_labels[ $event->old_status ]['label'] ?? $event->old_status ); ?> &rarr; <?php echo esc_html( $status_labels[ $event->new_status ]['label'] ?? $event->new_status ); ?>
							<?php endif; ?>
							<?php if ( $event->notes ) : ?>
								<br><small style="color:#9ca3af;"><?php echo esc_html( $event->notes ); ?></small>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No events logged yet.', 'advanced-ffl-checkout' ); ?></p>
		<?php endif; ?>
	</div>

	<?php endif; ?>
</div>