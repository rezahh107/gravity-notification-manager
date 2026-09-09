<?php
/**
 * Gravity Flow admin navigation resolver.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Builds only the bounded Form Settings → Workflow admin target.
 */
final class GravityFlowNavigation {

	/**
	 * Return a relative wp-admin path when the Flow configuration target is usable.
	 *
	 * @param int  $form_id        Gravity Forms form ID.
	 * @param bool $flow_available Whether the supported local Gravity Flow API is available.
	 * @return string|null
	 */
	public static function relative_path( int $form_id, bool $flow_available ): ?string {
		if ( $form_id < 1 || ! $flow_available ) {
			return null;
		}

		return 'admin.php?page=gf_edit_forms&view=settings&subview=gravityflow&id=' . $form_id;
	}
}
