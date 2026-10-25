<?php
/**
 * Seeds the local demo store. Run with:
 *   wp eval-file wp-content/plugins/stock-price-sync-for-woocommerce/bin/demo-seed.php
 *
 * Development helper only; not part of the plugin package.
 *
 * @package WSPS
 */

defined( 'ABSPATH' ) || exit;

// Store basics: euro prices, no onboarding wizard or marketing banners in the demo.
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_currency_pos', 'left' );
update_option( 'woocommerce_price_thousand_sep', ',' );
update_option( 'woocommerce_price_decimal_sep', '.' );
update_option( 'woocommerce_price_num_decimals', 2 );
update_option( 'woocommerce_default_country', 'DE' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_allow_tracking', 'no' );
update_option( 'woocommerce_coming_soon', 'no' );
if ( class_exists( 'WC_Admin_Notices' ) ) {
	WC_Admin_Notices::remove_all_notices();
}

// Compact product list for screenshots: hide tags, brands, GTIN, featured and date columns.
$wsps_admin = get_user_by( 'login', 'admin' );
if ( $wsps_admin ) {
	update_user_meta( $wsps_admin->ID, 'manageedit-productcolumnshidden', array( 'product_tag', 'taxonomy-product_brand', 'global_unique_id', 'featured', 'date' ) );
}

$wsps_categories = array();
foreach ( array( 'Dogs', 'Cats', 'Grooming', 'Toys & Accessories' ) as $wsps_name ) {
	$wsps_term = term_exists( $wsps_name, 'product_cat' );
	if ( ! $wsps_term ) {
		$wsps_term = wp_insert_term( $wsps_name, 'product_cat' );
	}
	$wsps_categories[ $wsps_name ] = (int) $wsps_term['term_id'];
}

// SKU => name, regular price, sale price, stock, category.
$wsps_simple = array(
	'DOG-FOOD-2KG'       => array( 'Grain-Free Dog Food, 2 kg', '18.90', '', 24, 'Dogs' ),
	'CAT-FOOD-1500'      => array( 'Indoor Cat Dry Food, 1.5 kg', '21.50', '', 15, 'Cats' ),
	'CAT-LITTER-10L'     => array( 'Clumping Cat Litter, 10 L', '9.90', '', 0, 'Cats' ),
	'DOG-LEASH-RED'      => array( 'Nylon Dog Leash, 1.5 m, Red', '12.50', '', 30, 'Dogs' ),
	'BOWL-STEEL-M'       => array( 'Stainless Steel Bowl, Medium', '7.90', '', 42, 'Toys & Accessories' ),
	'TOY-ROPE-L'         => array( 'Cotton Rope Chew Toy, Large', '6.99', '', 18, 'Toys & Accessories' ),
	'SHAMPOO-OAT-250'    => array( 'Oatmeal Pet Shampoo, 250 ml', '8.40', '', 11, 'Grooming' ),
	'BRUSH-SLICKER'      => array( 'Self-Cleaning Slicker Brush', '14.90', '12.90', 9, 'Grooming' ),
	'TREATS-CHICKEN-200' => array( 'Chicken Training Treats, 200 g', '4.80', '', 55, 'Dogs' ),
	'SCRATCH-POST-60'    => array( 'Sisal Scratching Post, 60 cm', '24.90', '', 6, 'Cats' ),
	'BED-ORTHO-M'        => array( 'Orthopedic Pet Bed, Medium', '49.00', '44.00', 4, 'Toys & Accessories' ),
);

foreach ( $wsps_simple as $wsps_sku => list( $wsps_name, $wsps_regular, $wsps_sale, $wsps_stock, $wsps_category ) ) {
	if ( wc_get_product_id_by_sku( $wsps_sku ) ) {
		continue;
	}
	$wsps_product = new WC_Product_Simple();
	$wsps_product->set_name( $wsps_name );
	$wsps_product->set_sku( $wsps_sku );
	$wsps_product->set_regular_price( $wsps_regular );
	$wsps_product->set_sale_price( $wsps_sale );
	$wsps_product->set_manage_stock( true );
	$wsps_product->set_stock_quantity( $wsps_stock );
	$wsps_product->set_category_ids( array( $wsps_categories[ $wsps_category ] ) );
	$wsps_product->set_status( 'publish' );
	$wsps_product->save();
	WP_CLI::log( "Created {$wsps_sku}" );
}

if ( ! wc_get_product_id_by_sku( 'HARNESS' ) ) {
	$wsps_attribute = new WC_Product_Attribute();
	$wsps_attribute->set_name( 'Size' );
	$wsps_attribute->set_options( array( 'S', 'M', 'L' ) );
	$wsps_attribute->set_visible( true );
	$wsps_attribute->set_variation( true );

	$wsps_parent = new WC_Product_Variable();
	$wsps_parent->set_name( 'Padded Dog Harness' );
	$wsps_parent->set_sku( 'HARNESS' );
	$wsps_parent->set_attributes( array( $wsps_attribute ) );
	$wsps_parent->set_category_ids( array( $wsps_categories['Dogs'] ) );
	$wsps_parent->set_status( 'publish' );
	$wsps_parent_id = $wsps_parent->save();

	foreach ( array(
		'S' => array( '27.90', 8 ),
		'M' => array( '29.90', 7 ),
		'L' => array( '29.90', 5 ),
	) as $wsps_size => list( $wsps_regular, $wsps_stock ) ) {
		$wsps_variation = new WC_Product_Variation();
		$wsps_variation->set_parent_id( $wsps_parent_id );
		$wsps_variation->set_attributes( array( 'size' => $wsps_size ) );
		$wsps_variation->set_sku( 'HARNESS-' . $wsps_size );
		$wsps_variation->set_regular_price( $wsps_regular );
		$wsps_variation->set_manage_stock( true );
		$wsps_variation->set_stock_quantity( $wsps_stock );
		$wsps_variation->set_status( 'publish' );
		$wsps_variation->save();
	}
	WC_Product_Variable::sync( $wsps_parent_id );
	WP_CLI::log( 'Created HARNESS with variations S, M, L' );
}

WP_CLI::success( 'Demo store is ready.' );
