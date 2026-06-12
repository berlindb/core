<?php
/**
 * Meta preset Table provisioning (#204 Phase A).
 *
 * Presets\Meta\Table is a one-line stub naming its meta Query stub; it derives
 * its name, prefix, and the exact generated Schema instance from that query, then
 * installs through the normal Table lifecycle.
 *
 * The table is uninstalled after the class completes, because DDL statements
 * cause an implicit MySQL commit that bypasses the WP test framework's per-test
 * transaction rollback.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Presets\Meta\Query as MetaQuery;
use BerlinDB\Database\Presets\Meta\Table as MetaTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/** Primary schema. */
class MetaTablePrimarySchema extends Schema {
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'primary'  => true,
			'extra'    => 'auto_increment',
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Primary Query. */
class MetaTablePrimaryQuery extends Query {
	protected $prefix       = 'mtt';
	protected $table_name   = 'widgets';
	protected $table_schema = MetaTablePrimarySchema::class;
	protected $item_name    = 'widget';
	protected $cache_group  = 'widgets';
}

/** The meta Query stub. */
class MetaTableWidgetMetaQuery extends MetaQuery {
	protected $primary = MetaTablePrimaryQuery::class;
}

/** The meta Table stub. */
class MetaTableWidgetMetaTable extends MetaTable {
	protected $query = MetaTableWidgetMetaQuery::class;
}

/** A misconfigured table stub naming no query. */
class MetaTableOrphanTable extends MetaTable {}

/** A misconfigured table stub naming a class that is not a meta Query. */
class MetaTableWrongClassTable extends MetaTable {
	protected $query = MetaTablePrimaryQuery::class;
}

/** A meta Query stub that itself fails to configure (no primary). */
class MetaTableBrokenMetaQuery extends MetaQuery {}

/** A misconfigured table stub naming a broken meta Query. */
class MetaTableBrokenQueryTable extends MetaTable {
	protected $query = MetaTableBrokenMetaQuery::class;
}

/**
 * Tests for Presets\Meta\Table.
 *
 * @since 3.1.0
 */
class MetaTableTest extends TestCase {

	/**
	 * The shared installed meta table.
	 *
	 * @var MetaTableWidgetMetaTable
	 */
	private static $table;

	/**
	 * Install the meta table once for this class.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new MetaTableWidgetMetaTable();

		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
	}

	/**
	 * Uninstall the meta table after all tests in this class complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * The table derives identity + schema from the stub and is installed.
	 *
	 * (Installed by setUpBeforeClass — and Table::init() also auto-upgrades when
	 * testing, so this proves "installed after construction + setup", not the
	 * install() call path in isolation.)
	 *
	 * @since 3.1.0
	 */
	public function test_meta_table_is_installed() {
		$this->assertTrue( self::$table->exists() );

		// The physical columns match the generated EAV schema.
		$columns = self::$table->columns();
		$this->assertIsArray( $columns );

		$names = array_map(
			static function ( $column ) {
				return is_object( $column ) ? $column->Field : ( $column['Field'] ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			},
			$columns
		);

		$this->assertContains( 'meta_id', $names );
		$this->assertContains( 'widget_id', $names );
		$this->assertContains( 'meta_key', $names );
		$this->assertContains( 'meta_value', $names );
	}

	/**
	 * The Table and Query stubs derive the same plugin-prefixed identity.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_table_name_matches_query() {
		$query = new MetaTableWidgetMetaQuery();

		$this->assertSame( 'widget_meta', $query->get_item_name() );
		$this->assertStringContainsString( 'mtt_widget_meta', $query->get_table_name() );
	}

	/**
	 * A table stub naming no meta Query fails loudly.
	 *
	 * @since 3.1.0
	 */
	public function test_missing_query_logs_warning() {
		$table = new MetaTableOrphanTable();

		$this->assertNotEmpty( $table->get_logs( array( 'code' => 'meta_table_query_missing' ) ) );
	}

	/**
	 * A table stub naming a non-meta Query class fails loudly.
	 *
	 * @since 3.1.0
	 */
	public function test_wrong_class_logs_warning() {
		$table = new MetaTableWrongClassTable();

		$this->assertNotEmpty( $table->get_logs( array( 'code' => 'meta_table_not_meta_query' ) ) );
	}

	/**
	 * A table stub naming a misconfigured meta Query fails loudly.
	 *
	 * @since 3.1.0
	 */
	public function test_misconfigured_query_logs_warning() {
		$table = new MetaTableBrokenQueryTable();

		$this->assertNotEmpty( $table->get_logs( array( 'code' => 'meta_table_query_misconfigured' ) ) );
	}
}
