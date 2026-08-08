<?php

namespace G2A\POS\Support;

/**
 * Thin logging facade so call sites stop reaching for error_log() directly.
 * Allows future swap to PSR-3 / Monolog without touching call sites.
 */
final class Logger {

	public const LEVEL_DEBUG   = 'debug';
	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	public static function debug( string $message, array $context = array() ): void {
		self::log( self::LEVEL_DEBUG, $message, $context );
	}

	public static function info( string $message, array $context = array() ): void {
		self::log( self::LEVEL_INFO, $message, $context );
	}

	public static function warning( string $message, array $context = array() ): void {
		self::log( self::LEVEL_WARNING, $message, $context );
	}

	public static function error( string $message, array $context = array() ): void {
		self::log( self::LEVEL_ERROR, $message, $context );
	}

	public static function exception( string $prefix, \Throwable $e, array $context = array() ): void {
		$context['exception_class'] = get_class( $e );
		$context['file']            = $e->getFile();
		$context['line']            = $e->getLine();
		self::log( self::LEVEL_ERROR, $prefix . ': ' . $e->getMessage(), $context );
	}

	private static function log( string $level, string $message, array $context ): void {
		if ( function_exists( 'apply_filters' ) ) {
			$handled = (bool) apply_filters( 'g2a_pos_logger_handled', false, $level, $message, $context );
			if ( $handled ) {
				return;
			}
		}

		$line = '[G2A POS][' . strtoupper( $level ) . '] ' . $message;
		if ( $context ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context ) : json_encode( $context );
			if ( is_string( $encoded ) && $encoded !== '' && $encoded !== '[]' ) {
				$line .= ' ' . $encoded;
			}
		}

		if ( function_exists( 'error_log' ) ) {
			error_log( $line );
		}
	}
}
