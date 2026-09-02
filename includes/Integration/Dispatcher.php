<?php
/**
 * Event dispatcher for SMS notifications.
 *
 * Handles workflow and step events, builds messages, resolves recipients,
 * and queues SMS for sending.
 *
 * @package GFSMS\Integration
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

use GFSMS\Alert\Webhook_Alert;
use GFSMS\Domain\EventSnapshot;
use GFSMS\Domain\EventState;
use GFSMS\Domain\EventType;
use GFSMS\Domain\Settings;
use GFSMS\Infrastructure\ProviderFactory;
use GFSMS\Logging\Logger;
use GFSMS\Queue\Event_Queue;
use GFSMS\Services\LockManager;
use GFSMS\Services\MessageBuilder;
use GFSMS\Services\PatternVariableBuilder;
use GFSMS\Services\PhoneNumberNormalizer;
use GFSMS\Services\RecipientResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dispatcher
 *
 * Orchestrates SMS sending based on Gravity Flow and Gravity Forms events.
 *
 * @since 3.0.0
 */
final class Dispatcher {

	/**
	 * Singleton instance.
	 *
	 * @since 3.0.0
	 * @var self|null
	 */
	private static ?self $_instance = null;

	/**
	 * Step objects cache.
	 *
	 * @since 3.0.0
	 * @var array<int, array<int, object>>
	 */
	private array $step_cache = array();

	/**
	 * Entry data cache.
	 *
	 * @since 3.0.0
	 * @var array<int, array|false>
	 */
	private array $entry_cache = array();

	/**
	 * Form data cache.
	 *
	 * @since 3.0.0
	 * @var array<int, array|false>
	 */
	private array $form_cache = array();

	/**
	 * Constructor.
	 *
	 * @since 3.0.0
	 *
	 * @param Logger                 $logger                    Logger instance.
	 * @param ProviderFactory        $provider_factory          Provider factory.
	 * @param RecipientResolver      $recipient_resolver        Recipient resolver.
	 * @param MessageBuilder         $message_builder           Message builder.
	 * @param PatternVariableBuilder $pattern_variable_builder Pattern variable builder.
	 * @param LockManager            $lock_manager              Lock manager.
	 * @param PhoneNumberNormalizer  $phone_normalizer          Phone number normalizer.
	 * @param Settings               $settings                  Plugin settings.
	 */
	private function __construct(
		private readonly Logger $logger,
		private readonly ProviderFactory $provider_factory,
		private readonly RecipientResolver $recipient_resolver,
		private readonly MessageBuilder $message_builder,
		private readonly PatternVariableBuilder $pattern_variable_builder,
		private readonly LockManager $lock_manager,
		private readonly PhoneNumberNormalizer $phone_normalizer,
		private readonly Settings $settings,
	) {}

	/**
	 * Returns the singleton instance.
	 *
	 * @since 3.0.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			$settings                 = new Settings();
			$logger                   = Logger::instance();
			$provider_factory         = new ProviderFactory( $settings );
			$phone_normalizer         = new PhoneNumberNormalizer( $provider_factory );
			$lock_manager             = new LockManager();
			$recipient_resolver       = new RecipientResolver( $phone_normalizer );
			$message_builder          = new MessageBuilder();
			$pattern_variable_builder = new PatternVariableBuilder( $message_builder );

			self::$_instance = new self(
				$logger,
				$provider_factory,
				$recipient_resolver,
				$message_builder,
				$pattern_variable_builder,
				$lock_manager,
				$phone_normalizer,
				$settings
			);
		}
		return self::$_instance;
	}

	/**
	 * Register WordPress hooks for Gravity Flow events.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'gravityflow_step_complete', [ $this, 'handle_step_complete' ], 10, 5 );
		add_action( 'gravityflow_workflow_complete', [ $this, 'handle_workflow_complete' ], 10, 3 );
	}

	/**
	 * Gravity Flow step complete handler.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $step_id  Step ID.
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id  Form ID.
	 * @param string $status   Step status (approved/rejected).
	 * @param object $step     Step object.
	 *
	 * @return void
	 */
	public function handle_step_complete( $step_id, $entry_id, $form_id, $status, $step ): void {
		$this->process_step_event( (int) $step_id, (int) $entry_id, (int) $form_id, (string) $status, $step );
	}

