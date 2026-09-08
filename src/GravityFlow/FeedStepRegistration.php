<?php
/**
 * Optional Gravity Flow Feed-Step registration bridge.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityFlow;

/**
 * Registers the GNM Feed Step only after Gravity Flow exposes its public base.
 */
final class FeedStepRegistration {

	/**
	 * Whether the step has been registered in this request.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Whether a gravityflow_loaded callback has been installed.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Register now when possible, otherwise defer to gravityflow_loaded.
	 *
	 * @return bool Whether registration is available now or safely deferred.
	 */
	public static function boot(): bool {
		if ( self::contract_available() ) {
			return self::register();
		}

		if ( ! function_exists( 'add_action' ) ) {
			return false;
		}

		if ( ! self::$hooked ) {
			add_action( 'gravityflow_loaded', array( self::class, 'register' ) );
			self::$hooked = true;
		}

		return true;
	}

	/**
	 * Register the step through Gravity Flow's public step registry.
	 *
	 * @return bool Whether the step is registered.
	 */
	public static function register(): bool {
		if ( self::$registered ) {
			return true;
		}

		if ( ! self::contract_available() ) {
			return false;
		}

		\Gravity_Flow_Steps::register( new NotificationFeedStep() );
		self::$registered = true;
		return true;
	}

	/**
	 * Whether the supported Gravity Flow Feed-Step surface is loaded.
	 *
	 * @return bool
	 */
	private static function contract_available(): bool {
		return class_exists( '\Gravity_Flow_Step_Feed_Add_On' )
			&& class_exists( '\Gravity_Flow_Steps' );
	}
}
