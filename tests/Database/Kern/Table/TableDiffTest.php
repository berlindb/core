<?php
/**
 * Table::diff() / diverged() integration tests.
 *
 * Exercises the live-table side of the Diff subsystem: Table::diff() introspects
 * the current table (Schema::from_table) and compares it to the declared schema.
 * A freshly installed table should match its schema and therefore not diverge.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Diff\Patch;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Integration tests for Table::diff() and Table::diverged().
 *
 * @since 3.1.0
 */
class TableDiffTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/**
	 * Install the test table once.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();

		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
	}

	/**
	 * diff() returns a Patch.
	 *
	 * @since 3.1.0
	 */
	public function test_diff_returns_a_patch() {
		$this->assertInstanceOf( Patch::class, self::$table->diff() );
	}

	/**
	 * A freshly installed table matches its declared schema (no drift).
	 *
	 * @since 3.1.0
	 */
	public function test_freshly_installed_table_does_not_diverge() {
		$this->assertTrue( self::$table->diff()->is_empty() );
		$this->assertFalse( self::$table->diverged() );
	}

	/**
	 * Real drift on the live table is detected: a dropped index reads as "added".
	 *
	 * Exercises the full live path - SHOW INDEX introspection, normalization, and
	 * comparison against the declared schema - end to end, not just the pure
	 * Comparator. The declared 'status' index is missing from the introspected
	 * table, so the diff reports it as an addition (the change that reconciles the
	 * live table back to its declared schema).
	 *
	 * @since 3.1.0
	 */
	public function test_diff_detects_a_dropped_live_index() {
		// Introduce real drift: drop a derived lookup index from the live table.
		self::$table->drop_index( 'status' );

		$patch = self::$table->diff();
		$names = array_map(
			static function ( $index ) {
				return $index->name;
			},
			$patch->added_indexes()
		);

		// Reconcile the live table back to its declared schema (the patch is the recipe).
		foreach ( $patch->added_indexes() as $index ) {
			self::$table->add_index( $index );
		}

		$this->assertFalse( $patch->is_empty() );
		$this->assertContains( 'status', $names );
		$this->assertFalse( self::$table->diverged() );
	}
}
