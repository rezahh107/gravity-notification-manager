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

	/**
	 * Stored value.
	 *
	 * @var string
	 */
	private string $status;
	/**
	 * Stored value.
	 *
	 * @var string
	 */
	private string $channel;
	/**
	 * Stored value.
	 *
	 * @var string|null
	 */
	private ?string $provider_id;
	/**
	 * Stored value.
	 *
	 * @var string|null
	 */
	private ?string $capability;
		/**
		 * Stored value.
		 *
		 * @var array<int,
		 */
	private array $provider_references;
	/**
	 * Stored value.
	 *
	 * @var string
	 */
	private string $diagnostic;
	/**
	 * Stored value.
	 *
	 * @var int|null
	 */
	private ?int $http_status;

		/**
		 * Construct the object.
		 *
		 * @param string      $status Value.
		 * @param string      $channel Value.
		 * @param string|null $provider_id Value.
		 * @param string|null $capability Value.
		 * @param array       $provider_references Value.
		 * @param string      $diagnostic Value.
		 * @param int|null    $http_status Value.
		 * @throws \InvalidArgumentException When supplied data is invalid.
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

	/**
	 * Status.
	 *
	 * @return string Return value.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Channel.
	 *
	 * @return string Return value.
	 */
	public function channel(): string {
		return $this->channel;
	}

	/**
	 * Provider id.
	 *
	 * @return string|null Return value.
	 */
	public function provider_id(): ?string {
		return $this->provider_id;
	}

	/**
	 * Capability.
	 *
	 * @return string|null Return value.
	 */
	public function capability(): ?string {
		return $this->capability;
	}

		/**
		 * Provider references.
		 *
		 * @return array Return value.
		 */
	public function provider_references(): array {
		return $this->provider_references;
	}

	/**
	 * Diagnostic.
	 *
	 * @return string Return value.
	 */
	public function diagnostic(): string {
		return $this->diagnostic;
	}

		/**
		 * Http status.
		 *
		 * @return int|null Return value.
		 */
	public function http_status(): ?int {
		return $this->http_status;
	}
}
