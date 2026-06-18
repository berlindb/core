<?php
/**
 * IS NULL / IS NOT NULL parser integration tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Fixtures: a table with a nullable column.
// ---------------------------------------------------------------------------

/** Schema with a nullable 'nickname' column ($allow_null). */
class NullThingSchema extends Schema {
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'primary'  => true,
			'extra'    => 'auto_increment',
		),
		array(
			'name'   => 'label',
			'type'   => 'varchar',
			'length' => '50',
		),
		array(
			'name'       => 'nickname',
			'type'       => 'varchar',
			'length'     => '50',
			'allow_null' => true,
			'default'    => null,
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Query over the nullable-column table. */
class NullThingQuery extends Query {
	protected $prefix       = 'inull';
	protected $table_name   = 'things';
	protected $table_schema = NullThingSchema::class;
	protected $item_name    = 'thing';
	protected $cache_group  = 'inull_things';
}

/** Table for the nullable-column fixture. */
class NullThingTable extends Table {
	protected $prefix  = 'inull';
	protected $name    = 'things';
	protected $version = '1.0.0';
	protected $schema  = NullThingSchema::class;
}

/**
 * Tests that compare_key IS NULL / IS NOT NULL query a nullable column.
 *
 * A: nickname = 'Ace'  (non-null)
 * B: nickname = NULL
 * C: nickname = NULL
 *
 * @since 3.1.0
 */
class IsNullParserTest extends TestCase {

	/** @var NullThingTable */
	private static $table;

	/** @var array<string, int> label => id for the current test. */
	private $ids = array();

	/**
	 * Install the fixture table once.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new NullThingTable();

		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
	}

	/**
	 * Uninstall the fixture table after the class.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Seed one row with a nickname and two with NULL nicknames.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		self::$table->delete_all();
		wp_cache_flush();

		$things = new NullThingQuery();

		// A has a nickname; B and C leave it unset so it defaults to NULL.
		$this->ids['A'] = (int) $things->add_item(
			array(
				'label'    => 'A',
				'nickname' => 'Ace',
			)
		);
		$this->ids['B'] = (int) $things->add_item( array( 'label' => 'B' ) );
		$this->ids['C'] = (int) $things->add_item( array( 'label' => 'C' ) );

		wp_cache_flush();
	}

	/**
	 * Labels of the rows a compare_query matches, sorted.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $clause A single compare_query clause.
	 * @return list<string>
	 */
	private function labels( array $clause ): array {
		$results = ( new NullThingQuery() )->query( array( 'compare_query' => $clause ) );

		$labels = array();
		foreach ( (array) $results as $row ) {
			$labels[] = $row->label;
		}

		sort( $labels );

		return $labels;
	}

	/**
	 * Test that compare IS NULL matches only the rows whose column is NULL.
	 *
	 * @since 3.1.0
	 */
	public function test_is_null_matches_null_rows() {
		$this->assertSame(
			array( 'B', 'C' ),
			$this->labels(
				array(
					'key'     => 'nickname',
					'compare' => 'IS NULL',
				)
			)
		);
	}

	/**
	 * Test that compare IS NOT NULL matches only the rows that have a value.
	 *
	 * @since 3.1.0
	 */
	public function test_is_not_null_matches_non_null_rows() {
		$this->assertSame(
			array( 'A' ),
			$this->labels(
				array(
					'key'     => 'nickname',
					'compare' => 'IS NOT NULL',
				)
			)
		);
	}

	/**
	 * Test that lowercase 'is null' is accepted (compare is uppercased).
	 *
	 * @since 3.1.0
	 */
	public function test_is_null_is_case_insensitive() {
		$this->assertSame(
			array( 'B', 'C' ),
			$this->labels(
				array(
					'key'     => 'nickname',
					'compare' => 'is null',
				)
			)
		);
	}
}
