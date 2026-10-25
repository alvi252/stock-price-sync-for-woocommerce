<?php
/**
 * Counters of a report.
 *
 * @package WSPS
 *
 * @var \WSPS\Sync\SyncReport $wsps_report Report.
 */

use WSPS\Messages;
use WSPS\Sync\SyncReport;

defined( 'ABSPATH' ) || exit;
?>
<ul class="wsps-summary">
	<?php foreach ( SyncReport::STATUSES as $wsps_status ) : ?>
		<li class="wsps-summary-<?php echo esc_attr( $wsps_status ); ?><?php echo 0 === $wsps_report->count( $wsps_status ) ? ' is-zero' : ''; ?>">
			<span class="wsps-summary-count"><?php echo esc_html( number_format_i18n( $wsps_report->count( $wsps_status ) ) ); ?></span>
			<span class="wsps-summary-label"><?php echo esc_html( Messages::count_label( $wsps_status, $wsps_report->is_dry_run() ) ); ?></span>
		</li>
	<?php endforeach; ?>
</ul>
