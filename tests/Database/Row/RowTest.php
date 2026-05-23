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
use Yoast\WPTestUtils\WPIntegration\TestCase;

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
			public $slug = '';
		};

		$this->assertTrue( $row->exists() );
	}
}
