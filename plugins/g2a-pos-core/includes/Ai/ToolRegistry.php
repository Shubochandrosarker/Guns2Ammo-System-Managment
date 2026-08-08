<?php

namespace G2A\POS\Ai;

/**
 * Typed tool registry. Every tool the agent can call is registered here
 * with: JSON-schema arguments, a capability the caller must hold, an
 * `action_policy` (silent | preview | confirm), and a callable.
 *
 * Tools are loaded from G2A\POS\Ai\Tools\* at boot. To add a tool,
 * drop a new class with a static `definition()` and a static `run($args)`,
 * then register it in `ToolRegistry::boot()`.
 */
final class ToolRegistry {

	/** @var array<string, array{spec: array, callable: callable, capability: string, policy: string}> */
	private static array $tools = array();

	public static function register( string $name, array $spec, callable $handler, string $capability, string $policy = 'silent' ): void {
		self::$tools[ $name ] = array(
			'spec'       => $spec,
			'callable'   => $handler,
			'capability' => $capability,
			'policy'     => $policy,
		);
	}

	public static function has( string $name ): bool {
		return isset( self::$tools[ $name ] ); }

	public static function policy_for( string $name ): string {
		return self::$tools[ $name ]['policy'] ?? 'confirm';
	}

	public static function capability_for( string $name ): string {
		return self::$tools[ $name ]['capability'] ?? 'g2a_pos_access';
	}

	public static function specs(): array {
		$out = array();
		foreach ( self::$tools as $name => $entry ) {
			$out[] = array(
				'type'     => 'function',
				'function' => array_merge( array( 'name' => $name ), $entry['spec'] ),
			);
		}
		return $out;
	}

	public static function execute( string $name, array $args ): array {
		if ( ! isset( self::$tools[ $name ] ) ) {
			return array(
				'ok'    => false,
				'error' => 'unknown_tool',
				'tool'  => $name,
			);
		}
		$cap = self::$tools[ $name ]['capability'];
		if ( $cap !== 'public' && ! current_user_can( $cap ) ) {
			return array(
				'ok'                  => false,
				'error'               => 'forbidden',
				'required_capability' => $cap,
			);
		}
		try {
			$result = call_user_func( self::$tools[ $name ]['callable'], $args );
			if ( ! is_array( $result ) ) {
				return array(
					'ok'     => true,
					'result' => $result,
				);
			}
			if ( ! array_key_exists( 'ok', $result ) ) {
				$result['ok'] = true;
			}
			return $result;
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
				'tool'  => $name,
			);
		}
	}

	public static function boot(): void {
		Tools\InventoryFindTool::register();
		Tools\CustomerLookupTool::register();
		Tools\BrainSearchTool::register();
		Tools\ReportsSalesTool::register();
		Tools\CartEmailInvoiceTool::register();
		Tools\BoundBookSearchTool::register();
		Tools\RangeWaiverLookupTool::register();
		Tools\AgentLearnTool::register();
		Tools\CatalogMatchTool::register();
		Tools\MembershipLookupTool::register();
		Tools\BookingLookupTool::register();
	}
}
