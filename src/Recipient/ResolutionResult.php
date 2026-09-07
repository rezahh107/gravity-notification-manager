<?php
/**
 * Recipient resolution result model.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient;

/**
 * Immutable value-oriented result for WU-03 recipient resolution.
 */
final class ResolutionResult {

	/**
	 * Requested channel.
	 *
	 * @var string
	 */
	private string $channel;

	/**
	 * Configured recipient source type.
	 *
	 * @var string
	 */
	private string $source_type;

	/**
	 * Stable resolved destination values.
	 *
	 * @var array<int, string>
	 */
	private array $destinations;

	/**
	 * Safe unresolved/skip classifications.
	 *
	 * @var array<int, array{subject:string,reason:string}>
	 */
	private array $skips;

	/**
	 * Constructor.
	 *
	 * @param string                                          $channel Requested channel.
	 * @param string                                          $source_type Configured recipient source type.
	 * @param array<int, string>                              $destinations Resolved destinations.
	 * @param array<int, array{subject:string,reason:string}> $skips Safe skip records.
	 */
	public function __construct( string $channel, string $source_type, array $destinations, array $skips ) {
		$this->channel      = $channel;
		$this->source_type  = $source_type;
		$this->destinations = array_values( array_unique( $destinations ) );
		$this->skips        = array_values( $skips );
	}

	/**
	 * Return the requested channel.
	 *
	 * @return string
	 */
	public function channel(): string {
		return $this->channel;
	}

	/**
	 * Return the configured source type.
	 *
	 * @return string
	 */
	public function source_type(): string {
		return $this->source_type;
	}

	/**
	 * Return resolved destinations in deterministic order.
	 *
	 * @return array<int, string>
	 */
	public function destinations(): array {
		return $this->destinations;
	}

	/**
	 * Return safe skip/unresolved records.
	 *
	 * @return array<int, array{subject:string,reason:string}>
	 */
	public function skips(): array {
		return $this->skips;
	}

	/**
	 * Whether at least one usable destination was resolved.
	 *
	 * @return bool
	 */
	public function has_destinations(): bool {
		return array() !== $this->destinations;
	}
}
