<?php
/**
 * Platform descriptor tests (#232).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Adapters\Platform;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the read-only database platform value object.
 *
 * @since 3.1.0
 */
class PlatformTest extends TestCase {

	/**
	 * An unknown platform is permissive: it supports every feature (BerlinDB's
	 * pre-#232 MySQL-family default) and reports no identity.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_is_permissive() {
		$platform = Platform::unknown();

		$this->assertSame( Platform::UNKNOWN, $platform->product() );
		$this->assertSame( '', $platform->version() );
		$this->assertFalse( $platform->is_known() );
		$this->assertTrue( $platform->has_storage_engines() );
	}

	/**
	 * A recognized product and version are preserved and reported.
	 *
	 * @since 3.1.0
	 */
	public function test_recognized_product_and_version() {
		$platform = new Platform( Platform::MYSQL, '8.0.35' );

		$this->assertSame( Platform::MYSQL, $platform->product() );
		$this->assertSame( '8.0.35', $platform->version() );
		$this->assertTrue( $platform->is_known() );
		$this->assertTrue( $platform->is( Platform::MYSQL ) );
		$this->assertFalse( $platform->is( Platform::SQLITE ) );
	}

	/**
	 * An unrecognized product falls back to UNKNOWN (reject-not-mutate).
	 *
	 * @since 3.1.0
	 */
	public function test_unrecognized_product_becomes_unknown() {
		$platform = new Platform( 'postgres', '16' );

		$this->assertSame( Platform::UNKNOWN, $platform->product() );
		$this->assertFalse( $platform->is_known() );
	}

	/**
	 * The product string is normalized (trimmed, lowercased) on the way in and
	 * for is() comparisons.
	 *
	 * @since 3.1.0
	 */
	public function test_product_is_normalized() {
		$platform = new Platform( '  MariaDB  ', ' 10.11.6 ' );

		$this->assertSame( Platform::MARIADB, $platform->product() );
		$this->assertSame( '10.11.6', $platform->version() );
		$this->assertTrue( $platform->is( 'MARIADB' ) );
	}

	/**
	 * SQLite has no pluggable storage engines, so it answers the storage-engine
	 * question false (the one construct BerlinDB gates on today).
	 *
	 * @since 3.1.0
	 */
	public function test_sqlite_lacks_storage_engines() {
		$platform = new Platform( Platform::SQLITE, '3.45.0' );

		$this->assertFalse( $platform->has_storage_engines() );
	}

	/**
	 * MySQL and MariaDB have storage engines.
	 *
	 * @since 3.1.0
	 */
	public function test_mysql_and_mariadb_have_storage_engines() {
		$this->assertTrue( ( new Platform( Platform::MYSQL ) )->has_storage_engines() );
		$this->assertTrue( ( new Platform( Platform::MARIADB ) )->has_storage_engines() );
	}
}
