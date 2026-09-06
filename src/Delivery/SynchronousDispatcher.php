<?php
/**
 * Ordered synchronous transport dispatcher.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery;

use GravityNotify\Delivery\Bale\BaleChannelInterface;
use GravityNotify\Delivery\Bale\BaleRequest;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\Sms\SmsRequest;

/**
 * Executes capability-aware SMS fallback and optional Bale fallback in order.
 */
final class SynchronousDispatcher {

	/**
	 * Ordered SMS provider registry.
	 *
	 * @var SmsProviderRegistry
	 */
	private SmsProviderRegistry $registry;

	/**
	 * Optional separate Bale channel.
	 *
	 * @var BaleChannelInterface|null
	 */
	private ?BaleChannelInterface $bale;

	/**
	 * Create dispatcher.
	 *
	 * @param SmsProviderRegistry       $registry Ordered SMS providers.
	 * @param BaleChannelInterface|null $bale     Optional Bale fallback channel.
	 */
	public function __construct( SmsProviderRegistry $registry, ?BaleChannelInterface $bale = null ) {
		$this->registry = $registry;
		$this->bale     = $bale;
	}

	/**
	 * Dispatch one normalized SMS request synchronously.
	 *
	 * Bale is attempted only when explicitly permitted and a normalized Bale
	 * request plus channel implementation are both present.
	 *
	 * @param SmsRequest       $request             Normalized SMS request.
	 * @param bool             $allow_bale_fallback Whether Bale fallback is logically permitted.
	 * @param BaleRequest|null $bale_request        Already-resolved Bale fallback request.
	 * @return array<int, AttemptResult>
	 */
	public function dispatch_sms(
		SmsRequest $request,
		bool $allow_bale_fallback = false,
		?BaleRequest $bale_request = null
	): array {
		$attempts = array();

		foreach ( $this->registry->providers() as $provider ) {
			if ( ! $this->registry->supports( $provider, $request->capability() ) ) {
				$attempts[] = new AttemptResult(
					AttemptStatus::SKIPPED,
					'sms',
					$provider->identifier(),
					$request->capability(),
					array(),
					'unsupported_capability'
				);
				continue;
			}

			$attempt = $provider->send( $request );
			$attempts[] = $attempt;

			if ( AttemptStatus::SUCCESS === $attempt->status() ) {
				return $attempts;
			}
		}

		if ( $allow_bale_fallback && null !== $this->bale && null !== $bale_request ) {
			$attempts[] = $this->bale->send( $bale_request );
		}

		return $attempts;
	}
}
