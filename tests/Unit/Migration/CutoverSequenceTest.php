<?php
/**
 * WU-08 no-dual-sender ordering tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Migration;

use GravityNotify\Migration\CutoverSequence;
use PHPUnit\Framework\TestCase;

final class CutoverSequenceTest extends TestCase {

	public function test_greenfield_cannot_enable_before_legacy_is_verified_inactive(): void {
		self::assertSame( CutoverSequence::LEGACY_DISABLED, CutoverSequence::enable_next( CutoverSequence::PREPARED, true, false ) );
		self::assertNull( CutoverSequence::enable_next( CutoverSequence::LEGACY_DISABLED, true, false ) );
		self::assertSame( CutoverSequence::GREENFIELD_ENABLED, CutoverSequence::enable_next( CutoverSequence::LEGACY_DISABLED, true, true ) );
	}

	public function test_failed_target_readiness_leaves_legacy_authority_unchanged(): void {
		self::assertNull( CutoverSequence::enable_next( CutoverSequence::PREPARED, false, false ) );
	}

	public function test_rollback_closes_greenfield_before_restoring_legacy(): void {
		self::assertSame( CutoverSequence::LEGACY_DISABLED, CutoverSequence::rollback_next( CutoverSequence::GREENFIELD_ENABLED ) );
		self::assertSame( CutoverSequence::PREPARED, CutoverSequence::rollback_next( CutoverSequence::LEGACY_DISABLED ) );
	}
}
