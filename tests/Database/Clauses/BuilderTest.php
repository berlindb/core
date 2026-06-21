<?php
/**
 * Builder unit tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Clauses\Builder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Clauses\Builder - the inert engine that assembles per-parser
 * JOIN/WHERE fragments into clause lists (JOINs flattened, WHEREs combined per the
 * optional 'criteria' tree). The combine policy itself is covered by WhereTest /
 * JoinTest; these assert the builder wires them together and surfaces warnings.
 *
 * @since 3.1.0
 */
class BuilderTest extends TestCase {

	/**
	 * Test that without a criteria tree, JOINs flatten and WHEREs AND together.
	 *
	 * @since 3.1.0
	 */
	public function test_combines_join_and_where_without_criteria() {
		$builder = new Builder(
			array(
				'join'    => array(
					'meta' => 'LEFT JOIN m ON m.id = t.id',
				),
				'where'   => array(
					'by'   => 'x = 1',
					'meta' => 'y = 2',
				),
				'parsers' => array( 'by', 'meta' ),
			)
		);

		$this->assertSame( array( 'LEFT JOIN m ON m.id = t.id' ), $builder->get_join_clauses() );
		$this->assertSame( array( 'x = 1', 'y = 2' ), $builder->get_where_clauses() );
		$this->assertSame( array(), $builder->get_warnings() );
	}

	/**
	 * Test that a criteria tree is applied to the WHERE fragments.
	 *
	 * @since 3.1.0
	 */
	public function test_criteria_tree_applied() {
		$builder = new Builder(
			array(
				'criteria' => array(
					'relation' => 'OR',
					'columns',
					'meta',
				),
				'where'    => array(
					'by'   => 'x = 1',
					'meta' => 'y = 2',
				),
				'parsers'  => array( 'by', 'meta' ),
			)
		);

		$this->assertSame( array( '( x = 1 OR y = 2 )' ), $builder->get_where_clauses() );
		$this->assertSame( array(), $builder->get_warnings() );
	}

	/**
	 * Test that a misconfigured criteria tree fails closed and surfaces a warning.
	 *
	 * @since 3.1.0
	 */
	public function test_bad_criteria_fail_closed_and_warn() {
		$builder = new Builder(
			array(
				'criteria' => array(
					'relation' => 'OR',
					'columns',
					'bogus',
				),
				'where'    => array( 'by' => 'x = 1' ),
				'parsers'  => array( 'by' ),
			)
		);

		$this->assertSame( array( '1 = 0' ), $builder->get_where_clauses() );
		$this->assertNotEmpty( $builder->get_warnings() );
	}
}
