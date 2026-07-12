<?php
/**
 * Instructor roster — single source of truth.
 *
 * The site previously shipped TWO conflicting fabricated rosters
 * (Halsey/Cruz/Okafor on /about/ vs Mendoza/Kelly/Tran on /training/),
 * an E-E-A-T killer flagged in the June 2026 audit. This file holds the
 * REAL roster and renders it identically everywhere.
 *
 * Adding / removing instructors (no code edits needed):
 *  - Option override: update_option( 'g2a_instructors_overrides', [...] )
 *    — entries merge over the defaults by slug; set 'active' => false to
 *    hide one; add a brand-new slug to append one.
 *  - Or the `g2a_instructors` filter from a child plugin.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The roster. Each instructor:
 *  slug, name, role, initials, bio, focuses[], creds[] (short badge
 *  labels), photo (attachment URL, optional), active (bool).
 *
 * @return array<string,array>
 */
function g2a_instructors() {
	$defaults = array(
		'nicholas-steigert' => array(
			'slug'     => 'nicholas-steigert',
			'name'     => 'Nicholas Steigert',
			'role'     => 'Lead Firearms Instructor',
			'initials' => 'NS',
			'bio'      => 'Over a decade of firearms training and real-world operational experience, including multiple combat deployments with 3rd Battalion, 7th Marines. Direct but approachable — Nicholas builds confident, capable shooters from first-timers to advanced students.',
			'focuses'  => array( 'Arizona CCW Certification', 'Defensive Handgun', 'Safety & Manipulation', 'Tactical Mindset' ),
			'creds'    => array( 'NRA Certified', 'USMC 3/7 Veteran' ),
			'photo'    => '',
			'active'   => true,
		),
		'alen-olson' => array(
			'slug'     => 'alen-olson',
			'name'     => 'Alen Olson',
			'role'     => 'Firearms Instructor',
			'initials' => 'AO',
			'bio'      => 'Retired Mesa Police Department firearms instructor with multiple decades of experience training new and seasoned shooters. Calm, professional, and grounded in real-world law-enforcement accountability.',
			'focuses'  => array( 'Arizona CCW Certification', 'Defensive Fundamentals', 'Marksmanship', 'New Shooter Instruction' ),
			'creds'    => array( 'NRA Certified', 'Ret. Mesa PD Instructor' ),
			'photo'    => '',
			'active'   => true,
		),
	);

	// Admin-editable overrides merge over the defaults by slug.
	$overrides = get_option( 'g2a_instructors_overrides', array() );
	if ( is_array( $overrides ) ) {
		foreach ( $overrides as $slug => $row ) {
			if ( ! is_array( $row ) ) continue;
			$slug = sanitize_key( $slug );
			$defaults[ $slug ] = array_merge( $defaults[ $slug ] ?? array( 'slug' => $slug, 'active' => true ), $row );
		}
	}

	$roster = apply_filters( 'g2a_instructors', $defaults );

	return array_filter( (array) $roster, static function ( $i ) {
		return is_array( $i ) && ! empty( $i['name'] ) && ( ! isset( $i['active'] ) || $i['active'] );
	} );
}

/**
 * Render the roster as cards. Re-uses the training page's .ig/.inst card
 * classes so it inherits each page's existing styling (and light/dark
 * tokens). Outputs Person JSON-LD for E-E-A-T / AI-citation.
 */
function g2a_render_instructors() {
	$roster = g2a_instructors();
	if ( ! $roster ) return;
	?>
	<div class="ig" data-reveal-stagger>
		<?php foreach ( $roster as $i ) : ?>
			<div class="inst g2a-lift">
				<?php if ( ! empty( $i['photo'] ) ) : ?>
					<div class="av" style="padding:0;overflow:hidden;"><img src="<?php echo esc_url( $i['photo'] ); ?>" alt="<?php echo esc_attr( $i['name'] ); ?>" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;"></div>
				<?php else : ?>
					<div class="av"><?php echo esc_html( $i['initials'] ?? strtoupper( substr( (string) $i['name'], 0, 2 ) ) ); ?></div>
				<?php endif; ?>
				<div class="nm"><?php echo esc_html( strtoupper( $i['name'] ) ); ?></div>
				<div class="role"><?php echo esc_html( $i['role'] ?? '' ); ?></div>
				<p><?php echo esc_html( $i['bio'] ?? '' ); ?></p>
				<?php if ( ! empty( $i['creds'] ) ) : ?>
					<div class="creds"><?php foreach ( (array) $i['creds'] as $c ) : ?><span><?php echo esc_html( $c ); ?></span><?php endforeach; ?></div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<script type="application/ld+json"><?php
	echo wp_json_encode( array_values( array_map( static function ( $i ) {
		return array(
			'@context'  => 'https://schema.org',
			'@type'     => 'Person',
			'name'      => $i['name'],
			'jobTitle'  => $i['role'] ?? 'Firearms Instructor',
			// References the single Organization node (inc/aeo.php) by @id
			// instead of inlining a fresh, disconnected duplicate.
			'worksFor'  => array( '@id' => home_url( '/#organization' ) ),
			'description' => $i['bio'] ?? '',
			'knowsAbout'  => array_values( (array) ( $i['focuses'] ?? array() ) ),
		);
	}, g2a_instructors() ) ), JSON_UNESCAPED_SLASHES );
	?></script>
	<?php
}
