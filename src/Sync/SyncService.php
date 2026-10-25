<?php
/**
 * Runs a sync.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

use WSPS\Feed\FeedItemFactory;
use WSPS\Feed\FieldMapping;
use WSPS\Feed\InvalidValueException;
use WSPS\Feed\ParseException;
use WSPS\Feed\Row;
use WSPS\Log\LogRepository;
use WSPS\Messages;
use WSPS\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch, parse, validate, match by SKU, diff and apply, in batches.
 */
final class SyncService {

	private const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Feed downloader.
	 *
	 * @var FeedFetcher
	 */
	private FeedFetcher $fetcher;

	/**
	 * Product access.
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Run log.
	 *
	 * @var LogRepository
	 */
	private LogRepository $log;

	/**
	 * Lock against overlapping runs.
	 *
	 * @var SyncLock
	 */
	private SyncLock $lock;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param FeedFetcher       $fetcher  Feed downloader.
	 * @param ProductRepository $products Product access.
	 * @param LogRepository     $log      Run log.
	 * @param SyncLock          $lock     Lock against overlapping runs.
	 */
	public function __construct( Settings $settings, FeedFetcher $fetcher, ProductRepository $products, LogRepository $log, SyncLock $lock ) {
		$this->settings = $settings;
		$this->fetcher  = $fetcher;
		$this->products = $products;
		$this->log      = $log;
		$this->lock     = $lock;
	}

	/**
	 * Runs a sync against the configured feed.
	 *
	 * @param string $trigger One of the SyncReport::TRIGGER_* constants.
	 * @param bool   $dry_run Plan changes without saving them.
	 * @param bool   $log     Whether to store the run in the log (previews are not stored).
	 * @return SyncReport
	 * @throws \Throwable Unexpected errors are recorded in the log and rethrown.
	 */
	public function run( string $trigger, bool $dry_run, bool $log = true ): SyncReport {
		$report = new SyncReport( $trigger, $dry_run );
		self::lift_time_limit();

		if ( ! $dry_run && ! $this->lock->acquire() ) {
			$report->fail( __( 'Another sync is already running. Try again in a few minutes.', 'stock-price-sync-for-woocommerce' ) );
			return $this->finish( $report, $log );
		}

		$unexpected = null;
		try {
			$this->process_feed( $report, $dry_run );
		} catch ( FetchException $e ) {
			$report->fail( $e->getMessage() );
		} catch ( ParseException $e ) {
			$report->fail( Messages::parse_error( $e ) );
		} catch ( \Throwable $e ) {
			/* translators: %s: error message. */
			$report->fail( sprintf( __( 'Unexpected error: %s', 'stock-price-sync-for-woocommerce' ), $e->getMessage() ) );
			$unexpected = $e;
		} finally {
			$this->lock->release();
		}

		$this->finish( $report, $log );
		if ( null !== $unexpected ) {
			throw $unexpected;
		}
		return $report;
	}

