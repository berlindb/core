<?php
/**
 * BooleanGroup clause tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Clauses\BooleanGroup;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Clauses\BooleanGroup rendering.
 *
 * @since 3.1.0
 */
class BooleanGroupTest extends TestCase {

	/**
	 * Test that an empty group (no items, or only empty items) renders ''.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_renders_nothing() {
		$this->assertSame( '', ( new BooleanGroup( 'AND', array() ) )->get_sql() );
		$this->assertSame( '', ( new BooleanGroup( 'AND', array( '', '   ' ) ) )->get_sql() );
	}

	/**
	 * Test that a single item renders bare (no wrapping parentheses).
	 *
	 * @since 3.1.0
	 */
	public function test_single_item_is_not_parenthesised() {
		$this->assertSame( 'a = 1', ( new BooleanGroup( 'AND', array( 'a = 1' ) ) )->get_sql() );

		// Empty siblings are dropped, leaving a single bare item.
		$this->assertSame( 'a = 1', ( new BooleanGroup( 'OR', array( '', 'a = 1', '' ) ) )->get_sql() );
	}

	/**
	 * Test that multiple items are joined by the relation and parenthesised.
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_items_joined_and_parenthesised() {
		$this->assertSame(
			'( a = 1 AND b = 2 )',
			( new BooleanGroup( 'AND', array( 'a = 1', 'b = 2' ) ) )->get_sql()
		);
		$this->assertSame(
			'( a = 1 OR b = 2 )',
			( new BooleanGroup( 'OR', array( 'a = 1', 'b = 2' ) ) )->get_sql()
		);
	}

	/**
	 * Test that an unrecognized relation falls back to AND.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_relation_falls_back_to_and() {
		$this->assertSame(
			'( a = 1 AND b = 2 )',
			( new BooleanGroup( 'XOR', array( 'a = 1', 'b = 2' ) ) )->get_sql()
		);
	}

	/**
	 * Test that nested groups compose to arbitrary depth.
	 *
	 * @since 3.1.0
	 */
	public function test_nested_groups() {
		$inner = new BooleanGroup( 'OR', array( 'c = 3', 'd = 4' ) );
		$outer = new BooleanGroup( 'AND', array( 'a = 1', $inner ) );

		$this->assertSame( '( a = 1 AND ( c = 3 OR d = 4 ) )', $outer->get_sql() );
	}

	/**
	 * Test that a negated group wraps in NOT( ... ).
	 *
	 * @since 3.1.0
	 */
	public function test_negated_group() {
		$this->assertSame(
			'NOT ( a = 1 OR b = 2 )',
			( new BooleanGroup( 'OR', array( 'a = 1', 'b = 2' ), true ) )->get_sql()
		);

		// A negated single item still wraps in NOT.
		$this->assertSame(
			'NOT ( a = 1 )',
			( new BooleanGroup( 'AND', array( 'a = 1' ), true ) )->get_sql()
		);
	}
}