	/**
	 * Gravity Flow workflow complete handler.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id  Form ID.
	 * @param string $status   Final workflow status.
	 *
	 * @return void
	 */
	public function handle_workflow_complete( $entry_id, $form_id, $status ): void {
		$form = $this->get_form( (int) $form_id );
		if ( ! is_array( $form ) ) {
			return;
		}
		$this->process_workflow_event( (int) $entry_id, $form, (string) $status );
	}

	/**
	 * Process a step event (approve/reject) and send SMS if applicable.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $step_id  Step ID.
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id  Form ID.
	 * @param string $status   Step status (approved/rejected).
	 * @param object $step     Step object.
	 *
	 * @return void
	 */
	public function process_step_event(
		int $step_id,
		int $entry_id,
		int $form_id,
		string $status,
		object $step
	): void {
		$settings = $this->settings;

		if ( ! $settings->isEnabled() ) {
			return;
		}
		if ( 'yes' !== $settings->get( 'trigger_step', 'yes' ) ) {
			return;
		}
		if ( ! $this->is_status_allowed( $settings->get( 'step_status_trigger', 'approved' ), $status ) ) {
			return;
		}

		$step = $this->resolve_step_object( $step, $step_id, $form_id );
		if ( ! $step ) {
			$this->logger->warning(
				$entry_id,
				$step_id,
				0,
				EventType::STEP->value,
				'step_missing',
				__( 'Step object not available.', 'gfsms' )
			);
			return;
		}

		$form  = $this->get_form( $form_id );
		$entry = $this->get_entry( $entry_id );
		if ( ! is_array( $form ) || ! is_array( $entry ) ) {
			return;
		}

		$recipients = $this->recipient_resolver->resolve_step_recipients( $settings, $step, $entry, $form_id );
		if ( empty( $recipients ) ) {
			$this->logger->warning(
				$entry_id,
				$step_id,
				0,
				EventType::STEP->value,
				'no_recipient',
				__( 'SMS skipped: no recipient resolved.', 'gfsms' )
			);
			$this->timeline_note( $entry_id, __( 'SMS skipped: no recipient resolved.', 'gfsms' ), $step );
			return;
		}

		$sender = $this->resolve_sender();
		if ( '' === $sender ) {
			$this->logger->warning(
				$entry_id,
				$step_id,
				0,
				EventType::STEP->value,
				'invalid_sender',
				__( 'SMS skipped: invalid sender number.', 'gfsms' )
			);
			return;
		}

		if ( ! $this->passes_conditions( $form, $entry ) ) {
			return;
		}

		$method   = $settings->get( 'sending_method', 'webservice' );
		$template = ( 'rejected' === $status )
			? (string) $settings->get( 'template_rejected', '' )
			: (string) $settings->get( 'template_approved', '' );

		$message = $this->message_builder->build_step_message( $template, $form, $entry, $step );
		if ( '' === $message ) {
			return;
		}

		// Fetch rule for this form, if exists.
		$rule = $this->find_recipient_rule( $form_id );

		// Use rule-level pattern_code and variable_map if present, otherwise fall back to global.
		if ( 'pattern' === $method ) {
			$pattern_code = null;
			$pattern_vars = array();

			if ( null !== $rule && ! empty( $rule['pattern_code'] ) ) {
				$pattern_code = $rule['pattern_code'];
				$raw_variable_map = $rule['variable_map'] ?? array();
				if ( is_string( $raw_variable_map ) ) {
					$raw_variable_map = json_decode( $raw_variable_map, true ) ?: array();
				}
				$pattern_vars = $this->pattern_variable_builder->build_pattern_variables(
					$raw_variable_map,
					$form,
					$entry,
					$step
				);
			} else {
				// Fallback to global pattern_map.
				$event_key    = ( 'rejected' === $status ) ? 'step_rejected' : 'step_approved';
				$pattern_map  = $settings->get( 'pattern_map', array() );
				$pattern_data = $pattern_map[ $event_key ] ?? array();
				if ( ! empty( $pattern_data['pattern_code'] ) ) {
					$pattern_code = $pattern_data['pattern_code'];
					$pattern_vars = $this->pattern_variable_builder->build_pattern_variables(
						$pattern_data['variable_map'] ?? array(),
						$form,
						$entry,
						$step
					);
				}
			}

			if ( null !== $pattern_code ) {
				$snapshot = EventSnapshot::create(
					$entry_id,
					$step_id,
					0,
					EventType::STEP,
					EventState::PROCESSING,
					$this->meta_key( EventType::STEP, $step_id, $entry_id ),
					$sender,
					$recipients,
					$message,
					$method,
					0,
					$settings->getQueueDelay()
				);
				$snapshot = $snapshot->with_pattern( $pattern_code, $pattern_vars );
				if ( ! $this->lock_manager->acquire( $entry_id, $snapshot->meta_key ) ) {
					return;
				}
				$this->enqueue( $snapshot );
				return;
			}
		}

		// Standard (non-pattern) or pattern fallback if no code resolved.
		$snapshot = EventSnapshot::create(
			$entry_id,
			$step_id,
			0,
			EventType::STEP,
			EventState::PROCESSING,
			$this->meta_key( EventType::STEP, $step_id, $entry_id ),
			$sender,
			$recipients,
			$message,
			$method,
			0,
			$settings->getQueueDelay()
		);

		if ( ! $this->lock_manager->acquire( $entry_id, $snapshot->meta_key ) ) {
			return;
		}
		$this->enqueue( $snapshot );
	}

