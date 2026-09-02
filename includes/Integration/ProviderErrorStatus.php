<?php
/**
 * Enum representing possible error statuses from SMS providers.
 *
 * @package GFSMS\Integration
 */

declare( strict_types = 1 );

namespace GFSMS\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Enum ProviderErrorStatus
 *
 * Classifies API response errors for retry logic.
 */
enum ProviderErrorStatus: string {

	/**
	 * Request succeeded.
	 */
	case SUCCESS = 'success';

	/**
	 * Error is temporary and may succeed on retry.
	 */
	case RETRYABLE = 'retryable';

	/**
	 * Error is permanent; do not retry.
	 */
	case PERMANENT = 'permanent';
}
