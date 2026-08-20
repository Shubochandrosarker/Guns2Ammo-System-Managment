<?php
/**
 * Single-product page — summary layout, gallery data and tab restructuring.
 *
 * Everything that shapes the WooCommerce single-product screen lives here so
 * the page has ONE owner instead of the four overlapping generations of
 * overrides that used to be scattered across tokens.css / app.css /
 * wc-fixes.css. The matching presentation layer is assets/css/single-product.css
 * (enqueued only on product pages) and assets/js/single-product.js.
 *
 * Layout produced (matches the approved design):
 *   breadcrumb
 *   ├── gallery      vertical thumbnail rail + main stage + zoom
 *   └── summary      title · brand/SKU · price · stock badges · qty + cart
 *                    · wishlist · trust row
 *   tabs             vertical rail: Description / Specifications / FAQs /
 *                    Shipping & Returns / Reviews
 *   related          4-up card grid
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==================================================================
 * PRODUCT FACTS
 * Small helpers the template parts, the gallery and the tabs all read
 * from, so a fact is derived in exactly one place.
 * ================================================================== */

/**
 * The product for the current single-product request.
 *
 * @return WC_Product|null
 */
function g2a_sp_product() {
	global $product;
	if ( $product instanceof WC_Product ) {
		return $product;
	}
	$resolved = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
	return $resolved instanceof WC_Product ? $resolved : null;
}

/**
 * Whether the product is a firearm that must move through an FFL transfer.
 *
 * Reads the same `_wpistic_ffl_required` meta the Advanced FFL Checkout
 * plugin writes and inc/woocommerce.php's Product schema already trusts.
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function g2a_sp_is_ffl( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	return 'yes' === get_post_meta( $id, '_wpistic_ffl_required', true );
}

/**
 * Whether a product counts as a new arrival (drives the "New" badge).
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function g2a_sp_is_new( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	$days = (int) apply_filters( 'g2a_new_product_days', 30, $product );
	if ( $days < 1 ) {
		return false;
	}
	$created = $product->get_date_created();
	if ( ! $created ) {
		return false;
	}
	return ( time() - (int) $created->getTimestamp() ) < ( $days * DAY_IN_SECONDS );
}

/**
 * Brand for a product: brand taxonomy first (core WooCommerce brands and the
 * common plugin taxonomies), then a `Brand` product attribute.
 *
 * @param WC_Product $product Product.
 * @return array{name:string,url:string} Empty name when the product has no brand.
 */
function g2a_sp_brand( $product ) {
	$empty = array(
		'name' => '',
		'url'  => '',
	);
	if ( ! $product instanceof WC_Product ) {
		return $empty;
	}
	$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

	$taxonomies = apply_filters(
		'g2a_brand_taxonomies',
		array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'pa_brand' )
	);
	foreach ( $taxonomies as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}
		$terms = get_the_terms( $id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			continue;
		}
		$term = reset( $terms );
		$link = get_term_link( $term );
		return array(
			'name' => $term->name,
			'url'  => is_wp_error( $link ) ? '' : $link,
		);
	}

	// Custom (non-taxonomy) product attribute named "Brand".
	$attribute = $product->get_attribute( 'Brand' );
	if ( '' !== (string) $attribute ) {
		$values = array_filter( array_map( 'trim', explode( ',', (string) $attribute ) ) );
		if ( $values ) {
			return array(
				'name' => reset( $values ),
				'url'  => '',
			);
		}
	}

	return $empty;
}

/* ==================================================================
 * DESCRIPTION SPLITTING
 * Product descriptions on this store are written as one long document
 * with H2 section headings ("… Specifications", "Common Questions",
 * …). The design puts those sections behind their own tabs, so we
 * split the description on its H2s and route each section to the tab
 * it belongs to. Products whose description has no such headings are
 * unaffected — everything stays in the Description tab.
 * ================================================================== */

/**
 * Split a product description into Description / Specifications / FAQ parts.
 *
 * @param WC_Product $product Product.
 * @return array{description:string,specs:string,faqs:array<int,array{q:string,a:string}>}
 */
