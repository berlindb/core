<?php
/**
 * parse_groupby() column-validation tests.
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
 * Tests that parse_groupby() drops unknown groupby columns instead of quoting them.
 *
 * get_columns_field_by() returns a `false` fallback for a name that is not a
 * column. parse_groupby() and the aggregate path now share
 * get_valid_groupby_columns(), which filters those out, so an unknown groupby
 * column is treated as ungrouped (no GROUP BY) rather than quoted into a malformed
 * identifier. The generated SQL is captured through the WordPress 'query' filter.
 *
 * @since 3.1.0
 */
class GroupByValidationTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/**
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
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Seed three rows across two statuses.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		self::$table->delete_all();
		wp_cache_flush();

		foreach (
			array(
				array( 'Alpha', 'active' ),
				array( 'Beta', 'active' ),
				array( 'Gamma', 'inactive' ),
			) as $row
		) {
			self::$query->add_item(
				array(
					'name'   => $row[0],
					'status' => $row[1],
				)
			);
		}

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
	 * Extract the column list of the first GROUP BY clause, or '' if none.
	 *
	 * @since 3.1.0
	 *
	 * @param string $sql Captured SQL.
	 * @return string The text between "GROUP BY" and the next clause, trimmed.
	 */
	private function group_by_columns( string $sql ): string {
		if ( 1 === preg_match( '/GROUP BY (.+?)(?: ORDER BY| LIMIT|$)/i', $sql, $m ) ) {
			return trim( $m[1] );
		}

		return '';
	}

	/**
	 * An unknown groupby column is dropped: the query runs ungrouped, with no
	 * GROUP BY clause and no malformed identifier.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_groupby_column_is_ungrouped() {
		$sql = $this->captured_sql(
			static function () {
				self::$query->query(
					array(
						'groupby' => 'nonexistent_column',
						'number'  => 100,
					)
				);
			}
		);

		$this->assertStringNotContainsStringIgnoringCase( 'GROUP BY', $sql );
	}

	/**
	 * A valid groupby column emits a GROUP BY on that column.
	 *
	 * @since 3.1.0
	 */
	public function test_valid_groupby_column_emits_group_by() {
		$sql = $this->captured_sql(
			static function () {
				self::$query->query(
					array(
						'groupby' => 'status',
						'number'  => 100,
					)
				);
			}
		);

		$this->assertStringContainsStringIgnoringCase( 'GROUP BY', $sql );
		$this->assertStringContainsString( 'status', $this->group_by_columns( $sql ) );
	}

	/**
	 * A mix of valid and unknown columns keeps only the valid one: the GROUP BY is
	 * identical to grouping by the valid column alone (the unknown adds nothing).
	 *
	 * @since 3.1.0
	 */
	public function test_mixed_groupby_keeps_only_valid_columns() {
		$valid_only = $this->group_by_columns(
			$this->captured_sql(
				static function () {
					self::$query->query(
						array(
							'groupby' => array( 'status' ),
							'number'  => 100,
						)
					);
				}
			)
		);

		$mixed = $this->group_by_columns(
			$this->captured_sql(
				static function () {
					self::$query->query(
						array(
							'groupby' => array( 'status', 'nonexistent_column' ),
							'number'  => 100,
						)
					);
				}
			)
		);

		$this->assertNotSame( '', $valid_only );
		$this->assertSame( $valid_only, $mixed );
	}
}
