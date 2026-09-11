<?php
/**
 * Strict local HTTP simulator for the IPPanel Edge subset used by GNM tests.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

$gravity_notify_state_file = getenv( 'GNM_IPPANEL_SIMULATOR_STATE' );
if ( ! is_string( $gravity_notify_state_file ) || '' === $gravity_notify_state_file ) {
	$gravity_notify_state_file = sys_get_temp_dir() . '/gnm-ippanel-simulator-state.json';
}

$gravity_notify_token = getenv( 'GNM_IPPANEL_SIMULATOR_TOKEN' );
if ( ! is_string( $gravity_notify_token ) || '' === $gravity_notify_token ) {
	$gravity_notify_token = 'dummy-ippanel-token';
}

$gravity_notify_from = getenv( 'GNM_IPPANEL_SIMULATOR_FROM' );
if ( ! is_string( $gravity_notify_from ) || '' === $gravity_notify_from ) {
	$gravity_notify_from = '+989000000000';
}

$gravity_notify_to = getenv( 'GNM_IPPANEL_SIMULATOR_TO' );
if ( ! is_string( $gravity_notify_to ) || '' === $gravity_notify_to ) {
	$gravity_notify_to = '+989111111111';
}

$gravity_notify_message = getenv( 'GNM_IPPANEL_SIMULATOR_MESSAGE' );
if ( ! is_string( $gravity_notify_message ) || '' === $gravity_notify_message ) {
	$gravity_notify_message = 'GNM deterministic simulator contract';
}

$gravity_notify_reference = '424242';

/**
 * Read persistent simulator counters.
 *
 * @param string $path State file path.
 * @return array<string, int>
 */
function gravity_notify_simulator_read_state( string $path ): array {
	if ( ! is_readable( $path ) ) {
		return array(
			'send_count'   => 0,
			'report_count' => 0,
		);
	}

	$decoded = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $decoded ) ) {
		return array(
			'send_count'   => 0,
			'report_count' => 0,
		);
	}

	return $decoded;
}

/**
 * Persist simulator counters.
 *
 * @param string             $path  State file path.
 * @param array<string, int> $state Simulator state.
 */
function gravity_notify_simulator_write_state( string $path, array $state ): void {
	file_put_contents( $path, json_encode( $state, JSON_UNESCAPED_SLASHES ) . PHP_EOL, LOCK_EX );
}

/**
 * Emit one JSON response and terminate the request.
 *
 * @param int                  $status  HTTP status code.
 * @param array<string, mixed> $payload Response payload.
 */
function gravity_notify_simulator_respond( int $status, array $payload ): never {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is the raw HTTP protocol payload for this local simulator.
	echo json_encode( $payload, JSON_UNESCAPED_SLASHES );
	exit;
}

/**
 * Enforce the deterministic simulator token.
 *
 * @param string $expected Expected authorization token.
 */
function gravity_notify_simulator_require_auth( string $expected ): void {
	$observed = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! is_string( $observed ) || ! hash_equals( $expected, $observed ) ) {
		gravity_notify_simulator_respond(
			401,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}
}

$gravity_notify_method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
$gravity_notify_uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
$gravity_notify_path   = (string) parse_url( $gravity_notify_uri, PHP_URL_PATH );
$gravity_notify_query  = array();
parse_str( (string) parse_url( $gravity_notify_uri, PHP_URL_QUERY ), $gravity_notify_query );

if ( '/_health' === $gravity_notify_path ) {
	if ( 'GET' !== $gravity_notify_method ) {
		gravity_notify_simulator_respond( 405, array( 'status' => 'method_not_allowed' ) );
	}
	gravity_notify_simulator_respond( 200, array( 'status' => 'ready' ) );
}

if ( '/_test/reset' === $gravity_notify_path ) {
	if ( 'POST' !== $gravity_notify_method ) {
		gravity_notify_simulator_respond( 405, array( 'status' => 'method_not_allowed' ) );
	}
	gravity_notify_simulator_require_auth( $gravity_notify_token );
	gravity_notify_simulator_write_state(
		$gravity_notify_state_file,
		array(
			'send_count'   => 0,
			'report_count' => 0,
		)
	);
	gravity_notify_simulator_respond( 200, array( 'status' => 'reset' ) );
}

if ( '/_test/state' === $gravity_notify_path ) {
	if ( 'GET' !== $gravity_notify_method ) {
		gravity_notify_simulator_respond( 405, array( 'status' => 'method_not_allowed' ) );
	}
	gravity_notify_simulator_require_auth( $gravity_notify_token );
	gravity_notify_simulator_respond( 200, gravity_notify_simulator_read_state( $gravity_notify_state_file ) );
}

