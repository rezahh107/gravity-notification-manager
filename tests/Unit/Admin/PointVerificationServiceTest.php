<?php
/**
 * Tests for explicit Notification Point re-verification.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\PointStatus;
use GravityNotify\Admin\PointVerificationService;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\Tests\Support\Admin\MutableAdminSource;
use PHPUnit\Framework\TestCase;

/**
 * Proves Check Again re-reads authoritative truth and never mutates topology.
 */
final class PointVerificationServiceTest extends TestCase {

	/**
	 * Re-verification reflects a later authoritative Flow configuration change.
	 *
	 * @return void
	 */
	public function test_check_again_re_reads_authoritative_configuration_and_changes_truthfully(): void {
		$source = new MutableAdminSource();
		$source->feeds[0]['meta']['recipient_source_type']  = FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE;
		$source->feeds[0]['meta']['recipient_source_value'] = '';
		$service = new PointVerificationService( $source );

		$first_reads = $source->read_count;
		self::assertSame( PointStatus::NEEDS_SETUP, $service->verify( 4, 11 )['state'] );
		self::assertGreaterThan( $first_reads, $source->read_count );

		$source->placements = array(
			array(
				'step_id'   => 22,
				'step_name' => 'Notify',
				'feed_ids'  => array( 11 ),
			),
		);
		$before_second = $source->read_count;
		self::assertSame( PointStatus::CONFIGURED, $service->verify( 4, 11 )['state'] );
		self::assertGreaterThan( $before_second, $source->read_count );
		self::assertSame( 0, $source->mutation_count );
	}

	/**
	 * Invalid identifiers fail closed without consulting the configuration source.
	 *
	 * @return void
	 */
	public function test_invalid_identifiers_fail_closed_without_reads(): void {
		$source  = new MutableAdminSource();
		$service = new PointVerificationService( $source );
		self::assertNull( $service->verify( 0, 11 ) );
		self::assertNull( $service->verify( 4, -1 ) );
		self::assertSame( 0, $source->read_count );
	}
}
