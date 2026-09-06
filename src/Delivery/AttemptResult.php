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
	 * Attempt status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Logical channel.
	 *
	 * @var string
	 */
	private string $channel;

	/**
	 * Provider identifier, when applicable.
	 *
	 * @var string|null
	 */
	private ?string $provider_id;

	/**
	 * Requested capability/mode, when applicable.
	 *
	 * @var string|null
	 */
	private ?string $capability;

	/**
	 * Safe documented provider references.
	 *
	 * @var array<int, string>
	 */
	private array $provider_references;

	/**
	 * Safe diagnostic classification.
	 *
	 * @var string
	 */
	private string $diagnostic;

	/**
	 * Build one immutable-by-interface attempt value.
	 *
	 * @param string             $status              Attempt status.
	 * @param string             $channel             Logical channel.
	 * @param string|null        $provider_id         Provider identifier.
	 * @param string|null        $capability          Requested capability.
	 * @param array<int, string> $provider_references Safe provider references.
	 * @param string             $diagnostic          Safe diagnostic classification.
	 */
	public function __construct(
		string $status,
		string $channel,
		?string $provider_id,
		?string $capability,
		array $provider_references,
		string $diagnostic
	) {
		if ( ! AttemptStatus::is_valid( $status ) ) {
			throw new InvalidArgumentException( 'Unsupported transport attempt status.' );
		}

		$this->status              = $status;
		$this->channel             = $channel;
		$this->provider_id         = $provider_id;
		$this->capability          = $capability;
		$this->provider_references = array_values( $provider_references );
		$this->diagnostic          = $diagnostic;
	}

	/**
	 * Attempt status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Logical channel.
	 *
	 * @return string
	 */
	public function channel(): string {
		return $this->channel;
	}

	/**
	 * Provider identifier.
	 *
	 * @return string|null
	 */
	public function provider_id(): ?string {
		return $this->provider_id;
	}

	/**
	 * Requested capability/mode.
	 *
	 * @return string|null
	 */
	public function capability(): ?string {
		return $this->capability;
	}

	/**
	 * Safe documented provider references.
	 *
	 * @return array<int, string>
	 */
	public function provider_references(): array {
		return $this->provider_references;
	}

	/**
	 * Safe diagnostic classification.
	 *
	 * @return string
	 */
	public function diagnostic(): string {
		return $this->diagnostic;
	}
}
