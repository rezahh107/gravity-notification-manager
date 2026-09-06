<?php
/**
 * Generic greenfield recipient resolver.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient;

use GravityNotify\GravityForms\FeedRuleSchema;

/**
 * Resolves WU-01 recipient-source metadata into channel destinations.
 */
final class RecipientResolver {

	/** Closed SMS user-meta key. */
	public const SMS_META_KEY = 'wudm_notification_mobile';

	/** Closed Bale user-meta key. */
	public const BALE_META_KEY = 'wudm_bale_chat_id';

	/** @var EntryFieldReader */
	private EntryFieldReader $entry_fields;

	/** @var UserDirectory */
	private UserDirectory $users;

	/** @var FlowAssigneeReader */
	private FlowAssigneeReader $flow_assignees;

	/**
	 * Constructor.
	 *
	 * @param EntryFieldReader   $entry_fields   Gravity Forms Entry reader.
	 * @param UserDirectory      $users          WordPress user/contact directory.
	 * @param FlowAssigneeReader $flow_assignees Gravity Flow assignee reader.
	 */
	public function __construct( EntryFieldReader $entry_fields, UserDirectory $users, FlowAssigneeReader $flow_assignees ) {
		$this->entry_fields   = $entry_fields;
		$this->users          = $users;
		$this->flow_assignees = $flow_assignees;
	}

	/**
	 * Resolve one normalized WU-01 feed metadata record.
	 *
	 * @param array $rule  Normalized WU-01 feed metadata.
	 * @param array $entry Current Gravity Forms Entry object.
	 * @param array $form  Current Gravity Forms Form object.
	 * @return ResolutionResult
	 */
	public function resolve( array $rule, array $entry = array(), array $form = array() ): ResolutionResult {
		$channel     = $this->scalar_string( $rule['channel'] ?? '' );
		$source_type = $this->scalar_string( $rule['recipient_source_type'] ?? '' );
		$source      = trim( $this->scalar_string( $rule['recipient_source_value'] ?? '' ) );

		if ( ! in_array( $channel, FeedRuleSchema::channels(), true ) ) {
			return $this->result_with_skip( $channel, $source_type, 'configured_source', 'unsupported_channel' );
		}

		switch ( $source_type ) {
			case FeedRuleSchema::RECIPIENT_ENTRY_FIELD:
				return $this->resolve_entry_field( $channel, $source_type, $source, $entry, $form );
			case FeedRuleSchema::RECIPIENT_FIXED:
				return $this->resolve_fixed( $channel, $source_type, $source );
			case FeedRuleSchema::RECIPIENT_USER:
				return $this->resolve_user( $channel, $source_type, $source );
			case FeedRuleSchema::RECIPIENT_ROLE:
				return $this->resolve_role( $channel, $source_type, $source );
			case FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE:
				return $this->resolve_flow_assignees( $channel, $source_type, $entry, $form );
			default:
				return $this->result_with_skip( $channel, $source_type, 'configured_source', 'unsupported_source_type' );
		}
	}

	/**
	 * Resolve one Gravity Forms Entry field/input source.
	 *
	 * @param string $channel     Requested channel.
	 * @param string $source_type Source type.
	 * @param string $selector    Field/input ID selector.
	 * @param array  $entry       Entry object.
	 * @param array  $form        Form object.
	 * @return ResolutionResult
	 */
	private function resolve_entry_field( string $channel, string $source_type, string $selector, array $entry, array $form ): ResolutionResult {
		if ( 1 !== preg_match( '/^[1-9][0-9]*(?:\.[1-9][0-9]*)?$/', $selector ) ) {
			return $this->result_with_skip( $channel, $source_type, 'configured_source', 'invalid_entry_field_selector' );
		}

		$value = $this->entry_fields->read( $selector, $entry, $form );
		if ( ! is_scalar( $value ) ) {
			return $this->result_with_skip( $channel, $source_type, 'entry_field', 'non_scalar_entry_field_value' );
		}

		$destination = trim( (string) $value );
		if ( '' === $destination ) {
			return $this->result_with_skip( $channel, $source_type, 'entry_field', 'missing_destination' );
		}

		return new ResolutionResult( $channel, $source_type, array( $destination ), array() );
	}