function g2a_sp_description_parts( $product ) {
	static $cache = array();

	$parts = array(
		'description' => '',
		'specs'       => '',
		'faqs'        => array(),
	);
	if ( ! $product instanceof WC_Product ) {
		return $parts;
	}

	$id = $product->get_id();
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}

	$raw = (string) $product->get_description();
	if ( '' === trim( $raw ) ) {
		$cache[ $id ] = $parts;
		return $parts;
	}

	/* Run the normal content filters first: shortcodes, blocks and wpautop
	 * must all have resolved before we can reason about the heading
	 * structure. */
	$html = apply_filters( 'the_content', $raw );

	if ( ! class_exists( 'DOMDocument' ) ) {
		$parts['description'] = $html;
		$cache[ $id ]         = $parts;
		return $parts;
	}

	$dom      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$loaded   = $dom->loadHTML(
		'<?xml encoding="utf-8" ?><body>' . $html . '</body>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	$body = $loaded ? $dom->getElementsByTagName( 'body' )->item( 0 ) : null;
	if ( ! $body ) {
		$parts['description'] = $html;
		$cache[ $id ]         = $parts;
		return $parts;
	}

	// Group top-level nodes into sections delimited by H2 headings.
	$sections = array();
	$current  = array(
		'title'   => '',
		'heading' => null,
		'nodes'   => array(),
	);
	foreach ( iterator_to_array( $body->childNodes ) as $node ) {
		if ( $node instanceof DOMElement && 'h2' === strtolower( $node->nodeName ) ) {
			$sections[] = $current;
			$current    = array(
				'title'   => trim( (string) $node->textContent ),
				'heading' => $node,
				'nodes'   => array(),
			);
			continue;
		}
		$current['nodes'][] = $node;
	}
	$sections[] = $current;

	foreach ( $sections as $section ) {
		$title = $section['title'];
		$body_html = g2a_sp_nodes_to_html( $section['nodes'] );

		if ( '' === $title && '' === trim( wp_strip_all_tags( $body_html ) ) ) {
			continue;
		}

		if ( '' !== $title && preg_match( '/spec(ification)?s?\b/i', $title ) ) {
			$parts['specs'] .= g2a_sp_node_to_html( $section['heading'] ) . $body_html;
			continue;
		}

		if ( '' !== $title && preg_match( '/\b(faqs?|questions?)\b/i', $title ) ) {
			$faqs = g2a_sp_parse_faq_nodes( $section['nodes'] );
			if ( $faqs ) {
				$parts['faqs'] = array_merge( $parts['faqs'], $faqs );
				continue;
			}
			// No question/answer pairs found — leave the section in Description
			// rather than silently dropping content.
		}

		$parts['description'] .= g2a_sp_node_to_html( $section['heading'] ) . $body_html;
	}

	$cache[ $id ] = $parts;
	return $parts;
}

/**
 * Serialise a DOM node back to HTML.
 *
 * @param DOMNode|null $node Node.
 * @return string
 */
function g2a_sp_node_to_html( $node ) {
	if ( ! $node instanceof DOMNode || ! $node->ownerDocument ) {
		return '';
	}
	return (string) $node->ownerDocument->saveHTML( $node );
}

/**
 * Serialise a list of DOM nodes back to HTML.
 *
 * @param array<int,DOMNode> $nodes Nodes.
 * @return string
 */
function g2a_sp_nodes_to_html( array $nodes ) {
	$html = '';
	foreach ( $nodes as $node ) {
		$html .= g2a_sp_node_to_html( $node );
	}
	return $html;
}

/**
 * Turn a "Common Questions" section into question/answer pairs.
 *
 * Recognises both markup shapes the store's descriptions use: an H3/H4
 * heading followed by its answer, and a paragraph whose entire content is a
 * <strong>/<b> question followed by its answer.
 *
 * @param array<int,DOMNode> $nodes Section nodes.
 * @return array<int,array{q:string,a:string}>
 */
function g2a_sp_parse_faq_nodes( array $nodes ) {
	$items   = array();
	$current = null;

	foreach ( $nodes as $node ) {
		if ( ! $node instanceof DOMElement ) {
			// Stray text between blocks — only keep it if it carries content.
			if ( $current && '' !== trim( (string) $node->textContent ) ) {
				$current['a'] .= g2a_sp_node_to_html( $node );
			}
			continue;
		}

		$name       = strtolower( $node->nodeName );
		$is_heading = in_array( $name, array( 'h3', 'h4', 'h5', 'h6' ), true );

		if ( ! $is_heading && 'p' === $name ) {
			$is_heading = g2a_sp_is_bold_only_paragraph( $node );
		}

		if ( $is_heading ) {
			if ( $current ) {
				$items[] = $current;
			}
			$current = array(
				'q' => trim( (string) $node->textContent ),
				'a' => '',
			);
			continue;
		}

		if ( $current ) {
			$current['a'] .= g2a_sp_node_to_html( $node );
		}
	}

	if ( $current ) {
		$items[] = $current;
	}

	return array_values(
		array_filter(
			$items,
			static function ( $item ) {
				return '' !== $item['q'] && '' !== trim( wp_strip_all_tags( $item['a'] ) );
			}
		)
	);
}

