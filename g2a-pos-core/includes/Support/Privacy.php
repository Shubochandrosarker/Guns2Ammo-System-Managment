<?php

namespace G2A\POS\Support;

final class Privacy {

	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'registerExporter' ) );
		add_action( 'admin_init', array( self::class, 'addPolicyText' ) );
	}

	public static function registerExporter( array $exporters ): array {
		$exporters['g2a-pos-core'] = array(
			'exporter_friendly_name' => __( 'G2A POS records', 'g2a-pos-core' ),
			'callback'               => array( self::class, 'exportPersonalData' ),
		);
		return $exporters;
	}

	public static function exportPersonalData( string $email, int $page = 1 ): array {
		global $wpdb;
		$data        = array();
		$waiverTable = $wpdb->prefix . 'g2a_range_waivers';
		$tableExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $waiverTable ) );
		if ( $tableExists === $waiverTable ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$waiverTable} WHERE email = %s ORDER BY id ASC LIMIT 100 OFFSET %d",
					sanitize_email( $email ),
					max( 0, ( $page - 1 ) * 100 )
				),
				ARRAY_A
			) ?: array();
			foreach ( $rows as $row ) {
				unset( $row['questions'], $row['check_ins'] );
				$data[] = array(
					'group_id'    => 'g2a-pos-waivers',
					'group_label' => __( 'Range waivers', 'g2a-pos-core' ),
					'item_id'     => 'waiver-' . (int) $row['id'],
					'data'        => array_map(
						static fn( $key, $value ) => array(
							'name'  => ucwords( str_replace( '_', ' ', (string) $key ) ),
							'value' => is_scalar( $value ) || $value === null ? (string) $value : wp_json_encode( $value ),
						),
						array_keys( $row ),
						array_values( $row )
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $data ) < 100,
		);
	}

	public static function addPolicyText(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'G2A POS Core',
			wp_kses_post(
				'<p>G2A POS Core stores transaction, inventory, range-waiver, compliance, and regulated firearm records. '
				. 'Some records may be subject to mandatory legal retention and cannot be erased on request. Access should be limited by role, and exports or erasure decisions should be reviewed by the organization’s compliance officer.</p>'
			)
		);
	}
}
