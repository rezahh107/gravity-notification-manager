<?php
/**
 * Deterministic user/contact directory fake.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Recipient;

use GravityNotify\Recipient\UserDirectory;

/**
 * Returns configured users, role memberships, and contact metadata only.
 */
final class FakeUserDirectory implements UserDirectory {

	/**
	 * User selector map.
	 *
	 * @var array<string, int>
	 */
	private array $selectors;

	/**
	 * Role membership map.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $roles;

	/**
	 * Contact meta map.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $contacts;

	/**
	 * Constructor.
	 *
	 * @param array<string, int>               $selectors User selector map.
	 * @param array<string, array<int, int>>   $roles Role membership map.
	 * @param array<int, array<string, mixed>> $contacts Contact meta map.
	 */
	public function __construct( array $selectors, array $roles, array $contacts ) {
		$this->selectors = $selectors;
		$this->roles      = $roles;
		$this->contacts   = $contacts;
	}

	/**
	 * Resolve one fake user selector.
	 *
	 * @param string $selector User selector.
	 * @return int|null
	 */
	public function find_user_id( string $selector ): ?int {
		return $this->selectors[ $selector ] ?? null;
	}

	/**
	 * Resolve fake role members.
	 *
	 * @param string $role Role slug.
	 * @return array<int, int>
	 */
	public function find_user_ids_by_role( string $role ): array {
		return $this->roles[ $role ] ?? array();
	}

	/**
	 * Read one fake contact meta value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $meta_key Contact meta key.
	 * @return mixed
	 */
	public function get_contact( int $user_id, string $meta_key ) {
		return $this->contacts[ $user_id ][ $meta_key ] ?? null;
	}
}
