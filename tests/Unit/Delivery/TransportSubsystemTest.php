<?php
/**
 * Deterministic WU-02 transport subsystem tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Delivery;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Bale\BaleChannelInterface;
use GravityNotify\Delivery\Bale\BaleClient;
use GravityNotify\Delivery\Bale\BaleRequest;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Delivery\Sms\IPPanelProvider;
use GravityNotify\Delivery\Sms\SmsCapability;
use GravityNotify\Delivery\Sms\SmsProviderInterface;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\Sms\SmsRequest;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\Tests\Support\WordPress\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Proves provider/channel semantics with zero real network I/O.
 */
final class TransportSubsystemTest extends TestCase {

	/**
	 * IPPanel advertises only capabilities verified by the current contract.
	 *
	 * @return void
	 */
	public function test_ippanel_capabilities_are_bounded(): void {
		$provider = new IPPanelProvider( $this->credential(), new FakeHttpTransport( array() ) );

		self::assertSame(
			array(
				SmsCapability::PLAIN,
				SmsCapability::PATTERN,
				SmsCapability::MULTI_RECIPIENT_PLAIN,
				SmsCapability::PROVIDER_MESSAGE_REFERENCE,
			),
			$provider->capabilities()
		);
		self::assertNotContains( SmsCapability::MULTI_RECIPIENT_PATTERN, $provider->capabilities(), true );
	}

	/**
	 * Plain request construction follows the current webservice contract.
	 *
	 * @return void
	 */
	public function test_ippanel_plain_request_construction_and_success_reference(): void {
		$http = new FakeHttpTransport(
			array(
				HttpResponse::from_http(
					200,
					'{"data":{"message_outbox_ids":[17]},"meta":{"status":true}}'
				),
			)
		);
		$provider = new IPPanelProvider( $this->credential(), $http );
		$request  = SmsRequest::plain(
			SmsCapability::MULTI_RECIPIENT_PLAIN,
			array( $this->recipient( '1' ), $this->recipient( '2' ) ),
			$this->sender(),
			'transport test'
		);

		$result  = $provider->send( $request );
		$sent    = $http->requests()[0];
		$payload = json_decode( $sent['args']['body'], true );

		self::assertSame( AttemptStatus::SUCCESS, $result->status() );
		self::assertSame( array( '17' ), $result->provider_references() );
		self::assertSame( 'https://edge.ippanel.com/v1/api/send', $sent['url'] );
		self::assertSame( $this->credential(), $sent['args']['headers']['Authorization'] );
		self::assertSame( 'webservice', $payload['sending_type'] );
		self::assertSame( $request->recipients(), $payload['params']['recipients'] );
		self::assertSame( 'transport test', $payload['message'] );
	}

	/**
	 * Pattern shape stays pattern and never becomes plain text.
	 *
	 * @return void
	 */
	public function test_ippanel_pattern_request_preserves_semantics(): void {
		$http = new FakeHttpTransport(
			array(
				HttpResponse::from_http(
					200,
					'{"data":{"message_outbox_ids":["p-1"]},"meta":{"status":true}}'
				),
			)
		);
		$provider = new IPPanelProvider( $this->credential(), $http );
		$request  = SmsRequest::pattern(
			SmsCapability::PATTERN,
			array( $this->recipient( '3' ) ),
			$this->sender(),
			'case_update',
			array( 'case' => 'A-7' )
		);

		$result  = $provider->send( $request );
		$payload = json_decode( $http->requests()[0]['args']['body'], true );

		self::assertSame( AttemptStatus::SUCCESS, $result->status() );
		self::assertSame( 'pattern', $payload['sending_type'] );
		self::assertSame( 'case_update', $payload['code'] );
		self::assertSame( array( 'case' => 'A-7' ), $payload['params'] );
		self::assertArrayNotHasKey( 'message', $payload );
	}

