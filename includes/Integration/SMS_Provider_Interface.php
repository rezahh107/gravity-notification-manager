<?php
/**
 * SMS Provider Interface
 *
 * Defines the contract for all SMS provider implementations.
 * All providers must implement:
 *  - Normal sending
 *  - Pattern (template) sending
 *  - Number normalization
 *  - Provider-specific formatting
 *  - Error classification
 *  - Provider identification
 *
 * @package GFSMS\Integration
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Interface SMS_Provider_Interface
 *
 * Contract for all SMS providers.
 */
interface SMS_Provider_Interface {

	/**
	 * Send a normal text message.
	 *
	 * @param string              $from       Sender number (raw user input).
	 * @param array<string>       $recipients List of recipient numbers (raw user input).
	 * @param string              $message    Message body.
	 * @param array<string,mixed> $args       Optional extra parameters (time, timeout, etc.).
	 *
	 * @return array{
	 *     success: bool,
	 *     http_status?: int,
	 *     provider_message_code?: string,
	 *     message_ids?: array<string>,
	 *     error_code?: string,
	 *     error_message?: string,
	 *     raw?: mixed
	 * }
	 */
	public function send(
		string $from,
		array $recipients,
		string $message,
		array $args = array()
	): array;

	/**
	 * Send patterned message (template API).
	 *
	 * @param string               $from         Sender number.
	 * @param array<string>        $recipients   List of recipient numbers.
	 * @param string               $pattern_code Pattern code.
	 * @param array<string,string> $variables    Key-value variables for pattern replacement.
	 * @param array<string,mixed>  $args         Optional extra parameters.
	 *
	 * @return array See send() return format.
	 */
	public function send_pattern(
		string $from,
		array $recipients,
		string $pattern_code,
		array $variables = array(),
		array $args = array()
	): array;

	/**
	 * Normalize phone number to internal canonical format.
	 *
	 * @param string $number Raw phone number.
	 *
	 * @return string|null Normalized number (e.g., 09xxxxxxxxx) or null if invalid.
	 */
	public function normalize_number( string $number ): ?string;

	/**
	 * Convert normalized number to provider format.
	 *
	 * @param string $number Normalized number.
	 *
	 * @return string|null Provider-formatted number (e.g., +98xxxxxxxxxx) or null if invalid.
	 */
	public function to_provider_format( string $number ): ?string;

	/**
	 * Classify provider errors for retry logic.
	 *
	 * @param array $result API response array.
	 *
	 * @return ProviderErrorStatus
	 */
	public function classify_error( array $result ): ProviderErrorStatus;

	/**
	 * Return provider name (unique identifier).
	 *
	 * @return string
	 */
	public function get_name(): string;
}
