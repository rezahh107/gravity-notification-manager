<?php
/**
 * SMS provider capability names.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Sms;

/**
 * Closed capability vocabulary used for deterministic routing.
 */
final class SmsCapability {

	/**
	 * One-recipient plain text.
	 */
	public const PLAIN = 'plain';

	/**
	 * One-recipient pattern/template.
	 */
	public const PATTERN = 'pattern';

	/**
	 * Multiple recipients receiving one plain message.
	 */
	public const MULTI_RECIPIENT_PLAIN = 'multi_recipient_plain';

	/**
	 * Multiple recipients receiving one pattern/template request.
	 */
	public const MULTI_RECIPIENT_PATTERN = 'multi_recipient_pattern';

	/**
	 * Provider exposes a documented message reference on acceptance.
	 */
	public const PROVIDER_MESSAGE_REFERENCE = 'provider_message_reference';

	/**
	 * Return all capability names.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::PLAIN,
			self::PATTERN,
			self::MULTI_RECIPIENT_PLAIN,
			self::MULTI_RECIPIENT_PATTERN,
			self::PROVIDER_MESSAGE_REFERENCE,
		);
	}
}
