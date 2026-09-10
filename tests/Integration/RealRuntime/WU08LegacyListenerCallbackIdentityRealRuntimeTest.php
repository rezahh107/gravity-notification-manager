<?php
/**
 * Real WordPress hook-identity regression for the WU-08 legacy Flow guard.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\RealRuntime;

use GFSMS\Integration\Dispatcher;
use GFSMS\Integration\Listener;
use GravityNotify\Migration\CutoverRegistry;
use GravityNotify\Migration\CutoverSequence;
use GravityNotify\Migration\LegacyRuntimeGuard;
use WP_Hook;
use WP_UnitTestCase;

/**
 * Proves canonical legacy Listener callbacks are removed and restored symmetrically.
 */
final class WU08LegacyListenerCallbackIdentityRealRuntimeTest extends WP_UnitTestCase {

	/**
	 * Cutover option value before the current test.
	 *
	 * @var mixed
	 */
	private $cutover_before;

	/**
	 * Preserve registry state and isolate legacy Flow hooks.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->cutover_before = get_option( CutoverRegistry::OPTION, null );
		delete_option( CutoverRegistry::OPTION );
		$this->remove_legacy_flow_callbacks();
	}

	/**
	 * Restore hook and option state after each regression.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_legacy_flow_callbacks();
		if ( null === $this->cutover_before ) {
			delete_option( CutoverRegistry::OPTION );
		} else {
			update_option( CutoverRegistry::OPTION, $this->cutover_before, false );
		}
		parent::tear_down();
	}

	/**
	 * Prove Step Listener identity is absent while guarded and canonical after restore.
	 *
	 * @testdox WU08-LISTENER-IDENTITY-REAL-16 Step Listener callback identity is symmetric across repeated guard cycles
	 */
	public function test_wu08_listener_identity_real_16_step_listener_is_removed_and_restored_canonically(): void {
		$form_id = 82016;
		$step_id = 816;
		$this->set_disabled_flow_scope( 'flow_step', $form_id, $step_id );
		$this->exercise_guard_cycle(
			'gravityflow_step_complete',
			'on_step_complete',
			'handle_step_complete',
			'begin_flow_step',
			'restore_flow_step',
			array( $step_id, 91016, $form_id, 'approved', (object) array() )
		);
	}

	/**
	 * Prove workflow Listener identity is absent while guarded and canonical after restore.
	 *
	 * @testdox WU08-LISTENER-IDENTITY-REAL-17 Workflow Listener callback identity is symmetric across repeated guard cycles
	 */
	public function test_wu08_listener_identity_real_17_workflow_listener_is_removed_and_restored_canonically(): void {
		$form_id = 82017;
		$this->set_disabled_flow_scope( 'flow_workflow', $form_id, 0 );
		$this->exercise_guard_cycle(
			'gravityflow_workflow_complete',
			'on_workflow_complete',
			'handle_workflow_complete',
			'begin_workflow',
			'restore_workflow',
			array( 91017, array( 'id' => $form_id ), 'complete' )
		);
	}

	/**
	 * Exercise one real WordPress hook through two complete guard/restore cycles.
	 *
	 * @param string       $hook              Flow hook.
	 * @param string       $listener_method   Legacy Listener method.
	 * @param string       $dispatcher_method Legacy Dispatcher method.
	 * @param string       $begin_method      Guard begin method.
	 * @param string       $restore_method    Guard restore method.
	 * @param array<mixed> $args              Hook arguments.
	 * @return void
	 */
	private function exercise_guard_cycle(
		string $hook,
		string $listener_method,
		string $dispatcher_method,
		string $begin_method,
		string $restore_method,
		array $args
	): void {
		self::assertTrue( function_exists( 'gravityflow' ), 'Real Gravity Flow must be active for the legacy Listener registration path.' );

		Listener::register_hooks();
		$dispatcher = Dispatcher::instance();
		$dispatcher->register_hooks();

		$canonical_listener = array( Listener::class, $listener_method );
		$alternate_listener = array( '\\GFSMS\\Integration\\Listener', $listener_method );
		$dispatcher_callback = array( $dispatcher, $dispatcher_method );
		$accepted_args        = count( $args );

		self::assertSame( 'GFSMS\\Integration\\Listener', Listener::class );
		self::assertSame( 10, has_action( $hook, $canonical_listener ) );
		self::assertSame( 10, has_action( $hook, $dispatcher_callback ) );
		self::assertFalse( has_action( $hook, $alternate_listener ) );
		$this->assert_exactly_one_callback( $hook, $canonical_listener, $accepted_args );

		add_action( $hook, array( LegacyRuntimeGuard::class, $begin_method ), 1, $accepted_args );
		add_action( $hook, array( LegacyRuntimeGuard::class, $restore_method ), 11, $accepted_args );

		$guarded_observations = 0;
		$observer             = function () use ( $hook, $canonical_listener, $alternate_listener, $dispatcher_callback, &$guarded_observations ): void {
			++$guarded_observations;
			self::assertFalse( has_action( $hook, $canonical_listener ), 'Canonical legacy Listener survived the guarded Flow scope.' );
			self::assertFalse( has_action( $hook, $dispatcher_callback ), 'Legacy Dispatcher survived the guarded Flow scope.' );
			self::assertFalse( has_action( $hook, $alternate_listener ), 'Leading-backslash alternate Listener identity exists while guarded.' );
		};
		add_action( $hook, $observer, 5, 0 );

		try {
			do_action_ref_array( $hook, $args );
			self::assertSame( 10, has_action( $hook, $canonical_listener ) );
			self::assertSame( 10, has_action( $hook, $dispatcher_callback ) );
			self::assertFalse( has_action( $hook, $alternate_listener ) );
			$this->assert_exactly_one_callback( $hook, $canonical_listener, $accepted_args );

			do_action_ref_array( $hook, $args );
			self::assertSame( 2, $guarded_observations );
			self::assertSame( 10, has_action( $hook, $canonical_listener ) );
			self::assertSame( 10, has_action( $hook, $dispatcher_callback ) );
			self::assertFalse( has_action( $hook, $alternate_listener ) );
			$this->assert_exactly_one_callback( $hook, $canonical_listener, $accepted_args );
		} finally {
			remove_action( $hook, $observer, 5 );
			remove_action( $hook, array( LegacyRuntimeGuard::class, $begin_method ), 1 );
			remove_action( $hook, array( LegacyRuntimeGuard::class, $restore_method ), 11 );
		}
	}

