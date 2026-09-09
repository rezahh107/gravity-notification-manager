<?php
/**
 * Tests for deterministic Notification Point inspection.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\PointInspector;
use GravityNotify\Admin\PointStatus;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\Tests\Support\Admin\MutableAdminSource;
use PHPUnit\Framework\TestCase;

/**
 * Proves truthful Point states and the no-topology-mutation boundary.
 */
final class PointInspectorTest extends TestCase {

	/**
	 * A complete submission Feed is configured without parallel topology state.
	 *
	 * @return void
	 */
	public function test_submission_feed_is_configured_without_parallel_topology_state(): void {
		$source = new MutableAdminSource();
		$point  = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::CONFIGURED, $point['state'] );
		self::assertStringContainsString( 'normal Gravity Forms Feed lifecycle', $point['detail'] );
		self::assertSame( 0, $source->mutation_count );
	}

	/**
	 * T-01: a single inactive matching Step is actionable NEEDS_SETUP.
	 *
	 * @return void
	 */
	public function test_single_inactive_flow_placement_reports_actionable_needs_setup(): void {
		$source = new MutableAdminSource();
		$source->placements = array(
			array(
				'step_id'   => 9,
				'step_name' => 'Approval notice',
				'feed_ids'  => array( 11 ),
				'active'    => false,
			),
		);
		$point = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::NEEDS_SETUP, $point['state'] );
		self::assertSame( 9, $point['flow_step_id'] );
		self::assertSame( 'Approval notice', $point['flow_step_name'] );
		self::assertStringContainsString( 'is inactive', $point['detail'] );
		self::assertStringContainsString( 'activate this Step or correct the intended placement', $point['next_action'] );
		self::assertSame( 0, $source->mutation_count );
	}

	/**
	 * T-02: a single active GNM Flow placement remains configured with Step context.
	 *
	 * @return void
	 */
	public function test_valid_flow_placement_reports_configured_with_bounded_context(): void {
		$source = new MutableAdminSource();
		$source->placements = array(
			array(
				'step_id'   => 9,
				'step_name' => 'Approval notice',
				'feed_ids'  => array( 11 ),
				'active'    => true,
			),
		);
		$point = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::CONFIGURED, $point['state'] );
		self::assertSame( 9, $point['flow_step_id'] );
		self::assertSame( 'Approval notice', $point['flow_step_name'] );
		self::assertStringContainsString( 'No topology change is required', $point['next_action'] );
	}

	/**
	 * A Flow-assignee Feed without a Flow Step reports exact setup guidance.
	 *
	 * @return void
	 */
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

	/**
	 * T-03: mixed active/inactive duplicate placements preserve inconsistency semantics.
	 *
	 * @return void
	 */
	public function test_multiple_flow_placements_are_inconsistent_and_never_auto_repaired(): void {
		$source = new MutableAdminSource();
		$source->placements = array(
			array(
				'step_id'   => 9,
				'step_name' => 'One',
				'feed_ids'  => array( 11 ),
				'active'    => true,
			),
			array(
				'step_id'   => 10,
				'step_name' => 'Two',
				'feed_ids'  => array( 11 ),
				'active'    => false,
			),
		);
		$point = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::NEEDS_SETUP, $point['state'] );
		self::assertStringContainsString( 'multiple GNM workflow Steps', $point['detail'] );
		self::assertStringContainsString( 'will not change Steps automatically', $point['next_action'] );
		self::assertSame( 0, $source->mutation_count );
	}

	/**
	 * T-04: inactive matching evidence cannot collapse into normal submission truth.
	 *
	 * @return void
	 */
	public function test_non_flow_recipient_with_inactive_matching_step_is_not_submission_configured(): void {
		$source = new MutableAdminSource();
		$source->placements = array(
			array(
				'step_id'   => 12,
				'step_name' => 'Inactive submission override',
				'feed_ids'  => array( 11 ),
				'active'    => false,
			),
		);
		$point = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::NEEDS_SETUP, $point['state'] );
		self::assertStringNotContainsString( 'normal Gravity Forms Feed lifecycle', $point['detail'] );
		self::assertStringContainsString( 'is inactive', $point['detail'] );
	}

	/**
	 * T-05: Flow-assignee inactive placement differs from a missing placement.
	 *
	 * @return void
	 */
	public function test_flow_assignee_inactive_step_requires_activation_not_addition(): void {
		$source = new MutableAdminSource();
		$source->feeds[0]['meta']['recipient_source_type']  = FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE;
		$source->feeds[0]['meta']['recipient_source_value'] = '';
		$source->placements = array(
			array(
				'step_id'   => 13,
				'step_name' => 'Assignee notice',
				'feed_ids'  => array( 11 ),
				'active'    => false,
			),
		);
		$point = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::NEEDS_SETUP, $point['state'] );
		self::assertStringContainsString( 'is inactive', $point['detail'] );
		self::assertStringContainsString( 'activate this Step or correct', $point['next_action'] );
		self::assertStringNotContainsString( 'Add a Gravity Notification Manager Feed Step', $point['next_action'] );
		self::assertSame( 0, $source->mutation_count );
	}

	/**
	 * Missing Gravity Flow availability is reported truthfully, not guessed.
	 *
	 * @return void
	 */
	public function test_missing_dependency_is_truthful_not_applicable(): void {
		$source             = new MutableAdminSource();
		$source->placements = null;
		$point              = ( new PointInspector( $source ) )->all()[0];
		self::assertSame( PointStatus::NOT_APPLICABLE, $point['state'] );
		self::assertStringContainsString( 'Gravity Flow is unavailable', $point['detail'] );
	}

	/**
	 * Incomplete and disabled Feed states remain distinct.
	 *
	 * @return void
	 */
	public function test_incomplete_and_disabled_feeds_are_distinguished(): void {
		$source                             = new MutableAdminSource();
		$source->feeds[0]['meta']['message'] = '';
		self::assertSame( PointStatus::NEEDS_SETUP, ( new PointInspector( $source ) )->all()[0]['state'] );
		$source->feeds[0]['is_active'] = false;
		self::assertSame( PointStatus::DISABLED, ( new PointInspector( $source ) )->all()[0]['state'] );
	}
}
