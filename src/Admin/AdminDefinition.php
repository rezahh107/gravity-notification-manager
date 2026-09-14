<?php
/**
 * Stable Gravity Notification Manager admin definition.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/** Defines the GNM information architecture and capability boundary. */
final class AdminDefinition {

	public const CAPABILITY                         = 'manage_options';
	public const ROOT_SLUG                          = 'gravity-notification-manager';
	public const POINTS_SLUG                        = 'gravity-notification-manager-points';
	public const PROVIDERS_SLUG                     = 'gravity-notification-manager-providers';
	public const LOGS_SLUG                          = 'gravity-notification-manager-operational-log';
	public const SETTINGS_SLUG                      = 'gravity-notification-manager-settings';
	public const ADVISOR_SLUG                       = 'gravity-notification-manager-advisor';
	public const DIAGNOSTICS_SLUG                   = 'gravity-notification-manager-diagnostics';
	public const CHECK_ACTION                       = 'gravity_notify_check_point';
	public const TEST_BALE_ACTION                   = 'gravity_notify_test_bale';
	public const PROVIDER_TEST_SMS_ACTION           = 'gravity_notify_provider_manager_test_sms';
	public const PROVIDER_CHECK_CONNECTION_ACTION   = 'gravity_notify_provider_manager_check_connection';
	public const PROVIDER_DISCOVER_LINES_ACTION     = 'gravity_notify_provider_manager_discover_lines';

	/** @return array<int, array{slug:string,title:string}> */
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

	/** @return array{slug:string,title:string} */
	public static function provider_surface(): array {
		return array(
			'slug'  => self::PROVIDERS_SLUG,
			'title' => __( 'SMS Providers', 'gravity-notification-manager' ),
		);
	}

	/** @return array{slug:string,title:string} */
	public static function log_surface(): array {
		return array(
			'slug'  => self::LOGS_SLUG,
			'title' => __( 'Operational Log', 'gravity-notification-manager' ),
		);
	}

	/** @return array<int, array{slug:string,title:string}> */
	public static function navigation_surfaces(): array {
		$surfaces = self::surfaces();
		array_splice( $surfaces, 2, 0, array( self::provider_surface(), self::log_surface() ) );
		return $surfaces;
	}
}
