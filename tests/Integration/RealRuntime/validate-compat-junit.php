<?php
/**
 * Fail-closed WU-10 compatibility JUnit validation.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

const GRAVITY_NOTIFY_WU10_COMPAT_TEST = 'test_wu10_compat_real_01_exact_runtime_identity_and_authentic_dependencies';

$result_path = $argv[1] ?? '';
if ( '' === $result_path || ! is_readable( $result_path ) ) {
	fwrite( STDERR, "WU-10 compatibility JUnit result is missing or unreadable.\n" );
	exit( 3 );
}

$document = new DOMDocument();
if ( ! $document->load( $result_path, LIBXML_NONET ) ) {
	fwrite( STDERR, "WU-10 compatibility JUnit result is malformed.\n" );
	exit( 3 );
}

$matched = null;
foreach ( $document->getElementsByTagName( 'testcase' ) as $test_case ) {
	$name = $test_case->attributes?->getNamedItem( 'name' )?->nodeValue;
	if ( GRAVITY_NOTIFY_WU10_COMPAT_TEST === $name ) {
		$matched = $test_case;
		break;
	}
}
if ( ! $matched instanceof DOMElement ) {
	fwrite( STDERR, "Required WU10-COMPAT-REAL-01 test was not present in JUnit output.\n" );
	exit( 5 );
}
if ( 0 < $matched->getElementsByTagName( 'error' )->length ) {
	fwrite( STDERR, "WU10-COMPAT-REAL-01 ended with a harness/runtime error.\n" );
	exit( 3 );
}
if ( 0 < $matched->getElementsByTagName( 'failure' )->length ) {
	fwrite( STDERR, "WU10-COMPAT-REAL-01 observed a compatibility assertion failure.\n" );
	exit( 4 );
}
if ( 0 < $matched->getElementsByTagName( 'skipped' )->length ) {
	fwrite( STDERR, "WU10-COMPAT-REAL-01 was skipped or incomplete.\n" );
	exit( 5 );
}
printf( "GNM_WU10_COMPAT_MANIFEST=PASS tests=1\n" );
