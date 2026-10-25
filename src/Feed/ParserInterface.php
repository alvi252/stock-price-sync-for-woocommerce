<?php
/**
 * Feed parser contract.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a raw feed body into rows.
 */
interface ParserInterface {

	/**
	 * Parses the feed body.
	 *
	 * @param string $body Raw response body.
	 * @return ParsedFeed
	 * @throws ParseException When the feed as a whole cannot be read.
	 */
	public function parse( string $body ): ParsedFeed;
}
