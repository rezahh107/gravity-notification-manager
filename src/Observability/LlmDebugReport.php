<?php
/**
 * Deterministic privacy-safe LLM debug report exporter.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Observability;

/**
 * Produces a compact whitelist-only report from one already-sanitized event.
 */
final class LlmDebugReport {

	public const SCHEMA = 'gnm-llm-debug-v1';

	public static function build( OperationalEvent $event ): string {
		$data   = $event->to_array();
		$report = array(
			'schema'         => self::SCHEMA,
			'timestamp_utc'  => $data['created_at_utc'],
			'channel'        => $data['channel'],
			'execution_type' => $data['execution_type'],
			'status'         => $data['status'],
			'trace_id'       => $data['trace_id'],
			'attempt_index'  => $data['attempt_index'],
		);

		self::optional( $report, 'provider', $data['provider'] );

		$source = array();
		self::optional( $source, 'form_id', $data['form_id'] );
		self::optional( $source, 'feed_id', $data['feed_id'] );
		self::optional( $source, 'entry_id', $data['entry_id'] );
		self::optional( $source, 'feed_name', $data['feed_name'] );
		if ( array() !== $source ) {
			$report['source'] = $source;
		}

		self::optional( $report, 'sender', $data['sender'] );
		self::optional( $report, 'destination_masked', $data['destination'] );
		if ( array() !== $data['provider_references'] ) {
			$report['provider_references'] = $data['provider_references'];
		}
		$report['diagnostic'] = $data['diagnostic'];
		self::optional( $report, 'http_status', $data['http_status'] );

		$runtime = array();
		self::optional( $runtime, 'gnm', $data['plugin_version'] );
		self::optional( $runtime, 'WordPress', $data['wp_version'] );
		self::optional( $runtime, 'php', $data['php_version'] );
		if ( array() !== $runtime ) {
			$report['runtime'] = $runtime;
		}

		$json = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{"schema":"' . self::SCHEMA . '"}';
	}

	/** @param array<string, mixed> $target */
	private static function optional( array &$target, string $key, $value ): void {
		if ( null === $value || '' === $value ) {
			return;
		}
		$target[ $key ] = $value;
	}
}
