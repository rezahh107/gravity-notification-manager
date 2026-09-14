<?php
/**
 * WordPress-native operational delivery log UI.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Observability\LlmDebugReport;
use GravityNotify\Observability\OperationalContext;
use GravityNotify\Observability\OperationalEvent;
use GravityNotify\Observability\WordPressOperationalEventStore;
use RuntimeException;

/**
 * Presents one shared observational subsystem through clearly separated SMS/Bale views.
 */
final class OperationalLogAdmin {

	private static string $screen_hook = '';

	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function register_menu(): void {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}
		$surface = AdminDefinition::log_surface();
		/* translators: %s: localized admin surface title. */
		$page_title = sprintf( __( '%s — Gravity Notification Manager', 'gravity-notification-manager' ), $surface['title'] );
		$hook       = add_submenu_page(
			AdminDefinition::ROOT_SLUG,
			$page_title,
			$surface['title'],
			AdminDefinition::CAPABILITY,
			AdminDefinition::LOGS_SLUG,
			array( self::class, 'render' ),
			4
		);
		if ( is_string( $hook ) ) {
			self::$screen_hook = $hook;
		}
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' === self::$screen_hook || self::$screen_hook !== $hook_suffix || ! defined( 'GRAVITY_NOTIFY_PLUGIN_URL' ) ) {
			return;
		}
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'gravity-notify-admin', GRAVITY_NOTIFY_PLUGIN_URL . 'assets/admin/gnm-admin.css', array(), null );
			wp_enqueue_style( 'gravity-notify-operational-log', GRAVITY_NOTIFY_PLUGIN_URL . 'assets/admin/gnm-operational-log.css', array( 'gravity-notify-admin' ), null );
		}
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'gravity-notify-operational-log', GRAVITY_NOTIFY_PLUGIN_URL . 'assets/admin/gnm-operational-log.js', array(), null, true );
		}
	}

	public static function render(): void {
		self::guard_capability();
		$channel = self::requested_channel();
		$filters = self::requested_filters();
		$events  = WordPressOperationalEventStore::production()->latest( $channel, $filters, 100 );

		echo '<div class="wrap gnm-admin gnm-log">';
		echo '<header class="gnm-page-header"><h1>' . esc_html__( 'Operational Log', 'gravity-notification-manager' ) . '</h1>';
		echo '<p>' . esc_html__( 'Observational evidence for outbound attempts. Gravity Forms Entry Meta remains delivery-state and Retry authority.', 'gravity-notification-manager' ) . '</p></header>';
		self::render_tabs( $channel );
		self::render_filters( $channel, $filters );
		self::render_table( $events );
		echo '<p id="gnm-copy-status" class="screen-reader-text" aria-live="polite"></p>';
		echo '</div>';
	}

	private static function render_tabs( string $channel ): void {
		echo '<nav class="nav-tab-wrapper gnm-log-tabs" aria-label="' . esc_attr__( 'Operational log channel', 'gravity-notification-manager' ) . '">';
		foreach ( array(
			'sms'  => __( 'SMS log', 'gravity-notification-manager' ),
			'bale' => __( 'Bale log', 'gravity-notification-manager' ),
		) as $value => $label ) {
			$url   = add_query_arg(
				array(
					'page'    => AdminDefinition::LOGS_SLUG,
					'channel' => $value,
				),
				admin_url( 'admin.php' )
			);
			$class = 'nav-tab' . ( $channel === $value ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** @param array<string, string> $filters */
	private static function render_filters( string $channel, array $filters ): void {
		echo '<form method="get" class="gnm-panel gnm-log-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminDefinition::LOGS_SLUG ) . '">';
		echo '<input type="hidden" name="channel" value="' . esc_attr( $channel ) . '">';
		echo '<label><span>' . esc_html__( 'Status', 'gravity-notification-manager' ) . '</span><select name="status">';
		self::option( '', __( 'All statuses', 'gravity-notification-manager' ), $filters['status'] ?? '' );
		self::option( AttemptStatus::SUCCESS, __( 'Success', 'gravity-notification-manager' ), $filters['status'] ?? '' );
		self::option( AttemptStatus::FAILED, __( 'Failed', 'gravity-notification-manager' ), $filters['status'] ?? '' );
		self::option( AttemptStatus::AMBIGUOUS, __( 'Ambiguous / warning', 'gravity-notification-manager' ), $filters['status'] ?? '' );
		self::option( AttemptStatus::SKIPPED, __( 'Skipped', 'gravity-notification-manager' ), $filters['status'] ?? '' );
		echo '</select></label>';
		echo '<label><span>' . esc_html__( 'Execution', 'gravity-notification-manager' ) . '</span><select name="execution_type">';
		self::option( '', __( 'All executions', 'gravity-notification-manager' ), $filters['execution_type'] ?? '' );
		self::option( OperationalContext::EXECUTION_NORMAL, __( 'Normal send', 'gravity-notification-manager' ), $filters['execution_type'] ?? '' );
		self::option( OperationalContext::EXECUTION_TEST, __( 'TEST', 'gravity-notification-manager' ), $filters['execution_type'] ?? '' );
		self::option( OperationalContext::EXECUTION_RETRY, __( 'Retry', 'gravity-notification-manager' ), $filters['execution_type'] ?? '' );
		echo '</select></label>';
		echo '<label class="gnm-log-filter-trace"><span>' . esc_html__( 'Trace ID', 'gravity-notification-manager' ) . '</span><input class="regular-text gnm-ltr" dir="ltr" type="text" name="trace_id" value="' . esc_attr( $filters['trace_id'] ?? '' ) . '" placeholder="xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx"></label>';
		submit_button( __( 'Filter log', 'gravity-notification-manager' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/** @param array<int, OperationalEvent> $events */
	private static function render_table( array $events ): void {
		if ( array() === $events ) {
			echo '<div class="gnm-panel gnm-empty"><p>' . esc_html__( 'No matching operational records were found.', 'gravity-notification-manager' ) . '</p></div>';
			return;
		}
		echo '<div class="gnm-panel gnm-log-table-wrap"><table class="widefat striped gnm-log-table"><thead><tr>';
		foreach ( array(
			__( 'Time', 'gravity-notification-manager' ),
			__( 'Result', 'gravity-notification-manager' ),
			__( 'Execution', 'gravity-notification-manager' ),
			__( 'Provider / Source', 'gravity-notification-manager' ),
			__( 'Route', 'gravity-notification-manager' ),
			__( 'Diagnostic', 'gravity-notification-manager' ),
			__( 'Trace', 'gravity-notification-manager' ),
			__( 'Debug', 'gravity-notification-manager' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $events as $event ) {
			self::render_row( $event );
		}
		echo '</tbody></table></div>';
	}

	private static function render_row( OperationalEvent $event ): void {
		$data = $event->to_array();
		echo '<tr>';
		echo '<td><bdi class="gnm-ltr" dir="ltr">' . esc_html( (string) $data['created_at_utc'] ) . ' UTC</bdi></td>';
		echo '<td>' . self::status_badge( (string) $data['status'] ) . '</td>';
		echo '<td>' . esc_html( self::execution_label( (string) $data['execution_type'] ) ) . '<br><small>#' . esc_html( (string) $data['attempt_index'] ) . '</small></td>';
		echo '<td>' . self::provider_and_source( $data ) . '</td>';
		echo '<td>' . self::route( $data ) . '</td>';
		echo '<td><bdi class="gnm-ltr" dir="ltr">' . esc_html( (string) $data['diagnostic'] ) . '</bdi>';
		if ( null !== $data['http_status'] ) {
			echo '<br><small>HTTP <bdi class="gnm-ltr" dir="ltr">' . esc_html( (string) $data['http_status'] ) . '</bdi></small>';
		}
		$references = is_array( $data['provider_references'] ) ? $data['provider_references'] : array();
		if ( array() !== $references ) {
			echo '<br><small>' . esc_html__( 'Reference:', 'gravity-notification-manager' ) . ' <bdi class="gnm-ltr" dir="ltr">' . esc_html( implode( ', ', $references ) ) . '</bdi></small>';
		}
		echo '</td>';
		echo '<td><bdi class="gnm-ltr gnm-log-trace" dir="ltr">' . esc_html( (string) $data['trace_id'] ) . '</bdi></td>';
		echo '<td>';
		if ( in_array( $data['status'], array( AttemptStatus::FAILED, AttemptStatus::AMBIGUOUS ), true ) ) {
			$report = LlmDebugReport::build( $event );
			echo '<button type="button" class="button gnm-copy-debug" data-report="' . esc_attr( $report ) . '" data-copied="' . esc_attr__( 'Debug report copied.', 'gravity-notification-manager' ) . '">' . esc_html__( 'Copy LLM Debug Report', 'gravity-notification-manager' ) . '</button>';
		} else {
			echo '<span aria-hidden="true">—</span>';
		}
		echo '</td></tr>';
	}

	/** @param array<string, mixed> $data */
	private static function provider_and_source( array $data ): string {
		$parts = array();
		if ( null !== $data['provider'] ) {
			$parts[] = '<bdi class="gnm-ltr" dir="ltr">' . esc_html( (string) $data['provider'] ) . '</bdi>';
		}
		if ( null !== $data['form_id'] ) {
			$parts[] = esc_html__( 'Form', 'gravity-notification-manager' ) . ' <bdi class="gnm-ltr" dir="ltr">#' . esc_html( (string) $data['form_id'] ) . '</bdi>';
		}
		if ( null !== $data['feed_id'] ) {
			$parts[] = esc_html__( 'Feed', 'gravity-notification-manager' ) . ' <bdi class="gnm-ltr" dir="ltr">#' . esc_html( (string) $data['feed_id'] ) . '</bdi>';
		}
		if ( null !== $data['entry_id'] ) {
			$parts[] = esc_html__( 'Entry', 'gravity-notification-manager' ) . ' <bdi class="gnm-ltr" dir="ltr">#' . esc_html( (string) $data['entry_id'] ) . '</bdi>';
		}
		return array() === $parts ? '<span aria-hidden="true">—</span>' : implode( '<br>', $parts );
	}

	/** @param array<string, mixed> $data */
	private static function route( array $data ): string {
		$parts = array();
		if ( null !== $data['sender'] ) {
			$parts[] = esc_html__( 'Sender', 'gravity-notification-manager' ) . ': <bdi class="gnm-ltr" dir="ltr">' . esc_html( (string) $data['sender'] ) . '</bdi>';
		}
		if ( null !== $data['destination'] ) {
			$parts[] = esc_html__( 'Destination', 'gravity-notification-manager' ) . ': <bdi class="gnm-ltr" dir="ltr">' . esc_html( (string) $data['destination'] ) . '</bdi>';
		}
		return array() === $parts ? '<span aria-hidden="true">—</span>' : implode( '<br>', $parts );
	}

	private static function status_badge( string $status ): string {
		$class = 'gnm-log-status gnm-log-status--' . strtolower( $status );
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	private static function status_label( string $status ): string {
		return match ( $status ) {
			AttemptStatus::SUCCESS   => __( 'Success', 'gravity-notification-manager' ),
			AttemptStatus::FAILED    => __( 'Failed', 'gravity-notification-manager' ),
			AttemptStatus::AMBIGUOUS => __( 'Ambiguous', 'gravity-notification-manager' ),
			AttemptStatus::SKIPPED   => __( 'Skipped', 'gravity-notification-manager' ),
			default                  => __( 'Unknown', 'gravity-notification-manager' ),
		};
	}

	private static function execution_label( string $execution ): string {
		return match ( $execution ) {
			OperationalContext::EXECUTION_TEST  => __( 'TEST', 'gravity-notification-manager' ),
			OperationalContext::EXECUTION_RETRY => __( 'Retry', 'gravity-notification-manager' ),
			default                             => __( 'Normal send', 'gravity-notification-manager' ),
		};
	}

	private static function option( string $value, string $label, string $selected ): void {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
	}

	private static function requested_channel(): string {
		$value = isset( $_GET['channel'] ) ? self::request_text( $_GET['channel'] ) : 'sms'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only log filter.
		return 'bale' === $value ? 'bale' : 'sms';
	}

	/** @return array<string, string> */
	private static function requested_filters(): array {
		$status    = isset( $_GET['status'] ) ? self::request_text( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$execution = isset( $_GET['execution_type'] ) ? self::request_text( $_GET['execution_type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$trace     = isset( $_GET['trace_id'] ) ? strtolower( self::request_text( $_GET['trace_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return array(
			'status'         => $status,
			'execution_type' => $execution,
			'trace_id'       => $trace,
		);
	}

	/** @param mixed $value */
	private static function request_text( $value ): string {
		$value = is_string( $value ) ? wp_unslash( $value ) : '';
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
	}

	private static function guard_capability(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( AdminDefinition::CAPABILITY ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'gravity-notification-manager' ) );
			}
			throw new RuntimeException( 'GNM admin capability check failed.' );
		}
	}
}
