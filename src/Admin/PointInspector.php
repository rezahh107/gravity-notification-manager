<?php
/**
 * Deterministic Notification Point inspector.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\GravityForms\FeedRuleSchema;

/**
 * Derives operator status from authoritative Feed/Flow reads only.
 */
final class PointInspector {
	private ConfigurationSourceInterface $source;

	public function __construct( ConfigurationSourceInterface $source ) {
		$this->source = $source;
	}

	/** @return array<int, array<string,mixed>> */
	public function all(): array {
		$points = array();
		foreach ( $this->source->forms() as $form ) {
			$form_id = (int) ( $form['id'] ?? 0 );
			if ( $form_id < 1 ) {
				continue;
			}
			$points = array_merge( $points, $this->for_form( $form_id, (string) ( $form['title'] ?? 'Form ' . $form_id ) ) );
		}
		return $points;
	}

	/** @return array<string,mixed>|null */
	public function find( int $form_id, int $feed_id ): ?array {
		if ( $form_id < 1 || $feed_id < 1 ) {
			return null;
		}
		$form_title = 'Form ' . $form_id;
		foreach ( $this->source->forms() as $form ) {
			if ( $form_id === (int) ( $form['id'] ?? 0 ) ) {
				$form_title = (string) ( $form['title'] ?? $form_title );
				break;
			}
		}
		foreach ( $this->for_form( $form_id, $form_title ) as $point ) {
			if ( $feed_id === (int) $point['feed_id'] ) {
				return $point;
			}
		}
		return null;
	}

	/** @return array<int, array<string,mixed>> */
	private function for_form( int $form_id, string $form_title ): array {
		$feeds = $this->source->feeds( $form_id );
		$ids   = array();
		foreach ( $feeds as $feed ) {
			$id = self::positive_id( is_array( $feed ) ? ( $feed['id'] ?? null ) : null );
			if ( null !== $id ) {
				$ids[] = $id;
			}
		}
		$placements = $this->source->workflow_placements( $form_id, $ids );
		$points     = array();

		foreach ( $feeds as $feed ) {
			if ( ! is_array( $feed ) ) {
				continue;
			}
			$feed_id = self::positive_id( $feed['id'] ?? null );
			if ( null === $feed_id ) {
				continue;
			}
			$rule  = FeedRuleSchema::normalize( isset( $feed['meta'] ) && is_array( $feed['meta'] ) ? $feed['meta'] : array() );
			$point = $this->base_point( $form_id, $form_title, $feed_id, $rule );

			if ( ! self::is_active( $feed['is_active'] ?? true ) ) {
				$point['state']       = PointStatus::DISABLED;
				$point['detail']      = 'This notification Feed is disabled.';
				$point['next_action'] = 'Enable the Feed in Gravity Forms when this notification should run.';
				$points[]             = $point;
				continue;
			}

			$missing = $this->missing_rule_fields( $rule );
			if ( ! empty( $missing ) ) {
				$point['state']       = PointStatus::NEEDS_SETUP;
				$point['detail']      = 'Feed configuration is incomplete: ' . implode( ', ', $missing ) . '.';
				$point['next_action'] = 'Open this Gravity Forms notification Feed and complete the listed settings, then save it.';
				$points[]             = $point;
				continue;
			}

			if ( null === $placements ) {
				$point['state']       = PointStatus::NOT_APPLICABLE;
				$point['detail']      = 'Gravity Flow is unavailable, so workflow placement cannot be verified.';
				$point['next_action'] = 'Activate a supported Gravity Flow installation, then use Check Again.';
				$points[]             = $point;
				continue;
			}

			$matches = $this->placements_for_feed( $placements, $feed_id );
			if ( count( $matches ) > 1 ) {
				$point['state']       = PointStatus::NEEDS_SETUP;
				$point['detail']      = 'This Feed is selected by multiple GNM workflow Steps.';
				$point['next_action'] = 'Open Forms → ' . $form_title . ' → Settings → Workflow and keep this Feed selected only at the intended notification position. GNM will not change Steps automatically.';
				$points[]             = $point;
				continue;
			}

			if ( FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE === $rule['recipient_source_type'] && empty( $matches ) ) {
				$point['state']       = PointStatus::NEEDS_SETUP;
				$point['detail']      = 'This Feed needs Gravity Flow assignee context but is not selected by a GNM workflow Step.';
				$point['next_action'] = 'Open Forms → ' . $form_title . ' → Settings → Workflow. Add a Gravity Notification Manager Feed Step at the intended position, select this Feed, and save. GNM will not insert or reorder Steps.';
				$points[]             = $point;
				continue;
			}

			$point['state'] = PointStatus::CONFIGURED;
			if ( 1 === count( $matches ) ) {
				$point['flow_step_id']   = $matches[0]['step_id'];
				$point['flow_step_name'] = $matches[0]['step_name'];
				$point['detail']         = 'Configured in Gravity Flow Step “' . $matches[0]['step_name'] . '”.';
				$point['next_action']    = 'No topology change is required. Use Check Again after workflow edits.';
			} else {
				$point['detail']      = 'Configured for the normal Gravity Forms Feed lifecycle on submission.';
				$point['next_action'] = 'No workflow Step is required unless this notification must run at a workflow position.';
			}
			$points[] = $point;
		}

		return $points;
	}

