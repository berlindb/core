<?php
/**
 * Operand invariants (width / scalar / side) tests.
 *
 * The operand "facts" - comparison pattern, width, left-ness, scalar-ness - are
 * held as properties on Operands\Base with thin accessors, computed at construction.
 * These pure-object tests pin the shape-level invariants the Predicate relies on,
 * independent of the resolver (which never builds an empty collection/tuple, so the
 * empty-construction defaults below are a defensive guard rather than a live path).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Operands\Collection;
use BerlinDB\Database\Operands\Range;
use BerlinDB\Database\Operands\Tuple;
use BerlinDB\Database\Operands\Value;
use PHPUnit\Framework\TestCase;

/**
 * Tests for operand shape invariants.
 *
 * @since 3.1.0
 */
class OperandInvariantsTest extends TestCase {

	/**
	 * An empty collection reports width 0 ( not the Base default of 1 ), whether
	 * constructed with no args ( init skipped ) or an empty operand list.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_collection_is_zero_width() {
		$this->assertSame( 0, ( new Collection() )->get_width() );
		$this->assertSame( 0, ( new Collection( array( 'operands' => array() ) ) )->get_width() );
	}

	/**
	 * An empty tuple reports width 0, not the Base default of 1.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_tuple_is_zero_width() {
		$this->assertSame( 0, ( new Tuple() )->get_width() );
	}

	/**
	 * A populated tuple is as wide as its member count.
	 *
	 * @since 3.1.0
	 */
	public function test_tuple_width_is_member_count() {
		$tuple = new Tuple(
			array(
				'operands' => array(
					new Value( array( 'sql' => '1' ) ),
					new Value( array( 'sql' => '2' ) ),
				),
			)
		);

		$this->assertSame( 2, $tuple->get_width() );
	}

	/**
	 * The value shapes ( collection / range / tuple ) are non-scalar, so an opt-in
	 * cast on them fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_value_shapes_are_non_scalar() {
		$this->assertFalse( ( new Collection() )->is_scalar() );
		$this->assertFalse( ( new Range() )->is_scalar() );
		$this->assertFalse( ( new Tuple() )->is_scalar() );
	}

	/**
	 * A single prepared value is scalar ( the Base default ), so it can be cast.
	 *
	 * @since 3.1.0
	 */
	public function test_value_is_scalar() {
		$this->assertTrue( ( new Value( array( 'sql' => '1' ) ) )->is_scalar() );
	}

	/**
	 * A collection and a range are value-only ( right side ); a tuple is a value that
	 * can also be a left subject.
	 *
	 * @since 3.1.0
	 */
	public function test_left_subject_eligibility() {
		$this->assertFalse( ( new Collection() )->can_be_left() );
		$this->assertFalse( ( new Range() )->can_be_left() );
		$this->assertTrue( ( new Tuple() )->can_be_left() );
	}
}
