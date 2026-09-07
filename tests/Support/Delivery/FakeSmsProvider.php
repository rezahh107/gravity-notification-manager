<?php
/**
 * Deterministic SMS provider fake.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Delivery;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\Sms\SmsCapability;
use GravityNotify\Delivery\Sms\SmsProviderInterface;
use GravityNotify\Delivery\Sms\SmsRequest;

/**
 * Captures synchronous SMS attempts without network I/O.
 */
final class FakeSmsProvider implements SmsProviderInterface {

	/**
	 * Configured attempt status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Provider identifier.
	 *
	 * @var string
	 */
	private string $identifier;

	/**
	 * Number of sends.
	 *
	 * @var int
	 */
	public int $send_count = 0;

	/**
	 * Last request.
	 *
	 * @var SmsRequest|null
	 */
	public ?SmsRequest $last_request = null;

	/**
	 * Constructor.
	 *
	 * @param string $status     Attempt status.
	 * @param string $identifier Provider ID.
	 */
	public function __construct( string $status, string $identifier = 'fake' ) {
		$this->status     = $status;
		$this->identifier = $identifier;
	}

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public function identifier(): string {
		return $this->identifier;
	}

	/**
	 * Supported plain-message capabilities.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array(
			SmsCapability::PLAIN,
			SmsCapability::MULTI_RECIPIENT_PLAIN,
		);
	}

	/**
	 * Record one synchronous attempt.
	 *
	 * @param SmsRequest $request Request.
	 * @return AttemptResult
	 */
	public function send( SmsRequest $request ): AttemptResult {
		++$this->send_count;
		$this->last_request = $request;

		return new AttemptResult(
			$this->status,
			'sms',
			$this->identifier,
			$request->capability(),
			array(),
			'test'
		);
	}
}
