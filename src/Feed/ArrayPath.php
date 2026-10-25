<?php
/**
 * Dot-notation access to nested arrays.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Reads values such as "price.regular" from nested arrays.
 */
final class ArrayPath {

	/**
	 * Returns the value at a path.
	 *
	 * An exact key match wins over dot notation, so a CSV column literally
	 * named "price.eur" keeps working.
	 *
	 * @param array<mixed> $data  Array to read from.
	 * @param string       $path  Key or dot-separated path.
	 * @param bool         $found Set to whether the path exists.
	 * @return mixed The value, or null when the path does not exist.
	 */
	public static function get( array $data, string $path, ?bool &$found = null ): mixed {
		$found = false;
		if ( '' === $path ) {
			return null;
		}

		if ( array_key_exists( $path, $data ) ) {
			$found = true;
			return $data[ $path ];
		}

		$current = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}

		$found = true;
		return $current;
	}
}
