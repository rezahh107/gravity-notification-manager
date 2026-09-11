<?php
/**
 * Strict local HTTP simulator for the IPPanel Edge subset used by GNM tests.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

$state_file = getenv( 'GNM_IPPANEL_SIMULATOR_STATE' ) ?: sys_get_temp_dir() . '/gnm-ippanel-simulator-state.json';
$token      = getenv( 'GNM_IPPANEL_SIMULATOR_TOKEN' ) ?: 'dummy-ippanel-token';
$from       = getenv( 'GNM_IPPANEL_SIMULATOR_FROM' ) ?: '+989000000000';
$to         = getenv( 'GNM_IPPANEL_SIMULATOR_TO' ) ?: '+989111111111';
$message    = getenv( 'GNM_IPPANEL_SIMULATOR_MESSAGE' ) ?: 'GNM deterministic simulator contract';
$reference  = '424242';

/** @return array<string, int> */
function gnm_simulator_read_state( string $path ): array {
	if ( ! is_readable( $path ) ) {
		return array( 'send_count' => 0, 'report_count' => 0 );
	}
	$decoded = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $decoded ) ? $decoded : array( 'send_count' => 0, 'report_count' => 0 );
}

/** @param array<string, int> $state */
function gnm_simulator_write_state( string $path, array $state ): void {
	file_put_contents( $path, json_encode( $state, JSON_UNESCAPED_SLASHES ) . PHP_EOL, LOCK_EX );
}

/** @param array<string, mixed> $payload */
function gnm_simulator_respond( int $status, array $payload ): never {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $payload, JSON_UNESCAPED_SLASHES );
	exit;
}

function gnm_simulator_require_auth( string $expected ): void {
	$observed = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! is_string( $observed ) || ! hash_equals( $expected, $observed ) ) {
		gnm_simulator_respond( 401, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
}

$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
$uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
$path   = (string) parse_url( $uri, PHP_URL_PATH );
$query  = array();
parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $query );

if ( '/_health' === $path ) {
	if ( 'GET' !== $method ) {
		gnm_simulator_respond( 405, array( 'status' => 'method_not_allowed' ) );
	}
	gnm_simulator_respond( 200, array( 'status' => 'ready' ) );
}

if ( '/_test/reset' === $path ) {
	if ( 'POST' !== $method ) {
		gnm_simulator_respond( 405, array( 'status' => 'method_not_allowed' ) );
	}
	gnm_simulator_require_auth( $token );
	gnm_simulator_write_state( $state_file, array( 'send_count' => 0, 'report_count' => 0 ) );
	gnm_simulator_respond( 200, array( 'status' => 'reset' ) );
}

if ( '/_test/state' === $path ) {
	if ( 'GET' !== $method ) {
		gnm_simulator_respond( 405, array( 'status' => 'method_not_allowed' ) );
	}
	gnm_simulator_require_auth( $token );
	gnm_simulator_respond( 200, gnm_simulator_read_state( $state_file ) );
}

if ( '/v1/api/send' === $path ) {
	if ( 'POST' !== $method ) {
		gnm_simulator_respond( 405, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	gnm_simulator_require_auth( $token );
	$content_type = strtolower( trim( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) ) );
	if ( 'application/json' !== strtok( $content_type, ';' ) ) {
		gnm_simulator_respond( 415, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	try {
		$payload = json_decode( (string) file_get_contents( 'php://input' ), true, 512, JSON_THROW_ON_ERROR );
	} catch ( JsonException $exception ) {
		unset( $exception );
		gnm_simulator_respond( 400, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	$keys        = is_array( $payload ) ? array_keys( $payload ) : array();
	$params      = is_array( $payload ) ? ( $payload['params'] ?? null ) : null;
	$param_keys  = is_array( $params ) ? array_keys( $params ) : array();
	$recipients  = is_array( $params ) ? ( $params['recipients'] ?? null ) : null;
	$valid_shape = is_array( $payload )
		&& array( 'sending_type', 'from_number', 'message', 'params' ) === $keys
		&& 'webservice' === ( $payload['sending_type'] ?? null )
		&& $from === ( $payload['from_number'] ?? null )
		&& $message === ( $payload['message'] ?? null )
		&& array( 'recipients' ) === $param_keys
		&& array( $to ) === $recipients;
	if ( ! $valid_shape ) {
		gnm_simulator_respond( 422, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	$state = gnm_simulator_read_state( $state_file );
	++$state['send_count'];
	gnm_simulator_write_state( $state_file, $state );
	if ( 1 !== $state['send_count'] ) {
		gnm_simulator_respond( 409, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	$scenario = is_string( $query['scenario'] ?? null ) ? $query['scenario'] : 'success';
	if ( 'provider_rejection' === $scenario ) {
		gnm_simulator_respond( 200, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	if ( 'missing_reference' === $scenario ) {
		gnm_simulator_respond( 200, array( 'meta' => array( 'status' => true ), 'data' => array( 'message_outbox_ids' => array() ) ) );
	}
	if ( 'success' !== $scenario ) {
		gnm_simulator_respond( 400, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	gnm_simulator_respond( 200, array( 'meta' => array( 'status' => true ), 'data' => array( 'message_outbox_ids' => array( $reference ) ) ) );
}

if ( '/v1/api/report/recipients' === $path ) {
	if ( 'GET' !== $method ) {
		gnm_simulator_respond( 405, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	gnm_simulator_require_auth( $token );
	if ( '1' !== (string) ( $query['page'] ?? '' ) || '10' !== (string) ( $query['per_page'] ?? '' ) || $reference !== (string) ( $query['bulk_id'] ?? '' ) ) {
		gnm_simulator_respond( 422, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	$state = gnm_simulator_read_state( $state_file );
	++$state['report_count'];
	gnm_simulator_write_state( $state_file, $state );
	$scenario = is_string( $query['scenario'] ?? null ) ? $query['scenario'] : 'delivered';
	$status   = '2';
	if ( 'terminal_non_delivery' === $scenario ) {
		$status = '3';
	} elseif ( 'pending' === $scenario ) {
		$status = '0';
	} elseif ( 'delivered' !== $scenario ) {
		gnm_simulator_respond( 400, array( 'meta' => array( 'status' => false ), 'data' => null ) );
	}
	gnm_simulator_respond( 200, array( 'meta' => array( 'status' => true ), 'data' => array( array( 'message_status' => $status ) ) ) );
}

gnm_simulator_respond( 404, array( 'meta' => array( 'status' => false ), 'data' => null ) );
