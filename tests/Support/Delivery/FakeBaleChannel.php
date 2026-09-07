<?php
/**
 * Deterministic Bale channel fake.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Delivery;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\Bale\BaleChannelInterface;
use GravityNotify\Delivery\Bale\BaleRequest;

/**
 * Captures synchronous Bale attempts without network I/O.
 */
final class FakeBaleChannel implements BaleChannelInterface {

	/**
	 * Configured attempt status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Number of sends.
	 *
	 * @var int
	 */
	public int $send_count = 0;

	/**
	 * Last request.
	 *
	 * @var BaleRequest|null
	 */
	public ?BaleRequest $last_request = null;

	/**
	 * Constructor.
	 *
	 * @param string $status Attempt status.
	 */
	public function __construct( string $status ) {
		$this->status = $status;
	}

	/**
	 * Record one synchronous attempt.
	 *
	 * @param BaleRequest $request Request.
	 * @return AttemptResult
	 */
	public function send( BaleRequest $request ): AttemptResult {
		++$this->send_count;
		$this->last_request = $request;

		return new AttemptResult(
			$this->status,
			'bale',
			null,
			null,
			array(),
			'test'
		);
	}
}
