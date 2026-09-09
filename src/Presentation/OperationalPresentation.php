<?php
/**
 * WU-07 operational presentation surfaces.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Presentation;

use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\DeliveryState\DeliveryStateReadResult;
use GravityNotify\DeliveryState\EntryMetaDeliveryStore;
use GravityNotify\GravityForms\ManualRetryHandler;
use GravityNotify\GravityForms\ManualRetryRuntimeInterface;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\WordPressManualRetryRuntime;

/**
 * Adds read-only Entry Detail/GravityView presentation and explicit Retry control.
 */
final class OperationalPresentation {

	/** GravityView Custom Content shortcode for one Attention Required target summary. */
	public const SHORTCODE = 'gnm_attention_target';

	/** Site-owned allow-list of GravityView View IDs that represent Attention Required cases. */
	public const ATTENTION_VIEW_IDS_FILTER = 'gravity_notify_attention_required_view_ids';

	/** Retry-result query parameter. */
	public const RETRY_RESULT_QUERY_ARG = 'gnm_retry_result';

	/** Retry Feed query parameter. */
	public const RETRY_FEED_QUERY_ARG = 'gnm_retry_feed_id';

	/** Capability required to expose operator-facing case detail. */
	public const VIEW_CAPABILITY = 'gravityforms_view_entries';

	/**
	 * Add-On instance.
	 *
	 * @var NotificationFeedAddOn
	 */
	private NotificationFeedAddOn $add_on;

	/**
	 * Read-only delivery-state projection.
	 *
	 * @var DeliveryStatePresentationReader
	 */
	private DeliveryStatePresentationReader $reader;

	/**
	 * Runtime reader/security seam.
	 *
	 * @var ManualRetryRuntimeInterface
	 */
	private ManualRetryRuntimeInterface $runtime;

	/**
	 * Production hook boot guard.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Constructor.
	 *
	 * @param NotificationFeedAddOn           $add_on Add-On instance.
	 * @param DeliveryStatePresentationReader $reader Presentation reader.
	 * @param ManualRetryRuntimeInterface     $runtime Runtime seam.
	 */
	public function __construct(
		NotificationFeedAddOn $add_on,
		DeliveryStatePresentationReader $reader,
		ManualRetryRuntimeInterface $runtime
	) {
		$this->add_on  = $add_on;
		$this->reader  = $reader;
		$this->runtime = $runtime;
	}

