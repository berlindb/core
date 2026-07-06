<?php
/**
 * Where clause unit tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Clauses\Where;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Clauses\Where - the clause that combines per-parser WHERE
 * fragments per an optional 'criteria' boolean tree.
 *
 * These feed it fragment/join maps + a tree directly and assert the SQL list it
 * returns, so every edge (including JOIN-under-OR, which has no cheap integration
 * fixture) is covered in isolation. Parser names here are arbitrary - a leaf is
 * validated only against the 'parsers' allow-list the clause is given.
 *
 * @since 3.1.0
 */
class WhereTest extends TestCase {

	/**
	 * Build a Where clause from explicit fragment maps and a tree.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed                $tree    The criteria tree (or null/empty for none).
	 * @param array<string,string> $where   Per-parser WHERE fragments.
	 * @param array<string,string> $join    Per-parser JOIN fragments.
	 * @param list<string>         $parsers Valid parser names.
	 * @return Where
	 */
	private function where_clause( $tree, array $where, array $join = array(), array $parsers = array() ): Where {
		return new Where(
			array(
				'tree'    => $tree,
				'join'    => $join,
				'where'   => $where,
				'parsers' => $parsers,
			)
		);
	}

	/**
	 * Test that an absent tree ANDs every parser fragment (historical behavior).
	 *
	 * @since 3.1.0
	 */
	public function test_no_tree_ands_all_fragments() {
		$clause = $this->where_clause(
			null,
			array(
				'by'   => 'x = 1',
				'meta' => 'y = 2',
			)
		);

		$this->assertSame( array( 'x = 1', 'y = 2' ), $clause->get_clauses() );
		$this->assertSame( array(), $clause->get_warnings() );
	}

	/**
	 * Test that an empty-array tree is treated as absent (the default), not an error.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_tree_is_absent() {
		$clause = $this->where_clause( array(), array( 'by' => 'x = 1' ) );

		$this->assertSame( array( 'x = 1' ), $clause->get_clauses() );
		$this->assertSame( array(), $clause->get_warnings() );
	}

	/**
	 * Test OR and AND grouping render the expected grouped SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_or_and_grouping() {
		$where   = array(
			'by'   => 'x = 1',
			'meta' => 'y = 2',
		);
		$parsers = array( 'by', 'meta' );

		$this->assertSame(
			array( '( x = 1 OR y = 2 )' ),
			$this->where_clause(
				array(
					'relation' => 'OR',
					'by',
					'meta',
				),
				$where,
				array(),
				$parsers
			)->get_clauses()
		);

		$this->assertSame(
			array( '( x = 1 AND y = 2 )' ),
			$this->where_clause(
				array(
					'relation' => 'AND',
					'by',
					'meta',
				),
				$where,
				array(),
				$parsers
			)->get_clauses()
		);
	}

	/**
	 * Test that an XOR criteria group renders chained exclusive-or SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_xor_grouping() {
		$this->assertSame(
			array( '( x = 1 XOR y = 2 )' ),
			$this->where_clause(
				array(
					'relation' => 'XOR',
					'by',
					'meta',
				),
				array(
					'by'   => 'x = 1',
					'meta' => 'y = 2',
				),
				array(),
				array( 'by', 'meta' )
			)->get_clauses()
		);
	}

	/**
	 * Test that nested groups nest to arbitrary depth.
	 *
	 * @since 3.1.0
	 */
	public function test_nested_groups() {
		$clause = $this->where_clause(
			array(
				'relation' => 'AND',
				'a',
				array(
					'relation' => 'OR',
					'b',
					'c',
				),
			),
			array(
				'a' => 'x = 1',
				'b' => 'y = 2',
				'c' => 'z = 3',
			),
			array(),
			array( 'a', 'b', 'c' )
		);

		$this->assertSame( array( '( x = 1 AND ( y = 2 OR z = 3 ) )' ), $clause->get_clauses() );
	}

