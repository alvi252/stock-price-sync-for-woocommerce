<?php
/**
 * Preview & sync tab.
 *
 * @package WSPS
 *
 * @var array<string, mixed>        $settings        Current settings.
 * @var \WSPS\Sync\SyncReport|null  $preview_report  Stored preview, if any.
 * @var int                         $preview_created When the preview was made.
 * @var int|false                   $next_run        Next scheduled run.
 */

use WSPS\Admin\AdminPage;
use WSPS\Messages;
use WSPS\Sync\SyncReport;

defined( 'ABSPATH' ) || exit;

$wsps_has_url = '' !== (string) $settings['source_url'];
?>
<div class="wsps-panel">
	<dl class="wsps-facts">
		<div>
			<dt><?php esc_html_e( 'Feed', 'stock-price-sync-for-woocommerce' ); ?></dt>
			<dd>
				<?php if ( $wsps_has_url ) : ?>
					<code><?php echo esc_html( (string) $settings['source_url'] ); ?></code>
					<span class="wsps-tag"><?php echo esc_html( strtoupper( (string) $settings['source_type'] ) ); ?></span>
				<?php else : ?>
					<a href="<?php echo esc_url( AdminPage::url( 'settings' ) ); ?>"><?php esc_html_e( 'Not configured yet', 'stock-price-sync-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Schedule', 'stock-price-sync-for-woocommerce' ); ?></dt>
			<dd>
				<?php echo esc_html( Messages::schedule_label( (string) $settings['schedule'] ) ); ?>
				<?php if ( $next_run && $next_run > time() ) : ?>
					<span class="wsps-muted">
						<?php
						/* translators: %s: human-readable time difference, e.g. "12 mins". */
						echo esc_html( sprintf( __( '(next run in %s)', 'stock-price-sync-for-woocommerce' ), human_time_diff( time(), (int) $next_run ) ) );
						?>
					</span>
				<?php elseif ( $next_run ) : ?>
					<span class="wsps-muted"><?php esc_html_e( '(due now, waiting for WP-Cron)', 'stock-price-sync-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Mode', 'stock-price-sync-for-woocommerce' ); ?></dt>
			<dd>
				<?php if ( ! empty( $settings['dry_run'] ) ) : ?>
					<span class="wsps-badge wsps-badge-invalid"><?php esc_html_e( 'Dry run', 'stock-price-sync-for-woocommerce' ); ?></span>
				<?php else : ?>
					<span class="wsps-badge wsps-badge-updated"><?php esc_html_e( 'Live', 'stock-price-sync-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
	</dl>

	<div class="wsps-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wsps_preview">
			<?php wp_nonce_field( 'wsps_preview' ); ?>
			<button type="submit" class="button button-primary" <?php disabled( ! $wsps_has_url ); ?>><?php esc_html_e( 'Preview changes', 'stock-price-sync-for-woocommerce' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wsps_sync_now">
			<?php wp_nonce_field( 'wsps_sync_now' ); ?>
			<button type="submit" class="button" <?php disabled( ! $wsps_has_url ); ?>><?php esc_html_e( 'Sync now', 'stock-price-sync-for-woocommerce' ); ?></button>
		</form>
		<p class="description"><?php esc_html_e( 'Preview downloads the feed and lists every planned change without saving anything. "Sync now" applies changes straight away.', 'stock-price-sync-for-woocommerce' ); ?></p>
	</div>
</div>

<?php if ( null !== $preview_report ) : ?>
	<?php
	$wsps_report  = $preview_report;
	$wsps_updates = $preview_report->items_with_status( SyncReport::STATUS_UPDATED );
	$wsps_issues  = array_values(
		array_filter(
			$preview_report->items(),
			static fn( array $item ): bool => SyncReport::STATUS_UPDATED !== $item['status']
		)
	);
	?>
	<div class="wsps-preview">
		<h2>
			<?php esc_html_e( 'Preview', 'stock-price-sync-for-woocommerce' ); ?>
			<span class="wsps-muted wsps-h-meta">
				<?php
				/* translators: %s: human-readable time difference, e.g. "2 mins". */
				echo esc_html( sprintf( __( 'made %s ago, nothing saved yet', 'stock-price-sync-for-woocommerce' ), human_time_diff( $preview_created, time() ) ) );
				?>
			</span>
		</h2>

		<?php if ( $preview_report->is_failed() ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( (string) $preview_report->failure() ); ?></p></div>
		<?php else : ?>
			<?php include __DIR__ . '/partials/summary.php'; ?>

			<?php if ( array() !== $wsps_updates ) : ?>
				<h3><?php esc_html_e( 'Planned changes', 'stock-price-sync-for-woocommerce' ); ?></h3>
				<table class="widefat striped wsps-table wsps-changes">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'SKU', 'stock-price-sync-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Product', 'stock-price-sync-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Field', 'stock-price-sync-for-woocommerce' ); ?></th>
							<th scope="col" class="wsps-value-col"><?php esc_html_e( 'Current', 'stock-price-sync-for-woocommerce' ); ?></th>
							<th scope="col" class="wsps-arrow-col"><span class="screen-reader-text"><?php esc_html_e( 'becomes', 'stock-price-sync-for-woocommerce' ); ?></span></th>
							<th scope="col" class="wsps-value-col"><?php esc_html_e( 'New', 'stock-price-sync-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $wsps_updates as $wsps_item ) : ?>
							<?php foreach ( $wsps_item['changes'] as $wsps_change ) : ?>
								<tr>
									<td><code><?php echo esc_html( $wsps_item['sku'] ); ?></code></td>
									<td>
										<?php $wsps_link = AdminPage::edit_link( $wsps_item['product_id'] ); ?>
										<?php if ( '' !== $wsps_link ) : ?>
											<a href="<?php echo esc_url( $wsps_link ); ?>"><?php echo esc_html( $wsps_item['product'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $wsps_item['product'] ); ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( Messages::field_label( $wsps_change->field ) ); ?></td>
									<td class="wsps-old"><?php echo wp_kses_post( AdminPage::format_value( $wsps_change->field, $wsps_change->from ) ); ?></td>
									<td class="wsps-arrow-col" aria-hidden="true">&rarr;</td>
									<td class="wsps-new"><?php echo wp_kses_post( AdminPage::format_value( $wsps_change->field, $wsps_change->to ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( array() !== $wsps_issues ) : ?>
				<h3><?php esc_html_e( 'Rows that will be skipped', 'stock-price-sync-for-woocommerce' ); ?></h3>
				<?php
				$wsps_items = $wsps_issues;
				include __DIR__ . '/partials/items.php';
				?>
				<?php if ( $preview_report->truncated_items() > 0 ) : ?>
					<p class="wsps-muted">
						<?php
						$wsps_more = $preview_report->truncated_items();
						/* translators: %s: number of rows. */
						echo esc_html( sprintf( _n( '%s more skipped row is counted above but not listed.', '%s more skipped rows are counted above but not listed.', $wsps_more, 'stock-price-sync-for-woocommerce' ), number_format_i18n( $wsps_more ) ) );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<div class="wsps-actions wsps-apply">
				<?php if ( array() !== $wsps_updates ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wsps_apply">
						<?php wp_nonce_field( 'wsps_apply' ); ?>
						<button type="submit" class="button button-primary" <?php disabled( ! empty( $settings['dry_run'] ) ); ?>>
							<?php
							$wsps_count = $preview_report->change_count();
							/* translators: %d: number of field changes. */
							echo esc_html( sprintf( _n( 'Apply %d change', 'Apply %d changes', $wsps_count, 'stock-price-sync-for-woocommerce' ), $wsps_count ) );
							?>
						</button>
					</form>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wsps_discard_preview">
					<?php wp_nonce_field( 'wsps_discard_preview' ); ?>
					<button type="submit" class="button button-link"><?php esc_html_e( 'Discard preview', 'stock-price-sync-for-woocommerce' ); ?></button>
				</form>
				<?php if ( ! empty( $settings['dry_run'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'Dry-run mode is on in the settings, so changes cannot be applied.', 'stock-price-sync-for-woocommerce' ); ?></p>
				<?php elseif ( array() !== $wsps_updates ) : ?>
					<p class="description"><?php esc_html_e( 'Only the changes listed above are saved. Products that changed in the meantime are skipped and reported.', 'stock-price-sync-for-woocommerce' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>
