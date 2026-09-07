<?php
/**
 * Tests for the shared WU-04 Feed execution path.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\GravityForms;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Bale\BaleChannelInterface;
use GravityNotify\Delivery\Bale\BaleRequest;
use GravityNotify\Delivery\Sms\SmsCapability;
use GravityNotify\Delivery\Sms\SmsProviderInterface;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\Sms\SmsRequest;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Proves WU-03 resolution and WU-02 synchronous dispatch are reused.
 */
final class NotificationFeedProcessorTest extends TestCase {

	/**
	 * A fixed SMS Feed resolves and dispatches synchronously through WU-02.
	 *
	 * @return void
	 */
	public function test_sms_feed_uses_recipient_resolver_and_synchronous_dispatcher(): void {
		$provider   = $this->provider( AttemptStatus::SUCCESS );
		$processor  = $this->processor( array( $provider ), null, '+982100000000' );
		$result     = $processor->execute(
			$this->rule(
				FeedRuleSchema::CHANNEL_SMS,
				FeedRuleSchema::RECIPIENT_FIXED,
				'+989121234567',
				FeedRuleSchema::FALLBACK_NONE
			),
			array( 'id' => 10 ),
			array( 'id' => 5 )
		);

		self::assertTrue( $result->delivery_succeeded() );
		self::assertCount( 1, $result->attempts() );
		self::assertSame( 1, $provider->send_count );
		self::assertInstanceOf( SmsRequest::class, $provider->last_request );
		self::assertSame( array( '+989121234567' ), $provider->last_request->recipients() );
		self::assertSame( 'Case accepted', $provider->last_request->message() );
		self::assertSame( SmsCapability::PLAIN, $provider->last_request->capability() );
	}

	/**
	 * Feed fallback policy controls whether a second SMS provider may run.
	 *
	 * @return void
	 */
	public function test_feed_fallback_policy_controls_sms_provider_fallback(): void {
		$first  = $this->provider( AttemptStatus::FAILED, 'first' );
		$second = $this->provider( AttemptStatus::SUCCESS, 'second' );
		$no_fallback = $this->processor( array( $first, $second ), null, '+982100000000' );

		$result = $no_fallback->execute(
			$this->rule(
				FeedRuleSchema::CHANNEL_SMS,
				FeedRuleSchema::RECIPIENT_FIXED,
				'+989121234567',
				FeedRuleSchema::FALLBACK_NONE
			),
			array(),
			array()
		);

		self::assertFalse( $result->delivery_succeeded() );
		self::assertSame( 1, $first->send_count );
		self::assertSame( 0, $second->send_count );

		$first_again  = $this->provider( AttemptStatus::FAILED, 'first' );
		$second_again = $this->provider( AttemptStatus::SUCCESS, 'second' );
		$with_fallback = $this->processor( array( $first_again, $second_again ), null, '+982100000000' );

		$result = $with_fallback->execute(
			$this->rule(
				FeedRuleSchema::CHANNEL_SMS,
				FeedRuleSchema::RECIPIENT_FIXED,
				'+989121234567',
				FeedRuleSchema::FALLBACK_COMPATIBLE_SMS
			),
			array(),
			array()
		);

		self::assertTrue( $result->delivery_succeeded() );
		self::assertSame( 1, $first_again->send_count );
		self::assertSame( 1, $second_again->send_count );
	}

	/**
	 * Bale remains a separate channel and uses the WU-02 dispatcher.
	 *
	 * @return void
	 */
	public function test_bale_feed_uses_separate_synchronous_channel(): void {
		$bale      = $this->bale( AttemptStatus::SUCCESS );
		$processor = $this->processor( array(), $bale );
		$result    = $processor->execute(
			$this->rule(
				FeedRuleSchema::CHANNEL_BALE,
				FeedRuleSchema::RECIPIENT_FIXED,
				'test-chat',
				FeedRuleSchema::FALLBACK_NONE
			),
			array(),
			array()
		);

		self::assertTrue( $result->delivery_succeeded() );
		self::assertSame( 1, $bale->send_count );
		self::assertInstanceOf( BaleRequest::class, $bale->last_request );
		self::assertSame( 'test-chat', $bale->last_request->chat_id() );
	}

