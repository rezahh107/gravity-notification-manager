<?php
/**
 * Context-aware Advisor model regression coverage.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\AdvisorModel;
use GravityNotify\Admin\PointStatus;
use PHPUnit\Framework\TestCase;

/** Proves Advisor guidance reflects existing product truth without inventing operations. */
final class AdvisorModelTest extends TestCase {

	/** SMS provider guidance reflects the current multi-provider readiness model. */
	public function test_provider_guidance_reflects_current_readiness(): void {
		$cards = AdvisorModel::build(
			array(
				'ippanel'     => false,
				'melipayamak' => true,
				'smsir'       => false,
				'farazsms'    => false,
				'bale'        => true,
			),
			array(),
			true,
			true,
			true
		);
		$provider = $this->card( $cards, 'provider-setup' );
		self::assertStringContainsString( 'At least one approved SMS provider is ready', $provider['answer'] );
		self::assertSame( AdvisorModel::ACTION_PROVIDERS, $provider['action'] );
	}

	/** Provider-test guidance points to Provider Manager for SMS and preserves explicit-send semantics. */
	public function test_provider_test_guidance_uses_provider_manager_surface(): void {
		$cards = AdvisorModel::build(
			array(
				'ippanel' => true,
				'bale'    => true,
			),
			array(),
			true,
			true,
			true
		);
		$test  = $this->card( $cards, 'provider-test' );
		self::assertSame( AdvisorModel::ACTION_PROVIDERS, $test['action'] );
		self::assertStringContainsString( 'Saving ordinary settings never sends', $test['answer'] );
		self::assertStringContainsString( 'real external send', $test['answer'] );
	}

	/** Feed creation guidance reflects Gravity Forms availability and current point inventory. */
	public function test_feed_setup_guidance_reflects_runtime_state(): void {
		$missing = AdvisorModel::build( array(), array(), false, false, false );
		$card    = $this->card( $missing, 'create-notification' );
		self::assertStringContainsString( 'Gravity Forms is not currently available', $card['answer'] );
		self::assertSame( AdvisorModel::ACTION_DIAGNOSTICS, $card['action'] );

		$available = AdvisorModel::build( array(), array(), true, false, false );
		$card      = $this->card( $available, 'create-notification' );
		self::assertStringContainsString( 'Settings → Gravity Notification Manager', $card['answer'] );
	}

	/** NEEDS_SETUP content reuses exact Point Inspector detail and next-action truth. */
	public function test_needs_setup_guidance_preserves_point_truth(): void {
		$point = $this->point( PointStatus::NEEDS_SETUP, 'Existing inspector detail.', 'Existing inspector next action.' );
		$cards = AdvisorModel::build( array(), array( $point ), true, true, true );
		$card  = $this->card( $cards, 'needs-setup' );
		self::assertCount( 1, $card['points'] );
		self::assertSame( 'Existing inspector detail.', $card['points'][0]['detail'] );
		self::assertSame( 'Existing inspector next action.', $card['points'][0]['next_action'] );
	}

	/** Supported Flow state exposes known identities while unavailable Flow invents no target. */
	public function test_flow_guidance_only_exposes_known_points_when_flow_is_available(): void {
		$point     = $this->point( PointStatus::CONFIGURED, 'Configured.', 'No change.' );
		$available = AdvisorModel::build( array(), array( $point ), true, true, true );
		$flow      = $this->card( $available, 'gravity-flow-placement' );
		self::assertCount( 1, $flow['flow_points'] );
		self::assertSame( 7, $flow['flow_points'][0]['form_id'] );

		$unavailable = AdvisorModel::build( array(), array( $point ), true, false, true );
		$flow        = $this->card( $unavailable, 'gravity-flow-placement' );
		self::assertSame( array(), $flow['flow_points'] );
		self::assertSame( AdvisorModel::ACTION_DIAGNOSTICS, $flow['action'] );
	}

	/** Retry and Attention guidance preserve current presentation boundaries. */
	public function test_retry_and_attention_guidance_match_existing_architecture(): void {
		$cards     = AdvisorModel::build( array(), array(), true, true, true );
		$retry     = $this->card( $cards, 'retry' );
		$attention = $this->card( $cards, 'attention-required' );
		self::assertStringContainsString( 'Gravity Forms Entry Detail', $retry['answer'] );
		self::assertSame( AdvisorModel::ACTION_NONE, $retry['action'] );
		self::assertStringContainsString( 'GravityView / Elementor', $attention['answer'] );
		self::assertSame( AdvisorModel::ACTION_NONE, $attention['action'] );
	}

	/**
	 * Find one Advisor card by identifier.
	 *
	 * @param array<int, array<string, mixed>> $cards Advisor cards.
	 * @param string                           $id    Card identifier.
	 * @return array<string, mixed>
	 */
	private function card( array $cards, string $id ): array {
		foreach ( $cards as $card ) {
			if ( ( $card['id'] ?? null ) === $id ) {
				return $card;
			}
		}
		self::fail( 'Advisor card not found: ' . $id );
	}

	/**
	 * Build one Point Inspector fixture.
	 *
	 * @param string $state       Point state.
	 * @param string $detail      Point detail.
	 * @param string $next_action Point next action.
	 * @return array<string, mixed>
	 */
	private function point( string $state, string $detail, string $next_action ): array {
		return array(
			'form_id'        => 7,
			'form_title'     => 'Registration',
			'feed_id'        => 21,
			'feed_name'      => 'Notify Student',
			'channel'        => 'SMS',
			'fallback'       => 'bale',
			'state'          => $state,
			'detail'         => $detail,
			'next_action'    => $next_action,
			'flow_step_id'   => 9,
			'flow_step_name' => 'Notify Student',
		);
	}
}
