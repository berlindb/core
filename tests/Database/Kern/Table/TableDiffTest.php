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
	 * Real drift is detected and apply() reconciles it: a dropped index is re-added.
	 *
	 * Exercises the full live path - SHOW INDEX introspection, normalization, and
	 * comparison against the declared schema - then renders (to_sql) and runs
	 * (apply) the reconciling ALTER end to end, not just the pure Comparator. The
	 * declared 'status' index is missing from the introspected table, so the diff
	 * reports it as an addition and apply() adds it back.
	 *
	 * @since 3.1.0
	 */
	public function test_apply_reconciles_a_dropped_live_index() {
		// Introduce real drift: drop a derived lookup index from the live table.
		self::$table->drop_index( 'status' );

		$patch = self::$table->diff();
		$names = array_map(
			static function ( $index ) {
				return $index->name;
			},
			$patch->added_indexes()
		);

		// The default operations preview the re-add as an ADD statement.
		$sql = $patch->to_sql();

		$this->assertFalse( $patch->is_empty() );
		$this->assertContains( 'status', $names );
		$this->assertNotEmpty( $sql );
		$this->assertStringContainsString( 'ADD', $sql[0] );
		$this->assertStringContainsString( 'status', $sql[0] );

		// Apply reconciles the live table back to its declared schema.
		$this->assertTrue( $patch->apply()->is_successful() );
		$this->assertFalse( self::$table->diverged() );
	}

	/**
	 * apply() defaults to 'add'+'modify' and leaves drops alone; opting in performs them.
	 *
	 * An extra index that the declared schema does not have reads as a "drop". The
	 * default apply must not remove it (a possibly-incomplete introspection must not
	 * authorize a destructive change); apply(['add','modify','drop']) does.
	 *
	 * @since 3.1.0
	 */
	public function test_apply_gates_drops_behind_the_drop_operation() {
		// Introduce drift the diff will classify as a drop: an undeclared index.
		self::$table->add_index(
			array(
				'name'    => 'diff_extra_idx',
				'columns' => array( 'status' ),
			)
		);

		// The default apply (add + modify) must not touch the extra index.
		self::$table->diff()->apply();

		$this->assertTrue( self::$table->index_exists( 'diff_extra_idx' ) );
		$this->assertTrue( self::$table->diverged() );

		// Opting into drops removes it and reconciles the table.
		$this->assertTrue( self::$table->diff()->apply( array( 'add', 'modify', 'drop' ) )->is_successful() );
		$this->assertFalse( self::$table->index_exists( 'diff_extra_idx' ) );
		$this->assertFalse( self::$table->diverged() );
	}

	/**
	 * A clean table renders no statements (nothing to reconcile).
	 *
	 * @since 3.1.0
	 */
	public function test_to_sql_is_empty_for_a_clean_table() {
		$this->assertSame( array(), self::$table->diff()->to_sql() );
		$this->assertSame( array(), self::$table->diff()->to_sql( array( 'add', 'modify', 'drop' ) ) );
	}

	/**
	 * revert() carries the table binding, so the inverse patch is still runnable.
	 *
	 * The diff of a dropped index reports it as an addition; the inverse reports it
	 * as a drop. An unbound patch renders nothing, so non-empty SQL from the reverted
	 * patch proves the binding survived revert().
	 *
	 * @since 3.1.0
	 */
	public function test_revert_carries_the_table_binding() {
		// Introduce real drift: drop a derived lookup index from the live table.
		self::$table->drop_index( 'status' );

		$patch    = self::$table->diff();
		$reverted = $patch->revert();

		// The inverse of "add status" is "drop status" - and it still has a table.
		$sql = $reverted->to_sql( array( 'add', 'modify', 'drop' ) );

		// Restore the table by applying the original (re-adds status).
		$patch->apply();

		$this->assertNotEmpty( $sql );
		$this->assertStringContainsString( 'DROP INDEX', $sql[0] );
		$this->assertStringContainsString( 'status', $sql[0] );
		$this->assertFalse( self::$table->diverged() );
	}
}
