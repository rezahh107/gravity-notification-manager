<?php
/**
 * Explicit operator-triggered provider/channel testing.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Bale\BaleClient;
use GravityNotify\Delivery\Bale\BaleRequest;
use GravityNotify\Delivery\Http\HttpTransportInterface;
use GravityNotify\Delivery\Http\WordPressHttpTransport;
use GravityNotify\Delivery\Sms\SmsCapability;
use GravityNotify\Delivery\Sms\SmsRequest;
use GravityNotify\Observability\OperationalContext;
use GravityNotify\Observability\OperationalLogger;
use GravityNotify\Provider\SmsProviderManager;
use InvalidArgumentException;

/**
 * Sends bounded configuration tests directly through existing production providers.
 *
 * This service intentionally does not use Feed processing, Retry, the dispatcher,
 * or delivery-state persistence. Observability is best-effort evidence only.
 */
final class ProviderTestService {

		/**
		 * Stored value.
		 *
		 * @var array<string,
		 */
	private array $settings;
	/**
	 * Stored value.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;
	/**
	 * Stored value.
	 *
	 * @var OperationalLogger|null
	 */
	private ?OperationalLogger $operational_log;

		/**
		 * Construct the object.
		 *
		 * @param array                  $settings Value.
		 * @param HttpTransportInterface $http Value.
		 * @param OperationalLogger|null $operational_log Value.
		 * @throws \InvalidArgumentException When supplied data is invalid.
		 */
	public function __construct( array $settings, HttpTransportInterface $http, ?OperationalLogger $operational_log = null ) {
		$this->settings        = $settings;
		$this->http            = $http;
		$this->operational_log = $operational_log;
	}

	/**
	 * Production.
	 *
	 * @return self Return value.
	 */
	public static function production(): self {
		return new self( Settings::read(), new WordPressHttpTransport(), OperationalLogger::production() );
	}

	/**
	 * Test sms.
	 *
	 * @param string $destination Value.
	 * @param string $message Value.
	 * @return AttemptResult Return value.
	 */
	public function test_sms( string $destination, string $message ): AttemptResult {
		$context  = new OperationalContext( OperationalContext::EXECUTION_TEST );
		$manager  = new SmsProviderManager( $this->settings );
		$from     = $manager->configured_sender( SmsProviderManager::IPPANEL );
		$provider = $manager->provider( SmsProviderManager::IPPANEL, $this->http );

		if ( null === $provider || ! self::is_e164( $from ) ) {
			return $this->observed_test_result(
				$context,
				$this->failure( 'sms', SmsProviderManager::IPPANEL, SmsCapability::PLAIN, 'provider_not_configured' ),
				array( $destination ),
				$from
			);
		}

		$destination = trim( $destination );
		if ( ! self::is_e164( $destination ) ) {
			return $this->observed_test_result(
				$context,
				$this->failure( 'sms', SmsProviderManager::IPPANEL, SmsCapability::PLAIN, 'invalid_destination' ),
				array( $destination ),
				$from
			);
		}

		try {
			$request = SmsRequest::plain( SmsCapability::PLAIN, array( $destination ), $from, $message );
		} catch ( InvalidArgumentException $exception ) {
			unset( $exception );
			return $this->observed_test_result(
				$context,
				$this->failure( 'sms', SmsProviderManager::IPPANEL, SmsCapability::PLAIN, 'invalid_test_request' ),
				array( $destination ),
				$from
			);
		}

		return $this->observed_test_result( $context, $provider->send( $request ), array( $destination ), $from );
	}

	/**
	 * Test bale.
	 *
	 * @param string $destination Value.
	 * @param string $message Value.
	 * @return AttemptResult Return value.
	 */
	public function test_bale( string $destination, string $message ): AttemptResult {
		$context = new OperationalContext( OperationalContext::EXECUTION_TEST );
		$token   = $this->settings['bale_bot_token'] ?? '';
		if ( ! is_string( $token ) || '' === $token ) {
			return $this->observed_test_result(
				$context,
				$this->failure( 'bale', null, null, 'provider_not_configured' ),
				array( $destination )
			);
		}

		$destination = self::bale_destination( $destination );
		if ( null === $destination ) {
			return $this->observed_test_result(
				$context,
				$this->failure( 'bale', null, null, 'invalid_destination' ),
				array()
			);
		}

		try {
			$request = new BaleRequest( $destination, $message );
		} catch ( InvalidArgumentException $exception ) {
			unset( $exception );
			return $this->observed_test_result(
				$context,
				$this->failure( 'bale', null, null, 'invalid_test_request' ),
				array( $destination )
			);
		}

		$result = ( new BaleClient( $token, $this->http ) )->send( $request );
		return $this->observed_test_result( $context, $result, array( $destination ) );
	}

	/**
	 * Is e164.
	 *
	 * @param string $value Value.
	 * @return bool Return value.
	 */
	private static function is_e164( string $value ): bool {
		return 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $value );
	}

	/**
	 * Bale destination.
	 *
	 * @param string $value Value.
	 * @return string|null Return value.
	 */
	private static function bale_destination( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value || 128 < strlen( $value ) || 1 === preg_match( '/[\x00-\x20\x7F]/', $value ) ) {
			return null;
		}
		if ( 1 === preg_match( '/^-?[1-9][0-9]{0,19}$/D', $value ) ) {
			return $value;
		}
		return 1 === preg_match( '/^@[A-Za-z0-9_]+$/D', $value ) ? $value : null;
	}

	/**
	 * Failure.
	 *
	 * @param string      $channel Value.
	 * @param string|null $provider_id Value.
	 * @param string|null $capability Value.
	 * @param string      $diagnostic Value.
	 * @return AttemptResult Return value.
	 */
	private function failure( string $channel, ?string $provider_id, ?string $capability, string $diagnostic ): AttemptResult {
		return new AttemptResult( AttemptStatus::FAILED, $channel, $provider_id, $capability, array(), $diagnostic );
	}

		/**
		 * Observed test result.
		 *
		 * @param OperationalContext $context Value.
		 * @param AttemptResult      $result Value.
		 * @param array              $destinations Value.
		 * @param string|null        $sender Value.
		 * @return AttemptResult Return value.
		 */
	private function observed_test_result(
		OperationalContext $context,
		AttemptResult $result,
		array $destinations,
		?string $sender = null
	): AttemptResult {
		if ( null !== $this->operational_log ) {
			$this->operational_log->record_attempt( $context, $result, $destinations, $sender, 1 );
		}
		return $result;
	}
}
