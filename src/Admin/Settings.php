<?php
/**
 * Bounded greenfield admin settings ownership.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Sanitizes only WU-06 settings; it performs no migration or external send.
 */
final class Settings {
	public const OPTION = 'gravity_notify_settings';
	public const GROUP = 'gravity_notify_settings';

	/** @return array<string,string> */
	public static function read(): array {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::sanitize_input( is_array( $value ) ? $value : array(), array() );
	}

	/** @param mixed $input @return array<string,string> */
	public static function sanitize_option( $input ): array {
		$existing = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::sanitize_input( is_array( $input ) ? $input : array(), is_array( $existing ) ? $existing : array() );
	}

	/**
	 * Pure sanitizer used by tests and the Settings API callback.
	 *
	 * @param array<string,mixed> $input Raw submitted input.
	 * @param array<string,mixed> $existing Existing option state.
	 * @return array<string,string>
	 */
	public static function sanitize_input( array $input, array $existing = array() ): array {
		$result = array(
			'ippanel_api_key' => self::secret( $existing['ippanel_api_key'] ?? '' ),
			'sms_from_number' => self::e164( $input['sms_from_number'] ?? ( $existing['sms_from_number'] ?? '' ) ),
			'bale_bot_token'  => self::secret( $existing['bale_bot_token'] ?? '' ),
		);

		$api_key = self::secret( $input['ippanel_api_key'] ?? '' );
		if ( '' !== $api_key ) {
			$result['ippanel_api_key'] = $api_key;
		}
		$bale_token = self::secret( $input['bale_bot_token'] ?? '' );
		if ( '' !== $bale_token ) {
			$result['bale_bot_token'] = $bale_token;
		}

		return $result;
	}

	/** @param array<string,string>|null $settings @return array<string,bool> */
	public static function readiness( ?array $settings = null ): array {
		$settings = null === $settings ? self::read() : $settings;
		return array(
			'ippanel' => '' !== ( $settings['ippanel_api_key'] ?? '' ) && '' !== ( $settings['sms_from_number'] ?? '' ),
			'bale'    => '' !== ( $settings['bale_bot_token'] ?? '' ),
		);
	}

	/** @param array<string,string>|null $settings @return array<string,string> */
	public static function diagnostic_facts( ?array $settings = null ): array {
		$ready = self::readiness( $settings );
		return array(
			'IPPanel' => $ready['ippanel'] ? 'CONFIGURED' : 'NEEDS_SETUP',
			'Bale'    => $ready['bale'] ? 'CONFIGURED' : 'NEEDS_SETUP',
		);
	}

	private static function secret( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		if ( ! is_string( $value ) ) {
			return '';
		}
		return substr( $value, 0, 512 );
	}

	private static function e164( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		return 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $value ) ? $value : '';
	}
}
