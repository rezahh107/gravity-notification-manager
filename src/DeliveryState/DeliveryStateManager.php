<?php
/**
 * WU-05 delivery-state schema and history management.
 *
 * @package GravityNotify
 */

namespace GravityNotify\DeliveryState;

use Closure;
use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\GravityForms\NotificationExecutionResult;

/**
 * Owns the lightweight versioned Entry Meta state document.
 */
final class DeliveryStateManager {

	/** Schema namespace identity. */
	public const SCHEMA_NAMESPACE = 'gravity_notify.delivery_state';

	/** Current state schema version. */
	public const SCHEMA_VERSION = 1;

	/** Confirmed logical delivery resolved. */
	public const FINAL_RESOLVED = 'RESOLVED';

	/** No confirmed logical delivery success exists. */
	public const FINAL_UNRESOLVED = 'UNRESOLVED';

	/** Ordinary native Feed execution. */
	public const EXECUTION_ORDINARY = 'ORDINARY';

	/** Explicit user-requested manual Retry execution. */
	public const EXECUTION_MANUAL_RETRY = 'MANUAL_RETRY';

	/** Ordinary repeat suppressed because delivery is already confirmed complete. */
	public const EXECUTION_DUPLICATE_SUPPRESSED = 'DUPLICATE_SUPPRESSED';

	/** Manual Retry is allowed for the target. */
	public const RETRY_ALLOWED = 'allowed';

	/** Manual Retry state is missing. */
	public const RETRY_STATE_MISSING = 'state_missing';

	/** Manual Retry state is malformed. */
	public const RETRY_STATE_MALFORMED = 'state_malformed';

	/** Target exists but does not currently require attention. */
	public const RETRY_NOT_REQUIRED = 'not_required';

	/**
	 * Persistence implementation.
	 *
	 * @var DeliveryStateStoreInterface
	 */
	private DeliveryStateStoreInterface $store;

