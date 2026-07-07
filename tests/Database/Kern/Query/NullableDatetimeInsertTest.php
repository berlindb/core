<?php
/**
 * Nullable datetime insert round-trip (#231).
 *
 * A nullable datetime ( allow_null + null default ) must store SQL NULL when its
 * value is empty, while a NOT NULL datetime stores the zero date. This proves the
 * Column::get_empty_datetime() behavior end-to-end through add_item() -> wpdb and
 * back through get_item(), not just at the validate() boundary.
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

/** Schema with a NOT NULL datetime and a nullable ( null-default ) datetime. */
class NullDtSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name' => 'created',
			'type' => 'datetime',
		),
		array(
			'name'       => 'ends',
			'type'       => 'datetime',
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

class NullDtRow extends Row {
	public $id      = 0;
	public $created = '';
	public $ends    = null;
}

class NullDtQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'nulldt_test';
	protected $table_alias      = 'ndt';
	protected $table_schema     = NullDtSchema::class;
	protected $item_name        = 'nulldt';
	protected $item_name_plural = 'nulldts';
	protected $item_shape       = NullDtRow::class;
	protected $cache_group      = 'berlindb-nulldt';
}

class NullDtTable extends Table {
	protected $schema  = NullDtSchema::class;
	protected $name    = 'berlindb_nulldt_test';
	protected $version = '202607070';
}

/**
 * Functional tests for the nullable-datetime insert round-trip.
 *
 * @since 3.1.0
 */
class NullableDatetimeInsertTest extends TestCase {

	/** @var NullDtTable */
	private static $table;

	/** @var NullDtQuery */
	private static $query;

	/**
	 * Install the table.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new NullDtTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}

		self::$query = new NullDtQuery();
	}

	/**
	 * Reset rows before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		self::$table->delete_all();
		wp_cache_flush();
	}

	/**
	 * Drop the table after the class.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * An omitted nullable datetime stores SQL NULL; a NOT NULL one stores the zero date.
	 *
	 * @since 3.1.0
	 */
	public function test_omitted_datetimes_store_null_and_zero_date() {
		$id = self::$query->add_item( array() );

		$this->assertNotEmpty( $id );

		$item = self::$query->get_item( $id );

		$this->assertNull( $item->ends );
		$this->assertSame( '0000-00-00 00:00:00', $item->created );
	}

	/**
	 * An explicitly empty nullable datetime also stores SQL NULL.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_nullable_datetime_stores_null() {
		$id = self::$query->add_item(
			array(
				'ends' => '',
			)
		);

		$item = self::$query->get_item( $id );

		$this->assertNull( $item->ends );
	}

	/**
	 * A real datetime value round-trips unchanged on the nullable column.
	 *
	 * @since 3.1.0
	 */
	public function test_real_value_round_trips_on_nullable_datetime() {
		$id = self::$query->add_item(
			array(
				'ends' => '2020-01-02 03:04:05',
			)
		);

		$item = self::$query->get_item( $id );

		$this->assertSame( '2020-01-02 03:04:05', $item->ends );
	}
}