	/**
	 * Missing recipients stay observable and do not invoke transport.
	 *
	 * @return void
	 */
	public function test_missing_destination_returns_safe_failure_without_transport(): void {
		$provider  = $this->provider( AttemptStatus::SUCCESS );
		$processor = $this->processor( array( $provider ), null, '+982100000000' );
		$result    = $processor->execute(
			$this->rule(
				FeedRuleSchema::CHANNEL_SMS,
				FeedRuleSchema::RECIPIENT_FIXED,
				'',
				FeedRuleSchema::FALLBACK_NONE
			),
			array(),
			array()
		);

		self::assertFalse( $result->delivery_succeeded() );
		self::assertSame( 0, $provider->send_count );
		self::assertSame( 'missing_destination', $result->skips()[0]['reason'] );
	}

	/**
	 * Provider failure is represented separately from workflow completion.
	 *
	 * @return void
	 */
	public function test_provider_failure_is_observable_without_throwing(): void {
		$provider  = $this->provider( AttemptStatus::FAILED );
		$processor = $this->processor( array( $provider ), null, '+982100000000' );
		$result    = $processor->execute(
			$this->rule(
				FeedRuleSchema::CHANNEL_SMS,
				FeedRuleSchema::RECIPIENT_FIXED,
				'+989121234567',
				FeedRuleSchema::FALLBACK_NONE
			),
			array(),
			array()
		);

		self::assertFalse( $result->delivery_succeeded() );
		self::assertSame( AttemptStatus::FAILED, $result->attempts()[0]->status() );
	}

	private function processor( array $providers, ?BaleChannelInterface $bale, string $sms_sender = '' ): NotificationFeedProcessor {
		$resolver = new RecipientResolver(
			new FakeEntryFieldReader( array() ),
			new FakeUserDirectory( array(), array(), array() ),
			new FakeFlowAssigneeReader( array( 'available' => false, 'reason' => 'flow_context_unavailable', 'assignees' => array() ) )
		);
		return new NotificationFeedProcessor( $resolver, new SynchronousDispatcher( new SmsProviderRegistry( $providers ), $bale ), $sms_sender );
	}

	private function rule( string $channel, string $source_type, string $source, string $fallback ): array {
		return FeedRuleSchema::normalize( array( 'feedName' => 'Case update', 'message' => 'Case accepted', 'recipient_source_type' => $source_type, 'recipient_source_value' => $source, 'channel' => $channel, 'fallback_policy' => $fallback ) );
	}

	private function provider( string $status, string $identifier = 'fake' ): SmsProviderInterface {
		return new class( $status, $identifier ) implements SmsProviderInterface {
			public int $send_count = 0;
			public ?SmsRequest $last_request = null;
			private string $status;
			private string $identifier;
			public function __construct( string $status, string $identifier ) { $this->status = $status; $this->identifier = $identifier; }
			public function identifier(): string { return $this->identifier; }
			public function capabilities(): array { return array( SmsCapability::PLAIN, SmsCapability::MULTI_RECIPIENT_PLAIN ); }
			public function send( SmsRequest $request ): AttemptResult { ++$this->send_count; $this->last_request = $request; return new AttemptResult( $this->status, 'sms', $this->identifier, $request->capability(), array(), 'test' ); }
		};
	}

	private function bale( string $status ): BaleChannelInterface {
		return new class( $status ) implements BaleChannelInterface {
			public int $send_count = 0;
			public ?BaleRequest $last_request = null;
			private string $status;
			public function __construct( string $status ) { $this->status = $status; }
			public function send( BaleRequest $request ): AttemptResult { ++$this->send_count; $this->last_request = $request; return new AttemptResult( $this->status, 'bale', null, null, array(), 'test' ); }
		};
	}
}
