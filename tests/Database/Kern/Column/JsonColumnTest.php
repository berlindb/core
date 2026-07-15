<?php
/**
 * Tests for the JSON column type.
 *
 * Unit tests work against Column objects directly. Integration tests use a
 * self-contained schema/table/query trio defined in this file so the shared
 * TestSchema is not modified - JSON is a transparent type behavior, not a
 * named special column like uuid or date_created.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use BerlinDB\Tests\Fixtures\EngineSkips;
use Yoast\WPTestUtils\WPIntegration\TestCase;

// ============================================================================
// Self-contained fixtures - not part of the shared test infrastructure.
// ============================================================================

/**
 * Minimal schema with a single JSON column alongside a primary key.
 *
 * The JSON column is named 'data' here but the column name is arbitrary -
 * Berlin applies encode/decode based on the type, not the name.
 *
 * @since 3.0.0
 */
class JsonTestSchema extends Schema {

	/** @var array */
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'default'   => false,
			'cache_key' => true,
		),
		array(
			'name'    => 'data',
			'type'    => 'json',
			'default' => '',
		),
	);

	/** @var array */
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/**
 * Table backed by JsonTestSchema.
 *
 * @since 3.0.0
 */
class JsonTestTable extends Table {

	/** @var string */
	protected $schema = JsonTestSchema::class;

	/** @var string */
	protected $name = 'berlindb_database_test_json';

	/** @var string */
	protected $version = '202600010';
}

/**
 * Query for the JSON test table.
 *
 * @since 3.0.0
 */
class JsonTestQuery extends Query {

	/** @var string */
	protected $prefix = 'berlindb_database';

	/** @var string */
	protected $table_name = 'test_json';

	/** @var string */
	protected $table_alias = 'tj';

	/** @var string */
	protected $table_schema = JsonTestSchema::class;

	/** @var string */
	protected $item_name = 'json_item';

	/** @var string */
	protected $item_name_plural = 'json_items';

	/** @var string */
	protected $item_shape = 'stdClass';

	/** @var string */
	protected $cache_group = 'berlindb-test-json';
}

// ============================================================================
// Test case.
// ============================================================================

/**
 * Tests for JSON column type: DDL generation, write encoding, read decoding.
 *
 * @since 3.0.0
 */
class JsonColumnTest extends TestCase {

	use EngineSkips;

	/** @var JsonTestTable */
	private static $table;