/**
 * Whether a paragraph is nothing but a bold run (i.e. a question heading).
 *
 * @param DOMElement $node Paragraph element.
 * @return bool
 */
function g2a_sp_is_bold_only_paragraph( DOMElement $node ) {
	$text = trim( (string) $node->textContent );
	if ( '' === $text ) {
		return false;
	}
	$bold = '';
	foreach ( $node->childNodes as $child ) {
		if ( $child instanceof DOMElement && in_array( strtolower( $child->nodeName ), array( 'strong', 'b' ), true ) ) {
			$bold .= (string) $child->textContent;
			continue;
		}
		if ( '' !== trim( (string) $child->textContent ) ) {
			return false; // Mixed content — a real paragraph, not a question.
		}
	}
	return '' !== trim( $bold );
}

/* ==================================================================
 * SUMMARY COLUMN
 * The default summary stack is replaced with the design's order.
 * Core callbacks are reused wherever the design keeps a stock element
 * (title, price, short description, add-to-cart) so plugins hooking the
 * standard actions keep working.
 * ================================================================== */

add_action(
	'wp',
	function () {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );

		/* The stock "Sale!" flash is a direct child of div.product, which is a
		 * CSS grid here — left in place it would claim a grid cell of its own
		 * and push the gallery and summary out of line. The gallery renders
		 * its own Sale badge instead. */
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );

		// Identity line (brand + SKU) sits between the title and the price.
		add_action( 'woocommerce_single_product_summary', 'g2a_sp_render_identity', 7 );
		// Rating moves under the identity line, above the price.
		add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 8 );
		// Stock / FFL badges sit under the price.
		add_action( 'woocommerce_single_product_summary', 'g2a_sp_render_status', 12 );
		// Save-for-later, then the trust row, below the add-to-cart form.
		// Priority 34: directly beneath the Add to Cart form (30) and above
		// the wishlist (35).
		add_action( 'woocommerce_single_product_summary', 'g2a_sp_render_try_at_range', 34 );

		add_action( 'woocommerce_single_product_summary', 'g2a_sp_render_wishlist', 35 );
		add_action( 'woocommerce_single_product_summary', 'g2a_sp_render_trust', 40 );
		// Categories / tags (SKU is already in the identity line).
		add_action( 'woocommerce_single_product_summary', 'g2a_sp_render_taxonomy_meta', 45 );

		// Quantity stepper controls, product pages only.
		add_action( 'woocommerce_before_quantity_input_field', 'g2a_sp_render_qty_minus' );
		add_action( 'woocommerce_after_quantity_input_field', 'g2a_sp_render_qty_plus' );
	},
	20
);

/* Suppress WooCommerce's own stock line on the product being viewed: the
 * status row under the price already states stock state (including
 * backorders), so the paragraph above the add-to-cart form would say the same
 * thing twice. Variations keep theirs — that text is swapped in by the
 * variation script and has no equivalent in the status row. */
add_filter(
	'woocommerce_get_stock_html',
	function ( $html, $product ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $html;
		}
		if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) ) {
			return $html;
		}
		// Only the product being viewed — never related/up-sell loop items.
		if ( (int) get_queried_object_id() !== (int) $product->get_id() ) {
			return $html;
		}
		return '';
	},
	10,
	2
);

/**
 * Brand + SKU line under the product title.
 *
 * @return void
 */
function g2a_sp_render_identity() {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return;
	}

	$brand = g2a_sp_brand( $product );
	$sku   = $product->get_sku();
	if ( '' === $brand['name'] && '' === (string) $sku ) {
		return;
	}

	echo '<div class="g2a-ident">';
	if ( '' !== $brand['name'] ) {
		echo '<span class="g2a-ident__row"><span class="g2a-ident__label">' . esc_html__( 'Brand:', 'guns2ammo' ) . '</span> ';
		if ( '' !== $brand['url'] ) {
			echo '<a class="g2a-ident__value" href="' . esc_url( $brand['url'] ) . '">' . esc_html( $brand['name'] ) . '</a>';
		} else {
			echo '<span class="g2a-ident__value">' . esc_html( $brand['name'] ) . '</span>';
		}
		echo '</span>';
	}
	if ( '' !== (string) $sku ) {
		echo '<span class="g2a-ident__row"><span class="g2a-ident__label">' . esc_html__( 'SKU:', 'guns2ammo' ) . '</span> '
			. '<span class="g2a-ident__value">' . esc_html( $sku ) . '</span></span>';
	}
	echo '</div>';
}

