<?php
/**
 * WordPress schema helper stub for operational installer tests.
 *
 * @package GravityNotify
 */

/**
 * Model WordPress dbDelta without mutating the installer test double.
 *
 * @param string|array $queries Schema queries.
 * @param bool         $execute Whether WordPress would execute the queries.
 * @return array<int, string> Empty result for the isolated installer test.
 */
function dbDelta( string|array $queries = '', bool $execute = true ): array { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Intentional WordPress core test stub.
	unset( $queries, $execute );
	return array();
}
