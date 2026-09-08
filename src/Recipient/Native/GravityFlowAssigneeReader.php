<?php
/**
 * Native Gravity Flow explicit-step assignee reader.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient\Native;

use GravityNotify\Recipient\FlowAssigneeReader;

/**
 * Reads documented assignee identity from one explicitly selected workflow Step.
 */
final class GravityFlowAssigneeReader implements FlowAssigneeReader {

	/**
	 * Read assignees from one explicit Step through the documented Gravity Flow API.
	 *
	 * @param array $entry   Current Gravity Forms Entry object.
	 * @param array $form    Current Gravity Forms Form object.
	 * @param int   $step_id Explicit positive Gravity Flow Step ID.
	 * @return array<string, mixed>
	 */
	public function read( array $entry, array $form, int $step_id ): array {
		if ( 0 >= $step_id ) {
			return $this->unavailable( 'flow_step_selector_invalid' );
		}

		if ( ! class_exists( '\\Gravity_Flow_API' ) ) {
			return $this->unavailable( 'flow_assignee_api_unavailable' );
		}

		$form_id = $this->form_id( $entry, $form );
		if ( null === $form_id ) {
			return $this->unavailable( 'flow_step_unavailable' );
		}

		try {
			$api = new \Gravity_Flow_API( $form_id );
			if ( ! method_exists( $api, 'get_step' ) ) {
				return $this->unavailable( 'flow_assignee_api_unavailable' );
			}
			$step = $api->get_step( $step_id, $entry );
		} catch ( \Throwable ) {
			return $this->unavailable( 'flow_step_unavailable' );
		}

		if ( ! is_object( $step ) ) {
			return $this->unavailable( 'flow_step_unavailable' );
		}

		if ( ! method_exists( $step, 'get_assignees' ) ) {
			return $this->unavailable( 'flow_assignee_api_unavailable' );
		}

		try {
			$objects = $step->get_assignees();
		} catch ( \Throwable ) {
			return $this->unavailable( 'flow_assignee_api_unavailable' );
		}

		if ( ! is_array( $objects ) ) {
			return $this->unavailable( 'flow_assignee_api_unavailable' );
		}

		$assignees = array();
		foreach ( $objects as $assignee ) {
			if ( ! is_object( $assignee ) || ! method_exists( $assignee, 'get_type' ) || ! method_exists( $assignee, 'get_id' ) ) {
				$assignees[] = array(
					'type' => '',
					'id'   => '',
				);
				continue;
			}

			try {
				$type = $assignee->get_type();
				$id   = $assignee->get_id();
			} catch ( \Throwable ) {
				$assignees[] = array(
					'type' => '',
					'id'   => '',
				);
				continue;
			}

			$assignees[] = array(
				'type' => is_scalar( $type ) ? (string) $type : '',
				'id'   => is_scalar( $id ) ? (string) $id : '',
			);
		}

		return array(
			'available' => true,
			'reason'    => '',
			'assignees' => $assignees,
		);
	}

	/**
	 * Resolve a positive form ID from current form/entry context.
	 *
	 * @param array $entry Entry object.
	 * @param array $form  Form object.
	 * @return int|null
	 */
	private function form_id( array $entry, array $form ): ?int {
		$candidate = $form['id'] ?? ( $entry['form_id'] ?? null );
		if ( ! is_scalar( $candidate ) || ! ctype_digit( (string) $candidate ) || 0 >= (int) $candidate ) {
			return null;
		}

		return (int) $candidate;
	}

	/**
	 * Build a safe unavailable-context result.
	 *
	 * @param string $reason Safe reason classification.
	 * @return array<string, mixed>
	 */
	private function unavailable( string $reason ): array {
		return array(
			'available' => false,
			'reason'    => $reason,
			'assignees' => array(),
		);
	}
}
