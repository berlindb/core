<?php
/**
 * criteria cross-parser boolean combination tests.
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
 * End-to-end tests for 'criteria' - the top-level boolean tree that combines
 * whole parser WHERE fragments with AND/OR across parsers (#211 Lever B), driven
 * through real queries. The Clauses\Where clause itself is unit-tested in WhereTest.
 *
 * Five fixture rows:
 *  - Alpha Widget   | active   | priority 10
 *  - Beta Widget    | active   | priority 20
 *  - Gamma Gadget   | inactive | priority 30
 *  - Delta Gadget   | inactive | priority 40
 *  - Epsilon Widget | pending  | priority 50
 *
 * Referenceable parser names: columns (the public alias for the 'by' parser -
 * direct column conditions), compare, search, in, not_in (and meta/date/relation
 * where active). Leaves are whole parser fragments, not individual criteria.
 *
 * @since 3.1.0
 */
class CriteriaIntegrationTest extends TestCase {

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
	 * Reset fixture data before each test.
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
				array( 'Alpha Widget', 'active', 10 ),
				array( 'Beta Widget', 'active', 20 ),
				array( 'Gamma Gadget', 'inactive', 30 ),
				array( 'Delta Gadget', 'inactive', 40 ),
				array( 'Epsilon Widget', 'pending', 50 ),
			) as $row
		) {
			self::$query->add_item(
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
	 * Run a query and return the matching row names, sorted for stable assertions.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Query vars.
	 * @return list<string>
	 */
	private function names( array $args ): array {
		$results = self::$query->query( $args );
		$names   = wp_list_pluck( $results, 'name' );

		sort( $names );

		return $names;
	}

	/**
	 * Test top-level OR across two parsers: status='active' (columns) OR
	 * priority>=40 (compare) returns the union, which an implicit AND could not.
	 *
	 * @since 3.1.0
	 */
	public function test_or_across_parsers() {
		$names = $this->names(
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

		$this->assertSame(
			array( 'Alpha Widget', 'Beta Widget', 'Delta Gadget', 'Epsilon Widget' ),
			$names
		);
	}

	/**
	 * Test that an explicit all-AND criteria matches the implicit default.
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_and_matches_default() {
		$args = array(
			'status'        => 'active',
			'compare_query' => array(
				'key'     => 'priority',
				'value'   => 20,
				'compare' => '>=',
			),
		);

		// Implicit AND (no criteria) -> only Beta (active AND priority>=20).
		$this->assertSame( array( 'Beta Widget' ), $this->names( $args ) );

		// Explicit AND criteria -> identical.
		$args[ 'criteria' ] = array(
			'relation' => 'AND',
			'columns',
			'compare',
		);
		$this->assertSame( array( 'Beta Widget' ), $this->names( $args ) );
	}

	/**
	 * Test that an active parser the tree did not name is AND-ed onto the result.
	 *
	 * (columns OR compare) AND search: (active OR priority>30) AND name LIKE Widget.
	 *
	 * @since 3.1.0
	 */
	public function test_unreferenced_parser_ands_on() {
		$names = $this->names(
			array(
				'status'        => 'active',
				'compare_query' => array(
					'key'     => 'priority',
					'value'   => 30,
					'compare' => '>',
				),
				'search'        => 'Widget',
				'criteria'      => array(
					'relation' => 'OR',
					'columns',
					'compare',
				),
			)
		);

		// active = Alpha,Beta ; priority>30 = Delta,Epsilon ; union AND Widget drops Delta (a Gadget).
		$this->assertSame( array( 'Alpha Widget', 'Beta Widget', 'Epsilon Widget' ), $names );
	}

	/**
	 * Test nested groups: columns AND ( compare OR search ).
	 *
	 * @since 3.1.0
	 */
	public function test_nested_groups() {
		$names = $this->names(
			array(
				'status'        => 'active',
				'compare_query' => array(
					'key'     => 'priority',
					'value'   => 50,
					'compare' => '>=',
				),
				'search'        => 'Alpha',
				'criteria'      => array(
					'relation' => 'AND',
					'columns',
					array(
						'relation' => 'OR',
						'compare',
						'search',
					),
				),
			)
		);

		// active = Alpha,Beta ; ( priority>=50=Epsilon OR name~Alpha=Alpha ) ; AND -> Alpha only.
		$this->assertSame( array( 'Alpha Widget' ), $names );
	}

	/**
	 * Test that a named-but-inactive parser leaf is silently dropped (not an error):
	 * OR( columns, search ) with no search var renders just the columns fragment.
	 *
	 * @since 3.1.0
	 */
	public function test_inactive_leaf_is_dropped() {
		$names = $this->names(
			array(
				'status'   => 'active',
				'criteria' => array(
					'relation' => 'OR',
					'columns',
					'search',
				),
			)
		);

		$this->assertSame( array( 'Alpha Widget', 'Beta Widget' ), $names );
	}

	/**
	 * Test that a not_in fragment (no JOIN) combines under OR:
	 * status='pending' (columns) OR status NOT IN ('pending') (not_in) = every row.
	 *
	 * @since 3.1.0
	 */
	public function test_not_in_under_or() {
		$names = $this->names(
			array(
				'status'         => 'pending',
				'status__not_in' => array( 'pending' ),
				'criteria'       => array(
					'relation' => 'OR',
					'columns',
					'not_in',
				),
			)
		);

		$this->assertCount( 5, $names );
	}

	/**
	 * Test that referencing an unknown parser fails the query closed (no rows),
	 * never silently widening.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_parser_fails_closed() {
		$names = $this->names(
			array(
				'status'   => 'active',
				'criteria' => array(
					'relation' => 'OR',
					'columns',
					'bogus',
				),
			)
		);

		$this->assertSame( array(), $names );
	}

	/**
	 * Test that a malformed relation fails the query closed.
	 *
	 * @since 3.1.0
	 */
	public function test_malformed_relation_fails_closed() {
		$names = $this->names(
			array(
				'status'   => 'active',
				'criteria' => array(
					'relation' => 'XOR',
					'columns',
					'compare',
				),
			)
		);

		$this->assertSame( array(), $names );
	}

	/**
	 * Test that a non-array criteria (a malformed directive, not an absent one)
	 * fails closed rather than degrading to the default AND.
	 *
	 * @since 3.1.0
	 */
	public function test_scalar_criteria_fails_closed() {
		$names = $this->names(
			array(
				'status'   => 'active',
				'criteria' => 'OR',
			)
		);

		$this->assertSame( array(), $names );
	}

	/**
	 * Test that criteria segments the result cache: two otherwise-identical
	 * queries differing only by relation must not collide on a cached result.
	 *
	 * @since 3.1.0
	 */
	public function test_criteria_segments_cache() {
		$base = array(
			'status'        => 'active',
			'compare_query' => array(
				'key'     => 'priority',
				'value'   => 40,
				'compare' => '>=',
			),
		);

		// OR -> union (4 rows); primes the cache.
		$or = $this->names(
			$base + array(
				'criteria' => array(
					'relation' => 'OR',
					'columns',
					'compare',
				),
			)
		);
		$this->assertCount( 4, $or );

		// AND of the same vars -> intersection (0 rows); must NOT reuse the OR cache.
		$and = $this->names(
			$base + array(
				'criteria' => array(
					'relation' => 'AND',
					'columns',
					'compare',
				),
			)
		);
		$this->assertSame( array(), $and );
	}

	/**
	 * Test group negation: NOT ( status='active' OR priority>=40 ) excludes the
	 * union (Alpha, Beta active; Delta 40, Epsilon 50) and leaves only Gamma.
	 *
	 * @since 3.1.0
	 */
	public function test_not_group_across_parsers() {
		$names = $this->names(
			array(
				'status'        => 'active',
				'compare_query' => array(
					'key'     => 'priority',
					'value'   => 40,
					'compare' => '>=',
				),
				'criteria'      => array(
					'relation' => 'OR',
					'not'      => true,
					'columns',
					'compare',
				),
			)
		);

		$this->assertSame( array( 'Gamma Gadget' ), $names );
	}

	/**
	 * Test that an explicit AND-NOT criteria narrows correctly: NOT ( status =
	 * 'active' ) keeps the three non-active rows (Gamma, Delta, Epsilon).
	 *
	 * @since 3.1.0
	 */
	public function test_not_single_parser() {
		$names = $this->names(
			array(
				'status'   => 'active',
				'criteria' => array(
					'relation' => 'AND',
					'not'      => true,
					'columns',
				),
			)
		);

		$this->assertSame( array( 'Delta Gadget', 'Epsilon Widget', 'Gamma Gadget' ), $names );
	}
}
