<?php
/**
 * Guns 2 Ammo defaults — seeds the AI knowledge base (FAQ + KB text), the
 * keyword auto-reply rules, and the auto-reply subject with verified
 * Guns 2 Ammo business facts.
 *
 * Seeding is strictly fill-only: an option is written ONLY when it is empty
 * (or, for the auto-reply subject, still the generic plugin default), so an
 * operator's edits are never overwritten. Runs on plugin activation and via
 * the "Seed Guns 2 Ammo defaults" button on the settings screen.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fill-only seeder for Guns 2 Ammo AI knowledge + reply templates.
 */
class Wpistic_Formistic_G2A_Defaults {

	/** admin-post action for the settings-screen button. */
	const ACTION = 'wpistic_formistic_g2a_seed';

	/** Capability required to run the seeder from the admin. */
	const CAP = 'manage_options';

	/**
	 * Register the admin-triggered seed action.
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_seed' ] );
	}

	/**
	 * Admin-post handler for the "Seed Guns 2 Ammo defaults" button.
	 * Redirects back to the settings screen with a notice + seeded count.
	 */
	public function handle_seed() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'formistic' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$seeded = self::seed();

		$back = add_query_arg( [
			'page'                     => Wpistic_Formistic_Settings::PAGE,
			'tab'                      => 'ai',
			'wpistic_formistic_notice' => 'g2a_seeded',
			'n'                        => count( $seeded ),
		], admin_url( 'admin.php' ) );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Seed every empty target option. Non-empty (operator-edited) values are
	 * left untouched.
	 *
	 * @return string[] Option names that were actually written.
	 */
	public static function seed() {
		$seeded  = [];
		$targets = [
			'wpistic_formistic_ai_faq_text'         => self::default_faq_text(),
			'wpistic_formistic_ai_kb_text'          => self::default_kb_text(),
			'wpistic_formistic_ai_auto_reply_rules' => self::default_auto_reply_rules(),
		];

		foreach ( $targets as $option => $value ) {
			$current = (string) get_option( $option, '' );
			if ( '' === trim( $current ) ) {
				update_option( $option, $value );
				$seeded[] = $option;
			}
		}

		// Subject — seed when unset OR still the generic plugin default.
		$subject_option  = 'wpistic_formistic_ai_auto_reply_subject';
		$current_subject = (string) get_option( $subject_option, '' );
		$generic_default = 'Thanks for contacting {site_name}';
		if ( '' === trim( $current_subject ) || $generic_default === trim( $current_subject ) ) {
			update_option( $subject_option, self::default_auto_reply_subject() );
			$seeded[] = $subject_option;
		}

		return $seeded;
	}

	/**
	 * Default auto-reply subject line.
	 *
	 * @return string
	 */
	public static function default_auto_reply_subject() {
		return 'Thanks for contacting {site_name} — Mesa\'s indoor range, gun store & training facility';
	}

