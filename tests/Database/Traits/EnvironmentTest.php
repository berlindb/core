<?php
/**
 * Environment trait tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Adapters\NullConnection;
use BerlinDB\Database\Adapters\Wpdb;
use BerlinDB\Database\Interfaces\Connection;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Test subject exposing the protected Environment trait methods as public.
 *
 * @since 3.0.0
 */
class EnvironmentTestSubject {

	use \BerlinDB\Database\Traits\Environment;

	/**
	 * @param string $key Global variable name.
	 * @return Connection
	 */
	public static function expose_db_global( string $key = 'wpdb' ): Connection {
		return static::get_db_global( $key );
	}

	/**
	 * @return Connection
	 */
	public function expose_db(): Connection {
		return $this->db();
	}
}

/**
 * Tests for the Environment trait — specifically get_db_global() caching
 * and its interaction with WordPress's switch_blog pattern.
 *
 * @since 3.0.0
 */
class EnvironmentTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Returns a fresh unique global name, safe to use without polluting the
	 * shared 'wpdb' cache entry.
	 *
	 * @return string
	 */
	private function unique_global(): string {
		return '__env_test_' . str_replace( '.', '_', uniqid( '', true ) );
	}

	// -------------------------------------------------------------------------
	// Wrapping.
	// -------------------------------------------------------------------------

	/**
	 * A raw \wpdb global is wrapped in a Wpdb adapter automatically.
	 *
	 * @since 3.0.0
	 */
	public function test_wpdb_global_is_wrapped_as_wpdb_adapter() {
		$conn = EnvironmentTestSubject::expose_db_global( 'wpdb' );
		$this->assertInstanceOf( Wpdb::class, $conn );
	}

	/**
	 * An unset global returns a NullConnection, not an exception.
	 *
	 * @since 3.0.0
	 */
	public function test_missing_global_returns_null_connection() {
		$key = $this->unique_global();
		$this->assertInstanceOf( NullConnection::class, EnvironmentTestSubject::expose_db_global( $key ) );
	}

	/**
	 * A global that already implements Connection is returned as-is.
	 *
	 * @since 3.0.0
	 */
	public function test_connection_global_is_returned_as_is() {
		$key             = $this->unique_global();
		$custom          = $this->createMock( Connection::class );
		$GLOBALS[ $key ] = $custom;

		$this->assertSame( $custom, EnvironmentTestSubject::expose_db_global( $key ) );

		unset( $GLOBALS[ $key ] );
	}

	// -------------------------------------------------------------------------
	// Cache.
	// -------------------------------------------------------------------------

	/**
	 * Repeated calls for the same global return the identical Connection instance.
	 *
	 * @since 3.0.0
	 */
	public function test_same_global_returns_cached_adapter() {
		$conn1 = EnvironmentTestSubject::expose_db_global( 'wpdb' );
		$conn2 = EnvironmentTestSubject::expose_db_global( 'wpdb' );
		$this->assertSame( $conn1, $conn2 );
	}

	/**
	 * Mutating $wpdb->prefix (as switch_to_blog() does) is immediately visible
	 * through the cached adapter — no stale snapshot.
	 *
	 * This is the core switch_blog safety assertion: Table::switch_blog() calls
	 * set_db_interface() → get_db() → get_db_global(), which returns the cached
	 * Wpdb adapter. Because the adapter holds a reference to the same \wpdb
	 * object (not a copy), any mutation WordPress applies to the prefix is
	 * reflected instantly.
	 *
	 * @since 3.0.0
	 */
	public function test_wpdb_prefix_mutation_is_visible_through_cached_adapter() {
		global $wpdb;

		$original_prefix = $wpdb->prefix;

		$conn_before  = EnvironmentTestSubject::expose_db_global( 'wpdb' );
		$wpdb->prefix = 'wp_switched_';

		$conn_after = EnvironmentTestSubject::expose_db_global( 'wpdb' );

		$this->assertSame( $conn_before, $conn_after, 'Same adapter instance should be returned from cache.' );
		$this->assertSame( 'wp_switched_', $conn_after->get_table_prefix( 'prefix' ) );

		$wpdb->prefix = $original_prefix;
	}

	/**
	 * Replacing the global with a new \wpdb instance (different spl_object_id)
	 * produces a fresh adapter — the stale cache entry is not reused.
	 *
	 * This covers the rare case where test setup or a custom integration
	 * replaces $wpdb wholesale rather than mutating it.
	 *
	 * @since 3.0.0
	 */
	public function test_replaced_wpdb_global_invalidates_cache() {
		$original = $GLOBALS['wpdb'];

		$conn_original = EnvironmentTestSubject::expose_db_global( 'wpdb' );

		$replacement     = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->getMock();
		$GLOBALS['wpdb'] = $replacement;

		$conn_replacement = EnvironmentTestSubject::expose_db_global( 'wpdb' );

		$this->assertNotSame( $conn_original, $conn_replacement, 'A replaced $wpdb instance should yield a new adapter.' );

		$GLOBALS['wpdb'] = $original;
	}

	// -------------------------------------------------------------------------
	// Instance method.
	// -------------------------------------------------------------------------

	/**
	 * get_db() delegates to get_db_global() using $this->db_global.
	 *
	 * @since 3.0.0
	 */
	public function test_get_db_returns_connection() {
		$subject = new EnvironmentTestSubject();
		$this->assertInstanceOf( Connection::class, $subject->expose_db() );
	}
}
