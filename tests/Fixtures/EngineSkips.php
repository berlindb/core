<?php
/**
 * Skip helpers for known, tracked cross-engine divergences.
 *
 * The CI matrix runs both MySQL and MariaDB (see berlindb/core#230). A handful of
 * tests assert behavior that genuinely differs between the engines; rather than let
 * those legs stay red, the affected test skips on the diverging engine and points at
 * the issue tracking the fix. Remove the skip once the underlying issue is resolved.
 *
 * @package     BerlinDB\Tests\Fixtures
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests\Fixtures;

use BerlinDB\Database\Adapters\Platform;
use BerlinDB\Database\Adapters\Wpdb;

/**
 * Engine-aware test skips backed by the Wpdb platform descriptor.
 *
 * @since 3.1.0
 */
trait EngineSkips {

	/**
	 * The detected database platform (product + version) for the test DB.
	 *
	 * @since 3.1.0
	 *
	 * @return Platform
	 */
	private function current_platform(): Platform {
		return ( new Wpdb( $GLOBALS['wpdb'] ) )->platform();
	}

	/**
	 * Skip the current test on MySQL (any version).
	 *
	 * @since 3.1.0
	 *
	 * @param string $reason Human-readable reason, including the tracking issue.
	 */
	private function skip_on_mysql( string $reason ): void {
		if ( Platform::MYSQL === $this->current_platform()->product() ) {
			$this->markTestSkipped( $reason );
		}
	}

	/**
	 * Skip the current test on MariaDB at or above a given version.
	 *
	 * @since 3.1.0
	 *
	 * @param string $version Minimum MariaDB version to skip at (e.g. '11').
	 * @param string $reason  Human-readable reason, including the tracking issue.
	 */
	private function skip_on_mariadb_at_least( string $version, string $reason ): void {
		$platform = $this->current_platform();

		if ( ( Platform::MARIADB === $platform->product() ) && version_compare( $platform->version(), $version, '>=' ) ) {
			$this->markTestSkipped( $reason );
		}
	}
}
