<?php
/**
 * Transport‑only SMS delivery service.
 *
 * Validates, rate‑limits and dispatches prepared SMS payloads to the
 * configured provider. All settings are read directly from the plugin’s
 * option key, keeping the class aligned with the refactored architecture.
 *
 * @package GFSMS\Integration
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

use GFSMS\Admin\Settings_Schema;
use GFSMS\Infrastructure\ProviderFactory;
use GFSMS\Logging\Logger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sms_Sender
 *
 * Singleton service for sending SMS via the configured provider.
 *
 * @since 3.0.0
 */
final class Sms_Sender {

	/**
	 * Prefix for rate‑limit transients.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	private const RATE_LIMIT_PREFIX = 'gfsms_rate_';

	/**
	 * Singleton instance.
	 *
	 * @since 3.0.0
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Plugin settings array.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	private array $settings;

	/**
	 * Logger instance.
	 *
	 * @since 3.0.0
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Provider factory (lazy‑loaded).
	 *
	 * @since 3.0.0
	 * @var ProviderFactory|null
	 */
	private ?ProviderFactory $provider_factory = null;

	/**
	 * Private constructor (singleton).
	 *
	 * @since 3.0.0
	 */
	private function __construct() {
		$this->settings = self::get_all_settings();
		$this->logger   = Logger::instance();
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @since 3.0.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the queue system, if present.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register_hooks(): void {
		if ( class_exists( Event_Queue::class ) ) {
			Event_Queue::instance()->register_hooks();
		}
	}

	/**
	 * Deliver an SMS payload.
	 *
	 * @since 3.0.0
	 *
	 * @param array $payload See `sanitize_payload()` for the expected keys.
	 * @return array Provider result structure.
	 */
	public function deliver( array $payload ): array {
		$sanitized = $this->sanitize_payload( $payload );

		if ( null === $sanitized ) {
			$from           = sanitize_text_field( (string) ( $payload['from'] ?? '' ) );
			$raw_recipients = (array) ( $payload['recipients'] ?? array() );
			$recipients     = array_values(
				array_filter(
					array_map(
						static function ( $n ) {
							return sanitize_text_field( trim( (string) $n ) );
						},
						$raw_recipients
					)
				)
			);

			$this->log_delivery(
				absint( $payload['entry_id'] ?? 0 ),
				absint( $payload['step_id'] ?? 0 ),
				sanitize_text_field( (string) ( $payload['event_type'] ?? 'system' ) ),
				$recipients,
				false,
				__( 'SMS skipped: missing sender or recipients.', 'gfsms' ),
				'invalid_params'
			);
			return array(
				'success'       => false,
				'error_code'    => 'invalid_params',
				'error_message' => __( 'Invalid sender or recipients.', 'gfsms' ),
			);
		}

		$from           = $sanitized['from'];
		$recipients     = $sanitized['recipients'];
		$message        = $sanitized['message'];
		$entry_id       = $sanitized['entry_id'];
		$step_id        = $sanitized['step_id'];
		$event_type     = $sanitized['event_type'];
		$sending_method = $sanitized['sending_method'];
		$pattern_code   = $sanitized['pattern_code'];
		$pattern_vars   = $sanitized['pattern_vars'];

		// ── Rate limit (per sender, 10/min) ─────────────────
		if ( ! empty( $this->settings['enable_rate_limit'] ) ) {
			if ( ! $this->rate_limit_ok( $from ) ) {
				$this->log_delivery(
					$entry_id,
					$step_id,
					$event_type,
					$recipients,
					false,
					__( 'Rate limit reached.', 'gfsms' ),
					'rate_limited'
				);
				return array(
					'success'       => false,
					'error_code'    => 'rate_limited',
					'error_message' => __( 'Rate limit reached.', 'gfsms' ),
				);
			}
		}

		// ── Obtain provider ──────────────────────────────────
		$factory = $this->get_provider_factory();
		if ( null === $factory ) {
			$this->log_delivery(
				$entry_id,
				$step_id,
				$event_type,
				$recipients,
				false,
				__( 'Provider infrastructure not available.', 'gfsms' ),
				'provider_missing'
			);
			return array(
				'success'       => false,
				'error_code'    => 'provider_missing',
				'error_message' => __( 'SMS provider not available.', 'gfsms' ),
			);
		}

		// ── Dispatch ─────────────────────────────────────────
		try {
			$provider = $factory->get_primary();

			if ( 'pattern' === $sending_method && '' !== $pattern_code ) {
				$result = $provider->send_pattern( $from, $recipients, $pattern_code, $pattern_vars );
			} else {
				$result = $provider->send( $from, $recipients, $message );
			}
		} catch ( Throwable $e ) {
			$this->log_delivery(
				$entry_id,
				$step_id,
				$event_type,
				$recipients,
				false,
				$message,
				'exception',
				array(
					'error_code'    => 'exception',
					'error_message' => $e->getMessage(),
				)
			);
			return array(
				'success'       => false,
				'error_code'    => 'exception',
				'error_message' => $e->getMessage(),
			);
		}

		// ── Log outcome ──────────────────────────────────────
		$success = ! empty( $result['success'] );
		$this->log_delivery(
			$entry_id,
			$step_id,
			$event_type,
			$recipients,
			$success,
			$message,
			(string) ( $result['error_code'] ?? ( $success ? '' : 'unknown' ) ),
			$result
		);

		return $result;
	}

	/**
	 * Extract and sanitise all payload fields.
	 *
	 * @since 3.0.0
	 *
	 * @param array $payload Raw input.
	 * @return array|null Associative array of clean values, or null if mandatory fields are missing.
	 */
	private function sanitize_payload( array $payload ): ?array {
		$settings = $this->settings;

		$from = sanitize_text_field( (string) ( $payload['from'] ?? $settings['default_sender_number'] ?? '' ) );

		$raw_recipients = (array) ( $payload['recipients'] ?? array() );
		$recipients     = array_values(
			array_filter(
				array_map(
					static function ( $n ) {
						return sanitize_text_field( trim( (string) $n ) );
					},
					$raw_recipients
				)
			)
		);

		$message        = sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) );
		$entry_id       = absint( $payload['entry_id'] ?? 0 );
		$step_id        = absint( $payload['step_id'] ?? 0 );
		$event_type     = sanitize_text_field( (string) ( $payload['event_type'] ?? 'system' ) );
		$sending_method = sanitize_key( (string) ( $payload['sending_method'] ?? $settings['sending_method'] ?? 'webservice' ) );
		$pattern_code   = sanitize_text_field( (string) ( $payload['pattern_code'] ?? '' ) );
		$pattern_vars   = (array) ( $payload['pattern_variables'] ?? $payload['variables'] ?? array() );

