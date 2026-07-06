<?php
/**
 * Logical operator tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Operators\Logical\Conjunction;
use BerlinDB\Database\Operators\Logical\Disjunction;
use BerlinDB\Database\Operators\Logical\ExclusiveDisjunction;
use BerlinDB\Database\Operators\Logical\Negation;
use BerlinDB\Database\Operators\Logical\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Operators\Logical family and its relation registry.
 *
 * @since 3.1.0
 */
class LogicalOperatorsTest extends TestCase {

	/**
	 * Each relation reports its keyword and is n-ary ( not unary ).
	 *
	 * @since 3.1.0
	 */
	public function test_relations_carry_keyword_and_are_not_unary() {
		$this->assertSame( 'AND', ( new Conjunction() )->get_symbol() );
		$this->assertSame( 'OR', ( new Disjunction() )->get_symbol() );
		$this->assertSame( 'XOR', ( new ExclusiveDisjunction() )->get_symbol() );

		$this->assertFalse( ( new Conjunction() )->is_unary() );
		$this->assertFalse( ( new Disjunction() )->is_unary() );
		$this->assertFalse( ( new ExclusiveDisjunction() )->is_unary() );
	}

	/**
	 * Negation is the unary NOT operator.
	 *
	 * @since 3.1.0
	 */
	public function test_negation_is_unary_not() {
		$this->assertSame( 'NOT', ( new Negation() )->get_symbol() );
		$this->assertTrue( ( new Negation() )->is_unary() );
	}

	/**
	 * The registry resolves a keyword to its relation, and an unknown keyword to null.
	 *
	 * @since 3.1.0
	 */
	public function test_registry_resolves_relations_by_keyword() {
		$registry = new Registry();

		$this->assertInstanceOf( Conjunction::class, $registry->get_operator( 'AND' ) );
		$this->assertInstanceOf( Disjunction::class, $registry->get_operator( 'OR' ) );
		$this->assertInstanceOf( ExclusiveDisjunction::class, $registry->get_operator( 'XOR' ) );
		$this->assertNull( $registry->get_operator( 'NAND' ) );
		$this->assertCount( 3, $registry->all() );
	}

	/**
	 * Negation is orthogonal to the relations, so it is NOT in the relation registry.
	 *
	 * @since 3.1.0
	 */
	public function test_registry_excludes_negation() {
		$this->assertNull( ( new Registry() )->get_operator( 'NOT' ) );
	}
}
