<?php
/**
 * Column Preset Intercept Context tests (#233).
 *
 * Context is the value object a preset's intercept() receives alongside the value:
 * it carries the method, the Column, and whether the caller supplied the column
 * (key presence), and vends the column's unset sentinel. DB-free.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Column;
use BerlinDB\Database\Presets\Column\Context;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BerlinDB\Database\Presets\Column\Context.
 *
 * @since 3.1.0
 */
class ContextTest extends TestCase {

	/**
	 * Build a plain datetime column.
	 *
	 * @since 3.1.0
	 * @return Column
	 */
	private function column(): Column {
		return new Column(
			array(
				'name' => 'when',
				'type' => 'datetime',
			)
		);
	}

	/**
	 * Test that the context exposes its method, column, and provided flag.
	 *
	 * @since 3.1.0
	 */
	public function test_context_exposes_method_column_and_provided() {
		$column  = $this->column();
		$context = new Context( 'update', $column, false );

		$this->assertSame( 'update', $context->method() );
		$this->assertSame( $column, $context->column() );
		$this->assertFalse( $context->provided() );
	}

	/**
	 * Test that provided() defaults to true when not specified.
	 *
	 * @since 3.1.0
	 */
	public function test_context_provided_defaults_to_true() {
		$context = new Context( 'insert', $this->column() );

		$this->assertTrue( $context->provided() );
	}

	/**
	 * Test that unset_value() returns the column's own unset sentinel.
	 *
	 * @since 3.1.0
	 */
	public function test_context_unset_value_matches_the_columns_sentinel() {
		$column  = $this->column();
		$context = new Context( 'insert', $column );

		$this->assertSame( $column->get_unset_sentinel(), $context->unset_value() );
		$this->assertTrue( $column->is_unset_sentinel( $context->unset_value() ) );
	}
}
