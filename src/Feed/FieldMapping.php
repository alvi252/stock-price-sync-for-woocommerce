<?php
/**
 * Mapping of feed columns to product fields.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Which feed column (CSV) or key path (JSON) holds each product field.
 * An empty source means "not mapped": that field is never touched.
 */
final class FieldMapping {

	public const SKU           = 'sku';
	public const STOCK         = 'stock';
	public const REGULAR_PRICE = 'regular_price';
	public const SALE_PRICE    = 'sale_price';

	/**
	 * Source column or key path per field.
	 *
	 * @var array<string, string>
	 */
	private array $sources;

	/**
	 * Constructor.
	 *
	 * @param string $sku           Source of the SKU, required.
	 * @param string $stock         Source of the stock quantity.
	 * @param string $regular_price Source of the regular price.
	 * @param string $sale_price    Source of the sale price.
	 * @throws \InvalidArgumentException When the SKU source is empty.
	 */
	public function __construct( string $sku, string $stock = '', string $regular_price = '', string $sale_price = '' ) {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			throw new \InvalidArgumentException( 'The SKU column must be mapped.' );
		}

		$this->sources = array(
			self::SKU           => $sku,
			self::STOCK         => trim( $stock ),
			self::REGULAR_PRICE => trim( $regular_price ),
			self::SALE_PRICE    => trim( $sale_price ),
		);
	}

	/**
	 * Returns the source for a field, or an empty string when it is not mapped.
	 *
	 * @param string $field One of the field constants.
	 * @return string
	 */
	public function source( string $field ): string {
		return $this->sources[ $field ] ?? '';
	}

	/**
	 * Whether a field is mapped.
	 *
	 * @param string $field One of the field constants.
	 * @return bool
	 */
	public function is_mapped( string $field ): bool {
		return '' !== $this->source( $field );
	}

	/**
	 * Returns mapped sources that are absent from a CSV header.
	 *
	 * @param string[] $columns Header columns.
	 * @return string[]
	 */
	public function missing_columns( array $columns ): array {
		$missing = array();
		foreach ( $this->sources as $source ) {
			if ( '' !== $source && ! in_array( $source, $columns, true ) ) {
				$missing[] = $source;
			}
		}
		return array_values( array_unique( $missing ) );
	}

	/**
	 * Returns mapped key paths that no JSON item contains.
	 *
	 * The sale price is not checked: a feed may legitimately add the key only
	 * to items that are on promotion. Returns nothing for an empty feed.
	 *
	 * @param Row[] $rows Parsed rows.
	 * @return string[]
	 */
	public function missing_paths( array $rows ): array {
		$pending = array();
		foreach ( array( self::SKU, self::STOCK, self::REGULAR_PRICE ) as $field ) {
			if ( '' !== $this->sources[ $field ] ) {
				$pending[ $this->sources[ $field ] ] = true;
			}
		}

		$readable = false;
		foreach ( $rows as $row ) {
			if ( null !== $row->problem ) {
				continue;
			}
			$readable = true;
			foreach ( array_keys( $pending ) as $path ) {
				ArrayPath::get( $row->data, $path, $found );
				if ( $found ) {
					unset( $pending[ $path ] );
				}
			}
			if ( array() === $pending ) {
				break;
			}
		}

		return $readable ? array_map( 'strval', array_keys( $pending ) ) : array();
	}

	/**
	 * Returns the top-level keys of the first readable item, to help fix a mapping.
	 *
	 * @param Row[] $rows Parsed rows.
	 * @return string[]
	 */
	public static function sample_keys( array $rows ): array {
		foreach ( $rows as $row ) {
			if ( null === $row->problem ) {
				return array_map( 'strval', array_keys( $row->data ) );
			}
		}
		return array();
	}
}
