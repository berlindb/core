<?php
/**
 * Base Custom Database Table Class.
 *
 * @package     Database
 * @subpackage  Table
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Kern;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A base database table class, which facilitates the creation of (and schema
 * changes to) individual database tables.
 *
 * This class is intended to be extended for each unique database table,
 * including global tables for multisite, and users tables.
 *
 * It exists to make managing database tables as easy as possible.
 *
 * Extending this class comes with several automatic benefits:
 * - Activation hook makes it great for plugins
 * - Tables store their versions in the database independently
 * - Tables upgrade via independent upgrade abstract methods
 * - Multisite friendly - site tables switch on "switch_blog" action
 *
 * @since 1.0.0
 */
class Table {

	/**
	 * Use the following traits:
	 *
	 * @since 3.0.0
	 */
	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;

	/** Attributes ************************************************************/

	/**
	 * Table name, without the global table prefix.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $name = '';

	/**
	 * Optional description.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $description = '';

	/**
	 * Plugin-level prefix for table names, hooks, and cache groups.
	 *
	 * Set this to your plugin's unique slug (e.g. 'edd', 'give') so that
	 * tables, hooks, and cache groups are namespaced to your plugin.
	 *
	 * Declare it as a class property in your subclass — it is read during
	 * construction before setup() runs, so it is always available when
	 * table names are assembled.
	 *
	 * apply_prefix() uses this value to produce the prefixed table name
	 * (e.g. 'edd_orders'). The WordPress table prefix ($wpdb->prefix,
	 * e.g. 'wp_') is separate and is always prepended by set_db_interface(),
	 * making the final name 'wp_edd_orders'.
	 *
	 * Inherited from the Base trait, it is redeclared here so subclass authors
	 * see it alongside the other table properties.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $prefix = '';

	/**
	 * Database version.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $version = '';

	/**
	 * Is this table for a site, or global.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	protected $global = false;

	/**
	 * Database version key (saved in _options or _sitemeta)
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $db_version_key = '';

	/**
	 * Current database version.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $db_version = '';

	/**
	 * Table prefix, including the site prefix.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $table_prefix = '';

	/**
	 * Table name.
	 *
	 * @since 1.0.0
	 * @var  string
	 */
	protected $table_name = '';

	/**
	 * Table name, prefixed from the base.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $prefixed_name = '';

	/**
	 * Table schema class name.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $schema = '';

	/**
	 * Database character-set & collation for table.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $charset_collation = '';

	/**
	 * Typically empty; probably ignore.
	 *
	 * By default, tables do not have comments. This is unused by any other
	 * relative code, but you can include up to 2048 characters here.
	 *
	 * @since 3.0.0
	 * @var   string
	 */
	protected $comment = '';

	/**
	 * Key => value array of versions => methods.
	 *
	 * @since 1.0.0
	 * @var   array<string, callable|string>
	 */
	protected $upgrades = array();

	/**
	 * Instantiated schema object, populated by set_schema() during boot.
	 *
	 * @since 3.0.0
	 * @var   Schema|null
	 */
	private $schema_object = null;

	/**
	 * Called after initialization.
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {

		// Setup this database table.
		$this->setup();

		// Bail if setup failed.
		if ( empty( $this->name ) || empty( $this->db_version_key ) ) {
			return;
		}

		// Add table to the database interface.
		$this->set_db_interface();

		// Add the database schema.
		$this->set_schema();

		// Add hooks.
		$this->add_hooks();

		// Maybe force upgrade if testing.
		if ( $this->is_testing() ) {
			$this->maybe_upgrade();
		}
	}

	/** Argument Handlers *****************************************************/

