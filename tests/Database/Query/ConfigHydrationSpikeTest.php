<?php
/**
 * SPIKE (#204 Phase 0a) — viability of defining a full table-set purely from
 * config arrays, with NO Schema/Table/Query/Row subclasses.
 *
 * The hypothesis: Boot::__construct() -> set_vars() already hydrates any kern
 * class from an array (the same path `new Column([...])` uses), and set_schema()
 * already accepts a Schema instance. If so, a registry/factory for presets
 * (MetaTable, #204) needs no eval / anonymous classes / codegen — just config +
 * a registry. This spike exercises construct -> install -> CRUD entirely from
 * config to confirm that, and to surface whatever breaks.
 *
 * Run alone: bin/run-tests.sh -p 8.2 -w 6.7 -- --filter ConfigHydrationSpikeTest
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Config-only hydration spike.
 *
 * @since 3.1.0
 */
class ConfigHydrationSpikeTest extends TestCase {

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
	 * SPIKE FINDING — a Query's identity is NOT config-constructable today, so
	 * `new Query([ 'table_name' => ..., 'table_schema' => ... ])` configures
	 * nothing and queries an unconfigured table (observed: `FROM '' i`).
	 *
	 * Unlike Table/Schema (boot -> set_vars -> properties), Query overrides
	 * parse_args() to treat constructor args as QUERY VARS, run the query
	 * immediately, and return [] so set_vars() is skipped (Query.php ~259). Its
	 * identity (table_name/table_schema/item_name/alias/cache_group) is read in
	 * sunrise() from SUBCLASS PROPERTIES.
	 *
	 * => #204 Phase 0a must give Query a DEFINITION channel separate from its
	 *    query-var constructor. Schema + Table already construct from config
	 *    (see the passing tests above); Query is the one gap.
	 *
	 * @since 3.1.0
	 */
	public function test_query_identity_is_not_config_constructable_yet() {
		$this->markTestSkipped(
			'Query identity is not config-constructable yet (#204 Phase 0a): '
			. 'Query::parse_args() reserves constructor args for query vars and '
			. 'skips set_vars(), so table identity must come from a separate '
			. 'definition channel. Schema and Table already hydrate from config.'
		);
	}
}
