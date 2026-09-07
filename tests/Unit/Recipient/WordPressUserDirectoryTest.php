<?php
/**
 * Tests for the native WordPress user directory adapter.
 *
 * @package GravityNotify
 */

namespace {
	if ( ! function_exists( 'get_users' ) ) {
		/**
		 * Capture the native adapter's WordPress user-query arguments.
		 *
		 * @param array $args WP_User_Query arguments.
		 * @return array<int, string>
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test double for the WordPress core get_users() function.
		function get_users( array $args = array() ): array {
			return \GravityNotify\Tests\Unit\Recipient\WordPressUserDirectoryTest::capture_get_users_args( $args );
		}
	}
}

namespace GravityNotify\Tests\Unit\Recipient {

	use GravityNotify\Recipient\Native\WordPressUserDirectory;
	use PHPUnit\Framework\TestCase;

	/**
	 * Verifies the native role-query contract without loading WordPress.
	 */
	final class WordPressUserDirectoryTest extends TestCase {

		/**
		 * Last arguments supplied to the get_users() test double.
		 *
		 * @var array<string, mixed>
		 */
		private static array $get_users_args = array();

		/**
		 * Reset captured arguments between tests.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();
			self::$get_users_args = array();
		}

		/**
		 * Capture one native get_users() call and return deterministic IDs.
		 *
		 * @param array<string, mixed> $args WP_User_Query arguments.
		 * @return array<int, string>
		 */
		public static function capture_get_users_args( array $args ): array {
			self::$get_users_args = $args;
			return array( '31', '33' );
		}

		/**
		 * Role queries use the documented ID selector and deterministic ordering.
		 *
		 * @return void
		 */
		public function test_role_query_uses_documented_id_field_and_deterministic_order(): void {
			$directory = new WordPressUserDirectory();

			self::assertSame( array( 31, 33 ), $directory->find_user_ids_by_role( 'reviewer' ) );
			self::assertSame(
				array(
					'role'    => 'reviewer',
					'fields'  => 'ID',
					'orderby' => 'ID',
					'order'   => 'ASC',
				),
				self::$get_users_args
			);
		}
	}
}
