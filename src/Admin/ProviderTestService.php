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

/** Tests production adapters directly without Feed/Retry/Entry-Meta side effects. */
final class ProviderTestService {

	/**
	 * Current settings snapshot.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;
	/**
	 * HTTP transport used by explicit test actions.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;
	/**
	 * Optional operational logger for TEST evidence.
	 *
	 * @var OperationalLogger|null
	 */
	private ?OperationalLogger $operational_log;

	/**
	 * Build the explicit provider/channel test service.
	 *
	 * @param array<string, mixed>   $settings        Settings snapshot.
	 * @param HttpTransportInterface $http            HTTP transport.
	 * @param OperationalLogger|null $operational_log Optional TEST observability logger.
	 */
	public function __construct( array $settings, HttpTransportInterface $http, ?OperationalLogger $operational_log = null ) {
		$this->settings        = $settings;
		$this->http            = $http;
		$this->operational_log = $operational_log;
	}

	/** Build the production explicit test service. */
	public static function production(): self {
		return new self( Settings::read(), new WordPressHttpTransport(), OperationalLogger::production() );
	}

	/**
	 * Test one explicitly selected configured SMS provider.
	 *
	 * @param string $destination E.164 destination.
	 * @param string $message     Test message.
	 * @param string $provider_id Provider identifier.
	 */
	public function test_sms( string $destination, string $message, string $provider_id = SmsProviderManager::IPPANEL ): AttemptResult {
		$context  = new OperationalContext( OperationalContext::EXECUTION_TEST );
		$manager  = new SmsProviderManager( $this->settings );
		$sender   = $manager->configured_sender( $provider_id );
		$provider = $manager->provider( $provider_id, $this->http );

		if ( null === $provider ) {
			return $this->observed(
				$context,
				$this->failure( 'sms', $provider_id, SmsCapability::PLAIN, 'provider_not_configured', $sender ),
				array( $destination )
			);
		}

		$destination = trim( $destination );
		if ( 1 !== preg_match( '/^\+[1-9][0-9]{1,14}$/D', $destination ) ) {
			return $this->observed(
				$context,
				$this->failure( 'sms', $provider_id, SmsCapability::PLAIN, 'invalid_destination', $sender ),
				array( $destination )
			);
		}

		try {
			$request = SmsRequest::plain( SmsCapability::PLAIN, array( $destination ), $manager->primary_sender(), $message );
		} catch ( InvalidArgumentException $exception ) {
			unset( $exception );
			return $this->observed(
				$context,
				$this->failure( 'sms', $provider_id, SmsCapability::PLAIN, 'invalid_test_request', $sender ),
				array( $destination )
			);
		}

		return $this->observed( $context, $provider->send( $request ), array( $destination ) );
	}

	/**
	 * Test Bale through its existing separate channel boundary.
	 *
	 * @param string $destination Bale chat target.
	 * @param string $message     Test message.
	 * @return AttemptResult
	 */
	public function test_bale( string $destination, string $message ): AttemptResult {
		$context = new OperationalContext( OperationalContext::EXECUTION_TEST );
		$token   = $this->settings['bale_bot_token'] ?? '';
		if ( ! is_string( $token ) || '' === $token ) {
			return $this->observed( $context, $this->failure( 'bale', null, null, 'provider_not_configured' ), array( $destination ) );
		}
		$destination = self::bale_destination( $destination );
		if ( null === $destination ) {
			return $this->observed( $context, $this->failure( 'bale', null, null, 'invalid_destination' ), array() );
		}
		try {
			$request = new BaleRequest( $destination, $message );
		} catch ( InvalidArgumentException $exception ) {
			unset( $exception );
			return $this->observed( $context, $this->failure( 'bale', null, null, 'invalid_test_request' ), array( $destination ) );
		}
		return $this->observed( $context, ( new BaleClient( $token, $this->http ) )->send( $request ), array( $destination ) );
	}

	/**
	 * Validate a Bale numeric chat ID or @username.
	 *
	 * @param string $value Raw destination.
	 * @return string|null
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
	 * Build a local failure result.
	 *
	 * @param string      $channel    Channel identifier.
	 * @param string|null $provider   Provider identifier when applicable.
	 * @param string|null $capability SMS capability when applicable.
	 * @param string      $diagnostic Safe diagnostic token.
	 * @param string|null $sender     Provider sender when known.
	 * @return AttemptResult
	 */
	private function failure( string $channel, ?string $provider, ?string $capability, string $diagnostic, ?string $sender = null ): AttemptResult {
		return new AttemptResult( AttemptStatus::FAILED, $channel, $provider, $capability, array(), $diagnostic, null, $sender );
	}

	/**
	 * Record the explicit TEST through the existing observability subsystem only.
	 *
	 * @param OperationalContext $context      TEST execution context.
	 * @param AttemptResult      $result       Attempt result.
	 * @param array<int, string> $destinations Tested destinations.
	 * @return AttemptResult
	 */
	private function observed( OperationalContext $context, AttemptResult $result, array $destinations ): AttemptResult {
		if ( null !== $this->operational_log ) {
			$this->operational_log->record_attempt( $context, $result, $destinations, null, 1 );
		}
		return $result;
	}
}