	/**
	 * Applies a plan made by a preview, exactly as it was shown.
	 *
	 * A product whose current values no longer match the preview's "old"
	 * values (an order came in, someone edited it) is skipped and reported,
	 * instead of being overwritten with values nobody reviewed.
	 *
	 * @param SyncReport $plan Dry-run report from the preview.
	 * @return SyncReport
	 * @throws \Throwable Unexpected errors are recorded in the log and rethrown.
	 */
	public function apply_plan( SyncReport $plan ): SyncReport {
		$report = new SyncReport( SyncReport::TRIGGER_MANUAL, false );
		self::lift_time_limit();

		if ( ! $this->lock->acquire() ) {
			$report->fail( __( 'Another sync is already running. Try again in a few minutes.', 'stock-price-sync-for-woocommerce' ) );
			return $this->finish( $report, true );
		}

		$unexpected = null;
		try {
			// Rows that were not found or invalid in the preview belong to this run too.
			$report->carry_over(
				$plan,
				array( SyncReport::STATUS_UNCHANGED, SyncReport::STATUS_NOT_FOUND, SyncReport::STATUS_INVALID, SyncReport::STATUS_ERROR )
			);

			$diff = new DiffCalculator( $this->settings->sync_options() );
			foreach ( array_chunk( $plan->items_with_status( SyncReport::STATUS_UPDATED ), $this->batch_size() ) as $batch ) {
				foreach ( $batch as $item ) {
					$this->apply_planned_item( $item, $diff, $report );
				}
				$this->end_batch();
			}
			$report->sort_by_row();
		} catch ( \Throwable $e ) {
			/* translators: %s: error message. */
			$report->fail( sprintf( __( 'Unexpected error: %s', 'stock-price-sync-for-woocommerce' ), $e->getMessage() ) );
			$unexpected = $e;
		} finally {
			$this->lock->release();
		}

		$this->finish( $report, true );
		if ( null !== $unexpected ) {
			throw $unexpected;
		}
		return $report;
	}

	/**
	 * Downloads, parses and processes the feed.
	 *
	 * Throws FetchException (from the fetcher) when the feed cannot be downloaded.
	 *
	 * @param SyncReport $report  Report to fill.
	 * @param bool       $dry_run Plan changes without saving them.
	 * @throws ParseException When the feed cannot be read or the mapped columns are missing.
	 */
	private function process_feed( SyncReport $report, bool $dry_run ): void {
		$feed    = $this->settings->parser()->parse( $this->fetcher->fetch() );
		$mapping = $this->settings->mapping();

		if ( null !== $feed->columns ) {
			$missing = $mapping->missing_columns( $feed->columns );
			if ( array() !== $missing ) {
				throw ParseException::missing_columns( $missing, $feed->columns );
			}
		} else {
			// JSON has no header: a mapped key that no item has is a typo, not "leave alone".
			$missing = $mapping->missing_paths( $feed->rows );
			if ( array() !== $missing ) {
				throw ParseException::missing_keys( $missing, FieldMapping::sample_keys( $feed->rows ) );
			}
		}

		/**
		 * Filters whether a price of 0 from the feed is accepted.
		 *
		 * Off by default: many feeds use 0 for "price on request", which
		 * would make the product free.
		 *
		 * @param bool $allow Whether zero prices are accepted. Default false.
		 */
		$allow_zero = (bool) apply_filters( 'wsps_allow_zero_prices', false );

		$factory = new FeedItemFactory( $mapping, $this->settings->number_parser(), wc_get_price_decimals(), $allow_zero );
		$diff    = new DiffCalculator( $this->settings->sync_options() );
		$seen    = array();

		foreach ( array_chunk( $feed->rows, $this->batch_size() ) as $batch ) {
			foreach ( $batch as $row ) {
				$this->process_row( $row, $factory, $diff, $report, $dry_run, $seen );
			}
			$this->end_batch();
		}
	}

