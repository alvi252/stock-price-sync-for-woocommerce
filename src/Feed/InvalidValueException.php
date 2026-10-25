<?php
/**
 * Exception for a single feed row that cannot be used.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown for one bad row or value. The row is skipped and reported as
 * "invalid"; the rest of the feed is still processed.
 */
final class InvalidValueException extends \RuntimeException {

	public const MISSING_SKU        = 'missing_sku';
	public const NOT_A_NUMBER       = 'not_a_number';
	public const NEGATIVE           = 'negative';
	public const NOT_INTEGER        = 'not_integer';
	public const TOO_LARGE          = 'too_large';
	public const ZERO_PRICE         = 'zero_price';
	public const SALE_ABOVE_REGULAR = 'sale_above_regular';
	public const MALFORMED_ROW      = 'malformed_row';
	public const DUPLICATE_SKU      = 'duplicate_sku';

	/**
	 * Machine-readable reason, one of the class constants.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Field the problem belongs to: sku, stock, regular_price, sale_price or row.
	 *
	 * @var string
	 */
	private string $field;

	/**
	 * Offending value as text, for the report.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Extra values that describe the problem.
	 *
	 * @var array<string, mixed>
	 */
	private array $context;

	/**
	 * Constructor.
	 *
	 * @param string               $reason  One of the class constants.
	 * @param string               $field   Field the problem belongs to.
	 * @param string               $value   Offending value as text.
	 * @param string               $message English description for logs.
	 * @param array<string, mixed> $context Extra values that describe the problem.
	 */
	public function __construct( string $reason, string $field, string $value, string $message, array $context = array() ) {
		parent::__construct( $message );
		$this->reason  = $reason;
		$this->field   = $field;
		$this->value   = $value;
		$this->context = $context;
	}

	/**
	 * Returns the machine-readable reason.
	 *
	 * @return string
	 */
	public function get_reason(): string {
		return $this->reason;
	}

	/**
	 * Returns the field the problem belongs to.
	 *
	 * @return string
	 */
	public function get_field(): string {
		return $this->field;
	}

	/**
	 * Returns the offending value as text.
	 *
	 * @return string
	 */
	public function get_value(): string {
		return $this->value;
	}

	/**
	 * Returns extra values that describe the problem.
	 *
	 * @return array<string, mixed>
	 */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * The row has no SKU.
	 *
	 * @return self
	 */
	public static function missing_sku(): self {
		return new self( self::MISSING_SKU, 'sku', '', 'SKU is empty.' );
	}

	/**
	 * The value is not a number.
	 *
	 * @param string $field Field name.
	 * @param string $value Raw value.
	 * @return self
	 */
	public static function not_a_number( string $field, string $value ): self {
		return new self( self::NOT_A_NUMBER, $field, $value, sprintf( '%s is not a valid number: "%s".', $field, $value ) );
	}

	/**
	 * The value is negative.
	 *
	 * @param string $field Field name.
	 * @param string $value Raw value.
	 * @return self
	 */
	public static function negative( string $field, string $value ): self {
		return new self( self::NEGATIVE, $field, $value, sprintf( '%s cannot be negative: "%s".', $field, $value ) );
	}

	/**
	 * The stock value has a fractional part.
	 *
	 * @param string $field Field name.
	 * @param string $value Raw value.
	 * @return self
	 */
	public static function not_integer( string $field, string $value ): self {
		return new self( self::NOT_INTEGER, $field, $value, sprintf( '%s must be a whole number: "%s".', $field, $value ) );
	}

	/**
	 * The value exceeds the allowed maximum.
	 *
	 * @param string $field Field name.
	 * @param string $value Raw value.
	 * @param int    $max   Allowed maximum.
	 * @return self
	 */
	public static function too_large( string $field, string $value, int $max ): self {
		return new self( self::TOO_LARGE, $field, $value, sprintf( '%s is larger than %d: "%s".', $field, $max, $value ), array( 'max' => $max ) );
	}

	/**
	 * The price is 0 while zero prices are not allowed.
	 *
	 * @param string $field Field name.
	 * @param string $value Raw value.
	 * @return self
	 */
	public static function zero_price( string $field, string $value ): self {
		return new self( self::ZERO_PRICE, $field, $value, sprintf( '%s is zero: "%s".', $field, $value ) );
	}

	/**
	 * The sale price is higher than the regular price.
	 *
	 * @param string $sale    Normalized sale price.
	 * @param string $regular Normalized regular price.
	 * @return self
	 */
	public static function sale_above_regular( string $sale, string $regular ): self {
		return new self(
			self::SALE_ABOVE_REGULAR,
			'sale_price',
			$sale,
			sprintf( 'Sale price %s is higher than the regular price %s.', $sale, $regular ),
			array( 'regular' => $regular )
		);
	}

	/**
	 * The row could not be read (wrong column count, not an object, ...).
	 *
	 * @param string               $problem Problem code reported by the parser.
	 * @param array<string, mixed> $context Values reported by the parser.
	 * @return self
	 */
	public static function malformed_row( string $problem, array $context = array() ): self {
		return new self(
			self::MALFORMED_ROW,
			'row',
			'',
			'Malformed row: ' . $problem,
			array_merge( array( 'problem' => $problem ), $context )
		);
	}

	/**
	 * The SKU already appeared earlier in the feed.
	 *
	 * @param string $sku       SKU.
	 * @param int    $first_row Row number of the first occurrence.
	 * @return self
	 */
	public static function duplicate_sku( string $sku, int $first_row ): self {
		return new self(
			self::DUPLICATE_SKU,
			'sku',
			$sku,
			sprintf( 'Duplicate SKU, first seen in row %d.', $first_row ),
			array( 'first_row' => $first_row )
		);
	}
}
