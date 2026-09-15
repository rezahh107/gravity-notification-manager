<?php
/**
 * Safe result from an explicit sender-line discovery operation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider\Discovery;

/** Contains only bounded line values and safe diagnostics. */
final class SenderLineDiscoveryResult {

	/**
	 * Whether discovery succeeded.
	 *
	 * @var bool
	 */
	private bool $successful;
	/**
	 * Valid discovered sender lines.
	 *
	 * @var array<int, string>
	 */
	private array $lines;
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
	 * Build an operator-safe sender discovery result.
	 *
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

	/** Return whether sender discovery succeeded. */
	public function successful(): bool {
		return $this->successful;
	}

	/**
	 * Return discovered sender lines.
	 *
	 * @return array<int, string>
	 */
	public function lines(): array {
		return $this->lines;
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
