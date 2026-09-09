<?php
/**
 * Real WordPress 7.1 WU-07 Entry Detail KSES regression contract.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\RealRuntime;

use GFAPI;
use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\DeliveryState\EntryMetaDeliveryStore;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\ManualRetryHandler;
use GravityNotify\GravityForms\NotificationExecutionResult;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\GravityForms\WordPressManualRetryRuntime;
use GravityNotify\Presentation\DeliveryStatePresentationReader;
use GravityNotify\Presentation\OperationalPresentation;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Delivery\FakeSmsProvider;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Exercises the exact browser-facing Entry Detail sanitizer on WordPress 7.1.
 */
final class OperationalPresentationKsesRealRuntimeTest extends WP_UnitTestCase {

	/**
	 * Greenfield Add-On under test.
	 *
	 * @var NotificationFeedAddOn
	 */
	private NotificationFeedAddOn $add_on;

	/** Fixture Form ID. */
	private int $form_id = 0;

	/** Fixture Entry ID. */
	private int $entry_id = 0;

	/** Original current user ID. */
	private int $original_user_id = 0;

	/** Authorized operator user ID. */
	private int $operator_user_id = 0;

	/** Prepare an authorized real-runtime operator. */
	public function set_up(): void {
		parent::set_up();
		$this->add_on           = NotificationFeedAddOn::get_instance();
		$this->original_user_id = get_current_user_id();
		$this->operator_user_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		$user = get_user_by( 'id', $this->operator_user_id );
		self::assertNotFalse( $user );
		$user->add_cap( WordPressManualRetryRuntime::CAPABILITY );
		wp_set_current_user( $this->operator_user_id );
		self::assertTrue( current_user_can( WordPressManualRetryRuntime::CAPABILITY ) );
	}

	/** Restore request/user state and remove Gravity Forms fixtures. */
	public function tear_down(): void {
		$this->add_on->configure_processor( null );
		$this->add_on->configure_delivery_state_manager( null );
		if ( 0 < $this->entry_id ) {
			GFAPI::delete_entry( $this->entry_id );
		}
		if ( 0 < $this->form_id ) {
			GFAPI::delete_form( $this->form_id );
		}
		wp_set_current_user( $this->original_user_id );
		parent::tear_down();
	}

