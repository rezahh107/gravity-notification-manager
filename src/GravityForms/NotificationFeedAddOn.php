<?php
/**
 * Greenfield Gravity Forms Feed Add-On foundation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityForms;

use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\DeliveryState\EntryMetaDeliveryStore;
use GravityNotify\GravityFlow\FeedStepRegistration;

/**
 * Defines one logical notification per Gravity Forms Feed.
 */
final class NotificationFeedAddOn extends \GFFeedAddOn {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Add-On version for the greenfield feed foundation.
	 *
	 * @var string
	 */
	protected $_version = '0.1.0';

	/**
	 * Canonical plugin slug.
	 *
	 * @var string
	 */
	protected $_slug = 'gravity-notification-manager';

	/**
	 * Canonical product title.
	 *
	 * @var string
	 */
	protected $_title = 'Gravity Notification Manager';

	/**
	 * Canonical short title.
	 *
	 * @var string
	 */
	protected $_short_title = 'Gravity Notification Manager';

	/**
	 * Keep feed processing synchronous by closed architecture decision.
	 *
	 * @var bool
	 */
	protected $_async_feed_processing = false;

	/**
	 * Request-local WU-04 execution dependency.
	 *
	 * Production settings/provider composition remains a later cutover concern.
	 *
	 * @var NotificationFeedProcessor|null
	 */
	private ?NotificationFeedProcessor $processor = null;

	/**
	 * Request-local WU-05 state manager override.
	 *
	 * Real Gravity Forms runtime lazily uses EntryMetaDeliveryStore when this is null.
	 *
	 * @var DeliveryStateManager|null
	 */
	private ?DeliveryStateManager $delivery_state_manager = null;

	/**
	 * Last request-local execution result.
	 *
	 * @var NotificationExecutionResult|null
	 */
	private ?NotificationExecutionResult $last_execution_result = null;

	/**
	 * Return the singleton Add-On instance.
	 *
	 * Also boots the optional Gravity Flow registration bridge without requiring
	 * Gravity Flow to be installed or loaded.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		FeedStepRegistration::boot();

		return self::$_instance;
	}

	/**
	 * Initialize native Add-On behavior and the authenticated manual Retry handler.
	 *
	 * Registration is side-effect-free with respect to notification delivery.
	 *
	 * @return void
	 */
	public function init() {
		parent::init();
		ManualRetryHandler::boot( $this );
	}

	/**
	 * Inject the already-composed synchronous WU-04 execution dependency.
	 *
	 * This seam avoids coupling Feed execution to legacy settings or activating
	 * production senders before the controlled cutover Work Unit.
	 *
	 * @param NotificationFeedProcessor|null $processor Processor or null to disable runtime delivery.
	 * @return void
	 */
	public function configure_processor( ?NotificationFeedProcessor $processor ): void {
		$this->processor = $processor;
	}

	/**
	 * Inject deterministic state persistence for tests or bounded composition.
	 *
	 * @param DeliveryStateManager|null $manager State manager, or null for native lazy Entry Meta storage.
	 * @return void
	 */
	public function configure_delivery_state_manager( ?DeliveryStateManager $manager ): void {
		$this->delivery_state_manager = $manager;
	}

	/**
	 * Return the latest request-local execution result.
	 *
	 * @return NotificationExecutionResult|null
	 */
	public function last_execution_result(): ?NotificationExecutionResult {
		return $this->last_execution_result;
	}

