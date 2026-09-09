<?php
/** @package GravityNotify */
namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\PointInspector;
use GravityNotify\Admin\PointStatus;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\Tests\Support\Admin\MutableAdminSource;
use PHPUnit\Framework\TestCase;

final class PointInspectorTest extends TestCase {
	public function test_submission_feed_is_configured_without_parallel_topology_state(): void {
		$source = new MutableAdminSource();
		$point  = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::CONFIGURED, $point['state'] );
		self::assertStringContainsString( 'normal Gravity Forms Feed lifecycle', $point['detail'] );
		self::assertSame( 0, $source->mutation_count );
	}

	public function test_valid_flow_placement_reports_configured_with_bounded_context(): void {
		$source = new MutableAdminSource();
		$source->placements = array( array( 'step_id' => 9, 'step_name' => 'Approval notice', 'feed_ids' => array( 11 ) ) );
		$point = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::CONFIGURED, $point['state'] );
		self::assertSame( 9, $point['flow_step_id'] );
		self::assertSame( 'Approval notice', $point['flow_step_name'] );
	}

	public function test_flow_assignee_feed_without_step_reports_actionable_needs_setup(): void {
		$source = new MutableAdminSource();
		$source->feeds[0]['meta']['recipient_source_type']  = FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE;
		$source->feeds[0]['meta']['recipient_source_value'] = '';
		$point = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::NEEDS_SETUP, $point['state'] );
		self::assertStringContainsString( 'Add a Gravity Notification Manager Feed Step', $point['next_action'] );
		self::assertStringContainsString( 'will not insert or reorder', $point['next_action'] );
		self::assertSame( 0, $source->mutation_count );
	}

	public function test_multiple_flow_placements_are_inconsistent_and_never_auto_repaired(): void {
		$source = new MutableAdminSource();
		$source->placements = array(
			array( 'step_id' => 9, 'step_name' => 'One', 'feed_ids' => array( 11 ) ),
			array( 'step_id' => 10, 'step_name' => 'Two', 'feed_ids' => array( 11 ) ),
		);
		$point = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::NEEDS_SETUP, $point['state'] );
		self::assertStringContainsString( 'multiple GNM workflow Steps', $point['detail'] );
		self::assertStringContainsString( 'will not change Steps automatically', $point['next_action'] );
	}

	public function test_missing_dependency_is_truthful_not_applicable(): void {
		$source = new MutableAdminSource();
		$source->placements = null;
		$point = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::NOT_APPLICABLE, $point['state'] );
		self::assertStringContainsString( 'Gravity Flow is unavailable', $point['detail'] );
	}

	public function test_incomplete_and_disabled_feeds_are_distinguished(): void {
		$source = new MutableAdminSource();
		$source->feeds[0]['meta']['message'] = '';
		self::assertSame( PointStatus::NEEDS_SETUP, ( new PointInspector( $source ) )->all()[0]['state'] );
		$source->feeds[0]['is_active'] = false;
		self::assertSame( PointStatus::DISABLED, ( new PointInspector( $source ) )->all()[0]['state'] );
	}
}
