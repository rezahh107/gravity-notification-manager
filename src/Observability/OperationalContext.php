<?php
/**
 * Correlation context for one logical outbound operation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Observability;

use InvalidArgumentException;

/**
 * Carries safe source facts shared by all attempts in one logical operation.
 */
final class OperationalContext {

	public const EXECUTION_NORMAL = 'normal';
	public const EXECUTION_TEST   = 'test';
	public const EXECUTION_RETRY  = 'retry';

	private string $trace_id;
	private string $execution_type;
	private ?int $form_id;
	private ?int $feed_id;
	private ?int $entry_id;
	private string $feed_name;

	/**
	 * @param string   $execution_type Execution type.
	 * @param int|null $form_id        Gravity Forms form ID.
	 * @param int|null $feed_id        GNM Feed ID.
	 * @param int|null $entry_id       Gravity Forms entry ID.
	 * @param string   $feed_name      Feed label.
	 * @param string   $trace_id       Optional externally supplied UUID for tests.
	 */
	public function __construct(
		string $execution_type,
		?int $form_id = null,
		?int $feed_id = null,
		?int $entry_id = null,
		string $feed_name = '',
		string $trace_id = ''
	) {
		if ( ! in_array( $execution_type, self::execution_types(), true ) ) {
			throw new InvalidArgumentException( 'Unsupported operational execution type.' );
		}

		$this->execution_type = $execution_type;
		$this->form_id        = self::positive_or_null( $form_id );
		$this->feed_id        = self::positive_or_null( $feed_id );
		$this->entry_id       = self::positive_or_null( $entry_id );
		$this->feed_name      = self::bounded_text( $feed_name, 191 );
		$this->trace_id       = '' === $trace_id ? self::new_trace_id() : self::validated_trace_id( $trace_id );
	}

	/** @return array<int, string> */
	public static function execution_types(): array {
		return array( self::EXECUTION_NORMAL, self::EXECUTION_TEST, self::EXECUTION_RETRY );
	}

	public function trace_id(): string {
		return $this->trace_id;
	}

	public function execution_type(): string {
		return $this->execution_type;
	}

	public function form_id(): ?int {
		return $this->form_id;
	}

	public function feed_id(): ?int {
		return $this->feed_id;
	}

	public function entry_id(): ?int {
		return $this->entry_id;
	}

	public function feed_name(): string {
		return $this->feed_name;
	}

	private static function positive_or_null( ?int $value ): ?int {
		return null !== $value && 0 < $value ? $value : null;
	}

	private static function bounded_text( string $value, int $limit ): string {
		$value = trim( preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
	}

	private static function validated_trace_id( string $trace_id ): string {
		$trace_id = strtolower( trim( $trace_id ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $trace_id ) ) {
			throw new InvalidArgumentException( 'Operational trace ID must be a UUIDv4.' );
		}
		return $trace_id;
	}

	private static function new_trace_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return self::validated_trace_id( (string) wp_generate_uuid4() );
		}

		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}