	/**
	 * Validate arguments after they are parsed.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $args Default empty array.
	 * @return array<string, mixed>
	 */
	protected function validate_args( $args = array() ) {

		// Sanitization callbacks.
		$callbacks = array(

			// Table.
			'name'              => array( $this, 'sanitize_table_name' ),
			'description'       => 'wp_kses_data',
			'version'           => 'wp_kses_data',
			'global'            => 'wp_validate_boolean',
			'db_version_key'    => 'wp_kses_data',
			'db_version'        => 'wp_kses_data',
			'table_prefix'      => array( $this, 'sanitize_table_name' ),
			'table_name'        => array( $this, 'sanitize_table_name' ),
			'prefixed_name'     => array( $this, 'sanitize_table_name' ),
			'schema'            => '',
			'charset_collation' => 'wp_kses_data',
			'comment'           => function ( $v ) {
				return $this->sanitize_comment( $v, 2048 );
			},

			// Extras.
			'upgrades'          => '',
		);

		// Default return arguments.
		$r = array();

		// Loop through and try to execute callbacks.
		foreach ( $args as $key => $value ) {

			// Callback is callable.
			if ( isset( $callbacks[ $key ] ) && is_callable( $callbacks[ $key ] ) ) {
				$r[ $key ] = call_user_func( $callbacks[ $key ], $value );

				/**
				 * Key has no validation method.
				 *
				 * Trust that the value has been validated. This may change in a
				 * future version.
				 */
			} else {
				$r[ $key ] = $value;
			}
		}

		// Return sanitized arguments.
		return $r;
	}

	/** Multisite *************************************************************/

	/**
	 * Update table version & references.
	 *
	 * Hooked to the "switch_blog" action.
	 *
	 * @since 1.0.0
	 *
	 * @param int $site_id The site being switched to
	 */
	public function switch_blog( $site_id = 0 ): void {

		// Update DB version based on the current site.
		if ( ! $this->is_global() ) {
			$this->db_version = get_blog_option( $site_id, $this->db_version_key, false );
		}

		// Update interface for switched site.
		$this->set_db_interface();
	}

	/** Public Helpers ********************************************************/

	/**
	 * Maybe upgrade this database table.
	 *
	 * Handles locking, creation, and schema changes.
	 *
	 * Hooked to the `admin_init` action.
	 *
	 * @since 1.0.0
	 */
	public function maybe_upgrade(): void {

		// Bail if not upgradeable.
		if ( ! $this->is_upgradeable() ) {
			return;
		}

		// Bail if upgrade not needed.
		if ( ! $this->needs_upgrade() ) {
			return;
		}

		// Bail if locked.
		if ( ! $this->lock_upgrades() ) {
			return;
		}

		// Upgrade or install, always release the lock afterward.
		try {

			// Upgrade.
			if ( $this->exists() ) {
				$this->upgrade();

				// Install.
			} else {
				$this->install();
			}
		} finally {

			// Always release the lock, even if an exception occurred.
			$this->unlock_upgrades();
		}
	}

	/**
	 * Return whether this table needs an upgrade.
	 *
	 * @since 1.0.0
	 *
	 * @param string $version Database version to check if upgrade is needed
	 *
	 * @return bool True if table needs upgrading. False if not.
	 */
	public function needs_upgrade( $version = '' ) {

		// Use the current table version if none was passed.
		if ( empty( $version ) ) {
			$version = $this->version;
		}

		// Get the current database version.
		$this->get_db_version();

		// Is this database table up to date?
		$is_current = version_compare( (string) $this->db_version, (string) $version, '>=' );

		// Return false if current, true if out of date.
		return ( true === $is_current )
			? false
			: true;
	}

	/**
	 * Return whether this table can be upgraded.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if table can be upgraded. False if not.
	 */
	public function is_upgradeable() {

		// Bail if global and upgrading global tables is not allowed.
		if ( $this->is_global() && ! wp_should_upgrade_global_tables() ) {
			return false;
		}

		// Kinda weird, but assume it is.
		return true;
	}

