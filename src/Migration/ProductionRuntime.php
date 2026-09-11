<?php
/**
 * WU-08 production registration and synchronous composition.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

use GravityNotify\Admin\Settings;
use GravityNotify\Delivery\Bale\BaleClient;
use GravityNotify\Delivery\Http\WordPressHttpTransport;
use GravityNotify\Delivery\Sms\IPPanelProvider;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Recipient\Native\GravityFlowAssigneeReader;
use GravityNotify\Recipient\Native\GravityFormsEntryFieldReader;
use GravityNotify\Recipient\Native\WordPressUserDirectory;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Support\NoSendGuard;

/**
 * Enables the greenfield runtime only when every active GNM Feed is cutover-authorized.
 */
final class ProductionRuntime {

	/** Register the supported Gravity Forms add-on bootstrap hook. */
	public static function boot(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'gform_loaded', array( self::class, 'register' ), 5 );
		}
	}

	/** Register and configure the production Feed Add-On fail-closed. */
	public static function register(): void {
		self::register_with_endpoint( null );
	}

	/**
	 * Register the production composition against one test-only loopback endpoint.
	 *
	 * This seam is unavailable unless the automated no-send guard is active.
	 *
	 * @param string $endpoint Local IPPanel simulator send endpoint.
	 */
	public static function register_with_test_ippanel_endpoint( string $endpoint ): void {
		NoSendGuard::assert_test_loopback_http_url( $endpoint );
		self::register_with_endpoint( $endpoint );
	}

	/**
	 * Register and configure with the selected provider endpoint.
	 *
	 * @param string|null $test_endpoint Test-only loopback endpoint or production default.
	 */
	private static function register_with_endpoint( ?string $test_endpoint ): void {
		if ( ! class_exists( '\\GFForms' ) || ! method_exists( '\\GFForms', 'include_addon_framework' ) ) {
			return;
		}
		\GFForms::include_addon_framework();
		if ( ! class_exists( '\\GFAddOn' ) || ! class_exists( NotificationFeedAddOn::class ) ) {
			return;
		}
		\GFAddOn::register( NotificationFeedAddOn::class );
		$add_on = NotificationFeedAddOn::get_instance();
		if ( ! self::all_active_feeds_authorized( $add_on ) ) {
			$add_on->configure_processor( null );
			return;
		}
		$add_on->configure_processor( self::processor( $test_endpoint ) );
	}

	/**
	 * Compose the existing synchronous greenfield processor.
	 *
	 * @param string|null $test_endpoint Test-only loopback IPPanel endpoint.
	 * @return NotificationFeedProcessor
	 */
	private static function processor( ?string $test_endpoint = null ): NotificationFeedProcessor {
		$settings  = Settings::read();
		$http      = new WordPressHttpTransport();
		$providers = array();
		if ( '' !== ( $settings['ippanel_api_key'] ?? '' ) ) {
			$providers[] = new IPPanelProvider( $settings['ippanel_api_key'], $http, $test_endpoint );
		}
		$bale = '' !== ( $settings['bale_bot_token'] ?? '' ) ? new BaleClient( $settings['bale_bot_token'], $http ) : null;
		$resolver = new RecipientResolver(
			new GravityFormsEntryFieldReader(),
			new WordPressUserDirectory(),
			new GravityFlowAssigneeReader()
		);
		return new NotificationFeedProcessor(
			$resolver,
			new SynchronousDispatcher( new SmsProviderRegistry( $providers ), $bale ),
			$settings['sms_from_number'] ?? ''
		);
	}

	/**
	 * Require explicit cutover authority for every active GNM Feed.
	 *
	 * @param NotificationFeedAddOn $add_on Registered GNM Feed Add-On.
	 * @return bool
	 */
	private static function all_active_feeds_authorized( NotificationFeedAddOn $add_on ): bool {
		if ( ! class_exists( '\\GFAPI' ) ) {
			return false;
		}
		$feeds = \GFAPI::get_feeds( null, null, $add_on->get_slug(), true );
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $feeds ) ) || ! is_array( $feeds ) || array() === $feeds ) {
			return false;
		}
		foreach ( $feeds as $feed ) {
			if ( ! is_array( $feed ) || ! CutoverRegistry::feed_authorized( (int) ( $feed['id'] ?? 0 ) ) ) {
				return false;
			}
		}
		return true;
	}
}
