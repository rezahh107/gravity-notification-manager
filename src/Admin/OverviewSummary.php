<?php
/**
 * Operational Overview summary derivation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Counts bounded Notification Point states without owning any state itself.
 */
final class OverviewSummary {

	/**
	 * Derive decision-useful counts from current Point inspection results.
	 *
	 * @param array<int, array<string, mixed>> $points Current authoritative Point results.
	 * @return array<string, int>
	 */
	public static function from_points( array $points ): array {
		$summary = array(
			'total'          => count( $points ),
			'configured'     => 0,
			'needs_setup'    => 0,
			'disabled'       => 0,
			'not_applicable' => 0,
		);

		foreach ( $points as $point ) {
			$state = $point['state'] ?? null;
			if ( PointStatus::CONFIGURED === $state ) {
				++$summary['configured'];
			} elseif ( PointStatus::NEEDS_SETUP === $state ) {
				++$summary['needs_setup'];
			} elseif ( PointStatus::DISABLED === $state ) {
				++$summary['disabled'];
			} elseif ( PointStatus::NOT_APPLICABLE === $state ) {
				++$summary['not_applicable'];
			}
		}

		return $summary;
	}
}
