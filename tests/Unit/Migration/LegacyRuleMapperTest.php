<?php
/**
 * WU-08 deterministic legacy Rule mapping tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Migration;

use GravityNotify\Migration\LegacyRuleMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests deterministic versus ambiguous legacy Rule mapping.
 */
final class LegacyRuleMapperTest extends TestCase {

	/**
	 * A strict fixed-E.164 plain Rule maps deterministically.
	 *
	 * @return void
	 */
	public function test_fixed_e164_plain_rule_maps_deterministically(): void {
		$rule   = $this->rule();
		$result = LegacyRuleMapper::map(
			$rule,
			4,
			array( 'default_sender_number' => '+982100000000' ),
			array( 'sms_from_number' => '+982100000000' )
		);
		self::assertSame( LegacyRuleMapper::MAP_DETERMINISTIC, $result['classification'] );
		self::assertSame( 7, $result['form_id'] );
		self::assertSame( 'fixed', $result['feed_meta']['recipient_source_type'] );
		self::assertSame( 'none', $result['feed_meta']['fallback_policy'] );
		self::assertSame( $result['scope_id'], $result['feed_meta']['gnm_migration_source'] );
	}

	/**
	 * Ambiguous semantics never auto-map.
	 *
	 * @param array<string, mixed> $rule   Legacy Rule fixture.
	 * @param string               $reason Expected safe reason code.
	 * @return void
	 * @dataProvider ambiguous_rules
	 */
	public function test_ambiguous_semantics_are_never_auto_mapped( array $rule, string $reason ): void {
		$result = LegacyRuleMapper::map(
			$rule,
			0,
			array( 'default_sender_number' => '+982100000000' ),
			array( 'sms_from_number' => '+982100000000' )
		);
		self::assertSame( LegacyRuleMapper::MANUAL_REQUIRED_AMBIGUOUS, $result['classification'] );
		self::assertSame( $reason, $result['reason'] );
	}

	/**
	 * Provide ambiguous legacy Rule cases.
	 *
	 * @return array<string, array{0:array<string, mixed>,1:string}>
	 */
	public function ambiguous_rules(): array {
		$base = $this->rule();

		$submitter = $base;
		$submitter['recipient_type'] = 'submitter';
		$field = $base;
		$field['recipient_type'] = 'field';
		$pattern = $base;
		$pattern['pattern_code'] = 'abc';
		$legacy_tag = $base;
		$legacy_tag['message_template'] = 'Entry {entry_id}';
		$sender = $base;
		$sender['sender_number'] = '+982100000001';

		return array(
			'submitter' => array( $submitter, 'retired_submitter_contact_contract' ),
			'field' => array( $field, 'entry_field_normalization_requires_operator_validation' ),
			'pattern' => array( $pattern, 'pattern_semantics_not_equivalent' ),
			'legacy tag' => array( $legacy_tag, 'legacy_only_message_tag_requires_semantic_conversion' ),
			'per-rule sender' => array( $sender, 'rule_sender_differs_from_target_global_sender' ),
		);
	}

	/**
	 * Stable legacy array index participates in direct-scope identity.
	 *
	 * @return void
	 */
	public function test_scope_identity_uses_stable_legacy_array_index(): void {
		$rule        = $this->rule();
		$fingerprint = LegacyRuleMapper::fingerprint( $rule );
		self::assertNotSame(
			LegacyRuleMapper::direct_scope_id( 7, 0, $fingerprint ),
			LegacyRuleMapper::direct_scope_id( 7, 1, $fingerprint )
		);
	}

	/**
	 * Build the base deterministic Rule fixture.
	 *
	 * @return array<string, string>
	 */
	private function rule(): array {
		return array(
			'form_id'          => '7',
			'recipient_type'   => 'fixed',
			'fixed_recipient'  => '+989121234567',
			'message_template' => 'Hello {Name:1}',
			'sender_number'    => '',
			'pattern_code'     => '',
		);
	}
}
