<?php
/**
 * PHPUnit bootstrap for the greenfield test harness.
 *
 * @package GravityNotify
 */

if ( ! defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) ) {
	define( 'GRAVITY_NOTIFY_TEST_NO_SEND', true );
}

if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Intentional WordPress core test-safeguard constant.
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
}

// The unit suite intentionally runs without booting WordPress. Production code
// still uses native gettext; these identity stubs keep source-locale unit tests
// deterministic without faking locale/catalog behavior.
if ( ! function_exists( '__' ) ) {
	/**
	 * Return source text in the WordPress-free unit harness.
	 *
	 * @param string $text   Source text.
	 * @param string $domain Text domain.
	 * @return string Source text.
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress core test stub.
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Return escaped source text in the WordPress-free unit harness.
	 *
	 * @param string $text   Source text.
	 * @param string $domain Text domain.
	 * @return string Escaped source text.
	 */
	function esc_html__( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress core test stub.
		unset( $domain );
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
