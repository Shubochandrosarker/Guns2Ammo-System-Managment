<?php

namespace G2A\POS\Ai;

/**
 * Decides whether a tool call runs silently, shows a preview, or
 * requires explicit human approval before firing. Anything that
 * transmits to a third party (email, SMS, NICS), changes money, or
 * touches the bound book / 4473 / NFA registry is `confirm`. Reads
 * are `silent`. Drafts (invoice render, receipt render) are `preview`.
 *
 * The merchant can override by setting `g2a_pos_ai_policy_overrides`
 * (option) — a map of `tool_name => silent|preview|confirm`.
 */
final class ActionPolicy {

	public const SILENT  = 'silent';
	public const PREVIEW = 'preview';
	public const CONFIRM = 'confirm';

	public static function for( string $tool_name ): string {
		$overrides = (array) get_option( 'g2a_pos_ai_policy_overrides', array() );
		if ( isset( $overrides[ $tool_name ] ) ) {
			return $overrides[ $tool_name ];
		}
		return ToolRegistry::policy_for( $tool_name );
	}

	public static function requires_confirmation( string $tool_name ): bool {
		return self::for( $tool_name ) === self::CONFIRM;
	}
}