	/**
	 * Processes one feed row. Problems are recorded per row and never stop the run.
	 *
	 * @param Row                $row     Raw row.
	 * @param FeedItemFactory    $factory Mapping and validation.
	 * @param DiffCalculator     $diff    Diff logic.
	 * @param SyncReport         $report  Report to fill.
	 * @param bool               $dry_run Plan changes without saving them.
	 * @param array<string, int> $seen    SKUs and product IDs already processed, with their row number.
	 */
	private function process_row( Row $row, FeedItemFactory $factory, DiffCalculator $diff, SyncReport $report, bool $dry_run, array &$seen ): void {
		try {
			$item = $factory->create( $row );
		} catch ( InvalidValueException $e ) {
			// Name the product when the SKU is readable, so the shop manager knows what was skipped.
			$sku     = $factory->sku_of( $row );
			$product = '' === $sku ? null : $this->products->find_by_sku( $sku );
			$report->add(
				SyncReport::STATUS_INVALID,
				$sku,
				$row->number,
				null === $product ? 0 : $product->get_id(),
				null === $product ? '' : $product->get_name(),
				array(),
				Messages::invalid( $e )
			);
			return;
		}

		// SKU lookups are case-insensitive in MySQL, so duplicates are too.
		$key = strtolower( $item->sku );
		if ( isset( $seen[ $key ] ) ) {
			$report->add( SyncReport::STATUS_INVALID, $item->sku, $row->number, 0, '', array(), Messages::invalid( InvalidValueException::duplicate_sku( $item->sku, $seen[ $key ] ) ) );
			return;
		}
		$seen[ $key ] = $row->number;

		$product = $this->products->find_by_sku( $item->sku );
		if ( null === $product ) {
			$report->add( SyncReport::STATUS_NOT_FOUND, $item->sku, $row->number );
			return;
		}

		// Two spellings of one SKU (accents, case) can resolve to the same product.
		$product_key = '#' . $product->get_id();
		if ( isset( $seen[ $product_key ] ) ) {
			$report->add( SyncReport::STATUS_INVALID, $item->sku, $row->number, $product->get_id(), $product->get_name(), array(), Messages::invalid( InvalidValueException::duplicate_sku( $item->sku, $seen[ $product_key ] ) ) );
			return;
		}
		$seen[ $product_key ] = $row->number;

		if ( ! $this->products->is_supported( $product ) ) {
			$report->add(
				SyncReport::STATUS_ERROR,
				$item->sku,
				$row->number,
				$product->get_id(),
				$product->get_name(),
				array(),
				sprintf(
					/* translators: %s: product type, e.g. "variable". */
					__( 'Products of type "%s" are not synced. Use the SKUs of simple products or variations.', 'stock-price-sync-for-woocommerce' ),
					$product->get_type()
				)
			);
			return;
		}

		$snapshot = $this->products->snapshot( $product );

		try {
			$changes = $diff->diff( $snapshot, $item );
		} catch ( InvalidValueException $e ) {
			$report->add( SyncReport::STATUS_INVALID, $item->sku, $row->number, $snapshot->id, $snapshot->name, array(), Messages::invalid( $e ) );
			return;
		}

		if ( array() === $changes ) {
			$report->add( SyncReport::STATUS_UNCHANGED, $item->sku, $row->number, $snapshot->id, $snapshot->name );
			return;
		}

		if ( ! $dry_run ) {
			try {
				$this->products->apply( $product, $changes );
			} catch ( \Exception $e ) {
				$report->add( SyncReport::STATUS_ERROR, $item->sku, $row->number, $snapshot->id, $snapshot->name, array(), self::save_error( $e ) );
				return;
			}
		}

		$report->add( SyncReport::STATUS_UPDATED, $item->sku, $row->number, $snapshot->id, $snapshot->name, $changes );
	}

