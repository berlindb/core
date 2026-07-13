<?php
/**
 * Base database View.
 *
 * @package     BerlinDB\Database\Kern
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Kern;

use BerlinDB\Database\Interfaces\Installable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A declared database VIEW - a named, read-oriented relation backed by a SELECT.
 *
 * A View is Table's sibling: it shares the installable-relation lifecycle
 * (Traits\Storage\{Registration, Versioning, Installation, Multisite}) - name and
 * registration, a stored version, install/uninstall/upgrade, multisite - but its
 * DDL is a SELECT, not a column list. So it composes the same Storage traits and
 * implements Interfaces\Installable with view DDL (CREATE OR REPLACE VIEW / DROP
 * VIEW / a view existence check), while a normal read-only Query pointed at the
 * registered view name reads its rows.
 *
 * MVP: the view body is a trusted raw SELECT ($definition). A declarative,
 * arrays-to-SELECT builder is a follow-up (#235); it must NOT reuse Query's
 * ephemeral request string.
 *
 * @since 3.1.0
 */
class View implements Installable {

	/**
	 * Use these traits.
	 *
	 * @since 3.1.0
	 */
	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;
	use \BerlinDB\Database\Traits\Storage\Hooks;
	use \BerlinDB\Database\Traits\Storage\Installation;
	use \BerlinDB\Database\Traits\Storage\Multisite;
	use \BerlinDB\Database\Traits\Storage\Registration;
	use \BerlinDB\Database\Traits\Storage\Versioning;

	/** Attributes ************************************************************/

	/*
	 * The relation identity ($name/$prefixed_name/$table_name/$table_prefix),
	 * version ($version/$db_version_key/$db_version), and multisite ($global) live
	 * in the Traits\Storage\* traits this class composes (#237).
	 */

	/**
	 * The SELECT statement the view is defined as (a trusted raw string).
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	protected $definition = '';

	/**
	 * Schema class name or Schema object describing the view's projected columns.
	 *
	 * Used for READING: a Query against the view name is configured with the same
	 * schema so rows hydrate into their shaped columns. Unlike a Table, the view's
	 * columns are defined by $definition (the SELECT), not by this schema - they
	 * must agree, which the developer keeps in sync.
	 *
	 * @since 3.1.0
	 * @var   class-string<Schema>|Schema|''
	 */
	protected $schema = '';

	/**
	 * Instantiated schema object, populated by set_schema() during boot.
	 *
	 * @since 3.1.0
	 * @var   Schema|null
	 */
	private $schema_object = null;

	/*
	 * $auto_install and add_hooks() live in the Traits\Storage\Hooks trait (#237).
	 */

	/** Construction **********************************************************/

	/**
	 * Sanitization callbacks for this view's configuration arguments.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed>
	 */
	protected function get_config_callbacks(): array {
		return array(

			// Identity.
			'name'           => array( $this, 'sanitize_table_name' ),
			'prefixed_name'  => array( $this, 'sanitize_table_name' ),
			'table_name'     => array( $this, 'sanitize_table_name' ),
			'table_prefix'   => array( $this, 'sanitize_table_name' ),

			// Definition & reading.
			'definition'     => '',
			'schema'         => '',

			// Version.
			'version'        => 'wp_kses_data',
			'db_version_key' => 'wp_kses_data',
			'db_version'     => 'wp_kses_data',

			// Multisite & install.
			'global'         => array( $this, 'sanitize_boolean' ),
			'auto_install'   => array( $this, 'sanitize_boolean' ),
		);
	}

	/**
	 * Establish the view's identity, register it, and wire its hooks.
	 *
	 * @since 3.1.0
	 */
	protected function init(): void {

		// Establish the view's identity from its name.
		$this->set_table_name();

		// Bail if the name could not be sanitized.
		if ( empty( $this->name ) ) {
			return;
		}

		$this->set_prefixed_name();
		$this->set_db_version_key();

		// Bail if identity is incomplete.
		if ( empty( $this->db_version_key ) ) {
			return;
		}

		// Register the relation with the database interface (Storage\Registration).
		$this->set_db_interface();

		// Load the reading schema.
		$this->set_schema();

		// Add hooks.
		$this->add_hooks();

		// Maybe force upgrade if testing.
		if ( $this->is_testing() ) {
			$this->maybe_upgrade();
		}
	}

