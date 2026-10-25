<?php
/**
 * Table of listed report items.
 *
 * @package WSPS
 *
 * @var array<int, array<string, mixed>> $wsps_items  Items to list.
 * @var \WSPS\Sync\SyncReport            $wsps_report Report the items belong to.
 */

use WSPS\Admin\AdminPage;
use WSPS\Messages;

defined( 'ABSPATH' ) || exit;
?>
<table class="widefat striped wsps-table wsps-items">
	<thead>
		<tr>
			<th scope="col" class="wsps-status-col"><?php esc_html_e( 'Result', 'stock-price-sync-for-woocommerce' ); ?></th>
			<th scope="col" class="wsps-row-col"><?php esc_html_e( 'Row', 'stock-price-sync-for-woocommerce' ); ?></th>
			<th scope="col"><?php esc_html_e( 'SKU', 'stock-price-sync-for-woocommerce' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Product', 'stock-price-sync-for-woocommerce' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Details', 'stock-price-sync-for-woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $wsps_items as $wsps_item ) : ?>
			<tr>
				<td>
					<span class="wsps-badge wsps-badge-<?php echo esc_attr( str_replace( '_', '-', $wsps_item['status'] ) ); ?>">
						<?php echo esc_html( Messages::status_label( $wsps_item['status'], $wsps_report->is_dry_run() ) ); ?>
					</span>
				</td>
				<td><?php echo esc_html( (string) $wsps_item['row'] ); ?></td>
				<td><?php echo '' !== $wsps_item['sku'] ? '<code>' . esc_html( $wsps_item['sku'] ) . '</code>' : '<span class="wsps-muted">&mdash;</span>'; ?></td>
				<td>
					<?php if ( '' === $wsps_item['product'] ) : ?>
						<span class="wsps-muted">&mdash;</span>
					<?php else : ?>
						<?php $wsps_link = AdminPage::edit_link( (int) $wsps_item['product_id'] ); ?>
						<?php if ( '' !== $wsps_link ) : ?>
							<a href="<?php echo esc_url( $wsps_link ); ?>"><?php echo esc_html( $wsps_item['product'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $wsps_item['product'] ); ?>
						<?php endif; ?>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( array() !== $wsps_item['changes'] ) : ?>
						<ul class="wsps-change-list">
							<?php foreach ( $wsps_item['changes'] as $wsps_change ) : ?>
								<li>
									<span class="wsps-field"><?php echo esc_html( Messages::field_label( $wsps_change->field ) ); ?>:</span>
									<span class="wsps-old"><?php echo wp_kses_post( AdminPage::format_value( $wsps_change->field, $wsps_change->from ) ); ?></span>
									<span aria-hidden="true">&rarr;</span>
									<span class="wsps-new"><?php echo wp_kses_post( AdminPage::format_value( $wsps_change->field, $wsps_change->to ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php elseif ( 'not_found' === $wsps_item['status'] ) : ?>
						<?php esc_html_e( 'No product or variation with this SKU.', 'stock-price-sync-for-woocommerce' ); ?>
					<?php else : ?>
						<?php echo esc_html( $wsps_item['message'] ); ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
