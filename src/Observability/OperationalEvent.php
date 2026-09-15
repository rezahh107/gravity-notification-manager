<?php
/**
 * Safe observational record for one outbound attempt.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Observability;

use GravityNotify\Delivery\AttemptStatus;
use InvalidArgumentException;

/**
 * Contains only bounded facts safe for operational persistence and diagnostics.
 */
final class OperationalEvent {

		/**
		 * Stored value.
		 *
		 * @var array<string,
		 */
	private array $data;

		/**
		 * Construct the object.
		 *
		 * @param array $data Value.
		 * @throws \InvalidArgumentException When supplied data is invalid.
		 */
	public function __construct( array $data ) {
		$status = (string) ( $data['status'] ?? '' );
		if ( ! AttemptStatus::is_valid( $status ) ) {
			throw new InvalidArgumentException( 'Unsupported operational event status.' );
		}

		$channel = (string) ( $data['channel'] ?? '' );
		if ( ! in_array( $channel, array( 'sms', 'bale' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported operational event channel.' );
		}

		$execution_type = (string) ( $data['execution_type'] ?? '' );
		if ( ! in_array( $execution_type, OperationalContext::execution_types(), true ) ) {
			throw new InvalidArgumentException( 'Unsupported operational event execution type.' );
		}

		$this->data = array(
			'id'                  => self::positive_or_null( $data['id'] ?? null ),
			'created_at_utc'      => self::timestamp( (string) ( $data['created_at_utc'] ?? '' ) ),
			'trace_id'            => self::trace_id( (string) ( $data['trace_id'] ?? '' ) ),
			'attempt_index'       => max( 1, (int) ( $data['attempt_index'] ?? 1 ) ),
			'channel'             => $channel,
			'execution_type'      => $execution_type,
			'status'              => $status,
			'provider'            => self::identifier_or_null( $data['provider'] ?? null, 64 ),
			'form_id'             => self::positive_or_null( $data['form_id'] ?? null ),
			'feed_id'             => self::positive_or_null( $data['feed_id'] ?? null ),
			'entry_id'            => self::positive_or_null( $data['entry_id'] ?? null ),
			'feed_name'           => self::text_or_null( $data['feed_name'] ?? null, 191 ),
			'sender'              => self::sender_or_null( $data['sender'] ?? null ),
			'destination'         => self::text_or_null( $data['destination'] ?? null, 191 ),
			'provider_references' => self::references( $data['provider_references'] ?? array() ),
			'diagnostic'          => self::identifier( (string) ( $data['diagnostic'] ?? 'unknown' ), 128, 'unknown' ),
			'http_status'         => self::http_status_or_null( $data['http_status'] ?? null ),
			'plugin_version'      => self::version_or_null( $data['plugin_version'] ?? null ),
			'wp_version'          => self::version_or_null( $data['wp_version'] ?? null ),
			'php_version'         => self::version_or_null( $data['php_version'] ?? null ),
		);
	}

		/**
		 * To array.
		 *
		 * @return array Return value.
		 */
	public function to_array(): array {
		return $this->data;
	}

		/**
		 * Get.
		 *
		 * @param string $key Value.
		 */
	public function get( string $key ) {
		return $this->data[ $key ] ?? null;
	}

		/**
		 * From storage row.
		 *
		 * @param array $row Value.
		 * @return self Return value.
		 */
	public static function from_storage_row( array $row ): self {
		$references = array();
		if ( isset( $row['provider_references'] ) && is_string( $row['provider_references'] ) && '' !== $row['provider_references'] ) {
			$decoded    = json_decode( $row['provider_references'], true );
			$references = is_array( $decoded ) ? $decoded : array();
		}
		$row['provider_references'] = $references;
		return new self( $row );
	}

	/**
	 * Positive or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null Return value.
	 */
	private static function positive_or_null( $value ): ?int {
		if ( is_int( $value ) ) {
			return 0 < $value ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
			return 0 < $value ? $value : null;
		}
		return null;
	}

	/**
	 * Timestamp.
	 *
	 * @param string $value Value.
	 * @return string Return value.
	 * @throws \InvalidArgumentException When the timestamp format is invalid.
	 */
	private static function timestamp( string $value ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Operational event timestamp is invalid.' );
		}
		return $value;
	}

	/**
	 * Trace id.
	 *
	 * @param string $value Value.
	 * @return string Return value.
	 * @throws \InvalidArgumentException When the trace identifier is not UUIDv4.
	 */
	private static function trace_id( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Operational event trace ID must be a UUIDv4.' );
		}
		return $value;
	}

	/**
	 * Identifier or null.
	 *
	 * @param mixed $value Value.
	 * @param int   $limit Value.
	 * @return string|null Return value.
	 */
	private static function identifier_or_null( $value, int $limit ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( self::looks_sensitive( $value ) ) {
			return null;
		}
		return 1 === preg_match( '/^[A-Za-z0-9._:-]{1,' . $limit . '}$/D', $value ) ? $value : null;
	}

	/**
	 * Identifier.
	 *
	 * @param string $value Value.
	 * @param int    $limit Value.
	 * @param string $fallback Value.
	 * @return string Return value.
	 */
	private static function identifier( string $value, int $limit, string $fallback ): string {
		$value = trim( $value );
		if ( self::looks_sensitive( $value ) ) {
			return $fallback;
		}
		return 1 === preg_match( '/^[A-Za-z0-9._:-]{1,' . $limit . '}$/D', $value ) ? $value : $fallback;
	}

	/**
	 * Text or null.
	 *
	 * @param mixed $value Value.
	 * @param int   $limit Value.
	 * @return string|null Return value.
	 */
	private static function text_or_null( $value, int $limit ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$value = trim( preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value ) ?? '' );
		$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
		return '' === $value ? null : $value;
	}

	/**
	 * Sender or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null Return value.
	 */
	private static function sender_or_null( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		return 1 === preg_match( '/^(?:\+[1-9][0-9]{1,14}|[1-9][0-9]{2,31})$/D', $value ) ? $value : null;
	}

		/**
		 * References.
		 *
		 * @param mixed $values Value.
		 * @return array Return value.
		 */
	private static function references( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$safe = array();
		foreach ( $values as $value ) {
			if ( is_int( $value ) ) {
				$value = (string) $value;
			}
			if ( is_string( $value ) && ! self::looks_sensitive( $value ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $value ) ) {
				$safe[] = $value;
			}
			if ( 3 <= count( $safe ) ) {
				break;
			}
		}
		return array_values( array_unique( $safe ) );
	}

	/**
	 * Looks sensitive.
	 *
	 * @param string $value Value.
	 * @return bool Return value.
	 */
	private static function looks_sensitive( string $value ): bool {
		return 1 === preg_match( '/(?:api[_-]?key|authorization|bearer|secret|token)/i', $value );
	}

	/**
	 * Http status or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null Return value.
	 */
	private static function http_status_or_null( $value ): ?int {
		$status = is_int( $value ) ? $value : ( is_string( $value ) && ctype_digit( $value ) ? (int) $value : 0 );
		return 100 <= $status && 599 >= $status ? $status : null;
	}

	/**
	 * Version or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null Return value.
	 */
	private static function version_or_null( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		return 1 === preg_match( '/^[A-Za-z0-9.+_-]{1,32}$/D', $value ) ? $value : null;
	}
}
