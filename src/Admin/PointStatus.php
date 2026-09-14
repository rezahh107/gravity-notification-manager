<?php
/**
 * Notification Point status vocabulary.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Deterministic operator-facing configuration states.
 */
final class PointStatus {
	public const CONFIGURED = 'CONFIGURED';
	public const NEEDS_SETUP = 'NEEDS_SETUP';
	public const DISABLED = 'DISABLED';
	public const NOT_APPLICABLE = 'NOT_APPLICABLE';
}
