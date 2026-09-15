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

	/**
	 * Normalized attempt status.
	 *
	 * @var string
	 */
	private string $status;
	/**
	 * Channel identifier.
	 *
	 * @var string
	 */
	private string $channel;
	/**
	 * Provider identifier when applicable.
	 *
	 * @var string|null
	 */
	private ?string $provider_id;
	/**
	 * SMS capability when applicable.
	 *
	 * @var string|null
	 */
	private ?string $capability;
	/**
	 * Safe provider references.
	 *
	 * @var array<int, mixed>
	 */
	private array $provider_references;
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
	 * Actual provider sender when known.
	 *
	 * @var string|null
	 */
	private ?string $sender;

	/**
	 * Build a bounded transport attempt result.
	 *
	 * @param string      $status              Attempt status.
	 * @param string      $channel             Channel identifier.
	 * @param string|null $provider_id         Provider identifier.
	 * @param string|null $capability          SMS capability.
	 * @param array       $provider_references Safe provider references.
	 * @param string      $diagnostic          Safe diagnostic token.
	 * @param int|null    $http_status         Observed HTTP status.
	 * @param string|null $sender              Actual provider sender when known.
	 * @throws InvalidArgumentException When status or HTTP status is unsupported.
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

	/** Return the normalized attempt status. */
	public function status(): string {
		return $this->status;
	}

	/** Return the channel identifier. */
	public function channel(): string {
		return $this->channel;
	}

	/** Return the provider identifier when applicable. */
	public function provider_id(): ?string {
		return $this->provider_id;
	}

	/** Return the SMS capability when applicable. */
	public function capability(): ?string {
		return $this->capability;
	}

	/**
	 * Return safe provider references.
	 *
	 * @return array<int, mixed>
	 */
	public function provider_references(): array {
		return $this->provider_references;
	}

	/** Return the safe diagnostic token. */
	public function diagnostic(): string {
		return $this->diagnostic;
	}

	/** Return the observed HTTP status when available. */
	public function http_status(): ?int {
		return $this->http_status;
	}

	/** Actual provider sender used by the adapter, when known. */
	public function sender(): ?string {
		return $this->sender;
	}
}
