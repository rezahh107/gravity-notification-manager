<?php
/**
 * Tests for the greenfield Gravity Forms Feed foundation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\GravityForms;

use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\Tests\Support\GravityForms\GFFeedAddOnStub;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Proves the bounded WU-01 feed contract without provider delivery.
 */
final class NotificationFeedAddOnTest extends TestCase {

	/**
	 * Install the deterministic global Gravity Forms parent-class alias.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! class_exists( 'GFFeedAddOn', false ) ) {
			class_alias( GFFeedAddOnStub::class, 'GFFeedAddOn' );
		}
	}

	/**
	 * The primary class uses the supported feed-based Add-On parent contract.
	 *
	 * @return void
	 */
	public function test_primary_class_extends_gf_feed_addon(): void {
		self::assertInstanceOf( \GFFeedAddOn::class, NotificationFeedAddOn::get_instance() );
	}

	/**
	 * Closed architecture keeps Gravity Forms feed execution synchronous.
	 *
	 * @return void
	 */
	public function test_async_feed_processing_is_explicitly_disabled(): void {
		$property = new ReflectionProperty( NotificationFeedAddOn::class, '_async_feed_processing' );

		self::assertFalse( $property->getValue( NotificationFeedAddOn::get_instance() ) );
	}

	/**
	 * Required WU-01 fields use native condition and merge-tag facilities.
	 *
	 * @return void
	 */
	public function test_feed_settings_contain_required_rule_fields_and_native_facilities(): void {
		$sections = NotificationFeedAddOn::get_instance()->feed_settings_fields();
		$fields   = $sections[0]['fields'];
		$by_name  = array();

		foreach ( $fields as $field ) {
			$by_name[ $field['name'] ] = $field;
		}

		foreach ( array( 'feedName', 'message', 'recipient_source_type', 'recipient_source_value', 'channel', 'fallback_policy', 'feed_condition' ) as $required_name ) {
			self::assertArrayHasKey( $required_name, $by_name );
		}

		self::assertSame( 'feed_condition', $by_name['feed_condition']['type'] );
		self::assertStringContainsString( 'merge-tag-support', $by_name['message']['class'] );
	}

	/**
	 * Schema version and normalization remain deterministic.
	 *
	 * @return void
	 */
	public function test_schema_version_and_normalization_are_stable(): void {
		$normalized = FeedRuleSchema::normalize(
			array(
				'feedName'               => 'Case update',
				'message'                => 'pattern:case_update',
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_ENTRY_FIELD,
				'recipient_source_value' => '7',
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => FeedRuleSchema::FALLBACK_COMPATIBLE_SMS,
			)
		);

		self::assertSame( 1, FeedRuleSchema::VERSION );
		self::assertSame( 1, $normalized['schema_version'] );
		self::assertSame( 'pattern:case_update', $normalized['message'] );
	}

	/**
	 * SMS and Bale remain separate channel choices.
	 *
	 * @return void
	 */
	public function test_sms_and_bale_are_distinct_channels(): void {
		self::assertSame(
			array( FeedRuleSchema::CHANNEL_SMS, FeedRuleSchema::CHANNEL_BALE ),
			FeedRuleSchema::channels()
		);
		self::assertNotSame( FeedRuleSchema::CHANNEL_SMS, FeedRuleSchema::CHANNEL_BALE );
	}

	/**
	 * Fallback intent never changes the message semantics in WU-01.
	 *
	 * @return void
	 */
	public function test_fallback_configuration_does_not_convert_pattern_to_plain(): void {
		$normalized = FeedRuleSchema::normalize(
			array(
				'message'         => 'pattern:welcome',
				'channel'         => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy' => FeedRuleSchema::FALLBACK_COMPATIBLE_SMS,
			)
		);

		self::assertSame( 'pattern:welcome', $normalized['message'] );
		self::assertSame( FeedRuleSchema::FALLBACK_COMPATIBLE_SMS, $normalized['fallback_policy'] );
		self::assertNotContains( 'plain', FeedRuleSchema::fallback_policies(), true );
	}

	/**
	 * The WU-01 process boundary performs no external delivery action.
	 *
	 * @return void
	 */
	public function test_process_feed_is_side_effect_free_for_external_delivery(): void {
		$before = array(
			'no_send_enabled' => defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) && GRAVITY_NOTIFY_TEST_NO_SEND,
			'http_blocked'    => defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL,
		);

		NotificationFeedAddOn::get_instance()->process_feed(
			array(
				'meta' => array(
					'feedName'               => 'No-send foundation',
					'message'                => 'No provider call',
					'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
					'recipient_source_value' => 'test-only',
					'channel'                => FeedRuleSchema::CHANNEL_BALE,
					'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
				),
			),
			array(),
			array()
		);

		self::assertSame(
			$before,
			array(
				'no_send_enabled' => defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) && GRAVITY_NOTIFY_TEST_NO_SEND,
				'http_blocked'    => defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL,
			)
		);
	}
}
