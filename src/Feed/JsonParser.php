<?php
/**
 * JSON feed parser.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Parses a JSON list of objects, either at the document root or at a
 * configurable dot-separated path such as "data.items".
 */
final class JsonParser implements ParserInterface {

	private const BOM = "\xEF\xBB\xBF";

	/**
	 * Dot-separated path to the list of items; empty for the document root.
	 *
	 * @var string
	 */
	private string $items_path;

	/**
	 * Constructor.
	 *
	 * @param string $items_path Dot-separated path to the list of items; empty for the root.
	 */
	public function __construct( string $items_path = '' ) {
		$this->items_path = trim( $items_path );
	}

	/**
	 * Parses the feed body.
	 *
	 * @param string $body Raw response body.
	 * @return ParsedFeed
	 * @throws ParseException When the document is invalid or holds no list of items.
	 */
	public function parse( string $body ): ParsedFeed {
		if ( 0 === strncmp( $body, self::BOM, 3 ) ) {
			$body = substr( $body, 3 );
		}
		if ( '' === trim( $body ) ) {
			throw ParseException::empty_feed();
		}

		try {
			// Big integers stay strings instead of silently losing precision as floats.
			$data = json_decode( $body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException $e ) {
			throw ParseException::invalid_json( $e->getMessage() );
		}

		if ( '' !== $this->items_path ) {
			$found = false;
			$data  = is_array( $data ) ? ArrayPath::get( $data, $this->items_path, $found ) : null;
			if ( ! $found ) {
				throw ParseException::items_path_not_found( $this->items_path );
			}
		}

		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			throw ParseException::not_a_list();
		}

		$rows = array();
		foreach ( $data as $index => $item ) {
			$number = $index + 1;
			if ( ! is_array( $item ) || ( array() !== $item && array_is_list( $item ) ) ) {
				$rows[] = new Row( $number, array(), Row::PROBLEM_NOT_AN_OBJECT );
				continue;
			}
			$rows[] = new Row( $number, $item );
		}

		return new ParsedFeed( $rows );
	}
}
