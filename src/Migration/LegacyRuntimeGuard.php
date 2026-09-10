<?php
/**
 * Temporary compatibility guards for scope-by-scope WU-08 cutover.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

/**
 * Suppresses legacy send callbacks only for scopes whose legacy authority is disabled.
 */
final class LegacyRuntimeGuard {

	private static int $direct_form_id = 0;
	private static bool $restore_flow_step = false;
	private static bool $restore_workflow = false;
	private static bool $restore_process_queue = false;
	private static bool $restore_retry_queue = false;

	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) || ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_action( 'gform_after_submission', array( self::class, 'begin_direct' ), 1, 2 );
		add_action( 'gform_after_submission', array( self::class, 'end_direct' ), 11, 2 );
		add_filter( 'option_gfsms_settings', array( self::class, 'filter_direct_settings' ), PHP_INT_MAX, 2 );
		add_action( 'gravityflow_step_complete', array( self::class, 'begin_flow_step' ), 1, 5 );
		add_action( 'gravityflow_step_complete', array( self::class, 'restore_flow_step' ), 11, 5 );
		add_action( 'gravityflow_workflow_complete', array( self::class, 'begin_workflow' ), 1, 3 );
		add_action( 'gravityflow_workflow_complete', array( self::class, 'restore_workflow' ), 11, 3 );
		add_action( 'gfsms_process_payload', array( self::class, 'begin_process_payload' ), 1, 1 );
		add_action( 'gfsms_process_payload', array( self::class, 'restore_process_payload' ), 11, 1 );
		add_action( 'gfsms_retry_payload', array( self::class, 'begin_retry_payload' ), 1, 1 );
		add_action( 'gfsms_retry_payload', array( self::class, 'restore_retry_payload' ), 11, 1 );
	}

	public static function begin_direct( array $entry, array $form ): void {
		unset( $entry );
		self::$direct_form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;
	}

	public static function end_direct( array $entry, array $form ): void {
		unset( $entry, $form );
		self::$direct_form_id = 0;
	}

	/** @param mixed $value @return mixed */
	public static function filter_direct_settings( $value ) {
		if ( 1 > self::$direct_form_id || ! is_array( $value ) || ! is_array( $value['gf_rules'] ?? null ) ) {
			return $value;
		}
		foreach ( $value['gf_rules'] as $index => $rule ) {
			if ( is_array( $rule ) && self::$direct_form_id === (int) ( $rule['form_id'] ?? 0 ) && ! CutoverRegistry::legacy_direct_rule_allowed( self::$direct_form_id, (int) $index, $rule ) ) {
				unset( $value['gf_rules'][ $index ] );
			}
		}
		return $value;
	}

	public static function begin_flow_step( $step_id, $entry_id, $form_id, $status, $step ): void {
		unset( $entry_id, $status, $step );
		if ( CutoverRegistry::legacy_flow_step_allowed( (int) $form_id, (int) $step_id ) ) {
			return;
		}
		self::$restore_flow_step = self::remove_flow_step_callbacks();
	}

	public static function restore_flow_step( $step_id, $entry_id, $form_id, $status, $step ): void {
		unset( $step_id, $entry_id, $form_id, $status, $step );
		if ( ! self::$restore_flow_step ) {
			return;
		}
		self::add_flow_step_callbacks();
		self::$restore_flow_step = false;
	}

	public static function begin_workflow( $entry_id, $form, $status ): void {
		unset( $entry_id, $status );
		$form_id = is_array( $form ) ? (int) ( $form['id'] ?? 0 ) : (int) $form;
		if ( CutoverRegistry::legacy_workflow_allowed( $form_id ) ) {
			return;
		}
		self::$restore_workflow = self::remove_workflow_callbacks();
	}

	public static function restore_workflow( $entry_id, $form, $status ): void {
		unset( $entry_id, $form, $status );
		if ( ! self::$restore_workflow ) {
			return;
		}
		self::add_workflow_callbacks();
		self::$restore_workflow = false;
	}

	public static function begin_process_payload( array $payload ): void {
		if ( self::legacy_payload_allowed( $payload ) ) {
			return;
		}
		self::$restore_process_queue = self::remove_queue_callback( 'gfsms_process_payload', 'process_payload' );
	}

	public static function restore_process_payload( array $payload ): void {
		unset( $payload );
		if ( self::$restore_process_queue ) {
			self::add_queue_callback( 'gfsms_process_payload', 'process_payload' );
			self::$restore_process_queue = false;
		}
	}

	public static function begin_retry_payload( array $payload ): void {
		if ( self::legacy_payload_allowed( $payload ) ) {
			return;
		}
		self::$restore_retry_queue = self::remove_queue_callback( 'gfsms_retry_payload', 'retry_payload' );
	}

	public static function restore_retry_payload( array $payload ): void {
		unset( $payload );
		if ( self::$restore_retry_queue ) {
			self::add_queue_callback( 'gfsms_retry_payload', 'retry_payload' );
			self::$restore_retry_queue = false;
		}
	}

	private static function legacy_payload_allowed( array $payload ): bool {
		$entry_id = (int) ( $payload['entry_id'] ?? 0 );
		if ( 1 > $entry_id || ! class_exists( '\\GFAPI' ) ) {
			return false;
		}
		$entry = \GFAPI::get_entry( $entry_id );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $entry ) ) {
			return false;
		}
		$form_id = is_array( $entry ) ? (int) ( $entry['form_id'] ?? 0 ) : 0;
		$type    = (string) ( $payload['event_type'] ?? '' );
		if ( 'step' === $type ) {
			return CutoverRegistry::legacy_flow_step_allowed( $form_id, (int) ( $payload['step_id'] ?? 0 ) );
		}
		if ( 'workflow' === $type ) {
			return CutoverRegistry::legacy_workflow_allowed( $form_id );
		}
		return true;
	}

	private static function remove_flow_step_callbacks(): bool {
		$removed = false;
		if ( class_exists( '\\GFSMS\\Integration\\Listener' ) ) {
			$removed = remove_action( 'gravityflow_step_complete', array( '\\GFSMS\\Integration\\Listener', 'on_step_complete' ), 10 ) || $removed;
		}
		if ( class_exists( '\\GFSMS\\Integration\\Dispatcher' ) ) {
			$removed = remove_action( 'gravityflow_step_complete', array( \GFSMS\Integration\Dispatcher::instance(), 'handle_step_complete' ), 10 ) || $removed;
		}
		return $removed;
	}

	private static function add_flow_step_callbacks(): void {
		if ( class_exists( '\\GFSMS\\Integration\\Listener' ) ) {
			add_action( 'gravityflow_step_complete', array( '\\GFSMS\\Integration\\Listener', 'on_step_complete' ), 10, 5 );
		}
		if ( class_exists( '\\GFSMS\\Integration\\Dispatcher' ) ) {
			add_action( 'gravityflow_step_complete', array( \GFSMS\Integration\Dispatcher::instance(), 'handle_step_complete' ), 10, 5 );
		}
	}

	private static function remove_workflow_callbacks(): bool {
		$removed = false;
		if ( class_exists( '\\GFSMS\\Integration\\Listener' ) ) {
			$removed = remove_action( 'gravityflow_workflow_complete', array( '\\GFSMS\\Integration\\Listener', 'on_workflow_complete' ), 10 ) || $removed;
		}
		if ( class_exists( '\\GFSMS\\Integration\\Dispatcher' ) ) {
			$removed = remove_action( 'gravityflow_workflow_complete', array( \GFSMS\Integration\Dispatcher::instance(), 'handle_workflow_complete' ), 10 ) || $removed;
		}
		return $removed;
	}

	private static function add_workflow_callbacks(): void {
		if ( class_exists( '\\GFSMS\\Integration\\Listener' ) ) {
			add_action( 'gravityflow_workflow_complete', array( '\\GFSMS\\Integration\\Listener', 'on_workflow_complete' ), 10, 3 );
		}
		if ( class_exists( '\\GFSMS\\Integration\\Dispatcher' ) ) {
			add_action( 'gravityflow_workflow_complete', array( \GFSMS\Integration\Dispatcher::instance(), 'handle_workflow_complete' ), 10, 3 );
		}
	}

	private static function remove_queue_callback( string $hook, string $method ): bool {
		if ( ! class_exists( '\\GFSMS\\Queue\\Event_Queue' ) ) {
			return false;
		}
		return remove_action( $hook, array( \GFSMS\Queue\Event_Queue::instance(), $method ), 10 );
	}

	private static function add_queue_callback( string $hook, string $method ): void {
		if ( class_exists( '\\GFSMS\\Queue\\Event_Queue' ) ) {
			add_action( $hook, array( \GFSMS\Queue\Event_Queue::instance(), $method ), 10, 1 );
		}
	}
}
