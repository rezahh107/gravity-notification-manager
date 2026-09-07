<?php
/**
 * WordPress user-function test doubles for native recipient adapters.
 *
 * @package GravityNotify
 */

if ( ! function_exists( 'get_users' ) ) {
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test double for the WordPress core get_users() function.
	/**
	 * Capture native get_users() arguments without loading WordPress.
	 *
	 * @param array $args WP_User_Query arguments.
	 * @return array<int, string>
	 */
	function get_users( array $args = array() ): array {
		return \GravityNotify\Tests\Unit\Recipient\WordPressUserDirectoryTest::capture_get_users_args( $args );
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}
