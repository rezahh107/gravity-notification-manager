<?php
/**
 * Feed metadata contract for the greenfield Gravity Forms notification rule.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityForms;

/**
 * Defines the bounded WU-01 feed metadata schema without delivery behavior.
 */
final class FeedRuleSchema {

	/**
	 * Current WU-01 metadata schema version.
	 */
	public const VERSION = 1;

	/**
	 * SMS channel identifier.
	 */
	public const CHANNEL_SMS = 'sms';

	/**
	 * Bale channel identifier.
	 */
	public const CHANNEL_BALE = 'bale';

	/**
	 * No fallback is requested.
	 */
	public const FALLBACK_NONE = 'none';

	/**
	 * A later transport Work Unit may use only a capability-compatible SMS fallback.
	 */
	public const FALLBACK_COMPATIBLE_SMS = 'compatible_sms_only';

	/**
	 * Entry-field recipient source placeholder.
	 */
	public const RECIPIENT_ENTRY_FIELD = 'entry_field';

	/**
	 * Fixed recipient source placeholder.
	 */
	public const RECIPIENT_FIXED = 'fixed';

	/**
	 * WordPress-user recipient source placeholder.
	 */
	public const RECIPIENT_USER = 'user';

	/**
	 * WordPress-role recipient source placeholder.
	 */
	public const RECIPIENT_ROLE = 'role';

	/**
	 * Gravity Flow assignee recipient source placeholder.
	 */
	public const RECIPIENT_FLOW_ASSIGNEE = 'flow_assignee';

	/**
	 * Normalize only the bounded WU-01 feed metadata contract.
	 *
	 * This method intentionally does not resolve recipients, select providers,
	 * convert message semantics, perform fallback, or write delivery state.
	 *
	 * @param array $meta Raw Gravity Forms feed metadata.
	 * @return array<string, int|string>
	 */
	public static function normalize( array $meta ): array {
		return array(
			'schema_version'         => self::VERSION,
			'feedName'               => self::string_value( $meta['feedName'] ?? '' ),
			'message'                => self::string_value( $meta['message'] ?? '' ),
			'recipient_source_type'  => self::string_value( $meta['recipient_source_type'] ?? '' ),
			'recipient_source_value' => self::string_value( $meta['recipient_source_value'] ?? '' ),
			'channel'                => self::string_value( $meta['channel'] ?? '' ),
			'fallback_policy'        => self::string_value( $meta['fallback_policy'] ?? self::FALLBACK_NONE ),
		);
	}

	/**
	 * Return the supported channel values for WU-01 configuration.
	 *
	 * @return array<int, string>
	 */
	public static function channels(): array {
		return array(
			self::CHANNEL_SMS,
			self::CHANNEL_BALE,
		);
	}

	/**
	 * Return bounded fallback intent values.
	 *
	 * @return array<int, string>
	 */
	public static function fallback_policies(): array {
		return array(
			self::FALLBACK_NONE,
			self::FALLBACK_COMPATIBLE_SMS,
		);
	}

	/**
	 * Return bounded recipient source placeholders for later resolution.
	 *
	 * @return array<int, string>
	 */
	public static function recipient_source_types(): array {
		return array(
			self::RECIPIENT_ENTRY_FIELD,
			self::RECIPIENT_FIXED,
			self::RECIPIENT_USER,
			self::RECIPIENT_ROLE,
			self::RECIPIENT_FLOW_ASSIGNEE,
		);
	}

	/**
	 * Convert scalar metadata safely to a string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function string_value( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}
}
