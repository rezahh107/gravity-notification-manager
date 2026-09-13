<?php
/**
 * Modern native WordPress admin foundation for Gravity Notification Manager.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use InvalidArgumentException;
use RuntimeException;

/**
 * Registers and renders exactly the bounded WU-06 admin surfaces.
 */
final class AdminController {

	/**
	 * Whether hooks have already been registered this request.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Captured GNM page hook suffixes for asset scoping.
	 *
	 * @var array<int, string>
	 */
	private static array $screen_hooks = array();

	/**
	 * Register native WordPress admin hooks without sending or mutating Flow topology.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted || ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_menu', array( self::class, 'register_menus' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_' . AdminDefinition::CHECK_ACTION, array( self::class, 'handle_check_again' ) );
		self::$booted = true;
	}

	/**
	 * Register exactly Overview / Notification Points / Settings / Help & Diagnostics.
	 *
	 * @return void
	 */
	public static function register_menus(): void {
		if ( ! function_exists( 'add_menu_page' ) || ! function_exists( 'add_submenu_page' ) ) {
			return;
		}

		$root = add_menu_page(
			__( 'Gravity Notification Manager', 'gravity-notification-manager' ),
			__( 'Notification Manager', 'gravity-notification-manager' ),
			AdminDefinition::CAPABILITY,
			AdminDefinition::ROOT_SLUG,
			array( self::class, 'render_overview' ),
			'dashicons-email-alt',
			58
		);
		self::remember_hook( $root );

		foreach ( AdminDefinition::surfaces() as $surface ) {
			$callback = match ( $surface['slug'] ) {
				AdminDefinition::POINTS_SLUG      => 'render_points',
				AdminDefinition::SETTINGS_SLUG    => 'render_settings',
				AdminDefinition::DIAGNOSTICS_SLUG => 'render_diagnostics',
				default                           => 'render_overview',
			};

			/* translators: %s: localized Gravity Notification Manager admin surface title. */
			$page_title = sprintf( __( '%s — Gravity Notification Manager', 'gravity-notification-manager' ), $surface['title'] );
			$hook       = add_submenu_page(
				AdminDefinition::ROOT_SLUG,
				$page_title,
				$surface['title'],
				AdminDefinition::CAPABILITY,
				AdminDefinition::ROOT_SLUG === $surface['slug'] ? AdminDefinition::ROOT_SLUG : $surface['slug'],
				array( self::class, $callback )
			);
			self::remember_hook( $hook );
		}
	}

	/**
	 * Register the bounded namespaced settings option through the Settings API.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}

		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize_option' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Load the single GNM stylesheet only on captured GNM admin screens.
	 *
	 * @param string $hook_suffix Current wp-admin hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, self::$screen_hooks, true ) || ! function_exists( 'wp_enqueue_style' ) ) {
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
	 * Render fast operational orientation from current authoritative reads.
	 *
	 * @return void
	 */
	public static function render_overview(): void {
		self::guard_capability();
		$points  = ( new PointInspector( new WordPressConfigurationSource() ) )->all();
		$summary = OverviewSummary::from_points( $points );

		self::header(
			__( 'Overview', 'gravity-notification-manager' ),
			__( 'Operational configuration health from current Feed and Flow truth.', 'gravity-notification-manager' )
		);
		echo '<div class="gnm-stats">';
		self::stat( __( 'Notification Points', 'gravity-notification-manager' ), $summary['total'] );
		self::stat( __( 'Configured', 'gravity-notification-manager' ), $summary['configured'] );
		self::stat( __( 'Needs Setup', 'gravity-notification-manager' ), $summary['needs_setup'] );
		self::stat( __( 'Unavailable / Disabled', 'gravity-notification-manager' ), $summary['not_applicable'] + $summary['disabled'] );
		echo '</div>';

		self::facts_panel( __( 'Environment', 'gravity-notification-manager' ), Environment::facts() );
		self::facts_panel( __( 'Channel readiness', 'gravity-notification-manager' ), Settings::diagnostic_facts() );

		if ( $summary['needs_setup'] > 0 ) {
			echo '<div class="gnm-panel"><h2>' . esc_html__( 'Configuration warning', 'gravity-notification-manager' ) . '</h2>';
			echo '<p>' . esc_html__( 'One or more Notification Points need operator setup. GNM will not repair workflow topology automatically.', 'gravity-notification-manager' ) . '</p>';
			echo '<a class="button button-primary" href="' . esc_url( self::admin_page_url( AdminDefinition::POINTS_SLUG ) ) . '">' . esc_html__( 'Review Notification Points', 'gravity-notification-manager' ) . '</a></div>';
		}

		self::footer();
	}

