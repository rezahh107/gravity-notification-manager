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

	/**
	 * Stored value.
	 *
	 * @var OperationalEventStoreInterface
	 */
	private OperationalEventStoreInterface $store;

	/**
	 * Construct the object.
	 *
	 * @param OperationalEventStoreInterface $store Value.
	 * @throws \InvalidArgumentException When supplied data is invalid.
	 */
	public function __construct( OperationalEventStoreInterface $store ) {
		$this->store = $store;
	}

		/**
		 * Production.
		 *
		 * @return self Return value.
		 */
	public static function production(): self {
		return new self( WordPressOperationalEventStore::production() );
	}

		/**
		 * Record attempts.
		 *
		 * @param OperationalContext $context Value.
		 * @param array              $attempts Value.
		 * @param array              $raw_destinations Value.
		 * @param string|null        $sender Value.
		 * @param int                $start_index Value.
		 * @return int Return value.
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
		 * Record attempt.
		 *
		 * @param OperationalContext $context Value.
		 * @param AttemptResult      $attempt Value.
		 * @param array              $raw_destinations Value.
		 * @param string|null        $sender Value.
		 * @param int                $attempt_index Value.
		 * @return bool Return value.
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

		/**
		 * Runtime snapshot.
		 *
		 * @return array Return value.
		 */
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
		 * Masked destinations.
		 *
		 * @param array $destinations Value.
		 * @return string|null Return value.
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

	/**
	 * Mask destination.
	 *
	 * @param string $value Value.
	 * @return string Return value.
	 */
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
