<?php
/**
 * Translated labels and messages.
 *
 * @package WSPS
 */

namespace WSPS;

use WSPS\Feed\FieldMapping;
use WSPS\Feed\InvalidValueException;
use WSPS\Feed\ParseException;
use WSPS\Feed\Row;
use WSPS\Sync\SyncReport;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the machine-readable reasons of the WordPress-independent classes
 * into translated text. Returns plain text; escape it on output.
 */
final class Messages {

	/**
	 * Label of a product field.
	 *
	 * @param string $field One of the FieldMapping constants.
	 * @return string
	 */
	public static function field_label( string $field ): string {
		switch ( $field ) {
			case FieldMapping::SKU:
				return __( 'SKU', 'stock-price-sync-for-woocommerce' );
			case FieldMapping::STOCK:
				return __( 'Stock', 'stock-price-sync-for-woocommerce' );
			case FieldMapping::REGULAR_PRICE:
				return __( 'Regular price', 'stock-price-sync-for-woocommerce' );
			case FieldMapping::SALE_PRICE:
				return __( 'Sale price', 'stock-price-sync-for-woocommerce' );
			default:
				return $field;
		}
	}

	/**
	 * Label of a row status.
	 *
	 * @param string $status  One of the SyncReport::STATUS_* constants.
	 * @param bool   $dry_run Whether the run was a dry run.
	 * @return string
	 */
	public static function status_label( string $status, bool $dry_run = false ): string {
		switch ( $status ) {
			case SyncReport::STATUS_UPDATED:
				return $dry_run ? __( 'To update', 'stock-price-sync-for-woocommerce' ) : __( 'Updated', 'stock-price-sync-for-woocommerce' );
			case SyncReport::STATUS_UNCHANGED:
				return __( 'Unchanged', 'stock-price-sync-for-woocommerce' );
			case SyncReport::STATUS_NOT_FOUND:
				return __( 'Not found', 'stock-price-sync-for-woocommerce' );
			case SyncReport::STATUS_INVALID:
				return __( 'Invalid', 'stock-price-sync-for-woocommerce' );
			case SyncReport::STATUS_ERROR:
				return __( 'Error', 'stock-price-sync-for-woocommerce' );
			default:
				return $status;
		}
	}

	/**
	 * Column or counter heading for a status.
	 *
	 * @param string $status  One of the SyncReport::STATUS_* constants.
	 * @param bool   $dry_run Whether the run was a dry run.
	 * @return string
	 */
	public static function count_label( string $status, bool $dry_run = false ): string {
		if ( SyncReport::STATUS_ERROR === $status ) {
			return __( 'Errors', 'stock-price-sync-for-woocommerce' );
		}
		return self::status_label( $status, $dry_run );
	}

	/**
	 * Label of a run trigger.
	 *
	 * @param string $trigger One of the SyncReport::TRIGGER_* constants.
	 * @return string
	 */
	public static function trigger_label( string $trigger ): string {
		switch ( $trigger ) {
			case SyncReport::TRIGGER_CRON:
				return __( 'Schedule', 'stock-price-sync-for-woocommerce' );
			case SyncReport::TRIGGER_CLI:
				return __( 'WP-CLI', 'stock-price-sync-for-woocommerce' );
			default:
				return __( 'Manual', 'stock-price-sync-for-woocommerce' );
		}
	}

	/**
	 * Label of a schedule setting.
	 *
	 * @param string $schedule One of Settings::SCHEDULES.
	 * @return string
	 */
	public static function schedule_label( string $schedule ): string {
		switch ( $schedule ) {
			case 'wsps_every_15_minutes':
				return __( 'Every 15 minutes', 'stock-price-sync-for-woocommerce' );
			case 'hourly':
				return __( 'Hourly', 'stock-price-sync-for-woocommerce' );
			case 'twicedaily':
				return __( 'Twice daily', 'stock-price-sync-for-woocommerce' );
			case 'daily':
				return __( 'Daily', 'stock-price-sync-for-woocommerce' );
			default:
				return __( 'Off (manual only)', 'stock-price-sync-for-woocommerce' );
		}
	}

