<?php
/**
 * Owner-deferred Bale phone-number presentation contract.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\AdvisorModel;
use GravityNotify\Admin\BaleDeliveryMode;
use GravityNotify\Admin\PointStatus;
use PHPUnit\Framework\TestCase;

/**
 * Proves the deferred Bale phone-number capability is stated truthfully and never implemented.
 */
final class DeferredBaleModeContractTest extends TestCase {

	private const DOMAIN = 'gravity-notification-manager';

	/** Both Bale recipient modes are presented, with the deferred one marked unavailable. */
	public function test_bale_modes_state_current_availability_truthfully(): void {
		$modes = BaleDeliveryMode::modes();
		self::assertCount( 2, $modes );

		$chat  = $this->mode( $modes, BaleDeliveryMode::CHAT_ID );
		$phone = $this->mode( $modes, BaleDeliveryMode::PHONE_NUMBER );

		self::assertTrue( $chat['available'] );
		self::assertSame( PointStatus::CONFIGURED, $chat['state'] );
		self::assertSame( 'Bale delivery by chat ID or username', $chat['label'] );

		self::assertFalse( $phone['available'] );
		self::assertSame( PointStatus::DISABLED, $phone['state'] );
		self::assertSame( 'Bale delivery by phone number', $phone['label'] );
		self::assertSame( 'In development — currently unavailable', $phone['status_label'] );
	}

	/** The deferred mode never promises a delivery date. */
	public function test_deferred_mode_makes_no_availability_promise(): void {
		$sources = array(
			file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/BaleDeliveryMode.php' ),
			file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/AdvisorModel.php' ),
			file_get_contents( dirname( __DIR__, 3 ) . '/languages/' . self::DOMAIN . '-fa_IR.po' ),
		);
		foreach ( $sources as $source ) {
			self::assertIsString( $source );
			self::assertStringNotContainsStringIgnoringCase( 'coming soon', $source );
			self::assertStringNotContainsString( 'به‌زودی', $source );
		}
	}

	/** The deferred presentation is rendered as text only: it owns no control and no action. */
	public function test_deferred_mode_presentation_is_non_interactive(): void {
		$section = $this->controller_section(
			'private static function render_bale_modes_panel',
			'private static function render_bale_test_notice'
		);
		$model = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/BaleDeliveryMode.php' );
		self::assertIsString( $model );

		self::assertStringContainsString( 'BaleDeliveryMode::modes()', $section );
		self::assertStringContainsString( 'gnm-mode--unavailable', $section );

		foreach ( array( $section, $model ) as $subject ) {
			self::assertStringNotContainsString( '<form', $subject );
			self::assertStringNotContainsString( '<input', $subject );
			self::assertStringNotContainsString( '<select', $subject );
			self::assertStringNotContainsString( '<button', $subject );
			self::assertStringNotContainsString( 'submit_button', $subject );
			self::assertStringNotContainsString( 'wp_nonce_field', $subject );
			self::assertStringNotContainsString( 'admin-post.php', $subject );
			self::assertStringNotContainsString( 'admin_post_', $subject );
			self::assertStringNotContainsString( 'wp_remote_', $subject );
			self::assertStringNotContainsString( 'update_option', $subject );
		}
	}

	/** No Safir or phone-number Bale transport was guessed into the runtime. */
	public function test_no_safir_or_phone_number_bale_transport_exists(): void {
		$root      = dirname( __DIR__, 3 );
		$admin     = $this->php_files( $root . '/src/Admin' );
		$transport = $this->php_files( $root . '/src/Delivery/Bale' );
		self::assertNotEmpty( $admin );
		self::assertNotEmpty( $transport );

		foreach ( array_merge( $admin, $transport ) as $file ) {
			$source = file_get_contents( $file );
			self::assertIsString( $source, $file );
			self::assertStringNotContainsStringIgnoringCase( 'safir', $source, $file );
		}

		foreach ( $transport as $file ) {
			$source = file_get_contents( $file );
			self::assertIsString( $source, $file );
			self::assertStringNotContainsStringIgnoringCase( 'phone', $source, $file );
		}
	}

	/**
	 * List the PHP sources in one directory.
	 *
	 * @param string $directory Directory path.
	 * @return array<int, string>
	 */
	private function php_files( string $directory ): array {
		$files = glob( $directory . '/*.php' );
		return is_array( $files ) ? $files : array();
	}

