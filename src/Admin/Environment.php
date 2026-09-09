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
	/** @return array<string,string> */
	public static function facts(): array {
		return array(
			'WordPress'     => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : 'Unavailable',
			'PHP'           => PHP_VERSION,
			'Gravity Forms' => self::availability( 'GFForms' ),
			'Gravity Flow'  => self::availability( 'Gravity_Flow' ),
			'GravityView'   => class_exists( 'GravityView_Plugin' ) || class_exists( 'GV\\Plugin' ) ? 'Available' : 'Not detected',
			'Feed/Flow'     => class_exists( '\\GFFeedAddOn' ) && class_exists( '\\Gravity_Flow_API' ) ? 'Available' : 'Unavailable',
		);
	}

	private static function availability( string $class_name ): string {
		return class_exists( $class_name ) ? 'Available' : 'Unavailable';
	}
}
