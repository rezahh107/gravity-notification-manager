<?php
/**
 * Immutable DTO representing a single SMS send event.
 *
 * Used by Dispatcher → Queue → Sender pipeline.
 * Serialisable via to_array() / from_array() for Action Scheduler.
 *
 * @package GFSMS\Domain
 */

declare( strict_types = 1 );

namespace GFSMS\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable DTO representing a single SMS send event.
 */
final class EventSnapshot {

	/**
	 * Unique event identifier (stable, idempotent).
	 *
	 * @var string
	 */
	public readonly string $event_id;

	/**
	 * Gravity Forms entry ID.
	 *
	 * @var int
	 */
	public readonly int $entry_id;

	/**
	 * Gravity Flow step ID.
	 *
	 * @var int
	 */
	public readonly int $step_id;

	/**
	 * Gravity Flow workflow ID.
	 *
	 * @var int
	 */
	public readonly int $workflow_id;

	/**
	 * Type of event (step, workflow, etc.).
	 *
	 * @var EventType
	 */
	public readonly EventType $event_type;

	/**
	 * Current state of the event.
	 *
	 * @var EventState
	 */
	public readonly EventState $state;

	/**
	 * Meta key for locking and tracking.
	 *
	 * @var string
	 */
	public readonly string $meta_key;

	/**
	 * Sender number.
	 *
	 * @var string
	 */
	public readonly string $from;

	/**
	 * List of recipient phone numbers.
	 *
	 * @var string[]
	 */
	public readonly array $recipients;

	/**
	 * SMS message content.
	 *
	 * @var string
	 */
	public readonly string $message;

	/**
	 * Sending method (webservice or pattern).
	 *
	 * @var string
	 */
	public readonly string $sending_method;

	/**
	 * Number of retry attempts.
	 *
	 * @var int
	 */
	public readonly int $retry_count;

	/**
	 * Unix timestamp when the event was created.
	 *
	 * @var int
	 */
	public readonly int $created_at;

	/**
	 * Delay in seconds before sending.
	 *
	 * @var int
	 */
	public readonly int $delay;

	/**
	 * Pattern code for IPPanel pattern sending method.
	 *
	 * @var string|null
	 */
	public readonly ?string $pattern_code;

	/**
	 * Variables map for IPPanel pattern.
	 *
	 * @var array<string,string>|null
	 */
	public readonly ?array $pattern_variables;

	/**
	 * Constructor (private – use named constructors).
	 *
	 * @param int         $entryId          Entry ID.
	 * @param int         $stepId           Step ID.
	 * @param int         $workflowId       Workflow ID.
	 * @param EventType   $eventType        Event type.
	 * @param EventState  $state            Event state.
	 * @param string      $metaKey          Meta key for locking.
	 * @param string      $from             Sender number.
	 * @param array       $recipients       Array of recipient phone numbers.
	 * @param string      $message          SMS message.
	 * @param string      $sendingMethod    Sending method (default 'webservice').
	 * @param int         $retryCount       Retry count (default 0).
	 * @param int         $createdAt        Created timestamp (default 0 = now).
	 * @param int         $delay            Delay in seconds (default 0).
	 * @param string|null $patternCode      Pattern code for pattern method.
	 * @param array|null  $patternVariables Pattern variables.
	 *
	 * @throws \InvalidArgumentException If recipients array is empty.
	 */
	private function __construct(
		int $entryId,
		int $stepId,
		int $workflowId,
		EventType $eventType,
		EventState $state,
		string $metaKey,
		string $from,
		array $recipients,
		string $message,
		string $sendingMethod = 'webservice',
		int $retryCount = 0,
		int $createdAt = 0,
		int $delay = 0,
		?string $patternCode = null,
		?array $patternVariables = null
	) {
		// Validate and sanitise recipients early.
		$recipients = array_values(
			array_filter(
				array_map( 'strval', $recipients ),
				static function ( string $v ): bool {
					return '' !== $v;
				}
			)
		);

		if ( array() === $recipients ) {
			throw new \InvalidArgumentException( 'EventSnapshot requires at least one recipient.' );
		}

		$this->entry_id          = $entryId;
		$this->step_id           = $stepId;
		$this->workflow_id       = $workflowId;
		$this->event_type        = $eventType;
		$this->state             = $state;
		$this->meta_key          = $metaKey;
		$this->from              = $from;
		$this->recipients        = $recipients;
		$this->message           = $message;
		$this->sending_method    = $sendingMethod;
		$this->retry_count       = $retryCount;
		$this->created_at        = ( 0 === $createdAt ) ? time() : $createdAt;
		$this->delay             = $delay;
		$this->pattern_code      = $patternCode;
		$this->pattern_variables = $patternVariables;

		// Stable, idempotent event ID for tracing / logging.
		$this->event_id = sha1(
			(string) $entryId . (string) $stepId . (string) $workflowId . $metaKey . (string) $this->created_at
		);
	}

