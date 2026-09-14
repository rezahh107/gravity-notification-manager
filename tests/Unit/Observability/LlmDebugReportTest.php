<?php
/**
 * LLM debug report privacy and determinism tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Observability;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Observability\LlmDebugReport;
use GravityNotify\Observability\OperationalContext;
use GravityNotify\Observability\OperationalEvent;
use PHPUnit\Framework\TestCase;

/** LlmDebugReportTest implementation. */
final class LlmDebugReportTest extends TestCase {

	/**
	 * Test report is deterministic whitelisted and redacted by model boundary.
	 */
	public function test_report_is_deterministic_whitelisted_and_redacted_by_model_boundary(): void {
		$event = new OperationalEvent(
			array(
				'created_at_utc'      => '2026-09-14 12:00:00',
				'trace_id'            => '33333333-3333-4333-8333-333333333333',
				'attempt_index'       => 2,
				'channel'             => 'sms',
				'execution_type'      => OperationalContext::EXECUTION_RETRY,
				'status'              => AttemptStatus::FAILED,
				'provider'            => 'ippanel',
				'form_id'             => 5,
				'feed_id'             => 7,
				'entry_id'            => 10,
				'feed_name'           => 'Case update',
				'sender'              => '+982100000000',
				'destination'         => '+9891*****4567',
				'provider_references' => array( 'safe-ref-9', 'Bearer-secret-must-drop because spaces' ),
				'diagnostic'          => 'http_rejection',
				'http_status'         => 403,
				'plugin_version'      => '3.3.0',
				'wp_version'          => '7.1',
				'php_version'         => '8.3.0',
				'api_key'             => 'super-secret-api-key',
				'raw_body'            => '{"token":"do-not-export"}',
				'message'             => 'private message body',
			)
		);

		$first  = LlmDebugReport::build( $event );
		$second = LlmDebugReport::build( $event );
		self::assertSame( $first, $second );
		self::assertStringContainsString( 'gnm-llm-debug-v1', $first );
		self::assertStringContainsString( 'http_rejection', $first );
		self::assertStringContainsString( '+9891*****4567', $first );
		self::assertStringNotContainsString( '+989121234567', $first );
		self::assertStringNotContainsString( 'super-secret-api-key', $first );
		self::assertStringNotContainsString( 'do-not-export', $first );
		self::assertStringNotContainsString( 'private message body', $first );
		self::assertStringNotContainsString( 'Bearer-secret-must-drop because spaces', $first );
	}

	/**
	 * Test absent evidence is omitted instead of invented.
	 */
	public function test_absent_evidence_is_omitted_instead_of_invented(): void {
		$event  = new OperationalEvent(
			array(
				'created_at_utc' => '2026-09-14 12:00:00',
				'trace_id'       => '44444444-4444-4444-8444-444444444444',
				'channel'        => 'bale',
				'execution_type' => OperationalContext::EXECUTION_TEST,
				'status'         => AttemptStatus::AMBIGUOUS,
				'diagnostic'     => 'transport_error',
			)
		);
		$report = LlmDebugReport::build( $event );
		self::assertStringNotContainsString( 'http_status', $report );
		self::assertStringNotContainsString( 'provider_references', $report );
		self::assertStringNotContainsString( 'destination_masked', $report );
	}
}
