<?php
/**
 * Bale outbound channel boundary.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Bale;

use GravityNotify\Delivery\AttemptResult;

/**
 * Keeps Bale outside the SMS provider registry.
 */
interface BaleChannelInterface {

	/**
	 * Send one synchronous outbound Bale message.
	 *
	 * @param BaleRequest $request Normalized Bale request.
	 * @return AttemptResult
	 */
	public function send( BaleRequest $request ): AttemptResult;
}