/**
 * Stock state + FFL transfer badges under the price.
 *
 * @return void
 */
function g2a_sp_render_status() {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return;
	}

	$in_stock   = $product->is_in_stock();
	$backorder  = $product->is_on_backorder( 1 );
	$stock_text = $in_stock ? __( 'In Stock', 'guns2ammo' ) : __( 'Out of Stock', 'guns2ammo' );
	if ( $backorder ) {
		$stock_text = __( 'Available on Backorder', 'guns2ammo' );
	}

	echo '<div class="g2a-status">';
	printf(
		'<span class="g2a-status__item %1$s">%2$s<span>%3$s</span></span>',
		esc_attr( $in_stock ? 'is-in' : 'is-out' ),
		g2a_sp_icon( $in_stock ? 'check' : 'alert' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
		esc_html( $stock_text )
	);

	g2a_sp_render_confidence_strip();

	echo '</div>';
}

/**
 * Claims that rotate in place of the old "Free FFL Transfer" badge.
 *
 * Every line here is verifiably true. No stock counters, no "12 people
 * viewing", no invented urgency: this is a firearms retailer, and a claim
 * that turns out to be theatre costs more trust than the click it buys.
 *
 * @return array[] Each: [ 'icon' => icon name, 'text' => claim ].
 */
function g2a_sp_confidence_claims() {
	$product = g2a_sp_product();
	$claims  = array();

	// Only claim pickup availability when the product is genuinely in
	// stock. A rotating strip that says "ready for pickup" over an
	// out-of-stock product is the exact kind of small lie that costs a
	// firearms retailer a customer.
	if ( $product && $product->is_in_stock() ) {
		$claims[] = array(
			'icon' => 'store',
			'text' => __( 'In Stock — ready for pickup in Mesa', 'guns2ammo' ),
		);
	}

	$claims = array_merge(
		$claims,
		array(
			array(
				// 4.7 stars from 500 Google reviews — the same figure the
				// footer has carried for months.
				'icon' => 'check',
				'text' => __( '4.7★ from 500 Google reviews', 'guns2ammo' ),
			),
			array(
				'icon' => 'shield',
				'text' => __( 'NRA-certified instructors on site', 'guns2ammo' ),
			),
			array(
				'icon' => 'transfer',
				'text' => __( 'Serving Mesa since 2014', 'guns2ammo' ),
			),
		)
	);

	/**
	 * Filter the rotating confidence claims.
	 *
	 * Anything added here must be factually true.
	 *
	 * @param array[] $claims Claims.
	 */
	return (array) apply_filters( 'g2a_sp_confidence_claims', $claims );
}

/**
 * The rotating strip itself.
 *
 * All four claims are printed. CSS shows only the first until the script
 * takes over, so with JavaScript off — or with reduced motion — a visitor
 * still sees claim one, statically and legibly, rather than an empty gap.
 *
 * @return void
 */
