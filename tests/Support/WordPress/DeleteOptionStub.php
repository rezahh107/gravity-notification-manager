<?php
/**
 * WordPress option deletion test stub for WordPress-free unit tests.
 *
 * @package GravityNotify
 */

/**
 * Record and remove an option from the isolated uninstall test state.
 *
 * @param string $option Option name.
 * @return bool Whether the option existed.
 */
function delete_option( string $option ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Intentional WordPress core test stub.
	$GLOBALS['gravity_notify_uninstall_deleted_options'][] = $option;
	if ( array_key_exists( $option, $GLOBALS['gravity_notify_uninstall_options'] ) ) {
		unset( $GLOBALS['gravity_notify_uninstall_options'][ $option ] );
		return true;
	}

	return false;
}
