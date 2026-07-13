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

use BerlinDB\Database\Interfaces\Installable;

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
class Table implements Installable {

	/**
	 * Use these traits.
	 *
	 * @since 3.0.0
	 */
	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;
	use \BerlinDB\Database\Traits\Storage\Registration;
	use \BerlinDB\Database\Traits\Storage\Versioning;
	use \BerlinDB\Database\Traits\Storage\Installation;
	use \BerlinDB\Database\Traits\Storage\Multisite;
	use \BerlinDB\Database\Traits\Storage\Hooks;
	use \BerlinDB\Database\Traits\Storage\Table\Alter;
	use \BerlinDB\Database\Traits\Storage\Table\Reconciliation;
	use \BerlinDB\Database\Traits\Storage\Table\Introspection;
	use \BerlinDB\Database\Traits\Storage\Table\Maintenance;
	use \BerlinDB\Database\Traits\Storage\Table\Temporary;

	/** Constants *************************************************************/

	/**
	 * Storage engines recognized by MySQL / MariaDB in WordPress environments.
	 *
	 * Used by sanitize_engine() and engine() to validate values before they
	 * are interpolated into SQL strings.
	 *
	 * @since 3.1.0
	 * @var   array<int,string>
	 */
	private const ENGINES = array( 'INNODB', 'MYISAM', 'MEMORY', 'ARCHIVE', 'CSV', 'BLACKHOLE', 'MERGE', 'ARIA' );

	/**
	 * Row formats recognized by MySQL / MariaDB.
	 *
	 * Used by sanitize_row_format() to validate values before they are
	 * interpolated into SQL strings.
	 *
	 * @since 3.1.0
	 * @var   array<int,string>
	 */
	private const ROW_FORMATS = array( 'DEFAULT', 'DYNAMIC', 'FIXED', 'COMPACT', 'COMPRESSED', 'REDUNDANT' );

	/** Attributes ************************************************************/

	/*
	 * The relation identity ($name, $prefixed_name, $table_name, $table_prefix)
	 * and its registration live in the Traits\Storage\Registration trait (#237).
	 */

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
	 * Declare it as a class property in your subclass - it is read during
	 * construction before init() runs, so it is always available when
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

	/*
	 * The version identity ($version, $db_version_key, $db_version) and its
	 * accessors live in the Traits\Storage\Versioning trait (#237).
	 */

	/*
	 * Multisite state ($global) and switch_blog()/is_global() live in the
	 * Traits\Storage\Multisite trait (#237).
	 */

	/**
	 * Schema class name or Schema object used to configure columns and indexes.
	 *
	 * Accepts either a fully-qualified class name string (the classic subclass
	 * pattern) or a Schema instance built at runtime - e.g. from a constructor
	 * argument or a Schema::from_table() call.
	 *
	 * @since 1.0.0
	 * @var   class-string<Schema>|Schema|''
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
	 * Storage engine for this table (e.g. 'InnoDB', 'MyISAM', 'MEMORY').
	 *
	 * Leave empty to use the server's default_storage_engine (typically
	 * InnoDB on modern MySQL / MariaDB).
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	protected $engine = '';

	/**
	 * InnoDB row format (e.g. 'DYNAMIC', 'COMPRESSED', 'COMPACT').
	 *
	 * Leave empty to use the engine default. DYNAMIC is the modern default
	 * for InnoDB (MySQL 5.7+ / MariaDB 10.2+) and handles large TEXT/BLOB
	 * columns without hitting the 8126-byte row-size limit.
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	protected $row_format = '';

	/**
	 * Starting AUTO_INCREMENT value written into CREATE TABLE.
	 *
	 * Useful for reserving low IDs for fixture or seed data. 0 or 1 defers
	 * to the engine default (next available value, effectively 1 on a new
	 * table). Use auto_increment() to reseed an existing table at runtime.
	 *
	 * @since 3.1.0
	 * @var   int
	 */
	protected $auto_increment = 0;

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
	 * @var   array<string,callable|string>
	 */
	protected $upgrades = array();

