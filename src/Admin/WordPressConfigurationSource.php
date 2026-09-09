<?php
/**
 * WordPress/Gravity Forms/Gravity Flow read-only configuration adapter.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\GravityForms\NotificationFeedAddOn;
use Throwable;

/**
 * Reads current authoritative Feed and Flow configuration without mutation.
 */
final class WordPressConfigurationSource implements ConfigurationSourceInterface {

	/**
	 * Return current Gravity Forms identities visible to Point Manager.
	 *
	 * @return array<int, array{id:int,title:string}>
	 */
	public function forms(): array {
		if ( ! class_exists( '\\GFAPI' ) || ! method_exists( '\\GFAPI', 'get_forms' ) ) {
			return array();
		}

		try {
			$forms = \GFAPI::get_forms();
		} catch ( Throwable $exception ) {
			unset( $exception );
			return array();
		}

		$result = array();
		foreach ( is_array( $forms ) ? $forms : array() as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$id = self::positive_id( $form['id'] ?? null );
			if ( null === $id ) {
				continue;
			}
			$result[] = array(
				'id'    => $id,
				'title' => is_scalar( $form['title'] ?? null ) ? (string) $form['title'] : 'Form ' . $id,
			);
		}

		return $result;
	}

	/**
	 * Return current greenfield GNM Feeds for one Form.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function feeds( int $form_id ): array {
		if ( $form_id < 1 || ! class_exists( '\\GFFeedAddOn' ) ) {
			return array();
		}

		try {
			$feeds = NotificationFeedAddOn::get_instance()->get_feeds( $form_id );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return array();
		}

		return is_array( $feeds ) ? $feeds : array();
	}

	/**
	 * Read GNM Feed selections and active state from current Gravity Flow Steps.
	 *
	 * @param int             $form_id  Gravity Forms form ID.
	 * @param array<int, int> $feed_ids Current GNM Feed IDs.
	 * @return array<int, array{step_id:int,step_name:string,feed_ids:array<int,int>,active:bool}>|null
	 */
	public function workflow_placements( int $form_id, array $feed_ids ): ?array {
		if ( $form_id < 1 || ! class_exists( '\\Gravity_Flow_API' ) ) {
			return null;
		}

		try {
			$api   = new \Gravity_Flow_API( $form_id );
			$steps = $api->get_steps();
		} catch ( Throwable $exception ) {
			unset( $exception );
			return null;
		}

		$placements = array();
		foreach ( is_array( $steps ) ? $steps : array() as $step ) {
			if ( ! is_object( $step ) || ! method_exists( $step, 'get_type' ) || 'gravity_notification_manager' !== $step->get_type() ) {
				continue;
			}
			if ( ! method_exists( $step, 'get_setting' ) ) {
				continue;
			}

			$selected = array();
			foreach ( $feed_ids as $feed_id ) {
				if ( $feed_id > 0 && $step->get_setting( 'feed_' . $feed_id ) ) {
					$selected[] = $feed_id;
				}
			}
			if ( empty( $selected ) ) {
				continue;
			}

			$step_id = method_exists( $step, 'get_id' ) ? self::positive_id( $step->get_id() ) : null;
			$name    = method_exists( $step, 'get_name' ) ? $step->get_name() : '';
			$active  = method_exists( $step, 'is_active' ) && (bool) $step->is_active();
			$placements[] = array(
				'step_id'   => null === $step_id ? 0 : $step_id,
				'step_name' => is_scalar( $name ) && '' !== trim( (string) $name ) ? trim( (string) $name ) : 'GNM Feed Step',
				'feed_ids'  => $selected,
				'active'    => $active,
			);
		}

		return $placements;
	}

	/**
	 * Parse a positive integer identity without malformed coercion.
	 *
	 * @param mixed $value Raw identity value.
	 * @return int|null
	 */
	private static function positive_id( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return null;
		}
		$value = (int) $value;
		return $value > 0 ? $value : null;
	}
}
