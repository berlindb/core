<?php
/**
 * CURRENT_TIMESTAMP DB-managed columns, end-to-end (#231 follow-up).
 *
 * A datetime column declared DEFAULT CURRENT_TIMESTAMP (and optionally ON UPDATE
 * CURRENT_TIMESTAMP) is populated by MySQL, not by BerlinDB: Column::intercept()
 * drops the field from the write so the DB clause fires. These tests prove that
 * round-trip through add_item()/update_item() and get_item().
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

/** Schema with DB-managed CURRENT_TIMESTAMP datetime columns. */
class CtsSchema extends Schema {
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
			'name'    => 'name',
			'type'    => 'varchar',
			'length'  => '50',
			'default' => '',
		),
		array(
			'name'    => 'created',
			'type'    => 'datetime',
			'default' => 'CURRENT_TIMESTAMP',
		),
		array(
			'name'    => 'changed',
			'type'    => 'datetime',
			'default' => 'CURRENT_TIMESTAMP',
			'extra'   => 'ON UPDATE CURRENT_TIMESTAMP',
		),
		array(
			'name'       => 'touched',
			'type'       => 'datetime',
			'default'    => 'CURRENT_TIMESTAMP',
			'allow_null' => true,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

class CtsRow extends Row {
	public $id      = 0;
	public $name    = '';
	public $created = '';
	public $changed = '';
	public $touched = null;
}

class CtsQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'cts_test';
	protected $table_alias      = 'cts';
	protected $table_schema     = CtsSchema::class;
	protected $item_name        = 'cts';
	protected $item_name_plural = 'ctses';
	protected $item_shape       = CtsRow::class;
	protected $cache_group      = 'berlindb-cts';
}

class CtsTable extends Table {
	protected $schema  = CtsSchema::class;
	protected $name    = 'berlindb_cts_test';
	protected $version = '202607081';
}

/**
 * Functional tests for CURRENT_TIMESTAMP DB-managed columns.
 *
 * @since 3.1.0
 */
class CurrentTimestampInsertTest extends TestCase {

	/** @var CtsTable */
	private static $table;

	/** @var CtsQuery */
	private static $query;

	/** Matches a MySQL DATETIME string. */
	private const DATETIME_RE = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

	/**
	 * Install the table.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new CtsTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}

		self::$query = new CtsQuery();
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
	 * A DEFAULT CURRENT_TIMESTAMP column is populated by MySQL on insert.
	 *
	 * If BerlinDB wrote the literal 'CURRENT_TIMESTAMP' instead of deferring, the
	 * stored value would be a mangled/zero date, not a real timestamp.
	 *
	 * @since 3.1.0
	 */
	public function test_default_current_timestamp_populates_on_insert() {
		$id   = self::$query->add_item( array( 'name' => 'a' ) );
		$item = self::$query->get_item( $id );

		$this->assertMatchesRegularExpression( self::DATETIME_RE, (string) $item->created );
		$this->assertNotSame( '0000-00-00 00:00:00', $item->created );
		$this->assertNotSame( 'CURRENT_TIMESTAMP', $item->created );
	}

	/**
	 * An explicit datetime value still wins over the CURRENT_TIMESTAMP default.
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_datetime_wins_over_current_timestamp_default() {
		$id   = self::$query->add_item(
			array(
				'name'    => 'a',
				'created' => '2019-05-06 07:08:09',
			)
		);
		$item = self::$query->get_item( $id );

		$this->assertSame( '2019-05-06 07:08:09', $item->created );
	}

	/**
	 * An ON UPDATE CURRENT_TIMESTAMP column refreshes when another column updates.
	 *
	 * The column is left out of the UPDATE write, so MySQL's ON UPDATE clause
	 * refreshes it; the value stays a real datetime and never moves backwards.
	 *
	 * @since 3.1.0
	 */
	public function test_on_update_current_timestamp_refreshes_on_update() {

		// Seed an explicit OLD value so the ON UPDATE advance is unambiguous.
		$id      = self::$query->add_item(
			array(
				'name'    => 'a',
				'changed' => '2000-01-01 00:00:00',
			)
		);
		$initial = self::$query->get_item( $id )->changed;

		$this->assertSame( '2000-01-01 00:00:00', $initial );

		self::$query->update_item( $id, array( 'name' => 'b' ) );
		wp_cache_flush();
		$updated = self::$query->get_item( $id )->changed;

		$this->assertMatchesRegularExpression( self::DATETIME_RE, (string) $updated );
		$this->assertGreaterThan( $initial, $updated );
	}

