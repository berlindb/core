<?php
/**
 * 'distinct' query var tests.
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
 * Tests for the 'distinct' query var and Query::parse_distinct().
 *
 * 'distinct' is wired into parse_query_vars() like 'explain': off by default,
 * and rendering the DISTINCT keyword into the SELECT when truthy. The generated
 * SQL is captured through the WordPress 'query' filter, which fires for every
 * statement $wpdb runs.
 *
 * @since 3.1.0
 */
class DistinctTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

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
	 * Seed one row before each test.
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
				'name'     => 'Alpha Widget',
				'status'   => 'active',
				'priority' => 10,
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
	 * Test that 'distinct' defaults to false on a fresh query.
	 *
	 * @since 3.1.0
	 */
	public function test_distinct_default_var_is_false() {

		// Run a query so the defaults are merged into the runtime query vars.
		self::$query->query( array( 'number' => 1 ) );

		$this->assertFalse( self::$query->get_query_var( 'distinct' ) );
	}

	/**
	 * Test that a default query renders no DISTINCT keyword.
	 *
	 * @since 3.1.0
	 */
	public function test_default_query_has_no_distinct() {
		$sql = $this->captured_sql(
			static function () {
				self::$query->query(
					array(
						'number'        => 10,
						'no_found_rows' => true,
					)
				);
			}
		);

		$this->assertStringNotContainsString( 'DISTINCT', $sql );
	}

	/**
	 * Test that 'distinct' => true renders SELECT DISTINCT.
	 *
	 * @since 3.1.0
	 */
	public function test_distinct_var_adds_keyword() {
		$sql = $this->captured_sql(
			static function () {
				self::$query->query(
					array(
						'distinct'      => true,
						'number'        => 10,
						'no_found_rows' => true,
					)
				);
			}
		);

		$this->assertStringContainsString( 'SELECT DISTINCT', $sql );
	}

	/**
	 * Test that the found-items count for a DISTINCT query uses COUNT(DISTINCT id),
	 * not "SELECT DISTINCT COUNT(*)" (which would count joined rows).
	 *
	 * @since 3.1.0
	 */
	public function test_distinct_found_rows_uses_count_distinct() {
		$sql = $this->captured_sql(
			static function () {
				self::$query->query(
					array(
						'distinct'      => true,
						'number'        => 1,
						'no_found_rows' => false,
					)
				);
			}
		);

		$this->assertStringContainsString( 'COUNT(DISTINCT', $sql );
		$this->assertStringNotContainsString( 'DISTINCT COUNT(*)', $sql );
	}

	/**
	 * Test that a direct count query with distinct uses COUNT(DISTINCT id) - not a
	 * standalone "SELECT DISTINCT COUNT(*)" - and returns the correct integer.
	 *
	 * @since 3.1.0
	 */
	public function test_distinct_count_query_uses_count_distinct() {
		$result = null;

		$sql = $this->captured_sql(
			static function () use ( &$result ) {
				$result = self::$query->query(
					array(
						'count'    => true,
						'distinct' => true,
					)
				);
			}
		);

		$this->assertStringContainsString( 'COUNT(DISTINCT', $sql );
		$this->assertStringNotContainsString( 'DISTINCT COUNT(*)', $sql );
		$this->assertSame( 1, $result );
	}
}
