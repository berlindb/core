<?php
/**
 * index_hints query-var tests.
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
 * Tests for the `index_hints` query var (USE / FORCE / IGNORE INDEX).
 *
 * Index names are validated against the schema's declared indexes (the fixture has
 * a `status` KEY plus PRIMARY); unknown names, unknown types, and USE/FORCE
 * conflicts fail open (drop + log) so the query still runs. The generated SQL is
 * captured through the WordPress 'query' filter.
 *
 * @since 3.1.0
 */
class IndexHintsTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/** The base table reference as it appears just before any index hint. */
	private const ALIAS = 'berlindb_database_tw';

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
				'name'   => 'Alpha',
				'status' => 'active',
			)
		);
		self::$query->add_item(
			array(
				'name'   => 'Beta',
				'status' => 'active',
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
	 * Run a query with the given index_hints and return the captured SQL.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $hints The index_hints query var.
	 * @return string
	 */
	private function sql_for_hints( $hints ): string {
		return $this->captured_sql(
			function () use ( $hints ) {
				self::$query->query(
					array(
						'index_hints'   => $hints,
						'number'        => 10,
						'no_found_rows' => true,
						/*
						 * index_hints is excluded from the cache key (it does not change
						 * results), so bypass the cache to force SQL on every call.
						 */
						'cache_results' => false,
					)
				);
			}
		);
	}

	/**
	 * Test FORCE INDEX on a declared index renders, right after the table alias.
	 *
	 * @since 3.1.0
	 */
	public function test_force_index_renders_after_alias() {
		$sql = $this->sql_for_hints(
			array(
				'type'    => 'force',
				'indexes' => array( 'status' ),
			)
		);

		$this->assertStringContainsString( self::ALIAS . ' FORCE INDEX (`status`)', $sql );
	}

	/**
	 * Test USE INDEX with a FOR JOIN scope.
	 *
	 * @since 3.1.0
	 */
	public function test_use_index_for_join() {
		$sql = $this->sql_for_hints(
			array(
				'type'    => 'use',
				'indexes' => array( 'status' ),
				'for'     => 'join',
			)
		);

		$this->assertStringContainsString( 'USE INDEX FOR JOIN (`status`)', $sql );
	}

	/**
	 * Test IGNORE INDEX renders.
	 *
	 * @since 3.1.0
	 */
	public function test_ignore_index() {
		$sql = $this->sql_for_hints(
			array(
				'type'    => 'ignore',
				'indexes' => array( 'status' ),
			)
		);

		$this->assertStringContainsString( 'IGNORE INDEX (`status`)', $sql );
	}

	/**
	 * Test the single associative spec is accepted as a one-element shorthand
	 * (the helper already passes a single spec, so this also covers list form).
	 *
	 * @since 3.1.0
	 */
	public function test_list_form_renders() {
		$sql = $this->sql_for_hints(
			array(
				array(
					'type'    => 'force',
					'indexes' => array( 'status' ),
				),
			)
		);

		$this->assertStringContainsString( 'FORCE INDEX (`status`)', $sql );
	}

	/**
	 * Test PRIMARY is emitted bare (not back-quoted), in any input casing.
	 *
	 * @since 3.1.0
	 */
	public function test_primary_renders_bare() {
		$sql = $this->sql_for_hints(
			array(
				'type'    => 'force',
				'indexes' => array( 'Primary' ),
			)
		);

		$this->assertStringContainsString( 'FORCE INDEX (PRIMARY)', $sql );
		$this->assertStringNotContainsString( '`PRIMARY`', $sql );
	}

	/**
	 * Test an unknown index name fails open: no hint renders and the query still runs.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_index_fails_open() {
		$rows = array();

		$sql = $this->captured_sql(
			function () use ( &$rows ) {
				$rows = self::$query->query(
					array(
						'index_hints'   => array(
							'type'    => 'force',
							'indexes' => array( 'not_a_real_index' ),
						),
						'number'        => 10,
						'no_found_rows' => true,
						'cache_results' => false,
					)
				);
			}
		);

		// No hint was rendered...
		$this->assertStringNotContainsString( 'INDEX (', $sql );

		// ...and the query still returned the seeded rows.
		$this->assertCount( 2, $rows );
	}

	/**
	 * Test the orderby / groupby FOR-scope aliases render as ORDER BY / GROUP BY.
	 *
	 * @since 3.1.0
	 */
	public function test_for_scope_aliases() {
		$order = $this->sql_for_hints(
			array(
				'type'    => 'force',
				'indexes' => array( 'status' ),
				'for'     => 'orderby',
			)
		);
		$this->assertStringContainsString( 'FORCE INDEX FOR ORDER BY (`status`)', $order );

		$group = $this->sql_for_hints(
			array(
				'type'    => 'force',
				'indexes' => array( 'status' ),
				'for'     => 'groupby',
			)
		);
		$this->assertStringContainsString( 'FORCE INDEX FOR GROUP BY (`status`)', $group );
	}

	/**
	 * Test an unknown FOR scope coerces to no scope (the hint still renders).
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_for_coerces_to_no_scope() {
		$sql = $this->sql_for_hints(
			array(
				'type'    => 'force',
				'indexes' => array( 'status' ),
				'for'     => 'sideways',
			)
		);

		$this->assertStringContainsString( 'FORCE INDEX (`status`)', $sql );
		$this->assertStringNotContainsString( 'FOR ', $sql );
	}

	/**
	 * Test USE and FORCE cannot be mixed: the first (USE) wins, FORCE is dropped.
	 *
	 * @since 3.1.0
	 */
	public function test_use_force_conflict_drops_later() {
		$sql = $this->sql_for_hints(
			array(
				array(
					'type'    => 'use',
					'indexes' => array( 'status' ),
				),
				array(
					'type'    => 'force',
					'indexes' => array( 'status' ),
				),
			)
		);

		$this->assertStringContainsString( 'USE INDEX (`status`)', $sql );
		$this->assertStringNotContainsString( 'FORCE INDEX', $sql );
	}

	/**
	 * Test multiple compatible specs render together with clean spacing.
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_specs_render() {
		$sql = $this->sql_for_hints(
			array(
				array(
					'type'    => 'force',
					'indexes' => array( 'status' ),
				),
				array(
					'type'    => 'ignore',
					'indexes' => array( 'primary' ),
				),
			)
		);

		$this->assertStringContainsString(
			self::ALIAS . ' FORCE INDEX (`status`) IGNORE INDEX (PRIMARY)',
			$sql
		);
	}

	/**
	 * Test duplicate index names within a spec are de-duplicated.
	 *
	 * @since 3.1.0
	 */
	public function test_duplicate_indexes_deduped() {
		$sql = $this->sql_for_hints(
			array(
				'type'    => 'force',
				'indexes' => array( 'status', 'status' ),
			)
		);

		$this->assertStringContainsString( 'FORCE INDEX (`status`)', $sql );
		$this->assertStringNotContainsString( '`status`, `status`', $sql );
	}

	/**
	 * Test that no index_hints leaves the FROM clause un-hinted.
	 *
	 * @since 3.1.0
	 */
	public function test_no_hints_no_change() {
		$sql = $this->captured_sql(
			static function () {
				self::$query->query(
					array(
						'number'        => 10,
						'no_found_rows' => true,
						'cache_results' => false,
					)
				);
			}
		);

		$this->assertStringNotContainsString( 'INDEX (', $sql );
	}

	/**
	 * Test hints set by a scoping hook (after validate_query_vars) are still
	 * sanitized at the render boundary: an undeclared index fails open rather than
	 * rendering invalid SQL, and a valid single-spec set by a hook still renders.
	 *
	 * @since 3.1.0
	 */
	public function test_hook_set_hints_are_resanitized() {

		// A hook that injects an UNDECLARED index after validation.
		$bad = static function ( $query ) {
			$query->set_query_var(
				'index_hints',
				array(
					'type'    => 'force',
					'indexes' => array( 'not_a_real_index' ),
				)
			);
		};
		add_action( 'berlindb_database_parse_widgets_query', $bad );

		$rows = array();
		$sql  = $this->captured_sql(
			function () use ( &$rows ) {
				$rows = self::$query->query(
					array(
						'number'        => 10,
						'no_found_rows' => true,
						'cache_results' => false,
					)
				);
			}
		);

		remove_action( 'berlindb_database_parse_widgets_query', $bad );

		// Fail-open: no invalid hint rendered, query still ran.
		$this->assertStringNotContainsString( 'INDEX (', $sql );
		$this->assertCount( 2, $rows );

		// A hook that injects a VALID single-spec after validation.
		$good = static function ( $query ) {
			$query->set_query_var(
				'index_hints',
				array(
					'type'    => 'force',
					'indexes' => array( 'status' ),
				)
			);
		};
		add_action( 'berlindb_database_parse_widgets_query', $good );

		$sql = $this->captured_sql(
			static function () {
				self::$query->query(
					array(
						'number'        => 10,
						'no_found_rows' => true,
						'cache_results' => false,
					)
				);
			}
		);

		remove_action( 'berlindb_database_parse_widgets_query', $good );

		// Re-sanitized into a one-element list and rendered.
		$this->assertStringContainsString( 'FORCE INDEX (`status`)', $sql );
	}

	/**
	 * Test the ID-resolution path (select_ids, used by delete_items/update_items)
	 * does not render index hints even when the filter carries them.
	 *
	 * @since 3.1.0
	 */
	public function test_select_ids_path_is_unhinted() {
		$sql = $this->captured_sql(
			static function () {
				self::$query->delete_items(
					array(
						'status'      => 'active',
						'index_hints' => array(
							'type'    => 'force',
							'indexes' => array( 'status' ),
						),
					)
				);
			}
		);

		$this->assertStringNotContainsString( 'FORCE INDEX', $sql );
	}
}