	/**
	 * Test that an active parser the tree did not name is appended (additive-AND).
	 *
	 * @since 3.1.0
	 */
	public function test_unreferenced_fragment_is_appended() {
		$clause = $this->where_clause(
			array(
				'relation' => 'OR',
				'by',
				'meta',
			),
			array(
				'by'   => 's = 1',
				'meta' => 'm = 2',
				'date' => 'd > 3',
			),
			array(),
			array( 'by', 'meta', 'date' )
		);

		// The OR group, then the unreferenced date fragment, to be AND-ed downstream.
		$this->assertSame( array( '( s = 1 OR m = 2 )', 'd > 3' ), $clause->get_clauses() );
	}

	/**
	 * Test that the public 'columns' leaf resolves to the internal 'by' parser.
	 *
	 * @since 3.1.0
	 */
	public function test_columns_alias_resolves_to_by() {
		$clause = $this->where_clause(
			array(
				'relation' => 'AND',
				'columns',
			),
			array( 'by' => 's = 1' ),
			array(),
			array( 'by' )
		);

		// Single referenced leaf -> bare fragment, and 'by' is not re-appended.
		$this->assertSame( array( 's = 1' ), $clause->get_clauses() );
		$this->assertSame( array(), $clause->get_warnings() );
	}

	/**
	 * Test that a named-but-inactive leaf is dropped (not an error).
	 *
	 * @since 3.1.0
	 */
	public function test_inactive_leaf_is_dropped() {
		$clause = $this->where_clause(
			array(
				'relation' => 'OR',
				'by',
				'meta',
			),
			array( 'by' => 's = 1' ),
			array(),
			array( 'by', 'meta' )
		);

		$this->assertSame( array( 's = 1' ), $clause->get_clauses() );
		$this->assertSame( array(), $clause->get_warnings() );
	}

	/**
	 * Test that a JOIN-emitting parser under OR fails closed and warns.
	 *
	 * @since 3.1.0
	 */
	public function test_join_under_or_fails_closed() {
		$clause = $this->where_clause(
			array(
				'relation' => 'OR',
				'columns',
				'relation',
			),
			array(
				'by'       => 's = 1',
				'relation' => 'EXISTS ( SELECT 1 )',
			),
			array( 'relation' => 'INNER JOIN other ON other.id = t.id' ),
			array( 'by', 'relation' )
		);

		$this->assertSame( array( '1 = 0' ), $clause->get_clauses() );
		$this->assertNotEmpty( $clause->get_warnings() );
	}

	/**
	 * Test that the JOIN-under-OR check is path-sensitive: a JOIN parser inside a
	 * nested OR (under an outer AND) still fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_join_under_nested_or_fails_closed() {
		$clause = $this->where_clause(
			array(
				'relation' => 'AND',
				'safe',
				array(
					'relation' => 'OR',
					'joined',
					'other',
				),
			),
			array(
				'safe'   => 's = 1',
				'joined' => 'j = 2',
				'other'  => 'o = 3',
			),
			array( 'joined' => 'INNER JOIN j ON j.id = t.id' ),
			array( 'safe', 'joined', 'other' )
		);

		$this->assertSame( array( '1 = 0' ), $clause->get_clauses() );
		$this->assertNotEmpty( $clause->get_warnings() );
	}

	/**
	 * Test that a JOIN-emitting parser under a pure-AND path combines normally.
	 *
	 * @since 3.1.0
	 */
	public function test_join_under_and_is_allowed() {
		$clause = $this->where_clause(
			array(
				'relation' => 'AND',
				'columns',
				'relation',
			),
			array(
				'by'       => 's = 1',
				'relation' => 'EXISTS ( SELECT 1 )',
			),
			array( 'relation' => 'INNER JOIN other ON other.id = t.id' ),
			array( 'by', 'relation' )
		);

		$this->assertSame( array( '( s = 1 AND EXISTS ( SELECT 1 ) )' ), $clause->get_clauses() );
		$this->assertSame( array(), $clause->get_warnings() );
	}

