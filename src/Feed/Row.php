<?php
/**
 * One record read from a feed.
 *
 * @package WSPS
 */

namespace WSPS\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * A feed record before mapping: raw values keyed by column or JSON key.
 */
final class Row {

	public const PROBLEM_COLUMN_COUNT  = 'column_count';
	public const PROBLEM_NOT_AN_OBJECT = 'not_an_object';

	/**
	 * Constructor.
	 *
	 * @param int                  $number          Row number as a person would count it
	 *                                              (CSV: record number with the header as 1,
	 *                                              blank lines not counted; JSON: 1-based item index).
	 * @param array<string, mixed> $data            Raw values.
	 * @param string|null          $problem         Problem code when the record is unreadable.
	 * @param array<string, mixed> $problem_context Values that describe the problem.
	 */
	public function __construct(
		public readonly int $number,
		public readonly array $data,
		public readonly ?string $problem = null,
		public readonly array $problem_context = array()
	) {
	}
}
