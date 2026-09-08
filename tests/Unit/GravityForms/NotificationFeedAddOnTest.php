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
 * Proves the Feed contract remains native, synchronous and safely unconfigured.
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
	 * Ensure singleton execution dependencies do not leak between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		NotificationFeedAddOn::get_instance()->configure_processor( null );
		parent::tearDown();
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
	 * Required fields retain native condition and merge-tag facilities.
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
	 * Fallback intent never changes the message semantics in the Feed schema.
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
	 * Runtime delivery remains disabled until a composed WU-04 processor is injected.
	 *
	 * @return void
	 */
	public function test_process_feed_without_runtime_configuration_is_safe_and_observable(): void {
		$add_on = NotificationFeedAddOn::get_instance();
		$add_on->configure_processor( null );

		$result = $add_on->process_feed(
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

		self::assertFalse( $result );
		self::assertNotNull( $add_on->last_execution_result() );
		self::assertFalse( $add_on->last_execution_result()->delivery_succeeded() );
		self::assertSame( 'runtime_not_configured', $add_on->last_execution_result()->skips()[0]['reason'] );
	}
}
