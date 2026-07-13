<?php
/**
 * Read-only query enforcement tests (#235 step 6).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestSchema;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * A read-only schema over the same test_widgets shape.
 *
 * @since 3.1.0
 */
class ReadOnlyWidgetSchema extends TestSchema {
	protected $read_only = true;
}

/**
 * A query configured with the read-only schema (same table).
 *
 * @since 3.1.0
 */
class ReadOnlyWidgetQuery extends TestQuery {
	protected $table_schema = ReadOnlyWidgetSchema::class;
}

/**
 * Read-only is DERIVED from the schema: a Query configured with a read-only
 * schema (a View's schema, or any reference relation) refuses every write via
 * can_write(), so the caller never sets a per-query flag. Reads are unaffected.
 *
 * @since 3.1.0
 */
class QueryReadOnlyTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$table = new TestTable();
		if ( ! $table->exists() ) {
			$table->install();
		}
	}

	/**
	 * A schema declaring read_only reports it; the default schema does not.
	 *
	 * @since 3.1.0
	 */
	public function test_schema_reports_read_only() {
		$this->assertTrue( ( new ReadOnlyWidgetSchema() )->is_read_only() );
		$this->assertFalse( ( new TestSchema() )->is_read_only() );
	}

	/**
	 * A query derives writability from its schema.
	 *
	 * @since 3.1.0
	 */
	public function test_query_derives_writability_from_schema() {
		$this->assertFalse( ( new ReadOnlyWidgetQuery() )->can_write() );
		$this->assertTrue( ( new TestQuery() )->can_write() );
	}

	/**
	 * Single-item writes are refused on a read-only query (before touching the DB).
	 *
	 * @since 3.1.0
	 */
	public function test_read_only_refuses_single_writes() {
		$query = new ReadOnlyWidgetQuery();

		$this->assertFalse( $query->add_item( array( 'name' => 'x' ) ) );
		$this->assertFalse( $query->update_item( 1, array( 'name' => 'y' ) ) );
		$this->assertFalse( $query->delete_item( 1 ) );
		$this->assertFalse( $query->copy_item( 1 ) );
	}

	/**
	 * Batch writes are refused on a read-only query with a single clean refusal.
	 *
	 * @since 3.1.0
	 */
	public function test_read_only_refuses_batch_writes() {
		$query = new ReadOnlyWidgetQuery();

		$this->assertSame( array(), $query->add_items( array( array( 'name' => 'x' ) ) ) );
		$this->assertFalse( $query->update_items( array( 1 ), array( 'name' => 'y' ) ) );
		$this->assertFalse( $query->delete_items( array( 1 ) ) );
	}

	/**
	 * A writable query still writes (the gate does not over-block).
	 *
	 * @since 3.1.0
	 */
	public function test_writable_query_still_writes() {
		$query = new TestQuery();

		$id = $query->add_item( array( 'name' => 'writable' ) );
		$this->assertNotEmpty( $id );

		$query->delete_item( $id );
	}
}
