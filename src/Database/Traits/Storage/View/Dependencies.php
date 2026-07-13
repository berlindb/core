<?php
/**
 * View dependency (defer-and-retry) trait.
 *
 * @package     BerlinDB\Database\Traits\Storage\View
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Storage\View;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The relations a view's SELECT reads from, and the check that lets create() defer
 * until they exist.
 *
 * The first of the Traits\Storage\View\* collection - storage traits specific to a
 * View, the counterpart to Traits\Storage\Table\* (as opposed to the Traits\Storage\*
 * traits shared by every relation). Dependencies is View-specific because a view can
 * only be created after the relations it selects from exist, and BerlinDB installs
 * relations in no guaranteed order; a base table has no such body-level prerequisite
 * (its deferred concern is foreign keys, handled on Table). Names are resolved with
 * the composing view's own prefix, so they name same-plugin siblings.
 *
 * @since 3.1.0
 */
trait Dependencies {

	/**
	 * Sibling relations this view's SELECT reads from, as unprefixed base names.
	 *
	 * A view cannot be created before the relations it selects from exist, and
	 * BerlinDB installs relations in no guaranteed order. Declaring those siblings
	 * here makes auto-install safe: create() DEFERS (does nothing, does not advance
	 * the version) until every dependency exists, so the next maybe_upgrade() on
	 * admin_init retries and the view self-heals once its sources land. Names are
	 * resolved with this view's own prefix, so list same-plugin siblings; tables
	 * that are always present (WordPress core) need not be declared.
	 *
	 * @since 3.1.0
	 * @var   string[]
	 */
	protected $depends_on = array();

	/**
	 * Sanitize the declared dependency list to non-empty relation base names.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value Raw depends_on config value.
	 * @return string[]
	 */
	private function sanitize_depends_on( $value ): array {

		$names = array();

		// Keep each sanitizable, non-empty base name.
		foreach ( (array) $value as $name ) {
			$sanitized = $this->sanitize_table_name( $name );

			if ( ! empty( $sanitized ) ) {
				$names[] = $sanitized;
			}
		}

		return $names;
	}

	/**
	 * Return the declared dependencies that do not yet exist in the database.
	 *
	 * @since 3.1.0
	 *
	 * @return string[] The missing dependency base names (empty when all exist).
	 */
	private function missing_dependencies(): array {

		$missing = array();

		foreach ( $this->depends_on as $dependency ) {
			if ( ! $this->dependency_exists( $dependency ) ) {
				$missing[] = $dependency;
			}
		}

		return $missing;
	}

	/**
	 * Return whether a sibling relation exists, by its unprefixed base name.
	 *
	 * Prefers the sibling's OWN registered full name from the connection (set during
	 * its set_db_interface()), which carries its real scope - so a per-site view can
	 * depend on a global sibling without the site prefix being wrongly applied. Falls
	 * back to resolving with this view's own prefix for a relation not yet registered
	 * (which assumes the dependency shares this view's plugin prefix and scope). Then
	 * probes with SHOW TABLES, which lists both base tables and views (so a view-on-
	 * view dependency works).
	 *
	 * @since 3.1.0
	 *
	 * @param string $name The dependency's unprefixed base name.
	 * @return bool
	 */
	private function dependency_exists( string $name ): bool {

		// Prefer the sibling's registered name (correct scope); else resolve locally.
		$prefixed_name = $this->apply_prefix( $name, '_' );
		$registered    = $this->db()->get_table_prefix( $prefixed_name );
		$prefixed      = ( '' !== $registered )
			? $registered
			: ( $this->table_prefix . $prefixed_name );

		// Query statement - SHOW TABLES LIKE lists base tables and views.
		$sql      = 'SHOW TABLES LIKE %s';
		$like     = $this->db()->esc_like( $prefixed );
		$prepared = $this->db()->prepare( $sql, $like );

		// Does a relation of that name exist?
		return ! empty( $this->db()->get_var( $prepared ) );
	}
}
