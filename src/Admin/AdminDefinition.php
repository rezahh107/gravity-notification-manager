<?php
/**
 * Stable Gravity Notification Manager admin definition.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Defines the bounded WU-06 information architecture and capability boundary.
 */
final class AdminDefinition {

	public const CAPABILITY = 'manage_options';
	public const ROOT_SLUG = 'gravity-notification-manager';
	public const POINTS_SLUG = 'gravity-notification-manager-points';
	public const SETTINGS_SLUG = 'gravity-notification-manager-settings';
	public const DIAGNOSTICS_SLUG = 'gravity-notification-manager-diagnostics';
	public const CHECK_ACTION = 'gravity_notify_check_point';

	/**
	 * Return exactly the approved four product surfaces.
	 *
	 * @return array<int, array{slug:string,title:string}>
	 */
	public static function surfaces(): array {
		return array(
			array( 'slug' => self::ROOT_SLUG, 'title' => 'Overview' ),
			array( 'slug' => self::POINTS_SLUG, 'title' => 'Notification Points' ),
			array( 'slug' => self::SETTINGS_SLUG, 'title' => 'Settings' ),
			array( 'slug' => self::DIAGNOSTICS_SLUG, 'title' => 'Help & Diagnostics' ),
		);
	}
}
