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

	/**
	 * Stored value.
	 *
	 * @var string
	 */
	private string $trace_id;
	/**
	 * Stored value.
	 *
	 * @var string
	 */
	private string $execution_type;
	/**
	 * Stored value.
	 *
	 * @var int|null
	 */
	private ?int $form_id;
	/**
	 * Stored value.
	 *
	 * @var int|null
	 */
	private ?int $feed_id;
	/**
	 * Stored value.
	 *
	 * @var int|null
	 */
	private ?int $entry_id;
	/**
	 * Stored value.
	 *
	 * @var string
	 */
	private string $feed_name;

		/**
		 * Construct the object.
		 *
		 * @param string   $execution_type Value.
		 * @param int|null $form_id Value.
		 * @param int|null $feed_id Value.
		 * @param int|null $entry_id Value.
		 * @param string   $feed_name Value.
		 * @param string   $trace_id Value.
		 * @throws \InvalidArgumentException When supplied data is invalid.
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

		/**
		 * Execution types.
		 *
		 * @return array Return value.
		 */
	public static function execution_types(): array {
		return array( self::EXECUTION_NORMAL, self::EXECUTION_TEST, self::EXECUTION_RETRY );
	}

	/**
	 * Trace id.
	 *
	 * @return string Return value.
	 */
	public function trace_id(): string {
		return $this->trace_id;
	}

	/**
	 * Execution type.
	 *
	 * @return string Return value.
	 */
	public function execution_type(): string {
		return $this->execution_type;
	}

	/**
	 * Form id.
	 *
	 * @return int|null Return value.
	 */
	public function form_id(): ?int {
		return $this->form_id;
	}

	/**
	 * Feed id.
	 *
	 * @return int|null Return value.
	 */
	public function feed_id(): ?int {
		return $this->feed_id;
	}

	/**
	 * Entry id.
	 *
	 * @return int|null Return value.
	 */
	public function entry_id(): ?int {
		return $this->entry_id;
	}

	/**
	 * Feed name.
	 *
	 * @return string Return value.
	 */
	public function feed_name(): string {
		return $this->feed_name;
	}

	/**
	 * Positive or null.
	 *
	 * @param int|null $value Value.
	 * @return int|null Return value.
	 */
	private static function positive_or_null( ?int $value ): ?int {
		return null !== $value && 0 < $value ? $value : null;
	}

	/**
	 * Bounded text.
	 *
	 * @param string $value Value.
	 * @param int    $limit Value.
	 * @return string Return value.
	 */
	private static function bounded_text( string $value, int $limit ): string {
		$value = trim( preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
	}

	/**
	 * Validated trace id.
	 *
	 * @param string $trace_id Value.
	 * @return string Return value.
	 * @throws \InvalidArgumentException When the trace identifier is not UUIDv4.
	 */
	private static function validated_trace_id( string $trace_id ): string {
		$trace_id = strtolower( trim( $trace_id ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $trace_id ) ) {
			throw new InvalidArgumentException( 'Operational trace ID must be a UUIDv4.' );
		}
		return $trace_id;
	}

	/**
	 * New trace id.
	 *
	 * @return string Return value.
	 * @throws \Exception When secure random bytes cannot be generated.
	 */
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
