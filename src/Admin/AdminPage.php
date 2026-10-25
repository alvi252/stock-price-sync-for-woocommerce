<?php
/**
 * Admin screen: settings, preview and log.
 *
 * @package WSPS
 */

namespace WSPS\Admin;

use WSPS\Feed\FieldMapping;
use WSPS\Log\LogRepository;
use WSPS\Messages;
use WSPS\Scheduler;
use WSPS\Settings;
use WSPS\Sync\SyncReport;
use WSPS\Sync\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * The "WooCommerce > Stock Sync" screen and its form handlers.
 */
final class AdminPage {

	public const SLUG = 'wsps';

	private const CAPABILITY  = 'manage_woocommerce';
	private const TABS        = array( 'settings', 'sync', 'log' );
	private const PREVIEW_TTL = HOUR_IN_SECONDS;

	/**
	 * Skipped rows (not found, invalid, errors) kept in a preview. Every planned
	 * change is always kept; this only bounds the size of the stored preview.
	 */
	private const PREVIEW_MAX_ISSUES = 200;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Run log.
	 *
	 * @var LogRepository
	 */
	private LogRepository $log;

	/**
	 * Builds the sync service on demand.
	 *
	 * @var callable(): SyncService
	 */
	private $service_factory;

	/**
	 * Screen hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Settings                $settings        Settings.
	 * @param LogRepository           $log             Run log.
	 * @param callable(): SyncService $service_factory Builds the sync service.
	 */
	public function __construct( Settings $settings, LogRepository $log, callable $service_factory ) {
		$this->settings        = $settings;
		$this->log             = $log;
		$this->service_factory = $service_factory;
	}