	/**
	 * When enforced foreign keys are emitted.
	 *
	 * BerlinDB installs tables independently and in no guaranteed order, so a real
	 * FOREIGN KEY inside CREATE TABLE would reference a table that may not exist yet.
	 * Enforced keys (relationship enforce => true) are therefore DEFERRED by default:
	 * create() builds a bare table, and the keys are added afterward - once every
	 * referenced table exists - with add_foreign_keys().
	 *
	 * Set to 'inline' only when you control install order (referenced tables created
	 * first, no cycles) and want the constraint emitted inside CREATE TABLE.
	 *
	 * Accepts 'deferred' (default) or 'inline'.
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	protected $foreign_keys = 'deferred';

	/*
	 * $auto_install and add_hooks() live in the Traits\Storage\Hooks trait (#237);
	 * should_auto_install() is overridden below to exclude temporary tables.
	 */

	/**
	 * Instantiated schema object, populated by set_schema() during boot.
	 *
	 * @since 3.0.0
	 * @var   Schema|null|object
	 */
	private $schema_object = null;

	/*
	 * Table-only storage concerns live in the Traits\Storage\Table\* traits (as
	 * opposed to the shared Traits\Storage\* traits every relation composes): the
	 * ALTER verbs and their $grammar in Table\Alter; the schema-reconciliation surface
	 * (diff/diverged/snapshot/reconcile) and $reconcile opt-in in Table\Reconciliation;
	 * the read-only introspection surface (status/get_create_sql/columns/indexes/count/
	 * column_exists/index_exists) in Table\Introspection; the maintenance verbs
	 * (truncate/delete_all/duplicate/copy/rename/analyze/check/checksum/optimize/repair)
	 * in Table\Maintenance; and the TEMPORARY-table mode ($temporary/is_temporary) in
	 * Table\Temporary. The create()/drop()/exists() DDL and the persists_relation_
	 * version()/should_auto_install() hook overrides below read $temporary but stay
	 * here, at the seam where the mode meets the shared lifecycle (#237).
	 */

	/**
	 * Called after initialization.
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {

		// Establish the table's identity from its name.
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

		// Build the table's charset/collation clause (table-specific, needs the connection).
		$this->set_charset_collation();

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
	 * Sanitization callbacks for a Table's configuration arguments.
	 *
	 * Applied by validate_args() (Traits\Configuration) during construction.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed> Map of config key => sanitization callback.
	 */
	protected function get_config_callbacks(): array {
		return array(

			// Name & Description.
			'name'              => array( $this, 'sanitize_table_name' ),
			'prefixed_name'     => array( $this, 'sanitize_table_name' ),
			'table_name'        => array( $this, 'sanitize_table_name' ),
			'table_prefix'      => array( $this, 'sanitize_table_name' ),

			// Schema & Columns.
			'schema'            => '',

			// SQL attributes.
			'description'       => 'wp_kses_data',
			'charset_collation' => 'wp_kses_data',
			'engine'            => array( $this, 'sanitize_engine' ),
			'row_format'        => array( $this, 'sanitize_row_format' ),
			'auto_increment'    => array( $this, 'sanitize_absint' ),
			'comment'           => array( $this, 'sanitize_table_comment' ),

			// Multisite.
			'global'            => array( $this, 'sanitize_boolean' ),

			// Version.
			'version'           => 'wp_kses_data',
			'db_version_key'    => 'wp_kses_data',
			'db_version'        => 'wp_kses_data',

			// Upgrades.
			'upgrades'          => '',
			'reconcile'         => '',
			'foreign_keys'      => '',
			'auto_install'      => array( $this, 'sanitize_boolean' ),
			'temporary'         => array( $this, 'sanitize_boolean' ),
		);
	}

	/** Argument Sanitization *************************************************/

