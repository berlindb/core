<?php
/**
 * Snapshot value-object tests.
 *
 * A Snapshot wraps an introspected Schema with how complete that capture is. Pure
 * value-object behavior, no database - the live introspection is covered by
 * SchemaSnapshotTest.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Diff\Snapshot;
use BerlinDB\Database\Kern\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Diff Snapshot value object.
 *
 * @since 3.1.0
 */
class SnapshotTest extends TestCase {

	/**
	 * schema() returns the wrapped schema.
	 *
	 * @since 3.1.0
	 */
	public function test_schema_accessor_returns_the_wrapped_schema() {
		$schema   = new Schema();
		$snapshot = new Snapshot( $schema, true, true );

		$this->assertSame( $schema, $snapshot->schema() );
	}

	/**
	 * A found table with fully-introspected indexes is complete.
	 *
	 * @since 3.1.0
	 */
	public function test_exists_and_indexes_complete_is_complete() {
		$snapshot = new Snapshot( new Schema(), true, true );

		$this->assertTrue( $snapshot->exists() );
		$this->assertTrue( $snapshot->indexes_complete() );
		$this->assertTrue( $snapshot->is_complete() );
	}

	/**
	 * A missing table is not complete (and never could be acted on).
	 *
	 * @since 3.1.0
	 */
	public function test_missing_table_is_not_complete() {
		$snapshot = new Snapshot( new Schema(), false, false );

		$this->assertFalse( $snapshot->exists() );
		$this->assertFalse( $snapshot->is_complete() );
	}

	/**
	 * A found table with incompletely-introspected indexes is not complete.
	 *
	 * @since 3.1.0
	 */
	public function test_incomplete_indexes_is_not_complete() {
		$snapshot = new Snapshot( new Schema(), true, false );

		$this->assertTrue( $snapshot->exists() );
		$this->assertFalse( $snapshot->indexes_complete() );
		$this->assertFalse( $snapshot->is_complete() );
	}

	/**
	 * is_complete() requires existence even if indexes are flagged complete.
	 *
	 * A not-found table cannot have meaningful indexes; guard the AND explicitly.
	 *
	 * @since 3.1.0
	 */
	public function test_not_found_is_never_complete() {
		$snapshot = new Snapshot( new Schema(), false, true );

		$this->assertFalse( $snapshot->is_complete() );
	}
}
