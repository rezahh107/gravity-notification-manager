<?php
/**
 * Privacy-safe admin environment facts.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Detects product availability without network calls or sensitive payloads.
 */
final class Environment {

	/**
	 * Return bounded environment and integration availability facts.
	 *
	 * @return array<string, string>
	 */
	public static function facts(): array {
		return array(
			__( 'WordPress', 'gravity-notification-manager' )     => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : __( 'Unavailable', 'gravity-notification-manager' ),
			__( 'PHP', 'gravity-notification-manager' )           => PHP_VERSION,
			__( 'Gravity Forms', 'gravity-notification-manager' ) => self::availability( 'GFForms' ),
			__( 'Gravity Flow', 'gravity-notification-manager' )  => self::availability( 'Gravity_Flow' ),
			__( 'GravityView', 'gravity-notification-manager' )   => class_exists( 'GravityView_Plugin' ) || class_exists( 'GV\\Plugin' ) ? __( 'Available', 'gravity-notification-manager' ) : __( 'Not detected', 'gravity-notification-manager' ),
			__( 'Feed/Flow', 'gravity-notification-manager' )     => class_exists( '\\GFFeedAddOn' ) && class_exists( '\\Gravity_Flow_API' ) ? __( 'Available', 'gravity-notification-manager' ) : __( 'Unavailable', 'gravity-notification-manager' ),
		);
	}

	/**
	 * Convert one class-availability check to operator-safe text.
	 *
	 * @param string $class_name Class name to inspect.
	 * @return string
	 */
	private static function availability( string $class_name ): string {
		return class_exists( $class_name ) ? __( 'Available', 'gravity-notification-manager' ) : __( 'Unavailable', 'gravity-notification-manager' );
	}
}