	/**
	 * Resolve one fixed channel target.
	 *
	 * @param string $channel     Requested channel.
	 * @param string $source_type Source type.
	 * @param string $source      Configured fixed target.
	 * @return ResolutionResult
	 */
	private function resolve_fixed( string $channel, string $source_type, string $source ): ResolutionResult {
		if ( '' === $source ) {
			return $this->result_with_skip( $channel, $source_type, 'configured_source', 'missing_destination' );
		}

		return new ResolutionResult( $channel, $source_type, array( $source ), array() );
	}

	/**
	 * Resolve one configured WordPress user.
	 *
	 * @param string $channel     Requested channel.
	 * @param string $source_type Source type.
	 * @param string $source      Configured user selector.
	 * @return ResolutionResult
	 */
	private function resolve_user( string $channel, string $source_type, string $source ): ResolutionResult {
		if ( '' === $source ) {
			return $this->result_with_skip( $channel, $source_type, 'configured_source', 'invalid_user_selector' );
		}

		$user_id = $this->users->find_user_id( $source );
		if ( null === $user_id ) {
			return $this->result_with_skip( $channel, $source_type, 'configured_user', 'user_not_found' );
		}

		return $this->resolve_user_ids( $channel, $source_type, array( $user_id ), 'configured_user' );
	}

	/**
	 * Resolve all matching WordPress role users.
	 *
	 * @param string $channel     Requested channel.
	 * @param string $source_type Source type.
	 * @param string $role        Role slug.
	 * @return ResolutionResult
	 */
	private function resolve_role( string $channel, string $source_type, string $role ): ResolutionResult {
		if ( '' === $role ) {
			return $this->result_with_skip( $channel, $source_type, 'configured_source', 'invalid_role' );
		}

		$user_ids = $this->normalize_user_ids( $this->users->find_user_ids_by_role( $role ) );
		if ( array() === $user_ids ) {
			return $this->result_with_skip( $channel, $source_type, 'configured_role', 'role_has_no_users' );
		}

		return $this->resolve_user_ids( $channel, $source_type, $user_ids, 'role_member' );
	}

	/**
	 * Resolve current Gravity Flow step assignees through documented identities.
	 *
	 * @param string $channel     Requested channel.
	 * @param string $source_type Source type.
	 * @param array  $entry       Entry object.
	 * @param array  $form        Form object.
	 * @return ResolutionResult
	 */
	private function resolve_flow_assignees( string $channel, string $source_type, array $entry, array $form ): ResolutionResult {
		$collection = $this->flow_assignees->read( $entry, $form );
		$available  = true === ( $collection['available'] ?? false );
		$reason     = $this->scalar_string( $collection['reason'] ?? 'flow_context_unavailable' );
		$assignees  = is_array( $collection['assignees'] ?? null ) ? $collection['assignees'] : array();

		if ( ! $available ) {
			return $this->result_with_skip( $channel, $source_type, 'flow_context', $this->safe_flow_reason( $reason ) );
		}

		if ( array() === $assignees ) {
			return $this->result_with_skip( $channel, $source_type, 'flow_assignee', 'flow_assignee_empty' );
		}

		$destinations = array();
		$skips        = array();

		foreach ( $assignees as $assignee ) {
			if ( ! is_array( $assignee ) ) {
				$skips[] = $this->skip( 'flow_assignee', 'unsupported_assignee_type' );
				continue;
			}

			$type = $this->scalar_string( $assignee['type'] ?? '' );
			$id   = trim( $this->scalar_string( $assignee['id'] ?? '' ) );

			if ( 'user_id' === $type ) {
				$user_id = ctype_digit( $id ) && 0 < (int) $id ? (int) $id : null;
				if ( null === $user_id ) {
					$skips[] = $this->skip( 'flow_assignee', 'assignee_user_not_found' );
					continue;
				}
				$this->append_user_contact( $channel, $user_id, 'flow_user', $destinations, $skips );
				continue;
			}

			if ( 'role' === $type ) {
				$user_ids = '' === $id ? array() : $this->normalize_user_ids( $this->users->find_user_ids_by_role( $id ) );
				if ( array() === $user_ids ) {
					$skips[] = $this->skip( 'flow_role', 'role_has_no_users' );
					continue;
				}
				foreach ( $user_ids as $user_id ) {
					$this->append_user_contact( $channel, $user_id, 'flow_role_member', $destinations, $skips );
				}
				continue;
			}

			if ( 'email' === $type ) {
				$user_id = '' === $id ? null : $this->users->find_user_id( $id );
				if ( null === $user_id ) {
					$skips[] = $this->skip( 'flow_email_assignee', 'assignee_user_not_found' );
					continue;
				}
				$this->append_user_contact( $channel, $user_id, 'flow_email_user', $destinations, $skips );
				continue;
			}

			$skips[] = $this->skip( 'flow_assignee', 'unsupported_assignee_type' );
		}

		return new ResolutionResult( $channel, $source_type, $destinations, $skips );
	}

