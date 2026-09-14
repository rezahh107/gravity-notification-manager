<?php
/**
 * Safe result from an explicit provider connection validation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Connection;

/** Contains only bounded operator-safe connection facts. */
final class ProviderConnectionResult {

	private bool $successful;
	private string $diagnostic;
	private ?int $http_status;

	public function __construct( bool $successful, string $diagnostic, ?int $http_status = null ) {
		$this->successful  = $successful;
		$this->diagnostic  = $diagnostic;
		$this->http_status = $http_status;
	}

	public function successful(): bool {
		return $this->successful;
	}

	public function diagnostic(): string {
		return $this->diagnostic;
	}

	public function http_status(): ?int {
		return $this->http_status;
	}
}
