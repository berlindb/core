<?php
/**
 * Arithmetic operator tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Operators\Arithmetic\Add;
use BerlinDB\Database\Operators\Arithmetic\Divide;
use BerlinDB\Database\Operators\Arithmetic\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Operators\Arithmetic family and its registry.
 *
 * @since 3.1.0
 */
class ArithmeticOperatorsTest extends TestCase {

	/**
	 * Each operator reports its infix symbol; integer operators are '%d', division '%f'.
	 *
	 * @since 3.1.0
	 */
	public function test_operators_carry_symbol_and_pattern() {
		$this->assertSame( '+', ( new Add() )->get_symbol() );
		$this->assertSame( '%d', ( new Add() )->get_pattern() );

		$this->assertSame( '/', ( new Divide() )->get_symbol() );
		$this->assertSame( '%f', ( new Divide() )->get_pattern() );
	}

	/**
	 * The registry resolves a symbol to its operator, and an unknown symbol to null.
	 *
	 * @since 3.1.0
	 */
	public function test_registry_resolves_by_symbol() {
		$registry = new Registry();

		$this->assertInstanceOf( Add::class, $registry->get_operator( '+' ) );
		$this->assertInstanceOf( Divide::class, $registry->get_operator( '/' ) );
		$this->assertNull( $registry->get_operator( '^' ) );
		$this->assertCount( 4, $registry->all() );
	}
}
