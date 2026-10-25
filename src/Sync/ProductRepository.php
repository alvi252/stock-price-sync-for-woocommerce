<?php
/**
 * WooCommerce product access.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

use WSPS\Feed\FieldMapping;

defined( 'ABSPATH' ) || exit;

/**
 * Finds products by SKU and writes changes through the WooCommerce CRUD API,
 * so stock status, the price index and caches are kept consistent by
 * WooCommerce itself.
 */
final class ProductRepository {

	/**
	 * Finds a product or variation by SKU.
	 *
	 * @param string $sku SKU.
	 * @return \WC_Product|null
	 */
	public function find_by_sku( string $sku ): ?\WC_Product {
		$id = (int) wc_get_product_id_by_sku( $sku );
		return $id > 0 ? $this->get( $id ) : null;
	}

	/**
	 * Loads a product or variation by ID.
	 *
	 * @param int $id Product ID.
	 * @return \WC_Product|null
	 */
	public function get( int $id ): ?\WC_Product {
		$product = wc_get_product( $id );
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Whether the product type is synced. Variable parents, grouped and
	 * external products have no stock or price of their own to set here.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function is_supported( \WC_Product $product ): bool {
		return $product->is_type( array( 'simple', 'variation' ) );
	}

	/**
	 * Copies the compared fields into a WordPress-independent snapshot.
	 *
	 * @param \WC_Product $product Product.
	 * @return ProductSnapshot
	 */
	public function snapshot( \WC_Product $product ): ProductSnapshot {
		// In the "edit" context a variation reports its own flag, never "parent".
		$manage_stock = true === $product->get_manage_stock( 'edit' );
		$stock        = $product->get_stock_quantity( 'edit' );

		return new ProductSnapshot(
			$product->get_id(),
			$product->get_name( 'edit' ),
			(string) $product->get_sku( 'edit' ),
			$manage_stock,
			$manage_stock && null !== $stock ? (int) $stock : null,
			(string) $product->get_regular_price( 'edit' ),
			(string) $product->get_sale_price( 'edit' )
		);
	}

	/**
	 * Applies changes and saves the product.
	 *
	 * @param \WC_Product $product Product.
	 * @param Change[]    $changes Changes to apply.
	 * @throws \WC_Data_Exception When WooCommerce rejects a value.
	 */
	public function apply( \WC_Product $product, array $changes ): void {
		foreach ( $changes as $change ) {
			switch ( $change->field ) {
				case FieldMapping::STOCK:
					$product->set_manage_stock( true );
					$product->set_stock_quantity( (int) $change->to );
					break;
				case FieldMapping::REGULAR_PRICE:
					$product->set_regular_price( (string) $change->to );
					break;
				case FieldMapping::SALE_PRICE:
					$product->set_sale_price( (string) $change->to );
					break;
			}
		}
		$product->save();
	}
}
