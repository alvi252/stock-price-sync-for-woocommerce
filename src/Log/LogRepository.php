<?php
/**
 * Storage of sync runs.
 *
 * @package WSPS
 */

namespace WSPS\Log;

use WSPS\Sync\SyncReport;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the most recent runs in a custom table.
 *
 * A custom table rather than posts or options: runs are append-only rows
 * with counters, pruned on every insert, and never need to be autoloaded.
 */
final class LogRepository {

	/**
	 * Number of runs kept.
	 */
	public const KEEP = 20;

	/**
	 * Maximum number of listed items stored per run. Counts are always complete.
	 */
	public const MAX_ITEMS = 500;

	/**
	 * Returns the table name with the site prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wsps_sync_log';
	}

	/**
	 * Creates or updates the table.
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		// dbDelta() is picky: one column per line, two spaces after PRIMARY KEY.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  started_at datetime NOT NULL,
  finished_at datetime NULL DEFAULT NULL,
  run_trigger varchar(20) NOT NULL DEFAULT '',
  dry_run tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT '',
  updated_count int(10) unsigned NOT NULL DEFAULT 0,
  unchanged_count int(10) unsigned NOT NULL DEFAULT 0,
  not_found_count int(10) unsigned NOT NULL DEFAULT 0,
  invalid_count int(10) unsigned NOT NULL DEFAULT 0,
  error_count int(10) unsigned NOT NULL DEFAULT 0,
  message text NULL,
  details longtext NULL,
  PRIMARY KEY  (id),
  KEY started_at (started_at)
) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Stores a run and prunes old ones.
	 *
	 * @param SyncReport $report Finished run.
	 * @return int ID of the new row, 0 on failure.
	 */
	public function insert( SyncReport $report ): int {
		global $wpdb;

		$finished = $report->finished_at();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; nothing to cache on insert.
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'started_at'      => gmdate( 'Y-m-d H:i:s', $report->started_at() ),
				'finished_at'     => null === $finished ? null : gmdate( 'Y-m-d H:i:s', $finished ),
				'run_trigger'     => $report->trigger(),
				'dry_run'         => $report->is_dry_run() ? 1 : 0,
				'status'          => $report->is_failed() ? 'failed' : 'completed',
				'updated_count'   => $report->count( SyncReport::STATUS_UPDATED ),
				'unchanged_count' => $report->count( SyncReport::STATUS_UNCHANGED ),
				'not_found_count' => $report->count( SyncReport::STATUS_NOT_FOUND ),
				'invalid_count'   => $report->count( SyncReport::STATUS_INVALID ),
				'error_count'     => $report->count( SyncReport::STATUS_ERROR ),
				'message'         => (string) $report->failure(),
				'details'         => (string) wp_json_encode( $report->to_array( self::MAX_ITEMS ) ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		$this->prune();
		return $id;
	}

	/**
	 * Returns the most recent runs, newest first.
	 *
	 * @param int $limit Maximum number of runs.
	 * @return array<int, array{id: int, started_at: int, finished_at: int|null, status: string, report: SyncReport}>
	 */
	public function latest( int $limit = self::KEEP ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read only on the plugin screen.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table_name(), $limit ),
			ARRAY_A
		);

		$runs = array();
		foreach ( (array) $rows as $row ) {
			$details = json_decode( (string) $row['details'], true );
			$runs[]  = array(
				'id'          => (int) $row['id'],
				'started_at'  => (int) strtotime( $row['started_at'] . ' UTC' ),
				'finished_at' => null === $row['finished_at'] ? null : (int) strtotime( $row['finished_at'] . ' UTC' ),
				'status'      => (string) $row['status'],
				'report'      => SyncReport::from_array( is_array( $details ) ? $details : array() ),
			);
		}
		return $runs;
	}

	/**
	 * Deletes everything but the most recent runs.
	 */
	public function prune(): void {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$oldest_kept = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, self::KEEP - 1 )
		);

		if ( $oldest_kept > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $table, $oldest_kept ) );
		}
	}
}
