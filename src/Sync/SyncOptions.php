<?php
/**
 * What a sync run is allowed to change.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Options that drive the diff.
 */
final class SyncOptions {

	/**
	 * Constructor.
	 *
	 * @param bool $update_stock   Update stock quantities.
	 * @param bool $update_prices  Update regular and sale prices.
	 * @param int  $price_decimals Number of decimals used by the store for prices.
	 */
	public function __construct(
		public readonly bool $update_stock = true,
		public readonly bool $update_prices = true,
		public readonly int $price_decimals = 2
	) {
	}
}
