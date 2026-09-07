<?php
/**
 * Deterministic Gravity Forms Feed Add-On test double.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\GravityForms;

/**
 * Minimal parent used to load the greenfield Add-On without Gravity Forms.
 */
class GFFeedAddOnStub {

	/**
	 * Test Feed list.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $test_feeds = array();

	/**
	 * Return configured test feeds.
	 *
	 * @param int $form_id Ignored form ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_feeds( $form_id ) {
		unset( $form_id );
		return $this->test_feeds;
	}

	/**
	 * Configure deterministic test feeds.
	 *
	 * @param array<int, array<string, mixed>> $feeds Feeds.
	 * @return void
	 */
	public function set_test_feeds( array $feeds ): void {
		$this->test_feeds = $feeds;
	}
}
