<?php
/**
 * Result of parsing a feed.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of a parsed feed, plus the header columns for CSV.
 */
final class ParsedFeed {

	/**
	 * Constructor.
	 *
	 * @param Row[]         $rows    Records in feed order.
	 * @param string[]|null $columns CSV header columns; null for JSON, where keys may vary per item.
	 */
	public function __construct(
		public readonly array $rows,
		public readonly ?array $columns = null
	) {
	}
}
