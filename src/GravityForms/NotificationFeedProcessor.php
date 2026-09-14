<?php
/**
 * Shared synchronous execution path for one notification Feed.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityForms;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Bale\BaleRequest;
use GravityNotify\Delivery\Sms\SmsCapability;
use GravityNotify\Delivery\Sms\SmsRequest;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\Observability\OperationalContext;
use GravityNotify\Observability\OperationalLogger;
use GravityNotify\Recipient\RecipientResolver;
use Throwable;

/**
 * Connects the canonical Feed rule to the recipient resolver and dispatcher.
 */
final class NotificationFeedProcessor {

	private RecipientResolver $resolver;
	private SynchronousDispatcher $dispatcher;
	private string $sms_sender;
	private ?OperationalLogger $operational_log;

	public function __construct(
		RecipientResolver $resolver,
		SynchronousDispatcher $dispatcher,
		string $sms_sender = '',
		?OperationalLogger $operational_log = null
	) {
		$this->resolver        = $resolver;
		$this->dispatcher      = $dispatcher;
		$this->sms_sender      = trim( $sms_sender );
		$this->operational_log = $operational_log;
	}

	/**
	 * Execute one normalized Feed rule synchronously.
	 *
	 * @param array<string, int|string> $rule    Normalized Feed rule.
	 * @param array                     $entry   Current Gravity Forms Entry.
	 * @param array                     $form    Current Gravity Forms Form.
	 * @param OperationalContext|null   $context Optional operation context from Feed orchestration.
	 */
	public function execute( array $rule, array $entry, array $form, ?OperationalContext $context = null ): NotificationExecutionResult {
		$context = $context ?? $this->fallback_context( $rule, $entry, $form );
		try {
			$resolution = $this->resolver->resolve( $rule, $entry, $form );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return $this->failed_without_attempt( 'recipient_resolution', 'resolution_exception' );
		}

		$skips = $resolution->skips();
		if ( ! $resolution->has_destinations() ) {
			return new NotificationExecutionResult( array(), $skips, false );
		}

		$message = $this->render_message( (string) ( $rule['message'] ?? '' ), $form, $entry );
		if ( '' === trim( $message ) ) {
			$skips[] = $this->skip( 'message', 'empty_message' );
			return new NotificationExecutionResult( array(), $skips, false );
		}

		$channel = (string) ( $rule['channel'] ?? '' );
		if ( FeedRuleSchema::CHANNEL_SMS === $channel ) {
			return $this->execute_sms( $rule, $resolution->destinations(), $message, $skips, $context );
		}

		if ( FeedRuleSchema::CHANNEL_BALE === $channel ) {
			return $this->execute_bale( $resolution->destinations(), $message, $skips, $context );
		}

		$skips[] = $this->skip( 'channel', 'unsupported_channel' );
		return new NotificationExecutionResult( array(), $skips, false );
	}

	/**
	 * @param array<string, int|string>                       $rule         Normalized Feed rule.
	 * @param array<int, string>                              $destinations Resolved SMS targets.
	 * @param array<int, array{subject:string,reason:string}> $skips        Existing safe skips.
	 */
	private function execute_sms(
		array $rule,
		array $destinations,
		string $message,
		array $skips,
		OperationalContext $context
	): NotificationExecutionResult {
		if ( '' === $this->sms_sender ) {
			$skips[] = $this->skip( 'sms_sender', 'sms_sender_unconfigured' );
			return new NotificationExecutionResult( array(), $skips, false );
		}

		$capability         = 1 === count( $destinations )
			? SmsCapability::PLAIN
			: SmsCapability::MULTI_RECIPIENT_PLAIN;
		$allow_sms_fallback = FeedRuleSchema::FALLBACK_COMPATIBLE_SMS === ( $rule['fallback_policy'] ?? FeedRuleSchema::FALLBACK_NONE );

		try {
			$request  = SmsRequest::plain( $capability, $destinations, $this->sms_sender, $message );
			$attempts = $this->dispatcher->dispatch_sms( $request, false, null, $allow_sms_fallback );
		} catch ( Throwable $exception ) {
			unset( $exception );
			$skips[] = $this->skip( 'sms_delivery', 'delivery_exception' );
			$this->record_exception_attempt( $context, 'sms', $capability, $destinations, $this->sms_sender );
			return new NotificationExecutionResult( array(), $skips, false );
		}

		$this->record_attempts( $context, $attempts, $destinations, $this->sms_sender );
		return new NotificationExecutionResult( $attempts, $skips, $this->has_success( $attempts ) );
	}

