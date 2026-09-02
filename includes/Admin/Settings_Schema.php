<?php
/**
 * Schema definition for plugin settings.
 *
 * IMPORTANT: All field definitions are static configuration.
 * The renderer is responsible for escaping output with
 * esc_attr(), esc_html(), esc_url(), etc.
 *
 * @package GFSMS\Admin
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings_Schema
 *
 * Defines settings structure, defaults, tabs, fields, and sanitization.
 *
 * @since 3.0.0
 */
final class Settings_Schema {

	/**
	 * Tab slug for general settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_GENERAL = 'general';

	/**
	 * Tab slug for recipients settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_RECIPIENTS = 'recipients';

	/**
	 * Tab slug for templates settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_TEMPLATES = 'templates';

	/**
	 * Tab slug for patterns settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_PATTERNS = 'patterns';

	/**
	 * Tab slug for IPPanel settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_IPPANEL = 'ippanel';

	/**
	 * Tab slug for queue settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_QUEUE = 'queue';

	/**
	 * Tab slug for Gravity Forms settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_GRAVITYFORMS = 'gravityforms';

	/**
	 * Tab slug for advanced settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_ADVANCED = 'advanced';

	/**
	 * Tab slug for logging settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_LOGGING = 'logging';

	/**
	 * Tab slug for webhook settings.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_WEBHOOK = 'webhook';

	/**
	 * Tab slug for help tab.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public const TAB_HELP = 'help';

	/**
	 * Minimum queue delay in seconds.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	private const MIN_QUEUE_DELAY = 0;

	/**
	 * Maximum queue delay in seconds (24 hours).
	 *
	 * @since 3.0.0
	 * @var int
	 */
	private const MAX_QUEUE_DELAY = 86400;

	/**
	 * Minimum log retention days.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	private const MIN_LOG_RETENTION = 1;

	/**
	 * Maximum log retention days (10 years).
	 *
	 * @since 3.0.0
	 * @var int
	 */
	private const MAX_LOG_RETENTION = 3650;

