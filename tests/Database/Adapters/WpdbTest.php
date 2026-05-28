<?php
/**
 * Wpdb adapter tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Adapters\Wpdb;
use BerlinDB\Database\Interfaces\Connection;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for the WordPress database adapter.
 *
 * @since 3.0.0
 */
class WpdbTest extends TestCase {

	/** @var Wpdb */
	private $adapter;

	/**
	 * Create a fresh adapter around the shared WordPress database object.
	 *
	 * @since 3.0.0
	 */
	public function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->adapter = new Wpdb( $wpdb );
	}

	/**
	 * Wpdb satisfies the public Connection contract.
	 *
	 * @since 3.0.0
	 */
	public function test_wpdb_adapter_implements_connection_interface() {
		$this->assertInstanceOf( Connection::class, $this->adapter );
	}

	/**
	 * prepare() delegates to wpdb and returns the prepared SQL string.
	 *
	 * @since 3.0.0
	 */
	public function test_prepare_delegates_to_wpdb() {
		$this->assertSame( "SELECT 'Berlin'", $this->adapter->prepare( 'SELECT %s', 'Berlin' ) );
	}

	/**
	 * Escaping LIKE fragments delegates to wpdb's escaping behavior.
	 *
	 * @since 3.0.0
	 */
	public function test_esc_like_delegates_to_wpdb() {
		global $wpdb;

		$value = '100%_match';

		$this->assertSame( $wpdb->esc_like( $value ), $this->adapter->esc_like( $value ) );
	}

	/**
	 * Property methods expose typed values from the wrapped wpdb object.
	 *
	 * @since 3.0.0
	 */
	public function test_property_methods_expose_wpdb_values() {
		global $wpdb;

		$this->assertSame( (int) $wpdb->insert_id, $this->adapter->get_insert_id() );
		$this->assertSame( (string) $wpdb->charset, $this->adapter->get_charset() );
		$this->assertSame( (string) $wpdb->collate, $this->adapter->get_collation() );
	}

	/**
	 * Table prefix helpers read and write dynamic wpdb properties.
	 *
	 * @since 3.0.0
	 */
	public function test_table_prefix_helpers_manage_dynamic_wpdb_properties() {
		global $wpdb;

		$key      = 'berlindb_adapter_test_table';
		$original = $wpdb->{$key} ?? null;

		$this->adapter->set_table_prefix( $key, 'wp_berlindb_adapter_test' );

		$this->assertSame( 'wp_berlindb_adapter_test', $wpdb->{$key} );
		$this->assertSame( 'wp_berlindb_adapter_test', $this->adapter->get_table_prefix( $key ) );

		if ( null === $original ) {
			unset( $wpdb->{$key} );
		} else {
			$wpdb->{$key} = $original;
		}
	}

	/**
	 * register_table() creates the group and does not duplicate table names.
	 *
	 * @since 3.0.0
	 */
	public function test_register_table_adds_table_name_once() {
		global $wpdb;

		$group    = 'berlindb_adapter_test_tables';
		$original = $wpdb->{$group} ?? null;

		$this->adapter->register_table( $group, 'widgets' );
		$this->adapter->register_table( $group, 'widgets' );

		$this->assertSame( array( 'widgets' ), $wpdb->{$group} );

		if ( null === $original ) {
			unset( $wpdb->{$group} );
		} else {
			$wpdb->{$group} = $original;
		}
	}
}
