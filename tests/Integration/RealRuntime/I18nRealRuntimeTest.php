<?php
/**
 * Real WordPress localization loading assertions.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\RealRuntime;

use WP_UnitTestCase;

/**
 * Proves the bundled Persian MO is loadable through WordPress native i18n APIs.
 */
final class I18nRealRuntimeTest extends WP_UnitTestCase {

	private const DOMAIN = 'gravity-notification-manager';

	/** @var callable|null */
	private $locale_filter = null;

	/** Restore global translation state after each assertion. */
	protected function tearDown(): void {
		if ( null !== $this->locale_filter ) {
			remove_filter( 'determine_locale', $this->locale_filter, PHP_INT_MAX );
			remove_filter( 'locale', $this->locale_filter, PHP_INT_MAX );
			$this->locale_filter = null;
		}
		unload_textdomain( self::DOMAIN, true );
		parent::tearDown();
	}

	/** Persian locale loads the bundled canonical catalog and returns Persian UI. */
	public function test_bundled_persian_catalog_loads_through_wordpress(): void {
		$this->force_locale( 'fa_IR' );
		unload_textdomain( self::DOMAIN, true );

		self::assertTrue(
			load_plugin_textdomain(
				self::DOMAIN,
				false,
				dirname( GFSMS_PLUGIN_BASENAME ) . '/languages'
			)
		);

		self::assertSame( 'نمای کلی', __( 'Overview', self::DOMAIN ) );
		self::assertSame( 'ذخیره تنظیمات', __( 'Save Settings', self::DOMAIN ) );
		self::assertTrue( is_textdomain_loaded( self::DOMAIN ) );
	}

	/** English remains the source/default locale without a duplicate English catalog. */
	public function test_english_locale_uses_source_strings(): void {
		$this->force_locale( 'en_US' );
		unload_textdomain( self::DOMAIN, true );

		load_plugin_textdomain(
			self::DOMAIN,
			false,
			dirname( GFSMS_PLUGIN_BASENAME ) . '/languages'
		);

		self::assertSame( 'Overview', __( 'Overview', self::DOMAIN ) );
		self::assertSame( 'Save Settings', __( 'Save Settings', self::DOMAIN ) );
	}

	/**
	 * Force the request locale without requiring an installed core language pack.
	 *
	 * @param string $locale Locale code.
	 * @return void
	 */
	private function force_locale( string $locale ): void {
		$this->locale_filter = static fn(): string => $locale;
		add_filter( 'determine_locale', $this->locale_filter, PHP_INT_MAX );
		add_filter( 'locale', $this->locale_filter, PHP_INT_MAX );
	}
}