<?php
/**
 * Unit test bootstrap.
 *
 * The tested classes are WordPress-independent. Only the ABSPATH constant is
 * defined, so their "defined( 'ABSPATH' ) || exit;" guard passes; any call to
 * a WordPress function would fail with "Call to undefined function".
 *
 * @package WSPS
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( function_exists( 'add_action' ) ) {
	throw new RuntimeException( 'Unit tests must run without WordPress.' );
}
