<?php
/**
 * Plugin Name:          Stock & Price Sync for WooCommerce
 * Description:          Keeps WooCommerce stock levels and prices in sync with a supplier feed or ERP export (CSV or JSON), matched by SKU. Preview, schedule, log and WP-CLI included.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Aleks T.
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          stock-price-sync-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package WSPS
 */

defined( 'ABSPATH' ) || exit;

define( 'WSPS_VERSION', '1.0.0' );
define( 'WSPS_PLUGIN_FILE', __FILE__ );
define( 'WSPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WSPS_PLUGIN_DIR . 'src/Autoloader.php';
WSPS\Autoloader::register( WSPS_PLUGIN_DIR . 'src/' );

register_activation_hook( __FILE__, array( WSPS\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( WSPS\Installer::class, 'deactivate' ) );

// Registered here rather than on boot, so the interval exists during activation too.
// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- The interval is 15 minutes, see Scheduler::add_intervals().
add_filter( 'cron_schedules', array( WSPS\Scheduler::class, 'add_intervals' ) );

// The plugin never touches orders, so it is compatible with High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( WSPS\Plugin::class, 'boot' ) );
