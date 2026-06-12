<?php
/**
 * Centralized instructor roster.
 *
 * One source of truth rendered identically on /training/ and /about/ —
 * the old hardcoded (and conflicting) rosters destroyed E-E-A-T. The
 * roster is editable under Appearance → Instructors: add, remove,
 * reorder, toggle instructors on/off, and attach a photo, without
 * touching code. Person JSON-LD is emitted wherever the roster renders.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The real default roster (provided by ownership, June 2026).
 */
function g2a_default_instructors() {
	return array(
		array(
			'id'      => 'nicholas-steigert',
			'name'    => 'Nicholas Steigert',
			'role'    => 'Lead Firearms Instructor',
			'bio'     => 'Over a decade of firearms knowledge, training experience, and real-world operational background. Nicholas holds multiple NRA firearms certifications and has extensive military and law enforcement experience, including multiple combat deployments with 3rd Battalion, 7th Marines. Known for a direct but approachable teaching style, he works with shooters of all experience levels — from first-time firearm owners to advanced shooters sharpening defensive skills.',
			'creds'   => array( 'NRA Certified', 'USMC 3/7 Veteran', 'Law Enforcement' ),
			'focuses' => array( 'Arizona CCW Certification', 'Defensive Handgun Training', 'Firearm Safety & Manipulation', 'Tactical Mindset & Preparedness', 'Beginner to Advanced Development', 'Real-World Defensive Applications' ),
			'photo'   => '',
			'enabled' => true,
		),
		array(
			'id'      => 'alen-olson',
			'name'    => 'Alen Olson',
			'role'    => 'Lead Firearms Instructor',
			'bio'     => 'Multiple decades of firearms experience, law enforcement service, and professional instruction. A retired Mesa Police firearms instructor, Alen has spent years training both new and experienced shooters in safe handling, defensive shooting fundamentals, and practical application. He holds multiple NRA certifications and is known for a calm, professional, highly knowledgeable approach that builds confidence and a safety-first mindset.',
			'creds'   => array( 'NRA Certified', 'Retired Mesa PD Firearms Instructor', 'Law Enforcement' ),
			'focuses' => array( 'Arizona CCW Certification', 'Defensive Handgun Fundamentals', 'Firearm Safety & Handling', 'Marksmanship Development', 'New Shooter Instruction', 'Practical Defensive Training' ),
			'photo'   => '',
			'enabled' => true,
		),
	);
}

/**
 * Full roster (saved option falls back to defaults). Filterable.
 *
 * @return array[] list of instructor rows.
 */
function g2a_instructors() {
	$saved = get_option( 'g2a_instructors' );
	$list  = ( is_array( $saved ) && $saved ) ? $saved : g2a_default_instructors();
	return apply_filters( 'g2a_instructors', $list );
}

/** Only the instructors marked enabled. */
function g2a_active_instructors() {
	return array_values( array_filter( g2a_instructors(), function ( $i ) {
		return ! empty( $i['enabled'] );
	} ) );
}

/** Person JSON-LD for the active roster. */
function g2a_instructors_schema() {
	$people = array();
	foreach ( g2a_active_instructors() as $i ) {
		$person = array(
			'@type'       => 'Person',
			'name'        => $i['name'],
			'jobTitle'    => $i['role'],
			'description' => $i['bio'],
			'worksFor'    => array(
				'@type' => 'LocalBusiness',
				'name'  => 'Guns 2 Ammo',
			),
		);
		if ( ! empty( $i['photo'] ) ) {
			$person['image'] = $i['photo'];
		}
		$people[] = $person;
	}
	if ( ! $people ) {
		return;
	}
	echo '<script type="application/ld+json">' . wp_json_encode( array(
		'@context' => 'https://schema.org',
		'@graph'   => $people,
	) ) . '</script>' . "\n";
}

/**
 * Render the roster as cards.
 *
 * @param string $style 'inst' (training page card style) or 'member'
 *                      (about page card style) — matches existing CSS.
 */
