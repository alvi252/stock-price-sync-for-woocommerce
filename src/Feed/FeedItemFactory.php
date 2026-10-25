<?php
/**
 * Turns raw feed rows into validated feed items.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the field mapping to a row and validates every mapped value.
 *
 * Rules for a mapped field:
 * - key missing from the row: leave the product field alone;
 * - empty stock or regular price: leave the product field alone;
 * - empty sale price: remove the sale price (supplier ended the promotion);
 * - anything that is not a valid non-negative number: the whole row is invalid;
 * - a price of 0: invalid unless zero prices are allowed, because feeds often
 *   use 0 for "price on request" and the product would become free.
 */
final class FeedItemFactory {

	/**
	 * Field mapping.
	 *
	 * @var FieldMapping
	 */
	private FieldMapping $mapping;

	/**
	 * Number parser.
	 *
	 * @var NumberParser
	 */
	private NumberParser $numbers;

	/**
	 * Number of decimals used by the store for prices.
	 *
	 * @var int
	 */
	private int $price_decimals;

	/**
	 * Whether a price of 0 is accepted.
	 *
	 * @var bool
	 */
	private bool $allow_zero_price;

	/**
	 * Constructor.
	 *
	 * @param FieldMapping $mapping          Field mapping.
	 * @param NumberParser $numbers          Number parser.
	 * @param int          $price_decimals   Number of decimals used by the store for prices.
	 * @param bool         $allow_zero_price Whether a price of 0 is accepted.
	 */
	public function __construct( FieldMapping $mapping, NumberParser $numbers, int $price_decimals, bool $allow_zero_price = false ) {
		$this->mapping          = $mapping;
		$this->numbers          = $numbers;
		$this->price_decimals   = $price_decimals;
		$this->allow_zero_price = $allow_zero_price;
	}

	/**
	 * Builds a validated item from a row.
	 *
	 * @param Row $row Raw row.
	 * @return FeedItem
	 * @throws InvalidValueException When the row cannot be used.
	 */
	public function create( Row $row ): FeedItem {
		if ( null !== $row->problem ) {
			throw InvalidValueException::malformed_row( $row->problem, $row->problem_context );
		}

		$sku = $this->sku_of( $row );
		if ( '' === $sku ) {
			throw InvalidValueException::missing_sku();
		}

		$stock   = null;
		$regular = null;
		$sale    = null;

		$raw = $this->value( $row, FieldMapping::STOCK, $found );
		if ( $found && ! self::is_empty( $raw ) ) {
			$stock = $this->numbers->parse_stock( $raw, FieldMapping::STOCK );
		}

		$raw = $this->value( $row, FieldMapping::REGULAR_PRICE, $found );
		if ( $found && ! self::is_empty( $raw ) ) {
			$regular = $this->price( $raw, FieldMapping::REGULAR_PRICE );
		}

		$raw = $this->value( $row, FieldMapping::SALE_PRICE, $found );
		if ( $found ) {
			$sale = self::is_empty( $raw ) ? '' : $this->price( $raw, FieldMapping::SALE_PRICE );
		}

		return new FeedItem( $sku, $row->number, $stock, $regular, $sale );
	}

	/**
	 * Parses a price and applies the zero-price rule.
	 *
	 * @param mixed  $raw   Raw value.
	 * @param string $field Field name.
	 * @return string
	 * @throws InvalidValueException When the value is invalid or zero while zero is not allowed.
	 */
	private function price( mixed $raw, string $field ): string {
		$price = $this->numbers->parse_price( $raw, $this->price_decimals, $field );
		if ( ! $this->allow_zero_price && 0.0 === (float) $price ) {
			throw InvalidValueException::zero_price( $field, is_scalar( $raw ) ? (string) $raw : '' );
		}
		return $price;
	}

	/**
	 * Returns the SKU of a row, or an empty string. Also used to label invalid rows.
	 *
	 * @param Row $row Raw row.
	 * @return string
	 */
	public function sku_of( Row $row ): string {
		$raw = $this->value( $row, FieldMapping::SKU, $found );
		if ( ! $found || ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return '';
		}
		return trim( (string) $raw );
	}

	/**
	 * Reads the raw value of a mapped field.
	 *
	 * @param Row       $row   Raw row.
	 * @param string    $field One of the FieldMapping constants.
	 * @param bool|null $found Set to whether the field is mapped and present in the row.
	 * @return mixed
	 */
	private function value( Row $row, string $field, ?bool &$found ): mixed {
		$found = false;
		if ( ! $this->mapping->is_mapped( $field ) ) {
			return null;
		}
		return ArrayPath::get( $row->data, $this->mapping->source( $field ), $found );
	}

	/**
	 * Whether a raw value counts as empty.
	 *
	 * @param mixed $raw Raw value.
	 * @return bool
	 */
	private static function is_empty( mixed $raw ): bool {
		return null === $raw || ( is_string( $raw ) && '' === trim( $raw ) );
	}
}
