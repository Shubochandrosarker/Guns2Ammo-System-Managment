<?php

namespace G2A\POS\Compliance\ATF;

use G2A\POS\Database\Form4473Repository;

/**
 * Single-form 4473 export in the XML shape accepted by the ATF eForms
 * 4473 import tool. Schema is intentionally flat — eForms validates
 * against its own XSD; we surface what we have and let eForms reject
 * incomplete records on upload.
 */
final class Form4473Xml {

	public static function for_form( int $form_id ): ?string {
		$row = ( new Form4473Repository() )->find( $form_id );
		if ( ! $row ) {
			return null;
		}
		$firearms = is_string( $row['firearms'] ?? null ) ? json_decode( (string) $row['firearms'], true ) : array();

		$xml = new \SimpleXMLElement( '<?xml version="1.0" encoding="UTF-8"?><Form4473/>' );
		$xml->addAttribute( 'FormSerial', (string) ( $row['form_serial'] ?? '' ) );
		$xml->addAttribute( 'FormatVersion', 'ATF-eForms-4473-v1' );
		$xml->addAttribute( 'TransactionStatus', (string) ( $row['transaction_status'] ?? '' ) );

		$t = $xml->addChild( 'Transferee' );
		$t->addChild( 'LastName', htmlspecialchars( (string) ( $row['transferee_last'] ?? '' ) ) );
		$t->addChild( 'FirstName', htmlspecialchars( (string) ( $row['transferee_first'] ?? '' ) ) );
		if ( ! empty( $row['transferee_middle'] ) ) {
			$t->addChild( 'MiddleName', htmlspecialchars( $row['transferee_middle'] ) );
		}
		$t->addChild( 'DateOfBirth', (string) ( $row['transferee_dob'] ?? '' ) );
		$t->addChild( 'Address', htmlspecialchars( (string) ( $row['transferee_address'] ?? '' ) ) );
		$t->addChild( 'City', htmlspecialchars( (string) ( $row['transferee_city'] ?? '' ) ) );
		$t->addChild( 'State', (string) ( $row['transferee_state'] ?? '' ) );
		$t->addChild( 'Zip', (string) ( $row['transferee_zip'] ?? '' ) );
		if ( ! empty( $row['transferee_county'] ) ) {
			$t->addChild( 'County', htmlspecialchars( $row['transferee_county'] ) );
		}
		if ( ! empty( $row['transferee_phone'] ) ) {
			$t->addChild( 'Phone', htmlspecialchars( $row['transferee_phone'] ) );
		}

		$id = $xml->addChild( 'Identification' );
		$id->addChild( 'IdType', (string) ( $row['id_type'] ?? '' ) );
		$id->addChild( 'IdNumber', htmlspecialchars( (string) ( $row['id_number'] ?? '' ) ) );
		$id->addChild( 'Issuer', htmlspecialchars( (string) ( $row['id_issuer'] ?? '' ) ) );
		if ( ! empty( $row['id_expiration'] ) ) {
			$id->addChild( 'Expires', (string) $row['id_expiration'] );
		}

		$cit = $xml->addChild( 'Citizenship' );
		$cit->addChild( 'IsUSCitizen', ! empty( $row['us_citizen'] ) ? '1' : '0' );
		if ( ! empty( $row['country_of_citizenship'] ) ) {
			$cit->addChild( 'Country', htmlspecialchars( $row['country_of_citizenship'] ) );
		}
		if ( ! empty( $row['uscis_number'] ) ) {
			$cit->addChild( 'USCISNumber', htmlspecialchars( $row['uscis_number'] ) );
		}

		$firearms_node = $xml->addChild( 'Firearms' );
		if ( is_array( $firearms ) ) {
			foreach ( $firearms as $f ) {
				$fn = $firearms_node->addChild( 'Firearm' );
				$fn->addChild( 'Manufacturer', htmlspecialchars( (string) ( $f['manufacturer'] ?? '' ) ) );
				$fn->addChild( 'Importer', htmlspecialchars( (string) ( $f['importer'] ?? '' ) ) );
				$fn->addChild( 'Model', htmlspecialchars( (string) ( $f['model'] ?? '' ) ) );
				$fn->addChild( 'SerialNumber', htmlspecialchars( (string) ( $f['serial_number'] ?? '' ) ) );
				$fn->addChild( 'Type', htmlspecialchars( (string) ( $f['firearm_type'] ?? '' ) ) );
				$fn->addChild( 'Caliber', htmlspecialchars( (string) ( $f['caliber'] ?? '' ) ) );
			}
		}

		$nics = $xml->addChild( 'NICS' );
		$nics->addChild( 'NTN', (string) ( $row['nics_ntn'] ?? '' ) );
		$nics->addChild( 'Response', (string) ( $row['nics_response'] ?? '' ) );
		if ( ! empty( $row['nics_response_date'] ) ) {
			$nics->addChild( 'ResponseDate', $row['nics_response_date'] );
		}

		$signs = $xml->addChild( 'Certifications' );
		$signs->addChild( 'TransfereeSignedAt', (string) ( $row['transferee_signature_at'] ?? '' ) );
		$signs->addChild( 'TransferorSignedAt', (string) ( $row['transferor_signature_at'] ?? '' ) );
		if ( ! empty( $row['transferred_at'] ) ) {
			$signs->addChild( 'TransferredAt', $row['transferred_at'] );
		}

		$dom                     = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput       = true;
		$dom->loadXML( $xml->asXML() );
		return (string) $dom->saveXML();
	}
}