	/**
	 * T-02-REAL-WP71-RENDER: the authenticated Retry form survives final KSES.
	 *
	 * @testdox WU07-KSES-REAL-01 Entry Detail Retry form survives local KSES
	 */
	public function test_wu07_kses_real_01_entry_detail_retry_form_survives_local_kses(): void {
		$this->create_fixture();
		$feed_id  = $this->add_feed();
		$provider = $this->configure_provider();
		$store    = new EntryMetaDeliveryStore();
		$manager  = new DeliveryStateManager( $store, static fn(): string => '2026-09-09T19:30:00+00:00' );

		self::assertTrue(
			$manager->record_execution(
				$this->entry_id,
				$this->form_id,
				$feed_id,
				'Real KSES retry fixture',
				FeedRuleSchema::CHANNEL_SMS,
				new NotificationExecutionResult(
					array(
						new AttemptResult( AttemptStatus::FAILED, 'sms', 'real-kses-fixture', 'plain', array(), 'fixture' ),
					),
					array(),
					false
				)
			)
		);

		$before = gform_get_meta( $this->entry_id, EntryMetaDeliveryStore::META_KEY );
		$presentation = new OperationalPresentation(
			$this->add_on,
			new DeliveryStatePresentationReader( $store, $manager ),
			new WordPressManualRetryRuntime()
		);

		ob_start();
		$presentation->render_entry_detail_meta_box(
			array(
				'entry' => GFAPI::get_entry( $this->entry_id ),
				'form'  => GFAPI::get_form( $this->form_id ),
			)
		);
		$html = (string) ob_get_clean();
		$after = gform_get_meta( $this->entry_id, EntryMetaDeliveryStore::META_KEY );

		self::assertSame( 0, $provider->send_count );
		self::assertSame( $before, $after );
		self::assertStringContainsString( '<form method="post" action="' . esc_attr( admin_url( 'admin-post.php' ) ) . '">', $html );
		self::assertStringContainsString( 'name="action" value="' . ManualRetryHandler::ACTION . '"', $html );
		self::assertStringContainsString( 'name="entry_id" value="' . $this->entry_id . '"', $html );
		self::assertStringContainsString( 'name="feed_id" value="' . $feed_id . '"', $html );
		self::assertStringContainsString( '<button type="submit" class="button">Retry notification now</button>', $html );
		self::assertStringContainsString( '<bdi dir="ltr">', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );

		$matches = array();
		self::assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $matches ) );
		self::assertNotSame( '', $matches[1] );
		self::assertNotFalse(
			wp_verify_nonce(
				html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				WordPressManualRetryRuntime::nonce_action( $this->entry_id, $feed_id )
			)
		);
	}

	/**
	 * T-03-REAL-WP71-NEGATIVE: local KSES rejects unapproved structure/attributes.
	 *
	 * @testdox WU07-KSES-REAL-02 local allow-list rejects unapproved markup
	 */
	public function test_wu07_kses_real_02_local_allowlist_rejects_unapproved_markup(): void {
		$global_before = wp_kses_allowed_html( 'post' );
		$allowed       = $this->entry_detail_allowed_html();
		$probe         = '<div class="gnm-entry-delivery" aria-live="polite" onclick="bad()">'
			. '<form method="post" action="' . esc_attr( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . ManualRetryHandler::ACTION . '" onerror="bad()" />'
			. '<button type="submit" class="button" style="display:none">Retry</button>'
			. '<bdi dir="ltr" style="direction:rtl">Feed #7</bdi>'
			. '<script>alert(1)</script><iframe src="https://example.invalid"></iframe>'
			. '</form></div>';

		$sanitized = wp_kses( $probe, $allowed );

		self::assertStringContainsString( '<form method="post" action="' . esc_attr( admin_url( 'admin-post.php' ) ) . '">', $sanitized );
		self::assertStringContainsString( 'name="action" value="' . ManualRetryHandler::ACTION . '"', $sanitized );
		self::assertStringContainsString( '<button type="submit" class="button">Retry</button>', $sanitized );
		self::assertStringContainsString( '<bdi dir="ltr">Feed #7</bdi>', $sanitized );
		self::assertStringNotContainsString( '<script', $sanitized );
		self::assertStringNotContainsString( '<iframe', $sanitized );
		self::assertStringNotContainsString( 'onclick=', $sanitized );
		self::assertStringNotContainsString( 'onerror=', $sanitized );
		self::assertStringNotContainsString( 'style=', $sanitized );
		self::assertSame( $global_before, wp_kses_allowed_html( 'post' ) );
	}

	/** Create a real persisted Form and Entry. */
	private function create_fixture(): void {
		$this->form_id = GFAPI::add_form(
			array(
				'title'  => 'GNM WU-07 KSES fixture',
				'fields' => array(
					array(
						'id'    => 1,
						'type'  => 'text',
						'label' => 'Name',
					),
				),
			)
		);
		self::assertGreaterThan( 0, $this->form_id );
		$this->entry_id = GFAPI::add_entry(
			array(
				'form_id' => $this->form_id,
				'1'       => 'Alice',
			)
		);
		self::assertGreaterThan( 0, $this->entry_id );
	}

	/** Persist one applicable GNM Feed. */
	private function add_feed(): int {
		$feed_id = GFAPI::add_feed(
			$this->form_id,
			array(
				'feedName'               => 'Real KSES retry fixture',
				'message'                => 'Never sent by render',
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
				'recipient_source_value' => '+15550000000',
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
			),
			$this->add_on->get_slug()
		);
		self::assertNotWPError( $feed_id );
		self::assertIsInt( $feed_id );
		self::assertGreaterThan( 0, $feed_id );
		return $feed_id;
	}

	/**
	 * Compose the normal synchronous chain with a deterministic no-network provider.
	 *
	 * @return FakeSmsProvider
	 */
	private function configure_provider(): FakeSmsProvider {
		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS );
		$resolver = new RecipientResolver(
			new FakeEntryFieldReader( array() ),
			new FakeUserDirectory( array(), array(), array() ),
			new FakeFlowAssigneeReader(
				array(
					'available' => false,
					'reason'    => 'not_used',
					'assignees' => array(),
				)
			)
		);
		$this->add_on->configure_processor(
			new NotificationFeedProcessor(
				$resolver,
				new SynchronousDispatcher( new SmsProviderRegistry( array( $provider ) ) ),
				'+15550000001'
			)
		);
		return $provider;
	}

	/**
	 * Read the private production allow-list without adding a public API.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function entry_detail_allowed_html(): array {
		$method = new ReflectionMethod( OperationalPresentation::class, 'entry_detail_allowed_html' );
		$method->setAccessible( true );
		$allowed = $method->invoke( null );
		self::assertIsArray( $allowed );
		return $allowed;
	}
}
