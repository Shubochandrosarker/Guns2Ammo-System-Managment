<?php
/**
 * Migration Admin — wizard UI + AJAX endpoints.
 *
 * Page: admin.php?page=g2ab-migration[&step=1..5][&run=ID]
 *
 * @package G2AB\Modules\Migration
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Migration_Admin {

	const PAGE_SLUG = 'g2ab-migration';
	const NONCE     = 'g2ab_migration_nonce';
	const CAP       = 'manage_options';

	private $module;

	public function __construct( G2AB_Module_Migration $module ) {
		$this->module = $module;
		add_action( 'admin_menu',            array( $this, 'register_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_g2ab_migration_upload_csv', array( $this, 'handle_csv_upload' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_g2ab_migration_start',     array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_g2ab_migration_status',    array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_g2ab_migration_rollback',  array( $this, 'ajax_rollback' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'g2ab-bookings',
			__( 'Migration', 'g2a-booking' ),
			__( 'Migration', 'g2a-booking' ),
			self::CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( (string) $hook, self::PAGE_SLUG ) === false ) return;

		$asset_url = G2AB_URL . 'includes/modules/migration/assets/';
		wp_enqueue_style(  'g2ab-migration', $asset_url . 'migration.css', array(), G2AB_VERSION );
		wp_enqueue_script( 'g2ab-migration', $asset_url . 'migration.js',  array(), G2AB_VERSION, true );
		wp_localize_script( 'g2ab-migration', 'G2ABMigration', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'pageUrl'  => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
			'i18n'     => array(
				'starting'    => __( 'Starting migration…',  'g2a-booking' ),
				'running'     => __( 'Migrating bookings…', 'g2a-booking' ),
				'completed'   => __( 'Migration complete.', 'g2a-booking' ),
				'failed'      => __( 'Migration failed.',   'g2a-booking' ),
				'rollbackOk'  => __( 'Rollback complete.',  'g2a-booking' ),
				'confirmRollback' => __( 'This will permanently delete all records imported by this run. Continue?', 'g2a-booking' ),
			),
		) );
	}

	// =================================================================
	// Page dispatcher
	// =================================================================

	public function render() {
		if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );

		$step = isset( $_GET['step'] ) ? (int) $_GET['step'] : 1;
		if ( $step < 1 || $step > 5 ) $step = 1;

		echo '<div class="wrap g2ab-mig-wrap">';
		$this->render_header( $step );

		switch ( $step ) {
			case 1: $this->render_step_detect();   break;
			case 2: $this->render_step_mapping();  break;
			case 3: $this->render_step_preview();  break;
			case 4: $this->render_step_progress(); break;
			case 5: $this->render_step_complete(); break;
		}

		$this->render_recent_runs();

		echo '</div>';
	}

	private function render_header( $current_step ) {
		$steps = array(
			1 => __( 'Detect',   'g2a-booking' ),
			2 => __( 'Map',      'g2a-booking' ),
			3 => __( 'Preview',  'g2a-booking' ),
			4 => __( 'Migrate',  'g2a-booking' ),
			5 => __( 'Done',     'g2a-booking' ),
		);
		?>
		<div class="g2ab-mig-header">
			<h1><span class="g2ab-mig-icon">↔</span> <?php esc_html_e( 'Migration Tool', 'g2a-booking' ); ?></h1>
			<p class="g2ab-mig-subtitle"><?php esc_html_e( 'Bring bookings in from Amelia or CSV. Bookly and BookingPress are queued next. Idempotent. Reversible. Production-safe.', 'g2a-booking' ); ?></p>

			<div class="g2ab-mig-stepper" role="navigation">
				<?php foreach ( $steps as $n => $label ) :
					$state = $n < $current_step ? 'done' : ( $n === $current_step ? 'active' : 'todo' );
				?>
					<div class="g2ab-mig-step g2ab-mig-step--<?php echo esc_attr( $state ); ?>">
						<span class="g2ab-mig-step-num"><?php echo $n < $current_step ? '✓' : esc_html( $n ); ?></span>
						<span class="g2ab-mig-step-label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	// =================================================================
	// Step 1 — Detect
	// =================================================================

	private function render_step_detect() {
		$adapters = $this->module->adapters();
		$available = $this->module->available_adapters();

		echo '<div class="g2ab-mig-card">';
		echo '<h2>' . esc_html__( 'Choose a source', 'g2a-booking' ) . '</h2>';
		echo '<p class="g2ab-mig-help">' . esc_html__( 'We scan your database for installed booking plugins. Pick one to start the wizard.', 'g2a-booking' ) . '</p>';

		echo '<div class="g2ab-mig-source-grid">';
		foreach ( $adapters as $id => $a ) {
			$is_avail = isset( $available[ $id ] );
			$counts = $is_avail ? $a->get_record_counts() : array();
			$bookings = (int) ( $counts['bookings'] ?? 0 );
			$class = $is_avail ? 'g2ab-mig-source g2ab-mig-source--available' : 'g2ab-mig-source g2ab-mig-source--unavailable';
			?>
			<div class="<?php echo esc_attr( $class ); ?>" data-adapter="<?php echo esc_attr( $id ); ?>">
				<div class="g2ab-mig-source-icon" style="background:<?php echo esc_attr( $a->color() ); ?>"><?php echo esc_html( $a->icon() ); ?></div>
				<div class="g2ab-mig-source-body">
					<h3><?php echo esc_html( $a->label() ); ?></h3>
					<p><?php echo esc_html( $a->description() ); ?></p>
					<?php if ( $is_avail ) : ?>
						<?php if ( $id === 'csv' ) : ?>
							<div class="g2ab-mig-source-stats"><?php esc_html_e( 'Always available — upload a CSV file.', 'g2a-booking' ); ?></div>
						<?php else : ?>
							<div class="g2ab-mig-source-stats">
								<span><strong><?php echo esc_html( number_format_i18n( $bookings ) ); ?></strong> <?php esc_html_e( 'bookings', 'g2a-booking' ); ?></span>
								<span><strong><?php echo esc_html( number_format_i18n( (int) ( $counts['booking_types'] ?? 0 ) ) ); ?></strong> <?php esc_html_e( 'services', 'g2a-booking' ); ?></span>
								<span><strong><?php echo esc_html( number_format_i18n( (int) ( $counts['resources'] ?? 0 ) ) ); ?></strong> <?php esc_html_e( 'staff/lanes', 'g2a-booking' ); ?></span>
							</div>
						<?php endif; ?>
					<?php else : ?>
						<div class="g2ab-mig-source-stats g2ab-mig-source-stats--muted"><?php esc_html_e( 'Not detected on this site.', 'g2a-booking' ); ?></div>
					<?php endif; ?>
				</div>
				<?php if ( $is_avail ) : ?>
					<?php if ( $id === 'csv' ) : ?>
						<a class="button button-primary g2ab-mig-source-btn" href="#g2ab-csv-upload"><?php esc_html_e( 'Upload CSV', 'g2a-booking' ); ?></a>
					<?php else : ?>
						<a class="button button-primary g2ab-mig-source-btn"
						   href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 2, 'adapter' => $id ), admin_url( 'admin.php' ) ) ); ?>">
							<?php esc_html_e( 'Continue →', 'g2a-booking' ); ?>
						</a>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php
		}
		foreach ( $this->coming_soon_sources() as $id => $source ) {
			?>
			<div class="g2ab-mig-source g2ab-mig-source--unavailable g2ab-mig-source--soon" data-adapter="<?php echo esc_attr( $id ); ?>">
				<div class="g2ab-mig-source-icon" style="background:<?php echo esc_attr( $source['color'] ); ?>"><?php echo esc_html( $source['icon'] ); ?></div>
				<div class="g2ab-mig-source-body">
					<h3><?php echo esc_html( $source['label'] ); ?></h3>
					<p><?php echo esc_html( $source['description'] ); ?></p>
					<div class="g2ab-mig-source-stats g2ab-mig-source-stats--muted"><?php esc_html_e( 'Coming soon. Amelia and CSV are available now.', 'g2a-booking' ); ?></div>
				</div>
				<span class="button g2ab-mig-source-btn disabled" aria-disabled="true"><?php esc_html_e( 'Coming Soon', 'g2a-booking' ); ?></span>
			</div>
			<?php
		}
		echo '</div>'; // grid

		// CSV upload form.
		?>
		<div id="g2ab-csv-upload" class="g2ab-mig-csv-upload">
			<h3><?php esc_html_e( 'Upload CSV', 'g2a-booking' ); ?></h3>
			<p><?php esc_html_e( 'Required columns: external_id, customer_name, customer_email, start_at, end_at, status, total_amount.', 'g2a-booking' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'g2ab_migration_upload_csv', 'g2ab_csv_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_migration_upload_csv">
				<input type="file" name="g2ab_csv" accept=".csv" required>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload & Continue', 'g2a-booking' ); ?></button>
			</form>
		</div>
		<?php
		echo '</div>'; // card
	}

	private function coming_soon_sources() {
		return array(
			'bookly' => array(
				'label'       => __( 'Bookly', 'g2a-booking' ),
				'description' => __( 'Bookly migration is planned after the Amelia importer is fully hardened.', 'g2a-booking' ),
				'icon'        => 'B',
				'color'       => '#6C2BD9',
			),
			'bookingpress' => array(
				'label'       => __( 'BookingPress', 'g2a-booking' ),
				'description' => __( 'BookingPress migration is planned for the next adapter pass.', 'g2a-booking' ),
				'icon'        => 'P',
				'color'       => '#F59E0B',
			),
		);
	}

	// =================================================================
	// Step 2 — Mapping
	// =================================================================

	private function render_step_mapping() {
		$adapter_id = isset( $_GET['adapter'] ) ? sanitize_key( wp_unslash( $_GET['adapter'] ) ) : '';
		$adapter = $this->module->adapter( $adapter_id );
		if ( ! $adapter ) {
			echo '<div class="g2ab-mig-card"><p>' . esc_html__( 'Unknown source. Go back to step 1.', 'g2a-booking' ) . '</p></div>';
			return;
		}

		$counts   = $adapter->get_record_counts();
		$warnings = $adapter->preflight_warnings();

		echo '<div class="g2ab-mig-card">';
		echo '<h2>' . sprintf( esc_html__( 'Review mapping — %s', 'g2a-booking' ), esc_html( $adapter->label() ) ) . '</h2>';

		// Counts summary
		echo '<div class="g2ab-mig-counts">';
		foreach ( array(
			'bookings'      => __( 'Bookings', 'g2a-booking' ),
			'booking_types' => __( 'Services', 'g2a-booking' ),
			'resources'     => __( 'Staff / Lanes', 'g2a-booking' ),
			'payments'      => __( 'Payments', 'g2a-booking' ),
		) as $key => $label ) {
			$n = (int) ( $counts[ $key ] ?? 0 );
			echo '<div class="g2ab-mig-count">';
			echo '<div class="g2ab-mig-count-num">' . esc_html( number_format_i18n( $n ) ) . '</div>';
			echo '<div class="g2ab-mig-count-label">' . esc_html( $label ) . '</div>';
			echo '</div>';
		}
		echo '</div>';

		// Warnings
		if ( $warnings ) {
			echo '<div class="g2ab-mig-warnings"><h3>' . esc_html__( 'Preflight notes', 'g2a-booking' ) . '</h3><ul>';
			foreach ( $warnings as $w ) echo '<li>' . esc_html( $w ) . '</li>';
			echo '</ul></div>';
		}

		// Status mapping (read-only display — uses adapter's hardcoded map)
		echo '<h3>' . esc_html__( 'Status mapping', 'g2a-booking' ) . '</h3>';
		echo '<p class="g2ab-mig-help">' . esc_html__( 'Each adapter applies a sensible default mapping. Statuses not listed below default to "pending".', 'g2a-booking' ) . '</p>';
		echo '<table class="g2ab-mig-table"><thead><tr><th>' . esc_html__( 'Source status', 'g2a-booking' ) . '</th><th>' . esc_html__( '→ G2AB status', 'g2a-booking' ) . '</th></tr></thead><tbody>';
		foreach ( array( 'approved', 'pending', 'cancelled', 'rejected', 'no_show', 'completed' ) as $src ) {
			echo '<tr><td><code>' . esc_html( $src ) . '</code></td><td><strong>' . esc_html( $adapter->map_status( $src ) ) . '</strong></td></tr>';
		}
		echo '</tbody></table>';

		// CTA
		echo '<div class="g2ab-mig-actions">';
		echo '<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '" class="button">← ' . esc_html__( 'Back', 'g2a-booking' ) . '</a>';
		echo '<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 3, 'adapter' => $adapter_id ), admin_url( 'admin.php' ) ) ) . '" class="button button-primary">' . esc_html__( 'Run dry-run preview →', 'g2a-booking' ) . '</a>';
		echo '</div>';

		echo '</div>'; // card
	}

	// =================================================================
	// Step 3 — Preview (dry-run)
	// =================================================================

	private function render_step_preview() {
		$adapter_id = isset( $_GET['adapter'] ) ? sanitize_key( wp_unslash( $_GET['adapter'] ) ) : '';
		?>
		<div class="g2ab-mig-card" id="g2ab-mig-preview" data-adapter="<?php echo esc_attr( $adapter_id ); ?>">
			<h2><?php esc_html_e( 'Dry run preview', 'g2a-booking' ); ?></h2>
			<p class="g2ab-mig-help"><?php esc_html_e( 'A dry run validates every record but writes nothing to disk. Recommended before live migration.', 'g2a-booking' ); ?></p>

			<div class="g2ab-mig-preview-status">
				<button class="button button-primary" id="g2ab-mig-start-dry"><?php esc_html_e( 'Start dry run', 'g2a-booking' ); ?></button>
				<span class="g2ab-mig-status-line"></span>
			</div>

			<div class="g2ab-mig-progress" id="g2ab-mig-preview-progress" style="display:none;">
				<div class="g2ab-mig-progress-bar"><div class="g2ab-mig-progress-fill" style="width:0%"></div></div>
				<div class="g2ab-mig-progress-stats">
					<span class="g2ab-mig-stat"><strong>0</strong> <?php esc_html_e( 'imported', 'g2a-booking' ); ?></span>
					<span class="g2ab-mig-stat"><strong>0</strong> <?php esc_html_e( 'skipped', 'g2a-booking' ); ?></span>
					<span class="g2ab-mig-stat"><strong>0</strong> <?php esc_html_e( 'failed', 'g2a-booking' ); ?></span>
				</div>
			</div>

			<div class="g2ab-mig-actions" id="g2ab-mig-preview-cta" style="display:none;">
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 2, 'adapter' => $adapter_id ), admin_url( 'admin.php' ) ) ); ?>" class="button">← <?php esc_html_e( 'Back', 'g2a-booking' ); ?></a>
				<button class="button button-primary" id="g2ab-mig-start-live"><?php esc_html_e( 'Looks good — run live migration →', 'g2a-booking' ); ?></button>
			</div>
		</div>
		<?php
	}

	// =================================================================
	// Step 4 — Live progress
	// =================================================================

	private function render_step_progress() {
		$run_id = isset( $_GET['run'] ) ? (int) $_GET['run'] : 0;
		?>
		<div class="g2ab-mig-card" id="g2ab-mig-live" data-run="<?php echo esc_attr( $run_id ); ?>">
			<h2><?php esc_html_e( 'Migrating…', 'g2a-booking' ); ?></h2>
			<p class="g2ab-mig-help"><?php esc_html_e( 'You can leave this page open or close it — migration continues in the background.', 'g2a-booking' ); ?></p>

			<div class="g2ab-mig-progress">
				<div class="g2ab-mig-progress-bar"><div class="g2ab-mig-progress-fill" style="width:0%"></div></div>
				<div class="g2ab-mig-progress-stats">
					<span class="g2ab-mig-stat"><strong>0</strong> <?php esc_html_e( 'imported', 'g2a-booking' ); ?></span>
					<span class="g2ab-mig-stat"><strong>0</strong> <?php esc_html_e( 'skipped', 'g2a-booking' ); ?></span>
					<span class="g2ab-mig-stat"><strong>0</strong> <?php esc_html_e( 'failed', 'g2a-booking' ); ?></span>
				</div>
				<div class="g2ab-mig-status-line"></div>
			</div>

			<details class="g2ab-mig-log-wrap">
				<summary><?php esc_html_e( 'Live log', 'g2a-booking' ); ?></summary>
				<pre class="g2ab-mig-log"></pre>
			</details>
		</div>
		<?php
	}

	// =================================================================
	// Step 5 — Complete
	// =================================================================

	private function render_step_complete() {
		$run_id = isset( $_GET['run'] ) ? (int) $_GET['run'] : 0;
		$run = $this->module->runner()->get_run( $run_id );
		if ( ! $run ) {
			echo '<div class="g2ab-mig-card"><p>' . esc_html__( 'Run not found.', 'g2a-booking' ) . '</p></div>';
			return;
		}
		$status = $run['status'];
		$badge = array(
			'completed'   => array( 'Migration complete', '#1B5E20' ),
			'failed'      => array( 'Migration failed',   '#C62828' ),
			'rolled_back' => array( 'Rolled back',        '#666666' ),
		);
		$b = $badge[ $status ] ?? array( ucfirst( $status ), '#888' );
		?>
		<div class="g2ab-mig-card g2ab-mig-complete">
			<div class="g2ab-mig-complete-badge" style="background:<?php echo esc_attr( $b[1] ); ?>">
				<?php echo esc_html( $b[0] ); ?>
			</div>

			<h2><?php echo esc_html( ucfirst( $run['adapter'] ) ); ?> → G2A Booking Engine</h2>

			<div class="g2ab-mig-counts">
				<div class="g2ab-mig-count"><div class="g2ab-mig-count-num"><?php echo esc_html( number_format_i18n( (int) $run['processed_records'] ) ); ?></div><div class="g2ab-mig-count-label"><?php esc_html_e( 'Imported', 'g2a-booking' ); ?></div></div>
				<div class="g2ab-mig-count"><div class="g2ab-mig-count-num"><?php echo esc_html( number_format_i18n( (int) $run['skipped_records'] ) ); ?></div><div class="g2ab-mig-count-label"><?php esc_html_e( 'Skipped (already imported)', 'g2a-booking' ); ?></div></div>
				<div class="g2ab-mig-count"><div class="g2ab-mig-count-num"><?php echo esc_html( number_format_i18n( (int) $run['failed_records'] ) ); ?></div><div class="g2ab-mig-count-label"><?php esc_html_e( 'Failed', 'g2a-booking' ); ?></div></div>
				<div class="g2ab-mig-count"><div class="g2ab-mig-count-num"><?php echo esc_html( number_format_i18n( (int) $run['total_records'] ) ); ?></div><div class="g2ab-mig-count-label"><?php esc_html_e( 'Total', 'g2a-booking' ); ?></div></div>
			</div>

			<?php if ( ! empty( $run['error_summary'] ) ) : ?>
				<div class="g2ab-mig-warnings"><h3><?php esc_html_e( 'Notes', 'g2a-booking' ); ?></h3><pre><?php echo esc_html( $run['error_summary'] ); ?></pre></div>
			<?php endif; ?>

			<details class="g2ab-mig-log-wrap">
				<summary><?php esc_html_e( 'Full log', 'g2a-booking' ); ?></summary>
				<pre class="g2ab-mig-log"><?php echo esc_html( $run['log'] ?? '' ); ?></pre>
			</details>

			<div class="g2ab-mig-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-bookings-list' ) ); ?>" class="button button-primary"><?php esc_html_e( 'View bookings →', 'g2a-booking' ); ?></a>
				<?php if ( $status !== 'rolled_back' && (int) $run['processed_records'] > 0 ) : ?>
					<button class="button g2ab-mig-rollback-btn" data-run="<?php echo esc_attr( $run_id ); ?>"><?php esc_html_e( 'Rollback this run', 'g2a-booking' ); ?></button>
				<?php endif; ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Run another migration', 'g2a-booking' ); ?></a>
			</div>
		</div>
		<?php
	}

	// =================================================================
	// Recent runs table (always visible at bottom)
	// =================================================================

	private function render_recent_runs() {
		$runs = $this->module->runner()->list_recent_runs( 10 );
		if ( ! $runs ) return;
		?>
		<div class="g2ab-mig-card g2ab-mig-history">
			<h2><?php esc_html_e( 'Recent runs', 'g2a-booking' ); ?></h2>
			<table class="g2ab-mig-table">
				<thead>
					<tr>
						<th>#</th>
						<th><?php esc_html_e( 'Adapter', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Mode', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Imported', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Started', 'g2a-booking' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $runs as $r ) : ?>
					<tr>
						<td>#<?php echo esc_html( $r['id'] ); ?></td>
						<td><?php echo esc_html( ucfirst( $r['adapter'] ) ); ?></td>
						<td><?php echo esc_html( $r['mode'] ); ?></td>
						<td><span class="g2ab-mig-badge g2ab-mig-badge--<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r['processed_records'] ) ); ?> / <?php echo esc_html( number_format_i18n( (int) $r['total_records'] ) ); ?></td>
						<td><?php echo esc_html( $r['started_at'] ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 5, 'run' => $r['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View', 'g2a-booking' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// =================================================================
	// CSV upload handler
	// =================================================================

	public function handle_csv_upload() {
		if ( ! current_user_can( self::CAP ) ) wp_die( 'No perm.' );
		if ( ! isset( $_POST['g2ab_csv_nonce'] ) || ! wp_verify_nonce( $_POST['g2ab_csv_nonce'], 'g2ab_migration_upload_csv' ) ) wp_die( 'Bad nonce.' );

		if ( empty( $_FILES['g2ab_csv'] ) || ! is_uploaded_file( $_FILES['g2ab_csv']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1, 'csv_error' => 'noupload' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Move into uploads with unique filename.
		$upload = wp_upload_dir();
		$dir = trailingslashit( $upload['basedir'] ) . 'g2ab-migration/';
		if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
		$dest = $dir . 'csv-' . wp_generate_password( 8, false ) . '.csv';
		if ( ! move_uploaded_file( $_FILES['g2ab_csv']['tmp_name'], $dest ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1, 'csv_error' => 'movefail' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Stash path in transient for next 30 min so step 2 can pick it up.
		set_transient( 'g2ab_mig_csv_path_' . get_current_user_id(), $dest, 30 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 2, 'adapter' => 'csv' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// =================================================================
	// AJAX handlers
	// =================================================================

	public function ajax_start() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) wp_send_json_error( array( 'message' => 'No perm.' ), 403 );

		$adapter_id = isset( $_POST['adapter'] ) ? sanitize_key( wp_unslash( $_POST['adapter'] ) ) : '';
		$mode       = isset( $_POST['mode'] )    ? sanitize_key( wp_unslash( $_POST['mode'] ) )    : 'live';

		$options = array();
		if ( $adapter_id === 'csv' ) {
			$path = get_transient( 'g2ab_mig_csv_path_' . get_current_user_id() );
			if ( ! $path ) wp_send_json_error( array( 'message' => 'CSV upload missing or expired. Re-upload.' ), 400 );
			$options['csv_path'] = $path;
		}

		$run_id = $this->module->runner()->start_run( $adapter_id, $mode, $options );
		if ( is_wp_error( $run_id ) ) {
			wp_send_json_error( array( 'message' => $run_id->get_error_message() ), 400 );
		}

		// Run first batch synchronously so dry-runs feel instant on small datasets.
		$this->module->runner()->process_batch( array( 'run_id' => $run_id, 'offset' => 0 ) );

		wp_send_json_success( array( 'run_id' => $run_id ) );
	}

	public function ajax_status() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) wp_send_json_error( array( 'message' => 'No perm.' ), 403 );

		$run_id = isset( $_POST['run_id'] ) ? (int) $_POST['run_id'] : 0;
		$this->module->runner()->maybe_process_next_batch( $run_id );
		$run = $this->module->runner()->get_run( $run_id );
		if ( ! $run ) wp_send_json_error( array( 'message' => 'Not found' ), 404 );

		$progress = (int) $run['total_records'] > 0
			? min( 100, (int) round( ( ( (int) $run['processed_records'] + (int) $run['skipped_records'] + (int) $run['failed_records'] ) / max( 1, (int) $run['total_records'] ) ) * 100 ) )
			: ( in_array( $run['status'], array( 'completed', 'failed' ), true ) ? 100 : 0 );

		// Limit log tail to last 8KB.
		$log = (string) ( $run['log'] ?? '' );
		if ( strlen( $log ) > 8192 ) $log = "...\n" . substr( $log, -7000 );

		wp_send_json_success( array(
			'status'    => $run['status'],
			'progress'  => $progress,
			'imported'  => (int) $run['processed_records'],
			'skipped'   => (int) $run['skipped_records'],
			'failed'    => (int) $run['failed_records'],
			'total'     => (int) $run['total_records'],
			'log'       => $log,
			'completed' => in_array( $run['status'], array( 'completed', 'failed', 'rolled_back' ), true ),
		) );
	}

	public function ajax_rollback() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) wp_send_json_error( array( 'message' => 'No perm.' ), 403 );

		$run_id = isset( $_POST['run_id'] ) ? (int) $_POST['run_id'] : 0;
		$result = $this->module->runner()->rollback_run( $run_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'deleted' => $result ) );
	}
}
