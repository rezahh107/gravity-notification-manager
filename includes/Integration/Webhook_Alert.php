<?php
/**
 * Webhook alerting for SMS events.
 *
 * Sends HTTP notifications to a configured endpoint when SMS events occur.
 *
 * @package GFSMS\Integration
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

use GFSMS\Admin\Settings_Schema;
use GFSMS\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Webhook_Alert
 *
 * Singleton service for dispatching webhook alerts.
 */
final class Webhook_Alert {

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
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Registers WordPress action hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'gfsms_sms_failed', array( $this, 'notify_failed' ), 10, 1 );
		add_action( 'gfsms_sms_retry_scheduled', array( $this, 'notify_retry' ), 10, 1 );
	}

	/**
	 * Notify webhook about a failed SMS.
	 *
	 * @param array $payload Event payload.
	 *
	 * @return void
	 */
	public function notify_failed( array $payload ): void {
		$this->send( 'sms_failed', $payload );
	}

	/**
	 * Notify webhook about a scheduled retry.
	 *
	 * @param array $payload Event payload.
	 *
	 * @return void
	 */
	public function notify_retry( array $payload ): void {
		$this->send( 'sms_retry', $payload );
	}

	/**
	 * Send the webhook POST request.
	 *
	 * @param string $event   Event type (sms_failed or sms_retry).
	 * @param array  $payload Event payload.
	 *
	 * @return void
	 */
	private function send( string $event, array $payload ): void {
		$settings = get_option( GFSMS_SETTINGS_OPTION, Settings_Schema::defaults() );
		$url      = trim( (string) ( $settings['webhook_url'] ?? '' ) );
		$events   = array_map( 'strval', (array) ( $settings['webhook_events'] ?? array() ) );

		if ( '' === $url || ! in_array( $event, $events, true ) ) {
			return;
		}

		$body = wp_json_encode(
			array(
				'event'   => $event,
				'site'    => home_url( '/' ),
				'payload' => $payload,
				'sent_at' => current_time( 'mysql' ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::instance()->log_entry(
				0,
				0,
				0,
				'webhook',
				'',
				'error',
				$response->get_error_message(),
				array(),
				$response->get_error_code()
			);
		}
	}
}
