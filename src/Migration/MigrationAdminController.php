<?php
/**
 * Explicit operator controls for WU-08 migration and cutover.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

use GravityNotify\Admin\AdminDefinition;

/**
 * Adds bounded controls to the existing Settings surface; it creates no new IA page.
 */
final class MigrationAdminController {

	public const PREVIEW_ACTION = 'gravity_notify_migration_preview';
	public const EXECUTE_ACTION = 'gravity_notify_migration_execute';
	public const CUTOVER_ACTION = 'gravity_notify_cutover_enable';
	public const ROLLBACK_ACTION = 'gravity_notify_cutover_rollback';
	public const FLOW_PREPARE_ACTION = 'gravity_notify_flow_cutover_prepare';

	/**
	 * Register WU-08 Settings controls and admin-post handlers.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'admin_notices', array( self::class, 'render_panel' ) );
		add_action( 'admin_post_' . self::PREVIEW_ACTION, array( self::class, 'handle_preview' ) );
		add_action( 'admin_post_' . self::EXECUTE_ACTION, array( self::class, 'handle_execute' ) );
		add_action( 'admin_post_' . self::CUTOVER_ACTION, array( self::class, 'handle_cutover' ) );
		add_action( 'admin_post_' . self::ROLLBACK_ACTION, array( self::class, 'handle_rollback' ) );
		add_action( 'admin_post_' . self::FLOW_PREPARE_ACTION, array( self::class, 'handle_flow_prepare' ) );
	}

	/**
	 * Render the bounded migration panel on the existing Settings screen.
	 *
	 * @return void
	 */
	public static function render_panel(): void {
		if ( ! self::on_settings_page() || ! current_user_can( AdminDefinition::CAPABILITY ) ) {
			return;
		}
		$report = ( new MigrationService() )->preview();
		echo '<div class="notice notice-info gnm-panel"><h2>' . esc_html__( 'Migration & controlled cutover', 'gravity-notification-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'Preview is read-only. Execute creates only deterministic inactive Feeds and migrates non-conflicting provider values. Ambiguous mappings remain manual.', 'gravity-notification-manager' ) . '</p>';
		self::action_form( self::PREVIEW_ACTION, 'Preview migration' );
		self::action_form( self::EXECUTE_ACTION, 'Execute deterministic migration', 'button button-primary' );
		self::render_report( $report );
		self::render_cutover_records();
		self::render_flow_prepare();
		echo '</div>';
	}

	/**
	 * Handle explicit read-only migration preview.
	 *
	 * @return void
	 */
	public static function handle_preview(): void {
		self::authorize( self::PREVIEW_ACTION );
		( new MigrationService() )->preview();
		self::redirect( 'previewed' );
	}

	/**
	 * Handle explicit deterministic migration execution.
	 *
	 * @return void
	 */
	public static function handle_execute(): void {
		self::authorize( self::EXECUTE_ACTION );
		( new MigrationService() )->execute();
		self::redirect( 'executed' );
	}

	/**
	 * Handle explicit controlled cutover for one prepared scope.
	 *
	 * @return void
	 */
	public static function handle_cutover(): void {
		$scope_id = self::scope_request( self::CUTOVER_ACTION );
		( new CutoverService() )->enable( $scope_id );
		self::redirect( 'cutover_checked' );
	}

	/**
	 * Handle explicit rollback for one cut-over scope.
	 *
	 * @return void
	 */
	public static function handle_rollback(): void {
		$scope_id = self::scope_request( self::ROLLBACK_ACTION );
		( new CutoverService() )->rollback( $scope_id );
		self::redirect( 'rollback_checked' );
	}

	/**
	 * Handle operator-confirmed Flow scope preparation.
	 *
	 * @return void
	 */
	public static function handle_flow_prepare(): void {
		self::guard_capability();
		check_admin_referer( self::FLOW_PREPARE_ACTION, 'gnm_migration_nonce' );

		$confirmed = isset( $_POST['semantic_confirmation'] ) && '1' === (string) wp_unslash( $_POST['semantic_confirmation'] );
		$type      = isset( $_POST['source_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['source_type'] ) ) : '';
		$form_id   = self::request_id( $_POST['form_id'] ?? null );
		$step_id   = self::request_id( $_POST['legacy_step_id'] ?? null, true );
		$feed_id   = self::request_id( $_POST['feed_id'] ?? null );
		$target_id = self::request_id( $_POST['target_flow_step_id'] ?? null );
		if ( $confirmed && null !== $form_id && null !== $step_id && null !== $feed_id && null !== $target_id ) {
			( new CutoverService() )->prepare_flow( $type, $form_id, $step_id, $feed_id, $target_id );
		}
		self::redirect( 'flow_checked' );
	}

	/**
	 * Render secret-safe migration classification counts.
	 *
	 * @param array<string, mixed> $report Migration preview report.
	 * @return void
	 */
	private static function render_report( array $report ): void {
		$rules         = is_array( $report['rules'] ?? null ) ? $report['rules'] : array();
		$deterministic = 0;
		$manual        = 0;
		foreach ( $rules as $rule ) {
			if ( LegacyRuleMapper::MAP_DETERMINISTIC === ( $rule['classification'] ?? '' ) ) {
				++$deterministic;
			} else {
				++$manual;
			}
		}
		echo '<p><strong>' . esc_html__( 'Direct GF Rules:', 'gravity-notification-manager' ) . '</strong> ' . esc_html( sprintf( '%d deterministic, %d manual required', $deterministic, $manual ) ) . '</p>';
	}

	/**
	 * Render current cutover records and explicit state-changing actions.
	 *
	 * @return void
	 */
	private static function render_cutover_records(): void {
		$records = CutoverRegistry::records();
		if ( array() === $records ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Prepared scopes', 'gravity-notification-manager' ) . '</h3><ul>';
		foreach ( $records as $scope_id => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			echo '<li><code>' . esc_html( $scope_id ) . '</code> — ' . esc_html( (string) ( $record['state'] ?? '' ) ) . ' — Feed #' . esc_html( (string) ( $record['feed_id'] ?? 0 ) );
			if ( CutoverSequence::PREPARED === ( $record['state'] ?? '' ) ) {
				self::scope_form( self::CUTOVER_ACTION, $scope_id, 'Enable controlled cutover' );
			} elseif ( CutoverSequence::GREENFIELD_ENABLED === ( $record['state'] ?? '' ) ) {
				self::scope_form( self::ROLLBACK_ACTION, $scope_id, 'Rollback to legacy' );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render read-only Flow placement verification inputs.
	 *
	 * @return void
	 */
	private static function render_flow_prepare(): void {
		echo '<details><summary>' . esc_html__( 'Prepare an operator-confirmed Gravity Flow scope', 'gravity-notification-manager' ) . '</summary>';
		echo '<p>' . esc_html__( 'Configure the GNM Feed and native Gravity Flow Feed Step first. This verifier never inserts or reorders workflow Steps. Confirm only after recipient/message/status semantics are manually checked.', 'gravity-notification-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::FLOW_PREPARE_ACTION ) . '">';
		wp_nonce_field( self::FLOW_PREPARE_ACTION, 'gnm_migration_nonce' );
		echo '<select name="source_type"><option value="flow_step">Legacy Flow Step event</option><option value="flow_workflow">Legacy workflow-complete event</option></select> ';
		echo '<input name="form_id" inputmode="numeric" placeholder="Form ID"> <input name="legacy_step_id" inputmode="numeric" placeholder="Legacy Step ID (0 for workflow)"> <input name="feed_id" inputmode="numeric" placeholder="GNM Feed ID"> <input name="target_flow_step_id" inputmode="numeric" placeholder="Target GNM Flow Step ID"> ';
		echo '<label><input type="checkbox" name="semantic_confirmation" value="1"> ' . esc_html__( 'I verified message, recipient, status and workflow-position semantics.', 'gravity-notification-manager' ) . '</label> ';
		echo '<button class="button" type="submit">' . esc_html__( 'Verify and prepare scope', 'gravity-notification-manager' ) . '</button></form></details>';
	}

	/**
	 * Render one capability-protected admin-post form.
	 *
	 * @param string $action Admin-post action.
	 * @param string $label  Button label.
	 * @param string $class  Button class list.
	 * @return void
	 */
	private static function action_form( string $action, string $label, string $class = 'button' ): void {
		echo '<form style="display:inline-block;margin-inline-end:8px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action, 'gnm_migration_nonce' );
		echo '<button class="' . esc_attr( $class ) . '" type="submit">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Render one target-bound cutover action form.
	 *
	 * @param string $action   Admin-post action.
	 * @param string $scope_id Cutover scope identity.
	 * @param string $label    Button label.
	 * @return void
	 */
	private static function scope_form( string $action, string $scope_id, string $label ): void {
		echo ' <form style="display:inline-block" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="scope_id" value="' . esc_attr( $scope_id ) . '">';
		wp_nonce_field( $action . ':' . $scope_id, 'gnm_migration_nonce' );
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Authorize and read one target-bound scope request.
	 *
	 * @param string $action Admin-post action.
	 * @return string
	 */
	private static function scope_request( string $action ): string {
		self::guard_capability();
		$scope_id = isset( $_POST['scope_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['scope_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Scope identity is required to derive the target-bound nonce action.
		if ( '' === $scope_id ) {
			return '';
		}
		check_admin_referer( $action . ':' . $scope_id, 'gnm_migration_nonce' );
		return $scope_id;
	}

	/**
	 * Enforce capability and nonce for a fixed action.
	 *
	 * @param string $action Admin-post action.
	 * @return void
	 */
	private static function authorize( string $action ): void {
		self::guard_capability();
		check_admin_referer( $action, 'gnm_migration_nonce' );
	}

	/**
	 * Require the canonical GNM management capability.
	 *
	 * @return void
	 */
	private static function guard_capability(): void {
		if ( ! current_user_can( AdminDefinition::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Gravity Notification Manager.', 'gravity-notification-manager' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Check whether the current request is the existing GNM Settings screen.
	 *
	 * @return bool
	 */
	private static function on_settings_page(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		return AdminDefinition::SETTINGS_SLUG === $page;
	}

	/**
	 * Parse a request identifier without accepting non-decimal input.
	 *
	 * @param mixed $value      Raw request value.
	 * @param bool  $allow_zero Whether zero is valid.
	 * @return int|null
	 */
	private static function request_id( $value, bool $allow_zero = false ): ?int {
		$value = is_scalar( $value ) ? (string) wp_unslash( $value ) : '';
		if ( 1 !== preg_match( '/^[0-9]+$/D', $value ) ) {
			return null;
		}
		$id = (int) $value;
		return $allow_zero ? ( 0 <= $id ? $id : null ) : ( 0 < $id ? $id : null );
	}

	/**
	 * Redirect to the existing Settings screen with a bounded status token.
	 *
	 * @param string $state Safe status token.
	 * @return void
	 */
	private static function redirect( string $state ): void {
		$url = add_query_arg(
			array(
				'page'          => AdminDefinition::SETTINGS_SLUG,
				'gnm_migration' => $state,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
