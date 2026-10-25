<?php
/**
 * Settings tab.
 *
 * @package WSPS
 */

use WSPS\Admin\AdminPage;
use WSPS\Settings;

defined( 'ABSPATH' ) || exit;

settings_errors();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="wsps-settings">
	<?php
	settings_fields( Settings::GROUP );
	do_settings_sections( AdminPage::SLUG );
	submit_button( __( 'Save settings', 'stock-price-sync-for-woocommerce' ) );
	?>
</form>
