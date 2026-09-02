<?php
/**
 * Renders reusable, securely‑escaped form field markup.
 *
 * Every method returns a string of HTML that is safe to echo directly.
 *
 * @package GFSMS\Admin
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders reusable, securely‑escaped form field markup.
 *
 * Every method returns a string of HTML that is safe to echo directly.
 *
 * @since 3.0.0
 */
final class Settings_Fields {

	/**
	 * Settings option name.
	 *
	 * @since 3.0.0
	 */
	private const OPTION = GFSMS_SETTINGS_OPTION;

	/**
	 * Standard text input.
	 *
	 * @param string $name        Field key inside the settings array.
	 * @param string $value       Current value.
	 * @param string $placeholder Placeholder attribute.
	 *
	 * @return string Safe HTML string.
	 * @since 3.0.0
	 */
	public static function text( string $name, string $value = '', string $placeholder = '' ): string {
		return sprintf(
			'<input type="text" class="regular-text gfsms-control" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Password input (autocomplete disabled).
	 *
	 * @param string $name        Field key.
	 * @param string $value       Current value.
	 * @param string $placeholder Placeholder attribute.
	 *
	 * @return string Safe HTML string.
	 * @since 3.0.0
	 */
	public static function password( string $name, string $value = '', string $placeholder = '' ): string {
		return sprintf(
			'<input type="password" class="regular-text gfsms-control" autocomplete="off" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Multi‑line textarea.
	 *
	 * @param string $name  Field key.
	 * @param string $value Current text.
	 * @param int    $rows  Number of rows.
	 *
	 * @return string Safe HTML string.
	 * @since 3.0.0
	 */
	public static function textarea( string $name, string $value = '', int $rows = 5 ): string {
		return sprintf(
			'<textarea rows="%3$s" class="large-text code gfsms-control" name="%1$s[%2$s]">%4$s</textarea>',
			esc_attr( self::OPTION ),
			esc_attr( $name ),
			esc_attr( (string) $rows ),
			esc_textarea( $value )
		);
	}

	/**
	 * Numeric input with min/max constraints.
	 *
	 * @param string $name  Field key.
	 * @param int    $value Current value.
	 * @param int    $min   Minimum allowed value.
	 * @param int    $max   Maximum allowed value.
	 *
	 * @return string Safe HTML string.
	 * @since 3.0.0
	 */
	public static function number( string $name, int $value = 0, int $min = 0, int $max = 999999 ): string {
		return sprintf(
			'<input type="number" class="small-text gfsms-control" name="%1$s[%2$s]" value="%3$s" min="%4$s" max="%5$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max )
		);
	}

	/**
	 * Single checkbox, optionally with a visible label.
	 *
	 * @param string $name    Field key.
	 * @param bool   $checked Whether the box is checked.
	 * @param string $label   Text to display next to the checkbox.
	 *
	 * @return string Safe HTML string.
	 * @since 3.0.0
	 */
	public static function checkbox( string $name, bool $checked, string $label = '' ): string {
		return sprintf(
			'<label class="gfsms-checkbox"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> <span>%4$s</span></label>',
			esc_attr( self::OPTION ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Drop‑down select.
	 *
	 * @param string               $name    Field key.
	 * @param string               $value   Currently selected value.
	 * @param array<string,string> $options Associative array of value => label.
	 *
	 * @return string Safe HTML string.
	 * @since 3.0.0
	 */
	public static function select( string $name, string $value, array $options ): string {
		$html = sprintf(
			'<select class="gfsms-control" name="%1$s[%2$s]">',
			esc_attr( self::OPTION ),
			esc_attr( $name )
		);
		foreach ( $options as $k => $label ) {
			$html .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $k ),
				selected( $value, (string) $k, false ),
				esc_html( (string) $label )
			);
		}
		return $html . '</select>';
	}

	/**
	 * Set of checkboxes that share a single field key.
	 *
	 * @param string               $name     Field key.
	 * @param string[]             $selected Values that are currently checked.
	 * @param array<string,string> $options  Associative array of value => label.
	 *
	 * @return string Safe HTML string.
	 * @since 3.0.0
	 */
	public static function multicheck( string $name, array $selected, array $options ): string {
		$selected = array_map( 'strval', $selected );
		$html     = '<div class="gfsms-multicheck">';

		foreach ( $options as $value => $label ) {
			$is_checked = checked( in_array( (string) $value, $selected, true ), true, false );
			$html      .= sprintf(
				'<label><input type="checkbox" name="%1$s[%2$s][]" value="%3$s" %4$s /> <span>%5$s</span></label>',
				esc_attr( self::OPTION ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				$is_checked,
				esc_html( (string) $label )
			);
		}

		return $html . '</div>';
	}
}
