<?php
/**
 * Platform-providing Connection interface.
 *
 * @package     BerlinDB\Database\Interfaces
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Interfaces;

use BerlinDB\Database\Adapters\Platform;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Optional Connection extension that provides its database platform descriptor.
 *
 * Kept SEPARATE from Connection (which is @since 3.0.0 - adding a method there
 * would break every custom adapter that already implements it). A Connection MAY
 * also implement this; BerlinDB checks `instanceof PlatformProvider` and falls
 * back to Platform::unknown() (the permissive default) when it is absent, so
 * existing adapters keep working unchanged.
 *
 * A connection is not a Platform, it PROVIDES one - hence the noun-role name
 * (matching Connection / MetaStore), not a Platform interface the connection
 * would implement directly.
 *
 * @since 3.1.0
 */
interface PlatformProvider {

	/**
	 * Return the descriptor for the underlying database platform.
	 *
	 * @since 3.1.0
	 *
	 * @return Platform
	 */
	public function platform(): Platform;
}