	/** Installable ***********************************************************/

	/**
	 * Create (or replace) this view from its SELECT definition.
	 *
	 * CREATE OR REPLACE VIEW redefines an existing view in place, which is also
	 * how the shared upgrade path re-applies a changed definition.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function create() {

		// Bail if there is no definition to create the view from.
		if ( empty( $this->definition ) ) {
			return false;
		}

		// Query statement.
		$sql    = "CREATE OR REPLACE VIEW {$this->table_name} AS {$this->definition}";
		$result = $this->db()->query( $sql );

		// Was the view created?
		return $this->is_success( $result );
	}

	/**
	 * Drop this view.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function drop() {

		// Query statement.
		$sql    = "DROP VIEW {$this->table_name}";
		$result = $this->db()->query( $sql );

		// Did the view get dropped?
		return $this->is_success( $result );
	}

	/**
	 * Return whether this view exists in the database.
	 *
	 * Checks that a relation of this name exists AND is a VIEW (not a base table),
	 * via SHOW FULL TABLES, whose rows carry a Table_type of 'VIEW' or 'BASE TABLE'.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function exists() {

		// Query statement.
		$sql      = 'SHOW FULL TABLES LIKE %s';
		$like     = $this->db()->esc_like( $this->table_name );
		$prepared = $this->db()->prepare( $sql, $like );
		$row      = $this->db()->get_row( $prepared );

		// Does a VIEW of this name exist?
		return is_object( $row ) && isset( $row->Table_type ) && ( 'VIEW' === $row->Table_type ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Bring this view up to its declared version.
	 *
	 * A view has no structural drift to reconcile: CREATE OR REPLACE redefines it
	 * from the current definition, then the version is recorded. (Bespoke upgrade
	 * callbacks are a follow-up; the MVP always re-applies the definition.)
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function upgrade() {

		// Re-apply the definition; bail if it could not be (re)created.
		if ( true !== $this->create() ) {
			return false;
		}

		// Record the version.
		$this->set_db_version();

		// Return, without failure.
		return true;
	}

	/** Public Management *****************************************************/

	/**
	 * Return the CREATE VIEW SQL for this view.
	 *
	 * Runs SHOW CREATE VIEW and returns the SQL string, or false when the view does
	 * not exist or the query fails.
	 *
	 * @since 3.1.0
	 *
	 * @return string|false
	 */
	public function get_create_sql(): string|false {

		// Query statement - SHOW CREATE VIEW returns exactly one row.
		$sql    = "SHOW CREATE VIEW {$this->table_name}";
		$result = $this->db()->get_row( $sql );

		// Return the CREATE VIEW definition, or false on failure.
		return ( is_object( $result ) && isset( $result->{'Create View'} ) ) ? $result->{'Create View'} : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Return the view's declared SELECT definition.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_definition(): string {
		return (string) $this->definition;
	}

	/**
	 * Return the view's reading Schema object, or null when unavailable.
	 *
	 * @since 3.1.0
	 *
	 * @return Schema|null
	 */
	public function get_schema(): ?Schema {
		return ( $this->schema_object instanceof Schema )
			? $this->schema_object
			: null;
	}

	/** Private ***************************************************************/

	/**
	 * Load the view's reading Schema object.
	 *
	 * @since 3.1.0
	 */
	private function set_schema(): void {

		// Accept a Schema object passed directly.
		if ( $this->schema instanceof Schema ) {
			$this->schema_object = $this->schema;
			return;
		}

		// Instantiate a Schema class name.
		if ( is_string( $this->schema ) && ! empty( $this->schema ) ) {
			$object = $this->instantiate_class( $this->schema );

			if ( $object instanceof Schema ) {
				$this->schema_object = $object;
			}
		}

		// Log a load failure (a view is still creatable without a reading schema).
		if ( ! ( $this->schema_object instanceof Schema ) ) {
			$this->log(
				'warning',
				'view_schema_unavailable',
				'View reading schema could not be loaded.',
				array(
					'schema' => is_string( $this->schema )
						? $this->schema
						: '',
				)
			);
		}
	}
}
