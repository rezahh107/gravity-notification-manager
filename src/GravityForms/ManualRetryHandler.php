<?php
/**
 * Secure synchronous manual notification Retry handler.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityForms;

/**
 * Handles only explicit authenticated POST Retry requests; it renders no WU-07 UI.
 */
final class ManualRetryHandler {

	/**
	 * Authenticated WordPress admin-post action.
	 */
	public const ACTION = 'gravity_notify_retry_notification';

	/** Successful Retry result. */
	public const RESULT_SUCCESS = 'retry_success';

	/** Unresolved Retry result. */
	public const RESULT_UNRESOLVED = 'retry_unresolved';

	/** Non-POST request error. */
	public const ERROR_METHOD = 'invalid_method';

	/** Capability error. */
	public const ERROR_CAPABILITY = 'invalid_capability';

	/** Entry identifier error. */
	public const ERROR_ENTRY_ID = 'invalid_entry_id';

	/** Feed identifier error. */
	public const ERROR_FEED_ID = 'invalid_feed_id';

	/** Nonce error. */
	public const ERROR_NONCE = 'invalid_nonce';

	/** Entry lookup error. */
	public const ERROR_ENTRY = 'invalid_entry';

	/** Feed lookup/identity error. */
	public const ERROR_FEED = 'invalid_feed';

	/** Persisted state eligibility error. */
	public const ERROR_STATE = 'invalid_state';

	/**
	 * Add-On execution seam.
	 *
	 * @var NotificationFeedAddOn
	 */
	private NotificationFeedAddOn $add_on;

	/**
	 * WordPress / Gravity Forms runtime adapter.
	 *
	 * @var ManualRetryRuntimeInterface
	 */
	private ManualRetryRuntimeInterface $runtime;

	/**
	 * Whether the production hook has already been registered this request.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Constructor.
	 *
	 * @param NotificationFeedAddOn       $add_on Add-On execution seam.
	 * @param ManualRetryRuntimeInterface $runtime Runtime APIs.
	 */
	public function __construct( NotificationFeedAddOn $add_on, ManualRetryRuntimeInterface $runtime ) {
		$this->add_on  = $add_on;
		$this->runtime = $runtime;
	}

	/**
	 * Register only the authenticated admin-post action.
	 *
	 * No nopriv hook is registered and registration itself performs no delivery.
	 *
	 * @param NotificationFeedAddOn $add_on Add-On instance.
	 * @return void
	 */
	public static function boot( NotificationFeedAddOn $add_on ): void {
		if ( self::$booted || ! function_exists( 'add_action' ) ) {
			return;
		}

		$handler = new self( $add_on, new WordPressManualRetryRuntime() );
		add_action( 'admin_post_' . self::ACTION, array( $handler, 'handle' ) );
		self::$booted = true;
	}

	/**
	 * WordPress admin-post callback.
	 *
	 * @return void
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared only to the literal POST method; no state is derived from it.
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( $_SERVER['REQUEST_METHOD'] )
			: '';
		$request = $this->sanitized_post_request();
		$result  = $this->dispatch( $method, $request );
		$code    = $this->response_code( $result );

		if ( function_exists( 'wp_die' ) ) {
			$title = function_exists( 'esc_html__' )
				? esc_html__( 'Gravity Notification Manager Retry', 'gravity-notification-manager' )
				: 'Gravity Notification Manager Retry';
			wp_die(
				function_exists( 'esc_html' ) ? esc_html( $result ) : $result,
				$title,
				array( 'response' => $code )
			);
		}
	}

	/**
	 * Validate and execute one explicit Retry request synchronously.
	 *
	 * @param string               $method  HTTP method.
	 * @param array<string, mixed> $request Sanitized request data.
	 * @return string Bounded outcome code.
	 */
	public function dispatch( string $method, array $request ): string {
		if ( 'POST' !== strtoupper( $method ) ) {
			return self::ERROR_METHOD;
		}

		if ( ! $this->runtime->current_user_can_retry() ) {
			return self::ERROR_CAPABILITY;
		}

		$entry_id = $this->positive_identifier( $request['entry_id'] ?? null );
		if ( null === $entry_id ) {
			return self::ERROR_ENTRY_ID;
		}

		$feed_id = $this->positive_identifier( $request['feed_id'] ?? null );
		if ( null === $feed_id ) {
			return self::ERROR_FEED_ID;
		}

		$nonce = is_string( $request['_wpnonce'] ?? null ) ? $request['_wpnonce'] : '';
		if ( '' === $nonce || ! $this->runtime->verify_nonce( $nonce, $entry_id, $feed_id ) ) {
			return self::ERROR_NONCE;
		}

		$entry = $this->runtime->get_entry( $entry_id );
		if ( null === $entry || $entry_id !== $this->positive_identifier( $entry['id'] ?? null ) ) {
			return self::ERROR_ENTRY;
		}

		$form_id = $this->positive_identifier( $entry['form_id'] ?? null );
		if ( null === $form_id ) {
			return self::ERROR_ENTRY;
		}

		$form = $this->runtime->get_form( $form_id );
		if ( null === $form || $form_id !== $this->positive_identifier( $form['id'] ?? null ) ) {
			return self::ERROR_ENTRY;
		}

		$feed = $this->runtime->get_feed( $feed_id );
		if ( ! $this->is_valid_feed_target( $feed, $feed_id, $form_id ) ) {
			return self::ERROR_FEED;
		}

		$result = $this->add_on->retry_feed( $feed, $entry, $form );
		if ( null === $result ) {
			return self::ERROR_STATE;
		}

		return $result->delivery_succeeded() ? self::RESULT_SUCCESS : self::RESULT_UNRESOLVED;
	}

