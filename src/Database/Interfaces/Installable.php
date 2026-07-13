<?php
/**
 * Installable storage relation interface.
 *
 * @package     BerlinDB\Database\Interfaces
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Interfaces;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The DDL a storage relation (a table or a view) must provide so the shared
 * Traits\Storage\Installation lifecycle can drive it.
 *
 * Installation owns the ORCHESTRATION (install / uninstall / maybe_upgrade /
 * tombstone / lock); the concrete relation owns the SQL that differs between a
 * table and a view - CREATE/DROP, existence, and the upgrade body. Return types
 * are intentionally unspecified to match the released Table method signatures
 * (each returns a bool by convention).
 *
 * @since 3.1.0
 */
interface Installable {

	/**
	 * Create the relation in the database.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function create();

	/**
	 * Drop the relation from the database.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function drop();

	/**
	 * Return whether the relation exists in the database.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function exists();

	/**
	 * Bring the relation up to its declared version.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function upgrade();
}