		if ( '' === $from || array() === $recipients ) {
			return null;
		}

		return compact(
			'from',
			'recipients',
			'message',
			'entry_id',
			'step_id',
			'event_type',
			'sending_method',
			'pattern_code',
			'pattern_vars'
		);
	}

	/**
	 * Rate‑limit check per sender (10 SMS / minute).
	 *
	 * @since 3.0.0
	 *
	 * @param string $from Sender number.
	 * @return bool True if the limit has not been exceeded.
	 */
	private function rate_limit_ok( string $from ): bool {
		$bucket = self::RATE_LIMIT_PREFIX . md5( $from ) . '_' . gmdate( 'YmdHi' );
		$count  = (int) get_transient( $bucket );

		if ( $count >= 10 ) {
			return false;
		}

		set_transient( $bucket, $count + 1, 70 );
		return true;
	}

	/**
	 * Log a delivery attempt using the unified Logger.
	 *
	 * Falls back to PHP error log if Logger is not available.
	 *
	 * @since 3.0.0
	 *
	 * @param int      $entry_id   Entry ID.
	 * @param int      $step_id    Step ID.
	 * @param string   $event_type Event type slug.
	 * @param string[] $recipients Array of recipient numbers.
	 * @param bool     $success    Delivery status.
	 * @param string   $message    SMS message content.
	 * @param string   $error_code Optional error code.
	 * @param array    $extra      Additional context data.
	 * @return void
	 */
	private function log_delivery(
		int $entry_id,
		int $step_id,
		string $event_type,
		array $recipients,
		bool $success,
		string $message,
		string $error_code = '',
		array $extra = array()
	): void {
		try {
			$recipient_str = implode( ',', array_slice( $recipients, 0, 5 ) );
			$this->logger->log_entry(
				$entry_id,
				$step_id,
				0, // workflow_id is 0 for non-workflow events
				$event_type,
				$recipient_str,
				$success ? 'sent' : 'failed',
				$message,
				array_merge( $extra, array( 'recipients' => $recipients ) ),
				$error_code,
				'sms_sender'
			);
		} catch ( Throwable $e ) {
			// Fallback: standard error log.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				'GFSMS Sms_Sender: ' . wp_json_encode(
					array(
						'entry_id'   => $entry_id,
						'step_id'    => $step_id,
						'event_type' => $event_type,
						'recipients' => $recipients,
						'success'    => $success,
						'message'    => $message,
						'error_code' => $error_code,
						'extra'      => $extra,
					)
				)
			);
		}
	}

	/**
	 * Lazily instantiate the provider factory.
	 *
	 * @since 3.0.0
	 *
	 * @return ProviderFactory|null
	 */
	private function get_provider_factory(): ?ProviderFactory {
		if ( null === $this->provider_factory && class_exists( ProviderFactory::class ) ) {
			$this->provider_factory = new ProviderFactory( $this->settings );
		}
		return $this->provider_factory;
	}

	/**
	 * Retrieve the full plugin settings array.
	 *
	 * @since 3.0.0
	 *
	 * @return array
	 */
	private static function get_all_settings(): array {
		$settings = get_option( GFSMS_SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) || array() === $settings ) {
			$settings = Settings_Schema::defaults();
		}
		return $settings;
	}

	/**
	 * Prevent cloning.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @since 3.0.0
	 * @return void
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}
}