	/** @param array<string,int|string> $rule */
	private function base_point( int $form_id, string $form_title, int $feed_id, array $rule ): array {
		$name = trim( (string) ( $rule['feedName'] ?? '' ) );
		return array(
			'form_id'        => $form_id,
			'form_title'     => $form_title,
			'feed_id'        => $feed_id,
			'feed_name'      => '' === $name ? 'Feed ' . $feed_id : $name,
			'channel'        => strtoupper( (string) ( $rule['channel'] ?? '' ) ),
			'fallback'       => (string) ( $rule['fallback_policy'] ?? FeedRuleSchema::FALLBACK_NONE ),
			'state'          => PointStatus::NEEDS_SETUP,
			'detail'         => '',
			'next_action'    => '',
			'flow_step_id'   => null,
			'flow_step_name' => null,
		);
	}

	/** @param array<string,int|string> $rule @return array<int,string> */
	private function missing_rule_fields( array $rule ): array {
		$missing = array();
		if ( '' === trim( (string) ( $rule['feedName'] ?? '' ) ) ) {
			$missing[] = 'Feed Name';
		}
		if ( '' === trim( (string) ( $rule['message'] ?? '' ) ) ) {
			$missing[] = 'Message';
		}
		if ( ! in_array( (string) ( $rule['channel'] ?? '' ), FeedRuleSchema::channels(), true ) ) {
			$missing[] = 'Channel';
		}
		$recipient_type = (string) ( $rule['recipient_source_type'] ?? '' );
		if ( ! in_array( $recipient_type, FeedRuleSchema::recipient_source_types(), true ) ) {
			$missing[] = 'Recipient Source';
		} elseif ( FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE !== $recipient_type && '' === trim( (string) ( $rule['recipient_source_value'] ?? '' ) ) ) {
			$missing[] = 'Recipient Source Value';
		}
		if ( ! in_array( (string) ( $rule['fallback_policy'] ?? '' ), FeedRuleSchema::fallback_policies(), true ) ) {
			$missing[] = 'Fallback Policy';
		}
		return $missing;
	}

	/** @param array<int,array{step_id:int,step_name:string,feed_ids:array<int,int>}> $placements */
	private function placements_for_feed( array $placements, int $feed_id ): array {
		return array_values(
			array_filter(
				$placements,
				static fn( array $placement ): bool => in_array( $feed_id, $placement['feed_ids'], true )
			)
		);
	}

	private static function is_active( $value ): bool {
		return true === $value || 1 === $value || '1' === $value;
	}

	private static function positive_id( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return null;
		}
		$value = (int) $value;
		return $value > 0 ? $value : null;
	}
}
