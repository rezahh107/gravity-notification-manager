<?php
/**
 * Capability-aware SMS provider contract.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Sms;

use GravityNotify\Delivery\AttemptResult;

/**
 * Stable synchronous provider boundary for normalized SMS requests.
 */
interface SmsProviderInterface {

	/**
	 * Stable provider identifier.
	 *
	 * @return string
	 */
	public function identifier(): string;

	/**
	 * Supported capabilities.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array;

	/**
	 * Execute one synchronous normalized request.
	 *
	 * @param SmsRequest $request Normalized SMS request.
	 * @return AttemptResult
	 */
	public function send( SmsRequest $request ): AttemptResult;
}
