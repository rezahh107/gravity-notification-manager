<?php
/**
 * Optional explicit provider connection-validation boundary.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Connection;

/** Provider administration capability intentionally separate from SMS delivery. */
interface ProviderConnectionInterface {

	/** Validate configured credentials after an explicit operator action. */
	public function check(): ProviderConnectionResult;
}
