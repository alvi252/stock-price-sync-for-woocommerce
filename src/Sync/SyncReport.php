<?php
/**
 * Outcome of a sync run.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Counts and per-item outcomes of one run. Every feed row gets exactly one
 * status. Unchanged rows are only counted, not listed, to keep reports small.
 */
final class SyncReport {

	public const STATUS_UPDATED   = 'updated';
	public const STATUS_UNCHANGED = 'unchanged';
	public const STATUS_NOT_FOUND = 'not_found';
	public const STATUS_INVALID   = 'invalid';
	public const STATUS_ERROR     = 'error';

	public const TRIGGER_MANUAL = 'manual';
	public const TRIGGER_CRON   = 'cron';
	public const TRIGGER_CLI    = 'cli';

	public const STATUSES = array(
		self::STATUS_UPDATED,
		self::STATUS_UNCHANGED,
		self::STATUS_NOT_FOUND,
		self::STATUS_INVALID,
		self::STATUS_ERROR,
	);

	/**
	 * What started the run: manual, cron or cli.
	 *
	 * @var string
	 */
	private string $trigger;

	/**
	 * Whether changes were only planned, not saved.
	 *
	 * @var bool
	 */
	private bool $dry_run;

	/**
	 * Start time, Unix timestamp.
	 *
	 * @var int
	 */
	private int $started_at;

	/**
	 * End time, Unix timestamp; null while running.
	 *
	 * @var int|null
	 */
	private ?int $finished_at = null;

	/**
	 * Reason the run stopped early; null when it completed.
	 *
	 * @var string|null
	 */
	private ?string $failure = null;

	/**
	 * Number of rows per status.
	 *
	 * @var array<string, int>
	 */
	private array $counts;

	/**
	 * Listed outcomes (every status except unchanged).
	 *
	 * @var array<int, array{status: string, sku: string, row: int, product_id: int, product: string, changes: Change[], message: string}>
	 */
	private array $items = array();

	/**
	 * Number of listed items dropped when the report was stored.
	 *
	 * @var int
	 */
	private int $truncated = 0;

	/**
	 * Constructor.
	 *
	 * @param string   $trigger    What started the run: manual, cron or cli.
	 * @param bool     $dry_run    Whether changes are only planned, not saved.
	 * @param int|null $started_at Start time; defaults to now.
	 */
	public function __construct( string $trigger, bool $dry_run, ?int $started_at = null ) {
		$this->trigger    = $trigger;
		$this->dry_run    = $dry_run;
		$this->started_at = $started_at ?? time();
		$this->counts     = array_fill_keys( self::STATUSES, 0 );
	}

	/**
	 * Records the outcome of one feed row.
	 *
	 * @param string   $status       One of the STATUS_* constants.
	 * @param string   $sku          SKU, '' when the row has none.
	 * @param int      $row          Row number in the feed.
	 * @param int      $product_id   Matched product or variation ID, 0 when none.
	 * @param string   $product_name Matched product name.
	 * @param Change[] $changes      Planned or applied changes.
	 * @param string   $message      Explanation for invalid rows and errors.
	 * @throws \InvalidArgumentException For an unknown status.
	 */
	public function add( string $status, string $sku, int $row, int $product_id = 0, string $product_name = '', array $changes = array(), string $message = '' ): void {
		if ( ! isset( $this->counts[ $status ] ) ) {
			throw new \InvalidArgumentException( 'Unknown status: ' . $status );
		}

		++$this->counts[ $status ];

		if ( self::STATUS_UNCHANGED === $status ) {
			return;
		}

		$this->items[] = array(
			'status'     => $status,
			'sku'        => $sku,
			'row'        => $row,
			'product_id' => $product_id,
			'product'    => $product_name,
			'changes'    => $changes,
			'message'    => $message,
		);
	}

	/**
	 * Copies counts and listed items of the given statuses from another report.
	 *
	 * Used when a previewed plan is applied: rows that were not found or
	 * invalid in the preview still belong to the applied run's log entry.
	 *
	 * @param SyncReport $other    Report to copy from.
	 * @param string[]   $statuses Statuses to copy.
	 */
	public function carry_over( SyncReport $other, array $statuses ): void {
		foreach ( $statuses as $status ) {
			if ( ! isset( $this->counts[ $status ] ) ) {
				continue;
			}
			$this->counts[ $status ] += $other->count( $status );
			foreach ( $other->items_with_status( $status ) as $item ) {
				$this->items[] = $item;
			}
		}
	}

	/**
	 * Orders the listed items by feed row, e.g. after carry_over().
	 */
	public function sort_by_row(): void {
		usort(
			$this->items,
			static fn( array $a, array $b ): int => $a['row'] <=> $b['row']
		);
	}

	/**
	 * Marks the run as stopped early.
	 *
	 * @param string $reason Human-readable reason.
	 */
	public function fail( string $reason ): void {
		$this->failure = $reason;
	}

	/**
	 * Marks the run as finished.
	 *
	 * @param int|null $finished_at End time; defaults to now.
	 */
	public function finish( ?int $finished_at = null ): void {
		$this->finished_at = $finished_at ?? time();
	}

