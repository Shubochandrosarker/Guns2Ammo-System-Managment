<?php

namespace G2A\POS\Wholesalers\Promotion;

use G2A\POS\Database\MapRuleRepository;
use G2A\POS\Database\WholesalerCategoryRepository;
use G2A\POS\Database\WholesalerProductRepository;
use G2A\POS\Database\WholesalerRepository;
use G2A\POS\Wholesalers\Media\LipseysImageUrls;
use G2A\POS\Wholesalers\Media\VendorImageMirror;

/**
 * One-shot promotion of a vendor-catalog SKU into a real WooCommerce product.
 *
 *  - Resolves the vendor row from g2a_wholesaler_products.
 *  - Creates (or updates) the WC product post + postmeta.
 *  - Pulls per-category markup from g2a_wholesaler_categories (or accepts an
 *    explicit override) to compute the sell price from dealer cost.
 *  - Mirrors the vendor CDN image into the media library and pins it as the
 *    featured image.
 *  - Captures the manufacturer's MAP as a g2a_map_rules row so the
 *    storefront's click-to-reveal honors it from the first impression.
 *  - Backfills wc_product_id on the vendor row so the link survives reimports.
 *
 * Idempotent: a re-promote of the same vendor SKU updates the existing WC
 * product, refreshes prices/stock, and re-uses the existing attachment.
 */
final class VendorProductPromoter {

	public static function promote( int $wholesalerId, string $vendorSku, array $options = array() ): array {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			return array(
				'ok'    => false,
				'error' => 'wordpress_required',
			);
		}

		$productRepo = new WholesalerProductRepository();
		$vendor      = $productRepo->find( $wholesalerId, $vendorSku );
		if ( ! $vendor ) {
			return array(
				'ok'    => false,
				'error' => 'vendor_product_not_found',
			);
		}

		$wholesalerRepo = new WholesalerRepository();
		$wholesaler     = $wholesalerRepo->find( $wholesalerId );
		if ( ! $wholesaler ) {
			return array(
				'ok'    => false,
				'error' => 'wholesaler_not_found',
			);
		}

		$categoryRepo = new WholesalerCategoryRepository();
		$categories   = $categoryRepo->all( $wholesalerId );
		$markupPct    = self::resolveMarkup( $vendor, $categories, $options );

		$cost      = self::asFloat( $vendor['current_price'] ?? null ) ?? self::asFloat( $vendor['wholesale_price'] ?? null );
		$msrp      = self::asFloat( $vendor['msrp'] ?? null );
		$sellPrice = self::sellPrice( $cost, $msrp, $markupPct, isset( $options['sell_price'] ) ? (float) $options['sell_price'] : null );

		$existingId = (int) ( $vendor['wc_product_id'] ?? 0 );
		$postType   = function_exists( 'wc_get_product' ) ? 'product' : 'g2a_product';
		$postArr    = array(
			'post_title'   => (string) ( $vendor['name'] ?: $vendorSku ),
			'post_content' => (string) ( $vendor['description'] ?? '' ),
			'post_status'  => ! empty( $options['publish'] ) ? 'publish' : 'draft',
			'post_type'    => $postType,
		);
		if ( $existingId > 0 ) {
			$postArr['ID'] = $existingId;
			$productId     = wp_update_post( $postArr, true );
		} else {
			$productId = wp_insert_post( $postArr, true );
		}
		if ( is_wp_error( $productId ) || ! $productId ) {
			return array(
				'ok'     => false,
				'error'  => 'wp_insert_failed',
				'detail' => is_wp_error( $productId ) ? $productId->get_error_message() : 'unknown',
			);
		}
		$productId = (int) $productId;

		$sku          = (string) ( $vendor['mfg_part'] ?: $vendor['vendor_sku'] );
		$regularPrice = $msrp !== null ? $msrp : ( $sellPrice ?? 0 );
		$stockQty     = (int) ( $vendor['stock_qty'] ?? 0 );

