<?php
/**
 * A validated feed row.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Target values for one SKU, already validated and normalized.
 *
 * A null value means "leave this field alone". For the sale price an empty
 * string means "remove the sale price".
 */
final class FeedItem {

	/**
	 * Constructor.
	 *
	 * @param string      $sku           SKU.
	 * @param int         $row           Row number in the feed.
	 * @param int|null    $stock         Stock quantity, or null to leave it alone.
	 * @param string|null $regular_price Normalized regular price, or null to leave it alone.
	 * @param string|null $sale_price    Normalized sale price, '' to remove it, or null to leave it alone.
	 */
	public function __construct(
		public readonly string $sku,
		public readonly int $row,
		public readonly ?int $stock = null,
		public readonly ?string $regular_price = null,
		public readonly ?string $sale_price = null
	) {
	}
}
