<?php
/**
 * Wpdb fake for literal SQL LIKE verification in the operational installer.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Observability;

/**
 * Models only the wpdb surface required by OperationalLogInstaller::install().
 */
final class OperationalInstallerWpdbFake {
	/**
	 * Database table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Ordered table names returned by SHOW TABLES LIKE.
	 *
	 * @var array<int, string>
	 */
	private array $tables;

	/**
	 * LIKE patterns passed through prepare().
	 *
	 * @var array<int, string>
	 */
	private array $prepared_like_patterns = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $tables Ordered table names.
	 */
	public function __construct( array $tables ) {
		$this->tables = array_values( $tables );
	}

	/** Return the charset/collation suffix used by dbDelta. */
	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * Escape SQL LIKE metacharacters exactly as wpdb::esc_like().
	 *
	 * @param string $text Raw pattern text.
	 * @return string Escaped LIKE pattern.
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Preserve the prepared query and arguments for deterministic evaluation.
	 *
	 * @param string $query SQL template.
	 * @param mixed  ...$args Placeholder values.
	 * @return string Serialized prepared representation.
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 'SHOW TABLES LIKE %s' === $query && isset( $args[0] ) && is_string( $args[0] ) ) {
			$this->prepared_like_patterns[] = $args[0];
		}

		return (string) json_encode(
			array(
				'query' => $query,
				'args'  => $args,
			)
		);
	}

	/**
	 * Return the first ordered table matching the prepared LIKE pattern.
	 *
	 * @param string $prepared Serialized prepared representation.
	 * @return string|null First matching table.
	 */
	public function get_var( string $prepared ): ?string {
		$data = json_decode( $prepared, true );
		if ( ! is_array( $data ) || ! isset( $data['args'][0] ) || ! is_string( $data['args'][0] ) ) {
			return null;
		}

		return $this->first_like_match( $data['args'][0] );
	}

	/**
	 * Return the first table matching a SQL LIKE pattern.
	 *
	 * @param string $pattern SQL LIKE pattern.
	 * @return string|null First matching table.
	 */
	public function first_like_match( string $pattern ): ?string {
		foreach ( $this->tables as $table ) {
			if ( $this->matches_like_pattern( $pattern, $table ) ) {
				return $table;
			}
		}

		return null;
	}

	/**
	 * Return the most recent LIKE pattern passed to prepare().
	 *
	 * @return string|null Prepared LIKE pattern.
	 */
	public function last_prepared_like_pattern(): ?string {
		if ( array() === $this->prepared_like_patterns ) {
			return null;
		}

		return $this->prepared_like_patterns[ count( $this->prepared_like_patterns ) - 1 ];
	}

	/**
	 * Evaluate %, _ and backslash escaping with the SQL LIKE semantics needed by this regression.
	 *
	 * @param string $pattern LIKE pattern.
	 * @param string $value Candidate table name.
	 * @return bool Whether the candidate matches.
	 */
	private function matches_like_pattern( string $pattern, string $value ): bool {
		$regex  = '';
		$length = strlen( $pattern );

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $pattern[ $index ];
			if ( '\\' === $character && $index + 1 < $length ) {
				++$index;
				$regex .= preg_quote( $pattern[ $index ], '~' );
				continue;
			}
			if ( '%' === $character ) {
				$regex .= '.*';
				continue;
			}
			if ( '_' === $character ) {
				$regex .= '.';
				continue;
			}
			$regex .= preg_quote( $character, '~' );
		}

		return 1 === preg_match( '~\\A' . $regex . '\\z~D', $value );
	}
}