	/** @var JsonTestQuery */
	private static $query;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$table = new JsonTestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new JsonTestQuery();
	}

	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 1 );
	}

	// ========================================================================
	// Column type helpers.
	// ========================================================================

	/**
	 * is_json() returns true for a json column.
	 *
	 * @since 3.0.0
	 */
	public function test_is_json_returns_true_for_json_type() {
		$col = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$this->assertTrue( $col->is_json() );
	}

	/**
	 * is_json() returns false for non-JSON types.
	 *
	 * @since 3.0.0
	 */
	public function test_is_json_returns_false_for_varchar() {
		$col = new Column(
			array(
				'name'   => 'title',
				'type'   => 'varchar',
				'length' => 200,
			)
		);
		$this->assertFalse( $col->is_json() );
	}

	// ========================================================================
	// DDL generation.
	// ========================================================================

	/**
	 * CREATE string contains 'json' with no length suffix.
	 *
	 * @since 3.0.0
	 */
	public function test_create_string_has_no_length() {
		$col    = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$create = $col->get_create_string();
		$this->assertStringContainsString( 'json', $create );
		$this->assertStringNotContainsString( 'json(', $create );
	}

	/**
	 * CREATE string has no CHARACTER SET or COLLATE clause.
	 *
	 * MySQL rejects charset/collation on JSON columns.
	 *
	 * @since 3.0.0
	 */
	public function test_create_string_has_no_charset() {
		$col    = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$create = $col->get_create_string();
		$this->assertStringNotContainsString( 'CHARACTER SET', $create );
		$this->assertStringNotContainsString( 'COLLATE', $create );
	}

	/**
	 * CREATE string has no string-literal DEFAULT clause.
	 *
	 * MySQL rejects DEFAULT '' on JSON columns.
	 *
	 * @since 3.0.0
	 */
	public function test_create_string_has_no_string_default() {
		$col    = new Column(
			array(
				'name'    => 'payload',
				'type'    => 'json',
				'default' => '',
			)
		);
		$create = $col->get_create_string();
		$this->assertStringNotContainsString( "default '", $create );
	}

	/**
	 * A non-empty JSON default emits no literal DEFAULT clause either.
	 *
	 * MySQL rejects a literal DEFAULT on JSON columns, so a declared default (even
	 * valid JSON) must not become a quoted-literal clause - the is_json guard is
	 * checked before the explicit-default branch.
	 *
	 * @since 3.1.0
	 */
	public function test_create_string_has_no_string_default_for_nonempty_default() {
		$col    = new Column(
			array(
				'name'    => 'payload',
				'type'    => 'json',
				'default' => '[]',
			)
		);
		$create = $col->get_create_string();
		$this->assertStringNotContainsString( "default '", $create );
	}

	/**
	 * A nullable JSON column emits DEFAULT NULL.
	 *
	 * @since 3.0.0
	 */
	public function test_create_string_emits_default_null_when_allow_null() {
		$col = new Column(
			array(
				'name'       => 'payload',
				'type'       => 'json',
				'allow_null' => true,
				'default'    => null,
			)
		);
		$this->assertStringContainsString( 'default null', $col->get_create_string() );
	}

	// ========================================================================
	// cast_json().
	// ========================================================================

	/**
	 * cast_json() decodes a JSON string to a PHP array.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_json_decodes_string_to_array() {
		$col  = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$data = $col->cast_json( '{"color":"red"}' );
		$this->assertSame( array( 'color' => 'red' ), $data );
	}

	/**
	 * cast_json() is idempotent - an already-decoded array passes through.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_json_is_idempotent_for_arrays() {
		$col  = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$data = array( 'color' => 'red' );
		$this->assertSame( $data, $col->cast_json( $data ) );
	}

	/**
	 * cast_json() returns an empty array for an invalid JSON string.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_json_returns_empty_array_for_invalid_json() {
		$col = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$this->assertSame( array(), $col->cast_json( 'not-json' ) );
	}

	/**
	 * cast_json() returns null for a null input.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_json_returns_null_for_null() {
		$col = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$this->assertNull( $col->cast_json( null ) );
	}

	/**
	 * cast_json() returns an empty array for an empty string.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_json_returns_empty_array_for_empty_string() {
		$col = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$this->assertSame( array(), $col->cast_json( '' ) );
	}

	// ========================================================================
	// validate_json().
	// ========================================================================

	/**
	 * validate_json() encodes a PHP array to a JSON string.
	 *
	 * @since 3.0.0
	 */
	public function test_validate_json_encodes_array() {
		$col  = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$data = $col->validate( array( 'color' => 'red' ) );
		$this->assertSame( '{"color":"red"}', $data );
	}

	/**
	 * validate_json() encodes a PHP object to a JSON string.
	 *
	 * @since 3.0.0
	 */
	public function test_validate_json_encodes_object() {
		$col  = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$data = $col->validate( (object) array( 'score' => 42 ) );
		$this->assertSame( '{"score":42}', $data );
	}

	/**
	 * validate_json() passes a valid JSON string through unchanged.
	 *
	 * @since 3.0.0
	 */
	public function test_validate_json_passes_valid_json_string() {
		$col   = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$input = '{"key":"value"}';
		$this->assertSame( $input, $col->validate( $input ) );
	}

	/**
	 * validate_json() returns '{}' for an invalid JSON string.
	 *
	 * @since 3.0.0
	 */
	public function test_validate_json_returns_empty_object_for_invalid_json() {
		$col = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$this->assertSame( '{}', $col->validate( 'not-json' ) );
	}

	/**
	 * validate_json() returns '{}' for an empty string.
	 *
	 * @since 3.0.0
	 */
	public function test_validate_json_returns_empty_object_for_empty_string() {
		$col = new Column(
			array(
				'name' => 'payload',
				'type' => 'json',
			)
		);
		$this->assertSame( '{}', $col->validate( '' ) );
	}

	// ========================================================================
	// End-to-end: write encoding and read decoding via Query.
	// ========================================================================

	/**
	 * An array written to a JSON column is decoded back to an array on read.
	 *
	 * @since 3.0.0
	 */
	public function test_array_roundtrips_through_json_column() {

		/*
		 * MySQL's native JSON type normalizes object key order on storage, so an
		 * associative array does not round-trip identically ( assertSame is order-
		 * sensitive ); MariaDB stores JSON as text and preserves order. Tracked in
		 * berlindb/core#247.
		 */
		$this->skip_on_mysql( 'JSON object key order is not preserved on MySQL; tracked in berlindb/core#247.' );

		$data = array(
			'color' => 'red',
			'size'  => 'large',
		);

		$id = self::$query->add_item( array( 'data' => $data ) );
		$this->assertNotFalse( $id );

		$item = self::$query->get_item( $id );
		$this->assertIsObject( $item );
		$this->assertSame( $data, $item->data );

		self::$query->delete_item( $id );
	}

	/**
	 * Nested arrays roundtrip correctly.
	 *
	 * @since 3.0.0
	 */
	public function test_nested_array_roundtrips_through_json_column() {
		$data = array(
			'tags'    => array( 'featured', 'sale' ),
			'details' => array(
				'weight'  => 1.5,
				'fragile' => true,
			),
		);

		$id = self::$query->add_item( array( 'data' => $data ) );
		$this->assertNotFalse( $id );

		$item = self::$query->get_item( $id );
		$this->assertIsObject( $item );
		$this->assertSame( $data, $item->data );

		self::$query->delete_item( $id );
	}

	/**
	 * An item added without a value for the JSON column reads back as an empty
	 * array (the '{}' default is decoded to []).
	 *
	 * @since 3.0.0
	 */
	public function test_missing_value_defaults_to_empty_array_on_read() {
		$id = self::$query->add_item( array() );
		$this->assertNotFalse( $id );

		$item = self::$query->get_item( $id );
		$this->assertIsObject( $item );
		$this->assertSame( array(), $item->data );

		self::$query->delete_item( $id );
	}

	/**
	 * The JSON column value can be updated to a new array.
	 *
	 * @since 3.0.0
	 */
	public function test_json_column_update() {
		$id = self::$query->add_item( array( 'data' => array( 'v' => 1 ) ) );
		$this->assertNotFalse( $id );

		self::$query->update_item( $id, array( 'data' => array( 'v' => 2 ) ) );

		$item = self::$query->get_item( $id );
		$this->assertSame( array( 'v' => 2 ), $item->data );

		self::$query->delete_item( $id );
	}
}