	/**
	 * Provider response classification is conservative and deterministic.
	 *
	 * @return void
	 */
	public function test_ippanel_response_classification(): void {
		$request = SmsRequest::plain(
			SmsCapability::PLAIN,
			array( $this->recipient( '4' ) ),
			$this->sender(),
			'classify'
		);
		$http    = new FakeHttpTransport(
			array(
				HttpResponse::from_transport_error( 'simulated_network_error' ),
				HttpResponse::from_http( 401, '{"meta":{"status":false}}' ),
				HttpResponse::from_http( 200, 'not-json' ),
				HttpResponse::from_http( 200, '{"meta":{"status":false},"data":null}' ),
				HttpResponse::from_http( 200, '{"meta":{"status":true},"data":{}}' ),
			)
		);
		$provider = new IPPanelProvider( $this->credential(), $http );

		self::assertSame( AttemptStatus::FAILED, $provider->send( $request )->status() );
		self::assertSame( AttemptStatus::FAILED, $provider->send( $request )->status() );
		self::assertSame( AttemptStatus::AMBIGUOUS, $provider->send( $request )->status() );
		self::assertSame( AttemptStatus::FAILED, $provider->send( $request )->status() );
		self::assertSame( AttemptStatus::AMBIGUOUS, $provider->send( $request )->status() );
	}

	/**
	 * Bale is a separate channel and follows documented sendMessage semantics.
	 *
	 * @return void
	 */
	public function test_bale_request_construction_success_and_rejection(): void {
		$http = new FakeHttpTransport(
			array(
				HttpResponse::from_http( 200, '{"ok":true,"result":{"message_id":29}}' ),
				HttpResponse::from_http( 200, '{"ok":false,"error_code":400}' ),
			)
		);
		$client  = new BaleClient( $this->credential(), $http );
		$request = new BaleRequest( $this->bale_target(), 'Bale transport test' );

		$success = $client->send( $request );
		$failed  = $client->send( $request );
		$sent    = $http->requests()[0];
		$payload = json_decode( $sent['args']['body'], true );

		self::assertSame( AttemptStatus::SUCCESS, $success->status() );
		self::assertSame( 'bale', $success->channel() );
		self::assertNull( $success->provider_id() );
		self::assertSame( array( '29' ), $success->provider_references() );
		self::assertSame( AttemptStatus::FAILED, $failed->status() );
		self::assertStringEndsWith( '/sendMessage', $sent['url'] );
		self::assertSame( $request->chat_id(), $payload['chat_id'] );
		self::assertSame( $request->text(), $payload['text'] );
	}

	/**
	 * Bale malformed or acceptance-uncertain 2xx responses are ambiguous.
	 *
	 * @return void
	 */
	public function test_bale_ambiguous_response_classification(): void {
		$http = new FakeHttpTransport(
			array(
				HttpResponse::from_http( 200, 'not-json' ),
				HttpResponse::from_http( 200, '{"ok":true,"result":{}}' ),
			)
		);
		$client  = new BaleClient( $this->credential(), $http );
		$request = new BaleRequest( $this->bale_target(), 'Bale ambiguity test' );

		self::assertSame( AttemptStatus::AMBIGUOUS, $client->send( $request )->status() );
		self::assertSame( AttemptStatus::AMBIGUOUS, $client->send( $request )->status() );
	}

