<?php
/**
 * Deterministic legacy Gravity Forms Rule mapping for WU-08.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

use GravityNotify\GravityForms\FeedRuleSchema;

/**
 * Maps only legacy direct-Gravity-Forms Rules whose target semantics are exact.
 */
final class LegacyRuleMapper {

	public const MAP_DETERMINISTIC = 'MAP_DETERMINISTIC';
	public const MANUAL_REQUIRED_AMBIGUOUS = 'MANUAL_REQUIRED_AMBIGUOUS';

	/**
	 * Map one legacy gf_rules element to target Feed metadata.
	 *
	 * @param mixed                $rule            Raw legacy Rule.
	 * @param int                  $legacy_index    Stable legacy array index.
	 * @param array<string, mixed> $legacy_settings Legacy global settings.
	 * @param array<string, mixed> $target_settings Current target settings.
	 * @return array<string, mixed>
	 */
	public static function map( $rule, int $legacy_index, array $legacy_settings, array $target_settings ): array {
		if ( ! is_array( $rule ) || 0 > $legacy_index ) {
			return self::manual( 'malformed_rule' );
		}

		$form_id = self::positive_id( $rule['form_id'] ?? null );
		if ( null === $form_id ) {
			return self::manual( 'invalid_form_identity' );
		}

		if ( '' !== self::scalar_string( $rule['pattern_code'] ?? '' ) ) {
			return self::manual( 'pattern_semantics_not_equivalent' );
		}

		$recipient_type = self::scalar_string( $rule['recipient_type'] ?? 'fixed' );
		if ( 'fixed' !== $recipient_type ) {
			return self::manual(
				'submitter' === $recipient_type
					? 'retired_submitter_contact_contract'
					: ( 'field' === $recipient_type ? 'entry_field_normalization_requires_operator_validation' : 'unsupported_recipient_semantics' )
			);
		}

		$recipient = trim( self::scalar_string( $rule['fixed_recipient'] ?? '' ) );
		if ( ! self::is_e164( $recipient ) ) {
			return self::manual( 'fixed_recipient_requires_normalization_or_is_invalid' );
		}

		$message = trim( self::scalar_string( $rule['message_template'] ?? '' ) );
		if ( '' === $message ) {
			return self::manual( 'empty_message_template' );
		}
		if ( 1 === preg_match( '/\{(?:form_title|entry_id|form_id|[0-9]+)\}/', $message ) ) {
			return self::manual( 'legacy_only_message_tag_requires_semantic_conversion' );
		}

		$legacy_default_sender = trim( self::scalar_string( $legacy_settings['default_sender_number'] ?? '' ) );
		$rule_sender           = trim( self::scalar_string( $rule['sender_number'] ?? '' ) );
		$effective_sender      = '' !== $rule_sender ? $rule_sender : $legacy_default_sender;
		$target_sender         = trim( self::scalar_string( $target_settings['sms_from_number'] ?? '' ) );

		if ( ! self::is_e164( $effective_sender ) ) {
			return self::manual( 'sender_requires_normalization_or_is_invalid' );
		}
		if ( '' !== $target_sender && $target_sender !== $effective_sender ) {
			return self::manual( 'rule_sender_differs_from_target_global_sender' );
		}
		if ( '' !== $rule_sender && $legacy_default_sender !== $rule_sender ) {
			return self::manual( 'per_rule_sender_not_representable_in_target_feed' );
		}

		$fingerprint = self::fingerprint( $rule );
		$scope_id    = self::direct_scope_id( $form_id, $legacy_index, $fingerprint );
		$meta        = array(
			'feedName'               => sprintf( 'Migrated legacy GF rule — Form %d — Rule %d', $form_id, $legacy_index + 1 ),
			'message'                => $message,
			'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
			'recipient_source_value' => $recipient,
			'channel'                => FeedRuleSchema::CHANNEL_SMS,
			'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
			'gnm_migration_source'   => $scope_id,
		);

		return array(
			'classification'     => self::MAP_DETERMINISTIC,
			'reason'             => '',
			'form_id'            => $form_id,
			'legacy_rule_index'  => $legacy_index,
			'legacy_fingerprint' => $fingerprint,
			'scope_id'           => $scope_id,
			'feed_meta'          => $meta,
		);
	}

	/**
	 * Compute a stable secret-safe fingerprint of one legacy Rule.
	 *
	 * @param mixed $rule Legacy Rule.
	 * @return string
	 */
	public static function fingerprint( $rule ): string {
		$canonical = self::canonicalize( is_array( $rule ) ? $rule : array() );
		$json      = json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '[]' );
	}

	/**
	 * Build stable direct-GF scope identity.
	 *
	 * @param int    $form_id      Form ID.
	 * @param int    $legacy_index Stable legacy Rule index.
	 * @param string $fingerprint  Rule fingerprint.
	 * @return string
	 */
	public static function direct_scope_id( int $form_id, int $legacy_index, string $fingerprint ): string {
		return sprintf( 'direct_gf:%d:%d:%s', $form_id, $legacy_index, substr( $fingerprint, 0, 20 ) );
	}

	/**
	 * Build a manual-required mapping result.
	 *
	 * @param string $reason Safe reason code.
	 * @return array<string, mixed>
	 */
	private static function manual( string $reason ): array {
		return array(
			'classification' => self::MANUAL_REQUIRED_AMBIGUOUS,
			'reason'         => $reason,
		);
	}

	/**
	 * Parse a positive legacy identifier.
	 *
	 * @param mixed $value Raw identifier.
	 * @return int|null
	 */
	private static function positive_id( $value ): ?int {
		if ( is_int( $value ) ) {
			return 0 < $value ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return null;
		}
		$value = (int) $value;
		return 0 < $value ? $value : null;
	}

	/**
	 * Check strict E.164 syntax.
	 *
	 * @param string $value Candidate number.
	 * @return bool
	 */
	private static function is_e164( string $value ): bool {
		return 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $value );
	}

	/**
	 * Convert a scalar legacy value to string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function scalar_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Canonicalize nested legacy Rule arrays before hashing.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<mixed>
	 */
	private static function canonicalize( array $value ): array {
		ksort( $value );
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::canonicalize( $item );
			}
		}
		return $value;
	}
}
