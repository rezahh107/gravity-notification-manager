<?php
/**
 * Native Gravity Flow current-step assignee reader.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient\Native;

use GravityNotify\Recipient\FlowAssigneeReader;

/**
 * Reads documented current-step assignee identity without workflow mutation.
 */
final class GravityFlowAssigneeReader implements FlowAssigneeReader {

	/** {@inheritDoc} */
	public function read( array $entry, array $form ): array {
		if ( ! class_exists( '\\Gravity_Flow_API' ) ) {
			return $this->unavailable( 'flow_api_unavailable' );
		}

		$form_id = $this->form_id( $entry, $form );
		if ( null === $form_id ) {
			return $this->unavailable( 'flow_context_unavailable' );
		}

		try {
			$api  = new \Gravity_Flow_API( $form_id );
			$step = $api->get_current_step( $entry );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->unavailable( 'flow_context_unavailable' );
		}

		if ( ! is_object( $step ) ) {
			return $this->unavailable( 'flow_step_unavailable' );
		}

		if ( ! method_exists( $step, 'get_assignees' ) ) {
			return $this->unavailable( 'flow_assignee_api_unavailable' );
		}

		$objects = $step->get_assignees();
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

			$type = $assignee->get_type();
			$id   = $assignee->get_id();
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
