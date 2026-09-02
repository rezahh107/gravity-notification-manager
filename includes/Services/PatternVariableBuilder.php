<?php
declare(strict_types=1);

namespace GFSMS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Builds pattern variable replacements for SMS templates.
 *
 * Takes a map of variable names to raw values and processes them
 * through step/entry context, merge tags, and sanitization.
 *
 * @since 3.0.0
 */
final class PatternVariableBuilder {

	/**
	 * @since 3.0.0
	 */
	public function __construct(
		private readonly MessageBuilder $message_builder
	) {}

	/**
	 * Build an associative array of sanitized pattern variables.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,string> $variable_map Key-value pairs to process.
	 * @param array                $form         Gravity Forms form array.
	 * @param array                $entry        Gravity Forms entry array.
	 * @param object|null          $step         Optional workflow step for context.
	 * @return array<string,string> Sanitized key-value replacements.
	 */
	public function build_pattern_variables( array $variable_map, array $form, array $entry, ?object $step = null ): array {
		$vars = array();

		foreach ( $variable_map as $key => $value ) {
			$replaced = (string) $value;

			if ( null !== $step ) {
				$replaced = strtr(
					$replaced,
					array(
						'{workflow_step_name}' => method_exists( $step, 'get_name' ) ? (string) $step->get_name() : '',
						'{assignee_name}'      => '',
						'{approval_comment}'   => $this->message_builder->build_step_message( '{approval_comment}', $form, $entry, $step ),
					)
				);
			}

			$replaced = strtr(
				$replaced,
				array(
					'{entry_id}'     => (string) ( $entry['id'] ?? '' ),
					'{form_id}'      => (string) ( $form['id'] ?? '' ),
					'{final_status}' => '',
				)
			);

			if ( class_exists( '\GFCommon' ) ) {
				try {
					$replaced = \GFCommon::replace_variables( $replaced, $form, $entry, false, false, false );
				} catch ( \Throwable $e ) {
					// Silently ignore merge failures.
				}
			}

			$vars[ $key ] = trim( wp_strip_all_tags( $replaced ) );
		}

		return $vars;
	}
}