function g2a_sp_render_confidence_strip() {
	$claims = g2a_sp_confidence_claims();

	if ( ! $claims ) {
		return;
	}

	echo '<span class="g2a-status__item g2a-confidence" data-g2a-confidence>';

	foreach ( array_values( $claims ) as $index => $claim ) {
		printf(
			'<span class="g2a-confidence__item%1$s" data-g2a-confidence-item%2$s>%3$s<span>%4$s</span></span>',
			esc_attr( 0 === $index ? ' is-active' : '' ),
			0 === $index ? '' : ' aria-hidden="true"',
			g2a_sp_icon( (string) $claim['icon'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
			esc_html( (string) $claim['text'] )
		);
	}

	echo '</span>';
}

/**
 * "Try At Range" — rent this exact model before buying it.
 *
 * Renders only for FFL products. You cannot try a holster, and offering to
 * rent one makes the whole block read as boilerplate.
 *
 * @return void
 */
function g2a_sp_render_try_at_range() {
	$product = g2a_sp_product();

	if ( ! $product || ! g2a_sp_is_ffl( $product ) ) {
		return;
	}

	// The referral plugin owns this block's copy and its on/off switch.
	if ( function_exists( 'g2ar_setting' ) && 'yes' !== g2ar_setting( 'try_at_range_enabled', 'yes' ) ) {
		return;
	}

	$heading = function_exists( 'g2ar_setting' )
		? (string) g2ar_setting( 'try_at_range_heading', __( 'Shoot it before you buy it', 'guns2ammo' ) )
		: __( 'Shoot it before you buy it', 'guns2ammo' );

	/*
	 * "We'll put your lane fee toward the purchase" is a COMMERCIAL PROMISE
	 * — the range would owe that credit to anyone who books off the back of
	 * this block. It stays off until the owner has said yes in writing, and
	 * the safe line runs instead. Flip it in Referrals → Settings.
	 */
	$fee_credit = function_exists( 'g2ar_setting' ) && 'yes' === g2ar_setting( 'try_at_range_fee_credit', 'no' );

	if ( function_exists( 'g2ar_setting' ) ) {
		$body = $fee_credit
			? (string) g2ar_setting( 'try_at_range_body_credit', '' )
			: (string) g2ar_setting( 'try_at_range_body_safe', '' );
	} else {
		$body = __( 'Rent this exact model on our indoor range. See how it shoots before you commit.', 'guns2ammo' );
	}

	$cta  = function_exists( 'g2ar_setting' )
		? (string) g2ar_setting( 'try_at_range_cta', __( 'Book a Lane', 'guns2ammo' ) )
		: __( 'Book a Lane', 'guns2ammo' );
	$meta = function_exists( 'g2ar_setting' )
		? (string) g2ar_setting( 'try_at_range_meta', __( 'From $20/hour · Mesa, AZ', 'guns2ammo' ) )
		: __( 'From $20/hour · Mesa, AZ', 'guns2ammo' );

	$url = add_query_arg( 'rental_interest', (int) $product->get_id(), home_url( '/book-a-lane/' ) );
	?>
	<div class="g2a-tar" data-g2a-tar>
		<span class="g2a-tar__sweep" aria-hidden="true"></span>
		<div class="g2a-tar__body">
			<span class="g2a-tar__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<circle cx="12" cy="12" r="8"></circle>
					<circle cx="12" cy="12" r="3"></circle>
					<path d="M12 1v3M12 20v3M1 12h3M20 12h3"></path>
				</svg>
			</span>
			<div class="g2a-tar__text">
				<h3 class="g2a-tar__h"><?php echo esc_html( $heading ); ?></h3>
				<p class="g2a-tar__p"><?php echo esc_html( $body ); ?></p>
			</div>
		</div>
		<div class="g2a-tar__foot">
			<a class="g2a-tar__cta" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $cta ); ?></a>
			<span class="g2a-tar__meta"><?php echo esc_html( $meta ); ?></span>
		</div>
	</div>
	<?php
}

/**
 * "−" control injected before the quantity input.
 *
 * @return void
 */
function g2a_sp_render_qty_minus() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	echo '<button type="button" class="g2a-qty__step" data-qty-step="-1" aria-label="' . esc_attr__( 'Decrease quantity', 'guns2ammo' ) . '">&minus;</button>';
}

/**
 * "+" control injected after the quantity input.
 *
 * @return void
 */
function g2a_sp_render_qty_plus() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	echo '<button type="button" class="g2a-qty__step" data-qty-step="1" aria-label="' . esc_attr__( 'Increase quantity', 'guns2ammo' ) . '">+</button>';
}

/**
 * Save-for-later button.
 *
 * Backed by browser storage (see assets/js/single-product.js) so the control
 * is genuinely functional without a wishlist plugin or an account. It steps
 * aside automatically if a real wishlist plugin is ever activated, so the page
 * never shows two competing buttons.
 *
 * @return void
 */
function g2a_sp_render_wishlist() {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return;
	}
	$plugin_wishlist = defined( 'YITH_WCWL' ) || class_exists( 'TInvWL' ) || function_exists( 'woosw_init' );
	if ( ! apply_filters( 'g2a_single_product_show_wishlist', ! $plugin_wishlist, $product ) ) {
		return;
	}

	printf(
		'<button type="button" class="g2a-wishlist" data-wishlist="%1$d" data-added-label="%2$s" aria-pressed="false">%3$s<span class="g2a-wishlist__label">%4$s</span></button>',
		(int) $product->get_id(),
		esc_attr__( 'Saved to Wishlist', 'guns2ammo' ),
		g2a_sp_icon( 'heart' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
		esc_html__( 'Add to Wishlist', 'guns2ammo' )
	);
}

/**
 * Trust row under the buy box.
 *
 * The claims are product-aware on purpose: firearms leave the store through
 * an FFL transfer or in-store pickup and are a final sale once transferred,
 * so they must never advertise the shipping/returns promises that apply to
 * accessories. Same rule inc/woocommerce.php applies to the Product schema.
 *
 * @return void
 */
