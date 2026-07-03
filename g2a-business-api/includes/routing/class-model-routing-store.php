<?php
/**
 * Model routing map — which model handles which purpose.
 *
 * The dashboard's AIModels page renders this as "Deep business analysis →
 * Claude Opus 4.7", "SEO analysis → Claude Opus 4.7", etc. The map is
 * persistent, editable through the API, and callable from server-side code
 * that wants to pick the right model for a given job.
 *
 * Purposes are an allow-list so the frontend can't invent bogus routes
 * that never get consumed. Model ids are validated at write time against
 * the g2aba_models option so a stale routing entry cannot slip through.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Routing_Store {
	private const OPTION = 'g2aba_model_routing';

	public const PURPOSES = array(
		'business_analysis',
		'seo_analysis',
		'booking_suggest',
		'support_classify',
		'email_drafts',
		'daily_summaries',
		'private_inventory',
	);

	/**
	 * Human-readable labels for the UI. Not the source of truth — that's
	 * PURPOSES above; this is just what the dashboard renders.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'business_analysis' => 'Deep business analysis',
			'seo_analysis'      => 'SEO analysis',
			'booking_suggest'   => 'Booking suggestions',
			'support_classify'  => 'Customer support classify',
			'email_drafts'      => 'Email drafts',
			'daily_summaries'   => 'Cheap daily summaries',
			'private_inventory' => 'Private inventory',
		);
	}

	/**
	 * @return array<string,string|null> Map from purpose → model id (or null if unset).
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::PURPOSES as $purpose ) {
			$out[ $purpose ] = isset( $raw[ $purpose ] ) && is_string( $raw[ $purpose ] ) && '' !== $raw[ $purpose ]
				? $raw[ $purpose ]
				: null;
		}
		return $out;
	}

	/**
	 * Bulk update. Unknown purposes are dropped; unknown model ids are
	 * rejected (returns an error string).
	 *
	 * @param array<string,string|null> $patch
	 * @return array{ok:true, routing:array<string,string|null>}|array{ok:false, error:string}
	 */
	public function update( array $patch ): array {
		$known_models = self::known_model_ids();
		$current      = $this->all();

		foreach ( $patch as $purpose => $model_id ) {
			if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
				continue;
			}
			if ( null === $model_id || '' === $model_id ) {
				$current[ $purpose ] = null;
				continue;
			}
			$model_id = (string) $model_id;
			if ( ! in_array( $model_id, $known_models, true ) ) {
				return array(
					'ok'    => false,
					'error' => sprintf( 'Unknown model id: %s', $model_id ),
				);
			}
			$current[ $purpose ] = $model_id;
		}
		update_option( self::OPTION, $current, false );
		return array( 'ok' => true, 'routing' => $current );
	}

	public function get_for( string $purpose ): ?string {
		$all = $this->all();
		return $all[ $purpose ] ?? null;
	}

	/**
	 * @return array<int,string>
	 */
	private static function known_model_ids(): array {
		$raw = get_option( 'g2aba_models', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_map( 'strval', array_keys( $raw ) );
	}
}