	/**
	 * Sanitize a storage engine name.
	 *
	 * Returns the uppercase engine name when it is in the set of engines
	 * known to be available in MySQL / MariaDB installations used alongside
	 * WordPress, or an empty string for unrecognized values.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	private function sanitize_engine( $v ): string {
		$upper = strtoupper( (string) $v );

		return in_array( $upper, self::ENGINES, true )
			? $upper
			: '';
	}

	/**
	 * Sanitize a row format name.
	 *
	 * Returns the uppercase format name when it is in the set of formats
	 * recognized by MySQL / MariaDB, or an empty string for unrecognized values.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	private function sanitize_row_format( $v ): string {
		$upper = strtoupper( (string) $v );

		return in_array( $upper, self::ROW_FORMATS, true )
			? $upper
			: '';
	}

	/**
	 * Sanitize the table comment, capped at the MySQL 2048-character limit.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	private function sanitize_table_comment( $v ): string {
		return $this->sanitize_comment( (string) $v, 2048 );
	}

	/** Public Helpers ********************************************************/

	/*
	 * The public helper surface lives in composed traits: the install/upgrade/
	 * uninstall lifecycle (install, uninstall, maybe_upgrade, needs_upgrade,
	 * is_upgradeable, lock/unlock, tombstone, get_callable) in Traits\Storage\
	 * Installation, driving this class's create()/drop()/exists()/upgrade() through
	 * the Interfaces\Installable contract; is_temporary() and the $temporary flag in
	 * Traits\Storage\Table\Temporary (#237).
	 */

	/** Public Management *****************************************************/

	/**
	 * Check if table exists.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function exists() {

		/*
		 * A TEMPORARY table is NOT listed by SHOW TABLES, so probe it directly:
		 * a LIMIT 0 read succeeds when the session table exists and errors when it
		 * does not. Errors are suppressed so a missing table is a clean false, not
		 * a logged warning.
		 */
		if ( $this->temporary ) {
			$suppress = $this->db()->suppress_errors( true );
			$result   = $this->db()->query( "SELECT 1 FROM {$this->table_name} LIMIT 0" );
			$this->db()->suppress_errors( $suppress );

			return ( false !== $result );
		}

		// Query statement.
		$sql      = 'SHOW TABLES LIKE %s';
		$like     = $this->db()->esc_like( $this->table_name );
		$prepared = $this->db()->prepare( $sql, $like );
		$result   = $this->db()->get_var( $prepared );

		// Does the table exist?
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

		// Bail if no schema to call.
		if ( ! is_callable( array( $this->schema_object, 'get_create_table_string' ) ) ) {
			return false;
		}

		/*
		 * Get the "CREATE TABLE" string. Foreign keys are deferred to
		 * add_foreign_keys() by default; emit them inline only when opted in.
		 */
		$inline_foreign_keys = ( 'inline' === $this->foreign_keys );
		$create_table_string = $this->schema_object->get_create_table_string( $inline_foreign_keys );

		// Bail if no create string.
		if ( empty( $create_table_string ) ) {
			return false;
		}

		// Required parts (TEMPORARY when this is a session-scoped table).
		$sql = array(
			$this->temporary
				? 'CREATE TEMPORARY TABLE'
				: 'CREATE TABLE',
			$this->table_name,
			"( {$create_table_string} )",
		);

		// Storage engine (omit on a platform that has none, e.g. SQLite).
		if ( ! empty( $this->engine ) && $this->platform()->has_storage_engines() ) {
			$sql[] = 'ENGINE=' . $this->engine;
		}

		// Character set & collation (may be empty; array_filter handles it).
		$sql[] = $this->charset_collation;

		// Row format.
		if ( ! empty( $this->row_format ) ) {
			$sql[] = 'ROW_FORMAT=' . $this->row_format;
		}

		// Starting AUTO_INCREMENT value (skip 0 and 1 - both are engine defaults).
		if ( $this->auto_increment > 1 ) {
			$sql[] = 'AUTO_INCREMENT=' . $this->auto_increment;
		}

		// Comment.
		if ( ! empty( $this->comment ) ) {
			$sql[] = "COMMENT='" . addslashes( $this->comment ) . "'";
		}

		// Query statement.
		$query  = implode( ' ', array_filter( $sql ) );
		$result = $this->db()->query( $query );

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

