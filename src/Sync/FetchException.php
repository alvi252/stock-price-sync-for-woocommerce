<?php
/**
 * Exception for a feed that cannot be downloaded.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when the feed cannot be downloaded. The message is translated
 * plain text: it is stored in the log and escaped where it is displayed.
 */
final class FetchException extends \RuntimeException {
}
