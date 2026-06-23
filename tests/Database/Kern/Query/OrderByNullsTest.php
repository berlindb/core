<?php
/**
 * ORDER BY ... NULLS FIRST / NULLS LAST tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for per-column NULLS FIRST / NULLS LAST ordering.
 *
 * MySQL has no NULLS FIRST/LAST syntax, so a per-column direction value carrying a
 * trailing "NULLS FIRST"/"NULLS LAST" is emulated with a leading ISNULL( col ) sort
 * key. The generated SQL is captured through the WordPress 'query' filter.
 *
 * @since 3.1.0
 */
class OrderByNullsTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/** The aliased priority column as it appears in generated SQL. */
	private const PRIORITY = '`berlindb_database_tw`.`priority`';

	/**
	 * Install the fixture table and query object before tests run.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new TestQuery();
	}

	/**
	 * Uninstall the fixture table after tests complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Seed two rows before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		self::$table->delete_all();
		wp_cache_flush();

		self::$query->add_item(
			array(
				'name'     => 'Alpha',
				'priority' => 10,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Beta',
				'priority' => 20,
			)
		);

		wp_cache_flush();
	}

	/**
	 * Capture every SQL statement $wpdb runs during the callback.
	 *
	 * @since 3.1.0
	 *
	 * @param callable $callback Code to run while capturing.
	 * @return string All captured statements, newline-joined.
	 */
	private function captured_sql( callable $callback ): string {
		$queries = array();

		$filter = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};

		add_filter( 'query', $filter );
		$callback();
		remove_filter( 'query', $filter );

		return implode( "\n", $queries );
	}

	/**
	 * Run a query with the given orderby and return the captured SQL.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,string> $orderby Orderby map.
	 * @return string
	 */
	private function sql_for_orderby( array $orderby ): string {
		return $this->captured_sql(
			static function () use ( $orderby ) {
				self::$query->query(
					array(
						'orderby'       => $orderby,
						'number'        => 10,
						'no_found_rows' => true,
					)
				);
			}
		);
	}

	/**
	 * Test NULLS LAST on an ascending column: ISNULL( col ) ASC sinks NULLs last.
	 *
	 * @since 3.1.0
	 */
	public function test_nulls_last_ascending() {
		$sql = $this->sql_for_orderby( array( 'priority' => 'ASC NULLS LAST' ) );

		$this->assertStringContainsString(
			'ISNULL( ' . self::PRIORITY . ' ) ASC, ' . self::PRIORITY . ' ASC',
			$sql
		);
	}

	/**
	 * Test NULLS FIRST on a descending column: ISNULL( col ) DESC floats NULLs first.
	 *
	 * @since 3.1.0
	 */
	public function test_nulls_first_descending() {
		$sql = $this->sql_for_orderby( array( 'priority' => 'DESC NULLS FIRST' ) );

		$this->assertStringContainsString(
			'ISNULL( ' . self::PRIORITY . ' ) DESC, ' . self::PRIORITY . ' DESC',
			$sql
		);
	}

	/**
	 * Test that a plain direction renders no ISNULL emulation (MySQL default nulls).
	 *
	 * @since 3.1.0
	 */
	public function test_plain_order_has_no_isnull() {
		$sql = $this->sql_for_orderby( array( 'priority' => 'ASC' ) );

		$this->assertStringNotContainsString( 'ISNULL(', $sql );
		$this->assertStringContainsString( self::PRIORITY . ' ASC', $sql );
	}

	/**
	 * Test a mixed orderby: only the NULLS column gets the ISNULL key, the other
	 * orders plainly.
	 *
	 * @since 3.1.0
	 */
	public function test_nulls_only_applies_to_its_column() {
		$sql = $this->sql_for_orderby(
			array(
				'priority' => 'ASC NULLS LAST',
				'name'     => 'DESC',
			)
		);

		// priority gets the ISNULL emulation.
		$this->assertStringContainsString( 'ISNULL( ' . self::PRIORITY . ' ) ASC', $sql );

		// name orders plainly (no ISNULL on it).
		$this->assertStringContainsString( '`berlindb_database_tw`.`name` DESC', $sql );
		$this->assertStringNotContainsString( 'ISNULL( `berlindb_database_tw`.`name`', $sql );
	}
}