	/**
	 * Persist one synthetic disabled Flow authority record for the guard under test.
	 *
	 * @param string $source_type    Flow source type.
	 * @param int    $form_id        Form ID.
	 * @param int    $legacy_step_id Legacy Step ID or zero for workflow-complete.
	 * @return void
	 */
	private function set_disabled_flow_scope( string $source_type, int $form_id, int $legacy_step_id ): void {
		$scope_id = sprintf( 'run032:%s:%d:%d', $source_type, $form_id, $legacy_step_id );
		$record   = array(
			'version'             => 1,
			'source_type'         => $source_type,
			'form_id'             => $form_id,
			'legacy_rule_index'   => -1,
			'legacy_fingerprint'  => '',
			'legacy_step_id'      => $legacy_step_id,
			'feed_id'             => 92000 + $form_id,
			'target_flow_step_id' => 93000 + $form_id,
			'state'               => CutoverSequence::LEGACY_DISABLED,
		);
		self::assertTrue( update_option( CutoverRegistry::OPTION, array( $scope_id => $record ), false ) );
		self::assertFalse(
			'flow_step' === $source_type
				? CutoverRegistry::legacy_flow_step_allowed( $form_id, $legacy_step_id )
				: CutoverRegistry::legacy_workflow_allowed( $form_id )
		);
	}

	/**
	 * Assert WordPress stores exactly one canonical callback with the expected arity.
	 *
	 * @param string            $hook          Hook name.
	 * @param array<int, mixed> $callback      Exact callback.
	 * @param int               $accepted_args Accepted argument count.
	 * @return void
	 */
	private function assert_exactly_one_callback( string $hook, array $callback, int $accepted_args ): void {
		global $wp_filter;

		self::assertArrayHasKey( $hook, $wp_filter );
		self::assertInstanceOf( WP_Hook::class, $wp_filter[ $hook ] );
		$callbacks = $wp_filter[ $hook ]->callbacks[10] ?? array();
		$matches   = array_filter(
			$callbacks,
			static fn( array $registered ): bool => ( $registered['function'] ?? null ) === $callback
		);
		self::assertCount( 1, $matches, 'Canonical Listener callback must exist exactly once.' );
		$registered = reset( $matches );
		self::assertIsArray( $registered );
		self::assertSame( $accepted_args, $registered['accepted_args'] ?? null );
	}

	/**
	 * Remove all callbacks this test may register, including the invalid alternate identity.
	 *
	 * @return void
	 */
	private function remove_legacy_flow_callbacks(): void {
		$dispatcher = Dispatcher::instance();
		remove_action( 'gravityflow_step_complete', array( Listener::class, 'on_step_complete' ), 10 );
		remove_action( 'gravityflow_step_complete', array( '\\GFSMS\\Integration\\Listener', 'on_step_complete' ), 10 );
		remove_action( 'gravityflow_workflow_complete', array( Listener::class, 'on_workflow_complete' ), 10 );
		remove_action( 'gravityflow_workflow_complete', array( '\\GFSMS\\Integration\\Listener', 'on_workflow_complete' ), 10 );
		remove_action( 'gravityflow_workflow_started', array( Listener::class, 'on_workflow_started' ), 10 );
		remove_action( 'gravityflow_step_complete', array( $dispatcher, 'handle_step_complete' ), 10 );
		remove_action( 'gravityflow_workflow_complete', array( $dispatcher, 'handle_workflow_complete' ), 10 );
		remove_action( 'gravityflow_step_complete', array( LegacyRuntimeGuard::class, 'begin_flow_step' ), 1 );
		remove_action( 'gravityflow_step_complete', array( LegacyRuntimeGuard::class, 'restore_flow_step' ), 11 );
		remove_action( 'gravityflow_workflow_complete', array( LegacyRuntimeGuard::class, 'begin_workflow' ), 1 );
		remove_action( 'gravityflow_workflow_complete', array( LegacyRuntimeGuard::class, 'restore_workflow' ), 11 );
	}
}