	/**
	 * Returns a descriptive help text for each tab.
	 *
	 * @since 3.0.0
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_description( string $tab ): string {
		return match ( $tab ) {
			self::TAB_GENERAL      => __( 'Configure when SMS notifications should be sent for workflow steps and workflow completion.', 'gfsms' ),
			self::TAB_RECIPIENTS   => __( 'Determine who receives SMS notifications. You can define different recipients per form using the rule-based system below.', 'gfsms' ),
			self::TAB_TEMPLATES    => __( 'Customise the SMS text. Use available merge tags to personalise messages. Choose between Webservice (simple text) or Pattern (predefined template) sending method.', 'gfsms' ),
			self::TAB_PATTERNS     => __( 'Map Gravity Flow events to IPPanel pattern codes. Each pattern requires a code and its variables in JSON format.', 'gfsms' ),
			self::TAB_IPPANEL      => __( 'Enter your IPPanel API credentials, select sender numbers, and test the connection.', 'gfsms' ),
			self::TAB_QUEUE        => __( 'Control how SMS messages are queued and retried on failure.', 'gfsms' ),
			self::TAB_GRAVITYFORMS => __( 'Send SMS when a Gravity Form is submitted. Configure per-form rules below.', 'gfsms' ),
			self::TAB_ADVANCED     => __( 'Conditional logic, rate limiting, and debugging options.', 'gfsms' ),
			self::TAB_LOGGING      => __( 'Manage how long SMS logs are kept.', 'gfsms' ),
			self::TAB_WEBHOOK      => __( 'Receive real-time alerts via webhook when SMS events occur (e.g., permanent failure). The webhook is called with JSON payload containing entry_id, step_id, error details, and timestamp.', 'gfsms' ),
			self::TAB_HELP         => __( 'Complete reference for merge tags, IPPanel Edge API documentation, and usage examples.', 'gfsms' ),
			default                => '',
		};
	}

	/**
	 * Returns default settings values.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'                 => false,
			'trigger_step'            => 'yes',
			'trigger_workflow'        => 'no',
			'step_status_trigger'     => 'approved',

			'recipient_rules'         => array(),

			'sending_method'          => 'webservice',
			'template_approved'       => __( 'Your request has been approved.', 'gfsms' ),
			'template_rejected'       => __( 'Your request has been rejected.', 'gfsms' ),
			'template_workflow'       => __( 'Workflow completed for entry #{entry_id}.', 'gfsms' ),

			'pattern_map'             => array(),

			'ippanel_api_key'         => '',
			'default_sender_number'   => '',
			'enable_fallback'         => false,
			'secondary_api_key'       => '',
			'secondary_sender_number' => '',

			'use_queue'               => false,
			'queue_delay'             => 0,
			'retry_enabled'           => true,

			'gf_rules'                => array(),

			'conditional_logic'       => '',
			'debug_mode'              => false,
			'enable_rate_limit'       => false,

			'log_retention_days'      => 180,

			'webhook_url'             => '',
			'webhook_events'          => array(),
		);
	}

	/**
	 * Returns the tab list with labels.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			self::TAB_GENERAL      => __( 'General', 'gfsms' ),
			self::TAB_RECIPIENTS   => __( 'Recipients', 'gfsms' ),
			self::TAB_TEMPLATES    => __( 'Templates', 'gfsms' ),
			self::TAB_PATTERNS     => __( 'Patterns', 'gfsms' ),
			self::TAB_IPPANEL      => __( 'IPPanel', 'gfsms' ),
			self::TAB_QUEUE        => __( 'Queue & Retry', 'gfsms' ),
			self::TAB_GRAVITYFORMS => __( 'Gravity Forms', 'gfsms' ),
			self::TAB_ADVANCED     => __( 'Advanced', 'gfsms' ),
			self::TAB_LOGGING      => __( 'Logging', 'gfsms' ),
			self::TAB_WEBHOOK      => __( 'Webhook', 'gfsms' ),
			self::TAB_HELP         => __( 'Help', 'gfsms' ),
		);
	}

	/**
	 * Returns the full field definitions array.
	 *
	 * All labels, help texts, and options are considered safe HTML.
	 * The renderer must escape dynamic values (e.g. placeholder) with esc_attr().
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array>
	 */
	public static function fields(): array {
		$sender_options = apply_filters(
			'gfsms_sender_options',
			array( '' => __( '— Select Sender Number —', 'gfsms' ) )
		);

		return array(
			// ── General ──
			'enabled'                 => array(
				'tab'     => self::TAB_GENERAL,
				'group'   => __( 'Global Settings', 'gfsms' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable SMS notifications', 'gfsms' ),
				'tooltip' => __( 'Master switch for the plugin.', 'gfsms' ),
			),
			'trigger_step'            => array(
				'tab'     => self::TAB_GENERAL,
				'group'   => __( 'Step Triggers', 'gfsms' ),
				'type'    => 'select',
				'label'   => __( 'Send SMS on step completion', 'gfsms' ),
				'options' => array(
					'yes' => __( 'Yes', 'gfsms' ),
					'no'  => __( 'No', 'gfsms' ),
				),
			),
			'step_status_trigger'     => array(
				'tab'     => self::TAB_GENERAL,
				'group'   => __( 'Step Triggers', 'gfsms' ),
				'type'    => 'select',
				'label'   => __( 'Which step status triggers SMS?', 'gfsms' ),
				'options' => array(
					'approved' => __( 'Approved only', 'gfsms' ),
					'rejected' => __( 'Rejected only', 'gfsms' ),
					'both'     => __( 'Both approved and rejected', 'gfsms' ),
				),
			),
			'trigger_workflow'        => array(
				'tab'     => self::TAB_GENERAL,
				'group'   => __( 'Workflow Completion', 'gfsms' ),
				'type'    => 'select',
				'label'   => __( 'Send SMS on workflow completion', 'gfsms' ),
				'options' => array(
					'yes' => __( 'Yes', 'gfsms' ),
					'no'  => __( 'No', 'gfsms' ),
				),
			),

			// ── Recipients ──
			'recipient_rules'         => array(
				'tab'   => self::TAB_RECIPIENTS,
				'group' => __( 'Per-Form Recipient Rules', 'gfsms' ),
				'type'  => 'recipient_rules',
				'label' => __( 'Define recipient rules', 'gfsms' ),
				'help'  => self::recipient_rules_help(),
			),

			// ── Templates ──
			'sending_method'          => array(
				'tab'     => self::TAB_TEMPLATES,
				'group'   => __( 'Sending Method', 'gfsms' ),
				'type'    => 'select',
				'label'   => __( 'Sending method', 'gfsms' ),
				'options' => array(
					'webservice' => __( 'Webservice – send plain text, supports multiple recipients', 'gfsms' ),
					'pattern'    => __( 'Pattern – use a predefined IPPanel pattern code, single recipient only', 'gfsms' ),
				),
				'help'    => '<strong>' . __( 'Webservice', 'gfsms' ) . '</strong>: ' . __( 'Sends the exact text you write. ', 'gfsms' ) .
							'<strong>' . __( 'Pattern', 'gfsms' ) . '</strong>: ' . __( 'Uses an IPPanel pattern code (created in your IPPanel panel) with variables you provide below. Pattern only supports one recipient per message.', 'gfsms' ),
			),
			'template_approved'       => array(
				'tab'   => self::TAB_TEMPLATES,
				'group' => __( 'Step Templates', 'gfsms' ),
				'type'  => 'textarea',
				'label' => __( 'Approved step template', 'gfsms' ),
				'help'  => self::merge_tags_help(),
			),
			'template_rejected'       => array(
				'tab'   => self::TAB_TEMPLATES,
				'group' => __( 'Step Templates', 'gfsms' ),
				'type'  => 'textarea',
				'label' => __( 'Rejected step template', 'gfsms' ),
				'help'  => self::merge_tags_help(),
			),
			'template_workflow'       => array(
				'tab'   => self::TAB_TEMPLATES,
				'group' => __( 'Workflow Completion Template', 'gfsms' ),
				'type'  => 'textarea',
				'label' => __( 'Workflow completion template', 'gfsms' ),
				'help'  => self::merge_tags_help(),
			),

			// ── Patterns ──
			'pattern_map'             => array(
				'tab'   => self::TAB_PATTERNS,
				'group' => __( 'Pattern Mapping', 'gfsms' ),
				'type'  => 'pattern_map',
				'label' => __( 'Event → IPPanel pattern mapping', 'gfsms' ),
				'help'  => self::pattern_help(),
			),

			// ── IPPanel ──
			'ippanel_api_key'         => array(
				'tab'   => self::TAB_IPPANEL,
				'group' => __( 'API Credentials', 'gfsms' ),
				'type'  => 'password',
				'label' => __( 'IPPanel API Key', 'gfsms' ),
			),
			'default_sender_number'   => array(
				'tab'     => self::TAB_IPPANEL,
				'group'   => __( 'Sender Configuration', 'gfsms' ),
				'type'    => 'select',
				'label'   => __( 'Default sender number', 'gfsms' ),
				'options' => $sender_options,
			),
			'enable_fallback'         => array(
				'tab'   => self::TAB_IPPANEL,
				'group' => __( 'Fallback Provider', 'gfsms' ),
				'type'  => 'checkbox',
				'label' => __( 'Enable fallback provider', 'gfsms' ),
			),
			'secondary_api_key'       => array(
				'tab'   => self::TAB_IPPANEL,
				'group' => __( 'Fallback Provider', 'gfsms' ),
				'type'  => 'password',
				'label' => __( 'Fallback API Key', 'gfsms' ),
			),
			'secondary_sender_number' => array(
				'tab'   => self::TAB_IPPANEL,
				'group' => __( 'Fallback Provider', 'gfsms' ),
				'type'  => 'text',
				'label' => __( 'Fallback sender number', 'gfsms' ),
			),
			'test_ippanel_btn'        => array(
				'tab'          => self::TAB_IPPANEL,
				'group'        => __( 'Connection Test', 'gfsms' ),
				'type'         => 'button',
				'label'        => __( 'Test connection', 'gfsms' ),
				'button_label' => __( 'Send test SMS', 'gfsms' ),
			),

			// ── Queue ──
			'use_queue'               => array(
				'tab'   => self::TAB_QUEUE,
				'group' => __( 'Queue Settings', 'gfsms' ),
				'type'  => 'checkbox',
				'label' => __( 'Use queue', 'gfsms' ),
			),
			'queue_delay'             => array(
				'tab'   => self::TAB_QUEUE,
				'group' => __( 'Queue Settings', 'gfsms' ),
				'type'  => 'number',
				'label' => __( 'Queue delay (seconds)', 'gfsms' ),
				'min'   => self::MIN_QUEUE_DELAY,
				'max'   => self::MAX_QUEUE_DELAY,
			),
			'retry_enabled'           => array(
				'tab'   => self::TAB_QUEUE,
				'group' => __( 'Retry Policy', 'gfsms' ),
				'type'  => 'checkbox',
				'label' => __( 'Enable retry', 'gfsms' ),
			),

			// ── Gravity Forms ──
			'gf_rules'                => array(
				'tab'   => self::TAB_GRAVITYFORMS,
				'group' => __( 'Per-Form Rules', 'gfsms' ),
				'type'  => 'gf_rules',
				'label' => __( 'Define form submission rules', 'gfsms' ),
				'help'  => __( 'For each form you can set the recipient type (fixed number or form field), the message template, and a custom sender number. Available merge tags: {form_title}, {form_id}, {entry_id}, {date_created}, and all Gravity Forms merge tags.', 'gfsms' ),
			),

			// ── Advanced ──
			'conditional_logic'       => array(
				'tab'   => self::TAB_ADVANCED,
				'group' => __( 'Conditional Logic', 'gfsms' ),
				'type'  => 'textarea',
				'label' => __( 'Conditional logic JSON', 'gfsms' ),
			),
			'enable_rate_limit'       => array(
				'tab'   => self::TAB_ADVANCED,
				'group' => __( 'Rate Limiting', 'gfsms' ),
				'type'  => 'checkbox',
				'label' => __( 'Limit sending frequency', 'gfsms' ),
			),
			'debug_mode'              => array(
				'tab'   => self::TAB_ADVANCED,
				'group' => __( 'Debugging', 'gfsms' ),
				'type'  => 'checkbox',
				'label' => __( 'Enable debug logging', 'gfsms' ),
			),

			// ── Logging ──
			'log_retention_days'      => array(
				'tab'   => self::TAB_LOGGING,
				'group' => __( 'Log Retention', 'gfsms' ),
				'type'  => 'number',
				'label' => __( 'Keep logs for (days)', 'gfsms' ),
				'min'   => self::MIN_LOG_RETENTION,
				'max'   => self::MAX_LOG_RETENTION,
			),

			// ── Webhook ──
			'webhook_url'             => array(
				'tab'         => self::TAB_WEBHOOK,
				'group'       => __( 'Webhook Endpoint', 'gfsms' ),
				'type'        => 'text',
				'label'       => __( 'Webhook URL', 'gfsms' ),
				'placeholder' => 'https://example.com/webhook',
				'help'        => __( 'The webhook receives a POST request with JSON body containing: <code>entry_id</code>, <code>step_id</code>, <code>event_type</code>, <code>error_code</code>, <code>error_message</code>, <code>timestamp</code>. Use it to integrate with external monitoring systems.', 'gfsms' ),
			),
			'webhook_events'          => array(
				'tab'     => self::TAB_WEBHOOK,
				'group'   => __( 'Events to Notify', 'gfsms' ),
				'type'    => 'multicheck',
				'label'   => __( 'Trigger webhook on', 'gfsms' ),
				'options' => array(
					'sms_failed'   => __( 'SMS permanent failure', 'gfsms' ),
					'sms_retry'    => __( 'SMS retry scheduled', 'gfsms' ),
					'system_error' => __( 'System error', 'gfsms' ),
				),
			),

			// ── Help (read-only tab) ──
			'help_content'            => array(
				'tab'   => self::TAB_HELP,
				'group' => '',
				'type'  => 'help_content',
				'label' => '',
			),
		);
	}

	/**
	 * Help text for merge tags.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	private static function merge_tags_help(): string {
		return '<strong>' . __( 'Available merge tags:', 'gfsms' ) . '</strong> ' .
			'<code>{workflow_step_name}</code>, <code>{assignee_name}</code>, <code>{approval_comment}</code>, ' .
			'<code>{entry_id}</code>, <code>{form_id}</code>, <code>{final_status}</code>. ' .
			__( 'You can also use Gravity Forms merge tags like', 'gfsms' ) . ' <code>{Name (ID):1.3}</code>.';
	}

	/**
	 * Help text for pattern mapping.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	private static function pattern_help(): string {
		return '<strong>' . __( 'How to use patterns:', 'gfsms' ) . '</strong> ' .
			__( '1. Create a pattern in your IPPanel dashboard. 2. Copy its code here. 3. Define the variables as a JSON object (e.g. {"name":"value"}). Available merge tags are the same as in templates.', 'gfsms' );
	}

	/**
	 * Help text for recipient rules.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	private static function recipient_rules_help(): string {
		return '<strong>' . __( 'Rule-based recipients:', 'gfsms' ) . '</strong> ' .
			__( 'Add one rule per form. If no rule matches, the plugin will fall back to the assignee of the step.', 'gfsms' );
	}

	/**
	 * Sanitize and validate the entire settings array.
	 *
	 * **Critical fix**: Merge submitted values with currently stored settings,
	 * so saving one tab never erases another.
	 *
	 * @since 3.0.0
	 *
	 * @param array $input Raw input data (slashed).
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $input ): array {
		$input    = wp_unslash( $input );
		$defaults = self::defaults();

		// Preserve existing settings to avoid data loss from other tabs.
		$existing = get_option( GFSMS_SETTINGS_OPTION, $defaults );
		$out      = is_array( $existing ) ? $existing : $defaults;

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];

			// Boolean fields: use WordPress REST API sanitizer for strict true/false.
			if ( is_bool( $default ) ) {
				$out[ $key ] = (bool) rest_sanitize_boolean( $value );
				continue;
			}

			// Integer fields: clamp to allowed range.
			if ( is_int( $default ) ) {
				$out[ $key ] = self::clamp_numeric( $key, (int) $value );
				continue;
			}

			// Array fields: handle rule sets and pattern maps specially.
			if ( is_array( $default ) ) {
				if ( in_array( $key, array( 'recipient_rules', 'gf_rules' ), true ) && is_array( $value ) ) {
					$out[ $key ] = self::sanitize_rules( $value, $key );
					continue;
				}

				if ( 'pattern_map' === $key && is_array( $value ) ) {
					$out[ $key ] = self::sanitize_pattern_map( $value );
					continue;
				}

				// Generic array: sanitize each element as text.
				$out[ $key ] = array_map( 'sanitize_text_field', (array) $value );
				continue;
			}

			// URL field: strict sanitization + PHP filter validation.
			if ( 'webhook_url' === $key ) {
				$url = esc_url_raw( (string) $value );
				$out[ $key ] = filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
				continue;
			}

			// Sender numbers: digits and '+' only.
			if ( in_array( $key, array( 'default_sender_number', 'secondary_sender_number' ), true ) ) {
				$out[ $key ] = preg_replace( '/[^0-9+]/', '', (string) $value );
				continue;
			}

			// Conditional logic: must be valid JSON or becomes empty.
			if ( 'conditional_logic' === $key ) {
				$decoded = json_decode( (string) $value, true );
				$out[ $key ] = ( JSON_ERROR_NONE === json_last_error() )
					? sanitize_textarea_field( (string) $value )
					: '';
				continue;
			}

			// Default: sanitize as plain text.
			$out[ $key ] = sanitize_text_field( (string) $value );
		}

		return $out;
	}

	/**
	 * Clamp numeric values to their allowed range.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key   Field key.
	 * @param int    $value Raw value.
	 * @return int
	 */
	private static function clamp_numeric( string $key, int $value ): int {
		return match ( $key ) {
			'queue_delay'        => max( self::MIN_QUEUE_DELAY, min( self::MAX_QUEUE_DELAY, $value ) ),
			'log_retention_days' => max( self::MIN_LOG_RETENTION, min( self::MAX_LOG_RETENTION, $value ) ),
			default              => $value,
		};
	}

	/**
	 * Sanitize rule arrays (recipient_rules / gf_rules).
	 *
	 * @since 3.0.0
	 *
	 * @param array  $rules   Raw rules array.
	 * @param string $context Rule context (recipient_rules or gf_rules).
	 * @return array
	 */
	private static function sanitize_rules( array $rules, string $context ): array {
		$clean = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$form_id = absint( $rule['form_id'] ?? 0 );
			if ( 0 === $form_id ) {
				continue;
			}

			// Common new fields for both rule types.
			$pattern_code  = sanitize_text_field( $rule['pattern_code'] ?? '' );
			$variable_map  = self::sanitize_variable_map( $rule['variable_map'] ?? array() );

			if ( 'recipient_rules' === $context ) {
				$source   = sanitize_key( $rule['source'] ?? 'assignee' );
				$field_id = sanitize_text_field( $rule['mobile_field_id'] ?? '' );

				// For fixed numbers, mobile_field_id holds the numbers.
				$numbers = array();
				if ( 'fixed' === $source && ! empty( $field_id ) ) {
					$numbers = array_map(
						static fn( $num ) => preg_replace( '/[^0-9+]/', '', trim( (string) $num ) ),
						explode( ',', $field_id )
					);
				}

				$clean[] = array(
					'form_id'         => $form_id,
					'source'          => $source,
					'mobile_field_id' => $field_id,
					'fixed_numbers'   => implode( ',', array_filter( $numbers ) ),
					'pattern_code'    => $pattern_code,
					'variable_map'    => $variable_map,
				);
				continue;
			}

			// gf_rules.
			$clean[] = array(
				'form_id'          => $form_id,
				'recipient_type'   => sanitize_key( $rule['recipient_type'] ?? 'fixed' ),
				'fixed_recipient'  => sanitize_text_field( $rule['fixed_recipient'] ?? '' ),
				'recipient_field'  => sanitize_text_field( $rule['recipient_field'] ?? '' ),
				'message_template' => sanitize_textarea_field( $rule['message_template'] ?? '' ),
				'sender_number'    => preg_replace( '/[^0-9+]/', '', (string) ( $rule['sender_number'] ?? '' ) ),
				'pattern_code'     => $pattern_code,
				'variable_map'     => $variable_map,
			);
		}

		return $clean;
	}

	/**
	 * Sanitize pattern_map with validated variable structure.
	 *
	 * @since 3.0.0
	 *
	 * @param array $value Raw pattern map.
	 * @return array
	 */
	private static function sanitize_pattern_map( array $value ): array {
		$clean = array();

		foreach ( $value as $event_key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$clean[ sanitize_key( (string) $event_key ) ] = array(
				'pattern_code' => sanitize_text_field( (string) ( $item['pattern_code'] ?? '' ) ),
				'variable_map' => self::sanitize_variable_map( $item['variable_map'] ?? '' ),
			);
		}

		return $clean;
	}

	/**
	 * JSON decode + validate each key is string => string.
	 *
	 * @since 3.0.0
	 *
	 * @param mixed $raw Raw variable map data.
	 * @return array
	 */
	private static function sanitize_variable_map( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}
			$raw = $decoded;
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $k => $v ) {
			$clean[ sanitize_key( (string) $k ) ] = is_scalar( $v )
				? sanitize_text_field( (string) $v )
				: '';
		}

		return $clean;
	}
}