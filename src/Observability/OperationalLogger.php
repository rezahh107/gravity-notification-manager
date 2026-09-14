<?php
/**
 * Greenfield operational observability writer.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Observability;

use GravityNotify\Delivery\AttemptResult;
use Throwable;

/**
 * Converts normalized delivery outcomes into safe best-effort observational events.
 */
final class OperationalLogger {

	private OperationalEventStoreInterface $store;

	public function __construct( OperationalEventStoreInterface $store ) {
		$this->store = $store;
	}

	/** Production writer using the WordPress-native bounded event table. */
	public static function production(): self {
		return new self( WordPressOperationalEventStore::production() );
	}

	/**
	 * Record a sequence of attempts sharing one trace.
	 *
	 * Logging is deliberately best-effort and never throws into delivery.
	 *
	 * @param OperationalContext        $context          Logical operation context.
	 * @param array<int, AttemptResult> $attempts         Normalized attempts.
	 * @param array<int, string>        $raw_destinations Raw resolved destinations; masked before persistence.
	 * @param string|null               $sender           Validated SMS sender when applicable.
	 * @param int                       $start_index      First attempt index within the trace.
	 * @return int Next attempt index after the supplied attempts.
	 */
	public function record_attempts(
		OperationalContext $context,
		array $attempts,
		array $raw_destinations,
		?string $sender = null,
		int $start_index = 1
	): int {
		$index = max( 1, $start_index );
		foreach ( $attempts as $attempt ) {
			if ( $attempt instanceof AttemptResult ) {
				$this->record_attempt( $context, $attempt, $raw_destinations, $sender, $index );
				++$index;
			}
		}
		return $index;
	}

	/**
	 * Record one normalized attempt without affecting caller truth on persistence failure.
	 *
	 * @param array<int, string> $raw_destinations Resolved destinations.
	 */
	public function record_attempt(
		OperationalContext $context,
		AttemptResult $attempt,
		array $raw_destinations,
		?string $sender = null,
		int $attempt_index = 1
	): bool {
		try {
			$event = new OperationalEvent(
				array_merge(
					$this->runtime_snapshot(),
					array(
						'created_at_utc'      => gmdate( 'Y-m-d H:i:s' ),
						'trace_id'            => $context->trace_id(),
						'attempt_index'       => max( 1, $attempt_index ),
						'channel'             => $attempt->channel(),
						'execution_type'      => $context->execution_type(),
						'status'              => $attempt->status(),
						'provider'            => $attempt->provider_id(),
						'form_id'             => $context->form_id(),
						'feed_id'             => $context->feed_id(),
						'entry_id'            => $context->entry_id(),
						'feed_name'           => $context->feed_name(),
						'sender'              => $sender,
						'destination'         => self::masked_destinations( $raw_destinations ),
						'provider_references' => $attempt->provider_references(),
						'diagnostic'          => $attempt->diagnostic(),
						'http_status'         => $attempt->http_status(),
					)
				)
			);
			return $this->store->append( $event );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}

	/** @return array<string, string|null> */
	private function runtime_snapshot(): array {
		$plugin = defined( 'GFSMS_PLUGIN_VERSION' ) ? (string) GFSMS_PLUGIN_VERSION : null;
		$wp     = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : null;
		return array(
			'plugin_version' => $plugin,
			'wp_version'     => $wp,
			'php_version'    => PHP_VERSION,
		);
	}

	/**
	 * Mask personal destinations before they cross the persistence boundary.
	 *
	 * @param array<int, string> $destinations Raw resolved targets.
	 */
	public static function masked_destinations( array $destinations ): ?string {
		$masks = array();
		foreach ( $destinations as $destination ) {
			if ( is_string( $destination ) && '' !== trim( $destination ) ) {
				$masks[] = self::mask_destination( trim( $destination ) );
			}
			if ( 3 <= count( $masks ) ) {
				break;
			}
		}
		if ( array() === $masks ) {
			return null;
		}
		$remaining = max( 0, count( $destinations ) - count( $masks ) );
		return implode( ', ', $masks ) . ( 0 < $remaining ? ' (+' . $remaining . ')' : '' );
	}

	private static function mask_destination( string $value ): string {
		$length = strlen( $value );
		if ( 6 >= $length ) {
			return substr( $value, 0, 1 ) . str_repeat( '*', max( 1, $length - 2 ) ) . substr( $value, -1 );
		}

		$prefix = str_starts_with( $value, '+' ) || str_starts_with( $value, '@' ) ? 4 : 3;
		$suffix = min( 4, max( 2, intdiv( $length, 4 ) ) );
		$hidden = max( 1, $length - $prefix - $suffix );
		return substr( $value, 0, $prefix ) . str_repeat( '*', $hidden ) . substr( $value, -$suffix );
	}
}
