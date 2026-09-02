<?php
declare(strict_types=1);

namespace GFSMS\Services;

use GFSMS\Domain\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves recipient phone numbers from step and workflow configurations.
 *
 * Handles legacy and rule‑based recipient sources, including assignees,
 * form fields, fixed numbers, roles, and the form submitter.
 *
 * @since 3.0.0
 */
final class RecipientResolver {

	/**
	 * @since 3.0.0
	 */
	public function __construct(
		private readonly PhoneNumberNormalizer $normalizer
	) {}

	/**
	 * Resolve recipients for a workflow step, preferring rules over legacy settings.
	 *
	 * @since 3.0.0
	 *
	 * @param Settings $settings Plugin settings instance.
	 * @param object   $step     The workflow step object (e.g., Gravity Flow step).
	 * @param array    $entry    Gravity Forms entry array.
	 * @param int      $form_id  The form ID.
	 * @return string[] Unique, normalised phone numbers.
	 */
	public function resolve_step_recipients( Settings $settings, object $step, array $entry, int $form_id ): array {
		$rules = $settings->get( 'recipient_rules', array() );
		$rule  = $this->find_rule( $rules, $form_id );

		if ( null !== $rule ) {
			return $this->resolve_from_rule( $rule, $step, $entry );
		}

		return $this->resolve_legacy( $settings, $step, $entry );
	}

	/**
	 * Resolve recipients for a workflow‑level notification (no step context).
	 *
	 * @since 3.0.0
	 *
	 * @param Settings $settings Plugin settings instance.
	 * @param array    $entry    Gravity Forms entry array.
	 * @return string[] Unique, normalised phone numbers.
	 */
	public function resolve_workflow_recipients( Settings $settings, array $entry ): array {
		$numbers = array();
		$source  = (string) $settings->get( 'workflow_recipient_source', 'fixed' );

		$allowed_sources = array( 'fixed', 'form_field', 'role', 'submitter' );
		if ( false === in_array( $source, $allowed_sources, true ) ) {
			$source = 'fixed';
		}

		if ( 'fixed' === $source ) {
			$numbers = $this->extract_numbers( (string) $settings->get( 'fixed_numbers', '' ) );
		} elseif ( 'form_field' === $source ) {
			$raw = rgar( $entry, (string) $settings->get( 'mobile_field_id', '' ) );
			$m   = $this->normalizer->normalize( (string) $raw );
			if ( null !== $m ) {
				$numbers[] = $m;
			}
		} elseif ( 'role' === $source ) {
			foreach ( (array) $settings->get( 'workflow_roles', array() ) as $role ) {
				$users = get_users(
					array(
						'role'   => (string) $role,
						'fields' => 'ID',
						'number' => 500,
					)
				);
				foreach ( $users as $user_id ) {
					$m = $this->get_user_mobile( (int) $user_id );
					if ( '' !== $m ) {
						$numbers[] = $m;
					}
				}
			}
		} elseif ( 'submitter' === $source ) {
			$user_id = (int) ( $entry['created_by'] ?? 0 );
			if ( $user_id > 0 ) {
				$m = $this->get_user_mobile( $user_id );
				if ( '' !== $m ) {
					$numbers[] = $m;
				}
			}
		}

		return array_values( array_unique( array_filter( $numbers ) ) );
	}

