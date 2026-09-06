<?php
/**
 * Native Gravity Forms Entry field reader.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient\Native;

use GravityNotify\Recipient\EntryFieldReader;

/**
 * Uses the documented GFAPI field object and get_value_export() contract.
 */
final class GravityFormsEntryFieldReader implements EntryFieldReader {

	/**
	 * Read one configured Gravity Forms field/input value.
	 *
	 * @param string $selector Gravity Forms field or input ID selector.
	 * @param array  $entry    Current Gravity Forms Entry object.
	 * @param array  $form     Current Gravity Forms Form object.
	 * @return mixed
	 */
	public function read( string $selector, array $entry, array $form ) {
		if ( ! class_exists( '\\GFAPI' ) ) {
			return null;
		}

		$field_id = false === strpos( $selector, '.' ) ? $selector : strstr( $selector, '.', true );
		$field    = \GFAPI::get_field( $form, $field_id );

		if ( ! is_object( $field ) || ! method_exists( $field, 'get_value_export' ) ) {
			return null;
		}

		return $field->get_value_export( $entry, $selector );
	}
}
