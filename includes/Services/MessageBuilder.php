<?php
declare(strict_types=1);

namespace GFSMS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Builds outgoing SMS messages from templates, replacing placeholders
 * with entry, form, step, and assignee data.
 *
 * @since 3.0.0
 */
final class MessageBuilder {

	/**
	 * Build a step‑specific SMS message.
	 *
	 * @since 3.0.0
	 *
	 * @param string $template Message template with placeholders.
	 * @param array  $form     Gravity Forms form array.
	 * @param array  $entry    Gravity Forms entry array.
	 * @param object $step     Workflow step object.
	 * @return string Stripped and trimmed plain‑text message.
	 */
	public function build_step_message( string $template, array $form, array $entry, object $step ): string {
		$assignee_names = array();

		foreach ( $this->extract_assignees( $step ) as $a ) {
			if ( is_object( $a ) && method_exists( $a, 'get_type' ) && 'user_id' === $a->get_type() ) {
				$u = get_userdata( (int) $a->get_id() );
				if ( $u ) {
					$assignee_names[] = $u->display_name;
				}
			} elseif ( is_numeric( $a ) ) {
				$u = get_userdata( (int) $a );
				if ( $u ) {
					$assignee_names[] = $u->display_name;
				}
			} elseif ( is_string( $a ) ) {
				$assignee_names[] = $a;
			}
		}

		$message = strtr(
			$template,
			array(
				'{workflow_step_name}' => method_exists( $step, 'get_name' ) ? (string) $step->get_name() : '',
				'{assignee_name}'      => implode( ', ', array_unique( $assignee_names ) ),
				'{approval_comment}'   => $this->extract_comment( $step ),
				'{entry_id}'           => (string) ( $entry['id'] ?? '' ),
				'{form_id}'            => (string) ( $form['id'] ?? '' ),
			)
		);

		if ( class_exists( '\GFCommon' ) ) {
			try {
				$message = \GFCommon::replace_variables( $message, $form, $entry, false, false, false );
			} catch ( \Throwable $e ) {
				// Silently ignore merge failures.
			}
		}

		return trim( wp_strip_all_tags( $message ) );
	}

	/**
	 * Build a workflow‑level SMS message (no step context).
	 *
	 * @since 3.0.0
	 *
	 * @param string $template Message template.
	 * @param array  $form     Gravity Forms form array.
	 * @param array  $entry    Gravity Forms entry array.
	 * @param string $status   Final workflow status.
	 * @return string Stripped and trimmed plain‑text message.
	 */
	public function build_workflow_message( string $template, array $form, array $entry, string $status ): string {
		$message = strtr(
			$template,
			array(
				'{final_status}' => $status,
				'{entry_id}'     => (string) ( $entry['id'] ?? '' ),
				'{form_id}'      => (string) ( $form['id'] ?? '' ),
			)
		);

		if ( class_exists( '\GFCommon' ) ) {
			try {
				$message = \GFCommon::replace_variables( $message, $form, $entry, false, false, false );
			} catch ( \Throwable $e ) {
				// Silently ignore merge failures.
			}
		}

		return trim( wp_strip_all_tags( $message ) );
	}

	/**
	 * Extract assignee objects from a step.
	 *
	 * @since 3.0.0
	 *
	 * @param object $step The step object.
	 * @return array Assignee objects (or empty array).
	 */
	private function extract_assignees( object $step ): array {
		if ( method_exists( $step, 'get_assignees' ) ) {
			$assignees = $step->get_assignees();
			return is_array( $assignees ) ? $assignees : array();
		}
		return array();
	}

	/**
	 * Extract the most recent approval comment from a step.
	 *
	 * @since 3.0.0
	 *
	 * @param object $step The step object.
	 * @return string Sanitized, truncated comment (max 500 chars).
	 */
	private function extract_comment( object $step ): string {
		if ( method_exists( $step, 'get_notes' ) ) {
			try {
				$notes = $step->get_notes();
				if ( is_array( $notes ) && ! empty( $notes ) ) {
					$last = end( $notes );
					if ( is_object( $last ) ) {
						return $this->sanitize_comment( (string) ( $last->value ?? $last->note_text ?? $last->comment ?? '' ) );
					}
					if ( is_array( $last ) ) {
						return $this->sanitize_comment( (string) ( $last['value'] ?? $last['note_text'] ?? $last['comment'] ?? '' ) );
					}
				}
			} catch ( \Throwable $e ) {
				// Gracefully handle any notes retrieval errors.
			}
		}

		if ( method_exists( $step, 'get_meta' ) ) {
			try {
				$meta = $step->get_meta( 'comment' );
				if ( ! empty( $meta ) ) {
					return $this->sanitize_comment( (string) $meta );
				}
			} catch ( \Throwable $e ) {
				// Gracefully handle meta retrieval errors.
			}
		}

		return '';
	}

	/**
	 * Sanitize and truncate a comment string.
	 *
	 * @since 3.0.0
	 *
	 * @param string $comment Raw comment text.
	 * @return string Clean, truncated string.
	 */
	private function sanitize_comment( string $comment ): string {
		$comment = trim( wp_strip_all_tags( $comment ) );
		$length  = mb_strlen( $comment, 'UTF-8' );

		if ( $length > 500 ) {
			return mb_substr( $comment, 0, 497, 'UTF-8' ) . '...';
		}

		return $comment;
	}
}
