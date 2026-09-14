<?php
/**
 * Stable Gravity Notification Manager admin definition.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Defines the bounded GNM information architecture and capability boundary.
 */
final class AdminDefinition {

	public const CAPABILITY = 'manage_options';
	public const ROOT_SLUG = 'gravity-notification-manager';
	public const POINTS_SLUG = 'gravity-notification-manager-points';
	public const SETTINGS_SLUG = 'gravity-notification-manager-settings';
	public const ADVISOR_SLUG = 'gravity-notification-manager-advisor';
	public const DIAGNOSTICS_SLUG = 'gravity-notification-manager-diagnostics';
	public const CHECK_ACTION = 'gravity_notify_check_point';
	public const TEST_SMS_ACTION = 'gravity_notify_test_sms';
	public const TEST_BALE_ACTION = 'gravity_notify_test_bale';

	/**
	 * Return exactly the approved five product surfaces.
	 *
	 * @return array<int, array{slug:string,title:string}>
	 */
	public static function surfaces(): array {
		return array(
			array(
				'slug'  => self::ROOT_SLUG,
				'title' => __( 'Overview', 'gravity-notification-manager' ),
			),
			array(
				'slug'  => self::POINTS_SLUG,
				'title' => __( 'Notification Points', 'gravity-notification-manager' ),
			),
			array(
				'slug'  => self::SETTINGS_SLUG,
				'title' => __( 'Settings', 'gravity-notification-manager' ),
			),
			array(
				'slug'  => self::ADVISOR_SLUG,
				'title' => __( 'Advisor', 'gravity-notification-manager' ),
			),
			array(
				'slug'  => self::DIAGNOSTICS_SLUG,
				'title' => __( 'Help & Diagnostics', 'gravity-notification-manager' ),
			),
		);
	}
}
