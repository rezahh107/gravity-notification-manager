<?php
/**
 * Localization identity and catalog regression contract.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Keeps active production UI on one text domain with complete Persian coverage.
 */
final class LocalizationContractTest extends TestCase {

	private const DOMAIN = 'gravity-notification-manager';

	/**
	 * Active production files that own current user-facing UI strings.
	 *
	 * @return array<int, string>
	 */
	private function active_ui_files(): array {
		$root = dirname( __DIR__, 3 );
		return array(
			$root . '/gravityflow-sms-ippanel.php',
			$root . '/src/Admin/AdminController.php',
			$root . '/src/Admin/AdminDefinition.php',
			$root . '/src/Admin/AdvisorModel.php',
			$root . '/src/Admin/Environment.php',
			$root . '/src/Admin/PointInspector.php',
			$root . '/src/Admin/ProviderManagerAdmin.php',
			$root . '/src/Admin/Settings.php',
			$root . '/src/Admin/WordPressConfigurationSource.php',
			$root . '/src/GravityForms/NotificationFeedAddOn.php',
			$root . '/src/GravityForms/ManualRetryHandler.php',
			$root . '/src/GravityFlow/NotificationFeedStep.php',
			$root . '/src/Presentation/OperationalPresentation.php',
		);
	}

	/** Plugin metadata and loader must advertise the canonical product identity. */
	public function test_plugin_metadata_and_loader_use_canonical_identity(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/gravityflow-sms-ippanel.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( 'Plugin Name: Gravity Notification Manager', $source );
		self::assertStringContainsString( 'Text Domain: ' . self::DOMAIN, $source );
		self::assertStringContainsString( 'Domain Path: /languages', $source );
		self::assertStringContainsString( "load_plugin_textdomain(\n\t\t'" . self::DOMAIN . "'", $source );
		self::assertStringNotContainsString( 'Text Domain: gfsms', $source );
	}

	/** Every active gettext call must use the canonical text domain. */
	public function test_active_gettext_calls_use_only_canonical_domain(): void {
		$call_pattern = '/\\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*([\'\"])(?:\\\\.|(?!\\1).)*\\1\\s*,\\s*([\'\"])([^\'\"]+)\\2/s';
		$total_calls  = 0;

		foreach ( $this->active_ui_files() as $file ) {
			$source = file_get_contents( $file );
			self::assertIsString( $source, $file );
			preg_match_all( $call_pattern, $source, $matches );
			foreach ( $matches[3] as $domain ) {
				++$total_calls;
				self::assertSame( self::DOMAIN, $domain, $file );
			}
		}

		self::assertGreaterThan( 50, $total_calls, 'The active UI gettext inventory unexpectedly shrank.' );
	}

	/** Known historical hard-coded UI sites must remain on gettext boundaries. */
	public function test_previous_hard_coded_ui_patterns_do_not_return(): void {
		$root       = dirname( __DIR__, 3 );
		$controller = file_get_contents( $root . '/src/Admin/AdminController.php' );
		$inspector  = file_get_contents( $root . '/src/Admin/PointInspector.php' );
		$presenter  = file_get_contents( $root . '/src/Presentation/OperationalPresentation.php' );
		self::assertIsString( $controller );
		self::assertIsString( $inspector );
		self::assertIsString( $presenter );

		self::assertStringNotContainsString( "self::header( 'Overview'", $controller );
		self::assertStringNotContainsString( "submit_button( 'Save Settings'", $controller );
		self::assertStringNotContainsString( "self::fail_request( 'Invalid Notification Point identifiers.'", $controller );
		self::assertStringNotContainsString( "'detail'      => 'This notification Feed is disabled.'", $inspector );
		self::assertStringNotContainsString( "'title'    => 'Notification Delivery'", $presenter );
		self::assertStringNotContainsString( "return '<p>Notification delivery state is unavailable for this Entry.</p>';", $presenter );
		self::assertStringNotContainsString( '<button type="submit" class="button">Retry notification now</button>', $presenter );
	}