	/**
	 * Find a recipient rule that matches the given form ID.
	 *
	 * @since 3.0.0
	 *
	 * @param array $rules   Recipient rules array.
	 * @param int   $form_id Form ID to match.
	 * @return array|null    The matched rule, or null.
	 */
	private function find_rule( array $rules, int $form_id ): ?array {
		foreach ( $rules as $rule ) {
			if ( (int) ( $rule['form_id'] ?? 0 ) === $form_id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Resolve recipients from a specific rule definition.
	 *
	 * @since 3.0.0
	 *
	 * @param array  $rule  The matched rule array.
	 * @param object $step  The current step object.
	 * @param array  $entry The Gravity Forms entry.
	 * @return string[] Normalised phone numbers.
	 */
	private function resolve_from_rule( array $rule, object $step, array $entry ): array {
		$source  = (string) ( $rule['source'] ?? 'assignee' );
		$numbers = array();

		$allowed_sources = array( 'assignee', 'form_field', 'fixed', 'submitter' );
		if ( false === in_array( $source, $allowed_sources, true ) ) {
			$source = 'assignee';
		}

		if ( 'assignee' === $source ) {
			foreach ( $this->extract_assignees( $step ) as $a ) {
				$numbers = array_merge( $numbers, $this->resolve_assignee_numbers( $a ) );
			}
		} elseif ( 'form_field' === $source && ! empty( $rule['mobile_field_id'] ) ) {
			$raw = rgar( $entry, $rule['mobile_field_id'] );
			$m   = $this->normalizer->normalize( (string) $raw );
			if ( null !== $m ) {
				$numbers[] = $m;
			}
		} elseif ( 'fixed' === $source && ! empty( $rule['fixed_numbers'] ) ) {
			$numbers = $this->extract_numbers( (string) $rule['fixed_numbers'] );
		} elseif ( 'submitter' === $source ) {
			$user_id = (int) ( $entry['created_by'] ?? 0 );
			if ( $user_id > 0 ) {
				$m = $this->get_user_mobile( $user_id );
				if ( '' !== $m ) {
					$numbers[] = $m;
				}
			}
		}

		return array_values( array_unique( array_filter( $numbers ) ) );
	}

	/**
	 * Fallback: resolve recipients using legacy (non‑rule) settings.
	 *
	 * @since 3.0.0
	 *
	 * @param Settings $settings Plugin settings instance.
	 * @param object   $step     The step object.
	 * @param array    $entry    The entry array.
	 * @return string[] Normalised phone numbers.
	 */
	private function resolve_legacy( Settings $settings, object $step, array $entry ): array {
		$numbers = array();
		$source  = (string) $settings->get( 'recipient_source', 'assignee' );

		$allowed_sources = array( 'assignee', 'form_field', 'fixed', 'submitter' );
		if ( false === in_array( $source, $allowed_sources, true ) ) {
			$source = 'assignee';
		}

		if ( 'assignee' === $source ) {
			foreach ( $this->extract_assignees( $step ) as $a ) {
				$numbers = array_merge( $numbers, $this->resolve_assignee_numbers( $a ) );
			}
		} elseif ( 'form_field' === $source && $settings->get( 'mobile_field_id' ) ) {
			$raw = rgar( $entry, (string) $settings->get( 'mobile_field_id', '' ) );
			$m   = $this->normalizer->normalize( (string) $raw );
			if ( null !== $m ) {
				$numbers[] = $m;
			}
		} elseif ( 'fixed' === $source ) {
			$numbers = $this->extract_numbers( (string) $settings->get( 'fixed_numbers', '' ) );
		} elseif ( 'submitter' === $source ) {
			$user_id = (int) ( $entry['created_by'] ?? 0 );
			if ( $user_id > 0 ) {
				$m = $this->get_user_mobile( $user_id );
				if ( '' !== $m ) {
					$numbers[] = $m;
				}
			}
		}

		return array_values( array_unique( array_filter( $numbers ) ) );
	}

	/**
	 * Extract and normalise numbers from a delimited string.
	 *
	 * @since 3.0.0
	 *
	 * @param string $raw Comma / whitespace separated numbers.
	 * @return string[] Normalised phone numbers.
	 */
	private function extract_numbers( string $raw ): array {
		$numbers = array();
		$parts   = preg_split( '/[,\s]+/', $raw ) ?: array();
		foreach ( $parts as $p ) {
			$m = $this->normalizer->normalize( trim( (string) $p ) );
			if ( null !== $m ) {
				$numbers[] = $m;
			}
		}
		return $numbers;
	}

	/**
	 * Extract assignee objects from a step.
	 *
	 * @since 3.0.0
	 *
	 * @param object $step The step object.
	 * @return array Assignee objects (or empty array).
	 */
	private function extract_assignees( object $step ): array {
		if ( method_exists( $step, 'get_assignees' ) ) {
			$assignees = $step->get_assignees();
			return is_array( $assignees ) ? $assignees : array();
		}
		return array();
	}

	/**
	 * Resolve mobile numbers from a single assignee (user, email, role, etc.).
	 *
	 * @since 3.0.0
	 *
	 * @param mixed $assignee An assignee object, user ID, email, or role slug.
	 * @return string[] Normalised phone numbers.
	 */
	private function resolve_assignee_numbers( mixed $assignee ): array {
		$numbers = array();
		$type    = null;
		$id      = null;

		if ( is_object( $assignee ) ) {
			$type = method_exists( $assignee, 'get_type' ) ? (string) $assignee->get_type() : null;
			$id   = method_exists( $assignee, 'get_id' ) ? $assignee->get_id() : null;
		} elseif ( is_numeric( $assignee ) ) {
			$type = 'user_id';
			$id   = (int) $assignee;
		} elseif ( is_string( $assignee ) ) {
			$type = is_email( $assignee ) ? 'email' : 'role';
			$id   = $assignee;
		}

		if ( 'user_id' === $type && $id ) {
			$m = $this->get_user_mobile( (int) $id );
			if ( '' !== $m ) {
				$numbers[] = $m;
			}
		} elseif ( 'email' === $type && $id ) {
			$user = get_user_by( 'email', (string) $id );
			if ( $user ) {
				$m = $this->get_user_mobile( (int) $user->ID );
				if ( '' !== $m ) {
					$numbers[] = $m;
				}
			}
		} elseif ( 'role' === $type && $id ) {
			$users = get_users(
				array(
					'role'   => (string) $id,
					'fields' => 'ID',
					'number' => 500,
				)
			);
			foreach ( $users as $uid ) {
				$m = $this->get_user_mobile( (int) $uid );
				if ( '' !== $m ) {
					$numbers[] = $m;
				}
			}
		}

		return $numbers;
	}

	/**
	 * Retrieve a user’s mobile number, caching the result.
	 *
	 * @since 3.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Normalised mobile number (empty string if not found).
	 */
	private function get_user_mobile( int $user_id ): string {
		$cache_key = "gfsms_user_mobile_{$user_id}";
		$cached    = wp_cache_get( $cache_key, 'gfsms' );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		$keys = apply_filters(
			'gfsms_user_mobile_keys',
			array(
				'billing_phone',
				'mobile',
				'plato_user_mobile',
			)
		);

		foreach ( $keys as $key ) {
			$value = (string) get_user_meta( $user_id, (string) $key, true );
			$m     = $this->normalizer->normalize( $value );
			if ( null !== $m ) {
				wp_cache_set( $cache_key, $m, 'gfsms', HOUR_IN_SECONDS );
				return $m;
			}
		}

		wp_cache_set( $cache_key, '', 'gfsms', HOUR_IN_SECONDS );
		return '';
	}
}