<?php
/**
 * Mutable read-only admin configuration source for deterministic WU-06 tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Admin;

use GravityNotify\Admin\ConfigurationSourceInterface;
use GravityNotify\GravityForms\FeedRuleSchema;

/**
 * Exposes mutable fixtures while tracking reads; no topology mutation API exists.
 */
final class MutableAdminSource implements ConfigurationSourceInterface {

	/**
	 * Current test Feed fixtures.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $feeds;

	/**
	 * Current Flow placement fixtures, or null for unavailable Flow.
	 *
	 * @var array<int, array{step_id:int,step_name:string,feed_ids:array<int,int>}>|null
	 */
	public ?array $placements = array();

	/**
	 * Number of read operations performed.
	 *
	 * @var int
	 */
	public int $read_count = 0;

	/**
	 * Mutation sentinel; WU-06 must leave this at zero.
	 *
	 * @var int
	 */
	public int $mutation_count = 0;

	/**
	 * Seed one complete deterministic notification Feed fixture.
	 */
	public function __construct() {
		$this->feeds = array(
			array(
				'id'        => 11,
				'is_active' => true,
				'meta'      => array(
					'feedName'               => 'Registration Approved',
					'message'                => 'Approved',
					'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
					'recipient_source_value' => '+989121234567',
					'channel'                => FeedRuleSchema::CHANNEL_SMS,
					'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
				),
			),
		);
	}

	/**
	 * Return the deterministic Form fixture and record one read.
	 *
	 * @return array<int, array{id:int,title:string}>
	 */
	public function forms(): array {
		++$this->read_count;
		return array(
			array(
				'id'    => 4,
				'title' => 'Registration',
			),
		);
	}

	/**
	 * Return Feed fixtures for the selected Form and record one read.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function feeds( int $form_id ): array {
		++$this->read_count;
		return 4 === $form_id ? $this->feeds : array();
	}

	/**
	 * Return Flow placement fixtures and record one read.
	 *
	 * @param int             $form_id  Gravity Forms form ID.
	 * @param array<int, int> $feed_ids GNM Feed IDs being inspected.
	 * @return array<int, array{step_id:int,step_name:string,feed_ids:array<int,int>}>|null
	 */
	public function workflow_placements( int $form_id, array $feed_ids ): ?array {
		unset( $feed_ids );
		++$this->read_count;
		return 4 === $form_id ? $this->placements : array();
	}
}