	/** Every active source string has a maintained non-empty Persian translation. */
	public function test_persian_po_covers_every_active_source_string(): void {
		$catalog  = $this->parse_po( dirname( __DIR__, 3 ) . '/languages/' . self::DOMAIN . '-fa_IR.po' );
		$msgids   = $this->active_gettext_msgids();
		$msgids[] = 'Native multi-channel notifications for Gravity Forms and Gravity Flow.';
		$msgids   = array_values( array_unique( $msgids ) );

		self::assertGreaterThan( 50, count( $msgids ) );
		foreach ( $msgids as $msgid ) {
			self::assertArrayHasKey( $msgid, $catalog, 'Missing Persian catalog entry: ' . $msgid );
			self::assertNotSame( '', trim( $catalog[ $msgid ] ), 'Empty Persian translation: ' . $msgid );
		}
	}

	/** Canonical MO must be structurally valid and exactly match maintained PO entries. */
	public function test_persian_mo_is_valid_and_matches_po_catalog(): void {
		$root = dirname( __DIR__, 3 ) . '/languages/';
		$po   = $this->parse_po( $root . self::DOMAIN . '-fa_IR.po' );
		$mo   = $this->parse_mo( $root . self::DOMAIN . '-fa_IR.mo' );

		self::assertNotEmpty( $mo );
		foreach ( $po as $msgid => $translation ) {
			if ( '' === $msgid ) {
				continue;
			}
			self::assertArrayHasKey( $msgid, $mo, 'MO is missing PO msgid: ' . $msgid );
			self::assertSame( $translation, $mo[ $msgid ], 'MO translation differs from PO for: ' . $msgid );
		}
	}

	/** RTL UI keeps technical values explicitly isolated as LTR. */
	public function test_rtl_contract_preserves_ltr_technical_values(): void {
		$root = dirname( __DIR__, 3 );
		$css  = file_get_contents( $root . '/assets/admin/gnm-admin.css' );
		$ops  = file_get_contents( $root . '/src/Presentation/OperationalPresentation.php' );
		self::assertIsString( $css );
		self::assertIsString( $ops );
		self::assertStringContainsString( '.gnm-ltr', $css );
		self::assertStringContainsString( 'unicode-bidi: isolate', $css );
		self::assertStringContainsString( '[dir="rtl"] .gnm-admin .gnm-ltr', $css );
		self::assertStringContainsString( '<bdi dir="ltr">', $ops );
	}

	/**
	 * Extract literal gettext source strings from the active UI inventory.
	 *
	 * @return array<int, string>
	 */
	private function active_gettext_msgids(): array {
		$pattern = '/\\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*([\'\"])((?:\\\\.|(?!\\1).)*)\\1\\s*,\\s*([\'\"])' . self::DOMAIN . '\\3/s';
		$msgids  = array();
		foreach ( $this->active_ui_files() as $file ) {
			$source = file_get_contents( $file );
			self::assertIsString( $source, $file );
			preg_match_all( $pattern, $source, $matches );
			foreach ( $matches[2] as $raw ) {
				$msgids[] = stripcslashes( $raw );
			}
		}
		return $msgids;
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

	/**
	 * Parse a little-endian GNU MO catalog.
	 *
	 * @param string $file MO file.
	 * @return array<string, string>
	 */
	private function parse_mo( string $file ): array {
		$data = file_get_contents( $file );
		self::assertIsString( $data, $file );
		self::assertGreaterThanOrEqual( 28, strlen( $data ) );
		$header = unpack( 'Vmagic/Vrevision/Vcount/Voriginals/Vtranslations', substr( $data, 0, 20 ) );
		self::assertIsArray( $header );
		self::assertSame( 0x950412de, $header['magic'], 'Invalid little-endian GNU MO magic.' );
		self::assertSame( 0, $header['revision'], 'Unsupported GNU MO revision.' );

		$catalog = array();
		for ( $index = 0; $index < $header['count']; ++$index ) {
			$original   = unpack( 'Vlength/Voffset', substr( $data, $header['originals'] + ( $index * 8 ), 8 ) );
			$translated = unpack( 'Vlength/Voffset', substr( $data, $header['translations'] + ( $index * 8 ), 8 ) );
			self::assertIsArray( $original );
			self::assertIsArray( $translated );
			$msgid             = substr( $data, $original['offset'], $original['length'] );
			$msgstr            = substr( $data, $translated['offset'], $translated['length'] );
			$catalog[ $msgid ] = $msgstr;
		}
		return $catalog;
	}
}
