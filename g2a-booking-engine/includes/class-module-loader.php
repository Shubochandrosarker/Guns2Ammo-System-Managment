<?php
/**
 * G2AB Module Loader — auto-discovers modules in /includes/modules/.
 *
 * Each module is a folder containing module.php with a manifest array.
 * Active modules (per g2ab_addons_active option) are bootstrapped on init.
 *
 * Manifest format (return from module.php):
 *   return array(
 *     'id'             => 'email_automation',
 *     'name'           => 'Email Automation',
 *     'desc'           => 'Automated emails per booking event.',
 *     'tier'           => 'free',                 // free | pro
 *     'status'         => 'active',               // active | coming_soon
 *     'category'       => 'notifications',
 *     'icon'           => 'M',
 *     'color'          => '#4A5D3A',
 *     'default_active' => true,
 *     'bootstrap'      => 'G2AB_Module_Email_Automation', // class with ::instance()
 *     'configure'      => 'admin.php?page=g2ab-settings&tab=email_automation',
 *   );
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_Loader {

	private static $instance = null;
	private $modules = array();
	private $booted  = false;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		$this->discover();
		add_filter( 'g2ab_addons', array( $this, 'merge_into_addon_registry' ), 20, 1 );
		$this->merge_default_active_modules_for_version();
		$this->boot_active_modules();
	}

	/**
	 * Scan /includes/modules/ for module folders and read manifests.
	 */
	public function discover() {
		$dir = G2AB_PATH . 'includes/modules/';
		if ( ! is_dir( $dir ) ) return;

		$folders = glob( $dir . '*', GLOB_ONLYDIR );
		if ( empty( $folders ) ) return;

		foreach ( $folders as $folder ) {
			$manifest_file = $folder . '/module.php';
			if ( ! file_exists( $manifest_file ) ) continue;

			$manifest = include $manifest_file;
			if ( ! is_array( $manifest ) || empty( $manifest['id'] ) ) continue;

			$manifest['_path'] = trailingslashit( $folder );
			$manifest['_url']  = G2AB_URL . 'includes/modules/' . basename( $folder ) . '/';
			$this->modules[ $manifest['id'] ] = $manifest;
		}
	}

	public function all() { return $this->modules; }

	public function get( $id ) {
		return isset( $this->modules[ $id ] ) ? $this->modules[ $id ] : null;
	}

	public function is_active( $id ) {
		if ( class_exists( 'G2AB_Addon_Manager' ) ) {
			return G2AB_Addon_Manager::instance()->is_active( $id );
		}
		$active = get_option( 'g2ab_addons_active', array() );
		return is_array( $active ) && in_array( $id, $active, true );
	}

	private function merge_default_active_modules_for_version() {
		$merged_version = get_option( 'g2ab_default_modules_merged_version', '' );
		if ( defined( 'G2AB_VERSION' ) && G2AB_VERSION === $merged_version ) return;

		$active = get_option( 'g2ab_addons_active', null );
		if ( ! is_array( $active ) ) $active = array();

		$changed = false;
		foreach ( $this->modules as $id => $manifest ) {
			if ( empty( $manifest['default_active'] ) ) continue;
			if ( ! in_array( $id, $active, true ) ) {
				$active[] = $id;
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'g2ab_addons_active', array_values( array_unique( array_map( 'sanitize_key', $active ) ) ) );
			if ( class_exists( 'G2AB_Addon_Manager' ) ) {
				G2AB_Addon_Manager::instance()->reset_registry();
			}
		}

		if ( defined( 'G2AB_VERSION' ) ) {
			update_option( 'g2ab_default_modules_merged_version', G2AB_VERSION );
		}
	}

	/**
	 * Boot all active modules — wrapped in try/catch so one broken module
	 * never kills the site.
	 */
	public function boot_active_modules() {
		if ( $this->booted ) return;
		$this->booted = true;

		foreach ( $this->modules as $id => $manifest ) {
			if ( ! $this->is_active( $id ) ) continue;
			if ( empty( $manifest['bootstrap'] ) ) continue;

			try {
				$class = $manifest['bootstrap'];
				if ( class_exists( $class ) && method_exists( $class, 'instance' ) ) {
					call_user_func( array( $class, 'instance' ) );
					do_action( 'g2ab_module_booted', $id, $manifest );
				}
			} catch ( \Throwable $e ) {
				error_log( sprintf( '[G2AB] Module "%s" boot failed: %s in %s:%d', $id, $e->getMessage(), $e->getFile(), $e->getLine() ) );
				do_action( 'g2ab_module_boot_failed', $id, $e );
			}
		}

		do_action( 'g2ab_modules_booted', $this );
	}

	/**
	 * Merge module manifests into the addon-manager registry so they
	 * appear automatically in the Addons tab UI.
	 */
	public function merge_into_addon_registry( $registry ) {
		if ( ! is_array( $registry ) ) $registry = array();
		foreach ( $this->modules as $id => $m ) {
			if ( isset( $registry[ $id ] ) ) continue; // hardcoded entries win
			$registry[ $id ] = array(
				'name'           => isset( $m['name'] ) ? $m['name'] : $id,
				'desc'           => isset( $m['desc'] ) ? $m['desc'] : '',
				'tier'           => isset( $m['tier'] ) ? $m['tier'] : 'free',
				'status'         => isset( $m['status'] ) ? $m['status'] : 'active',
				'default_active' => ! empty( $m['default_active'] ),
				'category'       => isset( $m['category'] ) ? $m['category'] : 'developer',
				'icon'           => isset( $m['icon'] ) ? $m['icon'] : 'M',
				'color'          => isset( $m['color'] ) ? $m['color'] : '#4A5D3A',
			);
			if ( ! empty( $m['configure'] ) ) $registry[ $id ]['configure'] = $m['configure'];
		}
		return $registry;
	}
}
