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
use GravityNotify\Recipient\RecipientResolver;
use Throwable;

/**
 * Connects the canonical Feed rule to the WU-03 resolver and WU-02 dispatcher.
 */
final class NotificationFeedProcessor {

	/**
	 * Recipient resolver.
	 *
	 * @var RecipientResolver
	 */
	private RecipientResolver $resolver;

	/**
	 * Synchronous transport dispatcher.
	 *
	 * @var SynchronousDispatcher
	 */
	private SynchronousDispatcher $dispatcher;

	/**
	 * Already-configured SMS sender/from value.
	 *
	 * Production settings/cutover composition remains outside WU-04.
	 *
	 * @var string
	 */
	private string $sms_sender;

	/**
	 * Constructor.
	 *
	 * @param RecipientResolver      $resolver Recipient resolver.
	 * @param SynchronousDispatcher $dispatcher Synchronous transport dispatcher.
	 * @param string                 $sms_sender Already-configured SMS sender.
	 */
	public function __construct(
		RecipientResolver $resolver,
		SynchronousDispatcher $dispatcher,
		string $sms_sender = ''
	) {
		$this->resolver    = $resolver;
		$this->dispatcher  = $dispatcher;
		$this->sms_sender = trim( $sms_sender );
	}

	/**
	 * Execute one normalized Feed rule synchronously.
	 *
	 * Native Gravity Forms / Gravity Flow lifecycle code remains responsible for
	 * Feed conditional logic. This method does not evaluate conditions itself.
	 *
	 * @param array<string, int|string> $rule Normalized Feed rule.
	 * @param array                     $entry Current Gravity Forms Entry.
	 * @param array                     $form Current Gravity Forms Form.
	 * @return NotificationExecutionResult
	 */
	public function execute( array $rule, array $entry, array $form ): NotificationExecutionResult {
		try {
			$resolution = $this->resolver->resolve( $rule, $entry, $form );
		} catch ( Throwable ) {
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
			return $this->execute_sms( $rule, $resolution->destinations(), $message, $skips );
		}

		if ( FeedRuleSchema::CHANNEL_BALE === $channel ) {
			return $this->execute_bale( $resolution->destinations(), $message, $skips );
		}

		$skips[] = $this->skip( 'channel', 'unsupported_channel' );
		return new NotificationExecutionResult( array(), $skips, false );
	}

	/**
	 * Execute an SMS rule through the existing WU-02 dispatcher.
	 *
	 * WU-01 exposes one message field and no pattern-code/parameter contract, so
	 * WU-04 preserves that field as rendered plain text and never performs a
	 * Pattern-to-Plain conversion.
	 *
	 * @param array<string, int|string>                       $rule Normalized Feed rule.
	 * @param array<int, string>                              $destinations Resolved SMS targets.
	 * @param string                                          $message Rendered message.
	 * @param array<int, array{subject:string,reason:string}> $skips Existing safe skips.
	 * @return NotificationExecutionResult
	 */
	private function execute_sms( array $rule, array $destinations, string $message, array $skips ): NotificationExecutionResult {
		if ( '' === $this->sms_sender ) {
			$skips[] = $this->skip( 'sms_sender', 'sms_sender_unconfigured' );
			return new NotificationExecutionResult( array(), $skips, false );
		}

		$capability = 1 === count( $destinations )
			? SmsCapability::PLAIN
			: SmsCapability::MULTI_RECIPIENT_PLAIN;

		$allow_sms_fallback = FeedRuleSchema::FALLBACK_COMPATIBLE_SMS === ( $rule['fallback_policy'] ?? FeedRuleSchema::FALLBACK_NONE );

		try {
			$request  = SmsRequest::plain( $capability, $destinations, $this->sms_sender, $message );
			$attempts = $this->dispatcher->dispatch_sms( $request, false, null, $allow_sms_fallback );
		} catch ( Throwable ) {
			$skips[] = $this->skip( 'sms_delivery', 'delivery_exception' );
			return new NotificationExecutionResult( array(), $skips, false );
		}

		return new NotificationExecutionResult( $attempts, $skips, $this->has_success( $attempts ) );
	}

	/**
	 * Execute a Bale rule through the existing WU-02 dispatcher.
	 *
	 * @param array<int, string>                              $destinations Resolved Bale targets.
	 * @param string                                          $message Rendered message.
	 * @param array<int, array{subject:string,reason:string}> $skips Existing safe skips.
	 * @return NotificationExecutionResult
	 */
	private function execute_bale( array $destinations, string $message, array $skips ): NotificationExecutionResult {
		$attempts    = array();
		$all_success = true;

		foreach ( $destinations as $destination ) {
			try {
				$request              = new BaleRequest( $destination, $message );
				$destination_attempts = $this->dispatcher->dispatch_bale( $request );
			} catch ( Throwable ) {
				$skips[]    = $this->skip( 'bale_delivery', 'delivery_exception' );
				$all_success = false;
				continue;
			}

			$attempts = array_merge( $attempts, $destination_attempts );
			if ( ! $this->has_success( $destination_attempts ) ) {
				$all_success = false;
			}
		}

		return new NotificationExecutionResult( $attempts, $skips, $all_success && array() !== $attempts );
	}

	/**
	 * Render native Gravity Forms merge tags when the runtime is available.
	 *
	 * @param string $message Raw Feed message.
	 * @param array  $form Current form.
	 * @param array  $entry Current entry.
	 * @return string
	 */
	private function render_message( string $message, array $form, array $entry ): string {
		if ( class_exists( '\GFCommon' ) && method_exists( '\GFCommon', 'replace_variables' ) ) {
			$rendered = \GFCommon::replace_variables( $message, $form, $entry, false, false, false, 'text' );
			return is_scalar( $rendered ) ? (string) $rendered : '';
		}

		return $message;
	}

	/**
	 * Whether at least one attempt obtained documented acceptance.
	 *
	 * @param array<int, AttemptResult> $attempts Transport attempts.
	 * @return bool
	 */
	private function has_success( array $attempts ): bool {
		foreach ( $attempts as $attempt ) {
			if ( AttemptStatus::SUCCESS === $attempt->status() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a safe failure result without transport I/O.
	 *
	 * @param string $subject Safe subject.
	 * @param string $reason Safe reason.
	 * @return NotificationExecutionResult
	 */
	private function failed_without_attempt( string $subject, string $reason ): NotificationExecutionResult {
		return new NotificationExecutionResult( array(), array( $this->skip( $subject, $reason ) ), false );
	}

	/**
	 * Build a safe skip classification.
	 *
	 * @param string $subject Safe subject.
	 * @param string $reason Safe reason.
	 * @return array{subject:string,reason:string}
	 */
	private function skip( string $subject, string $reason ): array {
		return array(
			'subject' => $subject,
			'reason'  => $reason,
		);
	}
}
