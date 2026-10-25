<?php
/**
 * Exception for a feed that cannot be read at all.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when the feed as a whole is unusable. A sync run stops on it.
 *
 * Carries a machine-readable reason and context, so the WordPress layer can
 * show a translated message. The exception message itself is plain English
 * and is meant for logs and tests, never echoed to the browser as is.
 */
final class ParseException extends \RuntimeException {

	public const EMPTY_FEED           = 'empty_feed';
	public const INVALID_JSON         = 'invalid_json';
	public const ITEMS_PATH_NOT_FOUND = 'items_path_not_found';
	public const NOT_A_LIST           = 'not_a_list';
	public const DUPLICATE_COLUMN     = 'duplicate_column';
	public const MISSING_COLUMNS      = 'missing_columns';
	public const MISSING_KEYS         = 'missing_keys';

	/**
	 * Machine-readable reason, one of the class constants.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Values that describe the problem (column names, JSON error, ...).
	 *
	 * @var array<string, mixed>
	 */
	private array $context;

	/**
	 * Constructor.
	 *
	 * @param string               $reason  One of the class constants.
	 * @param string               $message English description for logs.
	 * @param array<string, mixed> $context Values that describe the problem.
	 */
	public function __construct( string $reason, string $message, array $context = array() ) {
		parent::__construct( $message );
		$this->reason  = $reason;
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
	 * Returns the values that describe the problem.
	 *
	 * @return array<string, mixed>
	 */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * The response body is empty or whitespace only.
	 *
	 * @return self
	 */
	public static function empty_feed(): self {
		return new self( self::EMPTY_FEED, 'The feed is empty.' );
	}

	/**
	 * The body is not valid JSON.
	 *
	 * @param string $error Error reported by the JSON decoder.
	 * @return self
	 */
	public static function invalid_json( string $error ): self {
		return new self( self::INVALID_JSON, 'The feed is not valid JSON: ' . $error, array( 'error' => $error ) );
	}

	/**
	 * The configured JSON items path does not exist in the document.
	 *
	 * @param string $path Dot-separated path, e.g. "data.items".
	 * @return self
	 */
	public static function items_path_not_found( string $path ): self {
		return new self( self::ITEMS_PATH_NOT_FOUND, 'Items path not found in the feed: ' . $path, array( 'path' => $path ) );
	}

	/**
	 * The JSON document (or the value at the items path) is not a list.
	 *
	 * @return self
	 */
	public static function not_a_list(): self {
		return new self( self::NOT_A_LIST, 'The feed does not contain a list of items.' );
	}

	/**
	 * The CSV header contains the same column twice.
	 *
	 * @param string $column Column name.
	 * @return self
	 */
	public static function duplicate_column( string $column ): self {
		return new self( self::DUPLICATE_COLUMN, 'Duplicate column in the CSV header: ' . $column, array( 'column' => $column ) );
	}

	/**
	 * Mapped columns are absent from the CSV header.
	 *
	 * @param string[] $missing   Mapped column names that were not found.
	 * @param string[] $available Column names present in the header.
	 * @return self
	 */
	public static function missing_columns( array $missing, array $available ): self {
		return new self(
			self::MISSING_COLUMNS,
			'Mapped columns not found in the feed: ' . implode( ', ', $missing ),
			array(
				'missing'   => $missing,
				'available' => $available,
			)
		);
	}

	/**
	 * Mapped JSON keys are absent from every item.
	 *
	 * @param string[] $missing   Mapped key paths that no item contains.
	 * @param string[] $available Top-level keys of the first item.
	 * @return self
	 */
	public static function missing_keys( array $missing, array $available ): self {
		return new self(
			self::MISSING_KEYS,
			'Mapped keys not found in any item: ' . implode( ', ', $missing ),
			array(
				'missing'   => $missing,
				'available' => $available,
			)
		);
	}
}
