<?php
/**
 * Read-only current-configuration source for WU-06 admin inspection.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Exposes only read operations; topology mutation is intentionally absent.
 */
interface ConfigurationSourceInterface {
	/** @return array<int, array{id:int,title:string}> */
	public function forms(): array;

	/** @return array<int, array<string,mixed>> */
	public function feeds( int $form_id ): array;

	/**
	 * Return GNM Feed-Step placements, or null when Gravity Flow is unavailable.
	 *
	 * @param int             $form_id Form ID.
	 * @param array<int, int> $feed_ids Feed IDs to inspect.
	 * @return array<int, array{step_id:int,step_name:string,feed_ids:array<int,int>}>|null
	 */
	public function workflow_placements( int $form_id, array $feed_ids ): ?array;
}
