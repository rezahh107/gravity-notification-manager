<?php
/**
 * Deterministic WU-07 Entry Detail KSES regression tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Presentation;

use GravityNotify\Presentation\OperationalPresentation;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Proves the production Entry Detail sanitizer is local and narrowly scoped.
 */
final class OperationalPresentationKsesTest extends TestCase {

	/**
	 * T-01-ALLOWLIST-SHAPE: preserve every current Entry Detail structure only.
	 *
	 * @return void
	 */
	public function test_entry_detail_allowlist_matches_current_operational_markup(): void {
		$allowed = $this->entry_detail_allowed_html();

		self::assertSame(
			array( 'div', 'p', 'strong', 'section', 'h4', 'bdi', 'dl', 'dt', 'dd', 'form', 'input', 'button' ),
			array_keys( $allowed )
		);
		self::assertSame(
			array(
				'class'     => true,
				'aria-live' => true,
			),
			$allowed['div']
		);
		self::assertSame( array( 'class' => true ), $allowed['p'] );
		self::assertSame( array(), $allowed['strong'] );
		self::assertSame( array( 'class' => true ), $allowed['section'] );
		self::assertSame( array(), $allowed['h4'] );
		self::assertSame( array( 'dir' => true ), $allowed['bdi'] );
		self::assertSame( array(), $allowed['dl'] );
		self::assertSame( array(), $allowed['dt'] );
		self::assertSame( array(), $allowed['dd'] );
		self::assertSame(
			array(
				'method' => true,
				'action' => true,
			),
			$allowed['form']
		);
		self::assertSame(
			array(
				'type'  => true,
				'name'  => true,
				'value' => true,
			),
			$allowed['input']
		);
		self::assertSame(
			array(
				'type'  => true,
				'class' => true,
			),
			$allowed['button']
		);

		self::assertArrayNotHasKey( 'script', $allowed );
		self::assertArrayNotHasKey( 'iframe', $allowed );
		self::assertArrayNotHasKey( 'style', $allowed );
		foreach ( $allowed as $attributes ) {
			self::assertArrayNotHasKey( 'onclick', $attributes );
			self::assertArrayNotHasKey( 'onerror', $attributes );
			self::assertArrayNotHasKey( 'style', $attributes );
		}
	}

	/**
	 * T-01-ALLOWLIST-SHAPE: production render boundary must call local wp_kses().
	 *
	 * @return void
	 */
	public function test_entry_detail_renderer_uses_local_kses_instead_of_post_or_raw_echo(): void {
		$method = new ReflectionMethod( OperationalPresentation::class, 'render_entry_detail_meta_box' );
		$file   = file( (string) $method->getFileName() );
		self::assertIsArray( $file );

		$source = implode(
			'',
			array_slice(
				$file,
				$method->getStartLine() - 1,
				$method->getEndLine() - $method->getStartLine() + 1
			)
		);

		self::assertStringContainsString( 'wp_kses( $html, self::entry_detail_allowed_html() )', $source );
		self::assertStringNotContainsString( 'wp_kses_post', $source );
		self::assertStringNotContainsString( 'echo $html', $source );
	}

	/**
	 * Read the private production allow-list without adding a public API.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function entry_detail_allowed_html(): array {
		$method = new ReflectionMethod( OperationalPresentation::class, 'entry_detail_allowed_html' );
		$method->setAccessible( true );
		$allowed = $method->invoke( null );
		self::assertIsArray( $allowed );
		return $allowed;
	}
}
