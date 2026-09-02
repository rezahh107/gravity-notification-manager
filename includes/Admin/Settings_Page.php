<?php
/**
 * Settings page for the Gravity Flow SMS plugin.
 *
 * @package GFSMS\Admin
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Admin;

use GFSMS\Integration\IPPanel_Provider;
use GFSMS\Integration\Wp_HTTP_Client;
use GFSMS\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings_Page
 *
 * Handles the plugin settings page, tabs, fields, and AJAX actions.
 *
 * @since 3.0.0
 */
final class Settings_Page {

	private const MENU_SLUG           = 'gravityflow-sms';
	private const LOGS_SUB_SLUG       = 'gfsms-logs';
	private const OPTION_GROUP        = 'gfsms_settings_group';
	private const TEST_NONCE_ACTION   = 'gfsms_test_connection';
	private const FETCH_NONCE_ACTION  = 'gfsms_fetch_senders';

	/**
	 * Registered custom field renderers.
	 *
	 * @var array<string, class-string>
	 */
	private static array $field_renderers = [];

	/**
	 * In‑memory settings cache.
	 *
	 * @var array|null
	 */
	private static ?array $settings_cache = null;

	// ----------------------------------------------------------------
	// Bootstrap
	// ----------------------------------------------------------------
	public static function register_hooks(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'wp_ajax_gfsms_test_connection', [ self::class, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_gfsms_fetch_senders', [ self::class, 'ajax_fetch_senders' ] );
		add_action( 'gfsms_cleanup_logs', [ self::class, 'cleanup_old_logs' ] );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Gravity Flow SMS', 'gfsms' ),
			__( 'Gravity Flow SMS', 'gfsms' ),
			GFSMS_CAPABILITY,
			self::MENU_SLUG,
			[ self::class, 'render_page' ],
			'dashicons-email-alt2',
			30
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'SMS Logs', 'gfsms' ),
			__( 'SMS Logs', 'gfsms' ),
			GFSMS_CAPABILITY,
			self::LOGS_SUB_SLUG,
			[ Logs_Table::class, 'render_page' ]
		);
	}

	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			GFSMS_SETTINGS_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings_Schema::class, 'sanitize' ],
				'default'           => Settings_Schema::defaults(),
			]
		);
	}

	// ----------------------------------------------------------------
	// Assets
	// ----------------------------------------------------------------
	public static function enqueue_assets( string $hook ): void {
		if ( ! self::is_our_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style( 'gfsms-admin', GFSMS_PLUGIN_URL . 'assets/css/admin.css', [], GFSMS_PLUGIN_VERSION );
		if ( is_rtl() ) {
			wp_enqueue_style( 'gfsms-admin-rtl', GFSMS_PLUGIN_URL . 'assets/css/admin-rtl.css', [ 'gfsms-admin' ], GFSMS_PLUGIN_VERSION );
		}

		wp_enqueue_script(
			'gfsms-admin',
			GFSMS_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery', 'wp-util' ],
			GFSMS_PLUGIN_VERSION,
			true
		);
		wp_localize_script( 'gfsms-admin', 'gfsmsAdmin', self::js_config() );
	}

    private static function is_our_screen( string $hook ): bool {
    // Check by hook name
    if ( in_array( $hook, [
        'toplevel_page_' . self::MENU_SLUG,
        self::MENU_SLUG . '_page_' . self::LOGS_SUB_SLUG,
    ], true ) ) {
        return true;
    }
    // Fallback: check current page query parameter
    if ( isset( $_GET['page'] ) && $_GET['page'] === self::MENU_SLUG ) {
        return true;
    }
    return false;
    }
	private static function js_config(): array {
		return [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::TEST_NONCE_ACTION ),
			'fetchNonce' => wp_create_nonce( self::FETCH_NONCE_ACTION ),
			'strings'    => [
				'working'      => __( 'Working…', 'gfsms' ),
				'success'      => __( 'Connection successful.', 'gfsms' ),
				'error'        => __( 'Connection failed.', 'gfsms' ),
				'invalid'      => __( 'Invalid JSON.', 'gfsms' ),
				'fetchSenders' => __( 'Fetch Senders', 'gfsms' ),
				'fetching'     => __( 'Fetching…', 'gfsms' ),
				'noSenders'    => __( 'No senders found. Please check your API key or account.', 'gfsms' ),
				'addRule'      => __( 'Add Rule', 'gfsms' ),
				'removeRule'   => __( 'Remove', 'gfsms' ),
				'selectForm'   => __( 'Select a form', 'gfsms' ),
			],
		];
	}

	// ----------------------------------------------------------------
	// Settings access (with request‑level cache)
	// ----------------------------------------------------------------
	private static function get_settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$stored = get_option( GFSMS_SETTINGS_OPTION, [] );

		// Merge over defaults in a predictable order.
		self::$settings_cache = is_array( $stored )
			? wp_parse_args( $stored, Settings_Schema::defaults() )
			: Settings_Schema::defaults();

		return self::$settings_cache;
	}

	// ----------------------------------------------------------------
	// Provider factory (DRY)
	// ----------------------------------------------------------------
	private static function make_provider(): IPPanel_Provider {
		$settings = self::get_settings();
		return new IPPanel_Provider(
			new Wp_HTTP_Client(),
			(string) ( $settings['ippanel_api_key'] ?? '' ),
			$settings
		);
	}

	// ----------------------------------------------------------------
	// Phone utilities
	// ----------------------------------------------------------------
	private static function normalize_phone( string $input ): string {
		return preg_replace( '/[^0-9+]/', '', $input );
	}

	private static function is_valid_phone( string $phone ): bool {
		return (bool) preg_match( '/^\+?[0-9]{10,15}$/', $phone );
	}

	// ----------------------------------------------------------------
	// Page rendering
	// ----------------------------------------------------------------
	public static function render_page(): void {
		if ( ! current_user_can( GFSMS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gfsms' ) );
		}

		$settings = self::get_settings();
		$tabs     = apply_filters( 'gfsms_settings_tabs', Settings_Schema::tabs() );
		$fields   = apply_filters( 'gfsms_settings_fields', Settings_Schema::fields() );
		$tab      = self::current_tab( $tabs );

		echo '<div class="wrap gfsms-wrap" dir="auto">';
		echo '<h1>' . esc_html__( 'Gravity Flow SMS Notifier', 'gfsms' ) . '</h1>';

		$tab_desc = Settings_Schema::tab_description( $tab );
		if ( '' !== $tab_desc ) {
			echo '<p class="description gfsms-intro">' . esc_html( $tab_desc ) . '</p>';
		}

		self::render_tab_navigation( $tabs, $tab );

		if ( 'help' === $tab ) {
			self::render_help_tab();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_GROUP );
		self::render_form_fields( $fields, $tab, $settings );
		submit_button();
		echo '</form>';

		if ( 'ippanel' === $tab ) {
			self::render_fetch_senders_card();
			self::render_connection_test_card();
		}

		echo '</div>';
	}

	private static function render_help_tab(): void {
		?>
		<div class="gfsms-card" style="max-width: 900px;">
			<h2><?php esc_html_e( 'Merge Tags Reference', 'gfsms' ); ?></h2>
			<p><?php esc_html_e( 'Use these tags in your SMS templates.', 'gfsms' ); ?></p>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tag', 'gfsms' ); ?></th>
						<th><?php esc_html_e( 'Description', 'gfsms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>{workflow_step_name}</code></td><td><?php esc_html_e( 'Name of the completed step', 'gfsms' ); ?></td></tr>
					<tr><td><code>{assignee_name}</code></td><td><?php esc_html_e( 'Display name of the step assignee', 'gfsms' ); ?></td></tr>
					<tr><td><code>{approval_comment}</code></td><td><?php esc_html_e( 'Last approval comment', 'gfsms' ); ?></td></tr>
					<tr><td><code>{entry_id}</code></td><td><?php esc_html_e( 'Entry ID', 'gfsms' ); ?></td></tr>
					<tr><td><code>{form_id}</code></td><td><?php esc_html_e( 'Form ID', 'gfsms' ); ?></td></tr>
					<tr><td><code>{final_status}</code></td><td><?php esc_html_e( 'Final workflow status', 'gfsms' ); ?></td></tr>
					<tr><td><code>{form_title}</code></td><td><?php esc_html_e( 'Form title (Gravity Forms submission)', 'gfsms' ); ?></td></tr>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Sending Methods', 'gfsms' ); ?></h2>
			<p><strong><?php esc_html_e( 'Webservice:', 'gfsms' ); ?></strong> <?php esc_html_e( 'Sends plain text SMS. Supports multiple recipients.', 'gfsms' ); ?></p>
			<p><strong><?php esc_html_e( 'Pattern:', 'gfsms' ); ?></strong> <?php esc_html_e( 'Uses a pre-defined pattern from IPPanel. Only ONE recipient per message.', 'gfsms' ); ?></p>
			<h2><?php esc_html_e( 'Webhook Payload', 'gfsms' ); ?></h2>
			<pre><code>{
	"entry_id": 123,
	"step_id": 1,
	"event_type": "step",
	"error_code": "http_500",
	"error_message": "Internal Server Error",
	"timestamp": "2025-01-01 12:00:00"
}</code></pre>
			<p>
				<?php esc_html_e( 'For detailed API information, visit the official docs:', 'gfsms' ); ?>
				<a href="https://docs.ippanel.com" target="_blank" rel="noopener noreferrer">https://docs.ippanel.com</a>
			</p>
		</div>
		<?php
	}

	private static function render_tab_navigation( array $tabs, string $current ): void {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $slug );
			$class = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';
	}

	private static function render_form_fields( array $fields, string $tab, array $settings ): void {
		echo '<table class="form-table gfsms-form-table" role="presentation">';
		foreach ( self::group_fields( $fields, $tab ) as $group_title => $group_fields ) {
			echo '<tbody class="gfsms-group">';
			if ( '' !== $group_title ) {
				echo '<tr class="gfsms-group-heading"><th colspan="2"><h3>' . esc_html( $group_title ) . '</h3></th></tr>';
			}
			foreach ( $group_fields as $key => $field ) {
				self::render_field_row( $key, $field, $settings[ $key ] ?? null );
			}
			echo '</tbody>';
		}
		echo '</table>';
	}

	private static function group_fields( array $fields, string $tab ): array {
		$grouped = [];
		foreach ( $fields as $key => $field ) {
			if ( ( $field['tab'] ?? '' ) !== $tab ) {
				continue;
			}
			$group = (string) ( $field['group'] ?? '' );
			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = [];
			}
			$grouped[ $group ][ $key ] = $field;
		}
		return $grouped;
	}

	private static function render_field_row( string $name, array $field, mixed $value ): void {
		$label   = (string) ( $field['label'] ?? '' );
		$help    = (string) ( $field['help'] ?? '' );
		$tooltip = (string) ( $field['tooltip'] ?? '' );

		echo '<tr>';
		if ( '' !== $label ) {
			echo '<th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
			if ( '' !== $tooltip ) {
				echo ' <span class="dashicons dashicons-info-outline gfsms-tooltip" title="' . esc_attr( $tooltip ) . '"></span>';
			}
			echo '</th>';
		} else {
			echo '<th></th>';
		}

		echo '<td>';
		self::render_field_by_type( $name, $field, $value );
		if ( '' !== $help ) {
			echo '<p class="description">' . wp_kses(
				$help,
				[
					'a'      => [ 'href' => [], 'target' => [], 'rel' => [] ],
					'strong' => [],
					'em'     => [],
					'code'   => [],
					'br'     => [],
				]
			) . '</p>';
		}
		echo '</td>';
		echo '</tr>';
	}

	private static function render_field_by_type( string $name, array $field, mixed $value ): void {
		$type = $field['type'] ?? 'text';

		// Custom renderer via registry (pluggable)
		if ( isset( self::$field_renderers[ $type ] ) ) {
			$renderer_class = self::$field_renderers[ $type ];
			if ( class_exists( $renderer_class ) ) {
				$renderer = new $renderer_class();
				$renderer->render( $name, $field, $value );
				return;
			}
		}

		// Standard types
		switch ( $type ) {
			case 'checkbox':
				echo Settings_Fields::checkbox( $name, (bool) $value );
				break;
			case 'text':
				echo Settings_Fields::text( $name, (string) $value, (string) ( $field['placeholder'] ?? '' ) );
				break;
			case 'password':
				echo Settings_Fields::password( $name, (string) $value, (string) ( $field['placeholder'] ?? '' ) );
				break;
			case 'textarea':
				echo Settings_Fields::textarea(
					$name,
					is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : (string) $value,
					(int) ( $field['rows'] ?? 5 )
				);
				break;
			case 'number':
				echo Settings_Fields::number( $name, (int) $value, (int) ( $field['min'] ?? 0 ), (int) ( $field['max'] ?? 999999 ) );
				break;
			case 'select':
				self::render_select_field( $name, $field, $value );
				break;
			case 'multicheck':
				echo Settings_Fields::multicheck( $name, is_array( $value ) ? $value : [], (array) ( $field['options'] ?? [] ) );
				break;
			case 'button':
				// Legacy test button – may be removed later.
				echo '<button type="button" id="gfsms_test_connection_btn" class="button button-secondary">' . esc_html( (string) ( $field['button_label'] ?? __( 'Test', 'gfsms' ) ) ) . ' <span class="spinner" style="float:none;margin-top:0;"></span></button>';
				echo '<div id="gfsms_test_result" class="gfsms-result" aria-live="polite"></div>';
				break;
			case 'pattern_map':
				self::render_pattern_map( (array) $value );
				break;
			case 'recipient_rules':
			case 'gf_rules':
				self::render_rule_builder( $type, (array) $value );
				break;
			default:
				// Safe fallback for unknown types.
				echo '<input type="text" class="regular-text" value="' . esc_attr( (string) $value ) . '" />';
				break;
		}
	}

	private static function render_select_field( string $name, array $field, mixed $value ): void {
		$options         = (array) ( $field['options'] ?? [] );
		$is_sender_field = in_array( $name, [ 'default_sender_number', 'gf_sender_number', 'secondary_sender_number' ], true );

		if ( $is_sender_field ) {
			$cached = get_option( 'gfsms_cached_senders', [] );
			if ( is_array( $cached ) ) {
				foreach ( $cached as $sender ) {
					$sender = sanitize_text_field( $sender );
					if ( ! array_key_exists( $sender, $options ) ) {
						$options[ $sender ] = $sender;
					}
				}
			}
			if ( empty( $options ) ) {
				echo Settings_Fields::text( $name, (string) $value, __( 'No senders fetched – enter manually', 'gfsms' ) );
				echo '<p class="description">' . esc_html__( 'Click "Fetch Senders" below to retrieve automatically.', 'gfsms' ) . '</p>';
				return;
			}
		}

		echo Settings_Fields::select( $name, (string) $value, $options );
	}

	private static function render_pattern_map( array $patterns ): void {
		$events = [
			'step_approved'     => __( 'Step Approved', 'gfsms' ),
			'step_rejected'     => __( 'Step Rejected', 'gfsms' ),
			'workflow_complete' => __( 'Workflow Complete', 'gfsms' ),
			'gf_submission'     => __( 'Gravity Forms Submission', 'gfsms' ),
		];

		echo '<div class="gfsms-pattern-map">';
		foreach ( $events as $event_key => $label ) {
			$item         = (array) ( $patterns[ $event_key ] ?? [] );
			$pattern_code = (string) ( $item['pattern_code'] ?? '' );
			$variable_map = (array) ( $item['variable_map'] ?? [] );

			echo '<div class="gfsms-pattern-card">';
			echo '<h4>' . esc_html( $label ) . '</h4>';
			echo '<p><label>' . esc_html__( 'Pattern Code', 'gfsms' ) . '</label><br />';
			$name_attr = esc_attr( GFSMS_SETTINGS_OPTION . '[pattern_map][' . $event_key . '][pattern_code]' );
			echo '<input type="text" class="regular-text gfsms-control" name="' . $name_attr . '" value="' . esc_attr( $pattern_code ) . '" /></p>';
			echo '<p><label>' . esc_html__( 'Variable Map (JSON)', 'gfsms' ) . '</label><br />';
			$name_attr = esc_attr( GFSMS_SETTINGS_OPTION . '[pattern_map][' . $event_key . '][variable_map]' );
			echo '<textarea rows="4" class="large-text code gfsms-control gfsms-json-field" data-event="' . esc_attr( $event_key ) . '" name="' . $name_attr . '">' . esc_textarea( wp_json_encode( $variable_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) . '</textarea></p>';
			echo '<div class="gfsms-json-error" style="display:none;"></div>';
			echo '</div>';
		}
		echo '</div>';
	}

	private static function render_rule_builder( string $type, array $rules ): void {
		$form_options = [];
		static $forms_cache = null;
		if ( null === $forms_cache && class_exists( 'GFAPI' ) ) {
			$forms_cache = \GFAPI::get_forms() ?: [];
		}
		if ( is_array( $forms_cache ) ) {
			foreach ( $forms_cache as $f ) {
				$form_options[ $f['id'] ] = $f['title'];
			}
		}

		$is_recipient_rules = ( 'recipient_rules' === $type );
		$colspan_main       = $is_recipient_rules ? 4 : 6; // cells in main row excluding actions

		// Labels for data-label attributes (must match header text exactly)
		$label_form     = __( 'Form', 'gfsms' );
		$label_source   = __( 'Source', 'gfsms' );
		$label_mobile   = __( 'Mobile field ID / Numbers', 'gfsms' );
		$label_rec_type = __( 'Recipient type', 'gfsms' );
		$label_recipient= __( 'Recipient', 'gfsms' );
		$label_msg_tpl  = __( 'Message template', 'gfsms' );
		$label_sender   = __( 'Sender number', 'gfsms' );
		$label_actions  = __( 'Actions', 'gfsms' );
		$label_pattern  = __( 'Pattern', 'gfsms' );

		echo '<div class="gfsms-rule-builder" data-name="' . esc_attr( $type ) . '">';
		echo '<table class="widefat fixed gfsms-rules-table"><thead><tr>';
		echo '<th>' . esc_html( $label_form ) . '</th>';

		if ( $is_recipient_rules ) {
			echo '<th>' . esc_html( $label_source ) . '</th>';
			echo '<th>' . esc_html( $label_mobile ) . '</th>';
		} else {
			echo '<th>' . esc_html( $label_rec_type ) . '</th>';
			echo '<th>' . esc_html( $label_recipient ) . '</th>';
			echo '<th>' . esc_html( $label_msg_tpl ) . '</th>';
			echo '<th>' . esc_html( $label_sender ) . '</th>';
		}
		echo '<th>' . esc_html( $label_actions ) . '</th>';
		echo '</tr></thead><tbody>';

		// Template row – main row (hidden)
		echo '<tr class="gfsms-rule-template" style="display:none">';
		echo '<td data-label="' . esc_attr( $label_form ) . '"><select name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][__INDEX__][form_id]' ) . '" class="gfsms-form-select">';
		echo '<option value="">' . esc_html__( 'Select a form', 'gfsms' ) . '</option>';
		foreach ( $form_options as $id => $title ) {
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $title ) . '</option>';
		}
		echo '</select></td>';

		if ( $is_recipient_rules ) {
			echo '<td data-label="' . esc_attr( $label_source ) . '"><select name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][__INDEX__][source]' ) . '">';
			echo '<option value="assignee">' . esc_html__( 'Assignee', 'gfsms' ) . '</option>';
			echo '<option value="form_field">' . esc_html__( 'Form field', 'gfsms' ) . '</option>';
			echo '<option value="fixed">' . esc_html__( 'Fixed numbers', 'gfsms' ) . '</option>';
			echo '<option value="submitter">' . esc_html__( 'Submitter', 'gfsms' ) . '</option>';
			echo '</select></td>';
			echo '<td data-label="' . esc_attr( $label_mobile ) . '"><input type="text" name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][__INDEX__][mobile_field_id]' ) . '" class="regular-text" placeholder="' . esc_attr__( 'e.g. 3 or +98912...', 'gfsms' ) . '"></td>';
		} else {
			echo '<td data-label="' . esc_attr( $label_rec_type ) . '"><select name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][__INDEX__][recipient_type]' ) . '">';
			echo '<option value="fixed">' . esc_html__( 'Fixed number', 'gfsms' ) . '</option>';
			echo '<option value="field">' . esc_html__( 'Form field', 'gfsms' ) . '</option>';
			echo '<option value="submitter">' . esc_html__( 'Submitter', 'gfsms' ) . '</option>';
			echo '</select></td>';
			echo '<td data-label="' . esc_attr( $label_recipient ) . '"><input type="text" name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][__INDEX__][fixed_recipient]' ) . '" class="regular-text" placeholder="+98912..."></td>';
			echo '<td data-label="' . esc_attr( $label_msg_tpl ) . '"><textarea name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][__INDEX__][message_template]' ) . '" rows="2" class="large-text"></textarea></td>';
			echo '<td data-label="' . esc_attr( $label_sender ) . '"><input type="text" name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][__INDEX__][sender_number]' ) . '" class="regular-text" placeholder="+98..."></td>';
		}

		// Actions cell
		echo '<td data-label="' . esc_attr( $label_actions ) . '"><button type="button" class="button button-small gfsms-remove-rule" title="' . esc_attr__( 'Remove rule', 'gfsms' ) . '"><span class="dashicons dashicons-trash"></span></button></td>';
		echo '</tr>';

		// Template row – pattern fields (hidden)
		echo '<tr class="gfsms-pattern-row" style="display:none">';
		echo '<td colspan="' . esc_attr( (string) $colspan_main ) . '" data-label="' . esc_attr( $label_pattern ) . '">';
		echo '<div class="gfsms-pattern-fields">';
		echo '<label>' . esc_html__( 'Pattern code', 'gfsms' ) . '</label> ';
		echo '<input type="text" name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][__INDEX__][pattern_code]' ) . '" class="regular-text" placeholder="' . esc_attr__( 'Pattern code', 'gfsms' ) . '">';
		echo ' &nbsp; <label>' . esc_html__( 'Variable map (JSON)', 'gfsms' ) . '</label> ';
		echo '<textarea name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][__INDEX__][variable_map]' ) . '" rows="1" class="large-text" placeholder="' . esc_attr__( 'JSON map', 'gfsms' ) . '"></textarea>';
		echo '</div>';
		echo '</td>';
		echo '</tr>';

		// Existing rules
		if ( empty( $rules ) ) {
			$rules = [ [ 'form_id' => 0 ] ];
		}

		foreach ( $rules as $i => $rule ) {
			$form_id = (int) ( $rule['form_id'] ?? 0 );
			// Main row
			echo '<tr class="gfsms-rule-main">';
			echo '<td data-label="' . esc_attr( $label_form ) . '"><select name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][' . $i . '][form_id]' ) . '" class="gfsms-form-select">';
			echo '<option value="">' . esc_html__( 'Select a form', 'gfsms' ) . '</option>';
			foreach ( $form_options as $id => $title ) {
				echo '<option value="' . esc_attr( (string) $id ) . '" ' . selected( $form_id, $id, false ) . '>' . esc_html( $title ) . '</option>';
			}
			echo '</select></td>';

			if ( $is_recipient_rules ) {
				$source = (string) ( $rule['source'] ?? 'assignee' );
				echo '<td data-label="' . esc_attr( $label_source ) . '"><select name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][' . $i . '][source]' ) . '">';
				echo '<option value="assignee" ' . selected( $source, 'assignee', false ) . '>' . esc_html__( 'Assignee', 'gfsms' ) . '</option>';
				echo '<option value="form_field" ' . selected( $source, 'form_field', false ) . '>' . esc_html__( 'Form field', 'gfsms' ) . '</option>';
				echo '<option value="fixed" ' . selected( $source, 'fixed', false ) . '>' . esc_html__( 'Fixed numbers', 'gfsms' ) . '</option>';
				echo '<option value="submitter" ' . selected( $source, 'submitter', false ) . '>' . esc_html__( 'Submitter', 'gfsms' ) . '</option>';
				echo '</select></td>';
				$field_value = $rule['mobile_field_id'] ?? $rule['fixed_numbers'] ?? '';
				echo '<td data-label="' . esc_attr( $label_mobile ) . '"><input type="text" name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][' . $i . '][mobile_field_id]' ) . '" placeholder="' . esc_attr__( 'e.g. 3 or +98912...', 'gfsms' ) . '" value="' . esc_attr( (string) $field_value ) . '" class="regular-text"></td>';
			} else {
				$rec_type = (string) ( $rule['recipient_type'] ?? 'fixed' );
				echo '<td data-label="' . esc_attr( $label_rec_type ) . '"><select name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][' . $i . '][recipient_type]' ) . '">';
				echo '<option value="fixed" ' . selected( $rec_type, 'fixed', false ) . '>' . esc_html__( 'Fixed number', 'gfsms' ) . '</option>';
				echo '<option value="field" ' . selected( $rec_type, 'field', false ) . '>' . esc_html__( 'Form field', 'gfsms' ) . '</option>';
				echo '<option value="submitter" ' . selected( $rec_type, 'submitter', false ) . '>' . esc_html__( 'Submitter', 'gfsms' ) . '</option>';
				echo '</select></td>';
				$recipient_value = $rule['fixed_recipient'] ?? $rule['recipient_field'] ?? '';
				echo '<td data-label="' . esc_attr( $label_recipient ) . '"><input type="text" name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][' . $i . '][fixed_recipient]' ) . '" placeholder="+98912..." value="' . esc_attr( (string) $recipient_value ) . '" class="regular-text"></td>';
				echo '<td data-label="' . esc_attr( $label_msg_tpl ) . '"><textarea name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][' . $i . '][message_template]' ) . '" rows="2" class="large-text">' . esc_textarea( $rule['message_template'] ?? '' ) . '</textarea></td>';
				echo '<td data-label="' . esc_attr( $label_sender ) . '"><input type="text" name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][' . $i . '][sender_number]' ) . '" placeholder="+98..." value="' . esc_attr( $rule['sender_number'] ?? '' ) . '" class="regular-text"></td>';
			}

			// Actions
			echo '<td data-label="' . esc_attr( $label_actions ) . '"><button type="button" class="button button-small gfsms-remove-rule" title="' . esc_attr__( 'Remove rule', 'gfsms' ) . '"><span class="dashicons dashicons-trash"></span></button></td>';
			echo '</tr>';

			// Pattern row (always visible)
			$pattern_code_val = (string) ( $rule['pattern_code'] ?? '' );
			$variable_map_val = is_array( $rule['variable_map'] ?? null )
				? wp_json_encode( $rule['variable_map'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
				: (string) ( $rule['variable_map'] ?? '' );
			echo '<tr class="gfsms-pattern-row">';
			echo '<td colspan="' . esc_attr( (string) $colspan_main ) . '" data-label="' . esc_attr( $label_pattern ) . '">';
			echo '<div class="gfsms-pattern-fields">';
			echo '<label>' . esc_html__( 'Pattern code', 'gfsms' ) . '</label> ';
			echo '<input type="text" name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][' . $i . '][pattern_code]' ) . '" class="regular-text" value="' . esc_attr( $pattern_code_val ) . '" placeholder="' . esc_attr__( 'Pattern code', 'gfsms' ) . '">';
			echo ' &nbsp; <label>' . esc_html__( 'Variable map (JSON)', 'gfsms' ) . '</label> ';
			echo '<textarea name="' . esc_attr( GFSMS_SETTINGS_OPTION . '[' . $type . '][' . $i . '][variable_map]' ) . '" rows="1" class="large-text" placeholder="' . esc_attr__( 'JSON map', 'gfsms' ) . '">' . esc_textarea( $variable_map_val ) . '</textarea>';
			echo '</div>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<button type="button" class="button button-primary gfsms-add-rule">' . esc_html__( 'Add Rule', 'gfsms' ) . '</button>';
		echo '</div>';
	}

	private static function render_fetch_senders_card(): void {
		?>
		<div class="gfsms-card" style="margin-bottom:1em;">
			<h2><?php esc_html_e( 'Sender Numbers', 'gfsms' ); ?></h2>
			<p><?php esc_html_e( 'Click the button to retrieve your approved sender numbers from IPPanel.', 'gfsms' ); ?></p>
			<button type="button" id="gfsms_fetch_senders_btn"
					class="button button-secondary gfsms-action-btn"
					data-action="fetch_senders">
				<?php esc_html_e( 'Fetch Senders', 'gfsms' ); ?> <span class="spinner" style="float:none;margin-top:0;"></span>
			</button>
			<div id="gfsms_fetch_senders_result" class="gfsms-result" aria-live="polite"></div>
		</div>
		<?php
	}

	private static function render_connection_test_card(): void {
		?>
		<div class="gfsms-card">
			<h2><?php esc_html_e( 'Test IPPanel connection', 'gfsms' ); ?></h2>
			<p><?php esc_html_e( 'Use a real recipient number to verify credentials and sender configuration.', 'gfsms' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gfsms_test_phone"><?php esc_html_e( 'Recipient phone', 'gfsms' ); ?></label></th>
					<td><input type="text" id="gfsms_test_phone" class="regular-text gfsms-control" placeholder="+989123456789" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="gfsms_test_message"><?php esc_html_e( 'Test message', 'gfsms' ); ?></label></th>
					<td><textarea id="gfsms_test_message" class="large-text code gfsms-control" rows="4"><?php echo esc_textarea( __( 'Test message from Gravity Flow SMS.', 'gfsms' ) ); ?></textarea></td>
				</tr>
			</table>
			<button type="button" id="gfsms_send_test_btn"
					class="button button-primary gfsms-action-btn"
					data-action="test_connection">
				<?php esc_html_e( 'Send test SMS', 'gfsms' ); ?> <span class="spinner" style="float:none;margin-left:5px;"></span>
			</button>
			<div id="gfsms_test_message_result" class="gfsms-result" aria-live="polite"></div>
		</div>
		<?php
	}

	// ----------------------------------------------------------------
	// AJAX handlers
	// ----------------------------------------------------------------
	public static function ajax_test_connection(): void {
		check_ajax_referer( self::TEST_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( GFSMS_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'gfsms' ) ], 403 );
		}

		// Sanitize input with proper unslashing.
		$phone_raw = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$message   = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

		$phone = self::normalize_phone( $phone_raw );

		if ( '' === $phone || '' === $message ) {
			wp_send_json_error( [ 'message' => __( 'Phone and message are required.', 'gfsms' ) ], 422 );
		}

		if ( ! self::is_valid_phone( $phone ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid phone number format.', 'gfsms' ) ], 422 );
		}

		// Additional business validation: sender must be configured.
		$settings = self::get_settings();
		$sender   = (string) ( $settings['default_sender_number'] ?? '' );
		if ( '' === $sender ) {
			wp_send_json_error( [ 'message' => __( 'Sender number not configured.', 'gfsms' ) ], 422 );
		}

		try {
			$provider = self::make_provider();
			$result   = $provider->send( $sender, [ $phone ], $message );

			if ( ! is_array( $result ) ) {
				throw new \RuntimeException( 'Invalid provider response' );
			}

			if ( ! empty( $result['success'] ) ) {
				wp_send_json_success( [
					'message' => __( 'Connection successful.', 'gfsms' ),
					'result'  => $result,
				] );
			}

			wp_send_json_error( [
				'message' => (string) ( $result['error_message'] ?? __( 'Connection failed.', 'gfsms' ) ),
				'result'  => $result,
			] );
		} catch ( \Throwable $e ) {
			if ( class_exists( Logger::class ) && method_exists( Logger::class, 'debug_message' ) ) {
				Logger::instance()->debug_message( 'Test SMS failed: ' . $e->getMessage() );
			}
			wp_send_json_error( [
				'message' => __( 'Unexpected error while sending SMS.', 'gfsms' ),
			], 500 );
		}
	}

	public static function ajax_fetch_senders(): void {
		check_ajax_referer( self::FETCH_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( GFSMS_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'gfsms' ) ], 403 );
		}

		$provider = self::make_provider();
		$result   = $provider->fetch_senders();

		if ( empty( $result['success'] ) || empty( $result['senders'] ) ) {
			wp_send_json_error( [
				'message' => (string) ( $result['message'] ?? __( 'Could not retrieve senders.', 'gfsms' ) ),
			] );
		}

		$clean = array_values( array_filter( array_map(
			static fn( $s ) => preg_replace( '/[^0-9+]/', '', (string) $s ),
			$result['senders']
		) ) );

		update_option( 'gfsms_cached_senders', $clean, false );

		wp_send_json_success( [
			'message' => sprintf( __( 'Fetched %d sender(s).', 'gfsms' ), count( $clean ) ),
			'senders' => $clean,
		] );
	}

	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------
	private static function current_tab( array $tabs ): string {
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
		return array_key_exists( $tab, $tabs ) ? $tab : 'general';
	}

	public static function cleanup_old_logs(): void {
		$settings = self::get_settings();
		Logger::instance()->cleanup( (int) ( $settings['log_retention_days'] ?? 180 ) );
	}

	public static function register_field_renderer( string $type, string $class ): void {
		if ( class_exists( $class ) ) {
			self::$field_renderers[ $type ] = $class;
		}
	}
}