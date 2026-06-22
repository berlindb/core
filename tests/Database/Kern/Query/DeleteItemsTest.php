<?php
/**
 * delete_items() Operation tests.
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
 * End-to-end tests for Query::delete_items() and the Operations\Delete it drives.
 *
 * delete_items() resolves its input to a list of primary IDs and removes each
 * through delete_item(), so per-item semantics (cache cleanup, the {item}_deleted
 * action) still fire. The input is a single ID, a list of IDs, or a query-var
 * filter array (which compiles to a WHERE via the parsers + Clauses\Builder).
 *
 * Five fixture rows (name | status | priority):
 *  - Alpha Widget   | active   | 10
 *  - Beta Widget    | active   | 20
 *  - Gamma Gadget   | inactive | 30
 *  - Delta Gadget   | inactive | 40
 *  - Epsilon Widget | pending  | 50
 *
 * @since 3.1.0
 */
class DeleteItemsTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/** @var array<string,int> Fixture row name => primary ID, rebuilt each test. */
	private $ids = array();

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
	 * Reset fixture data before each test, capturing each row's primary ID.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		self::$table->delete_all();
		wp_cache_flush();

		$this->ids = array();

		foreach (
			array(
				array( 'Alpha Widget', 'active', 10 ),
				array( 'Beta Widget', 'active', 20 ),
				array( 'Gamma Gadget', 'inactive', 30 ),
				array( 'Delta Gadget', 'inactive', 40 ),
				array( 'Epsilon Widget', 'pending', 50 ),
			) as $row
		) {
			$this->ids[ $row[0] ] = self::$query->add_item(
				array(
					'name'     => $row[0],
					'status'   => $row[1],
					'priority' => $row[2],
				)
			);
		}

		wp_cache_flush();
	}

	/**
	 * Get the names of all remaining rows, sorted for stable assertions.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	private function remaining_names(): array {
		$results = self::$query->query( array( 'number' => 100 ) );
		$names   = wp_list_pluck( $results, 'name' );

		sort( $names );

		return $names;
	}

	/**
	 * Test deleting a single item by its primary ID.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_single_id() {
		$deleted = self::$query->delete_items( $this->ids[ 'Alpha Widget' ] );

		$this->assertSame( 1, $deleted );
		$this->assertSame(
			array( 'Beta Widget', 'Delta Gadget', 'Epsilon Widget', 'Gamma Gadget' ),
			$this->remaining_names()
		);
	}

	/**
	 * Test deleting several items by a list of primary IDs.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_list_of_ids() {
		$deleted = self::$query->delete_items(
			array(
				$this->ids[ 'Alpha Widget' ],
				$this->ids[ 'Beta Widget' ],
			)
		);

		$this->assertSame( 2, $deleted );
		$this->assertSame(
			array( 'Delta Gadget', 'Epsilon Widget', 'Gamma Gadget' ),
			$this->remaining_names()
		);
	}

	/**
	 * Test deleting by a direct column filter (status = active).
	 *
	 * @since 3.1.0
	 */
	public function test_delete_by_column_filter() {
		$deleted = self::$query->delete_items( array( 'status' => 'active' ) );

		$this->assertSame( 2, $deleted );
		$this->assertSame(
			array( 'Delta Gadget', 'Epsilon Widget', 'Gamma Gadget' ),
			$this->remaining_names()
		);
	}

	/**
	 * Test deleting by a cross-parser OR criteria tree: status = active OR
	 * priority >= 40 removes Alpha, Beta, Delta, Epsilon and leaves Gamma.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_by_criteria_or() {
		$deleted = self::$query->delete_items(
			array(
				'status'        => 'active',
				'compare_query' => array(
					'key'     => 'priority',
					'value'   => 40,
					'compare' => '>=',
				),
				'criteria'      => array(
					'relation' => 'OR',
					'columns',
					'compare',
				),
			)
		);

		$this->assertSame( 4, $deleted );
		$this->assertSame( array( 'Gamma Gadget' ), $this->remaining_names() );
	}

	/**
	 * Test that deletion routes through delete_item(): the {item}_deleted action
	 * fires once per removed row (a bulk DELETE would not fire it at all).
	 *
	 * @since 3.1.0
	 */
	public function test_delete_routes_through_delete_item() {
		$fired = array();

		add_action(
			'berlindb_database_widget_deleted',
			static function ( $item_id ) use ( &$fired ) {
				$fired[] = $item_id;
			}
		);

		$deleted = self::$query->delete_items( array( 'status' => 'inactive' ) );

		$this->assertSame( 2, $deleted );
		$this->assertCount( 2, $fired );
	}

	/**
	 * Test that empty input deletes nothing - the empty set never means "all".
	 *
	 * @since 3.1.0
	 */
	public function test_empty_input_deletes_nothing() {
		$this->assertFalse( self::$query->delete_items() );
		$this->assertFalse( self::$query->delete_items( array() ) );
		$this->assertCount( 5, $this->remaining_names() );
	}

	/**
	 * Test that a filter compiling to no WHERE is refused (never deletes all).
	 *
	 * @since 3.1.0
	 */
	public function test_unconstrained_filter_is_refused() {
		$this->assertFalse( self::$query->delete_items( array( 'not_a_real_column' => 'x' ) ) );
		$this->assertCount( 5, $this->remaining_names() );
	}

	/**
	 * Test that a valid filter matching no rows deletes nothing.
	 *
	 * @since 3.1.0
	 */
	public function test_valid_filter_with_no_matches_deletes_nothing() {
		$this->assertFalse( self::$query->delete_items( array( 'status' => 'archived' ) ) );
		$this->assertCount( 5, $this->remaining_names() );
	}

	/**
	 * Test that delete-by-filter honors the {plural}_query_clauses filter, so an
	 * install's scoping (tenant/ownership/status predicates) also constrains which
	 * rows a delete can reach. The filter scopes out Alpha, so deleting the active
	 * rows removes only Beta and leaves Alpha in place.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_items_honors_query_clauses_filter() {
		$filter = 'berlindb_database_widgets_query_clauses';

		$callback = static function ( $clauses ) {
			$clauses[ 'where' ] .= " AND name != 'Alpha Widget'";
			return $clauses;
		};

		add_filter( $filter, $callback );
		$deleted = self::$query->delete_items( array( 'status' => 'active' ) );
		remove_filter( $filter, $callback );

		$this->assertSame( 1, $deleted );
		$this->assertContains( 'Alpha Widget', $this->remaining_names() );
	}

	/**
	 * Test that delete-by-filter honors the parse_{plural}_query action, where an
	 * install scopes the query by mutating its vars via set_query_var(). The action
	 * narrows the active rows to priority 20, so only Beta is deleted - not Alpha.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_items_honors_parse_query_action() {
		$action = 'berlindb_database_parse_widgets_query';

		$callback = static function ( $query ) {
			$query->set_query_var( 'priority', 20 );
		};

		add_action( $action, $callback );

		/*
		 * Delete on a throwaway instance: set_query_var() in the action mutates the
		 * instance's var defaults, which would pollute the shared fixture query.
		 */
		$deleted = ( new TestQuery() )->delete_items( array( 'status' => 'active' ) );
		remove_action( $action, $callback );

		// active = Alpha (10) + Beta (20); the action adds priority=20, so only Beta.
		$this->assertSame( 1, $deleted );
		$this->assertContains( 'Alpha Widget', $this->remaining_names() );
	}

	/**
	 * Test that delete-by-filter honors the pre_get_{plural} action, where an install
	 * scopes the query just in time via set_query_var(). The action narrows the active
	 * rows to priority 20, so only Beta is deleted - not Alpha.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_items_honors_pre_get_action() {
		$action = 'berlindb_database_pre_get_widgets';

		$callback = static function ( $query ) {
			$query->set_query_var( 'priority', 20 );
		};

		add_action( $action, $callback );

		/*
		 * Delete on a throwaway instance: set_query_var() in the action mutates the
		 * instance's var defaults, which would pollute the shared fixture query.
		 */
		$deleted = ( new TestQuery() )->delete_items( array( 'status' => 'active' ) );
		remove_action( $action, $callback );

		$this->assertSame( 1, $deleted );
		$this->assertContains( 'Alpha Widget', $this->remaining_names() );
	}

	/**
	 * Test that a scoping hook's set_query_var() during a delete leaves no trace on
	 * the Query instance: a later query is not silently constrained by it. Run on the
	 * shared instance precisely to catch leaked vars/defaults.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_items_does_not_leak_hook_scope() {
		$action = 'berlindb_database_pre_get_widgets';

		$callback = static function ( $query ) {
			$query->set_query_var( 'priority', 20 );
		};

		add_action( $action, $callback );
		$deleted = self::$query->delete_items( array( 'status' => 'active' ) );
		remove_action( $action, $callback );

		// active + priority 20 -> only Beta deleted.
		$this->assertSame( 1, $deleted );

		// The hook's priority=20 must not persist: all 4 remaining rows come back.
		$this->assertCount( 4, $this->remaining_names() );
	}

	/**
	 * Test that a non-sequential, integer-keyed ID array (e.g. an array_filter() or
	 * wp_list_pluck() result) is treated as a list of IDs, not as query-var filters.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_by_numeric_keyed_id_array() {
		$ids = array(
			2 => $this->ids[ 'Alpha Widget' ],
			5 => $this->ids[ 'Beta Widget' ],
		);

		$deleted = self::$query->delete_items( $ids );

		$this->assertSame( 2, $deleted );
		$this->assertSame(
			array( 'Delta Gadget', 'Epsilon Widget', 'Gamma Gadget' ),
			$this->remaining_names()
		);
	}
}
