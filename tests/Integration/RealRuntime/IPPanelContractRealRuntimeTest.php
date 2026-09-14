<?php
/**
 * Deterministic IPPanel contract coverage through the real greenfield runtime.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\RealRuntime;

use GFAPI;
use GravityNotify\Admin\Settings;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\WordPressHttpTransport;
use GravityNotify\Delivery\Sms\IPPanelProvider;
use GravityNotify\Delivery\Sms\SmsCapability;
use GravityNotify\Delivery\Sms\SmsRequest;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\Migration\CutoverRegistry;
use GravityNotify\Migration\CutoverSequence;
use GravityNotify\Migration\CutoverService;
use GravityNotify\Migration\ProductionRuntime;
use ReflectionClass;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Exercises IPPanel through the real GF/Flow production runtime and loopback HTTP.
 */
final class IPPanelContractRealRuntimeTest extends WP_UnitTestCase {
	/** Dummy API token used only by the local simulator. */
	private const TOKEN = 'dummy-ippanel-token';

	/** Deterministic sender address. */
	private const FROM = '+989000000000';

	/** Deterministic recipient address. */
	private const TO = '+989111111111';

	/** Deterministic message body. */
	private const MESSAGE = 'GNM deterministic simulator contract';

	/** Deterministic provider reference returned by the simulator. */
	private const REFERENCE = '424242';

	/**
	 * Fixture form ID.
	 *
	 * @var int
	 */
	private int $form_id = 0;

	/**
	 * Fixture entry ID.
	 *
	 * @var int
	 */
	private int $entry_id = 0;

	/**
	 * Original settings option value.
	 *
	 * @var mixed
	 */
	private $settings_before;

	/**
	 * Original cutover option value.
	 *
	 * @var mixed
	 */
	private $cutover_before;

	/** Prepare deterministic runtime state for each contract test. */
	public function set_up(): void {
		parent::set_up();
		$this->settings_before = get_option( Settings::OPTION, null );
		$this->cutover_before  = get_option( CutoverRegistry::OPTION, null );
		delete_option( Settings::OPTION );
		delete_option( CutoverRegistry::OPTION );
		NotificationFeedAddOn::get_instance()->configure_processor( null );
		$this->reset_simulator();
	}

	/** Restore options and database fixtures. */
	public function tear_down(): void {
		NotificationFeedAddOn::get_instance()->configure_processor( null );
		if ( 0 < $this->entry_id ) {
			GFAPI::delete_entry( $this->entry_id );
		}
		if ( 0 < $this->form_id ) {
			NotificationFeedAddOn::get_instance()->delete_feeds( $this->form_id );
			GFAPI::delete_form( $this->form_id );
		}
		$this->restore_option( Settings::OPTION, $this->settings_before );
		$this->restore_option( CutoverRegistry::OPTION, $this->cutover_before );
		parent::tear_down();
	}

	/**
	 * Prove IPPANEL-CONTRACT-REAL-19 through the retired-legacy production runtime.
	 *
	 * @testdox IPPANEL-CONTRACT-REAL-19 greenfield GF/Flow IPPanel path crosses loopback HTTP and reaches delivered
	 */
	public function test_ippanel_contract_real_19_real_gf_flow_production_path_reaches_delivered(): void {
		self::assertSame( 'https://edge.ippanel.com/v1/api/send', $this->production_endpoint() );
		self::assertTrue(
			update_option(
				Settings::OPTION,
				array(
					'ippanel_api_key' => self::TOKEN,
					'sms_from_number' => self::FROM,
					'bale_bot_token'  => '',
				),
				false
			)
		);

		$this->form_id = GFAPI::add_form(
			array(
				'title'  => 'GNM deterministic IPPanel contract',
				'fields' => array(
					array(
						'id'    => 1,
						'type'  => 'text',
						'label' => 'Marker',
					),
				),
			)
		);
		self::assertGreaterThan( 0, $this->form_id );

		$feed    = $this->add_target_feed();
		$target  = $this->add_target_step( $feed );
		$source  = $this->add_legacy_source_step();
		$service = new CutoverService();
		$scope   = $service->prepare_flow( 'flow_step', $this->form_id, $source, $feed, $target );
		self::assertIsString( $scope );
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( $scope )['state'] );
		self::assertTrue( $service->enable( $scope ) );
		self::assertFalse( CutoverRegistry::legacy_flow_step_allowed( $this->form_id, $source ) );
		self::assertTrue( CutoverRegistry::feed_authorized( $feed ) );