function g2a_sp_render_trust() {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return;
	}

	if ( g2a_sp_is_ffl( $product ) ) {
		$items = array(
			array( 'shield', __( 'Secure Checkout', 'guns2ammo' ) ),
			array( 'transfer', __( 'Licensed FFL Dealer', 'guns2ammo' ) ),
			array( 'store', __( 'In-Store Pickup', 'guns2ammo' ) ),
		);
	} else {
		$items = array(
			array( 'shield', __( 'Secure Checkout', 'guns2ammo' ) ),
			array( 'truck', __( 'Fast Shipping', 'guns2ammo' ) ),
			array( 'return', __( '14-Day Returns', 'guns2ammo' ) ),
		);
	}

	echo '<div class="g2a-trust">';
	foreach ( $items as $item ) {
		printf(
			'<span class="g2a-trust__item">%1$s<span>%2$s</span></span>',
			g2a_sp_icon( $item[0] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
			esc_html( $item[1] )
		);
	}
	echo '</div>';
}

/**
 * Category / tag links (the stock meta block minus the SKU, which the
 * identity line already shows).
 *
 * @return void
 */
function g2a_sp_render_taxonomy_meta() {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return;
	}

	$categories = wc_get_product_category_list( $product->get_id(), ', ', '<span class="g2a-meta__row"><span class="g2a-meta__label">' . esc_html__( 'Category:', 'guns2ammo' ) . '</span> ', '</span>' );
	$tags       = wc_get_product_tag_list( $product->get_id(), ', ', '<span class="g2a-meta__row"><span class="g2a-meta__label">' . esc_html__( 'Tags:', 'guns2ammo' ) . '</span> ', '</span>' );

	if ( '' === $categories && '' === $tags ) {
		return;
	}

	echo '<div class="product_meta g2a-meta">' . wp_kses_post( $categories . $tags ) . '</div>';
}

/* ==================================================================
 * TABS
 * The stock tab set is renamed + extended, and rendered by the vertical
 * tab template in woocommerce/single-product/tabs/tabs.php. Standard
 * WooCommerce tab markup (ul.wc-tabs + .wc-tab panels) is preserved so
 * core's tab script keeps driving the switching.
 * ================================================================== */

add_filter( 'woocommerce_product_tabs', 'g2a_sp_tabs', 98 );

/**
 * Restructure the single-product tabs.
 *
 * @param array<string,array<string,mixed>> $tabs Tabs.
 * @return array<string,array<string,mixed>>
 */
function g2a_sp_tabs( $tabs ) {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return $tabs;
	}

	$parts     = g2a_sp_description_parts( $product );
	$has_specs = '' !== trim( $parts['specs'] );

	if ( isset( $tabs['description'] ) ) {
		if ( '' === trim( wp_strip_all_tags( $parts['description'] ) ) ) {
			unset( $tabs['description'] );
		} else {
			$tabs['description']['title']    = __( 'Description', 'guns2ammo' );
			$tabs['description']['priority'] = 10;
			$tabs['description']['callback'] = 'g2a_sp_tab_description';
			$tabs['description']['icon']     = 'doc';
		}
	}

	if ( isset( $tabs['additional_information'] ) || $has_specs ) {
		$tabs['additional_information'] = array(
			'title'    => __( 'Specifications', 'guns2ammo' ),
			'priority' => 20,
			'callback' => 'g2a_sp_tab_specs',
			'icon'     => 'spec',
		);
	}

	if ( ! empty( $parts['faqs'] ) ) {
		$tabs['g2a_faqs'] = array(
			'title'    => __( 'FAQs', 'guns2ammo' ),
			'priority' => 30,
			'callback' => 'g2a_sp_tab_faqs',
			'icon'     => 'help',
		);
	}

	$tabs['g2a_shipping'] = array(
		'title'    => __( 'Shipping & Returns', 'guns2ammo' ),
		'priority' => 40,
		'callback' => 'g2a_sp_tab_shipping',
		'icon'     => 'truck',
	);

	if ( isset( $tabs['reviews'] ) ) {
		$tabs['reviews']['priority'] = 50;
		$tabs['reviews']['icon']     = 'star';
	}

	return $tabs;
}

/**
 * Description panel — every description section that is not specs or FAQs.
 *
 * @return void
 */
