<?php
/**
 * Deterministic Gravity Flow Feed-Step base test double.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\GravityFlow;

/**
 * Models only the verified public Feed-Step behaviors WU-04 relies upon.
 */
abstract class GravityFlowStepFeedAddOnStub {

	/**
	 * Feed Add-On class name supplied by the extending integration.
	 *
	 * @var string
	 */
	protected $_class_name = '';

	/**
	 * Selected feed IDs.
	 *
	 * @var array<int, int>
	 */
	private array $selected_feed_ids = array();

	/**
	 * Current form.
	 *
	 * @var array
	 */
	private array $form = array();

	/**
	 * Current entry.
	 *
	 * @var array
	 */
	private array $entry = array();

	/**
	 * Configure current form/entry and selected Feed IDs.
	 *
	 * @param array           $form Form.
	 * @param array           $entry Entry.
	 * @param array<int, int> $selected_feed_ids Selected feed IDs.
	 * @return void
	 */
	public function configure_test_context( array $form, array $entry, array $selected_feed_ids ): void {
		$this->form              = $form;
		$this->entry             = $entry;
		$this->selected_feed_ids = $selected_feed_ids;
	}

	/**
	 * Expose the configured Add-On class.
	 *
	 * @return string
	 */
	public function get_feed_add_on_class_name() {
		return $this->_class_name;
	}

	/**
	 * Reuse existing Add-On feeds.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_feeds() {
		$class  = $this->get_feed_add_on_class_name();
		$add_on = $class::get_instance();
		return $add_on->get_feeds( (int) ( $this->form['id'] ?? 0 ) );
	}

	/**
	 * Simulate the verified Gravity Flow process contract.
	 *
	 * Selected Feed conditions are supplied by the test as an `condition_met`
	 * flag so no parallel GNM condition engine is involved.
	 *
	 * @return bool Step completion.
	 */
	public function process() {
		$class  = $this->get_feed_add_on_class_name();
		$add_on = $class::get_instance();

		foreach ( $this->get_feeds() as $feed ) {
			if ( ! in_array( (int) $feed['id'], $this->selected_feed_ids, true ) ) {
				continue;
			}

			if ( false === ( $feed['condition_met'] ?? true ) ) {
				continue;
			}

			$add_on->process_feed( $feed, $this->entry, $this->form );
		}

		// The verified Feed-Step base completes independently from delivery status.
		return true;
	}

	/**
	 * Model the supported ordinary-submit interception result.
	 *
	 * @param array<int, array<string, mixed>> $feeds Ordinary Add-On feed list.
	 * @return array<int, array<string, mixed>>
	 */
	public function intercept_submission_feeds( array $feeds ): array {
		return array_values(
			array_filter(
				$feeds,
				fn( array $feed ): bool => ! in_array( (int) $feed['id'], $this->selected_feed_ids, true )
			)
		);
	}
}
