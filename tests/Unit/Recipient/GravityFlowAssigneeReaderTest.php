<?php
/**
 * Tests for exact-Step Gravity Flow assignee access.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Recipient;

use GravityNotify\Recipient\Native\GravityFlowAssigneeReader;
use PHPUnit\Framework\TestCase;

/** Minimal assignee object stub. */
final class GravityFlowAssigneeIdentityStub {
	/** @var string */
	private string $type;
	/** @var string */
	private string $id;

	/**
	 * @param string $type Assignee type.
	 * @param string $id Assignee identity.
	 */
	public function __construct( string $type, string $id ) {
		$this->type = $type;
		$this->id   = $id;
	}

	/** @return string */
	public function get_type(): string {
		return $this->type;
	}

	/** @return string */
	public function get_id(): string {
		return $this->id;
	}
}

/** Minimal Step stub with configurable assignee collection. */
final class GravityFlowAssigneeStepStub {
	/** @var mixed */
	private $assignees;

	/** @param mixed $assignees Assignee collection. */
	public function __construct( $assignees ) {
		$this->assignees = $assignees;
	}

	/** @return mixed */
	public function get_assignees() {
		return $this->assignees;
	}
}

/** Step stub whose assignee API throws. */
final class GravityFlowThrowingAssigneeStepStub {
	/** @return array */
	public function get_assignees(): array {
		throw new \RuntimeException( 'synthetic assignee API failure' );
	}
}

/** Global Gravity_Flow_API stand-in exposing exact and old current-Step paths. */
final class GravityFlowExactStepApiStub {
	/** @var array<int, object> */
	private static array $steps = array();
	/** @var object|null */
	private static ?object $current_step = null;
	/** @var int|null */
	public static ?int $last_step_id = null;
	/** @var array|null */
	public static ?array $last_entry = null;
	/** @var int */
	public static int $current_step_calls = 0;

	/** @param int $form_id Form ID. */
	public function __construct( int $form_id ) {
		unset( $form_id );
	}

	/**
	 * @param array<int, object> $steps Exact Step map.
	 * @param object|null        $current_step Current workflow Step.
	 * @return void
	 */
	public static function configure( array $steps, ?object $current_step ): void {
		self::$steps              = $steps;
		self::$current_step       = $current_step;
		self::$last_step_id       = null;
		self::$last_entry         = null;
		self::$current_step_calls = 0;
	}

	/**
	 * @param int   $step_id Explicit Step ID.
	 * @param array $entry Entry context.
	 * @return object|null
	 */
	public function get_step( int $step_id, array $entry ): ?object {
		self::$last_step_id = $step_id;
		self::$last_entry   = $entry;
		return self::$steps[ $step_id ] ?? null;
	}

	/**
	 * Old heuristic path retained only so regression coverage proves it is unused.
	 *
	 * @param array $entry Entry context.
	 * @return object|null
	 */
	public function get_current_step( array $entry ): ?object {
		unset( $entry );
		++self::$current_step_calls;
		return self::$current_step;
	}
}

/** Exact-Step reader regression tests. */
final class GravityFlowAssigneeReaderTest extends TestCase {
	/** @return void */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( 'Gravity_Flow_API', false ) ) {
			class_alias( GravityFlowExactStepApiStub::class, 'Gravity_Flow_API' );
		}
	}

	/**
	 * Configured business Step wins over the current Notification Feed Step.
	 *
	 * @return void
	 */
	public function test_exact_selected_step_is_used_instead_of_current_notification_step(): void {
		$current_notification_step = new GravityFlowAssigneeStepStub(
			array( new GravityFlowAssigneeIdentityStub( 'user_id', '999' ) )
		);
		$selected_business_step = new GravityFlowAssigneeStepStub(
			array( new GravityFlowAssigneeIdentityStub( 'user_id', '44' ) )
		);
		GravityFlowExactStepApiStub::configure( array( 77 => $selected_business_step ), $current_notification_step );

		$entry  = array( 'id' => 501, 'form_id' => 9 );
		$result = ( new GravityFlowAssigneeReader() )->read( $entry, array( 'id' => 9 ), 77 );

		self::assertTrue( $result['available'] );
		self::assertSame( array( array( 'type' => 'user_id', 'id' => '44' ) ), $result['assignees'] );
		self::assertSame( 77, GravityFlowExactStepApiStub::$last_step_id );
		self::assertSame( $entry, GravityFlowExactStepApiStub::$last_entry );
		self::assertSame( 0, GravityFlowExactStepApiStub::$current_step_calls );
	}

	/**
	 * Selected-Step lookup and assignee API/collection failures fail closed.
	 *
	 * @return void
	 */
	public function test_selected_step_unavailable_states_fail_closed(): void {
		$reader = new GravityFlowAssigneeReader();
		$entry  = array( 'id' => 502, 'form_id' => 9 );
		$form   = array( 'id' => 9 );

		GravityFlowExactStepApiStub::configure( array(), null );
		$missing = $reader->read( $entry, $form, 81 );
		self::assertFalse( $missing['available'] );
		self::assertSame( 'flow_step_unavailable', $missing['reason'] );

		GravityFlowExactStepApiStub::configure( array( 82 => new \stdClass() ), null );
		$no_assignee_api = $reader->read( $entry, $form, 82 );
		self::assertFalse( $no_assignee_api['available'] );
		self::assertSame( 'flow_assignee_api_unavailable', $no_assignee_api['reason'] );

		GravityFlowExactStepApiStub::configure( array( 83 => new GravityFlowAssigneeStepStub( 'not-an-array' ) ), null );
		$bad_collection = $reader->read( $entry, $form, 83 );
		self::assertFalse( $bad_collection['available'] );
		self::assertSame( 'flow_assignee_api_unavailable', $bad_collection['reason'] );

		GravityFlowExactStepApiStub::configure( array( 84 => new GravityFlowThrowingAssigneeStepStub() ), null );
		$throwing_collection = $reader->read( $entry, $form, 84 );
		self::assertFalse( $throwing_collection['available'] );
		self::assertSame( 'flow_assignee_api_unavailable', $throwing_collection['reason'] );

		GravityFlowExactStepApiStub::configure( array( 85 => new GravityFlowAssigneeStepStub( array() ) ), null );
		$empty = $reader->read( $entry, $form, 85 );
		self::assertTrue( $empty['available'] );
		self::assertSame( array(), $empty['assignees'] );
	}
}
