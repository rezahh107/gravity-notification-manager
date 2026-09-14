<?php
/**
 * Safe value object for one transport attempt.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery;

use InvalidArgumentException;

/** Carries only bounded, persistence-ready transport facts without secrets. */
final class AttemptResult {

	private string $status;
	private string $channel;
	private ?string $provider_id;
	private ?string $capability;
	/** @var array<int, mixed> */
	private array $provider_references;
	private string $diagnostic;
	private ?int $http_status;
	private ?string $sender;

	/**
	 * @param string      $status              Attempt status.
	 * @param string      $channel             Channel identifier.
	 * @param string|null $provider_id         Provider identifier.
	 * @param string|null $capability          SMS capability.
	 * @param array       $provider_references Safe provider references.
	 * @param string      $diagnostic          Safe diagnostic token.
	 * @param int|null    $http_status         Observed HTTP status.
	 * @param string|null $sender              Actual provider sender when known.
	 */
	public function __construct(
		string $status,
		string $channel,
		?string $provider_id,
		?string $capability,
		array $provider_references,
		string $diagnostic,
		?int $http_status = null,
		?string $sender = null
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
		$this->sender              = null === $sender || '' === trim( $sender ) ? null : trim( $sender );
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

	/** @return array<int, mixed> */
	public function provider_references(): array {
		return $this->provider_references;
	}

	public function diagnostic(): string {
		return $this->diagnostic;
	}

	public function http_status(): ?int {
		return $this->http_status;
	}

	/** Actual provider sender used by the adapter, when known. */
	public function sender(): ?string {
		return $this->sender;
	}
}
