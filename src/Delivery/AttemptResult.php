<?php
/**
 * Safe value object for one transport attempt.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery;

use InvalidArgumentException;

/**
 * Carries only bounded, persistence-ready transport facts without secrets.
 */
final class AttemptResult {

	private string $status;
	private string $channel;
	private ?string $provider_id;
	private ?string $capability;
	/** @var array<int, string> */
	private array $provider_references;
	private string $diagnostic;
	private ?int $http_status;

	/**
	 * Build one immutable-by-interface attempt value.
	 *
	 * @param string             $status              Attempt status.
	 * @param string             $channel             Logical channel.
	 * @param string|null        $provider_id         Provider identifier.
	 * @param string|null        $capability          Requested capability.
	 * @param array<int, string> $provider_references Safe provider references.
	 * @param string             $diagnostic          Safe diagnostic classification.
	 * @param int|null           $http_status         Observed HTTP response status, when established.
	 * @throws InvalidArgumentException When the attempt status or HTTP status is unsupported.
	 */
	public function __construct(
		string $status,
		string $channel,
		?string $provider_id,
		?string $capability,
		array $provider_references,
		string $diagnostic,
		?int $http_status = null
	) {
		if ( ! AttemptStatus::is_valid( $status ) ) {
			throw new InvalidArgumentException( 'Unsupported transport attempt status.' );
		}
		if ( null !== $http_status && ( 100 > $http_status || 599 < $http_status ) ) {
			throw new InvalidArgumentException( 'Unsupported transport HTTP status.' );
		}

		$this->status              = $status;
		$this->channel             = $channel;
		$this->provider_id         = $provider_id;
		$this->capability          = $capability;
		$this->provider_references = array_values( $provider_references );
		$this->diagnostic          = $diagnostic;
		$this->http_status         = $http_status;
	}

	public function status(): string {
		return $this->status;
	}

	public function channel(): string {
		return $this->channel;
	}

	public function provider_id(): ?string {
		return $this->provider_id;
	}

	public function capability(): ?string {
		return $this->capability;
	}

	/** @return array<int, string> */
	public function provider_references(): array {
		return $this->provider_references;
	}

	public function diagnostic(): string {
		return $this->diagnostic;
	}

	/** Observed HTTP status without inferring one for transport failures. */
	public function http_status(): ?int {
		return $this->http_status;
	}
}
