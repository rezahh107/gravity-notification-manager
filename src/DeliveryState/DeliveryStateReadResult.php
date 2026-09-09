<?php
/**
 * Entry Meta delivery-state read result.
 *
 * @package GravityNotify
 */

namespace GravityNotify\DeliveryState;

/**
 * Distinguishes missing, valid and malformed persisted delivery state.
 */
final class DeliveryStateReadResult {

	/**
	 * No delivery-state meta exists yet.
	 */
	public const MISSING = 'missing';

	/**
	 * Persisted state decoded successfully.
	 */
	public const VALID = 'valid';

	/**
	 * Persisted state exists but cannot be safely decoded.
	 */
	public const MALFORMED = 'malformed';

	/**
	 * Read status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Decoded state when valid.
	 *
	 * @var array<string, mixed>
	 */
	private array $state;

	/**
	 * Constructor.
	 *
	 * @param string               $status Read status.
	 * @param array<string, mixed> $state  Decoded state.
	 */
	private function __construct( string $status, array $state = array() ) {
		$this->status = $status;
		$this->state  = $state;
	}

	/**
	 * Missing state result.
	 *
	 * @return self
	 */
	public static function missing(): self {
		return new self( self::MISSING );
	}

	/**
	 * Valid decoded state result.
	 *
	 * @param array<string, mixed> $state Decoded state.
	 * @return self
	 */
	public static function valid( array $state ): self {
		return new self( self::VALID, $state );
	}

	/**
	 * Malformed state result.
	 *
	 * @return self
	 */
	public static function malformed(): self {
		return new self( self::MALFORMED );
	}

	/**
	 * Read status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Decoded state.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		return $this->state;
	}
}
