<?php
/**
 * Normalized SMS delivery request.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Sms;

use InvalidArgumentException;

/**
 * Holds already-resolved destinations and preserves plain/pattern semantics.
 */
final class SmsRequest {

	/**
	 * Requested capability.
	 *
	 * @var string
	 */
	private string $capability;

	/**
	 * Already-resolved recipients.
	 *
	 * @var array<int, string>
	 */
	private array $recipients;

	/**
	 * Sender/from value.
	 *
	 * @var string
	 */
	private string $from;

	/**
	 * Plain text, when applicable.
	 *
	 * @var string|null
	 */
	private ?string $message;

	/**
	 * Pattern code, when applicable.
	 *
	 * @var string|null
	 */
	private ?string $pattern_code;

	/**
	 * Pattern parameters.
	 *
	 * @var array<string, scalar>
	 */
	private array $pattern_parameters;

	/**
	 * Build a normalized request.
	 *
	 * @param string                $capability         Requested capability.
	 * @param array<int, string>    $recipients         Already-resolved recipients.
	 * @param string                $from               Sender/from value.
	 * @param string|null           $message            Plain message.
	 * @param string|null           $pattern_code       Pattern code.
	 * @param array<string, scalar> $pattern_parameters Pattern parameters.
	 */
	private function __construct(
		string $capability,
		array $recipients,
		string $from,
		?string $message,
		?string $pattern_code,
		array $pattern_parameters
	) {
		if ( empty( $recipients ) || '' === $from ) {
			throw new InvalidArgumentException( 'SMS request requires resolved recipients and sender.' );
		}

		$this->capability         = $capability;
		$this->recipients         = array_values( $recipients );
		$this->from               = $from;
		$this->message            = $message;
		$this->pattern_code       = $pattern_code;
		$this->pattern_parameters = $pattern_parameters;
	}

	/**
	 * Build a plain request without semantic conversion.
	 *
	 * @param string             $capability Requested plain capability.
	 * @param array<int, string> $recipients Already-resolved recipients.
	 * @param string             $from       Sender/from value.
	 * @param string             $message    Plain message.
	 * @return self
	 */
	public static function plain( string $capability, array $recipients, string $from, string $message ): self {
		if ( ! in_array( $capability, array( SmsCapability::PLAIN, SmsCapability::MULTI_RECIPIENT_PLAIN ), true ) ) {
			throw new InvalidArgumentException( 'Plain SMS request requires a plain capability.' );
		}

		if ( '' === $message ) {
			throw new InvalidArgumentException( 'Plain SMS request requires message text.' );
		}

		if ( SmsCapability::PLAIN === $capability && 1 !== count( $recipients ) ) {
			throw new InvalidArgumentException( 'Plain capability requires exactly one recipient.' );
		}

		if ( SmsCapability::MULTI_RECIPIENT_PLAIN === $capability && count( $recipients ) < 2 ) {
			throw new InvalidArgumentException( 'Multi-recipient plain capability requires at least two recipients.' );
		}

		return new self( $capability, $recipients, $from, $message, null, array() );
	}

	/**
	 * Build a pattern request without converting it to plain text.
	 *
	 * @param string                $capability         Requested pattern capability.
	 * @param array<int, string>    $recipients         Already-resolved recipients.
	 * @param string                $from               Sender/from value.
	 * @param string                $pattern_code       Pattern code.
	 * @param array<string, scalar> $pattern_parameters Pattern parameters.
	 * @return self
	 */
	public static function pattern(
		string $capability,
		array $recipients,
		string $from,
		string $pattern_code,
		array $pattern_parameters
	): self {
		if ( ! in_array( $capability, array( SmsCapability::PATTERN, SmsCapability::MULTI_RECIPIENT_PATTERN ), true ) ) {
			throw new InvalidArgumentException( 'Pattern SMS request requires a pattern capability.' );
		}

		if ( '' === $pattern_code ) {
			throw new InvalidArgumentException( 'Pattern SMS request requires a pattern code.' );
		}

		if ( SmsCapability::PATTERN === $capability && 1 !== count( $recipients ) ) {
			throw new InvalidArgumentException( 'Pattern capability requires exactly one recipient.' );
		}

		if ( SmsCapability::MULTI_RECIPIENT_PATTERN === $capability && count( $recipients ) < 2 ) {
			throw new InvalidArgumentException( 'Multi-recipient pattern capability requires at least two recipients.' );
		}

		return new self( $capability, $recipients, $from, null, $pattern_code, $pattern_parameters );
	}

	/**
	 * Requested capability.
	 *
	 * @return string
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Already-resolved recipients.
	 *
	 * @return array<int, string>
	 */
	public function recipients(): array {
		return $this->recipients;
	}

	/**
	 * Sender/from value.
	 *
	 * @return string
	 */
	public function from(): string {
		return $this->from;
	}

	/**
	 * Plain message.
	 *
	 * @return string|null
	 */
	public function message(): ?string {
		return $this->message;
	}

	/**
	 * Pattern code.
	 *
	 * @return string|null
	 */
	public function pattern_code(): ?string {
		return $this->pattern_code;
	}

	/**
	 * Pattern parameters.
	 *
	 * @return array<string, scalar>
	 */
	public function pattern_parameters(): array {
		return $this->pattern_parameters;
	}
}
