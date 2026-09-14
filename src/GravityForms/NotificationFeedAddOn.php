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
use GravityNotify\Observability\OperationalContext;

/**
 * Defines one logical notification per Gravity Forms Feed.
 */
final class NotificationFeedAddOn extends \GFFeedAddOn {

	/** @var self|null */
	private static $_instance = null;
	protected $_version = '0.1.0';
	protected $_slug = 'gravity-notification-manager';
	protected $_title = '';
	protected $_short_title = '';
	protected $_async_feed_processing = false;
	private ?NotificationFeedProcessor $processor = null;
	private ?DeliveryStateManager $delivery_state_manager = null;
	private ?NotificationExecutionResult $last_execution_result = null;

	public static function get_instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		FeedStepRegistration::boot();
		return self::$_instance;
	}

	public function init() {
		$this->_title       = __( 'Gravity Notification Manager', 'gravity-notification-manager' );
		$this->_short_title = __( 'Gravity Notification Manager', 'gravity-notification-manager' );
		parent::init();
		ManualRetryHandler::boot( $this );
	}

	public function configure_processor( ?NotificationFeedProcessor $processor ): void {
		$this->processor = $processor;
	}

	public function configure_delivery_state_manager( ?DeliveryStateManager $manager ): void {
		$this->delivery_state_manager = $manager;
	}

	public function last_execution_result(): ?NotificationExecutionResult {
		return $this->last_execution_result;
	}

	/** @return array<int, array<string, mixed>> */
	public function feed_settings_fields() {
		return array(
			array(
				'title'  => __( 'Notification Rule', 'gravity-notification-manager' ),
				'fields' => array(
					array(
						'name'     => 'feedName',
						'label'    => __( 'Feed Name', 'gravity-notification-manager' ),
						'type'     => 'text',
						'required' => true,
					),
					array(
						'name'     => 'message',
						'label'    => __( 'Message', 'gravity-notification-manager' ),
						'type'     => 'textarea',
						'class'    => 'medium merge-tag-support mt-position-right',
						'required' => true,
					),
					array(
						'name'    => 'recipient_source_type',
						'label'   => __( 'Recipient Source', 'gravity-notification-manager' ),
						'type'    => 'select',
						'choices' => $this->recipient_source_choices(),
					),
					array(
						'name'  => 'recipient_source_value',
						'label' => __( 'Recipient Source Value', 'gravity-notification-manager' ),
						'type'  => 'text',
					),
					array(
						'name'    => 'channel',
						'label'   => __( 'Channel', 'gravity-notification-manager' ),
						'type'    => 'select',
						'choices' => array(
							array( 'label' => __( 'SMS', 'gravity-notification-manager' ), 'value' => FeedRuleSchema::CHANNEL_SMS ),
							array( 'label' => __( 'Bale', 'gravity-notification-manager' ), 'value' => FeedRuleSchema::CHANNEL_BALE ),
						),
					),
					array(
						'name'    => 'fallback_policy',
						'label'   => __( 'Fallback Policy', 'gravity-notification-manager' ),
						'type'    => 'select',
						'choices' => array(
							array( 'label' => __( 'No fallback', 'gravity-notification-manager' ), 'value' => FeedRuleSchema::FALLBACK_NONE ),
							array( 'label' => __( 'Compatible SMS fallback', 'gravity-notification-manager' ), 'value' => FeedRuleSchema::FALLBACK_COMPATIBLE_SMS ),
						),
					),
					array( 'name' => 'feed_condition', 'label' => __( 'Condition', 'gravity-notification-manager' ), 'type' => 'feed_condition' ),
				),
			),
		);
	}

	public function process_feed( $feed, $entry, $form ) {
		$result = $this->execute_feed( is_array( $feed ) ? $feed : array(), is_array( $entry ) ? $entry : array(), is_array( $form ) ? $form : array(), false );
		return $result->delivery_succeeded();
	}

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
		$result = $this->execute_feed( $feed, $entry, $form, true );
		return $this->has_state_persistence_failure( $result ) ? null : $result;
	}

	private function execute_feed( array $feed, array $entry, array $form, bool $manual_retry ): NotificationExecutionResult {
		$meta = isset( $feed['meta'] ) && is_array( $feed['meta'] ) ? $feed['meta'] : array();
		$rule       = FeedRuleSchema::normalize( $meta );
		$manager    = $this->delivery_state_manager();
		$entry_id   = $this->positive_identifier( $entry['id'] ?? null );
		$form_id    = $this->positive_identifier( $form['id'] ?? null );
		$feed_id    = $this->positive_identifier( $feed['id'] ?? null );
		$feed_name  = (string) ( $rule['feedName'] ?? '' );
		$channel    = (string) ( $rule['channel'] ?? '' );
		$can_record = null !== $manager && null !== $entry_id && null !== $form_id && null !== $feed_id;

		if ( ! $manual_retry && $can_record && $manager->is_confirmed_complete( $entry_id, $feed_id ) ) {
			$result = new NotificationExecutionResult( array(), array( array( 'subject' => 'delivery_state', 'reason' => 'duplicate_suppressed' ) ), true );
			if ( ! $manager->record_execution( $entry_id, $form_id, $feed_id, $feed_name, $channel, $result, DeliveryStateManager::EXECUTION_DUPLICATE_SUPPRESSED ) ) {
				$result = $this->with_state_persistence_failure( $result );
			}
			$this->last_execution_result = $result;
			return $result;
		}

		if ( null === $this->processor ) {
			$result = new NotificationExecutionResult( array(), array( array( 'subject' => 'runtime', 'reason' => 'runtime_not_configured' ) ), false );
		} else {
			$context = new OperationalContext(
				$manual_retry ? OperationalContext::EXECUTION_RETRY : OperationalContext::EXECUTION_NORMAL,
				$form_id,
				$feed_id,
				$entry_id,
				$feed_name
			);
			$result = $this->processor->execute( $rule, $entry, $form, $context );
		}

		if ( $can_record ) {
			$execution_type = $manual_retry ? DeliveryStateManager::EXECUTION_MANUAL_RETRY : DeliveryStateManager::EXECUTION_ORDINARY;
			if ( ! $manager->record_execution( $entry_id, $form_id, $feed_id, $feed_name, $channel, $result, $execution_type ) ) {
				$result = $this->with_state_persistence_failure( $result );
			}
		}
		$this->last_execution_result = $result;
		return $result;
	}

	private function delivery_state_manager(): ?DeliveryStateManager {
		if ( null === $this->delivery_state_manager && function_exists( 'gform_get_meta' ) && function_exists( 'gform_update_meta' ) ) {
			$this->delivery_state_manager = new DeliveryStateManager( new EntryMetaDeliveryStore() );
		}
		return $this->delivery_state_manager;
	}

	private function has_state_persistence_failure( NotificationExecutionResult $result ): bool {
		foreach ( $result->skips() as $skip ) {
			if ( 'delivery_state' === ( $skip['subject'] ?? null ) && 'persistence_failed' === ( $skip['reason'] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	private function with_state_persistence_failure( NotificationExecutionResult $result ): NotificationExecutionResult {
		$skips   = $result->skips();
		$skips[] = array( 'subject' => 'delivery_state', 'reason' => 'persistence_failed' );
		return new NotificationExecutionResult( $result->attempts(), $skips, $result->delivery_succeeded() );
	}

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

	/** @return array<int, array<string, string>> */
	private function recipient_source_choices(): array {
		return array(
			array( 'label' => __( 'Entry field', 'gravity-notification-manager' ), 'value' => FeedRuleSchema::RECIPIENT_ENTRY_FIELD ),
			array( 'label' => __( 'Fixed target', 'gravity-notification-manager' ), 'value' => FeedRuleSchema::RECIPIENT_FIXED ),
			array( 'label' => __( 'WordPress user', 'gravity-notification-manager' ), 'value' => FeedRuleSchema::RECIPIENT_USER ),
			array( 'label' => __( 'WordPress role', 'gravity-notification-manager' ), 'value' => FeedRuleSchema::RECIPIENT_ROLE ),
			array( 'label' => __( 'Gravity Flow assignee', 'gravity-notification-manager' ), 'value' => FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE ),
		);
	}
}
