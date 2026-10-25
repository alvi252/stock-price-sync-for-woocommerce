<?php
/**
 * Plugin settings.
 *
 * @package WSPS
 */

namespace WSPS;

use WSPS\Feed\CsvParser;
use WSPS\Feed\FieldMapping;
use WSPS\Feed\JsonParser;
use WSPS\Feed\NumberParser;
use WSPS\Feed\ParserInterface;
use WSPS\Sync\SyncOptions;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitizes and interprets the single settings option.
 */
final class Settings {

	public const OPTION = 'wsps_settings';
	public const GROUP  = 'wsps_settings_group';

	public const SCHEDULES = array( 'off', 'wsps_every_15_minutes', 'hourly', 'twicedaily', 'daily' );

	private const DELIMITERS = array(
		'comma'     => ',',
		'semicolon' => ';',
		'tab'       => "\t",
		'pipe'      => '|',
	);

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'source_type'       => 'csv',
			'source_url'        => '',
			'auth_header_name'  => '',
			'auth_header_value' => '',
			'csv_delimiter'     => 'comma',
			'decimal_separator' => 'dot',
			'json_items_path'   => '',
			'map_sku'           => 'sku',
			'map_stock'         => 'stock',
			'map_regular_price' => 'regular_price',
			'map_sale_price'    => '',
			'update_stock'      => true,
			'update_prices'     => true,
			'schedule'          => 'off',
			'dry_run'           => false,
		);
	}

	/**
	 * Returns all settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Registers the option with the Settings API so every write is sanitized.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitizes submitted settings.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$current  = $this->all();
		$defaults = self::defaults();

		if ( ! is_array( $input ) ) {
			return $current;
		}

		$clean = array();

		$clean['source_type'] = self::choice( $input['source_type'] ?? '', array( 'csv', 'json' ), $defaults['source_type'] );

		$url                 = trim( (string) ( $input['source_url'] ?? '' ) );
		$clean['source_url'] = '' === $url ? '' : esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' !== $url && '' === $clean['source_url'] ) {
			self::error( 'source_url', __( 'The feed URL must start with http:// or https://.', 'stock-price-sync-for-woocommerce' ) );
		}

		// Header names are RFC 7230 tokens; anything else would break the request.
		$clean['auth_header_name'] = (string) preg_replace( '/[^A-Za-z0-9!#$%&\'*+.^_`|~-]/', '', (string) ( $input['auth_header_name'] ?? '' ) );

		// The secret is never printed back into the form, so an empty field means "keep the saved value",
		// but only for the same host: otherwise anyone allowed to edit the URL could send the saved key elsewhere.
		// Control characters are removed to rule out header injection; the token itself is kept as typed.
		$value     = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) ( $input['auth_header_value'] ?? '' ) ) );
		$same_host = strtolower( (string) wp_parse_url( $clean['source_url'], PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( (string) $current['source_url'], PHP_URL_HOST ) );
		if ( '' === $clean['auth_header_name'] ) {
			$value = '';
		} elseif ( '' === $value && $same_host ) {
			$value = (string) $current['auth_header_value'];
		} elseif ( '' === $value && '' !== (string) $current['auth_header_value'] ) {
			self::error( 'auth_header_value', __( 'The feed host changed, so the saved auth header value was removed. Enter it again for the new host.', 'stock-price-sync-for-woocommerce' ), 'warning' );
		}
		$clean['auth_header_value'] = $value;

		$clean['csv_delimiter']     = self::choice( $input['csv_delimiter'] ?? '', array_keys( self::DELIMITERS ), $defaults['csv_delimiter'] );
		$clean['decimal_separator'] = self::choice( $input['decimal_separator'] ?? '', array( 'dot', 'comma' ), $defaults['decimal_separator'] );
		$clean['json_items_path']   = (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) ( $input['json_items_path'] ?? '' ) );

		foreach ( array( 'map_sku', 'map_stock', 'map_regular_price', 'map_sale_price' ) as $key ) {
			$clean[ $key ] = trim( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ) );
		}
		if ( '' === $clean['map_sku'] ) {
			$clean['map_sku'] = '' !== $current['map_sku'] ? $current['map_sku'] : $defaults['map_sku'];
			self::error( 'map_sku', __( 'The SKU column is required to match products. The previous value was kept.', 'stock-price-sync-for-woocommerce' ) );
		}

		$clean['update_stock']  = ! empty( $input['update_stock'] );
		$clean['update_prices'] = ! empty( $input['update_prices'] );
		$clean['dry_run']       = ! empty( $input['dry_run'] );
		$clean['schedule']      = self::choice( $input['schedule'] ?? '', self::SCHEDULES, $defaults['schedule'] );

		if ( ! $clean['update_stock'] && ! $clean['update_prices'] ) {
			self::error( 'update_stock', __( 'Neither stock nor prices are selected, so a sync will not change anything.', 'stock-price-sync-for-woocommerce' ), 'warning' );
		}

		return $clean;
	}

	/**
	 * Builds the parser for the configured source type.
	 *
	 * @return ParserInterface
	 */
	public function parser(): ParserInterface {
		$settings = $this->all();
		if ( 'json' === $settings['source_type'] ) {
			return new JsonParser( (string) $settings['json_items_path'] );
		}
		return new CsvParser( self::DELIMITERS[ $settings['csv_delimiter'] ] ?? ',' );
	}

	/**
	 * Builds the field mapping.
	 *
	 * @return FieldMapping
	 */
	public function mapping(): FieldMapping {
		$settings = $this->all();
		return new FieldMapping(
			'' !== $settings['map_sku'] ? (string) $settings['map_sku'] : 'sku',
			(string) $settings['map_stock'],
			(string) $settings['map_regular_price'],
			(string) $settings['map_sale_price']
		);
	}

	/**
	 * Builds the number parser for the configured decimal separator.
	 *
	 * @return NumberParser
	 */
	public function number_parser(): NumberParser {
		return new NumberParser( 'comma' === $this->get( 'decimal_separator' ) ? NumberParser::DECIMAL_COMMA : NumberParser::DECIMAL_DOT );
	}

	/**
	 * Builds the diff options.
	 *
	 * @return SyncOptions
	 */
	public function sync_options(): SyncOptions {
		$settings = $this->all();
		return new SyncOptions( (bool) $settings['update_stock'], (bool) $settings['update_prices'], wc_get_price_decimals() );
	}

	/**
	 * Hash of every setting that affects what a sync would change.
	 *
	 * A previewed plan is only applied while this value is unchanged.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		$settings = $this->all();
		unset( $settings['schedule'], $settings['dry_run'], $settings['auth_header_value'] );
		ksort( $settings );
		return md5( (string) wp_json_encode( $settings ) . '|' . wc_get_price_decimals() );
	}

	/**
	 * Returns a value when it is one of the allowed choices, else the fallback.
	 *
	 * @param mixed    $value    Submitted value.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $fallback Value used otherwise.
	 * @return string
	 */
	private static function choice( $value, array $allowed, string $fallback ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Reports a validation message on the settings screen, when there is one.
	 *
	 * @param string $code    Field the message belongs to.
	 * @param string $message Message.
	 * @param string $type    Notice type: error, warning, success or info.
	 */
	private static function error( string $code, string $message, string $type = 'error' ): void {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}
		// The first save runs the sanitize callback twice (update_option falls back to add_option).
		foreach ( get_settings_errors( self::OPTION ) as $existing ) {
			if ( 'wsps_' . $code === $existing['code'] ) {
				return;
			}
		}
		add_settings_error( self::OPTION, 'wsps_' . $code, $message, $type );
	}
}