	/**
	 * Returns what started the run.
	 *
	 * @return string
	 */
	public function trigger(): string {
		return $this->trigger;
	}

	/**
	 * Whether changes were only planned, not saved.
	 *
	 * @return bool
	 */
	public function is_dry_run(): bool {
		return $this->dry_run;
	}

	/**
	 * Returns the start time.
	 *
	 * @return int
	 */
	public function started_at(): int {
		return $this->started_at;
	}

	/**
	 * Returns the end time, or null while running.
	 *
	 * @return int|null
	 */
	public function finished_at(): ?int {
		return $this->finished_at;
	}

	/**
	 * Whether the run stopped early.
	 *
	 * @return bool
	 */
	public function is_failed(): bool {
		return null !== $this->failure;
	}

	/**
	 * Returns why the run stopped early, or null.
	 *
	 * @return string|null
	 */
	public function failure(): ?string {
		return $this->failure;
	}

	/**
	 * Returns the number of rows per status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		return $this->counts;
	}

	/**
	 * Returns the number of rows with a status.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @return int
	 */
	public function count( string $status ): int {
		return $this->counts[ $status ] ?? 0;
	}

	/**
	 * Returns the number of rows in the feed that were processed.
	 *
	 * @return int
	 */
	public function total(): int {
		return array_sum( $this->counts );
	}

	/**
	 * Returns the number of field changes across all updated items.
	 *
	 * @return int
	 */
	public function change_count(): int {
		$total = 0;
		foreach ( $this->items_with_status( self::STATUS_UPDATED ) as $item ) {
			$total += count( $item['changes'] );
		}
		return $total;
	}

	/**
	 * Returns the listed items (every status except unchanged).
	 *
	 * @return array<int, array{status: string, sku: string, row: int, product_id: int, product: string, changes: Change[], message: string}>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Returns the listed items with a status.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @return array<int, array{status: string, sku: string, row: int, product_id: int, product: string, changes: Change[], message: string}>
	 */
	public function items_with_status( string $status ): array {
		return array_values(
			array_filter(
				$this->items,
				static fn( array $item ): bool => $item['status'] === $status
			)
		);
	}

	/**
	 * Returns how many listed items were dropped when the report was stored.
	 *
	 * @return int
	 */
	public function truncated_items(): int {
		return $this->truncated;
	}

	/**
	 * Exports the report for storage (transient, log table).
	 *
	 * @param int|null $max_items    Maximum number of listed items to keep; null keeps all.
	 * @param bool     $keep_updates Keep every "updated" item and apply the limit to the
	 *                               other statuses only (a preview must keep its whole plan).
	 * @return array<string, mixed>
	 */
	public function to_array( ?int $max_items = null, bool $keep_updates = false ): array {
		if ( null === $max_items ) {
			$items = $this->items;
		} elseif ( $keep_updates ) {
			$items  = array();
			$others = 0;
			foreach ( $this->items as $item ) {
				if ( self::STATUS_UPDATED === $item['status'] ) {
					$items[] = $item;
				} elseif ( $others < $max_items ) {
					$items[] = $item;
					++$others;
				}
			}
		} else {
			$items = array_slice( $this->items, 0, $max_items );
		}

		return array(
			'trigger'         => $this->trigger,
			'dry_run'         => $this->dry_run,
			'started_at'      => $this->started_at,
			'finished_at'     => $this->finished_at,
			'failure'         => $this->failure,
			'counts'          => $this->counts,
			'items_truncated' => $this->truncated + count( $this->items ) - count( $items ),
			'items'           => array_map(
				static function ( array $item ): array {
					$item['changes'] = array_map(
						static fn( Change $change ): array => $change->to_array(),
						$item['changes']
					);
					return $item;
				},
				$items
			),
		);
	}

	/**
	 * Restores a report exported with to_array().
	 *
	 * @param array<string, mixed> $data Exported report.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$report = new self(
			(string) ( $data['trigger'] ?? self::TRIGGER_MANUAL ),
			! empty( $data['dry_run'] ),
			isset( $data['started_at'] ) ? (int) $data['started_at'] : null
		);

		$report->finished_at = isset( $data['finished_at'] ) ? (int) $data['finished_at'] : null;
		$report->failure     = isset( $data['failure'] ) ? (string) $data['failure'] : null;
		$report->truncated   = (int) ( $data['items_truncated'] ?? 0 );

		foreach ( self::STATUSES as $status ) {
			$report->counts[ $status ] = (int) ( $data['counts'][ $status ] ?? 0 );
		}

		foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$report->items[] = array(
				'status'     => (string) ( $item['status'] ?? '' ),
				'sku'        => (string) ( $item['sku'] ?? '' ),
				'row'        => (int) ( $item['row'] ?? 0 ),
				'product_id' => (int) ( $item['product_id'] ?? 0 ),
				'product'    => (string) ( $item['product'] ?? '' ),
				'changes'    => array_map(
					static fn( $change ): Change => Change::from_array( (array) $change ),
					(array) ( $item['changes'] ?? array() )
				),
				'message'    => (string) ( $item['message'] ?? '' ),
			);
		}

		return $report;
	}
}
