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
use GravityNotify\Provider\SmsProviderManager;
use InvalidArgumentException;

/**
 * Sends bounded configuration tests directly through existing production providers.
 *
 * This service intentionally does not use Feed processing, Retry, the dispatcher,
 * or delivery-state persistence.
 */
final class ProviderTestService {

	/**
	 * Sanitized provider settings snapshot.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Injected transport seam.
	 *
	 * @var HttpTransportInterface
	 */
	private HttpTransportInterface $http;

	/**
	 * Create a provider-test service around one settings snapshot and transport.
	 *
	 * @param array<string, mixed>   $settings Sanitized provider settings.
	 * @param HttpTransportInterface $http     Existing transport seam.
	 */
	public function __construct( array $settings, HttpTransportInterface $http ) {
		$this->settings = $settings;
		$this->http     = $http;
	}

	/** Build the production service from current Settings and WordPress HTTP. */
	public static function production(): self {
		return new self( Settings::read(), new WordPressHttpTransport() );
	}

	/**
	 * Send exactly one plain IPPanel SMS test.
	 *
	 * @param string $destination Explicit operator-entered E.164 destination.
	 * @param string $message     Bounded localized test message.
	 */
	public function test_sms( string $destination, string $message ): AttemptResult {
		$manager  = new SmsProviderManager( $this->settings );
		$from     = $manager->configured_sender( SmsProviderManager::IPPANEL );
		$provider = $manager->provider( SmsProviderManager::IPPANEL, $this->http );

		if ( null === $provider || ! self::is_e164( $from ) ) {
			return $this->failure( 'sms', SmsProviderManager::IPPANEL, SmsCapability::PLAIN, 'provider_not_configured' );
		}

		$destination = trim( $destination );
		if ( ! self::is_e164( $destination ) ) {
			return $this->failure( 'sms', SmsProviderManager::IPPANEL, SmsCapability::PLAIN, 'invalid_destination' );
		}

		try {
			$request = SmsRequest::plain(
				SmsCapability::PLAIN,
				array( $destination ),
				$from,
				$message
			);
		} catch ( InvalidArgumentException $exception ) {
			unset( $exception );
			return $this->failure( 'sms', SmsProviderManager::IPPANEL, SmsCapability::PLAIN, 'invalid_test_request' );
		}

		return $provider->send( $request );
	}

	/**
	 * Send exactly one Bale test message.
	 *
	 * @param string $destination Explicit operator-entered chat ID/channel username.
	 * @param string $message     Bounded localized test message.
	 */
	public function test_bale( string $destination, string $message ): AttemptResult {
		$token = $this->settings['bale_bot_token'] ?? '';
		if ( ! is_string( $token ) || '' === $token ) {
			return $this->failure( 'bale', null, null, 'provider_not_configured' );
		}

		$destination = self::bale_destination( $destination );
		if ( null === $destination ) {
			return $this->failure( 'bale', null, null, 'invalid_destination' );
		}

		try {
			$request = new BaleRequest( $destination, $message );
		} catch ( InvalidArgumentException $exception ) {
			unset( $exception );
			return $this->failure( 'bale', null, null, 'invalid_test_request' );
		}

		return ( new BaleClient( $token, $this->http ) )->send( $request );
	}

	/**
	 * Check the existing IPPanel E.164 contract.
	 *
	 * @param string $value Candidate sender or destination.
	 * @return bool
	 */
	private static function is_e164( string $value ): bool {
		return 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $value );
	}

	/**
	 * Validate the documented Bale chat identifier/username shapes conservatively.
	 *
	 * @param string $value Candidate Bale destination.
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
	 * Build a bounded local failure without touching a provider.
	 *
	 * @param string      $channel     Channel identifier.
	 * @param string|null $provider_id Provider identifier when applicable.
	 * @param string|null $capability  SMS capability when applicable.
	 * @param string      $diagnostic  Safe diagnostic identifier.
	 */
	private function failure( string $channel, ?string $provider_id, ?string $capability, string $diagnostic ): AttemptResult {
		return new AttemptResult(
			AttemptStatus::FAILED,
			$channel,
			$provider_id,
			$capability,
			array(),
			$diagnostic
		);
	}
}
