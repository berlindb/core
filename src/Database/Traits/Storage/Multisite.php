<?php
/**
 * Storage relation multisite trait.
 *
 * @package     BerlinDB\Database\Traits\Storage
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Storage;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The multisite behavior a storage relation (a table or a view) shares: whether
 * it is a per-site or network-global relation, and re-resolving its identity when
 * WordPress switches the active site.
 *
 * Extracted from Table (#237). Requires the composing class to provide
 * set_db_interface() and the $db_version_key / $db_version identity
 * (Storage\Registration + Storage\Versioning).
 *
 * @since 3.1.0
 */
trait Multisite {

	/**
	 * Whether this relation is per-site or network-global.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	protected $global = false;

	/**
	 * Re-resolve the relation's version and registration for a switched site.
	 *
	 * Hooked to the "switch_blog" action.
	 *
	 * @since 1.0.0
	 *
	 * @param int $site_id The site being switched to.
	 */
	public function switch_blog( $site_id = 0 ): void {

		// Update DB version based on the current site.
		if ( ! $this->is_global() ) {
			$this->db_version = (string) get_blog_option( $site_id, $this->db_version_key, '' );
		}

		// Update interface for switched site.
		$this->set_db_interface();
	}

	/**
	 * Return whether this relation is network-global.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_global(): bool {
		return $this->global;
	}
}