	/**
	 * Read and sanitize only the WU-05 request fields.
	 *
	 * Reading occurs before nonce verification because the Entry/Feed identifiers
	 * are inputs to the nonce action. No mutation or send occurs until dispatch()
	 * validates the nonce and capability.
	 *
	 * @return array<string, string>
	 */
	private function sanitized_post_request(): array {
		$request = array();
		foreach ( array( 'entry_id', 'feed_id', '_wpnonce' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Sanitized here; dispatch() verifies the target-bound nonce before any mutation/send.
			$value = $_POST[ $key ] ?? '';
			if ( function_exists( 'wp_unslash' ) ) {
				$value = wp_unslash( $value );
			}
			if ( function_exists( 'sanitize_text_field' ) ) {
				$value = sanitize_text_field( $value );
			}
			$request[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $request;
	}

	/**
	 * Require canonical positive decimal identifiers; partial numeric strings fail closed.
	 *
	 * @param mixed $value Raw identifier.
	 * @return int|null
	 */
	private function positive_identifier( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return null;
		}

		$identifier = (int) $value;
		return $identifier > 0 ? $identifier : null;
	}

	/**
	 * Confirm Feed identity, form scope, active state and owning Add-On.
	 *
	 * @param array|null $feed    Feed object.
	 * @param int        $feed_id Expected Feed ID.
	 * @param int        $form_id Entry Form ID.
	 * @return bool
	 */
	private function is_valid_feed_target( ?array $feed, int $feed_id, int $form_id ): bool {
		if ( null === $feed || $feed_id !== $this->positive_identifier( $feed['id'] ?? null ) ) {
			return false;
		}

		$feed_form_id = isset( $feed['form_id'] ) && ( 0 === $feed['form_id'] || '0' === $feed['form_id'] )
			? 0
			: $this->positive_identifier( $feed['form_id'] ?? null );
		if ( null === $feed_form_id || ( 0 !== $feed_form_id && $form_id !== $feed_form_id ) ) {
			return false;
		}

		if ( isset( $feed['is_active'] ) && false === (bool) $feed['is_active'] ) {
			return false;
		}

		if ( ! isset( $feed['meta'] ) || ! is_array( $feed['meta'] ) ) {
			return false;
		}

		return isset( $feed['addon_slug'] )
			&& is_string( $feed['addon_slug'] )
			&& $this->add_on->get_slug() === $feed['addon_slug'];
	}

	/**
	 * Map bounded outcomes to HTTP response codes.
	 *
	 * @param string $result Outcome.
	 * @return int
	 */
	private function response_code( string $result ): int {
		if ( self::RESULT_SUCCESS === $result || self::RESULT_UNRESOLVED === $result ) {
			return 200;
		}

		if ( self::ERROR_CAPABILITY === $result || self::ERROR_NONCE === $result ) {
			return 403;
		}

		if ( self::ERROR_METHOD === $result ) {
			return 405;
		}

		return 400;
	}
}
