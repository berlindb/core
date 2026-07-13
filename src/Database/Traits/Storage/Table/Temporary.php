<?php
/**
 * Temporary-table mode trait.
 *
 * @package     BerlinDB\Database\Traits\Storage\Table
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Storage\Table;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The session-scoped TEMPORARY table mode: the $temporary flag and its is_temporary()
 * reader.
 *
 * One of the Traits\Storage\Table\* collection - a base table can be TEMPORARY; a
 * View cannot, so this is Table-only. It owns the flag and its accessor; Table
 * consults the flag at the seams the mode actually changes behavior, which stay on
 * Table because they are woven into methods that cannot themselves move:
 *  - create()/drop() emit CREATE/DROP TEMPORARY TABLE,
 *  - exists() probes the table directly (a TEMPORARY table is not in SHOW TABLES),
 *  - the persists_relation_version() and should_auto_install() overrides gate the
 *    version option, uninstall tombstone, and auto-install hook off for a temporary
 *    table (they override the shared Traits\Storage\Versioning / Hooks hooks, so
 *    keeping them on Table avoids a trait-collision insteadof block).
 *
 * @since 3.1.0
 */
trait Temporary {

	/**
	 * Whether this is a session-scoped TEMPORARY table.
	 *
	 * A temporary table lives only for the current database connection: it emits
	 * CREATE/DROP TEMPORARY TABLE, is auto-dropped when the session ends, and is
	 * NOT visible to SHOW TABLES (so exists() probes it directly). Because it does
	 * not persist, it skips the version option, the uninstall tombstone, and the
	 * admin_init auto-install hook - create it on demand within the session that
	 * uses it, not once via maybe_upgrade().
	 *
	 * @since 3.1.0
	 * @var   bool
	 */
	protected $temporary = false;

	/**
	 * Return whether this is a session-scoped TEMPORARY table.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_temporary(): bool {
		return ( true === $this->temporary );
	}
}