		/*
		 * Query statement (TEMPORARY targets only the session table, never a
		 * permanent table of the same name).
		 */
		$keyword = $this->temporary
			? 'DROP TEMPORARY TABLE'
			: 'DROP TABLE';
		$sql     = "{$keyword} {$this->table_name}";
		$result  = $this->db()->query( $sql );

		// Did the table get dropped?
		return $this->is_success( $result );
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

		// No bespoke callback for the pending version(s).
		if ( empty( $upgrades ) ) {

			/*
			 * Opt-in: reconcile structural drift to the declared schema before
			 * recording the version. This fills the "bumped the version with no
			 * upgrade callback" gap declaratively.
			 *
			 * A DEFERRED result (incomplete capture) does not advance the version -
			 * the next maybe_upgrade() retries against a fresh capture. A FAILED
			 * result (an ALTER errored) is logged and the version advances anyway:
			 * re-running a persistently failing ALTER on every request would be a
			 * worse outcome than recording the failure and moving on.
			 */
			if ( $this->wants_reconcile() ) {
				$reconcile = $this->reconcile( $this->get_reconcile_operations() );

				if ( $reconcile->is_deferred() ) {
					return false;
				}

				if ( $reconcile->is_failed() ) {
					$this->log( 'error', 'reconcile', "Reconcile failed for {$this->table_name}: {$reconcile->error()}" );
				}
			}

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
	 * @return array<string,mixed> Array of upgrade callbacks, keyed by their db version.
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
	 * Build the table's DEFAULT CHARACTER SET / COLLATE clause from the connection.
	 *
	 * Table-specific: a view has no charset. Runs in init() right after the shared
	 * Registration trait registers the relation (Traits\Storage\Registration).
	 *
	 * @since 1.0.0
	 */
	private function set_charset_collation(): void {

		// Charset.
		$charset = $this->db()->get_charset();
		if ( ! empty( $charset ) ) {
			$this->charset_collation = "DEFAULT CHARACTER SET {$charset}";
		}

		// Collation.
		$collation = $this->db()->get_collation();
		if ( ! empty( $collation ) ) {
			$this->charset_collation .= " COLLATE {$collation}";
		}
	}

	/**
	 * A temporary table does not persist a version (Storage\Versioning hook).
	 *
	 * A stored version would outlive the session-scoped table and mislead
	 * maybe_upgrade() into treating a vanished table as installed, so
	 * set_db_version() no-ops for it.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	protected function persists_relation_version(): bool {
		return ! $this->temporary;
	}

	/**
	 * Setup the Schema object.
	 *
	 * @since 3.0.0
	 */
	private function set_schema(): void {

		// Accept a Schema object passed directly via constructor or property assignment.
		if ( $this->schema instanceof Schema ) {
			$this->schema_object = $this->schema;
			return;
		}

		// Default log context.
		$log_error = true;
		$context   = array(
			'schema' => $this->schema,
		);

		// Maybe invoke a new table schema class (instances were returned above).
		if ( is_string( $this->schema ) && ! empty( $this->schema ) ) {
			try {
				$this->schema_object = $this->instantiate_class( $this->schema );
				$log_error           = ( null === $this->schema_object );
			} catch ( \Throwable $exception ) {
				$context['exception']         = get_class( $exception );
				$context['exception_message'] = $exception->getMessage();
			}
		}

		// A schema without get_create_table_string() is not usable by Table.
		if ( ( false === $log_error ) && ! is_callable( array( $this->schema_object, 'get_create_table_string' ) ) ) {
			$log_error         = true;
			$context['method'] = 'get_create_table_string';
		}

		// Maybe log schema setup failure.
		if ( true === $log_error ) {
			$this->log( 'error', 'table_schema_unavailable', 'Table schema could not be loaded.', $context );
		}
	}

	/**
	 * A temporary table cannot auto-install (Storage\Hooks hook).
	 *
	 * It would be created in the admin_init request's session and vanish
	 * immediately, so it is excluded from the admin_init auto-install hook -
	 * create it on demand within the session that uses it instead.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	protected function should_auto_install(): bool {
		return $this->auto_install && ! $this->temporary;
	}
}
