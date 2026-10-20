<?php
/**
 * $wpdb double holding order rows, for the atomic status and claim tests.
 *
 * A copy of core's `tests/Unit/SecAtomicOrderFakeDb.php`, which is still there
 * for core's own atomic-order tests: the refund tests that moved here with the
 * gateways need the same double, and the two are meant to stay comparable. The
 * filename is kept for that reason. Nothing in it is specific to this plugin —
 * it imitates MySQL, not WopCart.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

/**
 * Orders keyed by ID, with conditional updates evaluated like MySQL would.
 */
final class SecAtomicOrderFakeDb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';

	/**
	 * Stored order rows by ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $orders = array();

	/**
	 * Every statement seen, in order.
	 *
	 * @var string[]
	 */
	public array $log = array();

	/**
	 * Called once before the next meta compare-and-swap, to simulate a concurrent writer.
	 *
	 * @var callable|null
	 */
	public $before_cas = null;

	/**
	 * Interpolate placeholders.
	 *
	 * @param string $query   Query.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return string
	 */
	public function prepare( string $query, mixed ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	/**
	 * Return one order row.
	 *
	 * @param string $query  Query.
	 * @param string $output Output type, ignored.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $query, string $output = 'OBJECT' ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches wpdb's signature.
		$this->log[] = $query;

		if ( ! preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			return null;
		}

		$row = $this->orders[ (int) $match[1] ] ?? null;

		if ( null === $row ) {
			return null;
		}

		return str_starts_with( $query, 'SELECT meta' ) ? array( 'meta' => $row['meta'] ?? null ) : $row;
	}

	/**
	 * Conditional update: every WHERE column must match.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data  Columns.
	 * @param array<string,mixed> $where Conditions.
	 *
	 * @return int|false Rows affected.
	 */
	public function update( string $table, array $data, array $where ): int|false {
		$this->log[] = 'UPDATE ' . $table . ' ' . wp_json_encode( $where );

		$id = (int) ( $where['id'] ?? 0 );

		if ( ! isset( $this->orders[ $id ] ) ) {
			return 0;
		}

		foreach ( $where as $column => $value ) {
			if ( (string) ( $this->orders[ $id ][ $column ] ?? '' ) !== (string) $value ) {
				return 0;
			}
		}

		$this->orders[ $id ] = array_merge( $this->orders[ $id ], $data );

		return 1;
	}

	/**
	 * The meta compare-and-swap.
	 *
	 * @param string $query Query.
	 *
	 * @return int|false
	 */
	public function query( string $query ): int|false {
		$this->log[] = $query;

		if ( ! preg_match( "/SET meta = '(.*)', date_modified = '[^']*' WHERE id = (\d+) AND MD5\(COALESCE\(meta, ''\)\) = '([0-9a-f]{32})'/s", $query, $match ) ) {
			return false;
		}

		if ( null !== $this->before_cas ) {
			$callback         = $this->before_cas;
			$this->before_cas = null;
			$callback( $this );
		}

		$id = (int) $match[2];

		if ( ! isset( $this->orders[ $id ] ) || md5( (string) ( $this->orders[ $id ]['meta'] ?? '' ) ) !== $match[3] ) {
			return 0;
		}

		$this->orders[ $id ]['meta'] = $match[1];

		return 1;
	}

	/**
	 * Swallow log writes; this double only records queries.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data  Row.
	 *
	 * @return int
	 */
	public function insert( string $table, array $data ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- matches wpdb's signature.
		return 1;
	}
}
