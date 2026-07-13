<?php
/**
 * Storage relation registration trait.
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
 * Names an installable database relation (a table or a view) and registers it
 * with the database interface.
 *
 * The identity every storage relation shares: a base name, the plugin-prefixed
 * name, the fully site-prefixed SQL name, and its registration on the connection
 * so a Query can resolve it. Extracted from Table (#237) so Table and View both
 * compose it; the charset/collation applied afterward is table-specific and
 * stays on Table.
 *
 * Requires the composing class to provide sanitize_table_name(), apply_prefix(),
 * db(), and is_global() (all present on Kern classes via their shared traits).
 *
 * @since 3.1.0
 */
trait Registration {

	/**
	 * Relation name, without the global table prefix.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $name = '';

	/**
	 * Relation prefix, including the site prefix.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $table_prefix = '';

	/**
	 * Full, site-prefixed SQL relation name.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $table_name = '';

	/**
	 * Relation name, plugin-prefixed from the base.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $prefixed_name = '';

	/**
	 * Return this relation's full, prefixed SQL name.
	 *
	 * The name is already built with both the WordPress table prefix and the
	 * BerlinDB plugin prefix during construction, so it is ready to drop straight
	 * into a statement (as add_index() and friends already do internally).
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		return $this->table_name;
	}

	/**
	 * Sanitize and set this relation's name.
	 *
	 * Rejects (does not keep) an unsanitizable name by setting it empty, so
	 * init() can bail before deriving anything from it.
	 *
	 * @since 3.1.0
	 */
	private function set_table_name(): void {
		$sanitized = $this->sanitize_table_name( $this->name );

		$this->name = ( false !== $sanitized )
			? $sanitized
			: '';
	}

	/**
	 * Build the prefixed relation name from the (sanitized) name.
	 *
	 * @since 3.1.0
	 */
	private function set_prefixed_name(): void {
		$this->prefixed_name = $this->apply_prefix( $this->name, '_' );
	}

	/**
	 * Register this relation with the database interface.
	 *
	 * This must be done directly because the database interface does not
	 * have a common mechanism for manipulating them safely.
	 *
	 * @since 1.0.0
	 */
	private function set_db_interface(): void {

		// Set variables for global tables.
		if ( $this->is_global() ) {
			$site_id = 0;
			$tables  = 'ms_global_tables';

			// Set variables for per-site tables.
		} else {
			$site_id = null;
			$tables  = 'tables';
		}

		// Set table prefix and prefix table name.
		$this->table_prefix = $this->db()->get_blog_prefix( $site_id );

		// Get the prefixed table name.
		$prefixed_table_name = "{$this->table_prefix}{$this->prefixed_name}";

		// Set the table name and register it in the database interface.
		$this->table_name = $prefixed_table_name;
		$this->db()->set_table_prefix( $this->prefixed_name, $prefixed_table_name );

		// Add relation to the global table array.
		$this->db()->register_table( $tables, $this->prefixed_name );
	}
}
