<?php
/**
 * Screen wrapper: title, notice and tabs.
 *
 * @package WSPS
 *
 * @var \WSPS\Admin\AdminPage                     $this   Admin page.
 * @var string                                    $tab    Current tab.
 * @var array{type: string, message: string}|null $notice One-time notice.
 */

use WSPS\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

$wsps_tabs = array(
	'settings' => __( 'Settings', 'stock-price-sync-for-woocommerce' ),
	'sync'     => __( 'Preview & sync', 'stock-price-sync-for-woocommerce' ),
	'log'      => __( 'Log', 'stock-price-sync-for-woocommerce' ),
);
?>
<div class="wrap wsps-wrap">
	<h1><?php esc_html_e( 'Stock & Price Sync', 'stock-price-sync-for-woocommerce' ); ?></h1>

	<?php if ( null !== $notice && '' !== $notice['message'] ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'stock-price-sync-for-woocommerce' ); ?>">
		<?php foreach ( $wsps_tabs as $wsps_key => $wsps_label ) : ?>
			<a href="<?php echo esc_url( AdminPage::url( $wsps_key ) ); ?>"
				class="nav-tab<?php echo $tab === $wsps_key ? ' nav-tab-active' : ''; ?>"
				<?php echo $tab === $wsps_key ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $wsps_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="wsps-tab wsps-tab-<?php echo esc_attr( $tab ); ?>">
		<?php $this->render_tab( $tab ); ?>
	</div>
</div>
