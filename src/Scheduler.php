<?php
/**
 * WP-Cron scheduling.
 *
 * @package WSPS
 */

namespace WSPS;

use WSPS\Sync\SyncReport;
use WSPS\Sync\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the recurring sync event in line with the "schedule" setting.
 */
final class Scheduler {

	public const HOOK = 'wsps_cron_sync';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Builds the sync service on demand, so it is only created when the event runs.
	 *
	 * @var callable(): SyncService
	 */
	private $service_factory;

	/**
	 * Constructor.
	 *
	 * @param Settings                $settings        Settings.
	 * @param callable(): SyncService $service_factory Builds the sync service.
	 */
	public function __construct( Settings $settings, callable $service_factory ) {
		$this->settings        = $settings;
		$this->service_factory = $service_factory;
	}

	/**
	 * Hooks into WordPress.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		// Reschedule as soon as the settings are saved.
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'sync_schedule' ), 10, 0 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'sync_schedule' ), 10, 0 );
		// Self-healing: recreate the event if something removed it.
		add_action( 'admin_init', array( $this, 'sync_schedule' ) );
	}

	/**
	 * Adds the plugin's custom cron interval. Hooked from the main plugin file,
	 * so the interval also exists while the plugin is being activated.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Registered intervals.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_intervals( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules['wsps_every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'stock-price-sync-for-woocommerce' ),
		);

		return $schedules;
	}

	/**
	 * Runs the scheduled sync.
	 */
	public function run(): void {
		$service = ( $this->service_factory )();
		$service->run( SyncReport::TRIGGER_CRON, (bool) $this->settings->get( 'dry_run' ) );
	}

	/**
	 * Makes the scheduled event match the setting.
	 */
	public function sync_schedule(): void {
		$schedule = (string) $this->settings->get( 'schedule' );
		$current  = wp_get_schedule( self::HOOK );

		if ( 'off' === $schedule || ! in_array( $schedule, Settings::SCHEDULES, true ) ) {
			if ( false !== $current ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}

		if ( $current === $schedule ) {
			return;
		}

		if ( false !== $current ) {
			wp_clear_scheduled_hook( self::HOOK );
		}

		// The first run comes one full interval later, not right away: whoever just
		// saved the settings gets time to preview before anything is applied unattended.
		$schedules = wp_get_schedules();
		$interval  = (int) ( $schedules[ $schedule ]['interval'] ?? HOUR_IN_SECONDS );
		wp_schedule_event( time() + $interval, $schedule, self::HOOK );
	}

	/**
	 * Removes the scheduled event.
	 */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
