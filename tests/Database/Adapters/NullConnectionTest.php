<?php
/**
 * NullConnection adapter tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Adapters\NullConnection;
use BerlinDB\Database\Interfaces\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the inert Connection implementation used before a real database
 * handle is available.
 *
 * @since 3.0.0
 */
class NullConnectionTest extends TestCase {

	/**
	 * NullConnection satisfies the public Connection contract.
	 *
	 * @since 3.0.0
	 */
	public function test_null_connection_implements_connection_interface() {
		$this->assertInstanceOf( Connection::class, new NullConnection() );
	}

	/**
	 * Query-style methods return empty failure values, not PHP errors.
	 *
	 * @since 3.0.0
	 */
	public function test_query_methods_return_inert_values() {
		$db = new NullConnection();

		$this->assertNull( $db->prepare( 'SELECT %s', 'value' ) );
		$this->assertFalse( $db->query( 'SELECT 1' ) );
		$this->assertNull( $db->get_var( 'SELECT 1' ) );
		$this->assertNull( $db->get_row( 'SELECT 1' ) );
		$this->assertNull( $db->get_results( 'SELECT 1' ) );
		$this->assertSame( array(), $db->get_col( 'SELECT 1' ) );
	}

	/**
	 * Write methods return failure values without mutating state.
	 *
	 * @since 3.0.0
	 */
	public function test_write_methods_return_false() {
		$db = new NullConnection();

		$this->assertFalse( $db->insert( 'table_name', array( 'name' => 'Berlin' ) ) );
		$this->assertFalse( $db->update( 'table_name', array( 'name' => 'Berlin' ), array( 'id' => 1 ) ) );
		$this->assertFalse( $db->delete( 'table_name', array( 'id' => 1 ) ) );
	}

	/**
	 * Property and registry methods return type-safe empty values.
	 *
	 * @since 3.0.0
	 */
	public function test_property_and_registry_methods_return_safe_defaults() {
		$db = new NullConnection();

		$this->assertSame( '100%_match', $db->esc_like( '100%_match' ) );
		$this->assertFalse( $db->suppress_errors() );
		$this->assertSame( '', $db->get_blog_prefix() );
		$this->assertSame( 0, $db->get_insert_id() );
		$this->assertSame( '', $db->get_charset() );
		$this->assertSame( '', $db->get_collation() );
		$this->assertSame( 'custom_table', $db->get_table_prefix( 'custom_table' ) );

		$db->set_table_prefix( 'custom_table', 'wp_custom_table' );
		$db->register_table( 'custom_tables', 'custom_table' );

		$this->assertSame( 'custom_table', $db->get_table_prefix( 'custom_table' ) );
	}
}
