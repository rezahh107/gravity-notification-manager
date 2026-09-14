<?php
/**
 * Operational log localization/RTL packaging contract.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;

final class OperationalLogLocalizationTest extends TestCase {

	private const DOMAIN = 'gravity-notification-manager';

	public function test_operational_log_strings_are_in_persian_po_and_mo(): void {
		$root   = dirname( __DIR__, 3 );
		$po     = $this->parse_po( $root . '/languages/' . self::DOMAIN . '-fa_IR.po' );
		$mo     = $this->parse_mo( $root . '/languages/' . self::DOMAIN . '-fa_IR.mo' );
		$msgids = array_unique(
			array_merge(
				$this->gettext_msgids( $root . '/src/Admin/OperationalLogAdmin.php' ),
				$this->gettext_msgids( $root . '/src/Admin/AdminDefinition.php' )
			)
		);

		self::assertNotEmpty( $msgids );
		foreach ( $msgids as $msgid ) {
			self::assertArrayHasKey( $msgid, $po, 'Missing Persian PO entry: ' . $msgid );
			self::assertNotSame( '', trim( $po[ $msgid ] ), 'Empty Persian PO translation: ' . $msgid );
			self::assertArrayHasKey( $msgid, $mo, 'Missing Persian MO entry: ' . $msgid );
			self::assertSame( $po[ $msgid ], $mo[ $msgid ], 'PO/MO mismatch: ' . $msgid );
		}
	}

	public function test_operational_log_css_preserves_ltr_technical_islands_in_rtl(): void {
		$css = file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/gnm-operational-log.css' );
		self::assertIsString( $css );
		self::assertStringContainsString( '[dir="rtl"] .gnm-log .gnm-ltr', $css );
		self::assertStringContainsString( 'direction: ltr', $css );
		self::assertStringContainsString( 'unicode-bidi: isolate', $css );
	}

	/** @return array<int, string> */
	private function gettext_msgids( string $file ): array {
		$source  = file_get_contents( $file );
		$pattern = '/\\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*([\'\"])((?:\\\\.|(?!\\1).)*)\\1\\s*,\\s*([\'\"])' . self::DOMAIN . '\\3/s';
		self::assertIsString( $source );
		preg_match_all( $pattern, $source, $matches );
		return array_map( 'stripcslashes', $matches[2] );
	}

	/** @return array<string, string> */
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

	/** @return array<string, string> */
	private function parse_mo( string $file ): array {
		$data = file_get_contents( $file );
		self::assertIsString( $data, $file );
		self::assertGreaterThanOrEqual( 28, strlen( $data ) );
		$header = unpack( 'Vmagic/Vrevision/Vcount/Voriginals/Vtranslations', substr( $data, 0, 20 ) );
		self::assertIsArray( $header );
		self::assertSame( 0x950412de, $header['magic'] );
		$catalog = array();
		for ( $index = 0; $index < $header['count']; ++$index ) {
			$original   = unpack( 'Vlength/Voffset', substr( $data, $header['originals'] + ( $index * 8 ), 8 ) );
			$translated = unpack( 'Vlength/Voffset', substr( $data, $header['translations'] + ( $index * 8 ), 8 ) );
			self::assertIsArray( $original );
			self::assertIsArray( $translated );
			$catalog[ substr( $data, $original['offset'], $original['length'] ) ] = substr( $data, $translated['offset'], $translated['length'] );
		}
		return $catalog;
	}
}
