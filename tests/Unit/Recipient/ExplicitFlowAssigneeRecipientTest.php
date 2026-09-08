<?php
/**
 * Focused explicit-Step selector tests for Flow recipients.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Recipient;

use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Selector validation must fail closed before assignee access.
 */
final class ExplicitFlowAssigneeRecipientTest extends TestCase {

	/**
	 * @dataProvider invalid_selector_provider
	 *
	 * @param string $selector Invalid configured Step selector.
	 * @return void
	 */
	public function test_invalid_flow_step_selectors_fail_closed( string $selector ): void {
		$flow = new FakeFlowAssigneeReader(
			array(
				'available' => true,
				'reason'    => '',
				'assignees' => array( array( 'type' => 'user_id', 'id' => '41' ) ),
			)
		);
		$resolver = new RecipientResolver(
			new FakeEntryFieldReader( array() ),
			new FakeUserDirectory( array(), array(), array() ),
			$flow
		);

		$result = $resolver->resolve(
			array(
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE,
				'recipient_source_value' => $selector,
			)
		);

		self::assertSame( array(), $result->destinations() );
		self::assertSame( 'flow_step_selector_invalid', $result->skips()[0]['reason'] );
		self::assertNull( $flow->last_step_id );
	}

	/** @return array<string, array{0:string}> */
	public function invalid_selector_provider(): array {
		return array(
			'empty'         => array( '' ),
			'whitespace'    => array( '   ' ),
			'non-numeric'   => array( 'approval-step' ),
			'zero'          => array( '0' ),
			'negative'      => array( '-2' ),
			'decimal'       => array( '2.5' ),
			'plus-prefixed' => array( '+2' ),
			'leading-zero'  => array( '02' ),
			'overflow'      => array( '999999999999999999999999999999999999999' ),
		);
	}

	/**
	 * Empty selected-Step assignees remain a deterministic no-send result.
	 *
	 * @return void
	 */
	public function test_empty_selected_step_assignees_fail_closed(): void {
		$flow = new FakeFlowAssigneeReader(
			array(
				'available' => true,
				'reason'    => '',
				'assignees' => array(),
			)
		);
		$resolver = new RecipientResolver(
			new FakeEntryFieldReader( array() ),
			new FakeUserDirectory( array(), array(), array() ),
			$flow
		);

		$result = $resolver->resolve(
			array(
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE,
				'recipient_source_value' => '91',
			)
		);

		self::assertSame( 91, $flow->last_step_id );
		self::assertSame( array(), $result->destinations() );
		self::assertSame( 'flow_assignee_empty', $result->skips()[0]['reason'] );
	}
}
