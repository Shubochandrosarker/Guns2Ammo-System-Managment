<?php

namespace G2A\POS\Compliance\State\Connectors;

final class ConnectorRegistry {

	private static array $instances = array();

	public static function for( string $state_code ): Connector {
		$code = strtoupper( substr( $state_code, 0, 2 ) );
		if ( isset( self::$instances[ $code ] ) ) {
			return self::$instances[ $code ];
		}
		$impl = match ( $code ) {
			'CA' => new CaliforniaDros(),
			'IL' => new NullConnector( 'IL', 'IL PICS' ),
			'NY' => new NullConnector( 'NY', 'NY NICS-IS' ),
			'PA' => new NullConnector( 'PA', 'PA PICS' ),
			'OR' => new NullConnector( 'OR', 'OSP FICS' ),
			'TN' => new NullConnector( 'TN', 'TN TICS' ),
			'UT' => new NullConnector( 'UT', 'UT BCI' ),
			'VA' => new NullConnector( 'VA', 'VA VSP' ),
			'CT' => new NullConnector( 'CT', 'CT DESPP' ),
			default => new NullConnector( $code ),
		};
		self::$instances[ $code ] = $impl;
		return $impl;
	}
}
