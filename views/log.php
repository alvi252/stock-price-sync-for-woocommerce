<?php
/**
 * Log tab.
 *
 * @package WSPS
 *
 * @var array<int, array{id: int, started_at: int, finished_at: int|null, status: string, report: \WSPS\Sync\SyncReport}> $runs Recent runs.
 */

use WSPS\Log\LogRepository;
use WSPS\Messages;
use WSPS\Sync\SyncReport;

defined( 'ABSPATH' ) || exit;

$wsps_time_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<p class="description">
	<?php
	/* translators: %d: number of runs kept. */
	echo esc_html( sprintf( __( 'The last %d runs: manual, scheduled and WP-CLI. Previews are not logged.', 'stock-price-sync-for-woocommerce' ), LogRepository::KEEP ) );
	?>
</p>

<?php if ( array() === $runs ) : ?>
	<div class="wsps-empty"><p><?php esc_html_e( 'No sync has run yet.', 'stock-price-sync-for-woocommerce' ); ?></p></div>
<?php else : ?>
	<table class="widefat wsps-table wsps-log">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Started', 'stock-price-sync-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Trigger', 'stock-price-sync-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'stock-price-sync-for-woocommerce' ); ?></th>
				<?php foreach ( SyncReport::STATUSES as $wsps_status ) : ?>
					<th scope="col" class="num"><?php echo esc_html( Messages::count_label( $wsps_status ) ); ?></th>
				<?php endforeach; ?>
				<th scope="col" class="num"><?php esc_html_e( 'Duration', 'stock-price-sync-for-woocommerce' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Details', 'stock-price-sync-for-woocommerce' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $runs as $wsps_index => $wsps_run ) : ?>
				<?php
				$wsps_report  = $wsps_run['report'];
				$wsps_details = 'wsps-run-' . $wsps_run['id'];
				$wsps_failed  = 'failed' === $wsps_run['status'];
				?>
				<tr class="wsps-run<?php echo 0 === $wsps_index % 2 ? ' alternate' : ''; ?>">
					<td><?php echo esc_html( wp_date( $wsps_time_format, $wsps_run['started_at'] ) ); ?></td>
					<td><?php echo esc_html( Messages::trigger_label( $wsps_report->trigger() ) ); ?></td>
					<td>
						<?php if ( $wsps_failed ) : ?>
							<span class="wsps-badge wsps-badge-error"><?php esc_html_e( 'Failed', 'stock-price-sync-for-woocommerce' ); ?></span>
						<?php elseif ( $wsps_report->is_dry_run() ) : ?>
							<span class="wsps-badge wsps-badge-invalid"><?php esc_html_e( 'Dry run', 'stock-price-sync-for-woocommerce' ); ?></span>
						<?php else : ?>
							<span class="wsps-badge wsps-badge-updated"><?php esc_html_e( 'Completed', 'stock-price-sync-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</td>
					<?php foreach ( SyncReport::STATUSES as $wsps_status ) : ?>
						<td class="num<?php echo 0 === $wsps_report->count( $wsps_status ) ? ' wsps-muted' : ''; ?>"><?php echo esc_html( number_format_i18n( $wsps_report->count( $wsps_status ) ) ); ?></td>
					<?php endforeach; ?>
					<td class="num">
						<?php
						$wsps_seconds = null === $wsps_run['finished_at'] ? 0 : max( 0, $wsps_run['finished_at'] - $wsps_run['started_at'] );
						/* translators: %d: duration in seconds. */
						echo esc_html( sprintf( _n( '%d second', '%d seconds', $wsps_seconds, 'stock-price-sync-for-woocommerce' ), $wsps_seconds ) );
						?>
					</td>
					<td class="wsps-toggle-col">
						<button type="button" class="button-link wsps-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $wsps_details ); ?>"
							data-show="<?php esc_attr_e( 'Details', 'stock-price-sync-for-woocommerce' ); ?>"
							data-hide="<?php esc_attr_e( 'Hide', 'stock-price-sync-for-woocommerce' ); ?>"><?php esc_html_e( 'Details', 'stock-price-sync-for-woocommerce' ); ?></button>
					</td>
				</tr>
				<tr id="<?php echo esc_attr( $wsps_details ); ?>" class="wsps-run-details" hidden>
					<td colspan="<?php echo esc_attr( (string) ( count( SyncReport::STATUSES ) + 5 ) ); ?>">
						<?php if ( $wsps_failed ) : ?>
							<div class="notice notice-error inline"><p><?php echo esc_html( (string) $wsps_report->failure() ); ?></p></div>
						<?php elseif ( array() === $wsps_report->items() ) : ?>
							<p class="wsps-muted"><?php esc_html_e( 'Everything was already up to date.', 'stock-price-sync-for-woocommerce' ); ?></p>
						<?php else : ?>
							<?php
							$wsps_items = $wsps_report->items();
							include __DIR__ . '/partials/items.php';
							?>
							<?php if ( $wsps_report->truncated_items() > 0 ) : ?>
								<p class="wsps-muted">
									<?php
									$wsps_more = $wsps_report->truncated_items();
									/* translators: %s: number of rows. */
									echo esc_html( sprintf( _n( '%s more row is counted above but not listed.', '%s more rows are counted above but not listed.', $wsps_more, 'stock-price-sync-for-woocommerce' ), number_format_i18n( $wsps_more ) ) );
									?>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