	/**
	 * Hooks into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'option_page_capability_' . Settings::GROUP, array( $this, 'capability' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WSPS_PLUGIN_FILE ), array( $this, 'action_links' ) );

		add_action( 'admin_post_wsps_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_wsps_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_wsps_discard_preview', array( $this, 'handle_discard_preview' ) );
		add_action( 'admin_post_wsps_sync_now', array( $this, 'handle_sync_now' ) );
	}

	/**
	 * Adds the screen under the WooCommerce menu.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Stock & Price Sync', 'stock-price-sync-for-woocommerce' ),
			__( 'Stock Sync', 'stock-price-sync-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Lets shop managers save the settings form (options.php requires manage_options by default).
	 *
	 * @return string
	 */
	public function capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * Adds a "Settings" link on the Plugins screen.
	 *
	 * @param array<string|int, string> $links Existing links.
	 * @return array<string|int, string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::url( 'settings' ) ),
				esc_html__( 'Settings', 'stock-price-sync-for-woocommerce' )
			)
		);
		return $links;
	}

	/**
	 * Loads the screen's CSS and JS on this screen only.
	 *
	 * @param string $hook_suffix Current screen hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'wsps-admin', plugins_url( 'css/admin.css', WSPS_PLUGIN_FILE ), array(), WSPS_VERSION );
		wp_enqueue_script( 'wsps-admin', plugins_url( 'js/admin.js', WSPS_PLUGIN_FILE ), array(), WSPS_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * Registers the settings sections and fields.
	 */
	public function register_fields(): void {
		add_settings_section(
			'wsps_source',
			__( 'Feed source', 'stock-price-sync-for-woocommerce' ),
			array( $this, 'render_source_intro' ),
			self::SLUG
		);
		add_settings_section(
			'wsps_mapping',
			__( 'Field mapping', 'stock-price-sync-for-woocommerce' ),
			array( $this, 'render_mapping_intro' ),
			self::SLUG
		);
		add_settings_section(
			'wsps_behavior',
			__( 'Sync behavior', 'stock-price-sync-for-woocommerce' ),
			'__return_false',
			self::SLUG
		);

		foreach ( $this->fields() as $key => $field ) {
			$field['key'] = $key;
			add_settings_field(
				'wsps-' . $key,
				$field['label'],
				array( $this, 'render_field' ),
				self::SLUG,
				$field['section'],
				array(
					'label_for' => 'checkboxes' === $field['type'] ? '' : 'wsps-' . $key,
					'class'     => $field['row_class'] ?? '',
					'field'     => $field,
				)
			);
		}
	}

	/**
	 * Intro text of the feed source section.
	 */
	public function render_source_intro(): void {
		echo '<p>' . esc_html__( 'Where the plugin downloads stock and prices from: a supplier feed, an ERP export or any URL that returns CSV or JSON.', 'stock-price-sync-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro text of the field mapping section.
	 */
	public function render_mapping_intro(): void {
		echo '<p>' . esc_html__( 'Column names from the CSV header, or keys of each JSON item (use dots for nested values, e.g. price.regular). Leave a field empty to never change it.', 'stock-price-sync-for-woocommerce' ) . '</p>';
	}

	/**
	 * Renders one settings field.
	 *
	 * @param array{field: array<string, mixed>} $args Field definition.
	 */
	public function render_field( $args ): void {
		$field    = $args['field'];
		$key      = (string) $field['key'];
		$settings = $this->settings->all();
		$name     = Settings::OPTION . '[' . $key . ']';
		$id       = 'wsps-' . $key;

		switch ( $field['type'] ) {
			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field['options'] as $value => $label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $value ),
						selected( (string) $settings[ $key ], (string) $value, false ),
						esc_html( $label )
					);
				}
				echo '</select>';
				break;

			case 'checkboxes':
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
				foreach ( $field['options'] as $option_key => $label ) {
					printf(
						'<label class="wsps-checkbox"><input type="checkbox" name="%s" value="1" %s> %s</label>',
						esc_attr( Settings::OPTION . '[' . $option_key . ']' ),
						checked( ! empty( $settings[ $option_key ] ), true, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset>';
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $settings[ $key ] ), true, false ),
					esc_html( $field['checkbox_label'] )
				);
				break;

			case 'password':
				$saved = '' !== (string) $settings[ $key ];
				printf(
					'<input type="password" id="%s" name="%s" value="" class="regular-text" autocomplete="new-password" placeholder="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $saved ? __( 'Saved (hidden)', 'stock-price-sync-for-woocommerce' ) : '' )
				);
				if ( $saved ) {
					$field['description'] = __( 'A value is saved and never shown again. Leave blank to keep it; clear the header name to remove it.', 'stock-price-sync-for-woocommerce' );
				}
				break;

			default:
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" class="%s" placeholder="%s"%s>',
					esc_attr( $field['type'] ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $settings[ $key ] ),
					esc_attr( $field['class'] ?? 'regular-text' ),
					esc_attr( $field['placeholder'] ?? '' ),
					empty( $field['required'] ) ? '' : ' required'
				);
		}

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
		}
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'stock-price-sync-for-woocommerce' ), 403 );
		}

		$tab    = self::current_tab();
		$notice = $this->pull_notice();

		include WSPS_PLUGIN_DIR . 'views/page.php';
	}

	/**
	 * Renders the content of the current tab. Called from views/page.php.
	 *
	 * @param string $tab Tab key.
	 */
	public function render_tab( string $tab ): void {
		$settings = $this->settings->all();

		switch ( $tab ) {
			case 'sync':
				$preview         = $this->get_preview();
				$preview_report  = null === $preview ? null : SyncReport::from_array( $preview['report'] );
				$preview_created = null === $preview ? 0 : (int) $preview['created'];
				$next_run        = wp_next_scheduled( Scheduler::HOOK );
				include WSPS_PLUGIN_DIR . 'views/sync.php';
				break;

			case 'log':
				$runs = $this->log->latest();
				include WSPS_PLUGIN_DIR . 'views/log.php';
				break;

			default:
				include WSPS_PLUGIN_DIR . 'views/settings.php';
		}
	}

	/**
	 * Handles "Preview changes": a dry run that is kept for the "Apply" step.
	 */
	public function handle_preview(): void {
		$this->verify_request( 'wsps_preview' );

		$report = $this->service()->run( SyncReport::TRIGGER_MANUAL, true, false );

		$stored = set_transient(
			self::preview_key(),
			array(
				'report'      => $report->to_array( self::PREVIEW_MAX_ISSUES, true ),
				'fingerprint' => $this->settings->fingerprint(),
				'created'     => time(),
			),
			self::PREVIEW_TTL
		);

		if ( ! $stored ) {
			$this->push_notice( 'error', __( 'The preview could not be stored, probably because it is too large for the object cache. Use WP-CLI with --dry-run for very large feeds.', 'stock-price-sync-for-woocommerce' ) );
		}

		$this->redirect( 'sync' );
	}

	/**
	 * Handles "Apply": saves exactly the previewed changes.
	 */
	public function handle_apply(): void {
		$this->verify_request( 'wsps_apply' );

		$preview = $this->get_preview();

		if ( null === $preview ) {
			$this->push_notice( 'error', __( 'The preview has expired. Run a new preview before applying changes.', 'stock-price-sync-for-woocommerce' ) );
			$this->redirect( 'sync' );
		}
		if ( $this->settings->get( 'dry_run' ) ) {
			$this->push_notice( 'warning', __( 'Dry-run mode is on, so no changes were saved. Turn it off in the settings to apply changes.', 'stock-price-sync-for-woocommerce' ) );
			$this->redirect( 'sync' );
		}
		if ( $preview['fingerprint'] !== $this->settings->fingerprint() ) {
			$this->push_notice( 'error', __( 'The settings changed after the preview was made. Run a new preview before applying changes.', 'stock-price-sync-for-woocommerce' ) );
			$this->redirect( 'sync' );
		}

		$report = $this->service()->apply_plan( SyncReport::from_array( $preview['report'] ) );
		if ( ! $report->is_failed() ) {
			// A failed apply (another run holds the lock) saved nothing; keep the preview for a retry.
			delete_transient( self::preview_key() );
		}

		$this->push_notice( self::notice_type( $report ), Messages::summary( $report ) );
		$this->redirect( 'log' );
	}

	/**
	 * Handles "Discard preview".
	 */
	public function handle_discard_preview(): void {
		$this->verify_request( 'wsps_discard_preview' );
		delete_transient( self::preview_key() );
		$this->redirect( 'sync' );
	}

	/**
	 * Handles "Sync now": a full run, live or dry depending on the settings.
	 */
	public function handle_sync_now(): void {
		$this->verify_request( 'wsps_sync_now' );

		$report = $this->service()->run( SyncReport::TRIGGER_MANUAL, (bool) $this->settings->get( 'dry_run' ) );
		if ( ! $report->is_failed() && ! $report->is_dry_run() ) {
			// Products changed, so the stored preview is out of date.
			delete_transient( self::preview_key() );
		}

		$this->push_notice( self::notice_type( $report ), Messages::summary( $report ) );
		$this->redirect( 'log' );
	}

	/**
	 * Formats a field value for display. Returns safe HTML.
	 *
	 * @param string          $field Field key.
	 * @param int|string|null $value Value.
	 * @return string
	 */
	public static function format_value( string $field, $value ): string {
		if ( FieldMapping::STOCK === $field ) {
			if ( null === $value ) {
				return '<span class="wsps-muted">' . esc_html__( 'not tracked', 'stock-price-sync-for-woocommerce' ) . '</span>';
			}
			return esc_html( number_format_i18n( (int) $value ) );
		}
		if ( null === $value || '' === $value ) {
			return '<span class="wsps-muted">' . esc_html__( 'none', 'stock-price-sync-for-woocommerce' ) . '</span>';
		}
		return wp_kses_post( wc_price( (float) $value ) );
	}

	/**
	 * Returns the edit link of a product; variations link to their parent.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string Empty when the product cannot be edited.
	 */
	public static function edit_link( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}
		$parent = wp_get_post_parent_id( $product_id );
		return (string) get_edit_post_link( $parent ? $parent : $product_id, 'raw' );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab Tab key.
	 * @return string
	 */
	public static function url( string $tab = 'settings' ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Field definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields(): array {
		return array(
			'source_type'       => array(
				'section' => 'wsps_source',
				'label'   => __( 'Feed format', 'stock-price-sync-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'csv'  => __( 'CSV', 'stock-price-sync-for-woocommerce' ),
					'json' => __( 'JSON', 'stock-price-sync-for-woocommerce' ),
				),
			),
			'source_url'        => array(
				'section'     => 'wsps_source',
				'label'       => __( 'Feed URL', 'stock-price-sync-for-woocommerce' ),
				'type'        => 'url',
				'class'       => 'large-text code',
				'placeholder' => 'https://supplier.example.com/stock.csv',
			),
			'auth_header_name'  => array(
				'section'     => 'wsps_source',
				'label'       => __( 'Auth header name', 'stock-price-sync-for-woocommerce' ),
				'type'        => 'text',
				'class'       => 'regular-text code',
				'placeholder' => 'X-Api-Key',
				'description' => __( 'Optional. For feeds that need an API key or token, e.g. "Authorization" or "X-Api-Key".', 'stock-price-sync-for-woocommerce' ),
			),
			'auth_header_value' => array(
				'section' => 'wsps_source',
				'label'   => __( 'Auth header value', 'stock-price-sync-for-woocommerce' ),
				'type'    => 'password',
			),
			'csv_delimiter'     => array(
				'section'   => 'wsps_source',
				'label'     => __( 'CSV delimiter', 'stock-price-sync-for-woocommerce' ),
				'type'      => 'select',
				'row_class' => 'wsps-only-csv',
				'options'   => array(
					'comma'     => __( 'Comma ( , )', 'stock-price-sync-for-woocommerce' ),
					'semicolon' => __( 'Semicolon ( ; )', 'stock-price-sync-for-woocommerce' ),
					'tab'       => __( 'Tab', 'stock-price-sync-for-woocommerce' ),
					'pipe'      => __( 'Pipe ( | )', 'stock-price-sync-for-woocommerce' ),
				),
			),
			'json_items_path'   => array(
				'section'     => 'wsps_source',
				'label'       => __( 'JSON items path', 'stock-price-sync-for-woocommerce' ),
				'type'        => 'text',
				'class'       => 'regular-text code',
				'row_class'   => 'wsps-only-json',
				'placeholder' => 'data.items',
				'description' => __( 'Dot-separated path to the list of items. Leave empty when the document itself is the list.', 'stock-price-sync-for-woocommerce' ),
			),
			'decimal_separator' => array(
				'section'     => 'wsps_source',
				'label'       => __( 'Decimal separator', 'stock-price-sync-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'dot'   => __( 'Dot (1,299.90)', 'stock-price-sync-for-woocommerce' ),
					'comma' => __( 'Comma (1.299,90)', 'stock-price-sync-for-woocommerce' ),
				),
				'description' => __( 'How numbers written as text are formatted in the feed. Values in the other format are reported as invalid instead of being misread.', 'stock-price-sync-for-woocommerce' ),
			),
			'map_sku'           => array(
				'section'  => 'wsps_mapping',
				'label'    => __( 'SKU', 'stock-price-sync-for-woocommerce' ),
				'type'     => 'text',
				'class'    => 'regular-text',
				'required' => true,
			),
			'map_stock'         => array(
				'section' => 'wsps_mapping',
				'label'   => __( 'Stock quantity', 'stock-price-sync-for-woocommerce' ),
				'type'    => 'text',
				'class'   => 'regular-text',
			),
			'map_regular_price' => array(
				'section' => 'wsps_mapping',
				'label'   => __( 'Regular price', 'stock-price-sync-for-woocommerce' ),
				'type'    => 'text',
				'class'   => 'regular-text',
			),
			'map_sale_price'    => array(
				'section'     => 'wsps_mapping',
				'label'       => __( 'Sale price', 'stock-price-sync-for-woocommerce' ),
				'type'        => 'text',
				'class'       => 'regular-text',
				'description' => __( 'An empty value in the feed removes the sale price.', 'stock-price-sync-for-woocommerce' ),
			),
			'update'            => array(
				'section' => 'wsps_behavior',
				'label'   => __( 'Update', 'stock-price-sync-for-woocommerce' ),
				'type'    => 'checkboxes',
				'options' => array(
					'update_stock'  => __( 'Stock quantities', 'stock-price-sync-for-woocommerce' ),
					'update_prices' => __( 'Regular and sale prices', 'stock-price-sync-for-woocommerce' ),
				),
			),
			'schedule'          => array(
				'section'     => 'wsps_behavior',
				'label'       => __( 'Schedule', 'stock-price-sync-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array_combine( Settings::SCHEDULES, array_map( array( Messages::class, 'schedule_label' ), Settings::SCHEDULES ) ),
				'description' => __( 'Runs through WP-Cron. On low-traffic sites, trigger WP-Cron from a real server cron for exact timing.', 'stock-price-sync-for-woocommerce' ),
			),
			'dry_run'           => array(
				'section'        => 'wsps_behavior',
				'label'          => __( 'Dry run', 'stock-price-sync-for-woocommerce' ),
				'type'           => 'checkbox',
				'checkbox_label' => __( 'Only log what would change; never save products', 'stock-price-sync-for-woocommerce' ),
				'description'    => __( 'Applies to scheduled runs, "Sync now" and WP-CLI. Useful while testing a new feed.', 'stock-price-sync-for-woocommerce' ),
			),
		);
	}

	/**
	 * Builds the sync service.
	 *
	 * @return SyncService
	 */
	private function service(): SyncService {
		return ( $this->service_factory )();
	}

	/**
	 * Checks capability and nonce of a form submission; stops the request otherwise.
	 *
	 * @param string $action Nonce action.
	 */
	private function verify_request( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'stock-price-sync-for-woocommerce' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirects to a tab and ends the request.
	 *
	 * @param string $tab Tab key.
	 * @return never
	 */
	private function redirect( string $tab ): never {
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/**
	 * Returns the current user's stored preview, or null.
	 *
	 * @return array{report: array<string, mixed>, fingerprint: string, created: int}|null
	 */
	private function get_preview(): ?array {
		$preview = get_transient( self::preview_key() );
		if ( ! is_array( $preview ) || ! isset( $preview['report'], $preview['fingerprint'], $preview['created'] ) ) {
			return null;
		}
		return $preview;
	}

	/**
	 * Transient key of the current user's preview.
	 *
	 * @return string
	 */
	private static function preview_key(): string {
		return 'wsps_preview_' . get_current_user_id();
	}

	/**
	 * Stores a one-time notice for the current user.
	 *
	 * @param string $type    Notice type: success, warning, error or info.
	 * @param string $message Plain-text message.
	 */
	private function push_notice( string $type, string $message ): void {
		set_transient(
			'wsps_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Returns and removes the current user's notice.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function pull_notice(): ?array {
		$key    = 'wsps_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'type'    => in_array( $notice['type'] ?? '', array( 'success', 'warning', 'error', 'info' ), true ) ? $notice['type'] : 'info',
			'message' => (string) ( $notice['message'] ?? '' ),
		);
	}

	/**
	 * Notice type that matches a report.
	 *
	 * @param SyncReport $report Report.
	 * @return string
	 */
	private static function notice_type( SyncReport $report ): string {
		if ( $report->is_failed() ) {
			return 'error';
		}
		if ( $report->count( SyncReport::STATUS_ERROR ) > 0 || $report->count( SyncReport::STATUS_INVALID ) > 0 ) {
			return 'warning';
		}
		return 'success';
	}

	/**
	 * Returns the requested tab, defaulting to settings.
	 *
	 * @return string
	 */
	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only, no state change.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		return in_array( $tab, self::TABS, true ) ? $tab : 'settings';
	}
}