	/**
	 * Define the standard Gravity Forms Feed Settings fields for one rule.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function feed_settings_fields() {
		return array(
			array(
				'title'  => 'Notification Rule',
				'fields' => array(
					array(
						'name'     => 'feedName',
						'label'    => 'Feed Name',
						'type'     => 'text',
						'required' => true,
					),
					array(
						'name'     => 'message',
						'label'    => 'Message',
						'type'     => 'textarea',
						'class'    => 'medium merge-tag-support mt-position-right',
						'required' => true,
					),
					array(
						'name'    => 'recipient_source_type',
						'label'   => 'Recipient Source',
						'type'    => 'select',
						'choices' => $this->recipient_source_choices(),
					),
					array(
						'name'  => 'recipient_source_value',
						'label' => 'Recipient Source Value',
						'type'  => 'text',
					),
					array(
						'name'    => 'channel',
						'label'   => 'Channel',
						'type'    => 'select',
						'choices' => array(
							array(
								'label' => 'SMS',
								'value' => FeedRuleSchema::CHANNEL_SMS,
							),
							array(
								'label' => 'Bale',
								'value' => FeedRuleSchema::CHANNEL_BALE,
							),
						),
					),
					array(
						'name'    => 'fallback_policy',
						'label'   => 'Fallback Policy',
						'type'    => 'select',
						'choices' => array(
							array(
								'label' => 'No fallback',
								'value' => FeedRuleSchema::FALLBACK_NONE,
							),
							array(
								'label' => 'Compatible SMS fallback',
								'value' => FeedRuleSchema::FALLBACK_COMPATIBLE_SMS,
							),
						),
					),
					array(
						'name'  => 'feed_condition',
						'label' => 'Condition',
						'type'  => 'feed_condition',
					),
				),
			),
		);
	}

	/**
	 * Shared synchronous Gravity Forms / Gravity Flow Feed-processing boundary.
	 *
	 * Gravity Forms and Gravity Flow evaluate their native Feed condition before
	 * invoking this method. The returned boolean is delivery outcome evidence for
	 * the native Feed framework; Gravity Flow step completion remains owned by
	 * the Feed-Step base and is not coupled to this value.
	 *
	 * @param array $feed  Gravity Forms feed.
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form.
	 * @return bool Whether delivery obtained documented acceptance.
	 */
	public function process_feed( $feed, $entry, $form ) {
		$result = $this->execute_feed(
			is_array( $feed ) ? $feed : array(),
			is_array( $entry ) ? $entry : array(),
			is_array( $form ) ? $form : array(),
			false
		);

		return $result->delivery_succeeded();
	}

	/**
	 * Explicit manual Retry execution seam used only by the secure handler.
	 *
	 * Retry requires an existing valid target with Attention Required and bypasses
	 * only ordinary duplicate suppression. Recipient resolution and transport
	 * routing remain exactly the current WU-03/WU-02 synchronous chain.
	 *
	 * @param array $feed  Validated Feed object.
	 * @param array $entry Validated Entry object.
	 * @param array $form  Validated Form object.
	 * @return NotificationExecutionResult|null Null when persisted state is not eligible for Retry.
	 */
	public function retry_feed( array $feed, array $entry, array $form ): ?NotificationExecutionResult {
		$manager  = $this->delivery_state_manager();
		$entry_id = $this->positive_identifier( $entry['id'] ?? null );
		$feed_id  = $this->positive_identifier( $feed['id'] ?? null );

		if ( null === $manager || null === $entry_id || null === $feed_id ) {
			return null;
		}

		if ( DeliveryStateManager::RETRY_ALLOWED !== $manager->retry_eligibility( $entry_id, $feed_id ) ) {
			return null;
		}

		return $this->execute_feed( $feed, $entry, $form, true );
	}

