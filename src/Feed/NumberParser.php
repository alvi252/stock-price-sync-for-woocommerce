<?php
/**
 * Strict parsing of stock quantities and prices.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Parses numbers written with a known decimal separator.
 *
 * Accepts thousands separators only in correct groups of three, so
 * "1,299.00" passes in dot mode while "12,5" is rejected instead of being
 * read as 125. That catches a feed written with the other decimal separator
 * before it reaches the store.
 */
final class NumberParser {

	public const DECIMAL_DOT   = '.';
	public const DECIMAL_COMMA = ',';

	/**
	 * Largest stock quantity accepted; anything above is almost certainly a data error.
	 */
	public const MAX_STOCK = 999999999;

	/**
	 * Decimal separator expected in text values.
	 *
	 * @var string
	 */
	private string $decimal_separator;

	/**
	 * Constructor.
	 *
	 * @param string $decimal_separator Either "." or ",".
	 * @throws \InvalidArgumentException For any other separator.
	 */
	public function __construct( string $decimal_separator = self::DECIMAL_DOT ) {
		if ( self::DECIMAL_DOT !== $decimal_separator && self::DECIMAL_COMMA !== $decimal_separator ) {
			throw new \InvalidArgumentException( 'The decimal separator must be "." or ",".' );
		}
		$this->decimal_separator = $decimal_separator;
	}

	/**
	 * Parses a stock quantity.
	 *
	 * @param mixed  $raw   Raw value from the feed (string, int or float).
	 * @param string $field Field name used in error reports.
	 * @return int
	 * @throws InvalidValueException When the value is not a whole number from 0 to MAX_STOCK.
	 */
	public function parse_stock( mixed $raw, string $field = FieldMapping::STOCK ): int {
		[ $integer, $fraction ] = $this->split( $raw, $field );

		if ( '' !== rtrim( $fraction, '0' ) ) {
			throw InvalidValueException::not_integer( $field, self::describe( $raw ) );
		}

		$integer = ltrim( $integer, '0' );
		if ( strlen( $integer ) > strlen( (string) self::MAX_STOCK ) || (int) $integer > self::MAX_STOCK ) {
			throw InvalidValueException::too_large( $field, self::describe( $raw ), self::MAX_STOCK );
		}

		return (int) $integer;
	}

	/**
	 * Parses a price and rounds it to the store's number of decimals.
	 *
	 * @param mixed  $raw      Raw value from the feed (string, int or float).
	 * @param int    $decimals Number of decimals used by the store.
	 * @param string $field    Field name used in error reports.
	 * @return string Price with a dot as decimal separator, e.g. "1299.00".
	 * @throws InvalidValueException When the value is not a non-negative number.
	 */
	public function parse_price( mixed $raw, int $decimals, string $field = FieldMapping::REGULAR_PRICE ): string {
		[ $integer, $fraction ] = $this->split( $raw, $field );

		return self::format( (float) ( $integer . '.' . ( '' === $fraction ? '0' : $fraction ) ), $decimals );
	}

	/**
	 * Formats a number as a price string with a dot decimal separator.
	 *
	 * @param float $value    Value.
	 * @param int   $decimals Number of decimals.
	 * @return string
	 */
	public static function format( float $value, int $decimals ): string {
		return number_format( round( $value, max( 0, $decimals ) ), max( 0, $decimals ), '.', '' );
	}

	/**
	 * Splits a raw value into integer and fraction digits.
	 *
	 * @param mixed  $raw   Raw value.
	 * @param string $field Field name used in error reports.
	 * @return array{0: string, 1: string} Integer digits and fraction digits.
	 * @throws InvalidValueException When the value is not a non-negative number.
	 */
	private function split( mixed $raw, string $field ): array {
		if ( is_int( $raw ) ) {
			if ( $raw < 0 ) {
				throw InvalidValueException::negative( $field, (string) $raw );
			}
			return array( (string) $raw, '' );
		}

		if ( is_float( $raw ) ) {
			if ( ! is_finite( $raw ) ) {
				throw InvalidValueException::not_a_number( $field, self::describe( $raw ) );
			}
			if ( $raw < 0 ) {
				throw InvalidValueException::negative( $field, self::describe( $raw ) );
			}
			// 14 significant digits avoids binary noise such as 0.1 + 0.2.
			$text = rtrim( rtrim( sprintf( '%.14F', $raw ), '0' ), '.' );
			$dot  = strpos( $text, '.' );
			return false === $dot ? array( $text, '' ) : array( substr( $text, 0, $dot ), substr( $text, $dot + 1 ) );
		}

		if ( ! is_string( $raw ) ) {
			throw InvalidValueException::not_a_number( $field, self::describe( $raw ) );
		}

		// Regular, no-break and narrow no-break spaces.
		$text = trim( $raw, " \t\n\r\0\x0B\u{00A0}\u{202F}" );

		$negative = false;
		if ( '' !== $text && ( '-' === $text[0] || '+' === $text[0] ) ) {
			$negative = '-' === $text[0];
			$text     = ltrim( substr( $text, 1 ) );
		}

		$decimal = preg_quote( $this->decimal_separator, '/' );
		$group   = self::DECIMAL_DOT === $this->decimal_separator ? '[, \x{00A0}\x{202F}]' : '[. \x{00A0}\x{202F}]';
		$pattern = '/^(?<int>\d{1,3}(?:(?<sep>' . $group . ')\d{3})(?:\k<sep>\d{3})*|\d+)(?:' . $decimal . '(?<frac>\d+))?$/u';

		if ( ! preg_match( $pattern, $text, $matches ) ) {
			throw InvalidValueException::not_a_number( $field, $raw );
		}

		$integer  = (string) preg_replace( '/\D/u', '', $matches['int'] );
		$fraction = $matches['frac'] ?? '';

		if ( $negative && '' !== trim( $integer . $fraction, '0' ) ) {
			throw InvalidValueException::negative( $field, $raw );
		}

		return array( $integer, $fraction );
	}

	/**
	 * Describes a raw value for an error report.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	private static function describe( mixed $raw ): string {
		if ( is_string( $raw ) ) {
			return $raw;
		}
		if ( is_bool( $raw ) ) {
			return $raw ? 'true' : 'false';
		}
		if ( is_float( $raw ) && is_nan( $raw ) ) {
			return 'NAN';
		}
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return (string) $raw;
		}
		return get_debug_type( $raw );
	}
}