	/**
	 * Registry order, capability gating and fallback behavior are deterministic.
	 *
	 * @return void
	 */
	public function test_dispatcher_preserves_order_skips_unsupported_and_continues_ambiguous_failed(): void {
		$first  = $this->provider_stub( 'first', array( SmsCapability::PLAIN ), AttemptStatus::FAILED );
		$second = $this->provider_stub( 'second', array( SmsCapability::PATTERN ), AttemptStatus::SUCCESS );
		$third  = $this->provider_stub( 'third', array( SmsCapability::PLAIN ), AttemptStatus::AMBIGUOUS );
		$bale   = $this->bale_stub( AttemptStatus::SUCCESS );

		$dispatcher = new SynchronousDispatcher(
			new SmsProviderRegistry( array( $first, $second, $third ) ),
			$bale
		);
		$request = SmsRequest::plain(
			SmsCapability::PLAIN,
			array( $this->recipient( '5' ) ),
			$this->sender(),
			'fallback'
		);
		$attempts = $dispatcher->dispatch_sms(
			$request,
			true,
			new BaleRequest( $this->bale_target(), 'Bale fallback' )
		);

		self::assertSame(
			array(
				AttemptStatus::FAILED,
				AttemptStatus::SKIPPED,
				AttemptStatus::AMBIGUOUS,
				AttemptStatus::SUCCESS,
			),
			array_map(
				static function ( AttemptResult $attempt ): string {
					return $attempt->status();
				},
				$attempts
			)
		);
		self::assertSame(
			array( 'first', 'second', 'third', null ),
			array_map(
				static function ( AttemptResult $attempt ): ?string {
					return $attempt->provider_id();
				},
				$attempts
			)
		);
	}

	/**
	 * Success stops fallback immediately.
	 *
	 * @return void
	 */
	public function test_dispatcher_stops_on_success_and_does_not_call_bale(): void {
		$first  = $this->provider_stub( 'first', array( SmsCapability::PLAIN ), AttemptStatus::SUCCESS );
		$second = $this->provider_stub( 'second', array( SmsCapability::PLAIN ), AttemptStatus::FAILED );
		$bale   = $this->bale_stub( AttemptStatus::FAILED );
		$dispatcher = new SynchronousDispatcher(
			new SmsProviderRegistry( array( $first, $second ) ),
			$bale
		);

		$attempts = $dispatcher->dispatch_sms(
			SmsRequest::plain(
				SmsCapability::PLAIN,
				array( $this->recipient( '6' ) ),
				$this->sender(),
				'stop'
			),
			true,
			new BaleRequest( $this->bale_target(), 'unused fallback' )
		);

		self::assertCount( 1, $attempts );
		self::assertSame( AttemptStatus::SUCCESS, $attempts[0]->status() );
	}

	/**
	 * Unsupported pattern capability is skipped without semantic conversion.
	 *
	 * @return void
	 */
	public function test_unsupported_pattern_is_skipped_without_network_or_plain_conversion(): void {
		$http       = new FakeHttpTransport( array() );
		$provider   = new IPPanelProvider( $this->credential(), $http );
		$dispatcher = new SynchronousDispatcher( new SmsProviderRegistry( array( $provider ) ) );
		$request    = SmsRequest::pattern(
			SmsCapability::MULTI_RECIPIENT_PATTERN,
			array( $this->recipient( '7' ), $this->recipient( '8' ) ),
			$this->sender(),
			'group_pattern',
			array( 'item' => 'value' )
		);

		$attempts = $dispatcher->dispatch_sms( $request );

		self::assertCount( 1, $attempts );
		self::assertSame( AttemptStatus::SKIPPED, $attempts[0]->status() );
		self::assertSame( SmsCapability::MULTI_RECIPIENT_PATTERN, $attempts[0]->capability() );
		self::assertSame( array(), $http->requests() );
	}

	/**
	 * Attempt diagnostics/results never expose transport credentials.
	 *
	 * @return void
	 */
	public function test_secret_values_are_not_exposed_in_results(): void {
		$credential = $this->credential();
		$http       = new FakeHttpTransport(
			array(
				HttpResponse::from_http( 401, '{"meta":{"status":false}}' ),
				HttpResponse::from_http( 200, '{"ok":false,"error_code":401}' ),
			)
		);
		$sms_result = ( new IPPanelProvider( $credential, $http ) )->send(
			SmsRequest::plain(
				SmsCapability::PLAIN,
				array( $this->recipient( '9' ) ),
				$this->sender(),
				'secret safety'
			)
		);
		$bale_result = ( new BaleClient( $credential, $http ) )->send(
			new BaleRequest( $this->bale_target(), 'secret safety' )
		);

		$exported = implode(
			'|',
			array(
				$sms_result->diagnostic(),
				(string) $sms_result->provider_id(),
				$bale_result->diagnostic(),
				(string) $bale_result->provider_id(),
			)
		);

		self::assertStringNotContainsString( $credential, $exported );
	}

