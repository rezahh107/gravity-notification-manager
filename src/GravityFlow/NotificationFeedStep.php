<?php
/**
 * Gravity Flow Feed-Step adapter for Gravity Notification Manager.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityFlow;

use GravityNotify\GravityForms\NotificationFeedAddOn;

/**
 * Selects existing GNM feeds through Gravity Flow's supported Feed-Step base.
 *
 * Feed discovery, native condition evaluation, processing, processed-feed
 * tracking and ordinary-submit interception intentionally remain inherited.
 */
final class NotificationFeedStep extends \Gravity_Flow_Step_Feed_Add_On {

	/**
	 * Stable Gravity Flow step type.
	 *
	 * @var string
	 */
	public $_step_type = 'gravity_notification_manager';

	/**
	 * Canonical GFFeedAddOn class consumed by the Feed-Step base.
	 *
	 * @var string
	 */
	protected $_class_name = NotificationFeedAddOn::class;

	/**
	 * Step label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Gravity Notification Manager';
	}
}