	/**
	 * Return the current table version from the database.
	 *
	 * This is public method for accessing a private variable so that it cannot
	 * be externally modified.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_version(): string {
		$this->get_db_version();

		return (string) $this->db_version;
	}

	/**
	 * Install a database table
	 *
	 * Create table and set the version if successful.
	 *
	 * @since 1.0.0
	 */
	public function install(): void {

		// Try to create the table.
		$created = $this->create();

		// Set the DB version if create was successful.
		if ( true === $created ) {
			$this->set_db_version();
		}
	}

	/**
	 * Uninstall a database table
	 *
	 * Drops table and deletes the version information if successful.
	 *
	 * If the table does not exist, the version will still be deleted.
	 *
	 * @since 1.0.0
	 */
	public function uninstall(): void {

		// Try to drop the table.
		$dropped = $this->drop();

		// Delete the DB version if drop was successful or table does not exist.
		if ( ( true === $dropped ) || ! $this->exists() ) {
			$this->delete_db_version();
		}
	}

	/** Public Management *****************************************************/

	/**
	 * Check if table exists.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function exists() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql      = 'SHOW TABLES LIKE %s';
		$like     = $db->esc_like( $this->table_name );
		$prepared = $db->prepare( $sql, $like );
		$result   = $db->get_var( $prepared );

		// Does the table exist?
		return $this->is_success( $result );
	}

	/**
	 * Get status of table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/show-table-status.html
	 *
	 * @since 3.0.0
	 *
	 * @return object|false Table status object, or false if the database is
	 *                      unavailable or the table does not exist.
	 */
	public function status() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql      = 'SHOW TABLE STATUS LIKE %s';
		$like     = $db->esc_like( $this->table_name );
		$prepared = $db->prepare( $sql, $like );
		$query    = (array) $db->get_results( $prepared );
		$result   = end( $query );

