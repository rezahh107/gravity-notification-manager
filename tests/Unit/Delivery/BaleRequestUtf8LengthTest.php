<?php
/**
 * Bale UTF-8 request length validation tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Delivery;

use GravityNotify\Delivery\Bale\BaleRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves Bale text length is counted as valid UTF-8 code points.
 */
final class BaleRequestUtf8LengthTest extends TestCase {

	/**
	 * Multibyte text below the character limit is accepted regardless of byte size.
	 *
	 * @return void
	 */
	public function test_multibyte_text_below_character_limit_is_accepted(): void {
		$text    = str_repeat( 'ژ', 3000 );
		$request = new BaleRequest( $this->target(), $text );

		self::assertSame( $text, $request->text() );
	}

	/**
	 * Exactly 4096 Persian UTF-8 code points are accepted.
	 *
	 * @return void
	 */
	public function test_exact_utf8_boundary_is_accepted(): void {
		$text    = str_repeat( 'ژ', 4096 );
		$request = new BaleRequest( $this->target(), $text );

		self::assertSame( $text, $request->text() );
	}

	/**
	 * More than 4096 Persian UTF-8 code points are rejected locally.
	 *
	 * @return void
	 */
	public function test_utf8_overflow_is_rejected(): void {
		$this->assert_invalid_text_rejected( str_repeat( 'ژ', 4097 ) );
	}

	/**
	 * ASCII boundaries retain the existing 1-4096 behavior.
	 *
	 * @return void
	 */
	public function test_ascii_boundaries_are_preserved(): void {
		self::assertSame( 'a', ( new BaleRequest( $this->target(), 'a' ) )->text() );

		$maximum = str_repeat( 'a', 4096 );
		self::assertSame( $maximum, ( new BaleRequest( $this->target(), $maximum ) )->text() );

		$this->assert_invalid_text_rejected( '' );
		$this->assert_invalid_text_rejected( str_repeat( 'a', 4097 ) );
	}

	/**
	 * Malformed UTF-8 remains inside the existing request-validation exception boundary.
	 *
	 * @return void
	 */
	public function test_malformed_utf8_is_rejected(): void {
		$this->assert_invalid_text_rejected( "\xC3\x28" );
	}

	/**
	 * Newlines count as characters in the selected PCRE UTF-8 path.
	 *
	 * @return void
	 */
	public function test_newline_is_counted_as_a_character(): void {
		$maximum = str_repeat( 'ژ', 4095 ) . "\n";
		self::assertSame( $maximum, ( new BaleRequest( $this->target(), $maximum ) )->text() );

		$this->assert_invalid_text_rejected( str_repeat( 'ژ', 4096 ) . "\n" );
	}

	/**
	 * Assert invalid Bale text uses the existing InvalidArgumentException boundary.
	 *
	 * @param string $text Invalid text fixture.
	 * @return void
	 */
	private function assert_invalid_text_rejected( string $text ): void {
		try {
			new BaleRequest( $this->target(), $text );
			self::fail( 'Expected BaleRequest to reject invalid text.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertSame(
				'Bale request requires a resolved target and 1-4096 characters of text.',
				$exception->getMessage()
			);
		}
	}

	/**
	 * Synthetic target; no real Bale destination is used.
	 *
	 * @return string
	 */
	private function target(): string {
		return implode( '', array( '@', 'unit', '_test', '_target' ) );
	}
}