	/**
	 * Process a workflow completion event.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id     Entry ID.
	 * @param array  $form         Form array.
	 * @param string $final_status Final workflow status.
	 *
	 * @return void
	 */
	public function process_workflow_event(
		int $entry_id,
		array $form,
		string $final_status
	): void {
		$settings = $this->settings;
		if ( ! $settings->isEnabled() ) {
			return;
		}
		if ( 'yes' !== $settings->get( 'trigger_workflow', 'no' ) ) {
			return;
		}

		$entry = $this->get_entry( $entry_id );
		if ( ! is_array( $entry ) ) {
			return;
		}

		$recipients = $this->recipient_resolver->resolve_workflow_recipients( $settings, $entry );
		if ( empty( $recipients ) ) {
			return;
		}

		$sender = $this->resolve_sender();
		if ( '' === $sender ) {
			return;
		}

		$method  = $settings->get( 'sending_method', 'webservice' );
		$message = $this->message_builder->build_workflow_message(
			(string) $settings->get( 'template_workflow', '' ),
			$form,
			$entry,
			$final_status
		);
		if ( '' === $message ) {
			return;
		}

		$snapshot = EventSnapshot::create(
			$entry_id,
			0,
			0,
			EventType::WORKFLOW,
			EventState::PROCESSING,
			$this->meta_key( EventType::WORKFLOW, 0, $entry_id ),
			$sender,
			$recipients,
			$message,
			$method,
			0,
			$settings->getQueueDelay()
		);

		if ( 'pattern' === $method ) {
			$pattern_map  = $settings->get( 'pattern_map', array() );
			$pattern_data = $pattern_map['workflow_complete'] ?? array();
			if ( ! empty( $pattern_data['pattern_code'] ) ) {
				$snapshot = $snapshot->with_pattern(
					$pattern_data['pattern_code'],
					$this->pattern_variable_builder->build_pattern_variables(
						$pattern_data['variable_map'] ?? array(),
						$form,
						$entry
					)
				);
			}
		}

		if ( ! $this->lock_manager->acquire( $entry_id, $snapshot->meta_key ) ) {
			return;
		}
		$this->enqueue( $snapshot );
	}

