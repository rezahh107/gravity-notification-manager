<?php
/**
 * Captures Gravity Flow lifecycle events and delegates them to the Dispatcher.
 *
 * @package GFSMS\Integration
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

use GFSMS\Logging\Logger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Listener
 *
 * Hooks into Gravity Flow actions and triggers SMS dispatching.
 */
final class Listener {

	/**
	 * Registers all Gravity Flow action hooks only if Gravity Flow is active.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'gravityflow' ) ) {
			return;
		}

		add_action( 'gravityflow_step_complete', array( self::class, 'on_step_complete' ), 10, 5 );
		add_action( 'gravityflow_workflow_complete', array( self::class, 'on_workflow_complete' ), 10, 3 );
		add_action( 'gravityflow_workflow_started', array( self::class, 'on_workflow_started' ), 10, 1 );
	}

	/**
	 * Handles a completed workflow step.
	 *
	 * @param mixed $step_id  Gravity Flow step ID.
	 * @param mixed $entry_id Gravity Forms entry ID.
	 * @param mixed $form_id  Gravity Forms form ID.
	 * @param mixed $status   Step status (e.g. 'approved', 'rejected').
	 * @param mixed $step     Gravity_Flow_Step object.
	 *
	 * @return void
	 */
	public static function on_step_complete( $step_id, $entry_id, $form_id, $status, $step ): void {
		try {
			$step_id  = (int) $step_id;
			$entry_id = (int) $entry_id;
			$form_id  = (int) $form_id;
			$status   = sanitize_text_field( (string) $status );

			if ( $step_id <= 0 || $entry_id <= 0 || $form_id <= 0 ) {
				return;
			}

			if ( ! class_exists( Dispatcher::class ) ) {
				self::log_error( 'Dispatcher class not found during step_complete.' );
				return;
			}

			Dispatcher::instance()->process_step_event(
				$step_id,
				$entry_id,
				$form_id,
				$status,
				$step
			);
		} catch ( Throwable $e ) {
			self::log_error( 'Listener step_complete error: ' . $e->getMessage() );
		}
	}

	/**
	 * Handles a completed workflow.
	 *
	 * @param mixed $entry_id     Gravity Forms entry ID.
	 * @param mixed $form         Array of form data from Gravity Forms.
	 * @param mixed $final_status Final workflow status (e.g. 'approved', 'rejected').
	 *
	 * @return void
	 */
	public static function on_workflow_complete( $entry_id, $form, $final_status ): void {
		try {
			$entry_id = (int) $entry_id;

			if ( $entry_id <= 0 || ! is_array( $form ) ) {
				return;
			}

			$status = sanitize_text_field( (string) $final_status );

			if ( ! class_exists( Dispatcher::class ) ) {
				self::log_error( 'Dispatcher class not found during workflow_complete.' );
				return;
			}

			Dispatcher::instance()->process_workflow_event(
				$entry_id,
				$form,
				$status
			);
		} catch ( Throwable $e ) {
			self::log_error( 'Listener workflow_complete error: ' . $e->getMessage() );
		}
	}

	/**
	 * Handles a workflow that just started.
	 *
	 * @param mixed $entry_id Gravity Forms entry ID.
	 *
	 * @return void
	 */
	public static function on_workflow_started( $entry_id ): void {
		try {
			$entry_id = (int) $entry_id;

			if ( $entry_id <= 0 ) {
				return;
			}

			if ( ! class_exists( Dispatcher::class ) ) {
				self::log_error( 'Dispatcher class not found during workflow_started.' );
				return;
			}

			Dispatcher::instance()->increment_generation( $entry_id );
		} catch ( Throwable $e ) {
			self::log_error( 'Listener workflow_started error: ' . $e->getMessage() );
		}
	}

	/**
	 * Log an error using the plugin’s Logger, if available.
	 *
	 * @param string $message Error message.
	 *
	 * @return void
	 */
	private static function log_error( string $message ): void {
		$message = (string) $message;

		if ( class_exists( Logger::class ) ) {
			try {
				Logger::instance()->error( $message, array( 'source' => 'listener' ) );
				return;
			} catch ( Throwable $e ) {
				// Fall through to error_log.
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'GFSMS Listener: ' . $message );
	}
}