function g2a_sp_tab_description() {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return;
	}
	$parts = g2a_sp_description_parts( $product );

	echo '<h2 class="g2a-panel__title">' . esc_html__( 'Description', 'guns2ammo' ) . '</h2>';
	// Editor content that has already been through the_content — same trust
	// level as WooCommerce's own description tab. Escaping here would strip
	// legitimate embeds out of merchant-written descriptions.
	echo '<div class="g2a-panel__body">' . $parts['description'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Specifications panel — the description's spec section plus the product's
 * WooCommerce attributes.
 *
 * @return void
 */
function g2a_sp_tab_specs() {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return;
	}
	$parts = g2a_sp_description_parts( $product );

	echo '<h2 class="g2a-panel__title">' . esc_html__( 'Specifications', 'guns2ammo' ) . '</h2>';

	if ( '' !== trim( $parts['specs'] ) ) {
		echo '<div class="g2a-specs">' . $parts['specs'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered editor content.
	}

	if ( $product->has_attributes() || $product->has_dimensions() || $product->has_weight() ) {
		echo '<div class="g2a-specs g2a-specs--attributes">';
		wc_display_product_attributes( $product );
		echo '</div>';
	}
}

/**
 * FAQ panel — question/answer accordion built from the description.
 *
 * @return void
 */
function g2a_sp_tab_faqs() {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return;
	}
	$parts = g2a_sp_description_parts( $product );
	if ( empty( $parts['faqs'] ) ) {
		return;
	}

	echo '<h2 class="g2a-panel__title">' . esc_html__( 'Common Questions', 'guns2ammo' ) . '</h2>';
	echo '<div class="g2a-faq">';
	foreach ( $parts['faqs'] as $faq ) {
		echo '<details class="g2a-faq__item">';
		echo '<summary class="g2a-faq__q">' . esc_html( $faq['q'] ) . '</summary>';
		echo '<div class="g2a-faq__a">' . $faq['a'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered editor content.
		echo '</details>';
	}
	echo '</div>';
}

/**
 * Shipping & Returns panel.
 *
 * Content is the store's real policy, split by whether the item is a firearm
 * (FFL transfer, final sale once transferred) or an accessory (14-day
 * in-store return window). Never invents a transit-time promise.
 *
 * @return void
 */
function g2a_sp_tab_shipping() {
	$product = g2a_sp_product();
	if ( ! $product ) {
		return;
	}

	$is_ffl  = g2a_sp_is_ffl( $product );
	$address = function_exists( 'g2a_biz_addr_line' ) ? g2a_biz_addr_line() : '6030 E Main St, Suite 103, Mesa, AZ 85205';
	$phone   = function_exists( 'g2a_biz_phone' ) ? g2a_biz_phone() : '(602) 715-2677';
	$policy  = home_url( '/refund-and-returns-policy/' );

	echo '<h2 class="g2a-panel__title">' . esc_html__( 'Shipping & Returns', 'guns2ammo' ) . '</h2>';
	echo '<div class="g2a-panel__body">';

	if ( $is_ffl ) {
		echo '<h3>' . esc_html__( 'Firearm delivery', 'guns2ammo' ) . '</h3>';
		printf(
			'<p>%s</p>',
			esc_html__( 'Firearms are never shipped direct to a customer. Pick this one up at our counter, or have it shipped to your licensed FFL dealer for transfer. Every transfer is completed on an ATF Form 4473 with a NICS background check, which we run in store.', 'guns2ammo' )
		);
		echo '<h3>' . esc_html__( 'In-store pickup', 'guns2ammo' ) . '</h3>';
		printf(
			/* translators: 1: store address, 2: store phone number. */
			'<p>' . esc_html__( 'Collect at %1$s. Bring a valid government-issued photo ID showing your current address. Call %2$s before you travel and we will hold the item at the counter.', 'guns2ammo' ) . '</p>',
			esc_html( $address ),
			esc_html( $phone )
		);
		echo '<h3>' . esc_html__( 'Returns', 'guns2ammo' ) . '</h3>';
		printf(
			'<p>%1$s <a href="%2$s">%3$s</a>.</p>',
			esc_html__( 'Firearms are a final sale once the transfer is complete — federal law and safety leave us no alternative. Inspect the firearm at the counter before you sign. Full terms are in our', 'guns2ammo' ),
			esc_url( $policy ),
			esc_html__( 'refund and returns policy', 'guns2ammo' )
		);
	} else {
		echo '<h3>' . esc_html__( 'Shipping', 'guns2ammo' ) . '</h3>';
		printf(
			'<p>%s</p>',
			esc_html__( 'Orders are picked and packed from our Mesa store. Shipping is calculated at checkout from your address, and in-store pickup is always free.', 'guns2ammo' )
		);
		echo '<h3>' . esc_html__( 'In-store pickup', 'guns2ammo' ) . '</h3>';
		printf(
			/* translators: 1: store address, 2: store phone number. */
			'<p>' . esc_html__( 'Collect at %1$s. Call %2$s and we will hold it at the counter for you.', 'guns2ammo' ) . '</p>',
			esc_html( $address ),
			esc_html( $phone )
		);
		echo '<h3>' . esc_html__( 'Returns', 'guns2ammo' ) . '</h3>';
		printf(
			'<p>%1$s <a href="%2$s">%3$s</a>.</p>',
			esc_html__( 'Accessories and gear can be returned in store within 14 days, unused and in their original packaging. Ammunition is non-returnable once it leaves the store. Full terms are in our', 'guns2ammo' ),
			esc_url( $policy ),
			esc_html__( 'refund and returns policy', 'guns2ammo' )
		);
	}

	echo '</div>';
}

/* ==================================================================
 * BREADCRUMB
 * The design uses sentence-case crumbs separated by chevrons.
 * ================================================================== */

add_filter(
	'woocommerce_breadcrumb_defaults',
	function ( $defaults ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $defaults;
		}
		$defaults['delimiter'] = '<span class="g2a-crumb__sep" aria-hidden="true">' . g2a_sp_icon( 'chevron-right' ) . '</span>';
		$defaults['wrap_before'] = '<nav class="woocommerce-breadcrumb g2a-crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'guns2ammo' ) . '">';
		$defaults['wrap_after']  = '</nav>';
		return $defaults;
	}
);

