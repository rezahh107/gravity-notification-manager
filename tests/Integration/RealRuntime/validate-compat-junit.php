<?php
/**
 * Fail-closed WU-10 compatibility JUnit validation.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

const GRAVITY_NOTIFY_WU10_COMPAT_TEST = 'test_wu10_compat_real_01_exact_runtime_identity_and_authentic_dependencies';

$gravity_notify_result_path = $argv[1] ?? '';
if ( '' === $gravity_notify_result_path || ! is_readable( $gravity_notify_result_path ) ) {
	fwrite( STDERR, "WU-10 compatibility JUnit result is missing or unreadable.\n" );
	exit( 3 );
}

$gravity_notify_document = new DOMDocument();
if ( ! $gravity_notify_document->load( $gravity_notify_result_path, LIBXML_NONET ) ) {
	fwrite( STDERR, "WU-10 compatibility JUnit result is malformed.\n" );
	exit( 3 );
}

$gravity_notify_matched = null;
foreach ( $gravity_notify_document->getElementsByTagName( 'testcase' ) as $gravity_notify_test_case ) {
	$gravity_notify_name = $gravity_notify_test_case->attributes?->getNamedItem( 'name' )?->nodeValue;
	if ( GRAVITY_NOTIFY_WU10_COMPAT_TEST === $gravity_notify_name ) {
		$gravity_notify_matched = $gravity_notify_test_case;
		break;
	}
}
if ( ! $gravity_notify_matched instanceof DOMElement ) {
	fwrite( STDERR, "Required WU10-COMPAT-REAL-01 test was not present in JUnit output.\n" );
	exit( 5 );
}
if ( 0 < $gravity_notify_matched->getElementsByTagName( 'error' )->length ) {
	fwrite( STDERR, "WU10-COMPAT-REAL-01 ended with a harness/runtime error.\n" );
	exit( 3 );
}
if ( 0 < $gravity_notify_matched->getElementsByTagName( 'failure' )->length ) {
	fwrite( STDERR, "WU10-COMPAT-REAL-01 observed a compatibility assertion failure.\n" );
	exit( 4 );
}
if ( 0 < $gravity_notify_matched->getElementsByTagName( 'skipped' )->length ) {
	fwrite( STDERR, "WU10-COMPAT-REAL-01 was skipped or incomplete.\n" );
	exit( 5 );
}
printf( "GNM_WU10_COMPAT_MANIFEST=PASS tests=1\n" );
