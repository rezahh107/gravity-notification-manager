<?php
/**
 * Entry-field value access seam for recipient resolution.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient;

/**
 * Reads one configured Gravity Forms field/input value from the current entry.
 */
interface EntryFieldReader {

	/**
	 * Read one configured field/input selector.
	 *
	 * @param string $selector Gravity Forms field or input ID selector.
	 * @param array  $entry    Current Gravity Forms Entry object.
	 * @param array  $form     Current Gravity Forms Form object.
	 * @return mixed
	 */
	public function read( string $selector, array $entry, array $form );
}
