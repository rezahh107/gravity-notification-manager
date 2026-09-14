<?php
/**
 * Read-only operational Advisor model.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Converts current product truth into task-oriented operator guidance.
 */
final class AdvisorModel {

	public const ACTION_NONE = 'none';
	public const ACTION_SETTINGS = 'settings';
	public const ACTION_POINTS = 'points';
	public const ACTION_DIAGNOSTICS = 'diagnostics';

	/**
	 * Build Advisor cards from already-read current state.
	 *
	 * @param array<string, bool>              $provider_readiness Current provider readiness.
	 * @param array<int, array<string, mixed>> $points Current Notification Point truth.
	 * @param bool                             $gravity_forms_available Whether Gravity Forms is available.
	 * @param bool                             $gravity_flow_available  Whether Gravity Flow navigation is available.
	 * @param bool                             $gravityview_available   Whether GravityView presentation is detected.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build(
		array $provider_readiness,
		array $points,
		bool $gravity_forms_available,
		bool $gravity_flow_available,
		bool $gravityview_available
	): array {
		$needs_setup = array_values(
			array_filter(
				$points,
				static fn( array $point ): bool => PointStatus::NEEDS_SETUP === ( $point['state'] ?? null )
			)
		);

		return array(
			array(
				'id'           => 'provider-setup',
				'question'     => __( 'How do I configure a provider?', 'gravity-notification-manager' ),
				'answer'       => self::provider_setup_answer( $provider_readiness ),
				'action'       => self::ACTION_SETTINGS,
				'action_label' => __( 'Open Settings', 'gravity-notification-manager' ),
			),
			array(
				'id'           => 'provider-test',
				'question'     => __( 'How do I test SMS or Bale?', 'gravity-notification-manager' ),
				'answer'       => __( 'Open Settings and use the dedicated IPPanel / SMS or Bale test control. Saving normal settings never sends a message; each test control is a separate explicit action that performs one real external send.', 'gravity-notification-manager' ),
				'action'       => self::ACTION_SETTINGS,
				'action_label' => __( 'Open provider tests', 'gravity-notification-manager' ),
			),
			array(
				'id'           => 'create-notification',
				'question'     => __( 'How do I create a notification?', 'gravity-notification-manager' ),
				'answer'       => self::feed_setup_answer( $gravity_forms_available, $points ),
				'action'       => empty( $points ) ? self::ACTION_DIAGNOSTICS : self::ACTION_POINTS,
				'action_label' => empty( $points ) ? __( 'Open Help & Diagnostics', 'gravity-notification-manager' ) : __( 'Review Notification Points', 'gravity-notification-manager' ),
			),
			array(
				'id'           => 'gravity-flow-placement',
				'question'     => __( 'Where do I place it in Gravity Flow?', 'gravity-notification-manager' ),
				'answer'       => self::flow_answer( $gravity_flow_available, $points ),
				'action'       => $gravity_flow_available ? self::ACTION_POINTS : self::ACTION_DIAGNOSTICS,
				'action_label' => $gravity_flow_available ? __( 'Review Notification Points', 'gravity-notification-manager' ) : __( 'Open Help & Diagnostics', 'gravity-notification-manager' ),
				'flow_points'  => $gravity_flow_available ? self::flow_points( $points ) : array(),
			),
			array(
				'id'           => 'verify-points',
				'question'     => __( 'How do I verify Notification Points?', 'gravity-notification-manager' ),
				'answer'       => __( 'Open Notification Points to inspect the current Feed and Gravity Flow placement truth. Use Check Again after changing a Feed or workflow Step; GNM only re-reads configuration and never repairs topology automatically.', 'gravity-notification-manager' ),
				'action'       => self::ACTION_POINTS,
				'action_label' => __( 'Open Notification Points', 'gravity-notification-manager' ),
			),
			array(
				'id'           => 'needs-setup',
				'question'     => __( 'Why does this point say NEEDS_SETUP?', 'gravity-notification-manager' ),
				'answer'       => empty( $needs_setup )
					? __( 'No current Notification Point is marked NEEDS_SETUP. If that changes, Advisor will repeat the same detail and next action reported by Notification Points.', 'gravity-notification-manager' )
					: __( 'These Notification Points currently need setup. The detail and next action below come directly from the existing Point Inspector truth.', 'gravity-notification-manager' ),
				'action'       => self::ACTION_POINTS,
				'action_label' => __( 'Open Notification Points', 'gravity-notification-manager' ),
				'points'       => self::point_guidance( $needs_setup, $gravity_flow_available ),
			),
			array(
				'id'           => 'retry',
				'question'     => __( 'How do I Retry a failed notification?', 'gravity-notification-manager' ),
				'answer'       => __( 'Open the affected Gravity Forms Entry Detail and find the Notification Delivery box. Retry appears there only when the stored target is trusted, Attention Required is true, Retry is currently allowed, and your account has the required capability. Retry is an explicit synchronous action and may contact the configured external channel.', 'gravity-notification-manager' ),
				'action'       => self::ACTION_NONE,
				'action_label' => '',
			),
			array(
				'id'           => 'attention-required',
				'question'     => __( 'Where do I see Attention Required?', 'gravity-notification-manager' ),
				'answer'       => self::attention_answer( $gravityview_available ),
				'action'       => self::ACTION_NONE,
				'action_label' => '',
			),
		);
	}

	/**
	 * Explain current provider readiness without exposing secrets.
	 *
	 * @param array<string, bool> $readiness Provider readiness.
	 * @return string
	 */
	private static function provider_setup_answer( array $readiness ): string {
		$ippanel = true === ( $readiness['ippanel'] ?? false );
		$bale    = true === ( $readiness['bale'] ?? false );

		if ( $ippanel && $bale ) {
			return __( 'IPPanel and Bale are currently configured according to Settings readiness. Use Settings to review the SMS sender or replace stored credentials.', 'gravity-notification-manager' );
		}
		if ( ! $ippanel && ! $bale ) {
			return __( 'IPPanel and Bale both need setup. Configure the IPPanel API key plus a valid E.164 sender number, and configure the Bale bot token in Settings.', 'gravity-notification-manager' );
		}
		if ( ! $ippanel ) {
			return __( 'IPPanel needs setup: configure the API key and a valid E.164 sender number in Settings. Bale is currently configured.', 'gravity-notification-manager' );
		}
		return __( 'Bale needs setup: configure the bot token in Settings. IPPanel is currently configured.', 'gravity-notification-manager' );
	}

