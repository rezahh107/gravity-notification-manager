<?php
/**
 * WP_List_Table for displaying SMS logs in admin.
 *
 * @package GFSMS\Admin
 */

declare( strict_types = 1 );

namespace GFSMS\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use GFSMS\Logging\Logger;

/**
 * Class Logs_Table
 *
 * Renders the SMS logs in a sortable, searchable table.
 */
final class Logs_Table extends \WP_List_Table {

	/**
	 * Register admin hooks for this table.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_gfsms_clear_logs', array( self::class, 'handle_clear_logs' ) );
	}

	/**
	 * Render the logs page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		$table = new self();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SMS Logs', 'gfsms' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="gfsms-logs" />
				<?php $table->search_box( __( 'Search', 'gfsms' ), 'gfsms_log_search' ); ?>
				<?php $table->display(); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gfsms_clear_logs" />
				<?php wp_nonce_field( 'gfsms_clear_logs' ); ?>
				<?php submit_button( __( 'Clear All Logs', 'gfsms' ), 'delete', 'clear_logs', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the clear logs action (POST).
	 *
	 * @return void
	 */
	public static function handle_clear_logs(): void {
		if ( ! current_user_can( GFSMS_CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'gfsms' ) );
		}

		check_admin_referer( 'gfsms_clear_logs' );

		global $wpdb;
		$table = Logger::table_name();

		// Use $wpdb->prepare with %i (identifier) for table name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );

		if ( false === $result ) {
			if ( class_exists( Logger::class ) ) {
				Logger::instance()->error(
					'Failed to clear SMS logs',
					array(
						'source' => 'logs_table',
						'table'  => $table,
					)
				);
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=gfsms-logs' ) );
		exit;
	}

	/**
	 * Prepare items for the table (fetch and paginate).
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		global $wpdb;
		$table        = Logger::table_name();
		$per_page     = 20;
		$current_page = max( 1, (int) $this->get_pagenum() );
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$where  = '1=1';
		$params = array();
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (recipient LIKE %s OR event_type LIKE %s OR error_code LIKE %s OR message LIKE %s)';
			$params = array( $like, $like, $like, $like );
		}

		// Build count query.
		$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset = ( $current_page - 1 ) * $per_page;
		// Build main query with LIMIT and OFFSET.
		$sql  = $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( $params, array( $per_page, $offset ) )
		);
		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->items           = is_array( $rows ) ? $rows : array();
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
			)
		);
	}

	/**
	 * Get the columns for the table.
	 *
	 * @return array
	 */
	public function get_columns(): array {
		return array(
			'id'         => __( 'ID', 'gfsms' ),
			'created_at' => __( 'Date', 'gfsms' ),
			'entry_id'   => __( 'Entry', 'gfsms' ),
			'step_id'    => __( 'Step', 'gfsms' ),
			'event_type' => __( 'Event', 'gfsms' ),
			'recipient'  => __( 'Recipient', 'gfsms' ),
			'status'     => __( 'Status', 'gfsms' ),
			'message'    => __( 'Message', 'gfsms' ),
			'error_code' => __( 'Error', 'gfsms' ),
		);
	}

	/**
	 * Default column value rendering.
	 *
	 * @param object $item        The row object.
	 * @param string $column_name The column name.
	 *
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		$value = $item->{$column_name} ?? '';
		if ( 'message' === $column_name ) {
			return esc_html( wp_trim_words( (string) $value, 12 ) );
		}
		return esc_html( (string) $value );
	}
}