	/**
	 * An unparseable value defers to the DEFAULT rather than binding a literal.
	 *
	 * Without the preset's validity gate, an invalid value would fall back through
	 * validate_datetime() to the column's CURRENT_TIMESTAMP default and be written
	 * as the literal string - producing a zero/invalid date, not a real timestamp.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_value_defers_to_default_current_timestamp() {
		$id   = self::$query->add_item(
			array(
				'name'    => 'a',
				'created' => 'not a date',
			)
		);
		$item = self::$query->get_item( $id );

		$this->assertMatchesRegularExpression( self::DATETIME_RE, (string) $item->created );
		$this->assertNotSame( 'CURRENT_TIMESTAMP', $item->created );
		$this->assertNotSame( '0000-00-00 00:00:00', $item->created );
	}

	/**
	 * An explicit datetime on update wins over ON UPDATE CURRENT_TIMESTAMP.
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_value_wins_on_update() {
		$id = self::$query->add_item( array( 'name' => 'a' ) );

		self::$query->update_item( $id, array( 'changed' => '1999-12-31 23:59:59' ) );
		wp_cache_flush();
		$item = self::$query->get_item( $id );

		$this->assertSame( '1999-12-31 23:59:59', $item->changed );
	}

	/**
	 * An omitted nullable CURRENT_TIMESTAMP column is populated by the DB default.
	 *
	 * @since 3.1.0
	 */
	public function test_omitted_nullable_current_timestamp_populates_from_default() {
		$id   = self::$query->add_item( array( 'name' => 'a' ) );
		$item = self::$query->get_item( $id );

		$this->assertMatchesRegularExpression( self::DATETIME_RE, (string) $item->touched );
		$this->assertNotNull( $item->touched );
	}

	/**
	 * An explicit null stores SQL NULL on a nullable CT column, on insert (#233).
	 *
	 * The caller supplied the column (key present) with null, so it is an explicit
	 * value - not an omission - and must be stored rather than deferred to DEFAULT.
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_null_stores_null_on_nullable_ct_insert() {
		$id   = self::$query->add_item(
			array(
				'name'    => 'a',
				'touched' => null,
			)
		);
		$item = self::$query->get_item( $id );

		$this->assertNull( $item->touched );
	}

	/**
	 * An explicit null stores SQL NULL on a nullable CT column, on update (#233).
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_null_stores_null_on_nullable_ct_update() {
		$id = self::$query->add_item( array( 'name' => 'a' ) );

		// Seeded by the DB default first, then explicitly nulled.
		$this->assertNotNull( self::$query->get_item( $id )->touched );

		self::$query->update_item( $id, array( 'touched' => null ) );
		wp_cache_flush();

		$this->assertNull( self::$query->get_item( $id )->touched );
	}

	/**
	 * An explicit null on a NON-nullable CT column defers, never stores null (#233).
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_null_on_non_nullable_ct_defers_to_default() {
		$id   = self::$query->add_item(
			array(
				'name'    => 'a',
				'created' => null,
			)
		);
		$item = self::$query->get_item( $id );

		$this->assertMatchesRegularExpression( self::DATETIME_RE, (string) $item->created );
		$this->assertNotNull( $item->created );
		$this->assertNotSame( '0000-00-00 00:00:00', $item->created );
	}

	/**
	 * An unchanged nullable CT column is not nulled by an unrelated update (#233).
	 *
	 * @since 3.1.0
	 */
	public function test_unchanged_nullable_ct_column_is_not_nulled_on_update() {
		$id      = self::$query->add_item( array( 'name' => 'a' ) );
		$initial = self::$query->get_item( $id )->touched;

		$this->assertNotNull( $initial );

		self::$query->update_item( $id, array( 'name' => 'b' ) );
		wp_cache_flush();

		$this->assertSame( $initial, self::$query->get_item( $id )->touched );
	}
}