function g2a_render_instructors( $style = 'inst' ) {
	$list = g2a_active_instructors();
	if ( ! $list ) {
		return;
	}
	foreach ( $list as $i ) {
		$initials = strtoupper( join( '', array_map( function ( $w ) {
			return mb_substr( $w, 0, 1 );
		}, array_slice( preg_split( '/\s+/', trim( $i['name'] ) ), 0, 2 ) ) ) );

		if ( 'member' === $style ) { ?>
      <div class="member" data-reveal>
        <div class="photo"<?php if ( ! empty( $i['photo'] ) ) : ?> style="background-image:url('<?php echo esc_url( $i['photo'] ); ?>');background-size:cover;background-position:center top;"<?php else : ?> data-pl="<?php echo esc_attr( $initials ); ?>"<?php endif; ?>></div>
        <div class="body">
          <div class="role"><?php echo esc_html( $i['role'] ); ?></div>
          <h4><?php echo esc_html( strtoupper( $i['name'] ) ); ?></h4>
          <p><?php echo esc_html( $i['bio'] ); ?></p>
        </div>
      </div>
		<?php } else { ?>
      <div class="inst" data-reveal>
        <?php if ( ! empty( $i['photo'] ) ) : ?>
        <div class="av" style="background-image:url('<?php echo esc_url( $i['photo'] ); ?>');background-size:cover;background-position:center;color:transparent;"><?php echo esc_html( $initials ); ?></div>
        <?php else : ?>
        <div class="av"><?php echo esc_html( $initials ); ?></div>
        <?php endif; ?>
        <div class="nm"><?php echo esc_html( strtoupper( $i['name'] ) ); ?></div>
        <div class="role"><?php echo esc_html( $i['role'] ); ?></div>
        <p><?php echo esc_html( $i['bio'] ); ?></p>
        <?php if ( ! empty( $i['creds'] ) ) : ?>
        <div class="creds"><?php foreach ( (array) $i['creds'] as $c ) : ?><span><?php echo esc_html( $c ); ?></span><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
		<?php }
	}
}

