<?php
/**
 * Event Queue Service - Enterprise Grade
 *
 * Handles asynchronous payload processing with locking mechanisms.
 *
 * @package GFSMS\Queue
 */

declare( strict_types = 1 );

namespace GFSMS\Queue;

use GFSMS\Domain\EventSnapshot;
use GFSMS\Integration\Dispatcher;
use GFSMS\Integration\Sms_Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Final class Event_Queue
 *
 * Singleton queue manager for SMS payloads.
 */
final class Event_Queue {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Action hook for processing payloads.
	 *
	 * @var string
	 */
	private const PROCESS_HOOK = 'gfsms_process_payload';

	/**
	 * Action hook for retrying payloads.
	 *
	 * @var string
	 */
	private const RETRY_HOOK = 'gfsms_retry_payload';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	private const GROUP = 'gfsms';

	/**
	 * Lock TTL in seconds.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 600;

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( self::PROCESS_HOOK, array( $this, 'process_payload' ), 10, 1 );
		add_action( self::RETRY_HOOK, array( $this, 'retry_payload' ), 10, 1 );
	}

	/**
	 * Enqueues a payload for processing, optionally with a delay.
	 *
	 * @param EventSnapshot|array $payload Payload data.
	 * @param int                 $delay   Delay in seconds.
	 *
	 * @return void
	 */
	public function enqueue( EventSnapshot|array $payload, int $delay = 0 ): void {
		$payload = $this->normalize_payload( $payload );
		if ( ! $this->is_valid_payload( $payload ) ) {
			$this->log( __( 'Invalid payload skipped (enqueue).', 'gfsms' ) );
			return;
		}

		if ( ! $this->acquire_lock( $payload ) ) {
			$this->log( __( 'Duplicate lock exists – skipped (enqueue).', 'gfsms' ) );
			return;
		}

		$timestamp = time() + max( 0, $delay );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
				$action_id = as_schedule_single_action( $timestamp, self::PROCESS_HOOK, array( $payload ), self::GROUP );
			} else {
				$action_id = as_enqueue_async_action( self::PROCESS_HOOK, array( $payload ), self::GROUP );
			}
			/* translators: %d is the Action Scheduler action ID */
			$this->log( sprintf( __( 'Action scheduled (ID: %d)', 'gfsms' ), $action_id ) );
			return;
		}

		wp_schedule_single_event( $timestamp, self::PROCESS_HOOK, array( $payload ) );
	}

	/**
	 * Schedules a retry for a failed payload.
	 *
	 * @param EventSnapshot|array $payload Payload data.
	 * @param int                 $delay   Delay in seconds (minimum 1).
	 *
	 * @return void
	 */
	public function schedule_retry( EventSnapshot|array $payload, int $delay = 10 ): void {
		$payload = $this->normalize_payload( $payload );
		if ( ! $this->is_valid_payload( $payload ) ) {
			$this->log( __( 'Invalid payload skipped (retry).', 'gfsms' ) );
			return;
		}

		if ( ! $this->acquire_retry_lock( $payload ) ) {
			$this->log( __( 'Duplicate retry lock exists – skipped.', 'gfsms' ) );
			return;
		}

		$timestamp = time() + max( 1, $delay );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::RETRY_HOOK, array( $payload ), self::GROUP );
			return;
		}

		wp_schedule_single_event( $timestamp, self::RETRY_HOOK, array( $payload ) );
	}

	/**
	 * Processes a payload (WP Cron / Action Scheduler callback).
	 *
	 * @param array $payload Normalized payload.
	 *
	 * @return void
	 */
	public function process_payload( array $payload ): void {
		if ( ! $this->is_valid_payload( $payload ) ) {
			return;
		}

		try {
			$result = Sms_Sender::instance()->deliver( $payload );
		} catch ( \Throwable $e ) {
			$result = array(
				'success'       => false,
				'error_code'    => 'exception',
				'error_message' => $e->getMessage(),
			);
		}

		Dispatcher::instance()->finalize_result( $payload, $result );
		$this->release_lock( $payload );
	}

	/**
	 * Retries a payload (WP Cron / Action Scheduler callback).
	 *
	 * @param array $payload Normalized payload.
	 *
	 * @return void
	 */
	public function retry_payload( array $payload ): void {
		if ( ! $this->is_valid_payload( $payload ) ) {
			return;
		}

		try {
			$result = Sms_Sender::instance()->deliver( $payload );
		} catch ( \Throwable $e ) {
			$result = array(
				'success'       => false,
				'error_code'    => 'exception',
				'error_message' => $e->getMessage(),
			);
		}

		Dispatcher::instance()->finalize_result( $payload, $result );
		$this->release_retry_lock( $payload );
	}

	/**
	 * Acquires a processing lock for the given payload.
	 *
	 * @param array $payload Normalized payload.
	 *
	 * @return bool True if lock acquired, false otherwise.
	 */
	private function acquire_lock( array $payload ): bool {
		$key = $this->lock_key( $payload );
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		$existing = (int) get_option( $key, 0 );
		if ( $existing && ( time() - $existing ) < self::LOCK_TTL ) {
			return false;
		}

		update_option( $key, time(), false );
		return true;
	}

	/**
	 * Acquires a retry lock for the given payload.
	 *
	 * @param array $payload Normalized payload.
	 *
	 * @return bool True if lock acquired, false otherwise.
	 */
	private function acquire_retry_lock( array $payload ): bool {
		$key = $this->retry_lock_key( $payload );
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		$existing = (int) get_option( $key, 0 );
		if ( $existing && ( time() - $existing ) < self::LOCK_TTL ) {
			return false;
		}

		update_option( $key, time(), false );
		return true;
	}

	/**
	 * Releases the processing lock.
	 *
	 * @param array $payload Normalized payload.
	 *
	 * @return void
	 */
	private function release_lock( array $payload ): void {
		delete_option( $this->lock_key( $payload ) );
	}

	/**
	 * Releases the retry lock.
	 *
	 * @param array $payload Normalized payload.
	 *
	 * @return void
	 */
	private function release_retry_lock( array $payload ): void {
		delete_option( $this->retry_lock_key( $payload ) );
	}

	/**
	 * Generates a lock key for the payload.
	 *
	 * @param array $payload Normalized payload.
	 *
	 * @return string
	 */
	private function lock_key( array $payload ): string {
		return 'gfsms_queue_' . md5( (string) ( $payload['meta_key'] ?? '' ) );
	}

	/**
	 * Generates a retry lock key for the payload.
	 *
	 * @param array $payload Normalized payload.
	 *
	 * @return string
	 */
	private function retry_lock_key( array $payload ): string {
		$retry = (int) ( $payload['retry_count'] ?? 0 );
		return 'gfsms_retry_queue_' . md5( (string) ( $payload['meta_key'] ?? '' ) . '_' . $retry );
	}

	/**
	 * Normalizes a payload to an array.
	 *
	 * @param EventSnapshot|array $payload Payload.
	 *
	 * @return array
	 */
	private function normalize_payload( EventSnapshot|array $payload ): array {
		return $payload instanceof EventSnapshot ? $payload->toArray() : $payload;
	}

	/**
	 * Validates a normalized payload.
	 *
	 * @param array $payload Normalized payload.
	 *
	 * @return bool
	 */
	private function is_valid_payload( array $payload ): bool {
		return isset(
			$payload['entry_id'],
			$payload['recipients'],
			$payload['message'],
			$payload['meta_key']
		)
			&& (int) $payload['entry_id'] > 0
			&& is_array( $payload['recipients'] )
			&& ! empty( $payload['recipients'] )
			&& is_string( $payload['message'] )
			&& '' !== $payload['message']
			&& is_string( $payload['meta_key'] )
			&& '' !== $payload['meta_key'];
	}

	/**
	 * Logs a message to the error log (translated for consistency).
	 *
	 * @param string $message Log message (already translated).
	 *
	 * @return void
	 */
	private function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[GFSMS Queue] ' . $message );
	}
}
