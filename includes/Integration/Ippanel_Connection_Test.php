<?php
/**
 * Handles AJAX requests for testing IPPanel connection and fetching sender numbers.
 *
 * @package GFSMS\Integration
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

use GFSMS\Admin\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ippanel_Connection_Test
 *
 * Provides AJAX endpoints for testing SMS connectivity.
 */
final class Ippanel_Connection_Test {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers AJAX hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_gfsms_fetch_sender_numbers', array( self::class, 'ajax_fetch_sender_numbers' ) );
	}

	/**
	 * AJAX handler to send a test SMS and cache the sender number.
	 *
	 * @return void
	 */
	public static function ajax_fetch_sender_numbers(): void {
		if ( ! check_ajax_referer( 'gfsms_test_connection', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'gfsms' ) ), 403 );
		}

		if ( ! current_user_can( GFSMS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'gfsms' ) ), 403 );
		}

		$settings = get_option( GFSMS_SETTINGS_OPTION, Settings_Schema::defaults() );

		$api_key = sanitize_text_field( (string) ( $settings['ippanel_api_key'] ?? '' ) );
		$sender  = sanitize_text_field( (string) ( $settings['default_sender_number'] ?? '' ) );

		if ( '' === $api_key || '' === $sender ) {
			wp_send_json_error(
				array(
					'message' => __( 'API key and sender number are required.', 'gfsms' ),
				),
				422
			);
		}

		$provider = new IPPanel_Provider(
			new Wp_HTTP_Client(),
			$api_key
		);

		$result = $provider->send(
			$sender,
			array( $sender ),
			__( 'Connection test from Gravity Flow SMS.', 'gfsms' )
		);

		if ( ! empty( $result['success'] ) ) {
			$cached = get_option( 'gfsms_cached_senders', array() );

			if ( ! is_array( $cached ) ) {
				$cached = array();
			}

			$cached[] = sanitize_text_field( $sender );

			$cached = array_values(
				array_unique(
					array_filter( $cached )
				)
			);

			update_option( 'gfsms_cached_senders', $cached, false );

			wp_send_json_success(
				array(
					'message' => __( 'Connection successful.', 'gfsms' ),
					'result'  => $result,
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => (string) ( $result['error_message'] ?? __( 'Connection failed.', 'gfsms' ) ),
				'result'  => $result,
			)
		);
	}
}
