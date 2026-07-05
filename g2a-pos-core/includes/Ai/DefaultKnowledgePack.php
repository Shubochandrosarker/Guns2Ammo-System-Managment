<?php

namespace G2A\POS\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The curated, verified Guns 2 Ammo knowledge pack.
 *
 * Every document below is written as clear factual prose an LLM can quote
 * from directly, sourced from the verified business facts (theme
 * business-info/llms modules, CCW course materials, instructor bios and the
 * booking-engine workflow docs). Ingested via
 * WebsiteKnowledgeSeeder::seed_default_pack() with source_type
 * `default_pack` and stable labels — the content-hash upsert in
 * BrainService makes re-seeding idempotent.
 */
final class DefaultKnowledgePack {

	public const SOURCE_TYPE = 'default_pack';
	public const TAG         = 'g2a-default-pack';

	/**
	 * @return array<int,array{label:string,body:string,tags:string,scope:string}>
	 */
	public static function documents(): array {
		return array(
			array(
				'label' => 'Guns 2 Ammo — business identity, location and hours',
				'tags'  => self::TAG . ',identity,hours,contact',
				'scope' => 'public',
				'body'  =>
					"Guns 2 Ammo is an Arizona shooting range: Mesa's most-trusted indoor shooting range, "
					. "FFL-licensed firearm store, and NRA-certified training facility, serving the East Valley since 2014.\n"
					. "Address: 6030 E Main St, Suite 103, Mesa, AZ 85205, US.\n"
					. "Phone: (602) 715-2677. Email: sales@guns2ammo.com.\n"
					. "Founded: 2014. Customer rating: 4.7 stars from 556 Google reviews.\n"
					. "Hours (Arizona time, America/Phoenix — no daylight saving): "
					. "Sunday 12pm–6pm; Monday–Thursday 10am–6pm; Friday 10am–7pm; Saturday 10am–7pm.\n"
					. 'The facility has 6 indoor shooting lanes. Walk-ins are welcome and lanes can be booked online.',
			),
			array(
				'label' => 'Guns 2 Ammo — membership plans and guest pricing',
				'tags'  => self::TAG . ',memberships,pricing',
				'scope' => 'public',
				'body'  =>
					"Guns 2 Ammo offers three range membership plans. All plans have no contracts — cancel anytime.\n"
					. "Defender: \$29.99 per month or \$299.99 per year. Covers 1 person. Includes unlimited range time, "
					. "online lane reservations, and waiver tracking.\n"
					. "Patriot: \$39.99 per month or \$449.99 per year. Covers 2 people (the primary member plus 1 linked profile).\n"
					. "Guardian: \$59.99 per month or \$649.99 per year. Covers 4 people (the primary member plus 3 linked profiles).\n"
					. "Guest pricing for extra shooters on a member's lane (applied by staff at check-in): "
					. "Defender members pay \$15 per extra shooter per hour; Patriot and Guardian members pay \$10 per extra shooter per hour.\n"
					. 'An Instructor Membership at $49.99 per month is planned but not yet available.',
			),
			array(
				'label' => 'Guns 2 Ammo — training classes, CCW and transfer pricing',
				'tags'  => self::TAG . ',training,ccw,transfers,pricing',
				'scope' => 'public',
				'body'  =>
					"Guns 2 Ammo is an NRA-certified training facility offering concealed-carry and firearms training.\n"
					. "Arizona CCW classroom course: \$85, approximately 4 hours. Covers Arizona firearm laws and regulations "
					. "(A.R.S. §13-3112); classroom only, no live fire.\n"
					. "Arizona CCW with live fire: \$149.99, approximately 5 hours. The classroom course plus a live-fire "
					. "range session.\n"
					. "FFL firearm transfers: \$35 per firearm.\n"
					. 'NFA transfers: $95 for suppressors/SBR items and $295 for full-auto items.',
			),
			array(
				'label' => 'Guns 2 Ammo — firearms instructors',
				'tags'  => self::TAG . ',training,instructors',
				'scope' => 'public',
				'body'  =>
					"Guns 2 Ammo's firearms training is led by two lead instructors.\n"
					. "Alen Olson is a lead firearms instructor at Guns 2 Ammo. He is a retired Mesa Police Department "
					. "firearms instructor and holds multiple NRA certifications. He specializes in the Arizona CCW course, "
					. "defensive handgun fundamentals, firearm safety, marksmanship, and new-shooter instruction.\n"
					. "Nicholas Steigert is a lead firearms instructor at Guns 2 Ammo with more than 10 years of instructing "
					. "experience and multiple NRA certifications. He is a US Marine Corps veteran with combat deployments "
					. "with 3rd Battalion, 7th Marines. He teaches the Arizona CCW course and defensive handgun, focuses on "
					. 'tactical mindset, and develops shooters from beginner through advanced levels.',
			),
			array(
				'label' => 'Guns 2 Ammo — facility, lane booking and check-in',
				'tags'  => self::TAG . ',facility,booking',
				'scope' => 'public',
				'body'  =>
					"The Guns 2 Ammo facility has 6 indoor shooting lanes.\n"
					. "Lanes can be reserved online through the booking system, which takes secure card payments via Stripe "
					. "and applies membership-tier pricing automatically.\n"
					. "On arrival, customers self check-in by scanning the QR code on their booking confirmation.\n"
					. 'Booking confirmations, reminders, and follow-ups are emailed automatically.',
			),
		);
	}
}
