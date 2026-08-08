<?php
/**
 * PHPUnit bootstrap.
 *
 * There's no WordPress/WooCommerce/MySQL environment available in CI or in
 * this repo, so tests target pure-logic methods only, with WordPress
 * procedural functions mocked via Brain Monkey and the handful of
 * WooCommerce classes a method touches stood in with minimal local stubs
 * (not full WooCommerce). Plugin class files are require_once'd directly,
 * never through the real plugin bootstrap (advanced-ffl-checkout.php),
 * which assumes a live WordPress + WooCommerce runtime.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WPISTIC_FFL_DB_PREFIX' ) ) {
	define( 'WPISTIC_FFL_DB_PREFIX', 'wpistic_ffl_' );
}

// ── Minimal WooCommerce stand-ins ──────────────────────────────────────────
// Only the surface Checkout::get_ffl_items()/create_transfer_on_payment()
// touch — not a WooCommerce reimplementation.
if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		public function __construct( private int $id, private string $name = '', private string $sku = '' ) {}
		public function get_id(): int {
			return $this->id;
		}
		public function get_name(): string {
			return $this->name;
		}
		public function get_sku(): string {
			return $this->sku;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	class WC_Order_Item_Product {
		private static int $next_id = 1;
		private int $id;

		public function __construct( private ?WC_Product $product, private int $qty = 1 ) {
			$this->id = self::$next_id++;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_product() {
			return $this->product;
		}

		public function get_quantity(): int {
			return $this->qty;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		/** @var WC_Order_Item_Product[] */
		private array $items = [];

		/** @param WC_Order_Item_Product[] $items */
		public function set_items( array $items ): void {
			$this->items = $items;
		}

		public function get_items() {
			return $this->items;
		}
	}
}

// Minimal WC_Payment_Gateway stand-in -- PHP resolves an `extends` clause
// at class-DECLARATION time (when the file is require_once'd), not at
// instantiation time, so G2A_Gateway_Nmi/G2A_Gateway_Credova's own files
// would fatal on load without this even for tests that only exercise
// their static, instance-independent helper methods and never construct
// the gateway itself.
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public function init_form_fields(): void {}
		public function init_settings(): void {}
		public function get_option( $key, $default = '' ) {
			return $default;
		}
	}
}

// Plugin classes referenced by type-hint even when the test doesn't
// exercise the code paths that use them (e.g. Checkout references DB,
// Compliance, Mailer, G2A_Scheduler inside methods this suite doesn't call).
// PHP only resolves those at call time, so no stubs are needed for them.
