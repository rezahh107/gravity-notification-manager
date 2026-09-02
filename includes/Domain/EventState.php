<?php
/**
 * Enum representing possible states of an SMS event.
 *
 * @package GFSMS\Domain
 */

declare( strict_types = 1 );

namespace GFSMS\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Enum EventState
 *
 * Defines the possible states of an SMS event through its lifecycle.
 */
enum EventState: string {

	/**
	 * Event has been successfully sent.
	 */
	case SENT = 'sent';

	/**
	 * Event has failed permanently (no more retries).
	 */
	case FAILED_PERMANENT = 'failed_permanent';

	/**
	 * Event is currently being processed.
	 */
	case PROCESSING = 'processing';
}