	/** The existing Bot API chat_id/username mode stays configurable and explicitly testable. */
	public function test_current_bale_chat_destination_mode_remains_available(): void {
		$settings = $this->controller_section(
			'public static function render_settings',
			'public static function render_advisor'
		);
		$control = $this->controller_section(
			'private static function render_bale_test_control',
			'private static function render_bale_modes_panel'
		);

		self::assertStringContainsString( "secret_field( 'bale_bot_token'", $settings );
		self::assertStringContainsString( 'render_bale_modes_panel()', $settings );
		self::assertStringContainsString( 'render_bale_test_control()', $settings );
		self::assertStringContainsString( 'AdminDefinition::TEST_BALE_ACTION', $control );
		self::assertStringContainsString( '123456789 or @channel', $control );
	}

	/** Advisor repeats the same recipient-mode truth without inventing an operation. */
	public function test_advisor_bale_guidance_matches_current_availability(): void {
		$cards  = AdvisorModel::build( array( 'bale' => true ), array(), true, true, true );
		$answer = '';
		foreach ( $cards as $card ) {
			if ( 'bale-recipients' === ( $card['id'] ?? null ) ) {
				$answer = (string) $card['answer'];
				self::assertSame( AdvisorModel::ACTION_SETTINGS, $card['action'] );
			}
		}

		self::assertNotSame( '', $answer, 'Advisor is missing the Bale recipient-mode card.' );
		self::assertStringContainsString( 'numeric chat ID or an @username destination', $answer );
		self::assertStringContainsString( 'in development and currently unavailable', $answer );
	}

	/** Every deferred-mode string is translatable and carries the maintained Persian text. */
	public function test_deferred_mode_strings_are_translatable_and_translated(): void {
		$root   = dirname( __DIR__, 3 );
		$source = file_get_contents( $root . '/src/Admin/BaleDeliveryMode.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( "__( 'Bale delivery by phone number', '" . self::DOMAIN . "' )", $source );
		self::assertStringContainsString( "__( 'In development — currently unavailable', '" . self::DOMAIN . "' )", $source );

		$po = $this->parse_po( $root . '/languages/' . self::DOMAIN . '-fa_IR.po' );
		self::assertSame( 'ارسال بله با شماره موبایل', $po['Bale delivery by phone number'] ?? '' );
		self::assertSame( 'در حال توسعه — فعلاً در دسترس نیست', $po['In development — currently unavailable'] ?? '' );
	}

	/**
	 * Find one mode by identifier.
	 *
	 * @param array<int, array<string, mixed>> $modes Modes.
	 * @param string                           $id    Mode identifier.
	 * @return array<string, mixed>
	 */
	private function mode( array $modes, string $id ): array {
		foreach ( $modes as $mode ) {
			if ( ( $mode['id'] ?? null ) === $id ) {
				return $mode;
			}
		}
		self::fail( 'Bale delivery mode not found: ' . $id );
	}

	/**
	 * Return one bounded AdminController method section.
	 *
	 * @param string $start_marker Start marker.
	 * @param string $end_marker   End marker.
	 * @return string
	 */
	private function controller_section( string $start_marker, string $end_marker ): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/AdminController.php' );
		self::assertIsString( $source );
		$start = strpos( $source, $start_marker );
		$end   = strpos( $source, $end_marker, false === $start ? 0 : $start );
		self::assertIsInt( $start );
		self::assertIsInt( $end );
		self::assertGreaterThan( $start, $end );
		return substr( $source, $start, $end - $start );
	}

	/**
	 * Parse maintained single-line PO entries.
	 *
	 * @param string $file PO file.
	 * @return array<string, string>
	 */
	private function parse_po( string $file ): array {
		$source = file_get_contents( $file );
		self::assertIsString( $source, $file );
		preg_match_all( '/^msgid "((?:[^"\\\\]|\\\\.)*)"\\Rmsgstr "((?:[^"\\\\]|\\\\.)*)"/m', $source, $matches, PREG_SET_ORDER );
		$catalog = array();
		foreach ( $matches as $match ) {
			$catalog[ stripcslashes( $match[1] ) ] = stripcslashes( $match[2] );
		}
		return $catalog;
	}
}