	/**
	 * Finalize result after delivery attempt (called from Event_Queue).
	 *
	 * @since 3.0.0
	 *
	 * @param array       $snapshot_array Snapshot data array.
	 * @param array       $result         Delivery result.
	 * @param object|null $step           Step object (optional).
	 *
	 * @return void
	 */
	public function finalize_result( array $snapshot_array, array $result, ?object $step = null ): void {
		$snapshot = EventSnapshot::from_array( $snapshot_array );
		$primary  = $this->provider_factory->get_primary();

		// ── Success ───────────────────────────────────────────────
		if ( ! empty( $result['success'] ) ) {
			$this->lock_manager->finalize(
				$snapshot->entry_id,
				$snapshot->meta_key,
				EventState::SENT->value
			);
			/* translators: %s is a masked phone number */
			$this->timeline_note(
				$snapshot->entry_id,
				sprintf( __( 'SMS sent successfully to %s.', 'gfsms' ), $this->phone_normalizer->mask_mobile( $snapshot->primary_recipient() ) ),
				$step
			);
			$this->logger->log_entry(
				$snapshot->entry_id,
				$snapshot->step_id,
				$snapshot->workflow_id,
				$snapshot->event_type->value,
				$snapshot->primary_recipient(),
				'sent',
				$snapshot->message,
				$result,
				'',
				$primary->get_name()
			);
			return;
		}

		// ── Error handling ─────────────────────────────────────────
		$error_status = $primary->classify_error( $result );
		$max_retry    = (int) $this->settings->get( 'max_retry', 1 );

		// Retryable & retry budget available.
		if ( ProviderErrorStatus::RETRYABLE === $error_status
			&& true === $this->settings->get( 'retry_enabled', true )
			&& $snapshot->retry_count < $max_retry
		) {
			$this->lock_manager->release( $snapshot->entry_id, $snapshot->meta_key );
			$this->timeline_note( $snapshot->entry_id, __( 'SMS retry scheduled.', 'gfsms' ), $step );
			$delay = 2 ** $snapshot->retry_count * 30;
			$this->schedule_retry( $snapshot, $delay );
			return;
		}

		// ── Fallback provider ──────────────────────────────────────
		if ( $this->settings->get( 'enable_fallback' ) && $this->settings->get( 'secondary_api_key' ) ) {
			$fallback_provider = $this->provider_factory->get_fallback();
			$fb_from           = $this->settings->get( 'secondary_sender_number' ) ?: $snapshot->from;

			try {
				if ( 'pattern' === $snapshot->sending_method && null !== $snapshot->pattern_code ) {
					$fb_result = $fallback_provider->send_pattern(
						$fb_from,
						$snapshot->recipients,
						$snapshot->pattern_code,
						$snapshot->pattern_variables ?? array()
					);
				} else {
					$fb_result = $fallback_provider->send( $fb_from, $snapshot->recipients, $snapshot->message );
				}
			} catch ( \Throwable $e ) {
				$fb_result = array(
					'success'       => false,
					'error_code'    => 'exception',
					'error_message' => $e->getMessage(),
				);
			}

			$this->logger->log_entry(
				$snapshot->entry_id,
				$snapshot->step_id,
				$snapshot->workflow_id,
				$snapshot->event_type->value,
				$snapshot->primary_recipient(),
				! empty( $fb_result['success'] ) ? 'sent' : 'failed',
				$snapshot->message,
				$fb_result,
				(string) ( $fb_result['error_code'] ?? '' ),
				$fallback_provider->get_name()
			);

			if ( ! empty( $fb_result['success'] ) ) {
				$this->lock_manager->finalize(
					$snapshot->entry_id,
					$snapshot->meta_key,
					EventState::SENT->value
				);
				$this->timeline_note( $snapshot->entry_id, __( 'SMS sent via fallback.', 'gfsms' ), $step );
				return;
			}
		}

		// ── Permanent failure ──────────────────────────────────────
		$this->lock_manager->finalize(
			$snapshot->entry_id,
			$snapshot->meta_key,
			EventState::FAILED_PERMANENT->value
		);
		$this->timeline_note(
			$snapshot->entry_id,
			/* translators: %s is the error message */
			sprintf( __( 'SMS failed: %s', 'gfsms' ), (string) ( $result['error_message'] ?? __( 'Unknown error', 'gfsms' ) ) ),
			$step
		);
		$this->logger->log_entry(
			$snapshot->entry_id,
			$snapshot->step_id,
			$snapshot->workflow_id,
			$snapshot->event_type->value,
			$snapshot->primary_recipient(),
			'failed',
			$snapshot->message,
			$result,
			(string) ( $result['error_code'] ?? 'failed' ),
			$primary->get_name()
		);

		if ( ProviderErrorStatus::PERMANENT === $error_status ) {
			Webhook_Alert::instance()->send_alert(
				array(
					'entry_id' => $snapshot->entry_id,
					'step_id'  => $snapshot->step_id,
					'event'    => $snapshot->event_type->value,
					'error'    => $result,
				)
			);
		}
	}