	/**
	 * Test that an unknown parser name fails closed and warns.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_parser_fails_closed() {
		$clause = $this->where_clause(
			array(
				'relation' => 'OR',
				'by',
				'bogus',
			),
			array( 'by' => 's = 1' ),
			array(),
			array( 'by' )
		);

		$this->assertSame( array( '1 = 0' ), $clause->get_clauses() );
		$this->assertNotEmpty( $clause->get_warnings() );
	}

	/**
	 * Test that an unrecognized relation fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_malformed_relation_fails_closed() {
		$clause = $this->where_clause(
			array(
				'relation' => 'NAND',
				'a',
				'b',
			),
			array(
				'a' => 'x = 1',
				'b' => 'y = 2',
			),
			array(),
			array( 'a', 'b' )
		);

		$this->assertSame( array( '1 = 0' ), $clause->get_clauses() );
		$this->assertNotEmpty( $clause->get_warnings() );
	}

	/**
	 * Test that a non-array tree (a malformed directive) fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_scalar_tree_fails_closed() {
		$clause = $this->where_clause( 'OR', array( 'by' => 's = 1' ), array(), array( 'by' ) );

		$this->assertSame( array( '1 = 0' ), $clause->get_clauses() );
		$this->assertNotEmpty( $clause->get_warnings() );
	}

	/**
	 * Test that an EMPTY scalar tree ('', 0, false, '0') is malformed, not absent -
	 * it fails closed rather than falling back to the historical AND.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_scalar_tree_fails_closed() {
		foreach ( array( '', '0', 0, false ) as $bad ) {
			$clause = $this->where_clause( $bad, array( 'by' => 's = 1' ), array(), array( 'by' ) );

			$this->assertSame( array( '1 = 0' ), $clause->get_clauses(), 'tree = ' . var_export( $bad, true ) );
			$this->assertNotEmpty( $clause->get_warnings() );
		}
	}

	/**
	 * Test that a group with no items fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_group_fails_closed() {
		$clause = $this->where_clause( array( 'relation' => 'AND' ), array( 'by' => 's = 1' ), array(), array( 'by' ) );

		$this->assertSame( array( '1 = 0' ), $clause->get_clauses() );
		$this->assertNotEmpty( $clause->get_warnings() );
	}

	/**
	 * Test that 'not' => true negates a single-parser group.
	 *
	 * @since 3.1.0
	 */
	public function test_not_negates_single_parser() {
		$clause = $this->where_clause(
			array(
				'relation' => 'AND',
				'not'      => true,
				'by',
			),
			array( 'by' => 'status = 1' ),
			array(),
			array( 'by' )
		);

		$this->assertSame( array( 'NOT ( status = 1 )' ), $clause->get_clauses() );
		$this->assertSame( array(), $clause->get_warnings() );
	}

	/**
	 * Test that 'not' => true negates a multi-parser OR group.
	 *
	 * @since 3.1.0
	 */
	public function test_not_negates_or_group() {
		$clause = $this->where_clause(
			array(
				'relation' => 'OR',
				'not'      => true,
				'by',
				'meta',
			),
			array(
				'by'   => 'x = 1',
				'meta' => 'y = 2',
			),
			array(),
			array( 'by', 'meta' )
		);

		$this->assertSame( array( 'NOT ( x = 1 OR y = 2 )' ), $clause->get_clauses() );
		$this->assertSame( array(), $clause->get_warnings() );
	}

	/**
	 * Test that a negated group still AND-es on an unreferenced active parser.
	 *
	 * @since 3.1.0
	 */
	public function test_not_group_ands_unreferenced_parser() {
		$clause = $this->where_clause(
			array(
				'relation' => 'AND',
				'not'      => true,
				'by',
			),
			array(
				'by'   => 'x = 1',
				'meta' => 'y = 2',
			),
			array(),
			array( 'by', 'meta' )
		);

		$this->assertSame( array( 'NOT ( x = 1 )', 'y = 2' ), $clause->get_clauses() );
		$this->assertSame( array(), $clause->get_warnings() );
	}

	/**
	 * Test that a JOIN-emitting parser under NOT fails closed (its INNER JOIN
	 * pre-filters rows, so the negation cannot be expressed).
	 *
	 * @since 3.1.0
	 */
	public function test_join_under_not_fails_closed() {
		$clause = $this->where_clause(
			array(
				'relation' => 'AND',
				'not'      => true,
				'rel',
			),
			array( 'rel' => 'r.x = 1' ),
			array( 'rel' => 'INNER JOIN r ON r.id = t.rel_id' ),
			array( 'rel' )
		);

		$this->assertSame( array( '1 = 0' ), $clause->get_clauses() );
		$this->assertNotEmpty( $clause->get_warnings() );
	}
}
