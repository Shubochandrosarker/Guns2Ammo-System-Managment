<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\ATF\Form4473Pdf;
use WP_REST_Request;

/**
 * Upload the official ATF 5300.9 PDF, list / save the 4473 field-coordinate
 * map. Lets a manager tune coordinates from the dashboard without SSHing in.
 */
final class Form4473CalibrationController {

	public static function status( WP_REST_Request $req ) {
		return array(
			'template_installed' => self::template_exists(),
			'template_path'      => self::template_path(),
			'fields_path'        => self::fields_path(),
			'overlay_mode'       => Form4473Pdf::template_available(),
		);
	}

	public static function upload_template( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		$bin     = null;
		if ( ! empty( $payload['pdf_b64'] ) ) {
			$bin = base64_decode( (string) $payload['pdf_b64'], true );
		} else {
			$files = $req->get_file_params();
			if ( ! empty( $files['pdf']['tmp_name'] ) ) {
				$bin = (string) @file_get_contents( (string) $files['pdf']['tmp_name'] );
			}
		}
		if ( ! $bin ) {
			return new \WP_Error( 'no_pdf', 'Provide pdf (multipart) or pdf_b64.', array( 'status' => 400 ) );
		}
		if ( strncmp( $bin, '%PDF-', 5 ) !== 0 ) {
			return new \WP_Error( 'not_pdf', 'Uploaded bytes do not look like a PDF.', array( 'status' => 422 ) );
		}
		$dir = dirname( self::template_path() );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'mkdir', 'Could not create templates directory.', array( 'status' => 500 ) );
		}
		if ( file_put_contents( self::template_path(), $bin ) === false ) {
			return new \WP_Error( 'write', 'Could not write template file.', array( 'status' => 500 ) );
		}
		return array(
			'ok'            => true,
			'bytes'         => strlen( $bin ),
			'template_path' => self::template_path(),
		);
	}

	public static function get_fields( WP_REST_Request $req ) {
		$path = self::fields_path();
		if ( ! is_readable( $path ) ) {
			$example = self::fields_example_path();
			if ( is_readable( $example ) ) {
				return array(
					'fields' => self::decode_fields( $example ),
					'source' => 'example',
				);
			}
			return array(
				'fields' => array(),
				'source' => 'empty',
			);
		}
		return array(
			'fields' => self::decode_fields( $path ),
			'source' => 'live',
		);
	}

	public static function save_fields( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		$fields  = $payload['fields'] ?? null;
		if ( ! is_array( $fields ) ) {
			return new \WP_Error( 'bad_fields', 'fields must be an object map of key → coords.', array( 'status' => 400 ) );
		}
		$clean = array();
		foreach ( $fields as $key => $coord ) {
			if ( ! is_string( $key ) || ! is_array( $coord ) ) {
				continue;
			}
			if ( ! isset( $coord['x'], $coord['y'] ) ) {
				continue;
			}
			$clean[ sanitize_text_field( $key ) ] = array(
				'page'   => (int) ( $coord['page'] ?? 1 ),
				'x'      => (float) $coord['x'],
				'y'      => (float) $coord['y'],
				'size'   => isset( $coord['size'] ) ? (float) $coord['size'] : 10,
				'font'   => isset( $coord['font'] ) ? sanitize_text_field( (string) $coord['font'] ) : 'Helvetica',
				'style'  => isset( $coord['style'] ) ? sanitize_text_field( (string) $coord['style'] ) : '',
				'align'  => isset( $coord['align'] ) ? sanitize_text_field( (string) $coord['align'] ) : 'L',
				'width'  => isset( $coord['width'] ) ? (float) $coord['width'] : null,
				'height' => isset( $coord['height'] ) ? (float) $coord['height'] : null,
			);
		}
		$path = self::fields_path();
		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			return new \WP_Error( 'mkdir', 'Could not create templates directory.', array( 'status' => 500 ) );
		}
		if ( file_put_contents( $path, wp_json_encode( $clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) === false ) {
			return new \WP_Error( 'write', 'Could not write fields file.', array( 'status' => 500 ) );
		}
		return array(
			'ok'     => true,
			'fields' => $clean,
		);
	}

	private static function template_exists(): bool {
		return is_readable( self::template_path() );
	}

	private static function template_path(): string {
		return G2A_POS_CORE_PATH . 'assets/templates/4473.pdf';
	}

	private static function fields_path(): string {
		return G2A_POS_CORE_PATH . 'assets/templates/4473-fields.json';
	}

	private static function fields_example_path(): string {
		return G2A_POS_CORE_PATH . 'assets/templates/4473-fields.example.json';
	}

	private static function decode_fields( string $path ): array {
		$raw     = (string) @file_get_contents( $path );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		// Strip readme keys that start with underscore.
		return array_filter( $decoded, static fn( $_, $k ) => is_string( $k ) && ! str_starts_with( $k, '_' ), ARRAY_FILTER_USE_BOTH );
	}
}