		// Does the table exist?
		return $this->is_success( $result )
			? $result
			: false;
	}

	/**
	 * Get columns from table.
	 *
	 * @since 1.2.0
	 *
	 * @return list<mixed>|false Array of column rows on success, false on failure.
	 */
	public function columns() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "SHOW FULL COLUMNS FROM {$this->table_name}";
		$result = $db->get_results( $sql );

		// Return the results.
		return $this->is_success( $result )
			? $result
			: false;
	}

	/**
	 * Get indexes from table.
	 *
	 * @since 3.0.0
	 *
	 * @return list<mixed>|false Array of index rows on success, false on failure.
	 */
	public function indexes() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "SHOW INDEXES FROM {$this->table_name}";
		$result = $db->get_results( $sql );

		// Return the results.
		return $this->is_success( $result )
			? $result
			: false;
	}

	/**
	 * Add an index to this database table.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed>|Index $args Index arguments or an Index object.
	 *
	 * @return bool
	 */
	public function add_index( $args = array() ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Create index object from arguments.
		$index = ( $args instanceof Index )
			? $args
			: new Index( $args );

		// Get index SQL create string.
		$index_sql = $index->get_create_string();

		// Bail if no valid SQL was generated.
		if ( empty( $index_sql ) ) {
			return false;
		}

		// Query statement.
		$sql    = "ALTER TABLE {$this->table_name} ADD {$index_sql}";
		$result = $db->query( $sql );

		// Was the index added?
		return $this->is_success( $result );
	}

	/**
	 * Drop an index from this database table.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Index name.
	 *
	 * @return bool
	 */
	public function drop_index( $name = '' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Sanitize the index name.
		$name = $this->sanitize_column_name( $name );

		// Bail if index name is invalid.
		if ( empty( $name ) ) {
			return false;
		}

		// Query statement.
		$sql = ( 'primary' === strtolower( $name ) )
			? "ALTER TABLE {$this->table_name} DROP PRIMARY KEY"
			: "ALTER TABLE {$this->table_name} DROP INDEX `{$name}`";

		$result = $db->query( $sql );

		// Was the index dropped?
		return $this->is_success( $result );
	}

	/**
	 * Create this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function create() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Bail if no schema to call.
		if ( ! is_callable( array( $this->schema_object, 'get_create_table_string' ) ) ) {
			return false;
		}

		// Get the "CREATE TABLE" string.
		$create_table_string = $this->schema_object->get_create_table_string();

		// Bail if no create string.
		if ( empty( $create_table_string ) ) {
			return false;
		}

		// Required parts.
		$sql = array(
			'CREATE TABLE',
			$this->table_name,
			"( {$create_table_string} )",
			$this->charset_collation,
		);

		// Maybe append comment.
		if ( ! empty( $this->comment ) ) {
			$sql[] = "COMMENT='" . addslashes( $this->comment ) . "'";
		}

		// Query statement.
		$query  = implode( ' ', array_filter( $sql ) );
		$result = $db->query( $query );

		// Was the table created?
		return $this->is_success( $result );
	}

	/**
	 * Drop this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function drop() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "DROP TABLE {$this->table_name}";
		$result = $db->query( $sql );

		// Did the table get dropped?
		return $this->is_success( $result );
	}

	/**
	 * Truncate this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function truncate() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "TRUNCATE TABLE {$this->table_name}";
		$result = $db->query( $sql );

		// Did the table get truncated?
		return $this->is_success( $result );
	}

	/**
	 * Delete all items from this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function delete_all() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "DELETE FROM {$this->table_name}";
		$result = $db->query( $sql );

		// Return true as long as no SQL error occurred; 0 rows deleted is still a success.
		return false !== $result;
	}

	/**
	 * Duplicate this database table.
	 *
	 * Pair with copy().
	 *
	 * Both the WordPress table prefix and the BerlinDB plugin prefix are
	 * applied to the new table name automatically, matching how
	 * $this->table_name is built.
	 *
	 * @since 3.0.0
	 *
	 * @param string $new_table_name The name of the new table, without any prefix.
	 *
	 * @return bool
	 */
	public function duplicate( $new_table_name = '' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Sanitize the new table name.
		$table_name = $this->sanitize_table_name( $new_table_name );

		// Bail if new table name is invalid.
		if ( empty( $table_name ) ) {
			return false;
		}

		// Query statement.
		$table  = $this->table_prefix . $this->apply_prefix( $table_name );
		$sql    = "CREATE TABLE {$table} LIKE {$this->table_name}";
		$result = $db->query( $sql );

		// Did the table get duplicated?
		return $this->is_success( $result );
	}

	/**
	 * Copy the contents of this table to a new table.
	 *
	 * Pair with duplicate().
	 *
	 * Both the WordPress table prefix and the BerlinDB plugin prefix are
	 * applied to the new table name automatically, matching how
	 * $this->table_name is built.
	 *
	 * @since 1.1.0
	 *
	 * @param string $new_table_name The name of the destination table, without any prefix.
	 *
	 * @return bool
	 */
	public function copy( $new_table_name = '' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Sanitize the new table name.
		$table_name = $this->sanitize_table_name( $new_table_name );

		// Bail if new table name is invalid.
		if ( empty( $table_name ) ) {
			return false;
		}

		// Query statement.
		$table  = $this->table_prefix . $this->apply_prefix( $table_name );
		$sql    = "INSERT INTO {$table} SELECT * FROM {$this->table_name}";
		$result = $db->query( $sql );

		// Did the table get copied?
		return $this->is_success( $result );
	}

	/**
	 * Count the number of items in this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function count() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return 0;
		}

		// Query statement.
		$sql    = "SELECT COUNT(*) FROM {$this->table_name}";
		$result = $db->get_var( $sql );

		// 0 on error/empty, number of rows on success.
		return intval( $result );
	}

	/**
	 * Rename this database table.
	 *
	 * Both the WordPress table prefix and the BerlinDB plugin prefix are
	 * applied to the new table name automatically, matching how
	 * $this->table_name is built.
	 *
	 * After a successful rename, $this->table_name is not updated — callers
	 * are responsible for refreshing any references to the old name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $new_table_name The new name for this table, without any prefix.
	 *
	 * @return bool
	 */
	public function rename( $new_table_name = '' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Sanitize the new table name.
		$table_name = $this->sanitize_table_name( $new_table_name );

		// Bail if new table name is invalid.
		if ( empty( $table_name ) ) {
			return false;
		}

		// Query statement.
		$table  = $this->table_prefix . $this->apply_prefix( $table_name );
		$sql    = "RENAME TABLE {$this->table_name} TO {$table}";
		$result = $db->query( $sql );

		// Did the table get renamed?
		return $this->is_success( $result );
	}

	/**
	 * Check if column already exists.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses sanitize_column_name().
	 *
	 * @param string $name Column name to check.
	 *
	 * @return bool
	 */
	public function column_exists( $name = '' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql      = "SHOW COLUMNS FROM {$this->table_name} LIKE %s";
		$name     = $this->sanitize_column_name( $name );
		$like     = $db->esc_like( $name );
		$prepared = $db->prepare( $sql, $like );
		$result   = $db->query( $prepared );

		// Does the column exist?
		return $this->is_success( $result );
	}

	/**
	 * Check if index already exists.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses sanitize_column_name().
	 *
	 * @param string $name   Index name to check.
	 * @param string $column Column name to compare.
	 *
	 * @return bool
	 */
	public function index_exists( $name = '', $column = 'Key_name' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Limit $column to Key or Column name, until we can do better.
		if ( ! in_array( $column, array( 'Key_name', 'Column_name' ), true ) ) {
			$column = 'Key_name';
		}

		// Query statement.
		$sql      = "SHOW INDEXES FROM {$this->table_name} WHERE {$column} LIKE %s";
		$name     = $this->sanitize_column_name( $name );
		$like     = $db->esc_like( $name );
		$prepared = $db->prepare( $sql, $like );
		$result   = $db->query( $prepared );

		// Does the index exist?
		return $this->is_success( $result );
	}

	/** Repair ****************************************************************/

	/**
	 * Analyze this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/analyze-table.html
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function analyze() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "ANALYZE TABLE {$this->table_name}";
		$query  = (array) $db->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text )
			? $result->Msg_text
			: false;
	}

	/**
	 * Check this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/check-table.html
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function check() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "CHECK TABLE {$this->table_name}";
		$query  = (array) $db->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text )
			? $result->Msg_text
			: false;
	}

	/**
	 * Get the Checksum of this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/checksum-table.html
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function checksum() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "CHECKSUM TABLE {$this->table_name}";
		$query  = (array) $db->get_results( $sql );
		$result = end( $query );

		// Return checksum.
		return ! empty( $result->Checksum )
			? $result->Checksum
			: false;
	}

	/**
	 * Optimize this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/optimize-table.html
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function optimize() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "OPTIMIZE TABLE {$this->table_name}";
		$query  = (array) $db->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text )
			? $result->Msg_text
			: false;
	}

	/**
	 * Repair this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/repair-table.html
	 * Note: Not supported by InnoDB, the default engine in MySQL 8 and higher.
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function repair() {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return false;
		}

		// Query statement.
		$sql    = "REPAIR TABLE {$this->table_name}";
		$query  = (array) $db->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text )
			? $result->Msg_text
			: false;
	}

	/** Upgrades **************************************************************/

	/**
	 * Upgrade this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function upgrade() {

		// Get pending upgrades.
		$upgrades = $this->get_pending_upgrades();

		// Bail if no upgrades.
		if ( empty( $upgrades ) ) {
			$this->set_db_version();

			// Return, without failure.
			return true;
		}

		// Default result.
		$result = false;

		// Try to do the upgrades.
		foreach ( $upgrades as $version => $callback ) {

			// Do the upgrade.
			$result = $this->upgrade_to( $version, $callback );

			// Bail if an error occurs, to avoid skipping upgrades.
			if ( ! $this->is_success( $result ) ) {
				return false;
			}
		}

		// Success/fail.
		return $this->is_success( $result );
	}

	/**
	 * Return array of upgrades that still need to run.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, mixed> Array of upgrade callbacks, keyed by their db version.
	 */
	public function get_pending_upgrades() {

		// Default return value.
		$upgrades = array();

		// Bail if no upgrades, or no database version to compare to.
		if ( empty( $this->upgrades ) || empty( $this->db_version ) ) {
			return $upgrades;
		}

		// Loop through all upgrades, and pick out the ones that need doing.
		foreach ( $this->upgrades as $version => $callback ) {
			if ( true === version_compare( (string) $version, (string) $this->db_version, '>' ) ) {
				$upgrades[ $version ] = $callback;
			}
		}

		// Return.
		return $upgrades;
	}

	/**
	 * Upgrade to a specific database version.
	 *
	 * @since 1.0.0
	 *
	 * @param string $version  Database version to upgrade to.
	 * @param string $callback Callback function or class method to call.
	 *
	 * @return bool
	 */
	public function upgrade_to( $version = '', $callback = '' ) {

		// Bail if no upgrade is needed.
		if ( ! $this->needs_upgrade( $version ) ) {
			return false;
		}

		// Allow self-named upgrade callbacks.
		if ( empty( $callback ) ) {
			$callback = $version;
		}

		// Is the callback... callable?
		$callable = $this->get_callable( $callback );

		// Bail if no callable upgrade was found.
		if ( empty( $callable ) ) {
			return false;
		}

		// Do the upgrade.
		$result  = call_user_func( $callable );
		$success = $this->is_success( $result );

		// Bail if upgrade failed.
		if ( true !== $success ) {
			return false;
		}

		// Set the database version to this successful version.
		$this->set_db_version( $version );

		// Return success.
		return true;
	}

	/** Private ***************************************************************/

	/**
	 * Setup the necessary table variables.
	 *
	 * @since 1.0.0
	 */
	private function setup(): void {

		// Bail if no database interface is available.
		if ( ! $this->get_db() ) {
			return;
		}

		// Sanitize this database table name.
		$this->name = $this->sanitize_table_name( $this->name );

		// Bail if database table name sanitization failed.
		if ( false === $this->name ) {
			return;
		}

		// Separator.
		$glue = '_';

		// Setup the prefixed name.
		$this->prefixed_name = $this->apply_prefix( $this->name, $glue );

		// Maybe create database key.
		if ( empty( $this->db_version_key ) ) {
			$this->db_version_key = implode(
				$glue,
				array(
					sanitize_key( $this->db_global ),
					$this->prefixed_name,
					'version',
				)
			);
		}
	}

	/**
	 * Set this table up in the database interface.
	 *
	 * This must be done directly because the database interface does not
	 * have a common mechanism for manipulating them safely.
	 *
	 * @since 1.0.0
	 */
	private function set_db_interface(): void {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return;
		}

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
		$this->table_prefix = $db->get_blog_prefix( $site_id );

		// Get the prefixed table name.
		$prefixed_table_name = "{$this->table_prefix}{$this->prefixed_name}";

		// Set the database interface.
		$db->{$this->prefixed_name} = $this->table_name = $prefixed_table_name;

		// Create the array if it does not exist.
		if ( ! isset( $db->{$tables} ) ) {
			$db->{$tables} = array();
		}

		// Add table to the global table array.
		$db->{$tables}[] = $this->prefixed_name;

		// Charset.
		if ( ! empty( $db->charset ) ) {
			$this->charset_collation = "DEFAULT CHARACTER SET {$db->charset}";
		}

		// Collation.
		if ( ! empty( $db->collate ) ) {
			$this->charset_collation .= " COLLATE {$db->collate}";
		}
	}

	/**
	 * Set table version in the database.
	 *
	 * @since 1.0.0
	 *
	 * @param string $version Database version to set when upgrading/creating.
	 */
	private function set_db_version( $version = '' ): void {

		// If no version is passed during an upgrade, use the current version.
		if ( empty( $version ) ) {
			$version = $this->version;
		}

		// Update the DB version.
		$this->is_global()
			? update_network_option( get_main_network_id(), $this->db_version_key, $version )
			: update_option( $this->db_version_key, $version );

		// Set the DB version.
		$this->db_version = $version;
	}

	/**
	 * Get table version from the database.
	 *
	 * @since 1.0.0
	 */
	private function get_db_version(): void {
		$this->db_version = $this->is_global()
			? get_network_option( get_main_network_id(), $this->db_version_key, '' )
			: get_option( $this->db_version_key, '' );
	}

	/**
	 * Delete table version from the database.
	 *
	 * @since 1.0.0
	 */
	private function delete_db_version(): void {
		$this->is_global()
			? delete_network_option( get_main_network_id(), $this->db_version_key )
			: delete_option( $this->db_version_key );

		$this->db_version = '';
	}

	/**
	 * Lock upgrades.
	 *
	 * Prevents multiple upgrade processes from running simultaneously on the
	 * same table. Uses a transient with a 15-minute expiration to ensure the
	 * lock is automatically released even if the upgrade process fails.
	 *
	 * @since 3.0.0
	 *
	 * @return bool True if the lock was created, false if a lock already exists.
	 */
	private function lock_upgrades() {

		// Generate a unique lock key for this table.
		$lock_key = $this->db_version_key . '_upgrade_lock';

		// Check if a lock already exists.
		$lock_exists = $this->is_global()
			? get_site_transient( $lock_key )
			: get_transient( $lock_key );

		// If a lock already exists, return false.
		if ( false !== $lock_exists ) {
			return false;
		}

		// Create the lock transient.
		$lock_set = $this->is_global()
			? set_site_transient( $lock_key, time(), 900 )
			: set_transient( $lock_key, time(), 900 );

		// Return whether the lock was successfully created.
		return (bool) $lock_set;
	}

	/**
	 * Unlock upgrades.
	 *
	 * Removes the transient that was set by lock_upgrades(), allowing other
	 * upgrade processes to proceed.
	 *
	 * @since 3.0.0
	 *
	 * @return bool True if the lock was released, false otherwise.
	 */
	private function unlock_upgrades() {

		// Generate the same lock key used in lock_upgrades().
		$lock_key = $this->db_version_key . '_upgrade_lock';

		// Delete the lock transient.
		$deleted = $this->is_global()
			? delete_site_transient( $lock_key )
			: delete_transient( $lock_key );

		// Return whether the lock was successfully released.
		return (bool) $deleted;
	}

	/**
	 * Setup the Schema object.
	 *
	 * @since 3.0.0
	 */
	private function set_schema(): void {

		// Bail if no table schema.
		if ( empty( $this->schema ) || ! class_exists( $this->schema ) ) {
			return;
		}

		// Invoke a new table schema class.
		$this->schema_object = new $this->schema();
	}

	/**
	 * Add class hooks to the parent application actions.
	 *
	 * @since 1.0.0
	 */
	private function add_hooks(): void {

		// Add table to the global database object.
		add_action( 'switch_blog', array( $this, 'switch_blog' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * Check if table is global.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_global() {
		return $this->global;
	}

	/**
	 * Try to get a callable upgrade, with some magic to avoid needing to
	 * do this dance repeatedly inside subclasses.
	 *
	 * @since 1.0.0
	 *
	 * @param string $callback
	 *
	 * @return array<int, mixed>|string|false Resolved callable, or false if not callable.
	 */
	private function get_callable( $callback = '' ) {

		// Default return value.
		$callable = $callback;

		// Look for global function.
		if ( ! is_callable( $callable ) ) {

			// Fallback to local class method.
			$callable = array( $this, $callback );
			if ( ! is_callable( $callable ) ) {

				// Fallback to class method prefixed with "__".
				$callable = array( $this, "__{$callback}" );
				if ( ! is_callable( $callable ) ) {
					$callable = false;
				}
			}
		}

		// Return callable string, or false if not callable.
		return $callable;
	}
}
