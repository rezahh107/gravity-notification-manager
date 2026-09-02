<?php
/**
 * Handles SMS delivery for direct Gravity Forms submissions (non‑Workflow).
 *
 * @package GFSMS\Integration
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

use GFSMS\Admin\Settings_Schema;
use GFSMS\Infrastructure\ProviderFactory;
use GFSMS\Logging\Logger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Class GravityForms_Handler
 *
 * Sends SMS after Gravity Forms submission based on per‑form rules.
 */
final class GravityForms_Handler {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Cached IPPanel_Provider instance.
	 *
	 * @var IPPanel_Provider|null
	 */
	private ?IPPanel_Provider $provider = null;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->logger = Logger::instance();
	}

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
	 * Registers WordPress hooks for Gravity Forms.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if (
			! function_exists( 'gform_after_submission' )
			&& ! did_action( 'gform_loaded' )
		) {
			return;
		}

		add_action( 'gform_after_submission', array( $this, 'on_after_submission' ), 10, 2 );
	}

	/**
	 * Triggered after a Gravity Forms entry is submitted.
	 *
	 * Processes all matching per‑form rules.
	 *
	 * @param array $entry Gravity Forms entry data.
	 * @param array $form  Gravity Forms form data.
	 *
	 * @return void
	 */
	public function on_after_submission( array $entry, array $form ): void {
		$settings = self::get_all_settings();

		// Rules are required.
		$rules = $settings['gf_rules'] ?? array();
		if ( empty( $rules ) ) {
			return;
		}

		$entry_id = absint( $entry['id'] ?? 0 );
		$form_id  = absint( $form['id'] ?? 0 );

		// Obtain the SMS provider once.
		$provider = $this->get_provider( $settings );
		if ( null === $provider ) {
			$this->log(
				$entry_id,
				'gf_submission',
				false,
				__( 'Provider not available.', 'gfsms' ),
				'provider_missing'
			);
			return;
		}

		$default_sender = sanitize_text_field( (string) ( $settings['default_sender_number'] ?? '' ) );

		foreach ( $rules as $rule ) {
			if ( (int) ( $rule['form_id'] ?? 0 ) !== $form_id ) {
				continue;
			}

			// ── Recipient ──────────────────────────────────────
			$recipient_type = sanitize_key( $rule['recipient_type'] ?? 'fixed' );
			$raw_recipient  = $this->resolve_rule_recipient( $recipient_type, $rule, $entry );
			$clean_recipient = preg_replace( '/[^0-9+]/', '', $raw_recipient );
			$normalized      = $provider->normalize_number( $clean_recipient );

			if ( '' === $normalized || null === $normalized ) {
				$this->log(
					$entry_id,
					'gf_submission',
					false,
					__( 'No valid recipient number.', 'gfsms' ),
					'no_recipient'
				);
				continue;
			}

			// ── Message ────────────────────────────────────────
			$template = (string) ( $rule['message_template'] ?? '' );
			$message  = $this->build_message( $template, $entry, $form );
			if ( '' === $message ) {
				$this->log(
					$entry_id,
					'gf_submission',
					false,
					__( 'Final message empty after processing.', 'gfsms' ),
					'empty_message'
				);
				continue;
			}

			// ── Sender ─────────────────────────────────────────
			$sender = sanitize_text_field( (string) ( $rule['sender_number'] ?? '' ) );
			if ( '' === $sender ) {
				$sender = $default_sender;
			}
			if ( '' === $sender ) {
				$this->log(
					$entry_id,
					'gf_submission',
					false,
					__( 'No sender number configured.', 'gfsms' ),
					'no_sender'
				);
				continue;
			}

			// ── Send ───────────────────────────────────────────
			try {
				if ( ! empty( $rule['pattern_code'] ) ) {
					// Send as pattern.
					$pattern_code = $rule['pattern_code'];
					$variable_map = $rule['variable_map'] ?? array();
					if ( is_string( $variable_map ) ) {
						$variable_map = json_decode( $variable_map, true ) ?: array();
					}
					$result = $provider->send_pattern(
						$sender,
						array( $normalized ),
						$pattern_code,
						$variable_map
					);
				} else {
					$timeout = max( 5, (int) ( $settings['gf_send_timeout'] ?? 15 ) );
					$result = $provider->send( $sender, array( $normalized ), $message, array( 'timeout' => $timeout ) );
				}
			} catch ( Throwable $e ) {
				$this->logger->error(
					'GF SMS send exception',
					array(
						'entry_id' => $entry_id,
						'error'    => $e->getMessage(),
						'source'   => 'gravityforms_handler',
					)
				);
				$this->log( $entry_id, 'gf_submission', false, $e->getMessage(), 'exception' );
				continue;
			}

			// ── Log outcome ────────────────────────────────────
			$success    = ! empty( $result['success'] );
			$error_code = (string) ( $result['error_code'] ?? ( $success ? '' : 'unknown' ) );
			$this->log(
				$entry_id,
				'gf_submission',
				$success,
				$message,
				$error_code,
				$result
			);
		}
	}

	/**
	 * Resolve a recipient number from a rule’s definition.
	 *
	 * @param string $recipient_type Type: fixed, field, or submitter.
	 * @param array  $rule           The rule array.
	 * @param array  $entry          Gravity Forms entry.
	 *
	 * @return string Raw recipient number.
	 */
	private function resolve_rule_recipient( string $recipient_type, array $rule, array $entry ): string {
		if ( 'fixed' === $recipient_type ) {
			return sanitize_text_field( trim( (string) ( $rule['fixed_recipient'] ?? '' ) ) );
		}

		if ( 'field' === $recipient_type ) {
			$field_id = (string) ( $rule['recipient_field'] ?? '' );
			if ( '' !== $field_id && isset( $entry[ $field_id ] ) ) {
				return sanitize_text_field( trim( (string) $entry[ $field_id ] ) );
			}
			return '';
		}

		if ( 'submitter' === $recipient_type ) {
			$user_id = (int) ( $entry['created_by'] ?? 0 );
			if ( $user_id > 0 ) {
				return $this->get_submitter_mobile( $user_id );
			}
			return '';
		}

		return '';
	}

	/**
	 * Get mobile number of the form submitter (user meta `plato_user_mobile`).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Mobile number or empty string.
	 */
	private function get_submitter_mobile( int $user_id ): string {
		$keys = apply_filters(
			'gfsms_user_mobile_keys',
			array(
				'billing_phone',
				'mobile',
				'plato_user_mobile',
			)
		);

		foreach ( $keys as $key ) {
			$value = (string) get_user_meta( $user_id, (string) $key, true );
			$clean = preg_replace( '/[^0-9+]/', '', $value );
			if ( ! empty( $clean ) ) {
				return $clean;
			}
		}

		return '';
	}

	/**
	 * Build the final SMS message from a template string.
	 *
	 * @param string $template Message template with merge tags.
	 * @param array  $entry    Gravity Forms entry.
	 * @param array  $form     Gravity Forms form.
	 *
	 * @return string Sanitised and filtered message.
	 */
	private function build_message( string $template, array $entry, array $form ): string {
		$template = trim( $template );
		if ( '' === $template ) {
			return '';
		}

		$message = $template;

		// Static replacements.
		$message = strtr(
			$message,
			array(
				'{form_title}' => (string) ( $form['title'] ?? '' ),
				'{entry_id}'   => (string) absint( $entry['id'] ?? 0 ),
				'{form_id}'    => (string) absint( $form['id'] ?? 0 ),
			)
		);

		// Dynamic field IDs, e.g. {3}.
		if ( preg_match_all( '/{([0-9]+)}/', $message, $matches ) ) {
			foreach ( $matches[1] as $field_id ) {
				$value   = rgar( $entry, $field_id );
				$message = str_replace( '{' . $field_id . '}', (string) $value, $message );
			}
		}

		// Use Gravity Forms variable replacement, if available.
		if ( class_exists( '\GFCommon' ) ) {
			try {
				$message = \GFCommon::replace_variables( $message, $form, $entry, false, false, false );
			} catch ( Throwable $e ) {
				// Keep the current message.
			}
		}

		// Strip tags and trim.
		$message = wp_strip_all_tags( trim( $message ) );

		// Allow plugins to filter the final message.
		$message = apply_filters( 'gfsms_gf_message_built', $message, $entry, $form );

		return sanitize_textarea_field( $message );
	}

	/**
	 * Obtain the IPPanel_Provider instance, preferably via ProviderFactory.
	 *
	 * @param array $settings Plugin settings.
	 *
	 * @return IPPanel_Provider|null
	 */
	private function get_provider( array $settings ): ?IPPanel_Provider {
		if ( null !== $this->provider ) {
			return $this->provider;
		}

		$api_key = sanitize_text_field( (string) ( $settings['ippanel_api_key'] ?? '' ) );
		if ( '' === $api_key ) {
			return null;
		}

		// Try using the ProviderFactory first.
		if ( class_exists( ProviderFactory::class ) ) {
			try {
				$factory  = new ProviderFactory( $settings );
				$provider = $factory->get_primary();
				if ( $provider instanceof IPPanel_Provider ) {
					$this->provider = $provider;
					return $this->provider;
				}
			} catch ( Throwable $e ) {
				// Fall through to direct instantiation.
			}
		}

		// Fallback: direct instantiation.
		if ( ! class_exists( IPPanel_Provider::class ) || ! class_exists( Wp_HTTP_Client::class ) ) {
			return null;
		}

		$this->provider = new IPPanel_Provider( new Wp_HTTP_Client(), $api_key );
		return $this->provider;
	}

	/**
	 * Write an event to the plugin log.
	 *
	 * @param int    $entry_id   Gravity Forms entry ID.
	 * @param string $event_type Event type identifier.
	 * @param bool   $success    Whether the operation succeeded.
	 * @param string $message    Descriptive message or content.
	 * @param string $error_code Optional error code.
	 * @param array  $extra      Additional data.
	 *
	 * @return void
	 */
	private function log(
		int $entry_id,
		string $event_type,
		bool $success,
		string $message,
		string $error_code = '',
		array $extra = array()
	): void {
		$context = array(
			'entry_id'   => $entry_id,
			'event_type' => $event_type,
			'success'    => $success,
			'message'    => $message,
			'error_code' => $error_code,
			'extra'      => $extra,
			'source'     => 'gravityforms_handler',
		);

		try {
			if ( $success ) {
				$this->logger->info( 'GF SMS event', $context );
			} else {
				$this->logger->error( 'GF SMS event failed', $context );
			}
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'GFSMS GravityForms_Handler: ' . wp_json_encode( $context ) );
		}
	}

	/**
	 * Retrieve the complete plugin settings array with defaults.
	 *
	 * @return array
	 */
	private static function get_all_settings(): array {
		$settings = get_option( GFSMS_SETTINGS_OPTION, array() );
		return is_array( $settings ) ? array_merge( Settings_Schema::defaults(), $settings ) : Settings_Schema::defaults();
	}

	/**
	 * Prevent cloning.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @return void
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}
}