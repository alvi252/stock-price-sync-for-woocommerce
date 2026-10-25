<?php
/**
 * Current state of a product, detached from WooCommerce.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * The fields the sync compares, copied from a WooCommerce product so the
 * diff logic can be tested without WordPress.
 */
final class ProductSnapshot {

	/**
	 * Constructor.
	 *
	 * @param int      $id            Product or variation ID.
	 * @param string   $name          Product name (variations include their attributes).
	 * @param string   $sku           SKU.
	 * @param bool     $manage_stock  Whether the product tracks its own stock.
	 * @param int|null $stock         Stock quantity, null when not tracked.
	 * @param string   $regular_price Regular price as stored, '' when not set.
	 * @param string   $sale_price    Sale price as stored, '' when not set.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $sku,
		public readonly bool $manage_stock,
		public readonly ?int $stock,
		public readonly string $regular_price,
		public readonly string $sale_price
	) {
	}
}
