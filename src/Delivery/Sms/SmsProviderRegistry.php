<?php
/**
 * Ordered SMS provider registry.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Sms;

use InvalidArgumentException;

/**
 * Preserves configured order and performs capability checks without I/O.
 */
final class SmsProviderRegistry {

	/**
	 * Ordered providers.
	 *
	 * @var array<int, SmsProviderInterface>
	 */
	private array $providers;

	/**
	 * Register providers in configured order.
	 *
	 * @param array<int, SmsProviderInterface> $providers Ordered providers.
	 */
	public function __construct( array $providers ) {
		$seen = array();

		foreach ( $providers as $provider ) {
			$identifier = $provider->identifier();

			if ( isset( $seen[ $identifier ] ) ) {
				throw new InvalidArgumentException( 'Duplicate SMS provider identifier.' );
			}

			$seen[ $identifier ] = true;
		}

		$this->providers = array_values( $providers );
	}

	/**
	 * Return providers in configured order.
	 *
	 * @return array<int, SmsProviderInterface>
	 */
	public function providers(): array {
		return $this->providers;
	}

	/**
	 * Determine whether a provider supports the exact requested capability.
	 *
	 * @param SmsProviderInterface $provider   Provider.
	 * @param string               $capability Requested capability.
	 * @return bool
	 */
	public function supports( SmsProviderInterface $provider, string $capability ): bool {
		return in_array( $capability, $provider->capabilities(), true );
	}
}
