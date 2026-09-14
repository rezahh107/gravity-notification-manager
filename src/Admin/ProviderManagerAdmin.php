<?php
/**
 * WordPress-native Provider Manager / Senders administration.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Provider\SmsProviderManager;
use RuntimeException;

/**
 * Owns the directly discoverable SMS provider-management surface and explicit test action.
 */
final class ProviderManagerAdmin {

	/** Captured Provider Manager page hook for scoped assets. */
	private static string $screen_hook = '';

	/** Register the Provider Manager surface and explicit real-send action. */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_' . AdminDefinition::PROVIDER_TEST_SMS_ACTION, array( self::class, 'handle_test_sms' ) );
	}

	/** Register Provider Manager as a first-class submenu under the existing GNM parent. */
	public static function register_menu(): void {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}

		$surface = AdminDefinition::provider_surface();
		/* translators: %s: localized Gravity Notification Manager admin surface title. */
		$page_title = sprintf( __( '%s — Gravity Notification Manager', 'gravity-notification-manager' ), $surface['title'] );
		$hook       = add_submenu_page(
			AdminDefinition::ROOT_SLUG,
			$page_title,
			$surface['title'],
			AdminDefinition::CAPABILITY,
			AdminDefinition::PROVIDERS_SLUG,
			array( self::class, 'render' ),
			3
		);

		if ( is_string( $hook ) ) {
			self::$screen_hook = $hook;
		}
	}

	/** Load the established GNM admin stylesheet only on Provider Manager. */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' === self::$screen_hook || self::$screen_hook !== $hook_suffix || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		$dependencies = array();
		if ( function_exists( 'wp_style_is' ) && wp_style_is( 'wp-theme', 'registered' ) ) {
			wp_enqueue_style( 'wp-theme' );
			$dependencies[] = 'wp-theme';
		}

		if ( ! defined( 'GRAVITY_NOTIFY_PLUGIN_URL' ) ) {
			return;
		}

		wp_enqueue_style(
			'gravity-notify-admin',
			GRAVITY_NOTIFY_PLUGIN_URL . 'assets/admin/gnm-admin.css',
			$dependencies,
			null
		);
	}

	/**
	 * Render IPPanel configuration without contacting IPPanel.
	 *
	 * Network I/O occurs only in handle_test_sms() after capability + nonce checks.
	 */
	public static function render(): void {
		self::guard_capability();
		$settings = Settings::read();
		$manager  = new SmsProviderManager( $settings );
		$config   = $manager->configuration( SmsProviderManager::IPPANEL );
		$config   = null === $config
			? array(
				'enabled' => false,
				'api_key' => '',
				'sender'  => '',
			)
			: $config;

		echo '<div class="wrap gnm-admin"><header class="gnm-page-header">';
		echo '<h1>' . esc_html__( 'Providers & Senders', 'gravity-notification-manager' ) . '</h1>';
		echo '<p>' . esc_html__( 'Configure supported SMS providers and sender lines. Saving this page never contacts the provider or sends a message.', 'gravity-notification-manager' ) . '</p></header>';
		self::render_test_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="gnm-panel gnm-settings">';
		settings_fields( Settings::GROUP );
		echo '<div class="gnm-point__heading"><div><h2>' . esc_html__( 'IPPanel', 'gravity-notification-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'IPPanel is the supported SMS provider in this batch.', 'gravity-notification-manager' ) . '</p></div>';
		self::status_badge( $manager->readiness_status( SmsProviderManager::IPPANEL ) );
		echo '</div>';

		echo '<label class="gnm-field"><span>' . esc_html__( 'Enable IPPanel', 'gravity-notification-manager' ) . '</span>';
		echo '<input type="checkbox" name="' . esc_attr( Settings::OPTION ) . '[' . esc_attr( SmsProviderManager::CONFIG_KEY ) . '][' . esc_attr( SmsProviderManager::IPPANEL ) . '][enabled]" value="1"' . ( $config['enabled'] ? ' checked="checked"' : '' ) . '></label>';

		$placeholder = '' !== $config['api_key']
			? __( 'Stored — Enter a new value to replace', 'gravity-notification-manager' )
			: __( 'Not configured', 'gravity-notification-manager' );
		echo '<label class="gnm-field"><span>' . esc_html__( 'IPPanel API key', 'gravity-notification-manager' ) . '</span>';
		echo '<input type="password" class="regular-text gnm-ltr" dir="ltr" name="' . esc_attr( Settings::OPTION ) . '[' . esc_attr( SmsProviderManager::CONFIG_KEY ) . '][' . esc_attr( SmsProviderManager::IPPANEL ) . '][api_key]" value="" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="new-password"></label>';

		echo '<label class="gnm-field"><span>' . esc_html__( 'SMS sender number (E.164)', 'gravity-notification-manager' ) . '</span>';
		echo '<input type="text" class="regular-text gnm-ltr" dir="ltr" name="' . esc_attr( Settings::OPTION ) . '[' . esc_attr( SmsProviderManager::CONFIG_KEY ) . '][' . esc_attr( SmsProviderManager::IPPANEL ) . '][sender]" value="' . esc_attr( $config['sender'] ) . '" placeholder="+982100000000" autocomplete="off"></label>';
		echo '<p class="description">' . esc_html__( 'Enter a sender number assigned to this IPPanel account. Automatic sender-line discovery is not exposed because no current documented enumeration contract was established.', 'gravity-notification-manager' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Stored credentials are never echoed back into this page or diagnostics.', 'gravity-notification-manager' ) . '</p>';
		submit_button( __( 'Save Settings', 'gravity-notification-manager' ) );
		echo '</form>';

		self::render_test_control();
		echo '</div>';
	}

	/** Execute exactly one explicit IPPanel test after capability and target nonce checks. */
	public static function handle_test_sms(): void {
		self::guard_capability();
		check_admin_referer( self::nonce_action(), 'gnm_provider_test_nonce' );

		$raw_destination = isset( $_POST['destination'] ) ? wp_unslash( $_POST['destination'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$destination     = is_string( $raw_destination ) ? sanitize_text_field( $raw_destination ) : '';
		$result          = ProviderTestService::production()->test_sms(
			$destination,
			__( 'Gravity Notification Manager provider test message.', 'gravity-notification-manager' )
		);

		self::store_test_notice( $result );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Render the explicit real-send form. */
	private static function render_test_control(): void {
		echo '<section class="gnm-panel"><h2>' . esc_html__( 'IPPanel / SMS test', 'gravity-notification-manager' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Real external send:', 'gravity-notification-manager' ) . '</strong> ' . esc_html__( 'Submitting this action sends one real SMS through the configured IPPanel account.', 'gravity-notification-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AdminDefinition::PROVIDER_TEST_SMS_ACTION ) . '">';
		wp_nonce_field( self::nonce_action(), 'gnm_provider_test_nonce' );
		echo '<label class="gnm-field"><span>' . esc_html__( 'Test SMS destination (E.164)', 'gravity-notification-manager' ) . '</span><input type="text" class="regular-text gnm-ltr" dir="ltr" name="destination" value="" placeholder="+989121234567" autocomplete="off" required></label>';
		submit_button( __( 'Send Test SMS', 'gravity-notification-manager' ), 'secondary', 'submit', false );
		echo '</form></section>';
	}

	/** Render a one-time bounded provider result notice. */
	private static function render_test_notice(): void {
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'get_transient' ) || ! function_exists( 'delete_transient' ) ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( 0 >= $user_id ) {
			return;
		}

		$key    = self::notice_key( $user_id );
		$notice = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}

		$status = (string) ( $notice['status'] ?? '' );
		if ( ! in_array( $status, array( AttemptStatus::SUCCESS, AttemptStatus::FAILED, AttemptStatus::AMBIGUOUS ), true ) ) {
			return;
		}

		$class = match ( $status ) {
			AttemptStatus::SUCCESS   => 'notice-success',
			AttemptStatus::AMBIGUOUS => 'notice-warning',
			default                  => 'notice-error',
		};
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p><strong>' . esc_html__( 'IPPanel / SMS test', 'gravity-notification-manager' ) . '</strong> — ';
		/* translators: %s: stable provider test status token (SUCCESS, FAILED, or AMBIGUOUS). */
		echo esc_html( sprintf( __( 'Result: %s', 'gravity-notification-manager' ), $status ) ) . '</p>';
		echo '<p>' . esc_html( self::test_detail( (string) ( $notice['diagnostic'] ?? '' ) ) ) . '</p>';

		$references = is_array( $notice['references'] ?? null ) ? $notice['references'] : array();
		if ( array() !== $references ) {
			echo '<p>' . esc_html__( 'Provider reference:', 'gravity-notification-manager' ) . ' <bdi class="gnm-ltr" dir="ltr">' . esc_html( implode( ', ', $references ) ) . '</bdi></p>';
		}
		echo '</div>';
	}

	/** Store only privacy-safe provider result classifications/references. */
	private static function store_test_notice( AttemptResult $result ): void {
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'set_transient' ) ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( 0 >= $user_id ) {
			return;
		}

		$status = $result->status();
		if ( ! in_array( $status, array( AttemptStatus::SUCCESS, AttemptStatus::FAILED, AttemptStatus::AMBIGUOUS ), true ) ) {
			$status = AttemptStatus::FAILED;
		}

		$references = array();
		foreach ( $result->provider_references() as $reference ) {
			if ( is_string( $reference ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $reference ) ) {
				$references[] = $reference;
			}
			if ( 3 <= count( $references ) ) {
				break;
			}
		}

		$diagnostic = $result->diagnostic();
		if ( ! in_array( $diagnostic, self::safe_diagnostics(), true ) ) {
			$diagnostic = 'unknown_result';
		}

		set_transient(
			self::notice_key( $user_id ),
			array(
				'status'     => $status,
				'references' => $references,
				'diagnostic' => $diagnostic,
			),
			120
		);
	}

	/** Return safe provider-test diagnostic classifications only. */
	private static function safe_diagnostics(): array {
		return array(
			'provider_not_configured',
			'invalid_destination',
			'invalid_test_request',
			'accepted',
			'transport_error',
			'http_rejection',
			'provider_rejection',
			'malformed_response',
			'acceptance_unestablished',
			'unknown_result',
		);
	}

	/** Convert one safe diagnostic classification into localized operator guidance. */
	private static function test_detail( string $diagnostic ): string {
		return match ( $diagnostic ) {
			'provider_not_configured' => __( 'IPPanel test could not run because the provider is disabled or its API key/sender number is not configured.', 'gravity-notification-manager' ),
			'invalid_destination' => __( 'Enter a valid E.164 SMS destination.', 'gravity-notification-manager' ),
			'invalid_test_request' => __( 'The test request could not be created safely.', 'gravity-notification-manager' ),
			'accepted' => __( 'The provider accepted the test message.', 'gravity-notification-manager' ),
			'http_rejection', 'provider_rejection' => __( 'The provider rejected the test request.', 'gravity-notification-manager' ),
			'transport_error', 'malformed_response', 'acceptance_unestablished' => __( 'Provider acceptance could not be established safely.', 'gravity-notification-manager' ),
			default => __( 'The provider test did not produce a recognized safe result.', 'gravity-notification-manager' ),
		};
	}

	/** Build an action/provider/surface-bound nonce action. */
	private static function nonce_action(): string {
		return AdminDefinition::PROVIDER_TEST_SMS_ACTION . '_' . SmsProviderManager::IPPANEL . '_' . AdminDefinition::PROVIDERS_SLUG;
	}

	/** Build the short-lived per-user result key. */
	private static function notice_key( int $user_id ): string {
		return 'gravity_notify_provider_manager_test_' . $user_id;
	}

	/** Build the Provider Manager admin URL. */
	private static function page_url(): string {
		return add_query_arg( array( 'page' => AdminDefinition::PROVIDERS_SLUG ), admin_url( 'admin.php' ) );
	}

	/** Enforce the existing admin capability on render and action callbacks. */
	private static function guard_capability(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( AdminDefinition::CAPABILITY ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html__( 'You are not allowed to manage Gravity Notification Manager.', 'gravity-notification-manager' ), '', array( 'response' => 403 ) );
			}
			throw new RuntimeException( 'Unauthorized GNM Provider Manager access.' );
		}
	}

	/** Render a text+visual semantic status cue. */
	private static function status_badge( string $status ): void {
		$class = strtolower( str_replace( '_', '-', $status ) );
		$label = match ( $status ) {
			'CONFIGURED' => __( 'Configured', 'gravity-notification-manager' ),
			'DISABLED' => __( 'Disabled', 'gravity-notification-manager' ),
			default => __( 'Needs Setup', 'gravity-notification-manager' ),
		};
		echo '<span class="gnm-status gnm-status--' . esc_attr( $class ) . '"><span aria-hidden="true">●</span> ' . esc_html( $label ) . '</span>';
	}
}
