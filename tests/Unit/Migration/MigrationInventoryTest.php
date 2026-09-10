<?php
/**
 * WU-08 migration inventory tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Migration;

use GravityNotify\Migration\MigrationInventory;
use PHPUnit\Framework\TestCase;

final class MigrationInventoryTest extends TestCase {

	public function test_secret_values_are_never_returned_by_inventory(): void {
		$inventory = MigrationInventory::settings(
			array(
				'ippanel_api_key'         => 'do-not-leak-this-secret',
				'default_sender_number'   => '+982100000000',
				'use_queue'               => true,
				'retry_enabled'           => true,
			)
		);
		self::assertSame( MigrationInventory::MIGRATE_VALUE, $inventory['ippanel_api_key']['classification'] );
		self::assertTrue( $inventory['ippanel_api_key']['present'] );
		self::assertSame( MigrationInventory::DO_NOT_MIGRATE_RETIRE_LATER, $inventory['use_queue']['classification'] );
		self::assertStringNotContainsString( 'do-not-leak-this-secret', (string) json_encode( $inventory ) );
	}

	public function test_malformed_option_fails_closed(): void {
		$inventory = MigrationInventory::settings( 'bad' );
		self::assertSame( MigrationInventory::MANUAL_REQUIRED_AMBIGUOUS, $inventory['_option']['classification'] );
	}
}