	/**
	 * Execute one normalized Feed rule and persist bounded WU-05 state.
	 *
	 * @param array $feed         Feed object.
	 * @param array $entry        Entry object.
	 * @param array $form         Form object.
	 * @param bool  $manual_retry Whether duplicate suppression must be bypassed.
	 * @return NotificationExecutionResult
	 */
	private function execute_feed( array $feed, array $entry, array $form, bool $manual_retry ): NotificationExecutionResult {
		$meta = isset( $feed['meta'] ) && is_array( $feed['meta'] )
			? $feed['meta']
			: array();

		$rule       = FeedRuleSchema::normalize( $meta );
		$manager    = $this->delivery_state_manager();
		$entry_id   = $this->positive_identifier( $entry['id'] ?? null );
		$form_id    = $this->positive_identifier( $form['id'] ?? null );
		$feed_id    = $this->positive_identifier( $feed['id'] ?? null );
		$feed_name  = (string) ( $rule['feedName'] ?? '' );
		$channel    = (string) ( $rule['channel'] ?? '' );
		$can_record = null !== $manager && null !== $entry_id && null !== $form_id && null !== $feed_id;

		if ( ! $manual_retry && $can_record && $manager->is_confirmed_complete( $entry_id, $feed_id ) ) {
			$result = new NotificationExecutionResult(
				array(),
				array(
					array(
						'subject' => 'delivery_state',
						'reason'  => 'duplicate_suppressed',
					),
				),
				true
			);

			if ( ! $manager->record_execution(
				$entry_id,
				$form_id,
				$feed_id,
				$feed_name,
				$channel,
				$result,
				DeliveryStateManager::EXECUTION_DUPLICATE_SUPPRESSED
			) ) {
				$result = $this->with_state_persistence_failure( $result );
			}

			$this->last_execution_result = $result;
			return $result;
		}

		if ( null === $this->processor ) {
			$result = new NotificationExecutionResult(
				array(),
				array(
					array(
						'subject' => 'runtime',
						'reason'  => 'runtime_not_configured',
					),
				),
				false
			);
		} else {
			$result = $this->processor->execute( $rule, $entry, $form );
		}

		if ( $can_record ) {
			$execution_type = $manual_retry
				? DeliveryStateManager::EXECUTION_MANUAL_RETRY
				: DeliveryStateManager::EXECUTION_ORDINARY;

			if ( ! $manager->record_execution( $entry_id, $form_id, $feed_id, $feed_name, $channel, $result, $execution_type ) ) {
				$result = $this->with_state_persistence_failure( $result );
			}
		}

		$this->last_execution_result = $result;
		return $result;
	}

	/**
	 * Lazily compose the native Entry Meta store only when Gravity Forms meta APIs exist.
	 *
	 * @return DeliveryStateManager|null
	 */
	private function delivery_state_manager(): ?DeliveryStateManager {
		if ( null === $this->delivery_state_manager && function_exists( 'gform_get_meta' ) && function_exists( 'gform_update_meta' ) ) {
			$this->delivery_state_manager = new DeliveryStateManager( new EntryMetaDeliveryStore() );
		}

		return $this->delivery_state_manager;
	}

	/**
	 * Add a request-local state persistence failure without changing transport truth.
	 *
	 * @param NotificationExecutionResult $result Current result.
	 * @return NotificationExecutionResult
	 */
	private function with_state_persistence_failure( NotificationExecutionResult $result ): NotificationExecutionResult {
		$skips   = $result->skips();
		$skips[] = array(
			'subject' => 'delivery_state',
			'reason'  => 'persistence_failed',
		);

		return new NotificationExecutionResult( $result->attempts(), $skips, $result->delivery_succeeded() );
	}

	/**
	 * Require a positive integer identity without coercing malformed strings.
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
	 * Build standard Settings API choice arrays for later recipient resolution.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function recipient_source_choices(): array {
		return array(
			array(
				'label' => 'Entry field',
				'value' => FeedRuleSchema::RECIPIENT_ENTRY_FIELD,
			),
			array(
				'label' => 'Fixed target',
				'value' => FeedRuleSchema::RECIPIENT_FIXED,
			),
			array(
				'label' => 'WordPress user',
				'value' => FeedRuleSchema::RECIPIENT_USER,
			),
			array(
				'label' => 'WordPress role',
				'value' => FeedRuleSchema::RECIPIENT_ROLE,
			),
			array(
				'label' => 'Gravity Flow assignee',
				'value' => FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE,
			),
		);
	}
}