	/**
	 * Build a synthetic, non-secret credential at runtime.
	 *
	 * @return string
	 */
	private function credential(): string {
		return implode( '-', array( 'unit', 'test', 'credential' ) );
	}

	/**
	 * Build a synthetic Bale target without committing a real chat identifier.
	 *
	 * @return string
	 */
	private function bale_target(): string {
		return implode( '', array( '@', 'unit', '_test', '_target' ) );
	}

	/**
	 * Build a synthetic E.164-like recipient without a committed full number.
	 *
	 * @param string $suffix Distinct final digit.
	 * @return string
	 */
	private function recipient( string $suffix ): string {
		return implode( '', array( '+', '999', str_repeat( '0', 8 ), $suffix ) );
	}

	/**
	 * Build a synthetic sender value without a committed full number.
	 *
	 * @return string
	 */
	private function sender(): string {
		return implode( '', array( '+', '999', str_repeat( '1', 6 ) ) );
	}

	/**
	 * Build a deterministic SMS provider test double.
	 *
	 * @param string             $identifier   Provider identifier.
	 * @param array<int, string> $capabilities Capabilities.
	 * @param string             $status       Returned status.
	 * @return SmsProviderInterface
	 */
	private function provider_stub( string $identifier, array $capabilities, string $status ): SmsProviderInterface {
		return new class( $identifier, $capabilities, $status ) implements SmsProviderInterface {
			/**
			 * Provider identifier.
			 *
			 * @var string
			 */
			private string $identifier;

			/**
			 * Capabilities.
			 *
			 * @var array<int, string>
			 */
			private array $capabilities;

			/**
			 * Returned status.
			 *
			 * @var string
			 */
			private string $status;

			/**
			 * Create test double.
			 *
			 * @param string             $identifier   Identifier.
			 * @param array<int, string> $capabilities Capabilities.
			 * @param string             $status       Returned status.
			 */
			public function __construct( string $identifier, array $capabilities, string $status ) {
				$this->identifier   = $identifier;
				$this->capabilities = $capabilities;
				$this->status       = $status;
			}

			/**
			 * Provider identifier.
			 *
			 * @return string
			 */
			public function identifier(): string {
				return $this->identifier;
			}

			/**
			 * Capabilities.
			 *
			 * @return array<int, string>
			 */
			public function capabilities(): array {
				return $this->capabilities;
			}

			/**
			 * Return deterministic attempt.
			 *
			 * @param SmsRequest $request Request.
			 * @return AttemptResult
			 */
			public function send( SmsRequest $request ): AttemptResult {
				return new AttemptResult(
					$this->status,
					'sms',
					$this->identifier,
					$request->capability(),
					array(),
					'test_double'
				);
			}
		};
	}

	/**
	 * Build a deterministic Bale channel test double.
	 *
	 * @param string $status Returned status.
	 * @return BaleChannelInterface
	 */
	private function bale_stub( string $status ): BaleChannelInterface {
		return new class( $status ) implements BaleChannelInterface {
			/**
			 * Returned status.
			 *
			 * @var string
			 */
			private string $status;

			/**
			 * Create test double.
			 *
			 * @param string $status Returned status.
			 */
			public function __construct( string $status ) {
				$this->status = $status;
			}

			/**
			 * Return deterministic attempt.
			 *
			 * @param BaleRequest $request Request.
			 * @return AttemptResult
			 */
			public function send( BaleRequest $request ): AttemptResult {
				unset( $request );
				return new AttemptResult(
					$this->status,
					'bale',
					null,
					null,
					array(),
					'test_double'
				);
			}
		};
	}
}
