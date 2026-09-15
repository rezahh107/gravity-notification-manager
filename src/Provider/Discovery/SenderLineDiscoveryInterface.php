<?php
/**
 * Optional explicit sender-line discovery boundary.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Discovery;

/** Provider administration capability intentionally separate from SMS delivery. */
interface SenderLineDiscoveryInterface {

	/** Fetch currently authorized sender lines after an explicit operator action. */
	public function discover(): SenderLineDiscoveryResult;
}
