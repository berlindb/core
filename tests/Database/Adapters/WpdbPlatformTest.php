<?php
/**
 * Wpdb platform detection tests (#232).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Adapters\Platform;
use BerlinDB\Database\Adapters\Wpdb;
use BerlinDB\Database\Interfaces\PlatformProvider;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Integration tests: the Wpdb adapter detects the real database platform, the
 * 'berlindb_platform' filter overrides it, and a Table degrades an engine-only
 * construct when the platform lacks it.
 *
 * @since 3.1.0
 */
class WpdbPlatformTest extends TestCase {

	/** @var Wpdb */
	private $adapter;

	/**
	 * Fresh adapter around the shared WordPress database object.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->adapter = new Wpdb( $wpdb );
	}

	/**
	 * Remove the detection filter so a forced platform never leaks into another
	 * test (a leaked SQLite platform would break table installs).
	 *
	 * @since 3.1.0
	 */
	public function tearDown(): void {
		remove_all_filters( 'berlindb_platform' );

		parent::tearDown();
	}

	/**
	 * The Wpdb adapter provides a platform descriptor.
	 *
	 * @since 3.1.0
	 */
	public function test_wpdb_provides_a_platform() {
		$this->assertInstanceOf( PlatformProvider::class, $this->adapter );
	}

	/**
	 * Detection identifies the real test database as a known MySQL-family product
	 * with a version - not the permissive UNKNOWN fallback.
	 *
	 * @since 3.1.0
	 */
	public function test_detects_a_known_mysql_family_platform() {
		$platform = $this->adapter->platform();

		$this->assertTrue( $platform->is_known() );
		$this->assertContains( $platform->product(), array( Platform::MYSQL, Platform::MARIADB ) );
		$this->assertNotSame( '', $platform->version() );
		$this->assertTrue( $platform->has_storage_engines() );
	}

	/**
	 * The 'berlindb_platform' filter overrides detection (the host escape hatch).
	 *
	 * @since 3.1.0
	 */
	public function test_filter_overrides_detection() {
		add_filter(
			'berlindb_platform',
			static function () {
				return new Platform( Platform::SQLITE, '3.45.0' );
			}
		);

		global $wpdb;

		$platform = ( new Wpdb( $wpdb ) )->platform();

		$this->assertTrue( $platform->is( Platform::SQLITE ) );
		$this->assertFalse( $platform->has_storage_engines() );
	}

	/**
	 * A non-Platform filter return is ignored, leaving detection intact.
	 *
	 * @since 3.1.0
	 */
	public function test_non_platform_filter_return_is_ignored() {
		add_filter( 'berlindb_platform', '__return_true' );

		global $wpdb;

		$platform = ( new Wpdb( $wpdb ) )->platform();

		$this->assertTrue( $platform->is_known() );
		$this->assertContains( $platform->product(), array( Platform::MYSQL, Platform::MARIADB ) );
	}

	/**
	 * Table::engine() refuses (fails closed, logs) on a platform with no storage
	 * engines, rather than issuing an ALTER ... ENGINE= the engine cannot run.
	 *
	 * @since 3.1.0
	 */
	public function test_engine_is_refused_on_engineless_platform() {
		add_filter(
			'berlindb_platform',
			static function () {
				return new Platform( Platform::SQLITE, '3.45.0' );
			}
		);

		$table = new TestTable();

		$this->assertFalse( $table->engine( 'InnoDB' ) );

		// It fails closed with a logged warning, not silently.
		$warnings = $table->get_logs( array( 'level' => 'warning' ) );
		$this->assertNotEmpty( $warnings );
	}
}
