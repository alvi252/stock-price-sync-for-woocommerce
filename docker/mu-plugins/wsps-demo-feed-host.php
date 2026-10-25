<?php
/**
 * Demo environment only: the fake supplier feed runs in a container on a
 * private Docker network, which wp_safe_remote_get() refuses by default.
 * This is the same filter a site with an ERP on its internal network would use.
 *
 * @package WSPS
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'http_request_host_is_external',
	static function ( $is_external, $host ) {
		return $is_external || 'feeds.supplier.test' === $host;
	},
	10,
	2
);
