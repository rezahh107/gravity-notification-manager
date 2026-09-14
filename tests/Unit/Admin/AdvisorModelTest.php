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

/**
 * Proves Advisor guidance reflects existing product truth without inventing operations.
 */
final class AdvisorModelTest extends TestCase {

	/** Provider setup guidance reflects current Settings readiness. */
	public function test_provider_guidance_reflects_current_readiness(): void {
		$cards = AdvisorModel::build(
			array(
				'ippanel' => false,
				'bale'    => true,
			),
			array(),
			true,
			true,
			true
		);

		$provider = $this->card( $cards, 'provider-setup' );
		self::assertStringContainsString( 'IPPanel needs setup', $provider['answer'] );
		self::assertStringContainsString( 'Bale is currently configured', $provider['answer'] );
		self::assertSame( AdvisorModel::ACTION_SETTINGS, $provider['action'] );
	}

	/** Provider-test guidance points to Settings and distinguishes save from explicit real send. */
	public function test_provider_test_guidance_uses_existing_settings_surface(): void {
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

		$test = $this->card( $cards, 'provider-test' );
		self::assertSame( AdvisorModel::ACTION_SETTINGS, $test['action'] );
		self::assertStringContainsString( 'Saving normal settings never sends', $test['answer'] );
		self::assertStringContainsString( 'real external send', $test['answer'] );
	}

	/** Feed creation guidance reflects Gravity Forms availability and current point inventory. */
	public function test_feed_setup_guidance_reflects_runtime_state(): void {
		$missing = AdvisorModel::build(
			array(
				'ippanel' => false,
				'bale'    => false,
			),
			array(),
			false,
			false,
			false
		);
		$card    = $this->card( $missing, 'create-notification' );
		self::assertStringContainsString( 'Gravity Forms is not currently available', $card['answer'] );
		self::assertSame( AdvisorModel::ACTION_DIAGNOSTICS, $card['action'] );

		$available = AdvisorModel::build(
			array(
				'ippanel' => false,
				'bale'    => false,
			),
			array(),
			true,
			false,
			false
		);
		$card      = $this->card( $available, 'create-notification' );
		self::assertStringContainsString( 'Settings → Gravity Notification Manager', $card['answer'] );
	}

	/** NEEDS_SETUP content reuses exact Point Inspector detail and next-action truth. */
	public function test_needs_setup_guidance_preserves_point_truth(): void {
		$point = $this->point(
			PointStatus::NEEDS_SETUP,
			'Existing inspector detail.',
			'Existing inspector next action.'
		);
		$cards = AdvisorModel::build(
			array(
				'ippanel' => true,
				'bale'    => false,
			),
			array( $point ),
			true,
			true,
			true
		);

		$card = $this->card( $cards, 'needs-setup' );
		self::assertCount( 1, $card['points'] );
		self::assertSame( 'Existing inspector detail.', $card['points'][0]['detail'] );
		self::assertSame( 'Existing inspector next action.', $card['points'][0]['next_action'] );
		self::assertSame( 7, $card['points'][0]['form_id'] );
		self::assertSame( 21, $card['points'][0]['feed_id'] );
	}

	/** Supported Flow state exposes known identities while unavailable Flow invents no target. */
	public function test_flow_guidance_only_exposes_known_points_when_flow_is_available(): void {
		$point     = $this->point( PointStatus::CONFIGURED, 'Configured.', 'No change.' );
		$available = AdvisorModel::build(
			array(
				'ippanel' => true,
				'bale'    => true,
			),
			array( $point ),
			true,
			true,
			true
		);
		$flow      = $this->card( $available, 'gravity-flow-placement' );
		self::assertCount( 1, $flow['flow_points'] );
		self::assertSame( 7, $flow['flow_points'][0]['form_id'] );
		self::assertSame( 21, $flow['flow_points'][0]['feed_id'] );

		$unavailable = AdvisorModel::build(
			array(
				'ippanel' => true,
				'bale'    => true,
			),
			array( $point ),
			true,
			false,
			true
		);
		$flow        = $this->card( $unavailable, 'gravity-flow-placement' );
		self::assertSame( array(), $flow['flow_points'] );
		self::assertSame( AdvisorModel::ACTION_DIAGNOSTICS, $flow['action'] );
		self::assertStringContainsString( 'will not invent a workflow link', $flow['answer'] );
	}

	/** Retry and Attention guidance preserve the current presentation boundaries. */
	public function test_retry_and_attention_guidance_match_existing_architecture(): void {
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

		$retry = $this->card( $cards, 'retry' );
		self::assertStringContainsString( 'Gravity Forms Entry Detail', $retry['answer'] );
		self::assertStringContainsString( 'explicit synchronous action', $retry['answer'] );
		self::assertSame( AdvisorModel::ACTION_NONE, $retry['action'] );

		$attention = $this->card( $cards, 'attention-required' );
		self::assertStringContainsString( 'GravityView / Elementor', $attention['answer'] );
		self::assertStringContainsString( 'Entry Detail', $attention['answer'] );
		self::assertStringContainsString( 'does not know or invent a specific View URL', $attention['answer'] );
		self::assertSame( AdvisorModel::ACTION_NONE, $attention['action'] );
	}

	/**
	 * Find one card by stable identifier.
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
	 * Build one representative Notification Point.
	 *
	 * @param string $state       Point state.
	 * @param string $detail      Existing inspector detail.
	 * @param string $next_action Existing inspector next action.
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