	/**
	 * FAQ text injected into the AI knowledge context
	 * (option wpistic_formistic_ai_faq_text).
	 *
	 * @return string
	 */
	public static function default_faq_text() {
		$site = home_url( '/' );
		return <<<TEXT
Q: Where is Guns 2 Ammo located?
A: 6030 E Main St, Suite 103, Mesa, AZ 85205 (US). Phone (602) 715-2677, email sales@guns2ammo.com. Serving the East Valley since 2014.

Q: What are your hours?
A: Sunday 12pm-6pm, Monday-Thursday 10am-6pm, Friday 10am-7pm, Saturday 10am-7pm. All times are Arizona time (America/Phoenix — Arizona does not observe daylight saving).

Q: How many shooting lanes do you have?
A: 6 indoor shooting lanes. Lanes can be reserved online with secure Stripe payment and QR self check-in at {$site}

Q: How much is a membership?
A: Defender: \$29.99/month or \$299.99/year (1 person). Patriot: \$39.99/month or \$449.99/year (2 people — primary + 1 linked profile). Guardian: \$59.99/month or \$649.99/year (4 people — primary + 3 linked profiles). Every tier includes unlimited range time, online lane reservations, and waiver tracking. No contracts — cancel anytime.

Q: Can members bring guests?
A: Yes. Guest pricing per extra shooter per hour: Defender members \$15; Patriot and Guardian members \$10. Guest pricing is applied by staff at check-in.

Q: Do you offer CCW classes?
A: Yes. Arizona CCW classroom course: \$85, about 4 hours, covers Arizona laws and regulations (A.R.S. §13-3112), no live fire. Arizona CCW with live fire: \$149.99, about 5 hours.

Q: Who teaches your classes?
A: Alen Olson — lead firearms instructor, retired Mesa Police Department firearms instructor, multiple NRA certifications; specializes in Arizona CCW, defensive handgun fundamentals, safety, marksmanship, and new-shooter instruction. Nicholas Steigert — lead firearms instructor, 10+ years of experience, multiple NRA certifications, USMC combat deployments with 3rd Battalion, 7th Marines; teaches Arizona CCW, defensive handgun, tactical mindset, and beginner-to-advanced development.

Q: Do you do FFL transfers?
A: Yes — FFL transfers are \$35 per firearm. Have your seller ship to the shop; we notify you when it arrives so you can complete the background check in store.

Q: Do you handle NFA items (suppressors, SBRs)?
A: Yes. NFA transfer services are \$95 or \$295 depending on the transfer type — call (602) 715-2677 to confirm which applies to your item.

Q: How do I book a lane?
A: Book online at {$site} — pick a time, pay securely with Stripe, and check in with the QR code emailed to you. Walk-ins are welcome subject to lane availability.
TEXT;
	}

	/**
	 * Knowledge-base text injected into the AI knowledge context
	 * (option wpistic_formistic_ai_kb_text).
	 *
	 * @return string
	 */
	public static function default_kb_text() {
		$site = home_url( '/' );
		return <<<TEXT
BUSINESS: Guns 2 Ammo — Arizona Shooting Range. Mesa's most-trusted indoor shooting range, FFL firearm store, and NRA-certified training facility. In business since 2014. Google rating 4.7 stars (556 reviews).

CONTACT: 6030 E Main St, Suite 103, Mesa, AZ 85205, US. Phone: (602) 715-2677. Email: sales@guns2ammo.com. Website: {$site}

HOURS (America/Phoenix, no daylight saving): Sun 12pm-6pm; Mon-Thu 10am-6pm; Fri 10am-7pm; Sat 10am-7pm.

RANGE: 6 indoor shooting lanes. Online lane booking with Stripe payment, tier-based pricing, QR self check-in, and automated confirmation emails.

MEMBERSHIPS (no contracts, cancel anytime):
- Defender: \$29.99/mo or \$299.99/yr — 1 person. Unlimited range time, online lane reservations, waiver tracking.
- Patriot: \$39.99/mo or \$449.99/yr — 2 people (primary + 1 linked profile).
- Guardian: \$59.99/mo or \$649.99/yr — 4 people (primary + 3 linked profiles).
GUEST PRICING (per extra shooter per hour, staff-applied at check-in): Defender \$15; Patriot/Guardian \$10.

TRAINING:
- Arizona CCW classroom course: \$85, ~4 hours, Arizona laws + regulations (A.R.S. §13-3112), no live fire.
- Arizona CCW with live fire: \$149.99, ~5 hours.
- Also: defensive handgun fundamentals, marksmanship, and new-shooter instruction.

INSTRUCTORS:
- Alen Olson — lead firearms instructor. Retired Mesa Police Department firearms instructor, multiple NRA certifications. Specialties: Arizona CCW, defensive handgun fundamentals, safety, marksmanship, new-shooter instruction.
- Nicholas Steigert — lead firearms instructor. 10+ years experience, multiple NRA certifications, USMC combat deployments with 3rd Battalion, 7th Marines. Specialties: Arizona CCW, defensive handgun, tactical mindset, beginner-to-advanced development.

STORE SERVICES:
- FFL transfers: \$35 per firearm.
- NFA transfer services (suppressors, SBRs, etc.): \$95 or \$295 depending on transfer type — confirm by phone.

TONE FOR REPLIES: warm, professional, safety-first. Always include the phone number (602) 715-2677 for anything that needs scheduling or legal specifics. Never give legal advice beyond pointing to A.R.S. §13-3112 and the CCW course.
TEXT;
	}

