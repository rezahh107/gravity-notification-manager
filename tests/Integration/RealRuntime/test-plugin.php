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
		unset( $args );

		$simulator_endpoint = getenv( 'GNM_IPPANEL_SIMULATOR_SEND_ENDPOINT' );
		$allowed_origin     = is_string( $simulator_endpoint ) ? wp_parse_url( $simulator_endpoint ) : false;
		$request_origin     = wp_parse_url( $url );
		$allowed_port       = is_array( $allowed_origin ) && isset( $allowed_origin['port'] ) ? (int) $allowed_origin['port'] : 0;

		if (
			is_array( $allowed_origin )
			&& 'http' === ( $allowed_origin['scheme'] ?? '' )
			&& '127.0.0.1' === ( $allowed_origin['host'] ?? '' )
			&& 0 < $allowed_port
			&& is_array( $request_origin )
			&& ( $allowed_origin['scheme'] ?? '' ) === ( $request_origin['scheme'] ?? '' )
			&& ( $allowed_origin['host'] ?? '' ) === ( $request_origin['host'] ?? '' )
			&& isset( $request_origin['port'] )
			&& $allowed_port === (int) $request_origin['port']
		) {
			return $preempt;
		}

		return new WP_Error( 'gravity_notify_test_http_blocked', 'External HTTP blocked by the GNM real-runtime harness.', $url );
	},
	PHP_INT_MIN,
	3
);
