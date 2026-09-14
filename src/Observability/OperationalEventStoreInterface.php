<?php
/**
 * Persistence boundary for bounded operational evidence.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Observability;

/**
 * Stores observational events without participating in delivery-state decisions.
 */
interface OperationalEventStoreInterface {

		/**
		 * Append.
		 *
		 * @param OperationalEvent $event Value.
		 * @return bool Return value.
		 */
	public function append( OperationalEvent $event ): bool;

	/**
	 * Read newest events for operator diagnosis.
	 *
	 * @param string               $channel Channel filter.
	 * @param array<string,string> $filters Safe filters: status, execution_type, trace_id.
	 * @param int                  $limit   Maximum rows.
	 * @return array<int, OperationalEvent>
	 */
	public function latest( string $channel, array $filters = array(), int $limit = 100 ): array;
}