	/**
	 * @param array<int, string>                              $destinations Resolved Bale targets.
	 * @param array<int, array{subject:string,reason:string}> $skips        Existing safe skips.
	 */
	private function execute_bale(
		array $destinations,
		string $message,
		array $skips,
		OperationalContext $context
	): NotificationExecutionResult {
		$attempts      = array();
		$all_success   = true;
		$attempt_index = 1;

		foreach ( $destinations as $destination ) {
			try {
				$request              = new BaleRequest( $destination, $message );
				$destination_attempts = $this->dispatcher->dispatch_bale( $request );
			} catch ( Throwable $exception ) {
				unset( $exception );
				$skips[]     = $this->skip( 'bale_delivery', 'delivery_exception' );
				$all_success = false;
				$this->record_exception_attempt( $context, 'bale', null, array( $destination ), null, $attempt_index );
				++$attempt_index;
				continue;
			}

			$attempts      = array_merge( $attempts, $destination_attempts );
			$attempt_index = $this->record_attempts( $context, $destination_attempts, array( $destination ), null, $attempt_index );
			if ( ! $this->has_success( $destination_attempts ) ) {
				$all_success = false;
			}
		}

		return new NotificationExecutionResult( $attempts, $skips, $all_success && array() !== $attempts );
	}

	private function render_message( string $message, array $form, array $entry ): string {
		if ( class_exists( '\\GFCommon' ) && method_exists( '\\GFCommon', 'replace_variables' ) ) {
			$rendered = \GFCommon::replace_variables( $message, $form, $entry, false, false, false, 'text' );
			return is_scalar( $rendered ) ? (string) $rendered : '';
		}
		return $message;
	}

	/** @param array<int, AttemptResult> $attempts */
	private function has_success( array $attempts ): bool {
		foreach ( $attempts as $attempt ) {
			if ( AttemptStatus::SUCCESS === $attempt->status() ) {
				return true;
			}
		}
		return false;
	}

	private function failed_without_attempt( string $subject, string $reason ): NotificationExecutionResult {
		return new NotificationExecutionResult( array(), array( $this->skip( $subject, $reason ) ), false );
	}

	/** @return array{subject:string,reason:string} */
	private function skip( string $subject, string $reason ): array {
		return array(
			'subject' => $subject,
			'reason'  => $reason,
		);
	}

	private function fallback_context( array $rule, array $entry, array $form ): OperationalContext {
		return new OperationalContext(
			OperationalContext::EXECUTION_NORMAL,
			$this->positive_identifier( $form['id'] ?? null ),
			null,
			$this->positive_identifier( $entry['id'] ?? null ),
			(string) ( $rule['feedName'] ?? '' )
		);
	}

	/** @param mixed $value */
	private function positive_identifier( $value ): ?int {
		if ( is_int( $value ) ) {
			return 0 < $value ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
			return 0 < $value ? $value : null;
		}
		return null;
	}

	/**
	 * @param array<int, AttemptResult> $attempts
	 * @param array<int, string>        $destinations
	 */
	private function record_attempts(
		OperationalContext $context,
		array $attempts,
		array $destinations,
		?string $sender,
		int $start_index = 1
	): int {
		if ( null === $this->operational_log ) {
			return $start_index + count( $attempts );
		}
		return $this->operational_log->record_attempts( $context, $attempts, $destinations, $sender, $start_index );
	}

	/** @param array<int, string> $destinations */
	private function record_exception_attempt(
		OperationalContext $context,
		string $channel,
		?string $capability,
		array $destinations,
		?string $sender,
		int $attempt_index = 1
	): void {
		if ( null === $this->operational_log ) {
			return;
		}
		$this->operational_log->record_attempt(
			$context,
			new AttemptResult( AttemptStatus::AMBIGUOUS, $channel, null, $capability, array(), 'delivery_exception' ),
			$destinations,
			$sender,
			$attempt_index
		);
	}
}