	/**
	 * Register WU-07 presentation hooks without performing delivery or mutation.
	 *
	 * @param NotificationFeedAddOn $add_on Add-On instance.
	 * @return void
	 */
	public static function boot( NotificationFeedAddOn $add_on ): void {
		if ( self::$booted ) {
			return;
		}

		$store        = new EntryMetaDeliveryStore();
		$manager      = new DeliveryStateManager( $store );
		$presentation = new self(
			$add_on,
			new DeliveryStatePresentationReader( $store, $manager ),
			new WordPressManualRetryRuntime()
		);

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'gform_entry_detail_meta_boxes', array( $presentation, 'register_entry_detail_meta_box' ), 10, 3 );
			add_filter( 'gravityview/view/entries', array( $presentation, 'filter_gravityview_entries' ), 10, 3 );
		}

		if ( function_exists( 'add_shortcode' ) ) {
			add_shortcode( self::SHORTCODE, array( $presentation, 'attention_target_shortcode' ) );
		}

		self::$booted = true;
	}

	/**
	 * Add one independent Gravity Forms Entry Detail operational box.
	 *
	 * @param array<string, mixed> $meta_boxes Existing meta boxes.
	 * @param array<string, mixed> $entry      Current Entry.
	 * @param array<string, mixed> $form       Current Form.
	 * @return array<string, mixed>
	 */
	public function register_entry_detail_meta_box( array $meta_boxes, array $entry, array $form ): array {
		unset( $form );

		$entry_id = $this->positive_identifier( $entry['id'] ?? null );
		if ( null === $entry_id ) {
			return $meta_boxes;
		}

		$meta_boxes['gravity_notify_delivery'] = array(
			'title'    => 'Notification Delivery',
			'callback' => array( $this, 'render_entry_detail_meta_box' ),
			'context'  => 'side',
		);

		return $meta_boxes;
	}

	/**
	 * Echo the bounded Entry Detail box through a local WU-07 KSES allow-list.
	 *
	 * @param array<string, mixed> $args Gravity Forms callback args.
	 * @return void
	 */
	public function render_entry_detail_meta_box( array $args ): void {
		$entry = isset( $args['entry'] ) && is_array( $args['entry'] ) ? $args['entry'] : array();
		$form  = isset( $args['form'] ) && is_array( $args['form'] ) ? $args['form'] : array();
		$html  = $this->entry_detail_html( $entry, $form );

		if ( ! function_exists( 'wp_kses' ) ) {
			return;
		}

		echo wp_kses( $html, self::entry_detail_allowed_html() );
	}

	/**
	 * Return only the tags and attributes emitted by the Entry Detail call graph.
	 *
	 * This allow-list is intentionally local to this renderer. It does not alter
	 * WordPress global post HTML and does not broaden GravityView/Elementor output.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function entry_detail_allowed_html(): array {
		return array(
			'div'     => array(
				'class'     => true,
				'aria-live' => true,
			),
			'p'       => array(
				'class' => true,
			),
			'strong'  => array(),
			'section' => array(
				'class' => true,
			),
			'h4'      => array(),
			'bdi'     => array(
				'dir' => true,
			),
			'dl'      => array(),
			'dt'      => array(),
			'dd'      => array(),
			'form'    => array(
				'method' => true,
				'action' => true,
			),
			'input'   => array(
				'type'  => true,
				'name'  => true,
				'value' => true,
			),
			'button'  => array(
				'type'  => true,
				'class' => true,
			),
		);
	}

	/**
	 * Build semantic Entry Detail HTML without performing delivery or persistence.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param array<string, mixed> $form  Form.
	 * @return string
	 */
	public function entry_detail_html( array $entry, array $form ): string {
		$entry_id = $this->positive_identifier( $entry['id'] ?? null );
		$form_id  = $this->positive_identifier( $form['id'] ?? null );
		if ( null === $entry_id ) {
			return '<p>Notification delivery state is unavailable for this Entry.</p>';
		}

		$model = $this->reader->read_entry( $entry_id );
		$html  = '<div class="gnm-entry-delivery" aria-live="polite">';
		$html .= $this->retry_notice_html( $model );

		if ( DeliveryStateReadResult::MISSING === $model['read_status'] ) {
			$html .= '<p>No notification delivery state has been recorded for this Entry yet.</p>';
			$html .= '</div>';
			return $html;
		}

		if ( DeliveryStateReadResult::MALFORMED === $model['read_status'] && empty( $model['targets'] ) ) {
			$html .= '<p><strong>Attention Required:</strong> persisted notification state is malformed or untrusted. It is not treated as resolved, and Retry is unavailable until valid state exists.</p>';
			$html .= '</div>';
			return $html;
		}

		foreach ( $model['targets'] as $target ) {
			$html .= $this->target_detail_html( $target, $entry_id, $form_id );
		}

		if ( DeliveryStateReadResult::MALFORMED === $model['read_status'] ) {
			$html .= '<p><strong>Attention Required:</strong> at least one persisted notification target is malformed or untrusted.</p>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Filter only site-declared Attention Required GravityViews.
	 *
	 * The canonical state is nested JSON and is intentionally not mirrored into a
	 * second queryable state store. Current GravityView documentation explicitly
	 * supports post-fetch filtering for logic that cannot be expressed safely in
	 * SQL. Count/pagination implications are documented in the WU-07 contract.
	 *
	 * @param mixed $entries GravityView Entry_Collection.
	 * @param mixed $view    GravityView View.
	 * @param mixed $request GravityView Request.
	 * @return mixed
	 */
	public function filter_gravityview_entries( $entries, $view, $request ) {
		unset( $request );

		$view_id = is_object( $view ) && isset( $view->ID ) ? $this->positive_identifier( $view->ID ) : null;
		if ( null === $view_id || ! $this->is_attention_view( $view_id ) ) {
			return $entries;
		}

		if ( ! class_exists( '\\GV\\Entry_Collection' ) || ! is_object( $entries ) || ! method_exists( $entries, 'all' ) ) {
			return $entries;
		}

		$filtered  = new \GV\Entry_Collection();
		$entry_map = array();
		foreach ( $entries->all() as $entry ) {
			$entry_id = is_object( $entry ) && isset( $entry->ID ) ? $this->positive_identifier( $entry->ID ) : null;
			if ( null !== $entry_id ) {
				$entry_map[ $entry_id ] = $entry;
			}
		}

		foreach ( $this->attention_entry_ids( array_keys( $entry_map ) ) as $entry_id ) {
			$filtered->add( $entry_map[ $entry_id ] );
		}

		return $filtered;
	}

	/**
	 * Shortcode used in GravityView Custom Content to expose bounded case facts.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function attention_target_shortcode( $atts ): string {
		if ( ! $this->current_user_can_view_entries() ) {
			return '';
		}

		$attributes = is_array( $atts ) ? $atts : array();
		$entry_id   = $this->positive_identifier( $attributes['entry_id'] ?? null );
		if ( null === $entry_id ) {
			return '';
		}

		$model = $this->reader->read_entry( $entry_id );
		if ( true !== $model['entry_requires_attention'] ) {
			return '';
		}

		$form_id = $this->first_form_id( $model );
		if ( null === $form_id ) {
			$entry   = $this->runtime->get_entry( $entry_id );
			$form_id = is_array( $entry ) ? $this->positive_identifier( $entry['form_id'] ?? null ) : null;
		}

		return $this->attention_card_html(
			$model,
			null !== $form_id ? self::entry_detail_url( $entry_id, $form_id ) : ''
		);
	}

	/**
	 * Build bounded read-only Attention Required card HTML.
	 *
	 * @param array<string, mixed> $model            Presentation model.
	 * @param string               $entry_detail_url Entry Detail URL when available.
	 * @return string
	 */
	public function attention_card_html( array $model, string $entry_detail_url ): string {
		$html  = '<div class="gnm-attention-card">';
		$html .= '<p><strong>Attention Required</strong></p>';

		if ( DeliveryStateReadResult::MALFORMED === ( $model['read_status'] ?? null ) && empty( $model['targets'] ) ) {
			$html .= '<p>Notification state is malformed or untrusted; it is not treated as resolved.</p>';
		} else {
			foreach ( $model['targets'] ?? array() as $target ) {
				if ( true !== ( $target['attention_required'] ?? false ) ) {
					continue;
				}
				$html .= $this->target_summary_html( $target );
			}
		}

		if ( '' !== $entry_detail_url ) {
			$html .= '<p><a class="button" href="' . $this->escape_attr( $entry_detail_url ) . '">Open Gravity Forms Entry Detail</a></p>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Build canonical Gravity Forms Entry Detail admin URL.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $form_id  Form ID.
	 * @return string
	 */
	public static function entry_detail_url( int $entry_id, int $form_id ): string {
		if ( 0 >= $entry_id || 0 >= $form_id ) {
			return '';
		}

		$base = function_exists( 'admin_url' ) ? admin_url( 'admin.php' ) : '/wp-admin/admin.php';
		$args = array(
			'page' => 'gf_entries',
			'view' => 'entry',
			'id'   => $form_id,
			'lid'  => $entry_id,
		);

		if ( function_exists( 'add_query_arg' ) ) {
			return add_query_arg( $args, $base );
		}

		return $base . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Build server-owned post-Retry redirect URL with bounded outcome data.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id  Form ID.
	 * @param int    $feed_id  Feed ID.
	 * @param string $result   Bounded Retry result.
	 * @return string
	 */
	public static function retry_result_url( int $entry_id, int $form_id, int $feed_id, string $result ): string {
		$url = self::entry_detail_url( $entry_id, $form_id );
		if ( '' === $url ) {
			return '';
		}

		$args = array(
			self::RETRY_RESULT_QUERY_ARG => $result,
			self::RETRY_FEED_QUERY_ARG   => $feed_id,
		);

		if ( function_exists( 'add_query_arg' ) ) {
			return add_query_arg( $args, $url );
		}

		return $url . '&' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Render one trusted or explicitly-untrusted target in Entry Detail.
	 *
	 * @param array<string, mixed> $target   Target model.
	 * @param int                  $entry_id Entry ID.
	 * @param int|null             $form_id  Form ID from current page.
	 * @return string
	 */
	private function target_detail_html( array $target, int $entry_id, ?int $form_id ): string {
		$feed_id = $this->positive_identifier( $target['feed_id'] ?? null );
		if ( null === $feed_id ) {
			return '';
		}

		if ( true !== ( $target['trusted'] ?? false ) ) {
			return '<section class="gnm-delivery-target"><p><strong>Feed <bdi dir="ltr">#' . $this->escape_html( (string) $feed_id ) . '</bdi>:</strong> state is malformed or untrusted. Attention Required; Retry unavailable.</p></section>';
		}

		$target_form_id = $this->positive_identifier( $target['form_id'] ?? null );
		$effective_form = null !== $form_id ? $form_id : $target_form_id;
		$name           = '' !== (string) $target['feed_name'] ? (string) $target['feed_name'] : 'Unnamed notification Feed';
		$attention      = true === $target['attention_required'] ? 'Yes' : 'No';
		$last           = is_array( $target['last_execution'] ?? null ) ? $target['last_execution'] : null;

		$html  = '<section class="gnm-delivery-target">';
		$html .= '<h4>' . $this->escape_html( $name ) . ' <bdi dir="ltr">#' . $this->escape_html( (string) $feed_id ) . '</bdi></h4>';
		$html .= '<dl>';
		$html .= '<dt>Channel</dt><dd><bdi dir="ltr">' . $this->escape_html( (string) $target['channel'] ) . '</bdi></dd>';
		$html .= '<dt>Final state</dt><dd><bdi dir="ltr">' . $this->escape_html( (string) $target['final_status'] ) . '</bdi></dd>';
		$html .= '<dt>Attention Required</dt><dd>' . $attention . '</dd>';
		$html .= '<dt>Retry eligibility</dt><dd><bdi dir="ltr">' . $this->escape_html( (string) $target['retry_eligibility'] ) . '</bdi></dd>';
		$html .= '</dl>';

		if ( null !== $last ) {
			$statuses = isset( $last['attempt_statuses'] ) && is_array( $last['attempt_statuses'] )
				? implode( ', ', $last['attempt_statuses'] )
				: '';
			$html .= '<p>Last execution: <bdi dir="ltr">' . $this->escape_html( (string) $last['type'] ) . '</bdi> at <bdi dir="ltr">' . $this->escape_html( (string) $last['timestamp'] ) . '</bdi>.</p>';
			$html .= '' !== $statuses
				? '<p>Attempt status: <bdi dir="ltr">' . $this->escape_html( $statuses ) . '</bdi></p>'
				: '<p>No provider attempt was recorded for the last execution.</p>';
		}

		if ( null !== $effective_form && $this->retry_control_eligible( $target, $entry_id, $effective_form ) ) {
			$html .= $this->retry_form_html( $entry_id, $effective_form, $feed_id );
		}

		$html .= '</section>';
		return $html;
	}

	/**
	 * Decide whether the explicit Retry control is safe to expose now.
	 *
	 * @param array<string, mixed> $target   Target model.
	 * @param int                  $entry_id Entry ID.
	 * @param int                  $form_id  Form ID.
	 * @return bool
	 */
	public function retry_control_eligible( array $target, int $entry_id, int $form_id ): bool {
		$feed_id = $this->positive_identifier( $target['feed_id'] ?? null );
		if ( null === $feed_id
			|| true !== ( $target['trusted'] ?? false )
			|| true !== ( $target['attention_required'] ?? false )
			|| DeliveryStateManager::RETRY_ALLOWED !== ( $target['retry_eligibility'] ?? null )
			|| ! $this->runtime->current_user_can_retry() ) {
			return false;
		}

		$feed = $this->runtime->get_feed( $feed_id );
		return ManualRetryHandler::is_feed_target_valid( $this->add_on, $feed, $feed_id, $form_id );
	}

	/**
	 * Build explicit POST-only Retry form using the existing WU-05 action/nonce contract.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $form_id  Form ID.
	 * @param int $feed_id  Feed ID.
	 * @return string
	 */
	private function retry_form_html( int $entry_id, int $form_id, int $feed_id ): string {
		unset( $form_id );

		if ( ! function_exists( 'wp_create_nonce' ) ) {
			return '';
		}

		$action_url = function_exists( 'admin_url' ) ? admin_url( 'admin-post.php' ) : '/wp-admin/admin-post.php';
		$nonce      = wp_create_nonce( WordPressManualRetryRuntime::nonce_action( $entry_id, $feed_id ) );

		$html  = '<form method="post" action="' . $this->escape_attr( $action_url ) . '">';
		$html .= '<input type="hidden" name="action" value="' . $this->escape_attr( ManualRetryHandler::ACTION ) . '" />';
		$html .= '<input type="hidden" name="entry_id" value="' . $this->escape_attr( (string) $entry_id ) . '" />';
		$html .= '<input type="hidden" name="feed_id" value="' . $this->escape_attr( (string) $feed_id ) . '" />';
		$html .= '<input type="hidden" name="_wpnonce" value="' . $this->escape_attr( $nonce ) . '" />';
		$html .= '<button type="submit" class="button">Retry notification now</button>';
		$html .= '<p class="description">Retry is synchronous and may contact the configured external channel.</p>';
		$html .= '</form>';
		return $html;
	}

	/**
	 * Build bounded target summary for GravityView output.
	 *
	 * @param array<string, mixed> $target Target model.
	 * @return string
	 */
	private function target_summary_html( array $target ): string {
		$feed_id = $this->positive_identifier( $target['feed_id'] ?? null );
		if ( null === $feed_id ) {
			return '';
		}

		if ( true !== ( $target['trusted'] ?? false ) ) {
			return '<p>Feed <bdi dir="ltr">#' . $this->escape_html( (string) $feed_id ) . '</bdi>: state is malformed or untrusted.</p>';
		}

		$last     = is_array( $target['last_execution'] ?? null ) ? $target['last_execution'] : null;
		$statuses = null !== $last && isset( $last['attempt_statuses'] ) && is_array( $last['attempt_statuses'] )
			? implode( ', ', $last['attempt_statuses'] )
			: '';
		$name     = '' !== (string) $target['feed_name'] ? (string) $target['feed_name'] : 'Unnamed notification Feed';

		$html  = '<p><strong>' . $this->escape_html( $name ) . '</strong> <bdi dir="ltr">#' . $this->escape_html( (string) $feed_id ) . '</bdi> — ';
		$html .= '<bdi dir="ltr">' . $this->escape_html( (string) $target['channel'] ) . '</bdi> — ';
		$html .= '<bdi dir="ltr">' . $this->escape_html( (string) $target['final_status'] ) . '</bdi>';
		$html .= '' !== $statuses ? ' — attempts: <bdi dir="ltr">' . $this->escape_html( $statuses ) . '</bdi>' : '';
		$html .= '</p>';
		return $html;
	}

	/**
	 * Show post-Retry result only after re-reading authoritative state.
	 *
	 * @param array<string, mixed> $model Fresh state model.
	 * @return string
	 */
	private function retry_notice_html( array $model ): string {
		$result  = $this->sanitized_query_value( self::RETRY_RESULT_QUERY_ARG );
		$feed_id = $this->positive_identifier( $this->sanitized_query_value( self::RETRY_FEED_QUERY_ARG ) );
		if ( '' === $result || null === $feed_id ) {
			return '';
		}

		return $this->retry_notice_for_result( $model, $result, $feed_id );
	}

	/**
	 * Build one Retry notice only from the freshly re-read authoritative model.
	 *
	 * @param array<string, mixed> $model   Fresh presentation model.
	 * @param string               $result  Bounded Retry result.
	 * @param int                  $feed_id Feed ID.
	 * @return string
	 */
	public function retry_notice_for_result( array $model, string $result, int $feed_id ): string {
		$target = $this->find_target( $model, $feed_id );
		if ( ManualRetryHandler::RESULT_SUCCESS === $result
			&& is_array( $target )
			&& true === ( $target['trusted'] ?? false )
			&& DeliveryStateManager::FINAL_RESOLVED === ( $target['final_status'] ?? null )
			&& false === ( $target['attention_required'] ?? true ) ) {
			return '<div class="notice notice-success inline"><p>Retry completed and authoritative Entry Meta now confirms RESOLVED.</p></div>';
		}

		if ( ManualRetryHandler::RESULT_UNRESOLVED === $result
			&& is_array( $target )
			&& true === ( $target['attention_required'] ?? false ) ) {
			return '<div class="notice notice-warning inline"><p>Retry completed without confirmed resolution. Attention Required remains active.</p></div>';
		}

		if ( ManualRetryHandler::ERROR_STATE === $result ) {
			return '<div class="notice notice-error inline"><p>Retry transport may have run, but a persisted state transition was not confirmed. Do not infer delivery resolution.</p></div>';
		}

		return '<div class="notice notice-warning inline"><p>Retry result could not be confirmed from current authoritative Entry Meta.</p></div>';
	}

	/**
	 * Return only Entry IDs whose fresh authoritative state requires attention.
	 *
	 * @param array<int, int|string> $entry_ids Candidate Entry IDs.
	 * @return array<int, int>
	 */
	public function attention_entry_ids( array $entry_ids ): array {
		$attention = array();
		foreach ( $entry_ids as $candidate ) {
			$entry_id = $this->positive_identifier( $candidate );
			if ( null === $entry_id ) {
				continue;
			}

			$model = $this->reader->read_entry( $entry_id );
			if ( true === $model['entry_requires_attention'] ) {
				$attention[] = $entry_id;
			}
		}

		return $attention;
	}

	/**
	 * Determine whether current GravityView is site-declared for Attention Required.
	 *
	 * @param int $view_id View ID.
	 * @return bool
	 */
	private function is_attention_view( int $view_id ): bool {
		if ( ! function_exists( 'apply_filters' ) ) {
			return false;
		}

		$ids = apply_filters( self::ATTENTION_VIEW_IDS_FILTER, array() );
		if ( ! is_array( $ids ) ) {
			return false;
		}

		foreach ( $ids as $candidate ) {
			if ( $view_id === $this->positive_identifier( $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether current user may see Gravity Forms entries.
	 *
	 * @return bool
	 */
	private function current_user_can_view_entries(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( self::VIEW_CAPABILITY );
	}

	/**
	 * Find one target in a fresh presentation model.
	 *
	 * @param array<string, mixed> $model   Model.
	 * @param int                  $feed_id Feed ID.
	 * @return array<string, mixed>|null
	 */
	private function find_target( array $model, int $feed_id ): ?array {
		foreach ( $model['targets'] ?? array() as $target ) {
			if ( is_array( $target ) && $feed_id === $this->positive_identifier( $target['feed_id'] ?? null ) ) {
				return $target;
			}
		}

		return null;
	}

	/**
	 * Return first trusted Form ID from a model.
	 *
	 * @param array<string, mixed> $model Model.
	 * @return int|null
	 */
	private function first_form_id( array $model ): ?int {
		foreach ( $model['targets'] ?? array() as $target ) {
			if ( is_array( $target ) && true === ( $target['trusted'] ?? false ) ) {
				$form_id = $this->positive_identifier( $target['form_id'] ?? null );
				if ( null !== $form_id ) {
					return $form_id;
				}
			}
		}

		return null;
	}

	/**
	 * Read a bounded query value for the result notice only.
	 *
	 * @param string $key Query key.
	 * @return string
	 */
	private function sanitized_query_value( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status display; no mutation or authorization decision is based on this query value.
		$value = $_GET[ $key ] ?? '';
		if ( function_exists( 'wp_unslash' ) ) {
			$value = wp_unslash( $value );
		}
		if ( function_exists( 'sanitize_text_field' ) ) {
			$value = sanitize_text_field( $value );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Require canonical positive decimal identifier.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function positive_identifier( $value ): ?int {
		if ( is_int( $value ) ) {
			return 0 < $value ? $value : null;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return null;
		}

		$identifier = (int) $value;
		return 0 < $identifier ? $identifier : null;
	}

	/**
	 * Escape text for HTML, including deterministic unit-test fallback.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function escape_html( string $value ): string {
		return function_exists( 'esc_html' )
			? esc_html( $value )
			: htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Escape HTML attribute, including deterministic unit-test fallback.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function escape_attr( string $value ): string {
		return function_exists( 'esc_attr' )
			? esc_attr( $value )
			: htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
