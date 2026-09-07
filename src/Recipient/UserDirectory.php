<?php
/**
 * WordPress user/contact access seam for recipient resolution.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient;

/**
 * Provides only the user identity and contact lookups WU-03 requires.
 */
interface UserDirectory {

	/**
	 * Resolve a configured user selector to one WordPress user ID.
	 *
	 * @param string $selector Configured user selector.
	 * @return int|null
	 */
	public function find_user_id( string $selector ): ?int;

	/**
	 * Resolve a WordPress role to matching user IDs.
	 *
	 * @param string $role Role slug.
	 * @return array<int, int>
	 */
	public function find_user_ids_by_role( string $role ): array;

	/**
	 * Read one closed contact-meta value.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $meta_key Closed WU-03 contact meta key.
	 * @return mixed
	 */
	public function get_contact( int $user_id, string $meta_key );
}
