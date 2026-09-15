<?php
/**
 * WordPress-native SMS Provider Manager administration.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Provider\Connection\ProviderConnectionResult;
use GravityNotify\Provider\Discovery\SenderLineDiscoveryResult;
use GravityNotify\Provider\SmsProviderManager;
use RuntimeException;

/** Owns provider configuration plus explicit connection, discovery, and real-test actions. */
final class ProviderManagerAdmin {

	/** Provider Manager screen hook assigned by WordPress. */
	private static string $screen_hook = '';

	/** Register the surface and explicit provider-management actions. */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_' . AdminDefinition::PROVIDER_TEST_SMS_ACTION, array( self::class, 'handle_test_sms' ) );
		add_action( 'admin_post_' . AdminDefinition::PROVIDER_CHECK_CONNECTION_ACTION, array( self::class, 'handle_check_connection' ) );
		add_action( 'admin_post_' . AdminDefinition::PROVIDER_DISCOVER_LINES_ACTION, array( self::class, 'handle_discover_lines' ) );
	}

	/** Register Provider Manager under the GNM root. */
	public static function register_menu(): void {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}
		$surface = AdminDefinition::provider_surface();
		/* translators: %s: SMS Provider Manager surface title. */
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

	/**
	 * Load the existing GNM admin stylesheet only on the Provider Manager screen.
	 *
	 * @param string $hook_suffix Current WordPress admin screen hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' === self::$screen_hook || self::$screen_hook !== $hook_suffix || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}
		$dependencies = array();
		if ( function_exists( 'wp_style_is' ) && wp_style_is( 'wp-theme', 'registered' ) ) {
			wp_enqueue_style( 'wp-theme' );
			$dependencies[] = 'wp-theme';
		}
		if ( defined( 'GRAVITY_NOTIFY_PLUGIN_URL' ) ) {
			wp_enqueue_style( 'gravity-notify-admin', GRAVITY_NOTIFY_PLUGIN_URL . 'assets/admin/gnm-admin.css', $dependencies, null );
		}
	}

	/** Render all four approved providers with zero provider/network I/O. */
	public static function render(): void {
		self::guard_capability();
		$manager = new SmsProviderManager( Settings::read() );

		echo '<div class="wrap gnm-admin"><header class="gnm-page-header">';
		echo '<h1>' . esc_html__( 'SMS Providers', 'gravity-notification-manager' ) . '</h1>';
		echo '<p>' . esc_html__( 'Configure approved SMS providers here. Opening this page and saving ordinary settings never contacts a provider or sends a message.', 'gravity-notification-manager' ) . '</p></header>';
		self::render_notice();

		foreach ( SmsProviderManager::definitions() as $identifier => $definition ) {
			$config = $manager->configuration( $identifier );
			if ( null === $config ) {
				continue;
			}
			self::render_provider_settings( $identifier, $definition, $config, $manager );
			self::render_provider_actions( $identifier, $definition );
		}
		echo '</div>';
	}

	/** Execute exactly one explicit provider test. */
	public static function handle_test_sms(): void {
		self::guard_capability();
		$provider = self::posted_provider();
		check_admin_referer( self::nonce_action( AdminDefinition::PROVIDER_TEST_SMS_ACTION, $provider ), 'gnm_provider_test_nonce' );

		$raw_destination = isset( $_POST['destination'] ) ? wp_unslash( $_POST['destination'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Action-bound nonce verified immediately above.
		$destination     = is_string( $raw_destination ) ? sanitize_text_field( $raw_destination ) : '';
		$result          = ProviderTestService::production()->test_sms(
			$destination,
			__( 'Gravity Notification Manager provider test message.', 'gravity-notification-manager' ),
			$provider
		);
		self::store_test_notice( $provider, $result );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Execute one explicit connection validation. */
	public static function handle_check_connection(): void {
		self::guard_capability();
		$provider = self::posted_provider();
		check_admin_referer( self::nonce_action( AdminDefinition::PROVIDER_CHECK_CONNECTION_ACTION, $provider ), 'gnm_provider_connection_nonce' );

		$result = ProviderConnectionService::production()->check( $provider );
		self::store_connection_notice( $provider, $result );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Execute explicit line refresh only for a verified discovery-capable provider. */
	public static function handle_discover_lines(): void {
		self::guard_capability();
		$provider = self::posted_provider();
		check_admin_referer( self::nonce_action( AdminDefinition::PROVIDER_DISCOVER_LINES_ACTION, $provider ), 'gnm_provider_discovery_nonce' );

		$result = ProviderDiscoveryService::production()->discover( $provider );
		if ( $result->successful() ) {
			Settings::persist_discovered_lines( $provider, $result->lines() );
		}
		self::store_discovery_notice( $provider, $result );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Render one provider configuration form.
	 *
	 * @param string               $identifier Provider identifier.
	 * @param array<string, mixed> $definition Provider definition.
	 * @param array<string, mixed> $config     Provider config.
	 * @param SmsProviderManager   $manager    Provider Manager instance.
	 */
	private static function render_provider_settings( string $identifier, array $definition, array $config, SmsProviderManager $manager ): void {
		$label = (string) $definition['label'];
		echo '<section class="gnm-panel gnm-settings">';
		echo '<div class="gnm-point__heading"><div><h2>' . esc_html( $label ) . '</h2></div>';
		self::status_badge( $manager->readiness_status( $identifier ) );
		echo '</div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( Settings::GROUP );

		$base = Settings::OPTION . '[' . SmsProviderManager::CONFIG_KEY . '][' . $identifier . ']';
		echo '<label class="gnm-field"><span>' . esc_html__( 'Enabled', 'gravity-notification-manager' ) . '</span>';
		echo '<input type="checkbox" name="' . esc_attr( $base . '[enabled]' ) . '" value="1"' . ( ! empty( $config['enabled'] ) ? ' checked="checked"' : '' ) . '></label>';

		foreach ( $definition['credentials'] as $field => $metadata ) {
			self::render_credential( $base, $field, $metadata, $config );
		}
		self::render_sender_field( $base, $identifier, $config );
		echo '<p class="description">' . esc_html__( 'Stored secrets are write-only and are never echoed into this page or diagnostics.', 'gravity-notification-manager' ) . '</p>';
		submit_button( __( 'Save Provider', 'gravity-notification-manager' ) );
		echo '</form></section>';
	}

	/**
	 * Render one write-only or non-secret credential input.
	 *
	 * @param string               $base     Field-name base.
	 * @param string               $field    Credential field name.
	 * @param array<string, mixed> $metadata Credential metadata.
	 * @param array<string, mixed> $config   Provider configuration.
	 */
	private static function render_credential( string $base, string $field, array $metadata, array $config ): void {
		$secret      = true === ( $metadata['secret'] ?? false );
		$stored      = '' !== (string) ( $config[ $field ] ?? '' );
		$value       = $secret ? '' : (string) ( $config[ $field ] ?? '' );
		$placeholder = $secret
			? ( $stored ? __( 'Stored — enter a new value to replace', 'gravity-notification-manager' ) : __( 'Not configured', 'gravity-notification-manager' ) )
			: '';

		echo '<label class="gnm-field"><span>' . esc_html( self::credential_label( $field ) ) . '</span>';
		echo '<input type="' . ( $secret ? 'password' : 'text' ) . '" class="regular-text gnm-ltr" dir="ltr" name="' . esc_attr( $base . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="' . ( $secret ? 'new-password' : 'off' ) . '"></label>';
	}

	/**
	 * Render discovery-backed selection or an explicit manual sender fallback.
	 *
	 * @param string               $base       Field-name base.
	 * @param string               $identifier Provider identifier.
	 * @param array<string, mixed> $config     Provider configuration.
	 */
	private static function render_sender_field( string $base, string $identifier, array $config ): void {
		$sender = (string) ( $config['sender'] ?? '' );
		if ( SmsProviderManager::supports_discovery( $identifier ) ) {
			$lines = is_array( $config['discovered_lines'] ?? null ) ? $config['discovered_lines'] : array();
			if ( '' !== $sender && ! in_array( $sender, $lines, true ) ) {
				array_unshift( $lines, $sender );
			}
			echo '<label class="gnm-field"><span>' . esc_html__( 'Sender line', 'gravity-notification-manager' ) . '</span>';
			echo '<select class="regular-text gnm-ltr" dir="ltr" name="' . esc_attr( $base . '[sender]' ) . '">';
			echo '<option value="">' . esc_html__( 'Select a discovered line', 'gravity-notification-manager' ) . '</option>';
			foreach ( $lines as $line ) {
				$selected = $sender === $line || ( '' === $sender && 1 === count( $lines ) );
				echo '<option value="' . esc_attr( (string) $line ) . '"' . ( $selected ? ' selected="selected"' : '' ) . '>' . esc_html( (string) $line ) . '</option>';
			}
			echo '</select></label>';
			if ( array() === $lines ) {
				echo '<p class="description">' . esc_html__( 'Save the required credentials, then use Refresh Lines below. A provider request is made only when you press that button.', 'gravity-notification-manager' ) . '</p>';
			}
			return;
		}

		echo '<label class="gnm-field"><span>' . esc_html__( 'Sender line', 'gravity-notification-manager' ) . '</span>';
		echo '<input type="text" class="regular-text gnm-ltr" dir="ltr" name="' . esc_attr( $base . '[sender]' ) . '" value="' . esc_attr( $sender ) . '" autocomplete="off"></label>';
		echo '<p class="description">' . esc_html( self::manual_sender_explanation( $identifier ) ) . '</p>';
	}

	/**
	 * Render explicit provider operations outside ordinary Settings API save.
	 *
	 * @param string               $identifier Provider identifier.
	 * @param array<string, mixed> $definition Provider definition.
	 */
	private static function render_provider_actions( string $identifier, array $definition ): void {
		$label = (string) $definition['label'];
		echo '<section class="gnm-panel"><h2>' . esc_html( $label ) . ' — ' . esc_html__( 'Provider actions', 'gravity-notification-manager' ) . '</h2>';
		echo '<div class="gnm-actions">';

		self::render_action_form(
			AdminDefinition::PROVIDER_CHECK_CONNECTION_ACTION,
			$identifier,
			'gnm_provider_connection_nonce',
			__( 'Check Connection', 'gravity-notification-manager' ),
			'primary'
		);
		if ( SmsProviderManager::supports_discovery( $identifier ) ) {
			self::render_action_form(
				AdminDefinition::PROVIDER_DISCOVER_LINES_ACTION,
				$identifier,
				'gnm_provider_discovery_nonce',
				__( 'Refresh Lines', 'gravity-notification-manager' ),
				'secondary'
			);
		}
		echo '</div>';

		echo '<hr><h3>' . esc_html__( 'Real external send', 'gravity-notification-manager' ) . '</h3>';
		echo '<p>' . esc_html__( 'Submitting the test below sends one real SMS through this provider. Saving settings, checking this page, or refreshing the page does not send a message.', 'gravity-notification-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AdminDefinition::PROVIDER_TEST_SMS_ACTION ) . '">';
		echo '<input type="hidden" name="provider" value="' . esc_attr( $identifier ) . '">';
		wp_nonce_field( self::nonce_action( AdminDefinition::PROVIDER_TEST_SMS_ACTION, $identifier ), 'gnm_provider_test_nonce' );
		echo '<label class="gnm-field"><span>' . esc_html__( 'Test SMS destination (E.164)', 'gravity-notification-manager' ) . '</span><input type="text" class="regular-text gnm-ltr" dir="ltr" name="destination" value="" placeholder="+989121234567" autocomplete="off" required></label>';
		submit_button( __( 'Send Test SMS', 'gravity-notification-manager' ), 'secondary', 'submit', false );
		echo '</form></section>';
	}

	/**
	 * Render one explicit action form with provider-bound nonce.
	 *
	 * @param string $action     Admin-post action.
	 * @param string $provider   Provider identifier.
	 * @param string $nonce_name Nonce field name.
	 * @param string $label      Submit label.
	 * @param string $class      Submit button class.
	 */
	private static function render_action_form( string $action, string $provider, string $nonce_name, string $label, string $class ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="provider" value="' . esc_attr( $provider ) . '">';
		wp_nonce_field( self::nonce_action( $action, $provider ), $nonce_name );
		submit_button( $label, $class, 'submit', false );
		echo '</form>';
	}

	/** Render one short-lived, privacy-safe result notice. */
	private static function render_notice(): void {
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

		$kind     = (string) ( $notice['kind'] ?? '' );
		$provider = (string) ( $notice['provider'] ?? '' );
		$status   = (string) ( $notice['status'] ?? '' );
		$class    = 'SUCCESS' === $status ? 'notice-success' : ( 'AMBIGUOUS' === $status ? 'notice-warning' : 'notice-error' );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p><strong>' . esc_html( self::provider_label( $provider ) ) . '</strong> — ' . esc_html( self::notice_title( $kind ) ) . '</p>';
		echo '<p>' . esc_html( self::notice_detail( (string) ( $notice['diagnostic'] ?? '' ) ) ) . '</p>';

		$references = is_array( $notice['references'] ?? null ) ? $notice['references'] : array();
		if ( array() !== $references ) {
			echo '<p>' . esc_html__( 'Provider reference:', 'gravity-notification-manager' ) . ' <bdi class="gnm-ltr" dir="ltr">' . esc_html( implode( ', ', $references ) ) . '</bdi></p>';
		}
		$line_count = isset( $notice['line_count'] ) ? (int) $notice['line_count'] : null;
		if ( null !== $line_count ) {
			/* translators: %d: number of discovered sender lines. */
			echo '<p>' . esc_html( sprintf( __( 'Discovered lines: %d', 'gravity-notification-manager' ), $line_count ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Store only bounded TEST result evidence.
	 *
	 * @param string        $provider Provider identifier.
	 * @param AttemptResult $result   Test attempt result.
	 */
	private static function store_test_notice( string $provider, AttemptResult $result ): void {
		$references = array();
		foreach ( $result->provider_references() as $reference ) {
			if ( ( is_int( $reference ) || is_string( $reference ) ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', (string) $reference ) ) {
				$references[] = (string) $reference;
			}
			if ( 3 <= count( $references ) ) {
				break;
			}
		}
		self::store_notice(
			array(
				'kind'       => 'test',
				'provider'   => $provider,
				'status'     => in_array( $result->status(), array( AttemptStatus::SUCCESS, AttemptStatus::FAILED, AttemptStatus::AMBIGUOUS ), true ) ? $result->status() : AttemptStatus::FAILED,
				'diagnostic' => self::safe_diagnostic( $result->diagnostic() ),
				'references' => $references,
			)
		);
	}

	/**
	 * Store only bounded connection result evidence.
	 *
	 * @param string                   $provider Provider identifier.
	 * @param ProviderConnectionResult $result   Connection result.
	 */
	private static function store_connection_notice( string $provider, ProviderConnectionResult $result ): void {
		self::store_notice(
			array(
				'kind'       => 'connection',
				'provider'   => $provider,
				'status'     => $result->successful() ? 'SUCCESS' : 'FAILED',
				'diagnostic' => self::safe_diagnostic( $result->diagnostic() ),
			)
		);
	}

	/**
	 * Store only bounded discovery result evidence.
	 *
	 * @param string                    $provider Provider identifier.
	 * @param SenderLineDiscoveryResult $result   Discovery result.
	 */
	private static function store_discovery_notice( string $provider, SenderLineDiscoveryResult $result ): void {
		self::store_notice(
			array(
				'kind'       => 'discovery',
				'provider'   => $provider,
				'status'     => $result->successful() ? 'SUCCESS' : 'FAILED',
				'diagnostic' => self::safe_diagnostic( $result->diagnostic() ),
				'line_count' => count( $result->lines() ),
			)
		);
	}

	/**
	 * Persist a short-lived per-user notice without credentials, destinations, or raw bodies.
	 *
	 * @param array<string, mixed> $notice Safe notice payload.
	 */
	private static function store_notice( array $notice ): void {
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'set_transient' ) ) {
			return;
		}
		$user_id = (int) get_current_user_id();
		if ( 0 < $user_id ) {
			set_transient( self::notice_key( $user_id ), $notice, 120 );
		}
	}

	/**
	 * Return a safe allowlisted diagnostic token.
	 *
	 * @param string $diagnostic Candidate diagnostic token.
	 * @return string
	 */
	private static function safe_diagnostic( string $diagnostic ): string {
		return in_array( $diagnostic, self::safe_diagnostics(), true ) ? $diagnostic : 'unknown_result';
	}

	/**
	 * Return the allowlisted provider-management diagnostic tokens.
	 *
	 * @return array<int, string>
	 */
	private static function safe_diagnostics(): array {
		return array(
			'provider_not_configured',
			'invalid_destination',
			'invalid_test_request',
			'invalid_sender_format',
			'invalid_address_format',
			'accepted',
			'transport_error',
			'http_rejection',
			'provider_rejection',
			'authentication_failed',
			'malformed_response',
			'acceptance_unestablished',
			'connection_validated',
			'lines_refreshed',
			'discovery_unavailable',
			'unknown_result',
		);
	}

	/**
	 * Convert a safe diagnostic to localized operator guidance.
	 *
	 * @param string $diagnostic Safe diagnostic token.
	 * @return string
	 */
	private static function notice_detail( string $diagnostic ): string {
		return match ( $diagnostic ) {
			'provider_not_configured' => __( 'Save the required provider credentials and sender configuration first.', 'gravity-notification-manager' ),
			'invalid_destination' => __( 'Enter a valid E.164 SMS destination.', 'gravity-notification-manager' ),
			'invalid_test_request', 'invalid_sender_format', 'invalid_address_format' => __( 'The provider request could not be created safely from the current configuration.', 'gravity-notification-manager' ),
			'accepted' => __( 'The provider accepted the test message.', 'gravity-notification-manager' ),
			'connection_validated' => __( 'The provider accepted the configured credentials.', 'gravity-notification-manager' ),
			'lines_refreshed' => __( 'Sender lines were refreshed successfully.', 'gravity-notification-manager' ),
			'authentication_failed', 'http_rejection', 'provider_rejection' => __( 'The provider rejected the request or credentials.', 'gravity-notification-manager' ),
			'discovery_unavailable' => __( 'Sender-line discovery is not available for this provider; use the manual sender field.', 'gravity-notification-manager' ),
			'transport_error', 'malformed_response', 'acceptance_unestablished' => __( 'The provider result could not be established safely.', 'gravity-notification-manager' ),
			default => __( 'The provider action did not produce a recognized safe result.', 'gravity-notification-manager' ),
		};
	}

	/**
	 * Return a localized notice title.
	 *
	 * @param string $kind Notice kind.
	 * @return string
	 */
	private static function notice_title( string $kind ): string {
		return match ( $kind ) {
			'test' => __( 'Test result', 'gravity-notification-manager' ),
			'connection' => __( 'Connection result', 'gravity-notification-manager' ),
			'discovery' => __( 'Sender discovery result', 'gravity-notification-manager' ),
			default => __( 'Provider result', 'gravity-notification-manager' ),
		};
	}

	/** Validate one provider ID before it participates in nonce derivation. */
	private static function posted_provider(): string {
		$raw      = isset( $_POST['provider'] ) ? wp_unslash( $_POST['provider'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Provider ID is required to derive the provider-bound nonce.
		$provider = is_string( $raw ) ? sanitize_key( $raw ) : '';
		if ( ! in_array( $provider, SmsProviderManager::identifiers(), true ) ) {
			self::fail_request( __( 'Invalid SMS provider.', 'gravity-notification-manager' ) );
		}
		return $provider;
	}

	/**
	 * Build the provider-bound nonce action.
	 *
	 * @param string $action   Admin-post action.
	 * @param string $provider Provider identifier.
	 * @return string
	 */
	private static function nonce_action( string $action, string $provider ): string {
		return $action . '_' . $provider . '_' . AdminDefinition::PROVIDERS_SLUG;
	}

	/**
	 * Build the per-user transient notice key.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	private static function notice_key( int $user_id ): string {
		return 'gravity_notify_provider_manager_notice_' . $user_id;
	}

	/** Return the Provider Manager admin URL. */
	private static function page_url(): string {
		return add_query_arg( array( 'page' => AdminDefinition::PROVIDERS_SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Return a provider label from the fixed supported definitions.
	 *
	 * @param string $provider Provider identifier.
	 * @return string
	 */
	private static function provider_label( string $provider ): string {
		$definition = SmsProviderManager::definitions()[ $provider ] ?? null;
		return is_array( $definition ) ? (string) $definition['label'] : $provider;
	}

	/**
	 * Return the localized label for a provider credential field.
	 *
	 * @param string $field Credential field name.
	 * @return string
	 */
	private static function credential_label( string $field ): string {
		return match ( $field ) {
			'username' => __( 'Username', 'gravity-notification-manager' ),
			'password' => __( 'Password', 'gravity-notification-manager' ),
			default => __( 'API key', 'gravity-notification-manager' ),
		};
	}

	/**
	 * Explain why the current provider uses manual sender input.
	 *
	 * @param string $provider Provider identifier.
	 * @return string
	 */
	private static function manual_sender_explanation( string $provider ): string {
		return match ( $provider ) {
			SmsProviderManager::IPPANEL => __( 'Current official IPPanel documentation does not establish an account sender-line enumeration contract, so the sender remains a manual value.', 'gravity-notification-manager' ),
			SmsProviderManager::MELIPAYAMAK => __( 'Melipayamak exposes GetUserNumbers, but the current first-party material does not define a stable list response shape for safe parsing here, so the sender remains manual.', 'gravity-notification-manager' ),
			SmsProviderManager::FARAZSMS => __( 'FarazSMS line access uses a separate Bearer-authenticated contract whose current public response schema is not sufficient for safe line parsing with the configured API key, so the sender remains manual.', 'gravity-notification-manager' ),
			default => __( 'Enter the sender line authorized for this provider account.', 'gravity-notification-manager' ),
		};
	}

	/**
	 * Enforce the existing admin capability on render/action callbacks.
	 *
	 * @throws RuntimeException When WordPress authorization helpers are unavailable or access is denied.
	 */
	private static function guard_capability(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( AdminDefinition::CAPABILITY ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html__( 'You are not allowed to manage Gravity Notification Manager.', 'gravity-notification-manager' ), '', array( 'response' => 403 ) );
			}
			throw new RuntimeException( 'Unauthorized GNM Provider Manager access.' );
		}
	}

	/**
	 * Fail a malformed privileged request.
	 *
	 * @param string $message Safe operator-facing error message.
	 * @throws RuntimeException When WordPress wp_die() is unavailable.
	 */
	private static function fail_request( string $message ): void {
		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html( $message ), '', array( 'response' => 400 ) );
		}
		throw new RuntimeException( 'Invalid GNM Provider Manager request.' );
	}

	/**
	 * Render text plus a visual semantic readiness cue.
	 *
	 * @param string $status Readiness status.
	 */
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
