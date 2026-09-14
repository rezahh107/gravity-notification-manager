<?php
/**
 * Safe result from an explicit sender-line discovery operation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Discovery;

/** Contains only bounded line values and safe diagnostics. */
final class SenderLineDiscoveryResult {

	private bool $successful;
	/** @var array<int, string> */
	private array $lines;
	private string $diagnostic;
	private ?int $http_status;

	/**
	 * @param bool               $successful Discovery success.
	 * @param array<int, string> $lines      Valid sender lines.
	 * @param string             $diagnostic Safe diagnostic token.
	 * @param int|null           $http_status Observed HTTP status.
	 */
	public function __construct( bool $successful, array $lines, string $diagnostic, ?int $http_status = null ) {
		$this->successful  = $successful;
		$this->lines       = array_values( array_unique( $lines ) );
		$this->diagnostic  = $diagnostic;
		$this->http_status = $http_status;
	}

	public function successful(): bool {
		return $this->successful;
	}

	/** @return array<int, string> */
	public function lines(): array {
		return $this->lines;
	}

	public function diagnostic(): string {
		return $this->diagnostic;
	}

	public function http_status(): ?int {
		return $this->http_status;
	}
}
