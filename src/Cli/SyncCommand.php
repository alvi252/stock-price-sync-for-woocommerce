<?php
/**
 * WP-CLI command.
 *
 * @package WSPS
 */

namespace WSPS\Cli;

use WP_CLI;
use WSPS\Messages;
use WSPS\Settings;
use WSPS\Sync\SyncReport;
use WSPS\Sync\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Synchronizes WooCommerce stock and prices with the configured feed.
 */
final class SyncCommand {

	/**
	 * Builds the sync service on demand.
	 *
	 * @var callable(): SyncService
	 */
	private $service_factory;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param callable(): SyncService $service_factory Builds the sync service.
	 * @param Settings                $settings        Settings.
	 */
	public function __construct( callable $service_factory, Settings $settings ) {
		$this->service_factory = $service_factory;
		$this->settings        = $settings;
	}

	/**
	 * Runs a sync with the feed configured on the settings screen.
	 *
	 * Prints every change and skipped row, then a summary. The run is stored
	 * in the plugin log with the trigger "WP-CLI".
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would change without saving anything. Also implied by the
	 * "Dry run" setting.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what the next sync would change.
	 *     $ wp wsps sync --dry-run
	 *
	 *     # Apply changes; suitable for a system cron job.
	 *     $ wp wsps sync --quiet
	 *
	 * @when after_wp_load
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function sync( $args, $assoc_args ): void {
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) || (bool) $this->settings->get( 'dry_run' );

		if ( $dry_run ) {
			WP_CLI::log( __( 'Dry run: nothing will be saved.', 'stock-price-sync-for-woocommerce' ) );
		}

		$report = ( $this->service_factory )()->run( SyncReport::TRIGGER_CLI, $dry_run );

		if ( $report->is_failed() ) {
			WP_CLI::error( Messages::summary( $report ) );
		}

		$rows = array();
		foreach ( $report->items() as $item ) {
			$label = Messages::status_label( $item['status'], $report->is_dry_run() );
			if ( array() === $item['changes'] ) {
				$rows[] = array(
					'result'  => $label,
					'row'     => $item['row'],
					'sku'     => $item['sku'],
					'product' => $item['product'],
					'field'   => '',
					'current' => '',
					'new'     => '',
					'note'    => SyncReport::STATUS_NOT_FOUND === $item['status'] ? __( 'No product with this SKU', 'stock-price-sync-for-woocommerce' ) : $item['message'],
				);
				continue;
			}
			foreach ( $item['changes'] as $change ) {
				$rows[] = array(
					'result'  => $label,
					'row'     => $item['row'],
					'sku'     => $item['sku'],
					'product' => $item['product'],
					'field'   => Messages::field_label( $change->field ),
					'current' => self::plain( $change->from ),
					'new'     => self::plain( $change->to ),
					'note'    => '',
				);
			}
		}

		// Tables are printed with WP_CLI::line(), which --quiet does not silence on its own.
		if ( ! WP_CLI::get_config( 'quiet' ) ) {
			if ( array() !== $rows ) {
				WP_CLI\Utils\format_items( 'table', $rows, array( 'result', 'row', 'sku', 'product', 'field', 'current', 'new', 'note' ) );
			}

			$summary = array();
			foreach ( SyncReport::STATUSES as $status ) {
				$summary[0][ $status ] = $report->count( $status );
			}
			WP_CLI\Utils\format_items( 'table', $summary, SyncReport::STATUSES );
		}

		if ( $report->count( SyncReport::STATUS_ERROR ) > 0 ) {
			WP_CLI::warning( Messages::summary( $report ) );
			return;
		}
		WP_CLI::success( Messages::summary( $report ) );
	}

	/**
	 * Formats a value for the terminal.
	 *
	 * @param int|string|null $value Value.
	 * @return string
	 */
	private static function plain( $value ): string {
		if ( null === $value ) {
			return __( 'not tracked', 'stock-price-sync-for-woocommerce' );
		}
		if ( '' === $value ) {
			return __( 'none', 'stock-price-sync-for-woocommerce' );
		}
		return (string) $value;
	}
}
