<?php
/**
 * Row class tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestRow;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BerlinDB\Database\Row via the TestRow fixture.
 *
 * @since 3.0.0
 */
class RowTest extends TestCase {

	/**
	 * Test that exists is false when id is zero.
	 *
	 * @since 3.0.0
	 */
	public function test_exists_is_false_when_id_is_zero() {

		// Assert expected results.
		$row = new TestRow();
		$this->assertFalse( $row->exists() );
	}

	/**
	 * Test that exists is true when id is positive.
	 *
	 * @since 3.0.0
	 */
	public function test_exists_is_true_when_id_is_positive() {

		// Assert expected results.
		$row = new TestRow( array( 'id' => 7 ) );
		$this->assertTrue( $row->exists() );
	}

	/**
	 * Test that constructor maps fixture properties from args.
	 *
	 * @since 3.0.0
	 */
	public function test_constructor_maps_fixture_properties_from_args() {

		// Assert expected results.
		$row = new TestRow(
			array(
				'id'            => 11,
				'name'          => 'Widget A',
				'status'        => 'inactive',
				'priority'      => 42,
				'date_created'  => '2026-01-01 12:00:00',
				'date_modified' => '2026-01-02 12:00:00',
				'uuid'          => 'urn:uuid:11111111-1111-4111-8111-111111111111',
			)
		);

		$this->assertSame( 11, $row->id );
		$this->assertSame( 'Widget A', $row->name );
		$this->assertSame( 'inactive', $row->status );
		$this->assertSame( 42, $row->priority );
		$this->assertSame( '2026-01-01 12:00:00', $row->date_created );
		$this->assertSame( '2026-01-02 12:00:00', $row->date_modified );
		$this->assertSame( 'urn:uuid:11111111-1111-4111-8111-111111111111', $row->uuid );
	}

	/**
	 * Test that to array includes fixture properties.
	 *
	 * @since 3.0.0
	 */
	public function test_to_array_includes_fixture_properties() {

		// Assert expected results.
		$row = new TestRow(
			array(
				'id'   => 2,
				'name' => 'Widget B',
			)
		);

		$arr = $row->to_array();
		$this->assertArrayHasKey( 'id', $arr );
		$this->assertArrayHasKey( 'name', $arr );
		$this->assertSame( 2, $arr['id'] );
		$this->assertSame( 'Widget B', $arr['name'] );
	}

	/**
	 * Test that magic getter returns null for unknown properties.
	 *
	 * @since 3.0.0
	 */
	public function test_magic_getter_returns_null_for_unknown_properties() {

		// Assert expected results.
		$row = new TestRow();
		$this->assertNull( $row->nonexistent_property_xyz );
	}

	/**
	 * Test that known fixture properties are writable and readable.
	 *
	 * @since 3.0.0
	 */
	public function test_known_fixture_properties_are_writable_and_readable() {

		// Assert expected results.
		$row       = new TestRow();
		$row->name = 'Updated Widget';

		$this->assertSame( 'Updated Widget', $row->name );
	}

	/**
	 * Test that exists() respects a custom primary_column override.
	 *
	 * @since 3.0.0
	 */
	public function test_exists_respects_custom_primary_column() {
		$row = new class( array( 'slug' => 'hello' ) ) extends \BerlinDB\Database\Kern\Row {
			protected $primary_column = 'slug';
			public $slug              = '';
		};

		$this->assertTrue( $row->exists() );
	}

	// Cast trait — $casts applied on construction.

	/**
	 * Test that a string is cast to int when $casts maps the property to intval.
	 *
	 * @since 3.0.0
	 */
	public function test_casts_coerce_string_to_int() {
		$row = new class( array( 'count' => '42' ) ) extends \BerlinDB\Database\Kern\Row {
			public $count    = 0;
			protected $casts = array( 'count' => 'intval' );
		};

		$this->assertSame( 42, $row->count );
		$this->assertIsInt( $row->count );
	}

	/**
	 * Test that a string is cast to float when $casts maps the property to floatval.
	 *
	 * @since 3.0.0
	 */
	public function test_casts_coerce_string_to_float() {
		$row = new class( array( 'price' => '9.99' ) ) extends \BerlinDB\Database\Kern\Row {
			public $price    = 0.0;
			protected $casts = array( 'price' => 'floatval' );
		};

		$this->assertSame( 9.99, $row->price );
		$this->assertIsFloat( $row->price );
	}

	/**
	 * Test that a truthy string is cast to true when $casts maps the property to boolval.
	 *
	 * @since 3.0.0
	 */
	public function test_casts_coerce_truthy_string_to_true() {
		$row = new class( array( 'active' => '1' ) ) extends \BerlinDB\Database\Kern\Row {
			public $active   = false;
			protected $casts = array( 'active' => 'boolval' );
		};

		$this->assertTrue( $row->active );
	}

	/**
	 * Test that a falsy string is cast to false when $casts maps the property to boolval.
	 *
	 * @since 3.0.0
	 */
	public function test_casts_coerce_falsy_string_to_false() {
		$row = new class( array( 'active' => '0' ) ) extends \BerlinDB\Database\Kern\Row {
			public $active   = true;
			protected $casts = array( 'active' => 'boolval' );
		};

		$this->assertFalse( $row->active );
	}

	/**
	 * Test that a serialized string is unserialized when $casts uses maybe_unserialize.
	 *
	 * @since 3.0.0
	 */
	public function test_casts_unserialize_serialized_string() {
		$serialized = serialize( array( 'foo' => 'bar' ) );
		$row        = new class( array( 'meta' => $serialized ) ) extends \BerlinDB\Database\Kern\Row {
			public $meta     = '';
			protected $casts = array( 'meta' => 'maybe_unserialize' );
		};

		$this->assertIsArray( $row->meta );
		$this->assertSame( 'bar', $row->meta['foo'] );
	}

	/**
	 * Test that $casts applies multiple casts in a single Row.
	 *
	 * @since 3.0.0
	 */
	public function test_casts_applies_multiple_casts() {
		$row = new class(
			array(
				'id'    => '5',
				'price' => '19.99',
			)
		) extends \BerlinDB\Database\Kern\Row {
			public $id       = 0;
			public $price    = 0.0;
			protected $casts = array(
				'id'    => 'intval',
				'price' => 'floatval',
			);
		};

		$this->assertSame( 5, $row->id );
		$this->assertSame( 19.99, $row->price );
	}

	/**
	 * Test that $casts silently skips properties that do not exist on the Row.
	 *
	 * @since 3.0.0
	 */
	public function test_casts_skips_nonexistent_properties_without_error() {
		$row = new class( array( 'id' => '3' ) ) extends \BerlinDB\Database\Kern\Row {
			public $id       = 0;
			protected $casts = array(
				'id'          => 'intval',
				'nonexistent' => 'intval',
			);
		};

		$this->assertSame( 3, $row->id );
	}

	/**
	 * Test that the base Row has an empty $casts array and values pass through unchanged.
	 *
	 * @since 3.0.0
	 */
	public function test_base_row_casts_are_empty_and_values_pass_through() {
		$row = new TestRow( array( 'priority' => '7' ) );

		// priority is declared as int on TestRow, but no cast is defined,
		// so the raw string from the DB is preserved.
		$this->assertSame( '7', $row->priority );
	}
}