		$endpoint = $this->send_endpoint();
		ProductionRuntime::register_with_test_ippanel_endpoint( $endpoint );
		$urls     = array();
		$observer = static function ( $response, $context, $class, $args, $url ) use ( &$urls ): void {
			unset( $response, $class, $args );
			if ( 'response' === $context && is_string( $url ) ) {
				$urls[] = $url;
			}
		};
		add_action( 'http_api_debug', $observer, 10, 5 );
		try {
			$submission = GFAPI::submit_form( $this->form_id, array( 'input_1' => 'simulator' ) );
		} finally {
			remove_action( 'http_api_debug', $observer, 10 );
		}
		self::assertNotWPError( $submission );
		self::assertTrue( (bool) rgar( $submission, 'is_valid' ) );
		$this->entry_id = (int) rgar( $submission, 'entry_id' );
		self::assertGreaterThan( 0, $this->entry_id );

		$result = NotificationFeedAddOn::get_instance()->last_execution_result();
		self::assertNotNull( $result );
		self::assertCount( 1, $result->attempts() );
		$attempt = $result->attempts()[0];
		self::assertSame( AttemptStatus::SUCCESS, $attempt->status() );
		self::assertSame( 'ippanel', $attempt->provider_id() );
		self::assertSame( array( self::REFERENCE ), $attempt->provider_references() );
		self::assertContains( $endpoint, $urls );
		foreach ( $urls as $url ) {
			self::assertStringStartsWith( 'http://127.0.0.1:8765/', $url );
			self::assertStringNotContainsString( 'edge.ippanel.com', $url );
		}
		self::assertSame( 'delivered', $this->poll_delivery( self::REFERENCE, 'delivered', 1 ) );
		$state = $this->simulator_state();
		self::assertSame( 1, $state['send_count'] ?? null );
		self::assertSame( 1, $state['report_count'] ?? null );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed deterministic CI evidence marker.
		printf( 'GNM_IPPANEL_CONTRACT_REAL_19=PASS socket=127.0.0.1:8765 provider=ippanel transport=wordpress send_count=1 report_count=1 message_status=2 external_provider_calls=0 real_sms=0' . PHP_EOL );
	}

	/** Prove strict simulator contract failures and duplicate-send rejection. */
	public function test_ippanel_simulator_rejects_invalid_contract_and_duplicate_send(): void {
		$endpoint = $this->send_endpoint();
		$blocked  = wp_remote_get( 'http://127.0.0.1:8766/v1/api/send', array( 'timeout' => 1 ) );
		self::assertWPError( $blocked );
		self::assertSame( 'gravity_notify_test_http_blocked', $blocked->get_error_code() );

		self::assertSame( 401, $this->raw_status( 'POST', $endpoint, array( 'Content-Type' => 'application/json' ), $this->valid_body() ) );
		self::assertSame(
			401,
			$this->raw_status(
				'POST',
				$endpoint,
				array(
					'Authorization' => 'wrong',
					'Content-Type'  => 'application/json',
				),
				$this->valid_body()
			)
		);
		self::assertSame( 405, $this->raw_status( 'GET', $endpoint, array( 'Authorization' => self::TOKEN ) ) );
		self::assertSame(
			404,
			$this->raw_status(
				'POST',
				'http://127.0.0.1:8765/v1/api/wrong',
				array(
					'Authorization' => self::TOKEN,
					'Content-Type'  => 'application/json',
				),
				$this->valid_body()
			)
		);
		self::assertSame(
			415,
			$this->raw_status(
				'POST',
				$endpoint,
				array(
					'Authorization' => self::TOKEN,
					'Content-Type'  => 'text/plain',
				),
				$this->valid_body()
			)
		);
		self::assertSame(
			400,
			$this->raw_status(
				'POST',
				$endpoint,
				array(
					'Authorization' => self::TOKEN,
					'Content-Type'  => 'application/json',
				),
				'{bad-json'
			)
		);
		self::assertSame(
			422,
			$this->raw_status(
				'POST',
				$endpoint,
				array(
					'Authorization' => self::TOKEN,
					'Content-Type'  => 'application/json',
				),
				'{}'
			)
		);
		self::assertSame(
			200,
			$this->raw_status(
				'POST',
				$endpoint,
				array(
					'Authorization' => self::TOKEN,
					'Content-Type'  => 'application/json',
				),
				$this->valid_body()
			)
		);
		self::assertSame(
			409,
			$this->raw_status(
				'POST',
				$endpoint,
				array(
					'Authorization' => self::TOKEN,
					'Content-Type'  => 'application/json',
				),
				$this->valid_body()
			)
		);
	}

	/** Prove provider parsing fails closed on rejected or reference-less acceptance. */
	public function test_ippanel_provider_parser_fails_closed_on_rejection_and_missing_reference(): void {
		$request = SmsRequest::plain( SmsCapability::PLAIN, array( self::TO ), self::FROM, self::MESSAGE );
		foreach (
			array(
				'provider_rejection' => AttemptStatus::FAILED,
				'missing_reference'  => AttemptStatus::AMBIGUOUS,
			) as $scenario => $expected
		) {
			$this->reset_simulator();
			$provider = new IPPanelProvider( self::TOKEN, new WordPressHttpTransport(), $this->send_endpoint() . '?scenario=' . $scenario );
			$result   = $provider->send( $request );
			self::assertSame( $expected, $result->status() );
			self::assertSame( array(), $result->provider_references() );
		}
	}

	/** Prove terminal and bounded-pending delivery reports fail closed. */
	public function test_ippanel_delivery_report_fails_closed_for_terminal_and_bounded_pending(): void {
		self::assertSame( 'terminal_non_delivery', $this->poll_delivery( self::REFERENCE, 'terminal_non_delivery', 1 ) );
		self::assertSame( 'not_delivered', $this->poll_delivery( self::REFERENCE, 'pending', 2 ) );
	}

	/**
	 * Return the immutable production IPPanel endpoint.
	 *
	 * @throws RuntimeException When the endpoint constant is unavailable.
	 */
	private function production_endpoint(): string {
		$constant = ( new ReflectionClass( IPPanelProvider::class ) )->getReflectionConstant( 'ENDPOINT' );
		if ( false === $constant ) {
			throw new RuntimeException( 'IPPanel production endpoint constant is unavailable.' );
		}
		return (string) $constant->getValue();
	}

	/** Return the configured deterministic simulator send endpoint. */
	private function send_endpoint(): string {
		$value = getenv( 'GNM_IPPANEL_SIMULATOR_SEND_ENDPOINT' );
		self::assertIsString( $value );
		self::assertSame( 'http://127.0.0.1:8765/v1/api/send', $value );
		return $value;
	}

	/** Return the configured deterministic simulator report endpoint. */
	private function report_endpoint(): string {
		$value = getenv( 'GNM_IPPANEL_SIMULATOR_REPORT_ENDPOINT' );
		self::assertIsString( $value );
		return $value;
	}

	/** Reset deterministic simulator state. */
	private function reset_simulator(): void {
		$response = wp_remote_post(
			'http://127.0.0.1:8765/_test/reset',
			array(
				'headers' => array( 'Authorization' => self::TOKEN ),
				'timeout' => 2,
			)
		);
		self::assertNotWPError( $response );
		self::assertSame( 200, wp_remote_retrieve_response_code( $response ) );
	}

	/** Return current deterministic simulator counters. */
	private function simulator_state(): array {
		$response = wp_remote_get(
			'http://127.0.0.1:8765/_test/state',
			array(
				'headers' => array( 'Authorization' => self::TOKEN ),
				'timeout' => 2,
			)
		);
		self::assertNotWPError( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		self::assertIsArray( $decoded );
		return $decoded;
	}

	/** Build one valid deterministic simulator send body. */
	private function valid_body(): string {
		return (string) wp_json_encode(
			array(
				'sending_type' => 'webservice',
				'from_number'  => self::FROM,
				'message'      => self::MESSAGE,
				'params'       => array( 'recipients' => array( self::TO ) ),
			)
		);
	}

	/**
	 * Perform one raw WordPress HTTP request and return its status.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Request URL.
	 * @param array  $headers Request headers.
	 * @param string $body    Request body.
	 */
	private function raw_status( string $method, string $url, array $headers = array(), string $body = '' ): int {
		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 2,
			)
		);
		self::assertNotWPError( $response );
		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Poll deterministic delivery reports with a bounded attempt count.
	 *
	 * @param string $reference Provider reference.
	 * @param string $scenario  Simulator scenario.
	 * @param int    $max       Maximum poll attempts.
	 */
	private function poll_delivery( string $reference, string $scenario, int $max ): string {
		for ( $attempt = 1; $attempt <= $max; ++$attempt ) {
			$url      = add_query_arg(
				array(
					'page'     => 1,
					'per_page' => 10,
					'bulk_id'  => $reference,
					'scenario' => $scenario,
				),
				$this->report_endpoint()
			);
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array(
						'Authorization' => self::TOKEN,
						'Content-Type'  => 'application/json',
					),
					'timeout' => 2,
				)
			);
			if ( is_wp_error( $response ) || 200 > wp_remote_retrieve_response_code( $response ) || 300 <= wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $decoded ) || true !== ( $decoded['meta']['status'] ?? null ) || ! is_array( $decoded['data'] ?? null ) ) {
				continue;
			}
			$record = reset( $decoded['data'] );
			if ( ! is_array( $record ) ) {
				continue;
			}
			$status = (string) ( $record['message_status'] ?? '' );
			if ( '2' === $status ) {
				return 'delivered';
			}
			if ( in_array( $status, array( '3', '4' ), true ) ) {
				return 'terminal_non_delivery';
			}
		}
		return 'not_delivered';
	}

	/** Add the deterministic greenfield target Feed. */
	private function add_target_feed(): int {
		$feed_id = GFAPI::add_feed(
			$this->form_id,
			array(
				'feedName'               => 'Deterministic IPPanel target',
				'message'                => self::MESSAGE,
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
				'recipient_source_value' => self::TO,
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
			),
			NotificationFeedAddOn::get_instance()->get_slug()
		);
		self::assertNotWPError( $feed_id );
		self::assertIsInt( $feed_id );
		return $feed_id;
	}

	/**
	 * Add the deterministic target Gravity Flow Step.
	 *
	 * @param int $feed_id Target Feed ID.
	 */
	private function add_target_step( int $feed_id ): int {
		$step_id = ( new \Gravity_Flow_API( $this->form_id ) )->add_step(
			array(
				'step_name'        => 'Deterministic IPPanel target step',
				'step_type'        => 'gravity_notification_manager',
				'feed_' . $feed_id => '1',
			)
		);
		self::assertGreaterThan( 0, $step_id );
		return $step_id;
	}

	/** Add the historical source Step identity used by cutover authorization. */
	private function add_legacy_source_step(): int {
		$step_id = ( new \Gravity_Flow_API( $this->form_id ) )->add_step(
			array(
				'step_name' => 'Legacy source identity',
				'step_type' => 'approval',
			)
		);
		self::assertGreaterThan( 0, $step_id );
		return $step_id;
	}

	/**
	 * Restore one WordPress option to its pre-test state.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Original option value.
	 */
	private function restore_option( string $name, $value ): void {
		if ( null === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