	/**
	 * Explains why a row is invalid.
	 *
	 * @param InvalidValueException $e Exception.
	 * @return string
	 */
	public static function invalid( InvalidValueException $e ): string {
		$field   = self::field_label( $e->get_field() );
		$value   = $e->get_value();
		$context = $e->get_context();

		switch ( $e->get_reason() ) {
			case InvalidValueException::MISSING_SKU:
				return __( 'The SKU is empty.', 'stock-price-sync-for-woocommerce' );
			case InvalidValueException::NEGATIVE:
				/* translators: 1: field label, 2: value from the feed. */
				return sprintf( __( '%1$s cannot be negative: "%2$s".', 'stock-price-sync-for-woocommerce' ), $field, $value );
			case InvalidValueException::NOT_INTEGER:
				/* translators: 1: field label, 2: value from the feed. */
				return sprintf( __( '%1$s must be a whole number: "%2$s".', 'stock-price-sync-for-woocommerce' ), $field, $value );
			case InvalidValueException::TOO_LARGE:
				/* translators: 1: field label, 2: value from the feed. */
				return sprintf( __( '%1$s is unrealistically large: "%2$s".', 'stock-price-sync-for-woocommerce' ), $field, $value );
			case InvalidValueException::ZERO_PRICE:
				/* translators: %s: field label. */
				return sprintf( __( '%s is 0. Zero prices are not imported, so a product never becomes free by accident.', 'stock-price-sync-for-woocommerce' ), $field );
			case InvalidValueException::SALE_ABOVE_REGULAR:
				return sprintf(
					/* translators: 1: sale price, 2: regular price. */
					__( 'Sale price %1$s is higher than the regular price %2$s.', 'stock-price-sync-for-woocommerce' ),
					$value,
					(string) ( $context['regular'] ?? '' )
				);
			case InvalidValueException::DUPLICATE_SKU:
				return sprintf(
					/* translators: %d: row number. */
					__( 'Duplicate SKU; the first occurrence in row %d was used.', 'stock-price-sync-for-woocommerce' ),
					(int) ( $context['first_row'] ?? 0 )
				);
			case InvalidValueException::MALFORMED_ROW:
				if ( Row::PROBLEM_COLUMN_COUNT === ( $context['problem'] ?? '' ) ) {
					return sprintf(
						/* translators: 1: number of values in the row, 2: number of columns in the header. */
						__( 'The row has %1$d values but the header has %2$d columns. Check for an unquoted delimiter.', 'stock-price-sync-for-woocommerce' ),
						(int) ( $context['actual'] ?? 0 ),
						(int) ( $context['expected'] ?? 0 )
					);
				}
				return __( 'The item is not an object.', 'stock-price-sync-for-woocommerce' );
			case InvalidValueException::NOT_A_NUMBER:
			default:
				/* translators: 1: field label, 2: value from the feed. */
				return sprintf( __( '%1$s is not a valid number: "%2$s".', 'stock-price-sync-for-woocommerce' ), $field, $value );
		}
	}

	/**
	 * Explains why the feed could not be read.
	 *
	 * @param ParseException $e Exception.
	 * @return string
	 */
	public static function parse_error( ParseException $e ): string {
		$context = $e->get_context();

		switch ( $e->get_reason() ) {
			case ParseException::EMPTY_FEED:
				return __( 'The feed is empty.', 'stock-price-sync-for-woocommerce' );
			case ParseException::INVALID_JSON:
				/* translators: %s: JSON decoder error. */
				return sprintf( __( 'The feed is not valid JSON: %s.', 'stock-price-sync-for-woocommerce' ), (string) ( $context['error'] ?? '' ) );
			case ParseException::ITEMS_PATH_NOT_FOUND:
				/* translators: %s: items path from the settings. */
				return sprintf( __( 'The items path "%s" was not found in the feed.', 'stock-price-sync-for-woocommerce' ), (string) ( $context['path'] ?? '' ) );
			case ParseException::NOT_A_LIST:
				return __( 'The feed does not contain a list of items. Check the JSON items path.', 'stock-price-sync-for-woocommerce' );
			case ParseException::DUPLICATE_COLUMN:
				/* translators: %s: column name. */
				return sprintf( __( 'The CSV header contains the column "%s" twice.', 'stock-price-sync-for-woocommerce' ), (string) ( $context['column'] ?? '' ) );
			case ParseException::MISSING_COLUMNS:
				return sprintf(
					/* translators: 1: missing column names, 2: column names found in the feed. */
					__( 'Mapped columns not found in the feed: %1$s. Columns in the feed: %2$s. Check the field mapping and the CSV delimiter.', 'stock-price-sync-for-woocommerce' ),
					implode( ', ', (array) ( $context['missing'] ?? array() ) ),
					implode( ', ', (array) ( $context['available'] ?? array() ) )
				);
			case ParseException::MISSING_KEYS:
				return sprintf(
					/* translators: 1: missing JSON keys, 2: keys of the first item in the feed. */
					__( 'Mapped keys not found in any item: %1$s. Keys of the first item: %2$s. Check the field mapping.', 'stock-price-sync-for-woocommerce' ),
					implode( ', ', (array) ( $context['missing'] ?? array() ) ),
					implode( ', ', (array) ( $context['available'] ?? array() ) )
				);
			default:
				return $e->getMessage();
		}
	}

	/**
	 * One-line summary of a run, for admin notices and the CLI.
	 *
	 * @param SyncReport $report Report.
	 * @return string
	 */
	public static function summary( SyncReport $report ): string {
		if ( $report->is_failed() ) {
			/* translators: %s: reason the sync stopped. */
			return sprintf( __( 'Sync failed: %s', 'stock-price-sync-for-woocommerce' ), (string) $report->failure() );
		}

		$format = $report->is_dry_run()
			/* translators: 1: rows to update, 2: unchanged rows, 3: rows not found, 4: invalid rows, 5: rows with errors. */
			? __( 'Dry run finished, nothing was saved. To update: %1$d, unchanged: %2$d, not found: %3$d, invalid: %4$d, errors: %5$d.', 'stock-price-sync-for-woocommerce' )
			/* translators: 1: updated rows, 2: unchanged rows, 3: rows not found, 4: invalid rows, 5: rows with errors. */
			: __( 'Sync finished. Updated: %1$d, unchanged: %2$d, not found: %3$d, invalid: %4$d, errors: %5$d.', 'stock-price-sync-for-woocommerce' );

		return sprintf(
			$format,
			$report->count( SyncReport::STATUS_UPDATED ),
			$report->count( SyncReport::STATUS_UNCHANGED ),
			$report->count( SyncReport::STATUS_NOT_FOUND ),
			$report->count( SyncReport::STATUS_INVALID ),
			$report->count( SyncReport::STATUS_ERROR )
		);
	}
}
