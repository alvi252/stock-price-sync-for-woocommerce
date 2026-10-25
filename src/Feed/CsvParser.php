<?php
/**
 * CSV feed parser.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Parses CSV with a header row.
 *
 * Handles a UTF-8 byte order mark (Excel exports), CRLF / CR line endings,
 * quoted values with embedded delimiters or line breaks, and blank lines.
 */
final class CsvParser implements ParserInterface {

	private const BOM = "\xEF\xBB\xBF";

	/**
	 * Field delimiter, a single character.
	 *
	 * @var string
	 */
	private string $delimiter;

	/**
	 * Constructor.
	 *
	 * @param string $delimiter Field delimiter, a single character.
	 * @throws \InvalidArgumentException When the delimiter is not one character.
	 */
	public function __construct( string $delimiter = ',' ) {
		if ( 1 !== strlen( $delimiter ) ) {
			throw new \InvalidArgumentException( 'The CSV delimiter must be a single character.' );
		}
		$this->delimiter = $delimiter;
	}

	/**
	 * Parses the feed body.
	 *
	 * @param string $body Raw response body.
	 * @return ParsedFeed
	 * @throws ParseException When the feed is empty or the header is unusable.
	 */
	public function parse( string $body ): ParsedFeed {
		if ( 0 === strncmp( $body, self::BOM, 3 ) ) {
			$body = substr( $body, 3 );
		}
		if ( '' === trim( $body ) ) {
			throw ParseException::empty_feed();
		}
		// Classic Mac line endings are not understood by the CSV reader.
		if ( false === strpos( $body, "\n" ) ) {
			$body = str_replace( "\r", "\n", $body );
		}

		$file = new \SplTempFileObject();
		$file->fwrite( $body );
		$file->rewind();
		$file->setFlags( \SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::READ_AHEAD );
		// An empty escape character gives RFC 4180 behavior: quotes are escaped by doubling.
		$file->setCsvControl( $this->delimiter, '"', '' );

		$columns = null;
		$rows    = array();
		$number  = 0;

		foreach ( $file as $fields ) {
			if ( ! is_array( $fields ) || self::is_blank( $fields ) ) {
				continue;
			}
			++$number;

			if ( null === $columns ) {
				$columns = self::read_header( $fields );
				continue;
			}

			$fields = array_map( static fn( $value ): string => trim( (string) $value ), $fields );
			$count  = count( $fields );
			$width  = count( $columns );

			if ( $count > $width ) {
				// Extra values usually mean an unquoted delimiter inside a value:
				// every column after it is shifted, so nothing in the row can be trusted.
				$rows[] = new Row(
					$number,
					array(),
					Row::PROBLEM_COLUMN_COUNT,
					array(
						'expected' => $width,
						'actual'   => $count,
					)
				);
				continue;
			}

			// Trailing empty cells are often dropped by spreadsheet exports.
			$fields = array_pad( $fields, $width, '' );
			$rows[] = new Row( $number, array_combine( $columns, $fields ) );
		}

		if ( null === $columns ) {
			throw ParseException::empty_feed();
		}

		return new ParsedFeed( $rows, $columns );
	}

	/**
	 * Validates and normalizes the header row.
	 *
	 * @param array<int, string|null> $fields Header values.
	 * @return string[]
	 * @throws ParseException When a column name repeats.
	 */
	private static function read_header( array $fields ): array {
		$columns = array();
		foreach ( $fields as $index => $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				// Keep positions stable for unnamed columns; they can never be mapped.
				$name = '#' . ( $index + 1 );
			}
			if ( in_array( $name, $columns, true ) ) {
				throw ParseException::duplicate_column( $name );
			}
			$columns[] = $name;
		}
		return $columns;
	}

	/**
	 * Whether a parsed line contains only empty values.
	 *
	 * @param array<int, string|null> $fields Parsed values.
	 * @return bool
	 */
	private static function is_blank( array $fields ): bool {
		foreach ( $fields as $value ) {
			if ( null !== $value && '' !== trim( $value ) ) {
				return false;
			}
		}
		return true;
	}
}
