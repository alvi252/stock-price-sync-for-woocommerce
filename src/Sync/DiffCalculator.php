<?php
/**
 * Compares a product with its feed item.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

use WSPS\Feed\FeedItem;
use WSPS\Feed\FieldMapping;
use WSPS\Feed\InvalidValueException;
use WSPS\Feed\NumberParser;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which fields need to change. Pure logic, no WordPress.
 */
final class DiffCalculator {

	/**
	 * What the run is allowed to change.
	 *
	 * @var SyncOptions
	 */
	private SyncOptions $options;

	/**
	 * Constructor.
	 *
	 * @param SyncOptions $options What the run is allowed to change.
	 */
	public function __construct( SyncOptions $options ) {
		$this->options = $options;
	}

	/**
	 * Returns the changes needed to bring the product in line with the feed.
	 *
	 * An empty array means the product is already up to date. A sale price
	 * equal to the regular price means "no sale" and is stored as empty.
	 *
	 * @param ProductSnapshot $product Current product state.
	 * @param FeedItem        $item    Target values from the feed.
	 * @return Change[]
	 * @throws InvalidValueException When the resulting sale price would be higher than the regular price.
	 */
	public function diff( ProductSnapshot $product, FeedItem $item ): array {
		$changes = array();

		if ( $this->options->update_stock && null !== $item->stock ) {
			$old = $this->current_value( $product, FieldMapping::STOCK );
			if ( $old !== $item->stock ) {
				$changes[] = new Change( FieldMapping::STOCK, $old, $item->stock );
			}
		}

		if ( $this->options->update_prices && ( null !== $item->regular_price || null !== $item->sale_price ) ) {
			$old_regular = (string) $this->current_value( $product, FieldMapping::REGULAR_PRICE );
			$old_sale    = (string) $this->current_value( $product, FieldMapping::SALE_PRICE );
			$new_regular = $item->regular_price ?? $old_regular;
			$new_sale    = $item->sale_price ?? $old_sale;

			if ( '' !== $new_sale && is_numeric( $new_regular ) ) {
				if ( (float) $new_sale > (float) $new_regular ) {
					throw InvalidValueException::sale_above_regular( $new_sale, $new_regular );
				}
				// Many ERPs export "sale = regular" for items without a promotion.
				if ( (float) $new_sale === (float) $new_regular ) {
					$new_sale = '';
				}
			}

			if ( $new_regular !== $old_regular ) {
				$changes[] = new Change( FieldMapping::REGULAR_PRICE, $old_regular, $new_regular );
			}
			if ( $new_sale !== $old_sale ) {
				$changes[] = new Change( FieldMapping::SALE_PRICE, $old_sale, $new_sale );
			}
		}

		return $changes;
	}

	/**
	 * Returns the current value of a field in the same form the diff uses.
	 *
	 * Prices are normalized to the store's decimals, so "18.9" and "18.90"
	 * compare equal. Stock that is not tracked is null.
	 *
	 * @param ProductSnapshot $product Current product state.
	 * @param string          $field   One of the FieldMapping constants except sku.
	 * @return int|string|null
	 */
	public function current_value( ProductSnapshot $product, string $field ): int|string|null {
		switch ( $field ) {
			case FieldMapping::STOCK:
				return $product->manage_stock ? (int) $product->stock : null;
			case FieldMapping::REGULAR_PRICE:
				return $this->normalize_price( $product->regular_price );
			case FieldMapping::SALE_PRICE:
				return $this->normalize_price( $product->sale_price );
			default:
				return null;
		}
	}

	/**
	 * Normalizes a stored price. Non-numeric values are kept as they are,
	 * so they always differ from a valid feed price and get corrected.
	 *
	 * @param string $price Stored price.
	 * @return string
	 */
	private function normalize_price( string $price ): string {
		$price = trim( $price );
		if ( '' === $price || ! is_numeric( $price ) ) {
			return $price;
		}
		return NumberParser::format( (float) $price, $this->options->price_decimals );
	}
}
