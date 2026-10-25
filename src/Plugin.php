<?php
/**
 * Plugin bootstrap.
 *
 * @package WSPS
 */

namespace WSPS;

use WSPS\Admin\AdminPage;
use WSPS\Cli\SyncCommand;
use WSPS\Log\LogRepository;
use WSPS\Sync\FeedFetcher;
use WSPS\Sync\ProductRepository;
use WSPS\Sync\SyncLock;
use WSPS\Sync\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the services together and registers hooks.
 */
final class Plugin {

	/**
	 * The booted instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Run log.
	 *
	 * @var LogRepository
	 */
	private LogRepository $log;

	/**
	 * Sync service, created on first use.
	 *
	 * @var SyncService|null
	 */
	private ?SyncService $service = null;

	/**
	 * Boots the plugin once WooCommerce is available. Hooked to plugins_loaded.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'render_missing_woocommerce_notice' ) );
			return;
		}

		self::$instance = new self();
		self::$instance->register_hooks();
	}

	/**
	 * Returns the booted instance, or null when WooCommerce is missing.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->log      = new LogRepository();
	}

	/**
	 * Returns the sync service.
	 *
	 * @return SyncService
	 */
	public function sync_service(): SyncService {
		if ( null === $this->service ) {
			$this->service = new SyncService(
				$this->settings,
				new FeedFetcher( $this->settings ),
				new ProductRepository(),
				$this->log,
				new SyncLock()
			);
		}
		return $this->service;
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'stock-price-sync-for-woocommerce', false, dirname( plugin_basename( WSPS_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Shown when the plugin is active without WooCommerce.
	 */
	public static function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Stock & Price Sync for WooCommerce needs WooCommerce to be installed and active.', 'stock-price-sync-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Registers hooks.
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this->settings, 'register' ) );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ) );

		$factory = array( $this, 'sync_service' );

		( new Scheduler( $this->settings, $factory ) )->register_hooks();

		if ( is_admin() ) {
			( new AdminPage( $this->settings, $this->log, $factory ) )->register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'wsps', new SyncCommand( $factory, $this->settings ) );
		}
	}
}
