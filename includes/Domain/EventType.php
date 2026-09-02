<?php
/**
 * Enum representing possible types of SMS events.
 *
 * @package GFSMS\Domain
 */

declare( strict_types = 1 );

namespace GFSMS\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Enum EventType
 *
 * Defines the type of event that triggers an SMS.
 */
enum EventType: string {

	/**
	 * Event triggered by a workflow step.
	 */
	case STEP = 'step';

	/**
	 * Event triggered by workflow completion.
	 */
	case WORKFLOW = 'workflow';
}