	/**
	 * Named constructor – preferred way to create a snapshot.
	 *
	 * @param int        $entryId       Entry ID.
	 * @param int        $stepId        Step ID.
	 * @param int        $workflowId    Workflow ID.
	 * @param EventType  $eventType     Event type.
	 * @param EventState $state         Event state.
	 * @param string     $metaKey       Meta key.
	 * @param string     $from          Sender number.
	 * @param array      $recipients    Recipient phone numbers.
	 * @param string     $message       SMS message.
	 * @param string     $sendingMethod Sending method.
	 * @param int        $retryCount    Retry count.
	 * @param int        $delay         Delay in seconds.
	 * @param int|null   $createdAt     Created timestamp (null = now).
	 *
	 * @return self
	 */
	public static function create(
		int $entryId,
		int $stepId,
		int $workflowId,
		EventType $eventType,
		EventState $state,
		string $metaKey,
		string $from,
		array $recipients,
		string $message,
		string $sendingMethod = 'webservice',
		int $retryCount = 0,
		int $delay = 0,
		?int $createdAt = null
	): self {
		return new self(
			$entryId,
			$stepId,
			$workflowId,
			$eventType,
			$state,
			$metaKey,
			$from,
			$recipients,
			$message,
			$sendingMethod,
			$retryCount,
			$createdAt ?? time(),
			$delay
		);
	}

	/**
	 * Return a new instance with pattern data attached (immutable).
	 *
	 * @param string $code      Pattern code.
	 * @param array  $variables Pattern variables.
	 *
	 * @return self
	 */
	public function with_pattern( string $code, array $variables ): self {
		return new self(
			$this->entry_id,
			$this->step_id,
			$this->workflow_id,
			$this->event_type,
			$this->state,
			$this->meta_key,
			$this->from,
			$this->recipients,
			$this->message,
			$this->sending_method,
			$this->retry_count,
			$this->created_at,
			$this->delay,
			$code,
			$variables
		);
	}

	/**
	 * First recipient, or null if empty (shouldn't happen after validation).
	 *
	 * @return string|null
	 */
	public function primary_recipient(): ?string {
		return $this->recipients[0] ?? null;
	}

	/**
	 * Serialise for queue / action scheduler.
	 *
	 * @return array<string,scalar|array|null>
	 */
	public function to_array(): array {
		return array(
			'event_id'          => $this->event_id,
			'entry_id'          => $this->entry_id,
			'step_id'           => $this->step_id,
			'workflow_id'       => $this->workflow_id,
			'event_type'        => $this->event_type->value,
			'status'            => $this->state->value,
			'meta_key'          => $this->meta_key,
			'from'              => $this->from,
			'recipients'        => $this->recipients,
			'message'           => $this->message,
			'sending_method'    => $this->sending_method,
			'retry_count'       => $this->retry_count,
			'created_at'        => $this->created_at,
			'delay'             => $this->delay,
			'pattern_code'      => $this->pattern_code,
			'pattern_variables' => $this->pattern_variables,
		);
	}

	/**
	 * Reconstruct from queue payload.
	 *
	 * @param array $data Queue data array.
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			(int) ( $data['entry_id'] ?? 0 ),
			(int) ( $data['step_id'] ?? 0 ),
			(int) ( $data['workflow_id'] ?? 0 ),
			EventType::tryFrom( (string) ( $data['event_type'] ?? '' ) ) ?? EventType::STEP,
			EventState::tryFrom( (string) ( $data['status'] ?? '' ) ) ?? EventState::PROCESSING,
			(string) ( $data['meta_key'] ?? '' ),
			(string) ( $data['from'] ?? '' ),
			(array) ( $data['recipients'] ?? array() ),
			(string) ( $data['message'] ?? '' ),
			(string) ( $data['sending_method'] ?? 'webservice' ),
			(int) ( $data['retry_count'] ?? 0 ),
			(int) ( $data['created_at'] ?? time() ),
			(int) ( $data['delay'] ?? 0 ),
			array_key_exists( 'pattern_code', $data ) ? (string) $data['pattern_code'] : null,
			array_key_exists( 'pattern_variables', $data ) ? (array) $data['pattern_variables'] : null
		);
	}
}
