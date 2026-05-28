<?php
/**
 * Tests for Query::reduce_item().
 *
 * reduce_item() strips columns the current user cannot access for a given
 * CRUD method. All columns in TestSchema default to the 'exist' pseudo-cap,
 * which WordPress grants broadly, including to the anonymous user (ID 0).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for Query::reduce_item().
 *
 * @since 3.0.0
 */
class ReduceItemTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/** @var \ReflectionMethod */
	private static $method;

	/**
	 * Install fixtures and expose Query::reduce_item() before tests run.
	 *
	 * @since 3.0.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query  = new TestQuery();
		self::$method = new \ReflectionMethod( TestQuery::class, 'reduce_item' );
	}

	/**
	 * Uninstall the fixture table after reduce_item() tests complete.
	 *
	 * @since 3.0.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset the current user before each reduce_item() test.
	 *
	 * @since 3.0.0
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 1 );
	}

	// ========================================================================
	// Return type.
	// ========================================================================

	/**
	 * Empty array input returns an empty array.
	 *
	 * @since 3.0.0
	 */
	public function test_empty_array_returns_empty_array() {
		$result = self::$method->invoke( self::$query, 'update', array() );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Array input returns an array.
	 *
	 * @since 3.0.0
	 */
	public function test_array_input_returns_array() {
		$result = self::$method->invoke(
			self::$query,
			'select',
			array(
				'id'   => 1,
				'name' => 'Widget',
			)
		);
		$this->assertIsArray( $result );
	}

	/**
	 * Object input also returns an array (reduce_item always returns array).
	 *
	 * @since 3.0.0
	 */
	public function test_object_input_returns_array() {
		$input  = (object) array(
			'id'   => 1,
			'name' => 'Widget',
		);
		$result = self::$method->invoke( self::$query, 'select', $input );
		$this->assertIsArray( $result );
	}

	// ========================================================================
	// Capability checks — logged-in admin (user 1).
	// ========================================================================

	/**
	 * Schema columns are retained for a logged-in user.
	 *
	 * TestSchema columns default to the 'exist' cap, which passes for any
	 * logged-in user.
	 *
	 * @since 3.0.0
	 */
	public function test_schema_columns_retained_for_logged_in_user() {
		$input  = array(
			'id'     => 1,
			'name'   => 'Widget',
			'status' => 'active',
		);
		$result = self::$method->invoke( self::$query, 'select', $input );

		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Column values are preserved unchanged.
	 *
	 * @since 3.0.0
	 */
	public function test_column_values_preserved() {
		$input  = array(
			'id'       => 42,
			'name'     => 'My Widget',
			'priority' => 7,
		);
		$result = self::$method->invoke( self::$query, 'update', $input );

		$this->assertSame( 42, $result['id'] );
		$this->assertSame( 'My Widget', $result['name'] );
		$this->assertSame( 7, $result['priority'] );
	}

	// ========================================================================
	// Capability checks — anonymous user (user 0).
	// ========================================================================

	/**
	 * Schema columns are retained for the anonymous user.
	 *
	 * current_user_can( 'exist' ) returns true even for user ID 0.
	 *
	 * @since 3.0.0
	 */
	public function test_schema_columns_retained_for_anonymous_user() {
		wp_set_current_user( 0 );

		$input  = array(
			'id'     => 1,
			'name'   => 'Widget',
			'status' => 'active',
		);
		$result = self::$method->invoke( self::$query, 'select', $input );

		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Object input with anonymous user returns retained schema columns.
	 *
	 * @since 3.0.0
	 */
	public function test_object_with_anonymous_user_returns_schema_columns() {
		wp_set_current_user( 0 );

		$input  = (object) array(
			'id'   => 1,
			'name' => 'Widget',
		);
		$result = self::$method->invoke( self::$query, 'delete', $input );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'name', $result );
	}

	// ========================================================================
	// Unknown columns.
	// ========================================================================

	/**
	 * Keys not present in the schema are stripped.
	 *
	 * get_column_field() returns false for unknown column names, which
	 * resolves to an empty capability string and fails the cap check.
	 *
	 * @since 3.0.0
	 */
	public function test_unknown_column_stripped() {
		$input  = array(
			'id'            => 1,
			'name'          => 'Widget',
			'not_in_schema' => 'surprise',
		);
		$result = self::$method->invoke( self::$query, 'select', $input );

		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayNotHasKey( 'not_in_schema', $result );
	}

	/**
	 * Item consisting entirely of unknown columns reduces to an empty array.
	 *
	 * @since 3.0.0
	 */
	public function test_all_unknown_columns_returns_empty_array() {
		$input  = array(
			'ghost'   => 'boo',
			'phantom' => 'value',
		);
		$result = self::$method->invoke( self::$query, 'insert', $input );

		$this->assertEmpty( $result );
	}

	// ========================================================================
	// All four CRUD methods.
	// ========================================================================

	/**
	 * Schema columns are retained for all four CRUD methods when logged in.
	 *
	 * @since 3.0.0
	 *
	 * @dataProvider provide_crud_methods
	 */
	public function test_schema_columns_retained_for_all_methods( string $method ) {
		$input  = array(
			'id'   => 1,
			'name' => 'Widget',
		);
		$result = self::$method->invoke( self::$query, $method, $input );

		$this->assertArrayHasKey( 'id', $result, "Method '$method' should retain 'id'" );
		$this->assertArrayHasKey( 'name', $result, "Method '$method' should retain 'name'" );
	}

	/**
	 * Provide the CRUD method names supported by reduce_item().
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array{string}>
	 */
	public function provide_crud_methods(): array {
		return array(
			'select' => array( 'select' ),
			'insert' => array( 'insert' ),
			'update' => array( 'update' ),
			'delete' => array( 'delete' ),
		);
	}

	/**
	 * Schema columns are retained for all four methods when not logged in.
	 *
	 * @since 3.0.0
	 *
	 * @dataProvider provide_crud_methods
	 */
	public function test_schema_columns_retained_for_all_methods_when_anonymous( string $method ) {
		wp_set_current_user( 0 );

		$input  = array(
			'id'   => 1,
			'name' => 'Widget',
		);
		$result = self::$method->invoke( self::$query, $method, $input );

		$this->assertArrayHasKey( 'id', $result, "Method '$method' should retain 'id'" );
		$this->assertArrayHasKey( 'name', $result, "Method '$method' should retain 'name'" );
	}

	// ========================================================================
	// Mixed input.
	// ========================================================================

	/**
	 * When the item mixes schema and non-schema keys, only schema keys survive.
	 *
	 * @since 3.0.0
	 */
	public function test_mixed_schema_and_unknown_columns() {
		$input = array(
			'id'          => 5,
			'name'        => 'Widget',
			'mystery'     => 'should be gone',
			'status'      => 'active',
			'extra_field' => 'also gone',
		);

		$result = self::$method->invoke( self::$query, 'update', $input );

		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayNotHasKey( 'mystery', $result );
		$this->assertArrayNotHasKey( 'extra_field', $result );
	}
}