	/**
	 * Explain the supported Gravity Forms Feed setup path.
	 *
	 * @param bool                             $gravity_forms_available Whether Gravity Forms is available.
	 * @param array<int, array<string, mixed>> $points Current points.
	 * @return string
	 */
	private static function feed_setup_answer( bool $gravity_forms_available, array $points ): string {
		if ( ! $gravity_forms_available ) {
			return __( 'Gravity Forms is not currently available, so a GNM Notification Feed cannot be configured yet. Resolve the dependency first in Help & Diagnostics.', 'gravity-notification-manager' );
		}
		if ( empty( $points ) ) {
			return __( 'No GNM Notification Feed is currently detected. In Gravity Forms, open the intended Form, open Settings → Gravity Notification Manager, create a Feed, complete its message, channel, recipient source and fallback settings, then enable and save it.', 'gravity-notification-manager' );
		}
		return __( 'GNM already detects one or more Notification Feeds. Create or edit each logical notification in Gravity Forms under the Form’s Gravity Notification Manager Feed settings; Notification Points will verify the saved result.', 'gravity-notification-manager' );
	}

	/**
	 * Explain supported workflow placement from current Flow availability.
	 *
	 * @param bool                             $gravity_flow_available Whether supported Flow navigation is available.
	 * @param array<int, array<string, mixed>> $points Current points.
	 * @return string
	 */
	private static function flow_answer( bool $gravity_flow_available, array $points ): string {
		if ( ! $gravity_flow_available ) {
			return __( 'Gravity Flow is not currently available, so Advisor will not invent a workflow link. Resolve the dependency in Help & Diagnostics, then verify placement from Notification Points.', 'gravity-notification-manager' );
		}
		if ( empty( $points ) ) {
			return __( 'Create the GNM Notification Feed first. When the notification must run at a workflow position, open the Form’s Workflow settings, add the supported Gravity Notification Manager Feed Step at the intended position, select the Feed and save.', 'gravity-notification-manager' );
		}
		return __( 'When a notification must run at a workflow position, use the Form’s Workflow settings and the supported Gravity Notification Manager Feed Step. The known Form links below reuse GNM’s existing Gravity Flow navigation helper.', 'gravity-notification-manager' );
	}

	/**
	 * Preserve current Point Inspector detail and next-action truth.
	 *
	 * @param array<int, array<string, mixed>> $points Current points.
	 * @param bool                             $flow_available Whether Flow links are supported.
	 * @return array<int, array<string, mixed>>
	 */
	private static function point_guidance( array $points, bool $flow_available ): array {
		$guidance = array();
		foreach ( $points as $point ) {
			$guidance[] = array(
				'form_id'       => (int) ( $point['form_id'] ?? 0 ),
				'form_title'    => (string) ( $point['form_title'] ?? '' ),
				'feed_id'       => (int) ( $point['feed_id'] ?? 0 ),
				'feed_name'     => (string) ( $point['feed_name'] ?? '' ),
				'state'         => (string) ( $point['state'] ?? '' ),
				'detail'        => (string) ( $point['detail'] ?? '' ),
				'next_action'   => (string) ( $point['next_action'] ?? '' ),
				'flow_available' => $flow_available,
			);
		}
		return $guidance;
	}

	/**
	 * Return known Form/Feed identities eligible for exact Flow navigation.
	 *
	 * @param array<int, array<string, mixed>> $points Current points.
	 * @return array<int, array<string, mixed>>
	 */
	private static function flow_points( array $points ): array {
		$items = array();
		foreach ( $points as $point ) {
			$form_id = (int) ( $point['form_id'] ?? 0 );
			$feed_id = (int) ( $point['feed_id'] ?? 0 );
			if ( $form_id < 1 || $feed_id < 1 ) {
				continue;
			}
			$items[] = array(
				'form_id'    => $form_id,
				'form_title' => (string) ( $point['form_title'] ?? '' ),
				'feed_id'    => $feed_id,
				'feed_name'  => (string) ( $point['feed_name'] ?? '' ),
			);
		}
		return $items;
	}

	/**
	 * Explain the existing Attention Required presentation boundary.
	 *
	 * @param bool $gravityview_available Whether GravityView is detected.
	 * @return string
	 */
	private static function attention_answer( bool $gravityview_available ): string {
		if ( $gravityview_available ) {
			return __( 'Central Attention Required cases remain in the site-configured GravityView / Elementor presentation. Advisor does not know or invent a specific View URL. Gravity Forms Entry Detail remains the independent status and Retry fallback for an individual Entry.', 'gravity-notification-manager' );
		}
		return __( 'GravityView is not currently detected. Advisor does not create a replacement case dashboard; use Gravity Forms Entry Detail as the independent status and Retry fallback until the site’s GravityView / Elementor Attention Required presentation is available.', 'gravity-notification-manager' );
	}
}
