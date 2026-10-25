<?php
/**
 * Prevents overlapping sync runs.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Database lock stored as a row in the options table.
 *
 * The options API is not atomic (add_option() reads first, then upserts), so the lock
 * uses INSERT IGNORE on the unique option_name key, like WP_Upgrader's
 * lock. The value is "expiry|token": a stale lock (crashed run) can be taken
 * over once it expires, long runs extend it after every batch, and a run
 * only ever deletes or extends its own lock.
 */
final class SyncLock {

	private const OPTION = 'wsps_sync_lock';

	/**
	 * Lock lifetime in seconds.
	 *
	 * @var int
	 */
	private int $ttl;

	/**
	 * Value written by this instance while it holds the lock, '' otherwise.
	 *
	 * @var string
	 */
	private string $value = '';

	/**
	 * Constructor.
	 *
	 * @param int $ttl Lock lifetime in seconds.
	 */
	public function __construct( int $ttl = 15 * MINUTE_IN_SECONDS ) {
		$this->ttl = $ttl;
	}

	/**
	 * Tries to take the lock.
	 *
	 * @return bool Whether the lock was taken.
	 */
	public function acquire(): bool {
		global $wpdb;

		$value = $this->new_value();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock; the options API cannot do this.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION,
				$value
			)
		);

		if ( 1 !== $inserted ) {
			// Take over a stale lock left by a run that died. Only one request can win this update.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d",
					$value,
					self::OPTION,
					time()
				)
			);
		}

		if ( 1 !== $inserted ) {
			return false;
		}

		$this->value = $value;
		return true;
	}

	/**
	 * Extends the lock while a long run is still working. Call it between batches.
	 */
	public function refresh(): void {
		global $wpdb;

		if ( '' === $this->value ) {
			return;
		}

		$value = $this->new_value();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::OPTION,
				$this->value
			)
		);
		if ( 1 === $updated ) {
			$this->value = $value;
		}
	}

	/**
	 * Releases the lock if this instance still holds it.
	 */
	public function release(): void {
		global $wpdb;

		if ( '' === $this->value ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$this->value
			)
		);
		$this->value = '';
	}

	/**
	 * Removes the lock regardless of who holds it (deactivation, uninstall).
	 */
	public static function force_release(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Builds a lock value: expiry timestamp and a random token.
	 *
	 * @return string
	 */
	private function new_value(): string {
		return ( time() + $this->ttl ) . '|' . wp_generate_password( 20, false );
	}
}
