<?php
/**
 * Native WordPress user/contact directory adapter.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient\Native;

use GravityNotify\Recipient\UserDirectory;

/**
 * Uses only supported WordPress user, role, and user-meta APIs.
 */
final class WordPressUserDirectory implements UserDirectory {

	/**
	 * Resolve one configured user selector through WordPress user APIs.
	 *
	 * @param string $selector Configured user selector.
	 * @return int|null
	 */
	public function find_user_id( string $selector ): ?int {
		$selector = trim( $selector );
		if ( '' === $selector || ! function_exists( 'get_user_by' ) ) {
			return null;
		}

		if ( ctype_digit( $selector ) && 0 < (int) $selector ) {
			$user = get_user_by( 'id', (int) $selector );
		} elseif ( false !== strpos( $selector, '@' ) ) {
			$user = get_user_by( 'email', $selector );
		} else {
			$user = get_user_by( 'login', $selector );
			if ( false === $user ) {
				$user = get_user_by( 'slug', $selector );
			}
		}

		return is_object( $user ) && isset( $user->ID ) && 0 < (int) $user->ID ? (int) $user->ID : null;
	}

	/**
	 * Resolve matching user IDs for one WordPress role.
	 *
	 * @param string $role Role slug.
	 * @return array<int, int>
	 */
	public function find_user_ids_by_role( string $role ): array {
		$role = trim( $role );
		if ( '' === $role || ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = get_users(
			array(
				'role'    => $role,
				'fields'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		return is_array( $users ) ? array_map( 'intval', $users ) : array();
	}

	/**
	 * Read one channel-specific contact meta value.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $meta_key Closed WU-03 contact meta key.
	 * @return mixed
	 */
	public function get_contact( int $user_id, string $meta_key ) {
		if ( 0 >= $user_id || ! function_exists( 'get_user_meta' ) ) {
			return null;
		}

		return get_user_meta( $user_id, $meta_key, true );
	}
}
