<?php
/**
 * Storage relation hooks trait.
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
 * Wires a storage relation (a table or a view) into the WordPress action lifecycle:
 * multisite site-switching, and optional auto-install on admin_init.
 *
 * Extracted from Table (#237) so Table and View share one add_hooks(). Whether the
 * relation auto-installs is a should_auto_install() hook (default: the $auto_install
 * flag); Table overrides it to also exclude a TEMPORARY table, keeping this shared
 * trait free of temporary-table knowledge. Requires the composing class to provide
 * switch_blog() and maybe_upgrade() (Storage\Multisite + Storage\Installation).
 *
 * @since 3.1.0
 */
trait Hooks {

	/**
	 * Whether to hook maybe_upgrade() to admin_init for automatic installation.
	 *
	 * Set to false in a subclass to disable auto-install and require explicit
	 * calls to install() or maybe_upgrade() instead.
	 *
	 * @since 3.1.0
	 * @var   bool
	 */
	protected $auto_install = true;

	/**
	 * Whether this relation should auto-install on admin_init.
	 *
	 * The default is the $auto_install flag; a relation that cannot meaningfully
	 * auto-install (a TEMPORARY table) overrides this to false.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	protected function should_auto_install(): bool {
		return $this->auto_install;
	}

	/**
	 * Add class hooks to the parent application actions.
	 *
	 * @since 3.1.0
	 */
	private function add_hooks(): void {

		// Multisite site-switching always applies.
		add_action( 'switch_blog', array( $this, 'switch_blog' ) );

		// Only auto-install on admin_init when the relation opts in.
		if ( $this->should_auto_install() ) {
			add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		}
	}
}