/* ==================================================================
 * RELATED PRODUCTS
 * Cards reuse the shop-loop markup. Inside the related grid the
 * secondary action collapses to an icon button so four cards fit the
 * row at the design's width.
 * ================================================================== */

/* Priority 14 opens the flag just before woocommerce_upsell_display (15) and
 * woocommerce_output_related_products (20); 21 closes it after both. */
add_action(
	'woocommerce_after_single_product_summary',
	function () {
		$GLOBALS['g2a_sp_in_related'] = true;
	},
	14
);

add_action(
	'woocommerce_after_single_product_summary',
	function () {
		unset( $GLOBALS['g2a_sp_in_related'] );
	},
	21
);

/**
 * Whether the shop loop is currently rendering the related-products grid.
 *
 * @return bool
 */
function g2a_sp_in_related() {
	return ! empty( $GLOBALS['g2a_sp_in_related'] );
}

/* ==================================================================
 * ICONS
 * One inline sprite so the page needs no icon font or extra request.
 * ================================================================== */

/**
 * Inline SVG icon.
 *
 * @param string $name Icon name.
 * @return string SVG markup, or an empty string for an unknown icon.
 */
function g2a_sp_icon( $name ) {
	$paths = array(
		'check'         => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
		'alert'         => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5"/><path d="M12 16.2v.1"/>',
		'transfer'      => '<path d="M3 7h11v8H3z"/><path d="M14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
		'shield'        => '<path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z"/><path d="m9 12 2 2 4-4"/>',
		'truck'         => '<path d="M3 7h11v8H3z"/><path d="M14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
		'return'        => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
		'store'         => '<path d="M4 9h16v11H4z"/><path d="M4 9 6 4h12l2 5"/><path d="M10 20v-6h4v6"/>',
		'heart'         => '<path d="M12 20s-7-4.5-7-9.2A3.8 3.8 0 0 1 12 8a3.8 3.8 0 0 1 7 2.8C19 15.5 12 20 12 20z"/>',
		'doc'           => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
		'spec'          => '<path d="M4 5h16M4 12h16M4 19h16"/><circle cx="9" cy="5" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="8" cy="19" r="1.6"/>',
		'help'          => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.2 2.4c-.6.2-.9.7-.9 1.4v.3"/><path d="M12 16.6v.1"/>',
		'star'          => '<path d="m12 4 2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 9.7l5.4-.8z"/>',
		'search'        => '<circle cx="11" cy="11" r="6"/><path d="m20 20-3.6-3.6"/>',
		'cart'          => '<circle cx="9" cy="19" r="1.7"/><circle cx="17" cy="19" r="1.7"/><path d="M3 4h2l2.4 10.2a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.3L20 8H6"/>',
		'chevron-up'    => '<path d="m6 15 6-6 6 6"/>',
		'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
		'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
		// Shop promo banner (inc/shop-promo.php).
		'target'        => '<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.6"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
		'tag'           => '<path d="M4 4h7l9 9-7 7-9-9z"/><circle cx="8.2" cy="8.2" r="1.4"/>',
		'calendar'      => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
		'double-right'  => '<path d="m6 6 6 6-6 6"/><path d="m13 6 6 6-6 6"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	return '<svg class="g2a-icon g2a-icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}
