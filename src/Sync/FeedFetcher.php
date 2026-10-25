<?php
/**
 * Downloads the feed.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

use WSPS\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the configured feed URL with the WordPress HTTP API.
 *
 * Uses wp_safe_remote_get(), which refuses loopback and private network
 * addresses, so the settings screen cannot be used to read internal services.
 * A feed on an internal host (an ERP on the office network) is allowed with
 * WordPress' own filter:
 *
 *     add_filter( 'http_request_host_is_external', fn( $ok, $host ) => $ok || 'erp.local' === $host, 10, 2 );
 */
final class FeedFetcher {

	/**
	 * Largest feed accepted, in bytes.
	 */
	private const MAX_BYTES = 50 * MB_IN_BYTES;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Downloads the feed body.
	 *
	 * @return string
	 * @throws FetchException When no URL is set, the request fails or the response is not usable.
	 */
	public function fetch(): string {
		$settings = $this->settings->all();
		$url      = (string) $settings['source_url'];

		if ( '' === $url ) {
			throw new FetchException( __( 'No feed URL is configured.', 'stock-price-sync-for-woocommerce' ) );
		}

		$headers = array(
			'Accept' => 'json' === $settings['source_type'] ? 'application/json' : 'text/csv, text/plain;q=0.9, */*;q=0.1',
		);
		if ( '' !== $settings['auth_header_name'] && '' !== $settings['auth_header_value'] ) {
			$headers[ $settings['auth_header_name'] ] = $settings['auth_header_value'];
		}

		$args = array(
			/**
			 * Filters the feed request timeout in seconds.
			 *
			 * @param int $timeout Timeout in seconds. Default 30.
			 */
			'timeout'             => (int) apply_filters( 'wsps_fetch_timeout', 30 ),
			'redirection'         => 3,
			'headers'             => $headers,
			// One byte more than allowed, so an oversized feed is detected instead of silently cut.
			'limit_response_size' => self::MAX_BYTES + 1,
			'user-agent'          => 'StockPriceSyncForWooCommerce/' . WSPS_VERSION . '; ' . home_url( '/' ),
		);

		/**
		 * Filters the arguments passed to wp_remote_get() for the feed request.
		 *
		 * @param array  $args Request arguments.
		 * @param string $url  Feed URL.
		 */
		$args = (array) apply_filters( 'wsps_fetch_args', $args, $url );

		$response = wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new FetchException(
				sprintf(
					/* translators: %s: error message from the HTTP request. */
					__( 'The feed could not be downloaded: %s', 'stock-price-sync-for-woocommerce' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new FetchException(
				sprintf(
					/* translators: 1: HTTP status code, 2: HTTP status message. */
					__( 'The feed server answered with HTTP %1$d %2$s.', 'stock-price-sync-for-woocommerce' ),
					$code,
					wp_remote_retrieve_response_message( $response )
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		// The HTTP API stops reading at the limit and still reports success; a cut
		// CSV row could turn "125" into "1", so an oversized feed is rejected.
		if ( strlen( $body ) > self::MAX_BYTES ) {
			throw new FetchException(
				sprintf(
					/* translators: %s: maximum size, e.g. "50 MB". */
					__( 'The feed is larger than %s and was not processed.', 'stock-price-sync-for-woocommerce' ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		return $body;
	}
}
