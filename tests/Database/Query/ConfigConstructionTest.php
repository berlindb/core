<?php
/**
 * Config-only construction (#204 Phase 0a) — a full table-set (Schema, Table,
 * Query) defined purely from config arrays, with NO subclasses.
 *
 * Boot::__construct() -> configure()/set_vars() hydrates any kern class from an
 * array (the path `new Column([...])` uses), set_schema() accepts a Schema
 * instance, and the 2nd constructor arg ($config) lets a Query receive its
 * identity before sunrise() (with empty query vars, so no query fires). This is
 * the foundation a registry/factory for presets (MetaTable, #204) builds on —
 * no eval / anonymous classes / codegen required.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Row;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Config-only construction of a full table-set.
 *
 * @since 3.1.0
 */
class ConfigConstructionTest extends TestCase {

	/** @var Table */
	private static $table;

	/**
	 * The schema definition as plain config (no subclass).
	 *
	 * @return array<string, mixed>
	 */
	private static function schema_config(): array {
		return array(
			'columns' => array(
				array(
					'name'      => 'id',
					'type'      => 'bigint',
					'length'    => '20',
					'unsigned'  => true,
					'extra'     => 'auto_increment',
					'cache_key' => true,
					'sortable'  => true,
				),
				array(
					'name'    => 'title',
					'type'    => 'varchar',
					'length'  => '191',
					'default' => '',
				),
			),
			'indexes' => array(
				array(
					'type'    => 'primary',
					'columns' => array( 'id' ),
				),
			),
		);
	}

	/**
	 * Build a Table and Query entirely from config, install the table once.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new Table(
			array(
				'name'    => 'berlindb_spike_test',
				'version' => '202606020',
				'schema'  => new Schema( self::schema_config() ),
			)
		);

		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
	}

	/**
	 * Uninstall the spike table after the suite.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * A Schema built from a config array exposes its columns.
	 *
	 * @since 3.1.0
	 */
	public function test_schema_from_config_has_columns() {
		$schema = new Schema( self::schema_config() );
		$names  = wp_list_pluck( $schema->get_columns(), 'name' );

		$this->assertContains( 'id', $names );
		$this->assertContains( 'title', $names );
	}

	/**
	 * A Table built from a config array (with a Schema instance) installs.
	 *
	 * @since 3.1.0
	 */
	public function test_table_installs_from_config() {
		$this->assertTrue( self::$table->exists() );
	}

	/**
	 * A Query built purely from config round-trips add_item -> get_item -> query,
	 * with NO Query subclass.
	 *
	 * The definition carries a Schema, so Query::configure() recognizes it as
	 * config (not query vars), assigns it to properties before sunrise() reads
	 * them, and runs no query on construction. This is the #204 Phase 0a
	 * mechanism that makes the MetaTable preset / a registry possible.
	 *
	 * @since 3.1.0
	 */
	public function test_query_round_trips_from_config() {
		wp_set_current_user( 1 );

		$query = new Query(
			array(
				'prefix'           => 'berlindb',
				'table_name'       => 'spike_test',
				'table_alias'      => 'sp',
				'table_schema'     => new Schema( self::schema_config() ),
				'item_name'        => 'spike',
				'item_name_plural' => 'spikes',
				'item_shape'       => Row::class,
				'cache_group'      => 'berlindb-spike',
			)
		);

		// Configured and sealed, but un-run.
		$this->assertTrue( $query->is_booted() );

		$id = (int) $query->add_item( array( 'title' => 'Hello' ) );
		$this->assertGreaterThan( 0, $id );

		$item = $query->get_item( $id );
		$this->assertNotEmpty( $item );
		$this->assertSame( 'Hello', $item->title );

		$results = $query->query( array() );
		$ids     = array_map( 'intval', (array) wp_list_pluck( $results, 'id' ) );
		$this->assertContains( $id, $ids );
	}

	/**
	 * The type-stable structural query vars are canonicalized (number '5' -> 5,
	 * order 'asc' -> 'ASC', booleans coerced) so equivalent queries share a key.
	 *
	 * @since 3.1.0
	 */
	public function test_structural_query_vars_are_canonicalized() {
		wp_set_current_user( 1 );

		$query = new Query(
			array(
				'prefix'           => 'berlindb',
				'table_name'       => 'spike_test',
				'table_alias'      => 'sp',
				'table_schema'     => new Schema( self::schema_config() ),
				'item_name'        => 'spike',
				'item_name_plural' => 'spikes',
				'item_shape'       => Row::class,
				'cache_group'      => 'berlindb-spike',
			)
		);

		$query->query(
			array(
				'number'        => '5',
				'order'         => 'asc',
				'no_found_rows' => '',
			)
		);

		$this->assertSame( 5, $query->query_vars['number'] );
		$this->assertSame( 'ASC', $query->query_vars['order'] );
		$this->assertSame( false, $query->query_vars['no_found_rows'] );
	}
}