		$meta = array(
			'_sku'                   => $sku,
			'_regular_price'         => $regularPrice !== null ? (string) $regularPrice : '',
			'_sale_price'            => $sellPrice !== null && $sellPrice < ( $regularPrice ?? PHP_FLOAT_MAX ) ? (string) $sellPrice : '',
			'_price'                 => $sellPrice !== null ? (string) $sellPrice : ( (string) ( $regularPrice ?? '' ) ),
			'_manage_stock'          => 'yes',
			'_stock'                 => (string) $stockQty,
			'_stock_status'          => $stockQty > 0 ? 'instock' : 'outofstock',
			'_wc_cog_cost'           => $cost !== null ? (string) $cost : '',
			'_g2a_vendor_source'     => (string) $wholesaler['provider_code'],
			'_g2a_vendor_sku'        => $vendorSku,
			'_g2a_manufacturer'      => (string) ( $vendor['manufacturer'] ?? '' ),
			'_g2a_model'             => (string) ( $vendor['model'] ?? '' ),
			'_g2a_upc'               => (string) ( $vendor['upc'] ?? '' ),
			'_g2a_caliber'           => (string) ( $vendor['caliber'] ?? '' ),
			'_g2a_item_type'         => (string) ( $vendor['item_type'] ?? '' ),
			'_g2a_country_of_origin' => (string) ( $vendor['country_of_origin'] ?? '' ),
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $productId, $k, $v );
		}

		$attrs = json_decode( (string) ( $vendor['attributes'] ?? '' ), true );
		if ( is_array( $attrs ) ) {
			foreach ( $attrs as $k => $v ) {
				update_post_meta( $productId, '_g2a_attr_' . sanitize_key( (string) $k ), (string) $v );
			}
		}

		// Image (best-effort).
		$image = self::mirrorImage( (string) ( $wholesaler['provider_code'] ?? '' ), (string) ( $vendor['image_filename'] ?? '' ), $productId, $vendorSku );

		// MAP rule (best-effort).
		$mapRuleId = null;
		$mapPrice  = self::asFloat( $vendor['map_price'] ?? null );
		if ( $mapPrice !== null && $mapPrice > 0 ) {
			$mapRuleId = ( new MapRuleRepository() )->setForWcProduct(
				$productId,
				array(
					'map_price'    => $mapPrice,
					'source'       => 'vendor_promotion',
					'display_mode' => 'click_to_reveal',
					'notes'        => sprintf( 'Promoted from %s SKU %s', $wholesaler['provider_code'], $vendorSku ),
				)
			);
		}

		$productRepo->linkToWoo( (int) $vendor['id'], $productId );

		return array(
			'ok'            => true,
			'wc_product_id' => $productId,
			'created'       => $existingId === 0,
			'sku'           => $sku,
			'regular_price' => $regularPrice,
			'sale_price'    => $sellPrice,
			'stock_qty'     => $stockQty,
			'map_rule_id'   => $mapRuleId,
			'image'         => $image,
			'markup_pct'    => $markupPct,
		);
	}

	private static function resolveMarkup( array $vendor, array $categories, array $options ): ?float {
		if ( isset( $options['markup_pct'] ) ) {
			return (float) $options['markup_pct'];
		}
		$vendorCategory = (string) ( $vendor['vendor_category'] ?? '' );
		foreach ( $categories as $cat ) {
			if ( ( $cat['vendor_category'] ?? '' ) === $vendorCategory && $cat['markup_percent'] !== null && $cat['markup_percent'] !== '' ) {
				return (float) $cat['markup_percent'];
			}
		}
		return null;
	}

	private static function sellPrice( ?float $cost, ?float $msrp, ?float $markupPct, ?float $override ): ?float {
		if ( $override !== null && $override > 0 ) {
			return round( $override, 2 );
		}
		if ( $cost !== null && $markupPct !== null && $markupPct > 0 ) {
			return round( $cost * ( 1 + $markupPct / 100 ), 2 );
		}
		if ( $msrp !== null ) {
			return $msrp;
		}
		return $cost;
	}

	private static function mirrorImage( string $providerCode, string $imageFilename, int $productId, string $vendorSku ): ?array {
		if ( $imageFilename === '' ) {
			return null;
		}
		$url = match ( $providerCode ) {
			'lipseys' => LipseysImageUrls::cdnUrl( $imageFilename ),
			default => null,
		};
		if ( ! $url ) {
			return null;
		}
		return VendorImageMirror::mirror(
			$url,
			array(
				'vendor_sku'    => $vendorSku,
				'wc_product_id' => $productId,
				'set_featured'  => true,
			)
		);
	}

	private static function asFloat( $v ): ?float {
		if ( $v === null || $v === '' ) {
			return null;
		}
		$f = is_numeric( $v ) ? (float) $v : null;
		return $f !== null && $f > 0 ? $f : null;
	}
}
