<?php
/**
 * One field change on one product.
 *
 * @package WSPS
 */

namespace WSPS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * A planned or applied change: field, current value, target value.
 */
final class Change {

	/**
	 * Constructor.
	 *
	 * @param string          $field One of the FieldMapping constants except sku.
	 * @param int|string|null $from  Current value; null for stock that is not tracked.
	 * @param int|string      $to    Target value; '' removes a sale price.
	 */
	public function __construct(
		public readonly string $field,
		public readonly int|string|null $from,
		public readonly int|string $to
	) {
	}

	/**
	 * Exports the change for storage.
	 *
	 * @return array{field: string, from: int|string|null, to: int|string}
	 */
	public function to_array(): array {
		return array(
			'field' => $this->field,
			'from'  => $this->from,
			'to'    => $this->to,
		);
	}

	/**
	 * Restores a change exported with to_array().
	 *
	 * @param array<string, mixed> $data Exported change.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self( (string) ( $data['field'] ?? '' ), $data['from'] ?? null, $data['to'] ?? '' );
	}
}
