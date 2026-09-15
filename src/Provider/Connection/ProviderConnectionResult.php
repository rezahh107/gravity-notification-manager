<?php
/**
 * Safe result from an explicit provider connection validation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Connection;

/** Contains only bounded operator-safe connection facts. */
final class ProviderConnectionResult {

	/**
	 * Whether validation succeeded.
	 *
	 * @var bool
	 */
	private bool $successful;
	/**
	 * Safe diagnostic token.
	 *
	 * @var string
	 */
	private string $diagnostic;
	/**
	 * Observed HTTP status when available.
	 *
	 * @var int|null
	 */
	private ?int $http_status;

	/**
	 * Build an operator-safe connection result.
	 *
	 * @param bool     $successful  Whether validation succeeded.
	 * @param string   $diagnostic  Safe diagnostic token.
	 * @param int|null $http_status Observed HTTP status when available.
	 */
	public function __construct( bool $successful, string $diagnostic, ?int $http_status = null ) {
		$this->successful  = $successful;
		$this->diagnostic  = $diagnostic;
		$this->http_status = $http_status;
	}

	/** Return whether connection validation succeeded. */
	public function successful(): bool {
		return $this->successful;
	}

	/** Return the safe diagnostic token. */
	public function diagnostic(): string {
		return $this->diagnostic;
	}

	/** Return the observed HTTP status when available. */
	public function http_status(): ?int {
		return $this->http_status;
	}
}