	/**
	 * Deterministic timestamp provider.
	 *
	 * @var Closure():string
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param DeliveryStateStoreInterface $store State store.
	 * @param callable|null               $clock Optional timestamp provider for tests.
	 */
	public function __construct( DeliveryStateStoreInterface $store, ?callable $clock = null ) {
		$this->store = $store;
		$this->clock = null === $clock
			? static fn(): string => gmdate( 'c' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Whether ordinary sequential processing can be safely suppressed.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 * @return bool
	 */
	public function is_confirmed_complete( int $entry_id, int $feed_id ): bool {
		$target = $this->valid_target( $entry_id, $feed_id );
		if ( null === $target ) {
			return false;
		}

		return self::FINAL_RESOLVED === $target['final_status'] && false === $target['attention_required'];
	}

	/**
	 * Return the bounded manual Retry eligibility state.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 * @return string
	 */
	public function retry_eligibility( int $entry_id, int $feed_id ): string {
		$read = $this->store->read( $entry_id );
		if ( DeliveryStateReadResult::MISSING === $read->status() ) {
			return self::RETRY_STATE_MISSING;
		}

		if ( DeliveryStateReadResult::VALID !== $read->status() || ! $this->is_valid_root( $read->state(), $entry_id ) ) {
			return self::RETRY_STATE_MALFORMED;
		}

		$state = $read->state();
		$key   = $this->target_key( $feed_id );
		if ( ! isset( $state['notifications'][ $key ] ) ) {
			return self::RETRY_STATE_MISSING;
		}

		$target = $state['notifications'][ $key ];
		if ( ! is_array( $target ) || ! $this->is_valid_target( $target, $entry_id, $feed_id ) ) {
			return self::RETRY_STATE_MALFORMED;
		}

		return true === $target['attention_required'] ? self::RETRY_ALLOWED : self::RETRY_NOT_REQUIRED;
	}

	/**
	 * Record one execution while preserving all prior valid history.
	 *
	 * If prior state is malformed, ordinary processing recovers into a fresh valid
	 * document and records a bounded safe recovery reason. Manual Retry never calls
	 * this method unless retry_eligibility() first reports RETRY_ALLOWED.
	 *
	 * @param int                         $entry_id Entry ID.
	 * @param int                         $form_id Form ID.
	 * @param int                         $feed_id Feed ID.
	 * @param string                      $feed_name Logical Feed name.
	 * @param string                      $channel Current logical channel.
	 * @param NotificationExecutionResult $result Execution facts from WU-04.
	 * @param string                      $execution_type Execution type.
	 * @return bool
	 */
	public function record_execution(
		int $entry_id,
		int $form_id,
		int $feed_id,
		string $feed_name,
		string $channel,
		NotificationExecutionResult $result,
		string $execution_type = self::EXECUTION_ORDINARY
	): bool {
		if ( $entry_id <= 0 || $form_id <= 0 || $feed_id <= 0 ) {
			return false;
		}

		$read      = $this->store->read( $entry_id );
		$recovered = false;
		if ( DeliveryStateReadResult::VALID === $read->status() && $this->is_valid_root( $read->state(), $entry_id ) ) {
			$state = $read->state();
		} else {
			$state     = $this->new_root( $entry_id );
			$recovered = DeliveryStateReadResult::MALFORMED === $read->status() || DeliveryStateReadResult::VALID === $read->status();
		}

		$key    = $this->target_key( $feed_id );
		$target = $state['notifications'][ $key ] ?? null;
		if ( ! is_array( $target ) || ! $this->is_valid_target( $target, $entry_id, $feed_id ) ) {
			if ( null !== $target ) {
				$recovered = true;
			}
			$target = $this->new_target( $entry_id, $form_id, $feed_id, $feed_name, $channel );
		}

		$sequence  = $this->next_execution_sequence( $target['executions'] );
		$timestamp = ( $this->clock )();
		$skips     = $this->safe_skips( $result->skips() );
		if ( $recovered ) {
			$skips[] = array(
				'subject' => 'delivery_state',
				'reason'  => 'prior_state_malformed',
			);
		}

		$resolved = $result->delivery_succeeded();
		$final    = $resolved ? self::FINAL_RESOLVED : self::FINAL_UNRESOLVED;

		$target['form_id']                 = $form_id;
		$target['feed_name']               = $this->safe_string( $feed_name );
		$target['channel']                 = $this->safe_string( $channel );
		$target['final_status']            = $final;
		$target['attention_required']      = ! $resolved;
		$target['last_execution_sequence'] = $sequence;
		$target['executions'][]            = array(
			'execution_sequence' => $sequence,
			'type'               => $execution_type,
			'timestamp'          => $timestamp,
			'channel'            => $this->safe_string( $channel ),
			'attempts'           => $this->attempts( $result->attempts(), $sequence, $timestamp ),
			'skips'              => $skips,
			'delivery_succeeded' => $resolved,
			'final_status'       => $final,
			'attention_required' => ! $resolved,
		);

		if ( self::EXECUTION_MANUAL_RETRY === $execution_type ) {
			$target['retry_history'][] = array(
				'execution_sequence' => $sequence,
				'timestamp'          => $timestamp,
				'resolved'           => $resolved,
			);
			if ( $resolved ) {
				$target['resolved_by_retry'] = true;
			}
		}

		$state['notifications'][ $key ] = $target;

		return $this->store->write( $entry_id, $form_id, $state );
	}

	/**
	 * Read one valid target, or null for missing/malformed state.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id Feed ID.
	 * @return array<string, mixed>|null
	 */
	public function target_state( int $entry_id, int $feed_id ): ?array {
		return $this->valid_target( $entry_id, $feed_id );
	}

	/**
	 * Build one root state document.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array<string, mixed>
	 */
	private function new_root( int $entry_id ): array {
		return array(
			'namespace'      => self::SCHEMA_NAMESPACE,
			'schema_version' => self::SCHEMA_VERSION,
			'entry_id'       => $entry_id,
			'notifications'  => array(),
		);
	}

	/**
	 * Build one logical Feed target state.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id Form ID.
	 * @param int    $feed_id Feed ID.
	 * @param string $feed_name Feed name.
	 * @param string $channel Channel.
	 * @return array<string, mixed>
	 */
	private function new_target( int $entry_id, int $form_id, int $feed_id, string $feed_name, string $channel ): array {
		return array(
			'entry_id'                => $entry_id,
			'form_id'                 => $form_id,
			'feed_id'                 => $feed_id,
			'feed_name'               => $this->safe_string( $feed_name ),
			'channel'                 => $this->safe_string( $channel ),
			'final_status'            => self::FINAL_UNRESOLVED,
			'attention_required'      => true,
			'last_execution_sequence' => 0,
			'resolved_by_retry'       => false,
			'executions'              => array(),
			'retry_history'           => array(),
		);
	}

	/**
	 * Find one valid persisted target.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id Feed ID.
	 * @return array<string, mixed>|null
	 */
	private function valid_target( int $entry_id, int $feed_id ): ?array {
		$read = $this->store->read( $entry_id );
		if ( DeliveryStateReadResult::VALID !== $read->status() || ! $this->is_valid_root( $read->state(), $entry_id ) ) {
			return null;
		}

		$state  = $read->state();
		$key    = $this->target_key( $feed_id );
		$target = $state['notifications'][ $key ] ?? null;
		if ( ! is_array( $target ) || ! $this->is_valid_target( $target, $entry_id, $feed_id ) ) {
			return null;
		}

		return $target;
	}

	/**
	 * Validate only the bounded root schema required for safe reads.
	 *
	 * @param array<string, mixed> $state State document.
	 * @param int                  $entry_id Entry ID.
	 * @return bool
	 */
	private function is_valid_root( array $state, int $entry_id ): bool {
		return self::SCHEMA_NAMESPACE === ( $state['namespace'] ?? null )
			&& self::SCHEMA_VERSION === ( $state['schema_version'] ?? null )
			&& ( $state['entry_id'] ?? null ) === $entry_id
			&& isset( $state['notifications'] )
			&& is_array( $state['notifications'] );
	}

	/**
	 * Validate the complete bounded target contract trusted by suppression/Retry decisions.
	 *
	 * @param array<string, mixed> $target Target state.
	 * @param int                  $entry_id Entry ID.
	 * @param int                  $feed_id Feed ID.
	 * @return bool
	 */
	private function is_valid_target( array $target, int $entry_id, int $feed_id ): bool {
		$required_fields = array(
			'entry_id',
			'form_id',
			'feed_id',
			'feed_name',
			'channel',
			'final_status',
			'attention_required',
			'last_execution_sequence',
			'resolved_by_retry',
			'executions',
			'retry_history',
		);

		foreach ( $required_fields as $field ) {
			if ( ! array_key_exists( $field, $target ) ) {
				return false;
			}
		}

		if ( 0 >= $entry_id || 0 >= $feed_id || $entry_id !== $target['entry_id'] || $feed_id !== $target['feed_id'] ) {
			return false;
		}

		if ( ! is_int( $target['form_id'] ) || 0 >= $target['form_id'] ) {
			return false;
		}

		if ( ! $this->is_bounded_string( $target['feed_name'] ) || ! $this->is_bounded_string( $target['channel'] ) ) {
			return false;
		}

		if ( ! is_bool( $target['attention_required'] ) || ! is_bool( $target['resolved_by_retry'] ) ) {
			return false;
		}

		if ( ! is_int( $target['last_execution_sequence'] ) || 1 > $target['last_execution_sequence'] ) {
			return false;
		}

		if ( ! is_array( $target['executions'] ) || ! is_array( $target['retry_history'] ) ) {
			return false;
		}

		$final_status = $target['final_status'];
		if ( ! $this->is_valid_final_state( $final_status, $target['attention_required'] ) ) {
			return false;
		}

		$history = $this->validated_execution_history( $target['executions'] );
		if ( null === $history ) {
			return false;
		}

		$last_execution = $history['last_execution'];
		if ( $target['last_execution_sequence'] !== $last_execution['execution_sequence']
			|| $final_status !== $last_execution['final_status']
			|| $target['attention_required'] !== $last_execution['attention_required'] ) {
			return false;
		}

		return $this->is_valid_retry_history(
			$target['retry_history'],
			$history['executions_by_sequence'],
			$history['manual_retry_sequences'],
			$target['resolved_by_retry']
		);
	}

	/**
	 * Validate retained execution history and return relationships needed by Retry validation.
	 *
	 * @param array<int, mixed> $executions Retained executions.
	 * @return array{last_execution:array<string,mixed>,executions_by_sequence:array<int,array<string,mixed>>,manual_retry_sequences:array<int,int>}|null
	 */
	private function validated_execution_history( array $executions ): ?array {
		if ( array() === $executions || ! array_is_list( $executions ) ) {
			return null;
		}

		$previous_sequence      = 0;
		$executions_by_sequence = array();
		$manual_retry_sequences = array();
		$last_execution         = null;

		foreach ( $executions as $execution ) {
			if ( ! is_array( $execution ) || ! $this->is_valid_execution( $execution, $previous_sequence ) ) {
				return null;
			}

			$sequence                            = $execution['execution_sequence'];
			$executions_by_sequence[ $sequence ] = $execution;
			if ( self::EXECUTION_MANUAL_RETRY === $execution['type'] ) {
				$manual_retry_sequences[] = $sequence;
			}

			$previous_sequence = $sequence;
			$last_execution    = $execution;
		}

		if ( null === $last_execution ) {
			return null;
		}

		return array(
			'last_execution'         => $last_execution,
			'executions_by_sequence' => $executions_by_sequence,
			'manual_retry_sequences' => $manual_retry_sequences,
		);
	}

	/**
	 * Validate one retained execution and its nested attempt/skip records.
	 *
	 * @param array<string, mixed> $execution Execution record.
	 * @param int                  $previous_sequence Previous retained sequence.
	 * @return bool
	 */
	private function is_valid_execution( array $execution, int $previous_sequence ): bool {
		$required_fields = array(
			'execution_sequence',
			'type',
			'timestamp',
			'channel',
			'attempts',
			'skips',
			'delivery_succeeded',
			'final_status',
			'attention_required',
		);

		if ( ! $this->has_exact_keys( $execution, $required_fields ) ) {
			return false;
		}

		$sequence = $execution['execution_sequence'];
		if ( ! is_int( $sequence ) || 1 > $sequence || $previous_sequence >= $sequence ) {
			return false;
		}

		if ( ! in_array(
			$execution['type'],
			array( self::EXECUTION_ORDINARY, self::EXECUTION_MANUAL_RETRY, self::EXECUTION_DUPLICATE_SUPPRESSED ),
			true
		) ) {
			return false;
		}

		if ( ! $this->is_bounded_string( $execution['timestamp'] ) || ! $this->is_bounded_string( $execution['channel'] ) ) {
			return false;
		}

		if ( ! is_array( $execution['attempts'] ) || ! is_array( $execution['skips'] ) || ! is_bool( $execution['delivery_succeeded'] ) || ! is_bool( $execution['attention_required'] ) ) {
			return false;
		}

		if ( ! $this->is_valid_final_state( $execution['final_status'], $execution['attention_required'] ) ) {
			return false;
		}

		if ( true === $execution['delivery_succeeded'] && self::FINAL_RESOLVED !== $execution['final_status'] ) {
			return false;
		}

		if ( false === $execution['delivery_succeeded'] && self::FINAL_UNRESOLVED !== $execution['final_status'] ) {
			return false;
		}

		return $this->are_valid_attempts( $execution['attempts'], $sequence ) && $this->are_valid_skips( $execution['skips'] );
	}

	/**
	 * Validate one execution's retained transport attempts.
	 *
	 * @param array<int, mixed> $attempts Attempts.
	 * @param int               $execution_sequence Parent execution sequence.
	 * @return bool
	 */
	private function are_valid_attempts( array $attempts, int $execution_sequence ): bool {
		if ( ! array_is_list( $attempts ) ) {
			return false;
		}

		$previous_sequence = 0;
		$required_fields   = array(
			'execution_sequence',
			'attempt_sequence',
			'timestamp',
			'status',
			'channel',
			'provider',
			'capability',
			'provider_references',
		);

		foreach ( $attempts as $attempt ) {
			if ( ! is_array( $attempt ) || ! $this->has_exact_keys( $attempt, $required_fields ) ) {
				return false;
			}

			if ( $execution_sequence !== $attempt['execution_sequence'] || ! is_int( $attempt['attempt_sequence'] ) || 1 > $attempt['attempt_sequence'] || $previous_sequence >= $attempt['attempt_sequence'] ) {
				return false;
			}

			if ( ! is_string( $attempt['status'] ) || ! AttemptStatus::is_valid( $attempt['status'] ) ) {
				return false;
			}

			if ( ! $this->is_bounded_string( $attempt['timestamp'] ) || ! $this->is_bounded_string( $attempt['channel'] ) ) {
				return false;
			}

			if ( ! $this->is_nullable_bounded_string( $attempt['provider'] ) || ! $this->is_nullable_bounded_string( $attempt['capability'] ) ) {
				return false;
			}

			if ( ! is_array( $attempt['provider_references'] ) || ! array_is_list( $attempt['provider_references'] ) ) {
				return false;
			}

			foreach ( $attempt['provider_references'] as $reference ) {
				if ( ! $this->is_bounded_string( $reference ) ) {
					return false;
				}
			}

			$previous_sequence = $attempt['attempt_sequence'];
		}

		return true;
	}

	/**
	 * Validate one execution's retained safe skip records.
	 *
	 * @param array<int, mixed> $skips Skips.
	 * @return bool
	 */
	private function are_valid_skips( array $skips ): bool {
		if ( ! array_is_list( $skips ) ) {
			return false;
		}

		foreach ( $skips as $skip ) {
			if ( ! is_array( $skip ) || ! $this->has_exact_keys( $skip, array( 'subject', 'reason' ) ) ) {
				return false;
			}

			if ( ! $this->is_bounded_string( $skip['subject'] ) || ! $this->is_bounded_string( $skip['reason'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate retained manual-Retry history against retained execution truth.
	 *
	 * @param array<int, mixed>                $retry_history Retry records.
	 * @param array<int, array<string, mixed>> $executions_by_sequence Executions indexed by sequence.
	 * @param array<int, int>                  $manual_retry_sequences Manual-Retry execution sequences.
	 * @param bool                             $resolved_by_retry Target Retry-resolution flag.
	 * @return bool
	 */
	private function is_valid_retry_history( array $retry_history, array $executions_by_sequence, array $manual_retry_sequences, bool $resolved_by_retry ): bool {
		if ( ! array_is_list( $retry_history ) ) {
			return false;
		}

		$previous_sequence  = 0;
		$seen_sequences     = array();
		$has_resolved_retry = false;

		foreach ( $retry_history as $retry ) {
			if ( ! is_array( $retry ) || ! $this->has_exact_keys( $retry, array( 'execution_sequence', 'timestamp', 'resolved' ) ) ) {
				return false;
			}

			$sequence = $retry['execution_sequence'];
			if ( ! is_int( $sequence ) || 1 > $sequence || $previous_sequence >= $sequence || isset( $seen_sequences[ $sequence ] ) ) {
				return false;
			}

			if ( ! $this->is_bounded_string( $retry['timestamp'] ) || ! is_bool( $retry['resolved'] ) ) {
				return false;
			}

			$execution = $executions_by_sequence[ $sequence ] ?? null;
			if ( ! is_array( $execution ) || self::EXECUTION_MANUAL_RETRY !== $execution['type'] || $retry['resolved'] !== $execution['delivery_succeeded'] ) {
				return false;
			}

			$seen_sequences[ $sequence ] = true;
			$previous_sequence           = $sequence;
			$has_resolved_retry          = $has_resolved_retry || true === $retry['resolved'];
		}

		if ( count( $seen_sequences ) !== count( $manual_retry_sequences ) ) {
			return false;
		}

		foreach ( $manual_retry_sequences as $sequence ) {
			if ( ! isset( $seen_sequences[ $sequence ] ) ) {
				return false;
			}
		}

		return $resolved_by_retry === $has_resolved_retry;
	}

	/**
	 * Validate final status and Attention Required coherence.
	 *
	 * @param mixed $final_status Final status.
	 * @param mixed $attention_required Attention state.
	 * @return bool
	 */
	private function is_valid_final_state( $final_status, $attention_required ): bool {
		if ( ! is_bool( $attention_required ) || ! in_array( $final_status, array( self::FINAL_RESOLVED, self::FINAL_UNRESOLVED ), true ) ) {
			return false;
		}

		return ( self::FINAL_RESOLVED === $final_status && false === $attention_required )
			|| ( self::FINAL_UNRESOLVED === $final_status && true === $attention_required );
	}

	/**
	 * Whether a retained record contains exactly the existing persisted fields.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param array<int, string>   $required_fields Required fields.
	 * @return bool
	 */
	private function has_exact_keys( array $record, array $required_fields ): bool {
		if ( count( $record ) !== count( $required_fields ) ) {
			return false;
		}

		foreach ( $required_fields as $field ) {
			if ( ! array_key_exists( $field, $record ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a persisted safe string matches the current bounded writer representation.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_bounded_string( $value ): bool {
		return is_string( $value ) && 255 >= strlen( $value );
	}

	/**
	 * Whether a persisted optional string matches the current bounded writer representation.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_nullable_bounded_string( $value ): bool {
		return null === $value || $this->is_bounded_string( $value );
	}

	/**
	 * Convert transport attempts to bounded persistence-ready arrays.
	 *
	 * Provider references are retained when safe scalar identifiers exist. Provider
	 * diagnostics/raw error data are deliberately not persisted in Entry Meta.
	 *
	 * @param array<int, AttemptResult> $attempts Execution attempts.
	 * @param int                       $execution Execution sequence.
	 * @param string                    $timestamp Timestamp.
	 * @return array<int, array<string, mixed>>
	 */
	private function attempts( array $attempts, int $execution, string $timestamp ): array {
		$stored = array();
		foreach ( array_values( $attempts ) as $index => $attempt ) {
			if ( ! $attempt instanceof AttemptResult ) {
				continue;
			}

			$references = array();
			foreach ( $attempt->provider_references() as $reference ) {
				if ( is_scalar( $reference ) ) {
					$references[] = $this->safe_string( (string) $reference );
				}
			}

			$stored[] = array(
				'execution_sequence'  => $execution,
				'attempt_sequence'    => $index + 1,
				'timestamp'           => $timestamp,
				'status'              => $attempt->status(),
				'channel'             => $this->safe_string( $attempt->channel() ),
				'provider'            => null === $attempt->provider_id() ? null : $this->safe_string( $attempt->provider_id() ),
				'capability'          => null === $attempt->capability() ? null : $this->safe_string( $attempt->capability() ),
				'provider_references' => $references,
			);
		}

		return $stored;
	}

	/**
	 * Normalize already-safe execution skips to scalar bounded values.
	 *
	 * @param array<int, array{subject:string,reason:string}> $skips Safe skips.
	 * @return array<int, array{subject:string,reason:string}>
	 */
	private function safe_skips( array $skips ): array {
		$stored = array();
		foreach ( $skips as $skip ) {
			if ( ! is_array( $skip ) ) {
				continue;
			}

			$stored[] = array(
				'subject' => $this->safe_string( (string) ( $skip['subject'] ?? '' ) ),
				'reason'  => $this->safe_string( (string) ( $skip['reason'] ?? '' ) ),
			);
		}

		return $stored;
	}

	/**
	 * Return the next sequence without trusting contiguous prior arrays.
	 *
	 * @param array<int, mixed> $executions Prior executions.
	 * @return int
	 */
	private function next_execution_sequence( array $executions ): int {
		$maximum = 0;
		foreach ( $executions as $execution ) {
			if ( is_array( $execution ) && isset( $execution['execution_sequence'] ) && is_int( $execution['execution_sequence'] ) ) {
				$maximum = max( $maximum, $execution['execution_sequence'] );
			}
		}

		return $maximum + 1;
	}

	/**
	 * Logical Feed target key.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	private function target_key( int $feed_id ): string {
		return 'feed:' . $feed_id;
	}

	/**
	 * Bound safe scalar identifiers without storing message/recipient data.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function safe_string( string $value ): string {
		return substr( trim( $value ), 0, 255 );
	}
}