	/**
	 * Get a form by ID with caching.
	 *
	 * @since 3.0.0
	 *
	 * @param int $id Form ID.
	 *
	 * @return array|null
	 */
	private function get_form( int $id ): ?array {
		if ( ! isset( $this->form_cache[ $id ] ) ) {
			$form                    = \GFAPI::get_form( $id );
			$this->form_cache[ $id ] = is_array( $form ) ? $form : false;
		}
		$cached = $this->form_cache[ $id ];
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Get an entry by ID with caching.
	 *
	 * @since 3.0.0
	 *
	 * @param int $id Entry ID.
	 *
	 * @return array|null
	 */
	private function get_entry( int $id ): ?array {
		if ( ! isset( $this->entry_cache[ $id ] ) ) {
			$entry                    = \GFAPI::get_entry( $id );
			$this->entry_cache[ $id ] = is_array( $entry ) ? $entry : false;
		}
		$cached = $this->entry_cache[ $id ];
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Resolve a step object, using cache if needed.
	 *
	 * @since 3.0.0
	 *
	 * @param mixed $step    Step object or identifier.
	 * @param int   $step_id Step ID.
	 * @param int   $form_id Form ID.
	 *
	 * @return object|null
	 */
	private function resolve_step_object( mixed $step, int $step_id, int $form_id ): ?object {
		if ( is_object( $step ) && method_exists( $step, 'get_id' ) ) {
			return $step;
		}

		if ( ! isset( $this->step_cache[ $form_id ] ) ) {
			if ( ! class_exists( '\Gravity_Flow_API' ) ) {
				return null;
			}
			$api                          = new \Gravity_Flow_API( $form_id );
			$this->step_cache[ $form_id ] = array();
			foreach ( $api->get_steps() as $s ) {
				if ( is_object( $s ) && method_exists( $s, 'get_id' ) ) {
					$this->step_cache[ $form_id ][ $s->get_id() ] = $s;
				}
			}
		}

		return $this->step_cache[ $form_id ][ $step_id ] ?? null;
	}

	/**
	 * Find a recipient rule for a given form ID.
	 *
	 * @since 3.3.0
	 *
	 * @param int $form_id Form ID.
	 * @return array|null  The rule array, or null if not found.
	 */
	private function find_recipient_rule( int $form_id ): ?array {
		$rules = $this->settings->get( 'recipient_rules', array() );
		foreach ( $rules as $rule ) {
			if ( (int) ( $rule['form_id'] ?? 0 ) === $form_id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Enqueue a snapshot for sending.
	 *
	 * @since 3.0.0
	 *
	 * @param EventSnapshot $snapshot The event snapshot.
	 *
	 * @return void
	 */
	private function enqueue( EventSnapshot $snapshot ): void {
		Event_Queue::instance()->enqueue( $snapshot->to_array(), $snapshot->delay );
	}

	/**
	 * Schedule a retry for a failed snapshot.
	 *
	 * @since 3.0.0
	 *
	 * @param EventSnapshot $snapshot The snapshot.
	 * @param int           $delay    Delay in seconds.
	 *
	 * @return void
	 */
	private function schedule_retry( EventSnapshot $snapshot, int $delay ): void {
		$data                = $snapshot->to_array();
		$data['retry_count'] = $snapshot->retry_count + 1;
		Event_Queue::instance()->schedule_retry( $data, max( 1, $delay ) );
	}

	/**
	 * Resolve the sender phone number.
	 *
	 * @since 3.0.0
	 *
	 * @return string Normalized sender number, or empty string if invalid.
	 */
	private function resolve_sender(): string {
		$raw        = $this->settings->get( 'default_sender_number', '' );
		$normalized = $this->phone_normalizer->normalize( trim( (string) $raw ) );
		if ( null === $normalized ) {
			$this->logger->debug_message( 'Invalid sender configured' );
			return '';
		}
		return $normalized;
	}

	/**
	 * Check if a status is allowed based on trigger setting.
	 *
	 * @since 3.0.0
	 *
	 * @param string $trigger Trigger setting (approved, rejected, both).
	 * @param string $status  Actual status.
	 *
	 * @return bool
	 */
	private function is_status_allowed( string $trigger, string $status ): bool {
		return match ( $trigger ) {
			'approved' => 'approved' === $status,
			'rejected' => 'rejected' === $status,
			'both'     => in_array( $status, array( 'approved', 'rejected' ), true ),
			default    => true,
		};
	}

	/**
	 * Evaluate conditional logic for a form/entry.
	 *
	 * @since 3.0.0
	 *
	 * @param array $form  Form array.
	 * @param array $entry Entry array.
	 *
	 * @return bool
	 */
	private function passes_conditions( array $form, array $entry ): bool {
		$logic = $this->settings->get( 'conditional_logic', array() );
		if ( empty( $logic ) || ! class_exists( '\GFCommon' ) ) {
			return true;
		}
		try {
			return (bool) \GFCommon::evaluate_conditional_logic( $logic, $form, $entry );
		} catch ( \Throwable $e ) {
			$this->logger->debug_message( 'Conditional logic failed: ' . $e->getMessage() );
			return true;
		}
	}

	/**
	 * Add a note to the Gravity Flow timeline.
	 *
	 * @since 3.0.0
	 *
	 * @param int         $entry_id Entry ID.
	 * @param string      $note     Note text.
	 * @param object|null $step     Step object (unused).
	 *
	 * @return void
	 */
	private function timeline_note( int $entry_id, string $note, ?object $step = null ): void {
		$flow = function_exists( 'gravity_flow' ) ? gravity_flow() : null;
		if ( $flow && is_object( $flow ) && method_exists( $flow, 'add_timeline_note' ) ) {
			try {
				$flow->add_timeline_note( $entry_id, $note );
				return;
			} catch ( \Throwable $e ) {
				// Fall through to logging.
			}
		}
		$this->logger->debug_message( 'Timeline note: ' . $note );
	}

	/**
	 * Increment the event generation counter for an entry.
	 *
	 * @since 3.0.0
	 *
	 * @param int $entry_id Entry ID.
	 *
	 * @return void
	 */
	public function increment_generation( int $entry_id ): void {
		$current = (int) gform_get_meta( $entry_id, 'gfsms_generation' );
		gform_update_meta( $entry_id, 'gfsms_generation', max( 1, $current + 1 ) );
	}

	/**
	 * Generate a meta key for locking and tracking.
	 *
	 * @since 3.0.0
	 *
	 * @param EventType $type     Event type.
	 * @param int       $id       Step ID or 0.
	 * @param int       $entry_id Entry ID.
	 *
	 * @return string
	 */
	private function meta_key( EventType $type, int $id, int $entry_id ): string {
		$gen = (int) gform_get_meta( $entry_id, 'gfsms_generation' );
		if ( 0 === $gen ) {
			$gen = 1;
		}
		return sprintf( 'gfsms_event_%s_%d_%d', $type->value, $id, $gen );
	}
}