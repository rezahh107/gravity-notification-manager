<?php
/**
 * PHPUnit bootstrap for the greenfield test harness.
 *
 * @package GravityNotify
 */

if ( ! defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) ) {
	define( 'GRAVITY_NOTIFY_TEST_NO_SEND', true );
}

if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