	/**
	 * Render read-only Point Manager guidance and verification controls.
	 *
	 * @return void
	 */
	public static function render_points(): void {
		self::guard_capability();
		$points = ( new PointInspector( new WordPressConfigurationSource() ) )->all();

		self::header(
			__( 'Notification Points', 'gravity-notification-manager' ),
			__( 'Read-only guidance and verification. Gravity Flow remains the workflow topology authority.', 'gravity-notification-manager' )
		);
		if ( empty( $points ) ) {
			echo '<div class="gnm-panel gnm-empty"><h2>' . esc_html__( 'No notification Feeds detected', 'gravity-notification-manager' ) . '</h2><p>' . esc_html__( 'Create or enable a Gravity Notification Manager Feed in Gravity Forms. If dependencies are unavailable, check Help & Diagnostics.', 'gravity-notification-manager' ) . '</p></div>';
			self::footer();
			return;
		}

		echo '<div class="gnm-grid">';
		foreach ( $points as $point ) {
			self::render_point_card( $point );
		}
		echo '</div>';
		self::footer();
	}

	/**
	 * Render native write-only-secret Settings form with no connection test/send.
	 *
	 * @return void
	 */
	public static function render_settings(): void {
		self::guard_capability();
		$settings = Settings::read();
		$ready    = Settings::readiness( $settings );

		self::header(
			__( 'Settings', 'gravity-notification-manager' ),
			__( 'Native WordPress settings for approved greenfield provider configuration. Saving does not send messages or migrate legacy values.', 'gravity-notification-manager' )
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="gnm-panel gnm-settings">';
		settings_fields( Settings::GROUP );
		echo '<h2>' . esc_html__( 'SMS Providers / IPPanel', 'gravity-notification-manager' ) . '</h2>';
		self::secret_field( 'ippanel_api_key', __( 'IPPanel API key', 'gravity-notification-manager' ), $ready['ippanel'] || '' !== ( $settings['ippanel_api_key'] ?? '' ) );
		echo '<label class="gnm-field"><span>' . esc_html__( 'SMS sender number (E.164)', 'gravity-notification-manager' ) . '</span><input type="text" class="regular-text gnm-ltr" dir="ltr" name="' . esc_attr( Settings::OPTION ) . '[sms_from_number]" value="' . esc_attr( $settings['sms_from_number'] ?? '' ) . '" placeholder="+982100000000" autocomplete="off"></label>';
		echo '<h2>' . esc_html__( 'Bale', 'gravity-notification-manager' ) . '</h2>';
		self::secret_field( 'bale_bot_token', __( 'Bale bot token', 'gravity-notification-manager' ), '' !== ( $settings['bale_bot_token'] ?? '' ) );
		echo '<p class="description">' . esc_html__( 'Stored credentials are never echoed back into this page or diagnostics.', 'gravity-notification-manager' ) . '</p>';
		submit_button( __( 'Save Settings', 'gravity-notification-manager' ) );
		echo '</form>';
		self::footer();
	}

	/**
	 * Render side-effect-free privacy-safe environment and configuration facts.
	 *
	 * @return void
	 */
	public static function render_diagnostics(): void {
		self::guard_capability();
		self::header(
			__( 'Help & Diagnostics', 'gravity-notification-manager' ),
			__( 'Privacy-safe, side-effect-free facts. No provider request is made by this screen.', 'gravity-notification-manager' )
		);
		self::facts_panel( __( 'Environment', 'gravity-notification-manager' ), Environment::facts() );
		self::facts_panel( __( 'Provider configuration', 'gravity-notification-manager' ), Settings::diagnostic_facts() );

		$points  = ( new PointInspector( new WordPressConfigurationSource() ) )->all();
		$summary = OverviewSummary::from_points( $points );
		self::facts_panel(
			__( 'Point checks', 'gravity-notification-manager' ),
			array(
				__( 'Detected Points', 'gravity-notification-manager' ) => (string) $summary['total'],
				__( 'Configured', 'gravity-notification-manager' )      => (string) $summary['configured'],
				__( 'Needs Setup', 'gravity-notification-manager' )     => (string) $summary['needs_setup'],
				__( 'Disabled', 'gravity-notification-manager' )        => (string) $summary['disabled'],
				__( 'Not Applicable', 'gravity-notification-manager' )  => (string) $summary['not_applicable'],
			)
		);

		echo '<div class="gnm-panel"><h2>' . esc_html__( 'Safe next actions', 'gravity-notification-manager' ) . '</h2><p>' . esc_html__( 'Resolve missing dependencies first, then review Notification Points for exact workflow guidance. Diagnostics never include credentials, recipients, Entry contents, or raw provider responses.', 'gravity-notification-manager' ) . '</p></div>';
		self::footer();
	}

	/**
	 * Handle explicit Check Again POST with capability, strict IDs and target nonce.
	 *
	 * @return void
	 */
	public static function handle_check_again(): void {
		self::guard_capability();

		$form_id = self::request_id( isset( $_POST['form_id'] ) ? wp_unslash( $_POST['form_id'] ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- IDs are parsed before the target-bound nonce can be derived.
		$feed_id = self::request_id( isset( $_POST['feed_id'] ) ? wp_unslash( $_POST['feed_id'] ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- IDs are parsed before the target-bound nonce can be derived.
		if ( null === $form_id || null === $feed_id ) {
			self::fail_request( __( 'Invalid Notification Point identifiers.', 'gravity-notification-manager' ) );
		}

		check_admin_referer( self::nonce_action( $form_id, $feed_id ), 'gnm_nonce' );
		$point = ( new PointVerificationService( new WordPressConfigurationSource() ) )->verify( $form_id, $feed_id );
		if ( null === $point ) {
			self::fail_request( __( 'Notification Point was not found.', 'gravity-notification-manager' ) );
		}

		$url = add_query_arg(
			array( 'page' => AdminDefinition::POINTS_SLUG ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render one bounded Point card.
	 *
	 * @param array<string, mixed> $point Current Point truth.
	 * @return void
	 */
	private static function render_point_card( array $point ): void {
		echo '<article class="gnm-panel gnm-point">';
		echo '<div class="gnm-point__heading"><div><h2>' . esc_html( (string) $point['feed_name'] ) . '</h2><p>' . esc_html( (string) $point['form_title'] ) . ' · ' . esc_html__( 'Feed', 'gravity-notification-manager' ) . ' <span class="gnm-ltr" dir="ltr">#' . esc_html( (string) $point['feed_id'] ) . '</span></p></div>';
		self::status_badge( (string) $point['state'] );
		echo '</div>';
		echo '<dl class="gnm-facts"><div><dt>' . esc_html__( 'Channel', 'gravity-notification-manager' ) . '</dt><dd><span class="gnm-ltr" dir="ltr">' . esc_html( (string) $point['channel'] ) . '</span></dd></div><div><dt>' . esc_html__( 'Fallback', 'gravity-notification-manager' ) . '</dt><dd><span class="gnm-ltr" dir="ltr">' . esc_html( (string) $point['fallback'] ) . '</span></dd></div></dl>';
		echo '<p>' . esc_html( (string) $point['detail'] ) . '</p><p><strong>' . esc_html__( 'Next action:', 'gravity-notification-manager' ) . '</strong> ' . esc_html( (string) $point['next_action'] ) . '</p>';
		echo '<div class="gnm-actions">';

		$flow_path = GravityFlowNavigation::relative_path( (int) $point['form_id'], class_exists( '\\Gravity_Flow_API' ) );
		if ( null !== $flow_path ) {
			echo '<a class="button" href="' . esc_url( admin_url( $flow_path ) ) . '">' . esc_html__( 'Open Gravity Flow', 'gravity-notification-manager' ) . '</a>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AdminDefinition::CHECK_ACTION ) . '"><input type="hidden" name="form_id" value="' . esc_attr( (string) $point['form_id'] ) . '"><input type="hidden" name="feed_id" value="' . esc_attr( (string) $point['feed_id'] ) . '">';
		wp_nonce_field( self::nonce_action( (int) $point['form_id'], (int) $point['feed_id'] ), 'gnm_nonce' );
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Check Again', 'gravity-notification-manager' ) . '</button></form></div></article>';
	}

	/**
	 * Render text plus a visual semantic status cue.
	 *
	 * @param string $state Current status identifier.
	 * @return void
	 */
	private static function status_badge( string $state ): void {
		$class = strtolower( str_replace( '_', '-', $state ) );
		echo '<span class="gnm-status gnm-status--' . esc_attr( $class ) . '"><span aria-hidden="true">●</span> ' . esc_html( self::status_label( $state ) ) . '</span>';
	}

	/**
	 * Convert a stable status identifier to localized presentation text.
	 *
	 * @param string $state Stable status identifier.
	 * @return string
	 */
	private static function status_label( string $state ): string {
		return match ( $state ) {
			PointStatus::CONFIGURED     => __( 'Configured', 'gravity-notification-manager' ),
			PointStatus::NEEDS_SETUP    => __( 'Needs Setup', 'gravity-notification-manager' ),
			PointStatus::DISABLED       => __( 'Disabled', 'gravity-notification-manager' ),
			PointStatus::NOT_APPLICABLE => __( 'Not Applicable', 'gravity-notification-manager' ),
			default                     => $state,
		};
	}

	/**
	 * Render the native page shell header.
	 *
	 * @param string $title       Page title.
	 * @param string $description Page description.
	 * @return void
	 */
	private static function header( string $title, string $description ): void {
		echo '<div class="wrap gnm-admin"><header class="gnm-page-header"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $description ) . '</p></header>';
	}

	/**
	 * Close the native page shell.
	 *
	 * @return void
	 */
	private static function footer(): void {
		echo '</div>';
	}

	/**
	 * Render one summary statistic.
	 *
	 * @param string $label Statistic label.
	 * @param int    $value Statistic value.
	 * @return void
	 */
	private static function stat( string $label, int $value ): void {
		echo '<div class="gnm-stat"><strong class="gnm-stat__value gnm-ltr" dir="ltr">' . esc_html( (string) $value ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
	}

	/**
	 * Render privacy-safe structured facts.
	 *
	 * @param string                $title Panel title.
	 * @param array<string, string> $facts Safe label/value facts.
	 * @return void
	 */
	private static function facts_panel( string $title, array $facts ): void {
		echo '<section class="gnm-panel"><h2>' . esc_html( $title ) . '</h2><dl class="gnm-facts">';
		foreach ( $facts as $label => $value ) {
			$value = self::status_label( $value );
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		echo '</dl></section>';
	}

	/**
	 * Render one write-only credential field.
	 *
	 * @param string $key    Settings key.
	 * @param string $label  Human-readable label.
	 * @param bool   $stored Whether a stored value exists.
	 * @return void
	 */
	private static function secret_field( string $key, string $label, bool $stored ): void {
		$placeholder = $stored
			? __( 'Stored — Enter a new value to replace', 'gravity-notification-manager' )
			: __( 'Not configured', 'gravity-notification-manager' );
		echo '<label class="gnm-field"><span>' . esc_html( $label ) . '</span><input type="password" class="regular-text gnm-ltr" dir="ltr" name="' . esc_attr( Settings::OPTION ) . '[' . esc_attr( $key ) . ']" value="" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="new-password"></label>';
	}

	/**
	 * Build one GNM admin page URL.
	 *
	 * @param string $slug GNM page slug.
	 * @return string
	 */
	private static function admin_page_url( string $slug ): string {
		return add_query_arg( array( 'page' => $slug ), admin_url( 'admin.php' ) );
	}

	/**
	 * Enforce the capability on every render/action callback.
	 *
	 * @return void
	 * @throws RuntimeException When WordPress authorization is unavailable in a test context.
	 */
	private static function guard_capability(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( AdminDefinition::CAPABILITY ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html__( 'You are not allowed to manage Gravity Notification Manager.', 'gravity-notification-manager' ), '', array( 'response' => 403 ) );
			}
			throw new RuntimeException( 'Unauthorized GNM admin access.' );
		}
	}

	/**
	 * Strictly parse one positive identifier.
	 *
	 * @param mixed $value Raw identifier.
	 * @return int|null
	 */
	private static function request_id( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return null;
		}
		$value = (int) $value;
		return $value > 0 ? $value : null;
	}

	/**
	 * Build a target-bound Check Again nonce action.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @param int $feed_id GNM Feed ID.
	 * @return string
	 */
	private static function nonce_action( int $form_id, int $feed_id ): string {
		return AdminDefinition::CHECK_ACTION . '_' . $form_id . '_' . $feed_id;
	}

	/**
	 * Fail a malformed privileged request without side effects.
	 *
	 * @param string $message Escaped-user-facing request failure message.
	 * @return void
	 * @throws InvalidArgumentException When WordPress wp_die() is unavailable in a test context.
	 */
	private static function fail_request( string $message ): void {
		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html( $message ), '', array( 'response' => 400 ) );
		}
		throw new InvalidArgumentException( 'Invalid GNM admin request.' );
	}

	/**
	 * Remember one successful GNM menu hook registration.
	 *
	 * @param mixed $hook Menu page hook suffix.
	 * @return void
	 */
	private static function remember_hook( $hook ): void {
		if ( is_string( $hook ) && '' !== $hook ) {
			self::$screen_hooks[] = $hook;
		}
	}
}