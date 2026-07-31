<?php
/**
 * Admin: Events CRUD.
 *
 * Create scheduled, seat-limited events (Ladies Night, CCW classes,
 * competitions) — free or paid, single price each — and manage their
 * occurrences (specific dates/times with their own seat counts). Mirrors
 * the Amelia "events" model the user asked for.
 *
 * Uses the shared G2AB_Events repository for all data access, and posts
 * to admin-post.php handlers (nonce + capability gated). Self-registers
 * an "Events" submenu under G2A Booking.
 *
 * @package G2AB
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Events {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-events';
	const CAP       = 'manage_g2ab_settings';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_post_g2ab_save_event', array( $this, 'handle_save_event' ) );
		add_action( 'admin_post_g2ab_delete_event', array( $this, 'handle_delete_event' ) );
		add_action( 'admin_post_g2ab_add_occurrence', array( $this, 'handle_add_occurrence' ) );
		add_action( 'admin_post_g2ab_delete_occurrence', array( $this, 'handle_delete_occurrence' ) );
		add_action( 'admin_post_g2ab_recalculate_occurrence_capacity', array( $this, 'handle_recalculate_occurrence_capacity' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'g2ab-bookings',
			__( 'Events', 'g2a-booking' ),
			__( 'Events', 'g2a-booking' ),
			self::CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		$this->print_styles();
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$this->render_form( $action );
		} else {
			$this->render_list();
		}
	}

	/* ───────────────────────── List ───────────────────────── */

	private function render_list() {
		$events = G2AB_Events::get_events( array( 'status' => 'any', 'limit' => 200 ) );
		$notice = $this->get_notice();
		$add_url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'add' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap g2ab-admin g2ab-ev">
			<div class="g2ab-ev__header">
				<div>
					<h1><span class="g2ab-ev__stencil"><?php esc_html_e( 'EVENTS', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-ev__sub"><?php esc_html_e( 'Scheduled, seat-limited activities · free or paid', 'g2a-booking' ); ?></p>
				</div>
				<a class="g2ab-ev__btn g2ab-ev__btn--primary" href="<?php echo esc_url( $add_url ); ?>"><span>+</span> <?php esc_html_e( 'NEW EVENT', 'g2a-booking' ); ?></a>
			</div>
			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( empty( $events ) ) : ?>
				<div class="g2ab-ev__empty">
					<div class="g2ab-ev__empty-icon">◎</div>
					<h2><?php esc_html_e( 'No events yet.', 'g2a-booking' ); ?></h2>
					<p><?php esc_html_e( 'Create your first event — set the dates, seats and price, and it becomes bookable on the front end.', 'g2a-booking' ); ?></p>
					<a class="g2ab-ev__btn g2ab-ev__btn--primary" href="<?php echo esc_url( $add_url ); ?>">+ <?php esc_html_e( 'CREATE FIRST EVENT', 'g2a-booking' ); ?></a>
				</div>
			<?php else : ?>
				<div class="g2ab-ev__grid">
					<?php foreach ( $events as $ev ) :
						$next  = G2AB_Events::next_occurrence( $ev->id );
						$count = count( G2AB_Events::get_occurrences( $ev->id, array( 'upcoming_only' => false, 'with_seats' => false, 'limit' => 500 ) ) );
						$accent = $ev->color ? $ev->color : '#E8802F';
						?>
						<article class="g2ab-ev__card <?php echo 'publish' === $ev->status ? '' : 'is-inactive'; ?>" style="--ev-accent:<?php echo esc_attr( $accent ); ?>;">
							<div class="g2ab-ev__card-tag"><?php echo esc_html( strtoupper( G2AB_Events::category_label( $ev->category ) ) ); ?></div>
							<h3 class="g2ab-ev__card-title"><?php echo esc_html( $ev->title ); ?></h3>
							<?php if ( $ev->summary ) : ?><p class="g2ab-ev__card-summary"><?php echo esc_html( $ev->summary ); ?></p><?php endif; ?>
							<div class="g2ab-ev__card-price">
								<?php if ( (int) $ev->is_free === 1 || (float) $ev->price <= 0 ) : ?>
									<span class="g2ab-ev__free"><?php esc_html_e( 'FREE', 'g2a-booking' ); ?></span>
								<?php else : ?>
									$<?php echo esc_html( number_format( (float) $ev->price, 2 ) ); ?>
								<?php endif; ?>
								<small><?php echo (int) $ev->capacity; ?> <?php esc_html_e( 'seats', 'g2a-booking' ); ?></small>
							</div>
							<div class="g2ab-ev__card-next">
								<?php if ( $next ) : ?>
									<span class="g2ab-ev__next-date"><?php echo esc_html( $this->fmt_dt( $next->start_at ) ); ?></span>
									<span class="g2ab-ev__next-seats"><?php echo (int) $next->seats_left; ?>/<?php echo (int) $next->seats_total; ?> <?php esc_html_e( 'open', 'g2a-booking' ); ?></span>
								<?php else : ?>
									<span class="g2ab-ev__next-none"><?php esc_html_e( 'No upcoming dates', 'g2a-booking' ); ?></span>
								<?php endif; ?>
								<span class="g2ab-ev__count"><?php echo (int) $count; ?> <?php esc_html_e( 'dates', 'g2a-booking' ); ?></span>
							</div>
							<div class="g2ab-ev__card-flags">
								<?php if ( (int) $ev->requires_waiver ) : ?><span class="g2ab-ev__flag">⚠ WAIVER</span><?php endif; ?>
								<?php if ( (int) $ev->members_only ) : ?><span class="g2ab-ev__flag g2ab-ev__flag--gold">★ MEMBERS</span><?php endif; ?>
								<?php if ( 'publish' !== $ev->status ) : ?><span class="g2ab-ev__flag g2ab-ev__flag--off"><?php echo esc_html( strtoupper( $ev->status ) ); ?></span><?php endif; ?>
							</div>
							<div class="g2ab-ev__card-actions">
								<a class="g2ab-ev__btn" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'edit', 'id' => $ev->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'EDIT', 'g2a-booking' ); ?></a>
								<a class="g2ab-ev__btn" target="_blank" href="<?php echo esc_url( home_url( '/event/' . $ev->slug . '/' ) ); ?>"><?php esc_html_e( 'VIEW', 'g2a-booking' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this event and all its dates?', 'g2a-booking' ) ); ?>');">
									<?php wp_nonce_field( 'g2ab_delete_event_' . $ev->id, '_g2ab_nonce' ); ?>
									<input type="hidden" name="action" value="g2ab_delete_event" />
									<input type="hidden" name="id" value="<?php echo (int) $ev->id; ?>" />
									<button class="g2ab-ev__btn g2ab-ev__btn--danger"><?php esc_html_e( 'DELETE', 'g2a-booking' ); ?></button>
								</form>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ───────────────────────── Form ───────────────────────── */

	private function render_form( $action ) {
		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$ev  = ( 'edit' === $action && $id ) ? G2AB_Events::get_event( $id ) : null;
		if ( 'edit' === $action && ! $ev ) {
			echo '<div class="wrap g2ab-admin"><h1>' . esc_html__( 'Event not found.', 'g2a-booking' ) . '</h1></div>';
			return;
		}
		$is_edit = (bool) $ev;
		$notice  = $this->get_notice();
		$cats    = G2AB_Events::categories();
		$back    = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap g2ab-admin g2ab-ev">
			<div class="g2ab-ev__header">
				<div>
					<h1><span class="g2ab-ev__stencil"><?php echo $is_edit ? esc_html__( 'EDIT EVENT', 'g2a-booking' ) : esc_html__( 'NEW EVENT', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-ev__sub"><?php echo $is_edit ? esc_html( $ev->title ) : esc_html__( 'Define a new bookable event', 'g2a-booking' ); ?></p>
				</div>
				<a class="g2ab-ev__btn" href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'BACK', 'g2a-booking' ); ?></a>
			</div>
			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-ev__form">
				<?php wp_nonce_field( 'g2ab_save_event', '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_save_event" />
				<?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $ev->id; ?>" /><?php endif; ?>

				<div class="g2ab-ev__form-grid">
					<div class="g2ab-ev__panel">
						<h3><?php esc_html_e( 'IDENTITY', 'g2a-booking' ); ?></h3>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Title', 'g2a-booking' ); ?> <span class="req">*</span></label><input type="text" name="title" required value="<?php echo esc_attr( $ev->title ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Ladies Night Tuesday', 'g2a-booking' ); ?>" /></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Slug', 'g2a-booking' ); ?></label><input type="text" name="slug" value="<?php echo esc_attr( $ev->slug ?? '' ); ?>" placeholder="<?php esc_attr_e( 'auto from title', 'g2a-booking' ); ?>" /></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Category', 'g2a-booking' ); ?></label><select name="category"><?php foreach ( $cats as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $ev->category ?? 'event', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Short summary', 'g2a-booking' ); ?></label><input type="text" name="summary" value="<?php echo esc_attr( $ev->summary ?? '' ); ?>" placeholder="<?php esc_attr_e( 'One line shown on cards & banners', 'g2a-booking' ); ?>" /></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Status', 'g2a-booking' ); ?></label><select name="status"><option value="publish" <?php selected( $ev->status ?? 'publish', 'publish' ); ?>><?php esc_html_e( 'Published (bookable)', 'g2a-booking' ); ?></option><option value="draft" <?php selected( $ev->status ?? '', 'draft' ); ?>><?php esc_html_e( 'Draft (hidden)', 'g2a-booking' ); ?></option></select></div>
					</div>

					<div class="g2ab-ev__panel">
						<h3><?php esc_html_e( 'PRICING & SEATS', 'g2a-booking' ); ?></h3>
						<div class="g2ab-ev__field"><label class="g2ab-ev__check"><input type="checkbox" name="is_free" value="1" <?php checked( 1, (int) ( $ev->is_free ?? 0 ) ); ?> /> <?php esc_html_e( 'This event is free', 'g2a-booking' ); ?></label></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Price ($)', 'g2a-booking' ); ?></label><input type="number" name="price" step="0.01" min="0" value="<?php echo esc_attr( $ev->price ?? 0 ); ?>" /><small><?php esc_html_e( 'Ignored when “free” is checked.', 'g2a-booking' ); ?></small></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Member discount (%)', 'g2a-booking' ); ?></label><input type="number" name="member_discount" step="0.1" min="0" max="100" value="<?php echo esc_attr( $ev->member_discount ?? 0 ); ?>" placeholder="0" /><small><?php esc_html_e( 'Percent off for active members (e.g. 20 = 20% off). 0 = no member discount.', 'g2a-booking' ); ?></small></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Fixed member price ($)', 'g2a-booking' ); ?></label><input type="number" name="member_price" step="0.01" min="0" value="<?php echo esc_attr( ( null !== ( $ev->member_price ?? null ) ) ? $ev->member_price : '' ); ?>" placeholder="<?php esc_attr_e( 'blank = use discount above', 'g2a-booking' ); ?>" /><small><?php esc_html_e( 'Optional. Overrides the discount with a flat member price (0 = free for members).', 'g2a-booking' ); ?></small></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Default seats per date', 'g2a-booking' ); ?> <span class="req">*</span></label><input type="number" name="capacity" min="1" max="2000" required value="<?php echo esc_attr( $ev->capacity ?? 20 ); ?>" /></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Duration (minutes)', 'g2a-booking' ); ?></label><input type="number" name="duration_min" min="15" max="1440" value="<?php echo esc_attr( $ev->duration_min ?? 120 ); ?>" /></div>
					</div>

					<div class="g2ab-ev__panel">
						<h3><?php esc_html_e( 'RULES & STYLE', 'g2a-booking' ); ?></h3>
						<div class="g2ab-ev__field"><label class="g2ab-ev__check"><input type="checkbox" name="requires_waiver" value="1" <?php checked( 1, (int) ( $ev->requires_waiver ?? 1 ) ); ?> /> <?php esc_html_e( 'Requires waiver acceptance', 'g2a-booking' ); ?></label></div>
						<div class="g2ab-ev__field"><label class="g2ab-ev__check"><input type="checkbox" name="members_only" value="1" <?php checked( 1, (int) ( $ev->members_only ?? 0 ) ); ?> /> <?php esc_html_e( 'Members only', 'g2a-booking' ); ?></label></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Location', 'g2a-booking' ); ?></label><input type="text" name="location" value="<?php echo esc_attr( $ev->location ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Main Range', 'g2a-booking' ); ?>" /></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Banner image URL', 'g2a-booking' ); ?></label><input type="url" name="image_url" value="<?php echo esc_attr( $ev->image_url ?? '' ); ?>" placeholder="https://…" /></div>
						<div class="g2ab-ev__field"><label><?php esc_html_e( 'Accent colour', 'g2a-booking' ); ?></label><input type="text" name="color" value="<?php echo esc_attr( $ev->color ?? '#E8802F' ); ?>" placeholder="#E8802F" /></div>
					</div>

					<div class="g2ab-ev__panel g2ab-ev__panel--wide">
						<h3><?php esc_html_e( 'DESCRIPTION', 'g2a-booking' ); ?></h3>
						<div class="g2ab-ev__field"><textarea name="description" rows="5" placeholder="<?php esc_attr_e( 'Full event details shown on the landing page…', 'g2a-booking' ); ?>"><?php echo esc_textarea( $ev->description ?? '' ); ?></textarea></div>
					</div>
				</div>

				<div class="g2ab-ev__form-foot">
					<a class="g2ab-ev__btn" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'CANCEL', 'g2a-booking' ); ?></a>
					<button type="submit" class="g2ab-ev__btn g2ab-ev__btn--primary"><?php echo $is_edit ? esc_html__( 'SAVE EVENT', 'g2a-booking' ) : esc_html__( 'CREATE EVENT', 'g2a-booking' ); ?></button>
				</div>
			</form>

			<?php if ( $is_edit ) { $this->render_occurrences_panel( $ev ); } else { ?>
				<div class="g2ab-ev__occ-hint"><?php esc_html_e( 'Save the event first, then add dates & times below.', 'g2a-booking' ); ?></div>
			<?php } ?>
		</div>
		<?php
	}

	private function render_occurrences_panel( $ev ) {
		$occs = G2AB_Events::get_occurrences( $ev->id, array( 'upcoming_only' => false, 'include_cancelled' => true, 'limit' => 500 ) );
		?>
		<div class="g2ab-ev__occ">
			<h2 class="g2ab-ev__occ-title"><?php esc_html_e( 'DATES & TIMES', 'g2a-booking' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-ev__occ-add">
				<?php wp_nonce_field( 'g2ab_add_occurrence_' . $ev->id, '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_add_occurrence" />
				<input type="hidden" name="event_id" value="<?php echo (int) $ev->id; ?>" />
				<div class="g2ab-ev__occ-add-field"><label><?php esc_html_e( 'Start date & time', 'g2a-booking' ); ?></label><input type="datetime-local" name="start_at" required /></div>
				<div class="g2ab-ev__occ-add-field"><label><?php esc_html_e( 'Seats', 'g2a-booking' ); ?></label><input type="number" name="capacity" min="0" placeholder="<?php echo (int) $ev->capacity; ?>" /><small><?php esc_html_e( '0 = use default', 'g2a-booking' ); ?></small></div>
				<div class="g2ab-ev__occ-add-field"><label><?php esc_html_e( 'Price override ($)', 'g2a-booking' ); ?></label><input type="number" name="price" min="0" step="0.01" placeholder="<?php esc_attr_e( 'default', 'g2a-booking' ); ?>" /></div>
				<button type="submit" class="g2ab-ev__btn g2ab-ev__btn--primary"><?php esc_html_e( '+ ADD DATE', 'g2a-booking' ); ?></button>
			</form>

			<?php if ( empty( $occs ) ) : ?>
				<p class="g2ab-ev__occ-empty"><?php esc_html_e( 'No dates yet. Add one above to make this event bookable.', 'g2a-booking' ); ?></p>
			<?php else : ?>
				<table class="g2ab-ev__occ-table">
					<thead><tr>
						<th><?php esc_html_e( 'When', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Seats', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Booked', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Open', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Capacity check', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Price', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'g2a-booking' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $occs as $o ) :
							$diag = class_exists( 'G2AB_Event_Capacity_Service' ) ? G2AB_Event_Capacity_Service::diagnostics( $o->id ) : null;
							?>
							<tr class="<?php echo 'cancelled' === $o->status ? 'is-cancelled' : ''; ?>">
								<td><?php echo esc_html( $this->fmt_dt( $o->start_at ) ); ?></td>
								<td><?php echo (int) $o->seats_total; ?></td>
								<td><?php echo (int) $o->seats_booked; ?></td>
								<td><strong><?php echo (int) $o->seats_left; ?></strong></td>
								<td>
									<?php if ( is_array( $diag ) ) : ?>
										<details class="g2ab-ev__diag">
											<summary><?php echo (int) $diag['reserved']; ?>/<?php echo (int) $diag['capacity']; ?> <?php esc_html_e( 'live', 'g2a-booking' ); ?></summary>
											<p><?php printf( esc_html__( 'Confirmed: %1$d | Active holds: %2$d | Pay in store: %3$d | Expired holds: %4$d', 'g2a-booking' ), (int) $diag['confirmed_seats'], (int) $diag['active_pending_holds'], (int) $diag['reserved_pay_store_seats'], (int) $diag['expired_pending_holds'] ); ?></p>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<?php wp_nonce_field( 'g2ab_recalculate_occurrence_capacity_' . $o->id, '_g2ab_nonce' ); ?>
												<input type="hidden" name="action" value="g2ab_recalculate_occurrence_capacity" />
												<input type="hidden" name="id" value="<?php echo (int) $o->id; ?>" />
												<input type="hidden" name="event_id" value="<?php echo (int) $ev->id; ?>" />
												<button class="g2ab-ev__btn g2ab-ev__btn--sm"><?php esc_html_e( 'RECALC', 'g2a-booking' ); ?></button>
											</form>
										</details>
									<?php else : ?>
										<span class="g2ab-ev__next-none"><?php esc_html_e( 'Unavailable', 'g2a-booking' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo (int) $ev->is_free === 1 ? esc_html__( 'Free', 'g2a-booking' ) : '$' . esc_html( number_format( (float) $o->price_effective, 2 ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $o->status ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this date?', 'g2a-booking' ) ); ?>');">
										<?php wp_nonce_field( 'g2ab_delete_occurrence_' . $o->id, '_g2ab_nonce' ); ?>
										<input type="hidden" name="action" value="g2ab_delete_occurrence" />
										<input type="hidden" name="id" value="<?php echo (int) $o->id; ?>" />
										<input type="hidden" name="event_id" value="<?php echo (int) $ev->id; ?>" />
										<button class="g2ab-ev__btn g2ab-ev__btn--danger g2ab-ev__btn--sm"><?php esc_html_e( 'REMOVE', 'g2a-booking' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ───────────────────────── Handlers ───────────────────────── */

	public function handle_save_event() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		check_admin_referer( 'g2ab_save_event', '_g2ab_nonce' );

		$id   = (int) ( $_POST['id'] ?? 0 );
		$data = array(
			'title'           => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'slug'            => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
			'category'        => sanitize_key( wp_unslash( $_POST['category'] ?? 'event' ) ),
			'summary'         => sanitize_text_field( wp_unslash( $_POST['summary'] ?? '' ) ),
			'description'     => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ),
			'price'           => (float) ( $_POST['price'] ?? 0 ),
			'member_price'    => ( '' === ( $_POST['member_price'] ?? '' ) ) ? null : (float) $_POST['member_price'],
			'member_discount' => (float) ( $_POST['member_discount'] ?? 0 ),
			'is_free'         => isset( $_POST['is_free'] ) ? 1 : 0,
			'capacity'        => max( 1, (int) ( $_POST['capacity'] ?? 20 ) ),
			'duration_min'    => max( 15, (int) ( $_POST['duration_min'] ?? 120 ) ),
			'requires_waiver' => isset( $_POST['requires_waiver'] ) ? 1 : 0,
			'members_only'    => isset( $_POST['members_only'] ) ? 1 : 0,
			'location'        => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
			'image_url'       => esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) ),
			'color'           => sanitize_text_field( wp_unslash( $_POST['color'] ?? '' ) ),
			'status'          => sanitize_key( wp_unslash( $_POST['status'] ?? 'publish' ) ),
		);

		if ( '' === $data['title'] ) {
			$this->set_notice( 'error', __( 'Title is required.', 'g2a-booking' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=add' ) );
			exit;
		}

		if ( $id ) {
			G2AB_Events::update_event( $id, $data );
			$this->set_notice( 'success', __( 'Event updated.', 'g2a-booking' ) );
			$redirect = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . $id );
		} else {
			$new = G2AB_Events::create_event( $data );
			if ( is_wp_error( $new ) ) {
				$this->set_notice( 'error', $new->get_error_message() );
				$redirect = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=add' );
			} else {
				$this->set_notice( 'success', __( 'Event created. Now add some dates below.', 'g2a-booking' ) );
				$redirect = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . (int) $new );
			}
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_delete_event() {
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		check_admin_referer( 'g2ab_delete_event_' . $id, '_g2ab_nonce' );
		G2AB_Events::delete_event( $id );
		$this->set_notice( 'success', __( 'Event deleted.', 'g2a-booking' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function handle_add_occurrence() {
		$event_id = (int) ( $_POST['event_id'] ?? 0 );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		check_admin_referer( 'g2ab_add_occurrence_' . $event_id, '_g2ab_nonce' );
		$res = G2AB_Events::add_occurrence( $event_id, array(
			'start_at' => sanitize_text_field( wp_unslash( $_POST['start_at'] ?? '' ) ),
			'capacity' => (int) ( $_POST['capacity'] ?? 0 ),
			'price'    => ( '' === ( $_POST['price'] ?? '' ) ) ? null : (float) $_POST['price'],
		) );
		if ( is_wp_error( $res ) ) {
			$this->set_notice( 'error', $res->get_error_message() );
		} else {
			$this->set_notice( 'success', __( 'Date added.', 'g2a-booking' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . $event_id ) );
		exit;
	}

	public function handle_delete_occurrence() {
		$id       = (int) ( $_POST['id'] ?? 0 );
		$event_id = (int) ( $_POST['event_id'] ?? 0 );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		check_admin_referer( 'g2ab_delete_occurrence_' . $id, '_g2ab_nonce' );
		G2AB_Events::delete_occurrence( $id );
		$this->set_notice( 'success', __( 'Date removed.', 'g2a-booking' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . $event_id ) );
		exit;
	}

	/* ───────────────────────── Helpers ───────────────────────── */

	public function handle_recalculate_occurrence_capacity() {
		$id       = (int) ( $_POST['id'] ?? 0 );
		$event_id = (int) ( $_POST['event_id'] ?? 0 );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		check_admin_referer( 'g2ab_recalculate_occurrence_capacity_' . $id, '_g2ab_nonce' );
		if ( ! class_exists( 'G2AB_Event_Capacity_Service' ) ) {
			$this->set_notice( 'error', __( 'Capacity service is unavailable.', 'g2a-booking' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . $event_id ) );
			exit;
		}
		$expired = G2AB_Event_Capacity_Service::expire_stale_holds( $id );
		$diag    = G2AB_Event_Capacity_Service::diagnostics( $id );
		if ( is_wp_error( $diag ) ) {
			$this->set_notice( 'error', $diag->get_error_message() );
		} else {
			$this->set_notice( 'success', sprintf( __( 'Capacity recalculated. Expired holds: %1$d. Live open seats: %2$d of %3$d.', 'g2a-booking' ), (int) $expired, (int) $diag['seats_left'], (int) $diag['capacity'] ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . $event_id ) );
		exit;
	}

	private function fmt_dt( $sql ) {
		return G2AB_Events::format_local( $sql, 'D, M j · g:i A' );
	}

	private function set_notice( $type, $msg ) {
		set_transient( 'g2ab_ev_notice_' . get_current_user_id(), array( 'type' => $type, 'msg' => $msg ), 30 );
	}

	private function get_notice() {
		$n = get_transient( 'g2ab_ev_notice_' . get_current_user_id() );
		if ( ! $n ) {
			return '';
		}
		delete_transient( 'g2ab_ev_notice_' . get_current_user_id() );
		$cls = 'error' === $n['type'] ? 'g2ab-ev__notice--error' : 'g2ab-ev__notice--ok';
		return '<div class="g2ab-ev__notice ' . esc_attr( $cls ) . '">' . esc_html( $n['msg'] ) . '</div>';
	}

	private function print_styles() {
		?>
		<style id="g2ab-ev-admin-styles">
		.g2ab-ev{--ev-bg:#1A191E;--ev-surface:#26252C;--ev-surface2:#26252C;--ev-border:#2A323D;--ev-text:#F7F7F9;--ev-muted:#A7A6AE;--ev-orange:#E8802F;--ev-gold:#E8802F;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
		.g2ab-ev *{box-sizing:border-box;}
		.g2ab-ev__header{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin:18px 0 22px;padding-bottom:16px;border-bottom:2px solid var(--ev-border);}
		.g2ab-ev__stencil{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:30px;font-weight:700;letter-spacing:.06em;color:#fff;text-transform:uppercase;}
		.g2ab-ev h1{margin:0;padding:0;}
		.g2ab-ev__sub{margin:4px 0 0;color:var(--ev-muted);font-size:13px;}
		.g2ab-ev__btn{display:inline-flex;align-items:center;gap:6px;background:var(--ev-surface2);color:var(--ev-text)!important;border:1px solid var(--ev-border);border-radius:4px;padding:9px 15px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;text-decoration:none;cursor:pointer;transition:all .12s ease;line-height:1;}
		.g2ab-ev__btn:hover{border-color:var(--ev-orange);color:#fff!important;}
		.g2ab-ev__btn--primary{background:var(--ev-orange);border-color:var(--ev-orange);color:#fff!important;}
		.g2ab-ev__btn--primary:hover{background:#b85a16;border-color:#b85a16;}
		.g2ab-ev__btn--danger:hover{background:#C62828;border-color:#C62828;color:#fff!important;}
		.g2ab-ev__btn--sm{padding:6px 10px;font-size:11px;}
		.g2ab-ev__notice{padding:11px 16px;border-radius:4px;margin-bottom:18px;font-size:13px;font-weight:600;}
		.g2ab-ev__notice--ok{background:rgba(76,175,80,.15);color:#7BD389;border:1px solid rgba(76,175,80,.4);}
		.g2ab-ev__notice--error{background:rgba(198,40,40,.15);color:#F08A8A;border:1px solid rgba(198,40,40,.4);}
		.g2ab-ev__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px;}
		.g2ab-ev__card{position:relative;background:var(--ev-surface);border:1px solid var(--ev-border);border-top:4px solid var(--ev-accent,#E8802F);border-radius:6px;padding:18px 18px 16px;display:flex;flex-direction:column;gap:8px;}
		.g2ab-ev__card.is-inactive{opacity:.6;}
		.g2ab-ev__card-tag{display:inline-block;align-self:flex-start;background:var(--ev-accent,#E8802F);color:#fff;font-size:9px;font-weight:700;letter-spacing:.1em;padding:3px 8px;border-radius:2px;}
		.g2ab-ev__card-title{margin:2px 0 0;font-size:18px;color:#fff;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;letter-spacing:.02em;text-transform:uppercase;line-height:1.15;}
		.g2ab-ev__card-summary{margin:0;color:var(--ev-muted);font-size:12px;line-height:1.4;}
		.g2ab-ev__card-price{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:24px;font-weight:700;color:var(--ev-orange);line-height:1;display:flex;align-items:baseline;gap:8px;}
		.g2ab-ev__card-price small{font-size:11px;color:var(--ev-muted);font-family:inherit;letter-spacing:.04em;}
		.g2ab-ev__free{color:#4CAF50;}
		.g2ab-ev__card-next{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;font-size:12px;color:var(--ev-muted);border-top:1px solid var(--ev-border);padding-top:8px;}
		.g2ab-ev__next-date{color:var(--ev-text);font-weight:600;}
		.g2ab-ev__next-seats{color:#4CAF50;font-weight:700;}
		.g2ab-ev__next-none{color:#C62828;}
		.g2ab-ev__count{margin-left:auto;}
		.g2ab-ev__card-flags{display:flex;flex-wrap:wrap;gap:6px;min-height:4px;}
		.g2ab-ev__flag{font-size:9px;font-weight:700;letter-spacing:.08em;color:var(--ev-muted);background:var(--ev-surface2);padding:3px 7px;border-radius:2px;}
		.g2ab-ev__flag--gold{color:var(--ev-gold);}
		.g2ab-ev__flag--off{color:#F08A8A;}
		.g2ab-ev__card-actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:auto;padding-top:6px;}
		.g2ab-ev__card-actions form{display:inline;margin:0;}
		.g2ab-ev__empty{text-align:center;padding:60px 20px;background:var(--ev-surface);border:1px dashed var(--ev-border);border-radius:8px;color:var(--ev-muted);}
		.g2ab-ev__empty-icon{font-size:46px;color:var(--ev-orange);}
		.g2ab-ev__empty h2{color:#fff;margin:10px 0;}
		.g2ab-ev__form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;}
		.g2ab-ev__panel{background:var(--ev-surface);border:1px solid var(--ev-border);border-radius:6px;padding:18px;}
		.g2ab-ev__panel--wide{grid-column:1/-1;}
		.g2ab-ev__panel h3{margin:0 0 14px;font-size:12px;letter-spacing:.1em;color:var(--ev-orange);font-weight:700;border-bottom:1px solid var(--ev-border);padding-bottom:8px;}
		.g2ab-ev__field{margin-bottom:12px;}
		.g2ab-ev__field label{display:block;font-size:12px;color:var(--ev-muted);margin-bottom:5px;font-weight:600;}
		.g2ab-ev__field .req{color:var(--ev-orange);}
		.g2ab-ev__field input,.g2ab-ev__field select,.g2ab-ev__field textarea{width:100%;background:var(--ev-bg);border:1px solid var(--ev-border);border-radius:4px;color:var(--ev-text);padding:9px 11px;font-size:14px;}
		.g2ab-ev__field input:focus,.g2ab-ev__field select:focus,.g2ab-ev__field textarea:focus{outline:none;border-color:var(--ev-orange);}
		.g2ab-ev__field small{display:block;margin-top:4px;color:var(--ev-muted);font-size:11px;}
		.g2ab-ev__check{display:flex!important;align-items:center;gap:8px;color:var(--ev-text)!important;font-size:13px!important;}
		.g2ab-ev__check input{width:auto!important;}
		.g2ab-ev__form-foot{display:flex;justify-content:flex-end;gap:10px;margin:18px 0;}
		.g2ab-ev__occ-hint{margin-top:18px;padding:16px;background:var(--ev-surface);border:1px dashed var(--ev-border);border-radius:6px;color:var(--ev-muted);font-size:13px;}
		.g2ab-ev__occ{margin-top:26px;background:var(--ev-surface);border:1px solid var(--ev-border);border-radius:6px;padding:18px;}
		.g2ab-ev h2.g2ab-ev__occ-title{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:18px;letter-spacing:.06em;color:#fff!important;margin:0 0 14px;}
		.g2ab-ev__occ-add{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid var(--ev-border);}
		.g2ab-ev__occ-add-field label{display:block;font-size:11px;color:var(--ev-muted);margin-bottom:5px;font-weight:600;}
		.g2ab-ev__occ-add-field input{background:var(--ev-bg);border:1px solid var(--ev-border);border-radius:4px;color:var(--ev-text);padding:9px 11px;font-size:14px;}
		.g2ab-ev__occ-add-field small{display:block;margin-top:3px;color:var(--ev-muted);font-size:10px;}
		.g2ab-ev__occ-empty{color:var(--ev-muted);font-size:13px;margin:0;}
		.g2ab-ev__occ-table{width:100%;border-collapse:collapse;font-size:13px;}
		.g2ab-ev__occ-table th{text-align:left;color:var(--ev-muted);font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:8px 10px;border-bottom:1px solid var(--ev-border);}
		.g2ab-ev__occ-table td{padding:9px 10px;border-bottom:1px solid var(--ev-border);color:var(--ev-text);}
		.g2ab-ev__occ-table tr.is-cancelled{opacity:.5;}
		.g2ab-ev__occ-table form{margin:0;}
		.g2ab-ev__diag summary{cursor:pointer;color:#7BD389;font-weight:700;}
		.g2ab-ev__diag p{margin:8px 0;color:var(--ev-muted);font-size:11px;line-height:1.45;max-width:260px;}
		</style>
		<?php
	}
}
