<?php
/**
 * Test-only greenfield registration and dependency composition.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

use GravityNotify\GravityForms\NotificationFeedAddOn;

if ( ! defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) || true !== GRAVITY_NOTIFY_TEST_NO_SEND ) {
	throw new RuntimeException( 'The real-runtime bootstrap requires fail-closed no-send mode.' );
}
if ( ! class_exists( 'GFFeedAddOn' ) || ! class_exists( 'Gravity_Flow_Step_Feed_Add_On' ) ) {
	throw new RuntimeException( 'Real Gravity Forms and Gravity Flow must load before the greenfield test bootstrap.' );
}

GFAddOn::register( NotificationFeedAddOn::class );
NotificationFeedAddOn::get_instance()->init();

add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		unset( $preempt, $args );
		return new WP_Error( 'gravity_notify_test_http_blocked', 'External HTTP blocked by the GNM real-runtime harness.', $url );
	},
	PHP_INT_MIN,
	3
);