	/**
	 * Applies one previewed item after checking that nothing changed since the preview.
	 *
	 * @param array<string, mixed> $item   Planned item from the preview report.
	 * @param DiffCalculator       $diff   Diff logic, used to read current values.
	 * @param SyncReport           $report Report to fill.
	 */
	private function apply_planned_item( array $item, DiffCalculator $diff, SyncReport $report ): void {
		$product = $this->products->get( (int) $item['product_id'] );
		if ( null === $product ) {
			$report->add( SyncReport::STATUS_ERROR, $item['sku'], $item['row'], (int) $item['product_id'], $item['product'], array(), __( 'The product no longer exists.', 'stock-price-sync-for-woocommerce' ) );
			return;
		}

		$snapshot = $this->products->snapshot( $product );

		if ( 0 !== strcasecmp( $snapshot->sku, $item['sku'] ) ) {
			$report->add( SyncReport::STATUS_ERROR, $item['sku'], $item['row'], $snapshot->id, $snapshot->name, array(), __( 'The product SKU changed since the preview. Run a new preview.', 'stock-price-sync-for-woocommerce' ) );
			return;
		}

		$pending = array();
		foreach ( $item['changes'] as $change ) {
			$current = $diff->current_value( $snapshot, $change->field );
			if ( $current === $change->to ) {
				// Already saved since the preview, e.g. by a scheduled run of the same feed.
				continue;
			}
			$pending[] = $change;
			if ( $current !== $change->from ) {
				$report->add(
					SyncReport::STATUS_ERROR,
					$item['sku'],
					$item['row'],
					$snapshot->id,
					$snapshot->name,
					array(),
					sprintf(
						/* translators: 1: field label, 2: value shown in the preview, 3: current value. */
						__( '%1$s changed since the preview (was %2$s, now %3$s). Nothing was saved for this product; run a new preview.', 'stock-price-sync-for-woocommerce' ),
						Messages::field_label( $change->field ),
						self::describe( $change->from ),
						self::describe( $current )
					)
				);
				return;
			}
		}

		if ( array() === $pending ) {
			$report->add( SyncReport::STATUS_UNCHANGED, $item['sku'], $item['row'], $snapshot->id, $snapshot->name );
			return;
		}

		try {
			$this->products->apply( $product, $pending );
		} catch ( \Exception $e ) {
			$report->add( SyncReport::STATUS_ERROR, $item['sku'], $item['row'], $snapshot->id, $snapshot->name, array(), self::save_error( $e ) );
			return;
		}

		$report->add( SyncReport::STATUS_UPDATED, $item['sku'], $item['row'], $snapshot->id, $snapshot->name, $pending );
	}

	/**
	 * Closes the report, stores it and notifies listeners.
	 *
	 * @param SyncReport $report Report.
	 * @param bool       $log    Whether to store the run in the log.
	 * @return SyncReport
	 */
	private function finish( SyncReport $report, bool $log ): SyncReport {
		$report->finish();

		if ( $log ) {
			$this->log->insert( $report );

			/**
			 * Fires after a sync run (not a preview) has finished.
			 *
			 * @param SyncReport $report Outcome of the run.
			 */
			do_action( 'wsps_sync_completed', $report );
		}

		return $report;
	}

	/**
	 * Number of rows processed between memory clean-ups.
	 *
	 * @return int
	 */
	private function batch_size(): int {
		/**
		 * Filters how many feed rows are processed per batch.
		 *
		 * @param int $size Rows per batch. Default 50.
		 */
		return max( 1, (int) apply_filters( 'wsps_batch_size', self::DEFAULT_BATCH_SIZE ) );
	}

	/**
	 * Housekeeping between batches: extends the lock of a long run and drops
	 * the in-memory object cache, so memory stays flat on large feeds.
	 * Products loaded in earlier batches are not needed again.
	 */
	private function end_batch(): void {
		$this->lock->refresh();
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Removes the PHP time limit where the host allows it. Previews and applies
	 * of large feeds run in a web request too, and must not die half-way.
	 */
	private static function lift_time_limit(): void {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
	}

	/**
	 * Message for a product that could not be saved.
	 *
	 * @param \Exception $e Exception thrown by WooCommerce.
	 * @return string
	 */
	private static function save_error( \Exception $e ): string {
		/* translators: %s: error message from WooCommerce. */
		return sprintf( __( 'The product could not be saved: %s', 'stock-price-sync-for-woocommerce' ), $e->getMessage() );
	}

	/**
	 * Describes a field value in a message.
	 *
	 * @param int|string|null $value Value.
	 * @return string
	 */
	private static function describe( $value ): string {
		if ( null === $value ) {
			return __( 'not tracked', 'stock-price-sync-for-woocommerce' );
		}
		if ( '' === $value ) {
			return __( 'empty', 'stock-price-sync-for-woocommerce' );
		}
		return (string) $value;
	}
}