	/**
	 * Resolve channel contacts for a deterministic set of user IDs.
	 *
	 * @param string          $channel      Requested channel.
	 * @param string          $source_type  Source type.
	 * @param array<int, int> $user_ids     WordPress user IDs.
	 * @param string          $subject_base Safe skip subject prefix.
	 * @return ResolutionResult
	 */
	private function resolve_user_ids( string $channel, string $source_type, array $user_ids, string $subject_base ): ResolutionResult {
		$destinations = array();
		$skips        = array();

		foreach ( $this->normalize_user_ids( $user_ids ) as $user_id ) {
			$this->append_user_contact( $channel, $user_id, $subject_base, $destinations, $skips );
		}

		return new ResolutionResult( $channel, $source_type, $destinations, $skips );
	}

	/**
	 * Append one user's channel contact or a safe missing-contact skip.
	 *
	 * @param string                                                $channel      Requested channel.
	 * @param int                                                   $user_id      WordPress user ID.
	 * @param string                                                $subject_base Skip subject prefix.
	 * @param array<int, string>                                    $destinations Destination accumulator.
	 * @param array<int, array{subject:string,reason:string}>        $skips        Skip accumulator.
	 * @return void
	 */
	private function append_user_contact( string $channel, int $user_id, string $subject_base, array &$destinations, array &$skips ): void {
		$meta_key = FeedRuleSchema::CHANNEL_SMS === $channel ? self::SMS_META_KEY : self::BALE_META_KEY;
		$value    = $this->users->get_contact( $user_id, $meta_key );

		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			$skips[] = $this->skip( $subject_base . ':' . $user_id, 'missing_contact' );
			return;
		}

		$destinations[] = trim( (string) $value );
	}

	/**
	 * Normalize user IDs for deterministic role/assignee behavior.
	 *
	 * @param array $user_ids Candidate IDs.
	 * @return array<int, int>
	 */
	private function normalize_user_ids( array $user_ids ): array {
		$normalized = array();
		foreach ( $user_ids as $user_id ) {
			if ( is_int( $user_id ) || ctype_digit( (string) $user_id ) ) {
				$user_id = (int) $user_id;
				if ( 0 < $user_id ) {
					$normalized[] = $user_id;
				}
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_NUMERIC );
		return $normalized;
	}

	/**
	 * Restrict native-flow unavailability to safe classifications.
	 *
	 * @param string $reason Candidate reason.
	 * @return string
	 */
	private function safe_flow_reason( string $reason ): string {
		$allowed = array(
			'flow_api_unavailable',
			'flow_context_unavailable',
			'flow_step_unavailable',
			'flow_assignee_api_unavailable',
		);

		return in_array( $reason, $allowed, true ) ? $reason : 'flow_context_unavailable';
	}

	/**
	 * Build one result containing a single skip.
	 *
	 * @param string $channel     Requested channel.
	 * @param string $source_type Source type.
	 * @param string $subject     Safe skip subject.
	 * @param string $reason      Safe skip reason.
	 * @return ResolutionResult
	 */
	private function result_with_skip( string $channel, string $source_type, string $subject, string $reason ): ResolutionResult {
		return new ResolutionResult( $channel, $source_type, array(), array( $this->skip( $subject, $reason ) ) );
	}

	/**
	 * Build one safe skip record.
	 *
	 * @param string $subject Safe subject.
	 * @param string $reason  Safe reason classification.
	 * @return array{subject:string,reason:string}
	 */
	private function skip( string $subject, string $reason ): array {
		return array(
			'subject' => $subject,
			'reason'  => $reason,
		);
	}

	/**
	 * Convert only scalar values to strings.
	 *
	 * @param mixed $value Candidate scalar.
	 * @return string
	 */
	private function scalar_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
