<?php
/**
 * Greenfield Gravity Forms Feed Add-On foundation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityForms;

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
	 * Return the singleton Add-On instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
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
	 * Synchronous Gravity Forms feed-processing boundary for WU-01.
	 *
	 * The foundation intentionally performs no provider/network delivery and
	 * does not create delivery-state semantics. Later Work Units own those
	 * responsibilities.
	 *
	 * @param array $feed  Gravity Forms feed.
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form.
	 * @return void
	 */
	public function process_feed( $feed, $entry, $form ) {
		unset( $entry, $form );

		$meta = is_array( $feed ) && isset( $feed['meta'] ) && is_array( $feed['meta'] )
			? $feed['meta']
			: array();

		FeedRuleSchema::normalize( $meta );
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