if ( '/v1/api/send' === $gravity_notify_path ) {
	if ( 'POST' !== $gravity_notify_method ) {
		gravity_notify_simulator_respond(
			405,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}

	gravity_notify_simulator_require_auth( $gravity_notify_token );
	$gravity_notify_content_type = strtolower( trim( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) ) );
	if ( 'application/json' !== strtok( $gravity_notify_content_type, ';' ) ) {
		gravity_notify_simulator_respond(
			415,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}

	try {
		$gravity_notify_payload = json_decode( (string) file_get_contents( 'php://input' ), true, 512, JSON_THROW_ON_ERROR );
	} catch ( JsonException $exception ) {
		unset( $exception );
		gravity_notify_simulator_respond(
			400,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}

	$gravity_notify_keys            = is_array( $gravity_notify_payload ) ? array_keys( $gravity_notify_payload ) : array();
	$gravity_notify_params          = is_array( $gravity_notify_payload ) ? ( $gravity_notify_payload['params'] ?? null ) : null;
	$gravity_notify_param_keys      = is_array( $gravity_notify_params ) ? array_keys( $gravity_notify_params ) : array();
	$gravity_notify_recipients      = is_array( $gravity_notify_params ) ? ( $gravity_notify_params['recipients'] ?? null ) : null;
	$gravity_notify_payload_from    = is_array( $gravity_notify_payload ) ? ( $gravity_notify_payload['from_number'] ?? null ) : null;
	$gravity_notify_payload_message = is_array( $gravity_notify_payload ) ? ( $gravity_notify_payload['message'] ?? null ) : null;
	$gravity_notify_valid_shape     = is_array( $gravity_notify_payload )
		&& array( 'sending_type', 'from_number', 'message', 'params' ) === $gravity_notify_keys
		&& 'webservice' === ( $gravity_notify_payload['sending_type'] ?? null )
		&& $gravity_notify_from === $gravity_notify_payload_from
		&& $gravity_notify_message === $gravity_notify_payload_message
		&& array( 'recipients' ) === $gravity_notify_param_keys
		&& array( $gravity_notify_to ) === $gravity_notify_recipients;
	if ( ! $gravity_notify_valid_shape ) {
		gravity_notify_simulator_respond(
			422,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}

	$gravity_notify_state = gravity_notify_simulator_read_state( $gravity_notify_state_file );
	++$gravity_notify_state['send_count'];
	gravity_notify_simulator_write_state( $gravity_notify_state_file, $gravity_notify_state );
	if ( 1 !== $gravity_notify_state['send_count'] ) {
		gravity_notify_simulator_respond(
			409,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}

	$gravity_notify_scenario = is_string( $gravity_notify_query['scenario'] ?? null ) ? $gravity_notify_query['scenario'] : 'success';
	if ( 'provider_rejection' === $gravity_notify_scenario ) {
		gravity_notify_simulator_respond(
			200,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}
	if ( 'missing_reference' === $gravity_notify_scenario ) {
		gravity_notify_simulator_respond(
			200,
			array(
				'meta' => array( 'status' => true ),
				'data' => array( 'message_outbox_ids' => array() ),
			)
		);
	}
	if ( 'success' !== $gravity_notify_scenario ) {
		gravity_notify_simulator_respond(
			400,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}

	gravity_notify_simulator_respond(
		200,
		array(
			'meta' => array( 'status' => true ),
			'data' => array( 'message_outbox_ids' => array( $gravity_notify_reference ) ),
		)
	);
}

if ( '/v1/api/report/recipients' === $gravity_notify_path ) {
	if ( 'GET' !== $gravity_notify_method ) {
		gravity_notify_simulator_respond(
			405,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}

	gravity_notify_simulator_require_auth( $gravity_notify_token );
	$gravity_notify_bulk_id = (string) ( $gravity_notify_query['bulk_id'] ?? '' );
	if (
		'1' !== (string) ( $gravity_notify_query['page'] ?? '' )
		|| '10' !== (string) ( $gravity_notify_query['per_page'] ?? '' )
		|| $gravity_notify_reference !== $gravity_notify_bulk_id
	) {
		gravity_notify_simulator_respond(
			422,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}

	$gravity_notify_state = gravity_notify_simulator_read_state( $gravity_notify_state_file );
	++$gravity_notify_state['report_count'];
	gravity_notify_simulator_write_state( $gravity_notify_state_file, $gravity_notify_state );

	$gravity_notify_scenario = is_string( $gravity_notify_query['scenario'] ?? null ) ? $gravity_notify_query['scenario'] : 'delivered';
	$gravity_notify_status   = '2';
	if ( 'terminal_non_delivery' === $gravity_notify_scenario ) {
		$gravity_notify_status = '3';
	} elseif ( 'pending' === $gravity_notify_scenario ) {
		$gravity_notify_status = '0';
	} elseif ( 'delivered' !== $gravity_notify_scenario ) {
		gravity_notify_simulator_respond(
			400,
			array(
				'meta' => array( 'status' => false ),
				'data' => null,
			)
		);
	}

	gravity_notify_simulator_respond(
		200,
		array(
			'meta' => array( 'status' => true ),
			'data' => array( array( 'message_status' => $gravity_notify_status ) ),
		)
	);
}

gravity_notify_simulator_respond(
	404,
	array(
		'meta' => array( 'status' => false ),
		'data' => null,
	)
);