/* ---------------------------------------------------------------------
 * Admin: Appearance → Instructors
 * ------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_theme_page(
		__( 'Instructors', 'guns2ammo' ),
		__( 'Instructors', 'guns2ammo' ),
		'edit_theme_options',
		'g2a-instructors',
		'g2a_instructors_admin_page'
	);
} );

function g2a_instructors_admin_page() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	if ( isset( $_POST['g2a_instructors_nonce'] )
		&& wp_verify_nonce( sanitize_key( $_POST['g2a_instructors_nonce'] ), 'g2a_save_instructors' ) ) {
		$rows  = isset( $_POST['inst'] ) && is_array( $_POST['inst'] ) ? wp_unslash( $_POST['inst'] ) : array();
		$clean = array();
		foreach ( $rows as $r ) {
			$name = sanitize_text_field( $r['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$clean[] = array(
				'id'      => sanitize_title( $r['id'] ?? $name ),
				'name'    => $name,
				'role'    => sanitize_text_field( $r['role'] ?? '' ),
				'bio'     => sanitize_textarea_field( $r['bio'] ?? '' ),
				'creds'   => array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', (string) ( $r['creds'] ?? '' ) ) ) ) ),
				'focuses' => array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', (string) ( $r['focuses'] ?? '' ) ) ) ) ),
				'photo'   => esc_url_raw( $r['photo'] ?? '' ),
				'enabled' => ! empty( $r['enabled'] ),
			);
		}
		update_option( 'g2a_instructors', $clean, false );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Instructors saved.', 'guns2ammo' ) . '</p></div>';
	}

	$list = g2a_instructors();
	wp_enqueue_media();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Instructors', 'guns2ammo' ); ?></h1>
		<p><?php esc_html_e( 'This roster renders on the Training and About pages (with Person schema for search engines). Untick "Active" to hide an instructor without deleting them.', 'guns2ammo' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'g2a_save_instructors', 'g2a_instructors_nonce' ); ?>
			<div id="g2a-inst-rows">
			<?php foreach ( $list as $n => $i ) : ?>
				<div class="g2a-inst-row" style="border:1px solid #ccd0d4;background:#fff;padding:16px;margin:0 0 12px;max-width:780px;">
					<p>
						<label><strong><?php esc_html_e( 'Name', 'guns2ammo' ); ?></strong><br>
						<input type="text" name="inst[<?php echo (int) $n; ?>][name]" value="<?php echo esc_attr( $i['name'] ); ?>" class="regular-text"></label>
						&nbsp; <label><input type="checkbox" name="inst[<?php echo (int) $n; ?>][enabled]" <?php checked( ! empty( $i['enabled'] ) ); ?>> <?php esc_html_e( 'Active', 'guns2ammo' ); ?></label>
						<input type="hidden" name="inst[<?php echo (int) $n; ?>][id]" value="<?php echo esc_attr( $i['id'] ); ?>">
					</p>
					<p><label><strong><?php esc_html_e( 'Role / Title', 'guns2ammo' ); ?></strong><br>
						<input type="text" name="inst[<?php echo (int) $n; ?>][role]" value="<?php echo esc_attr( $i['role'] ); ?>" class="regular-text"></label></p>
					<p><label><strong><?php esc_html_e( 'Bio', 'guns2ammo' ); ?></strong><br>
						<textarea name="inst[<?php echo (int) $n; ?>][bio]" rows="4" class="large-text"><?php echo esc_textarea( $i['bio'] ); ?></textarea></label></p>
					<p><label><strong><?php esc_html_e( 'Credentials (comma-separated)', 'guns2ammo' ); ?></strong><br>
						<input type="text" name="inst[<?php echo (int) $n; ?>][creds]" value="<?php echo esc_attr( join( ', ', (array) $i['creds'] ) ); ?>" class="large-text"></label></p>
					<p><label><strong><?php esc_html_e( 'Training focuses (comma-separated)', 'guns2ammo' ); ?></strong><br>
						<input type="text" name="inst[<?php echo (int) $n; ?>][focuses]" value="<?php echo esc_attr( join( ', ', (array) ( $i['focuses'] ?? array() ) ) ); ?>" class="large-text"></label></p>
					<p><label><strong><?php esc_html_e( 'Photo URL', 'guns2ammo' ); ?></strong><br>
						<input type="url" name="inst[<?php echo (int) $n; ?>][photo]" value="<?php echo esc_attr( $i['photo'] ); ?>" class="regular-text g2a-inst-photo">
						<button type="button" class="button g2a-inst-media"><?php esc_html_e( 'Choose image', 'guns2ammo' ); ?></button></label></p>
					<p><button type="button" class="button-link-delete g2a-inst-remove"><?php esc_html_e( 'Remove instructor', 'guns2ammo' ); ?></button></p>
				</div>
			<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button" id="g2a-inst-add"><?php esc_html_e( '+ Add instructor', 'guns2ammo' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save instructors', 'guns2ammo' ); ?></button>
			</p>
		</form>
	</div>
	<script>
	(function () {
		var wrap = document.getElementById('g2a-inst-rows');
		document.getElementById('g2a-inst-add').addEventListener('click', function () {
			var rows = wrap.querySelectorAll('.g2a-inst-row');
			var tpl  = rows[rows.length - 1].cloneNode(true);
			var idx  = rows.length;
			tpl.querySelectorAll('input, textarea').forEach(function (el) {
				el.name = el.name.replace(/inst\[\d+\]/, 'inst[' + idx + ']');
				if (el.type === 'checkbox') { el.checked = true; } else { el.value = ''; }
			});
			wrap.appendChild(tpl);
		});
		wrap.addEventListener('click', function (e) {
			if (e.target.classList.contains('g2a-inst-remove')) {
				var rows = wrap.querySelectorAll('.g2a-inst-row');
				if (rows.length > 1) { e.target.closest('.g2a-inst-row').remove(); }
				else { rows[0].querySelectorAll('input, textarea').forEach(function (el) { if (el.type === 'checkbox') { el.checked = false; } else { el.value = ''; } }); }
			}
			if (e.target.classList.contains('g2a-inst-media') && window.wp && wp.media) {
				e.preventDefault();
				var input = e.target.closest('p').querySelector('.g2a-inst-photo');
				var frame = wp.media({ title: 'Instructor photo', multiple: false });
				frame.on('select', function () {
					input.value = frame.state().get('selection').first().toJSON().url;
				});
				frame.open();
			}
		});
	})();
	</script>
	<?php
}
