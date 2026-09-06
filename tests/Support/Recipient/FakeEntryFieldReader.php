<?php
/**
 * Deterministic Entry-field reader fake.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Recipient;

use GravityNotify\Recipient\EntryFieldReader;

/**
 * Returns configured field/input values without Gravity Forms runtime access.
 */
final class FakeEntryFieldReader implements EntryFieldReader {

	/**
	 * Selector-to-value map.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $values Selector map.
	 */
	public function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * Read one configured fake field value.
	 *
	 * @param string $selector Configured field/input selector.
	 * @param array  $entry Ignored Entry object.
	 * @param array  $form Ignored Form object.
	 * @return mixed
	 */
	public function read( string $selector, array $entry, array $form ) {
		unset( $entry, $form );
		return $this->values[ $selector ] ?? null;
	}
}