	/**
	 * Keyword auto-reply rules (option wpistic_formistic_ai_auto_reply_rules).
	 *
	 * One rule per line, format: keyword => template. The FIRST matching line
	 * wins, so more specific intents (CCW, NFA) sit above broader ones
	 * (class, hours). Placeholders: {name}, {site_name}, {site_url}.
	 *
	 * @return string
	 */
	public static function default_auto_reply_rules() {
		$ccw        = 'Hi {name}, thanks for reaching out to {site_name}! Our Arizona CCW classroom course is $85 (about 4 hours, no live fire) and our CCW course with live fire is $149.99 (about 5 hours). Both are taught by our NRA-certified instructors — Alen Olson (retired Mesa PD firearms instructor) and Nicholas Steigert (USMC veteran, 3rd Bn 7th Marines). See upcoming dates at {site_url} or call (602) 715-2677 to reserve your seat. — The Guns 2 Ammo Team';
		$nfa        = 'Hi {name}, thanks for contacting {site_name}! We handle NFA items (suppressors, SBRs, and more) — our NFA transfer service is $95 or $295 depending on the transfer type. Call (602) 715-2677 or reply with the details of your item and we\'ll walk you through the whole process. — The Guns 2 Ammo Team';
		$ffl        = 'Hi {name}, thanks for contacting {site_name}! FFL transfers are $35 per firearm. Have your seller ship to our shop at 6030 E Main St, Suite 103, Mesa, AZ 85205 — we\'ll email you as soon as it arrives so you can complete the background check in store. Questions? Call (602) 715-2677. — The Guns 2 Ammo Team';
		$membership = 'Hi {name}, thanks for your interest in a {site_name} membership! Defender is $29.99/mo or $299.99/yr (1 person), Patriot is $39.99/mo or $449.99/yr (2 people), and Guardian is $59.99/mo or $649.99/yr (4 people). Every tier includes unlimited range time and online lane reservations — no contracts, cancel anytime. Join online at {site_url} or stop by the shop. — The Guns 2 Ammo Team';
		$booking    = 'Hi {name}, thanks for reaching out to {site_name}! You can book one of our 6 indoor lanes online at {site_url} — pick your time, pay securely, and check in with the QR code we email you. Prefer to talk to a person? Call (602) 715-2677 and we\'ll set you up. — The Guns 2 Ammo Team';
		$training   = 'Hi {name}, thanks for your interest in training at {site_name}! We run Arizona CCW courses ($85 classroom / $149.99 with live fire) plus defensive handgun, marksmanship, and new-shooter instruction with NRA-certified instructors. See the schedule at {site_url} or call (602) 715-2677. — The Guns 2 Ammo Team';
		$hours      = 'Hi {name}, thanks for contacting {site_name}! We\'re at 6030 E Main St, Suite 103, Mesa, AZ 85205 — open Sun 12pm-6pm, Mon-Thu 10am-6pm, and Fri-Sat 10am-7pm (Arizona time). Call (602) 715-2677 if you need anything before your visit. — The Guns 2 Ammo Team';

		$rules = [
			'ccw => '            . $ccw,
			'concealed => '      . $ccw,
			'suppressor => '     . $nfa,
			'nfa => '            . $nfa,
			'sbr => '            . $nfa,
			'ffl => '            . $ffl,
			'transfer => '       . $ffl,
			'membership => '     . $membership,
			'member => '         . $membership,
			'lane => '           . $booking,
			'reservation => '    . $booking,
			'booking => '        . $booking,
			'book a lane => '    . $booking,
			'class => '          . $training,
			'course => '         . $training,
			'training => '       . $training,
			'lesson => '         . $training,
			'hours => '          . $hours,
			'location => '       . $hours,
			'address => '        . $hours,
			'directions => '     . $hours,
		];
		return implode( "\n", $rules );
	}
}
