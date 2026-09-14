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
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Observability\OperationalLogger;
use GravityNotify\Provider\SmsProviderManager;
use GravityNotify\Recipient\Native\GravityFlowAssigneeReader;
use GravityNotify\Recipient\Native\GravityFormsEntryFieldReader;
use GravityNotify\Recipient\Native\WordPressUserDirectory;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Support\NoSendGuard;

/** Enables the greenfield runtime only when every active GNM Feed is cutover-authorized. */
final class ProductionRuntime {

	public static function boot(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'gform_loaded', array( self::class, 'register' ), 5 );
		}
	}

	public static function register(): void {
		self::register_with_endpoint( null );
	}

	public static function register_with_test_ippanel_endpoint( string $endpoint ): void {
		NoSendGuard::assert_test_loopback_http_url( $endpoint );
		self::register_with_endpoint( $endpoint );
	}

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

	private static function processor( ?string $test_endpoint = null ): NotificationFeedProcessor {
		$settings         = Settings::read();
		$http             = new WordPressHttpTransport();
		$provider_manager = new SmsProviderManager( $settings );
		$providers        = $provider_manager->enabled_providers( $http, $test_endpoint );
		$bale             = '' !== ( $settings['bale_bot_token'] ?? '' ) ? new BaleClient( $settings['bale_bot_token'], $http ) : null;
		$resolver         = new RecipientResolver(
			new GravityFormsEntryFieldReader(),
			new WordPressUserDirectory(),
			new GravityFlowAssigneeReader()
		);
		return new NotificationFeedProcessor(
			$resolver,
			new SynchronousDispatcher( new SmsProviderRegistry( $providers ), $bale ),
			$provider_manager->configured_sender( SmsProviderManager::IPPANEL ),
			OperationalLogger::production()
		);
	}

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
