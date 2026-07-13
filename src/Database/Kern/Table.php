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

use BerlinDB\Database\Diff\Grammar;
use BerlinDB\Database\Diff\Patch;
use BerlinDB\Database\Diff\Result;
use BerlinDB\Database\Diff\Snapshot;
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
	 * Which operations upgrade() may reconcile against the declared schema.
	 *
	 * When a version bump runs upgrade() and there is no bespoke $upgrades callback
	 * for the pending version, this diffs the live table against the declared schema
	 * and applies the difference (see reconcile()), so a developer can evolve the
	 * schema by editing it rather than hand-writing ALTERs. The two compose across
	 * upgrade cycles: any bespoke callbacks run first (data migrations), then a later
	 * cycle reconciles the remaining structural drift.
	 *
	 * Defaults to ADDITIVE-ONLY, ON: a bumped version with no callback adds the
	 * columns/indexes the schema gained, which is non-destructive and is what a
	 * developer editing the schema almost always intends. The changes that can lose
	 * data are strictly opt-in.
	 *
	 * Accepts:
	 *  - array( 'add' ) - additive only, on (the default). Adds; never modifies or
	 *                     drops.
	 *  - true           - the moderate policy ('add', 'modify'). MODIFY COLUMN can
	 *                     truncate data on a type-narrowing, so it is opt-in.
	 *  - string[]       - an explicit operations list, e.g. array( 'add', 'modify',
	 *                     'drop' ) to also drop what the schema removed. Drops are
	 *                     never included unless named here.
	 *  - false / array() - off; reconcile does not run (bespoke $upgrades callbacks
	 *                     and the plain version bump still work as before).
	 *
	 * A reconcile only runs against a COMPLETE introspection (see Snapshot); an
	 * incomplete capture defers to the next maybe_upgrade(), and a failed ALTER is
	 * logged and the version advanced past (never re-run every request). A clean
	 * reconcile means every SUPPORTED change applied, not that the table is
	 * byte-identical to the declaration (the diff intentionally ignores defaults,
	 * charset/collation, comments, and the like).
	 *
	 * @since 3.1.0
	 * @var   bool|string[]
	 */
	protected $reconcile = array( 'add' );

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
	 * Instantiated schema object, populated by set_schema() during boot.
	 *
	 * @since 3.0.0
	 * @var   Schema|null|object
	 */
	private $schema_object = null;

	/**
	 * SQL grammar for schema-change statements, created lazily by grammar().
	 *
	 * @since 3.1.0
	 * @var   Grammar|null
	 */
	private $grammar = null;

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
	 * The install/upgrade/uninstall lifecycle (install, uninstall, maybe_upgrade,
	 * needs_upgrade, is_upgradeable, lock/unlock, tombstone, get_callable) lives in
	 * the Traits\Storage\Installation trait, driving this class's create()/drop()/
	 * exists()/upgrade() through the Interfaces\Installable contract (#237).
	 */

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

		// Query statement - SHOW TABLE STATUS LIKE exact_name returns at most one row.
		$sql      = 'SHOW TABLE STATUS LIKE %s';
		$like     = $this->db()->esc_like( $this->table_name );
		$prepared = $this->db()->prepare( $sql, $like );
		$result   = $this->db()->get_row( $prepared );

		// Does the table exist?
		return is_object( $result )
			? $result
			: false;
	}

	/**
	 * Return the CREATE TABLE SQL for this table.
	 *
	 * Runs SHOW CREATE TABLE and returns the SQL string from the result.
	 * Useful for debugging schema drift and as input for rollback tooling.
	 * Returns false if the table does not exist or the query fails.
	 *
	 * @since 3.1.0
	 *
	 * @return string|false
	 */
	public function get_create_sql(): string|false {

		// Query statement - SHOW CREATE TABLE always returns exactly one row.
		$sql    = "SHOW CREATE TABLE {$this->table_name}";
		$result = $this->db()->get_row( $sql );

		// Return the CREATE TABLE definition, or false on failure.
		return ( is_object( $result ) && isset( $result->{'Create Table'} ) ) ? $result->{'Create Table'} : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Get columns from table.
	 *
	 * @since 1.2.0
	 *
	 * @return list<mixed>|false Array of column rows on success, false on failure.
	 */
	public function columns() {

		// Query statement.
		$sql    = "SHOW FULL COLUMNS FROM {$this->table_name}";
		$result = $this->db()->get_results( $sql );

		// Return the results.
		return ( $this->is_success( $result ) && is_array( $result ) )
			? array_values( $result )
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

		// Query statement.
		$sql    = "SHOW INDEXES FROM {$this->table_name}";
		$result = $this->db()->get_results( $sql );

		// Return the results.
		return ( $this->is_success( $result ) && is_array( $result ) )
			? array_values( $result )
			: false;
	}

	/**
	 * Compare the live table to its declared schema, returning the Patch.
	 *
	 * Introspects the current table structure (Schema::from_table) and diffs it
	 * against this table's declared schema. The returned Patch describes the
	 * changes needed to bring the live table up to the declared schema - columns
	 * and indexes to add, drop, or modify. Returns an empty Patch when there is no
	 * usable declared schema to compare against.
	 *
	 * Caveats:
	 *  - The introspected "actual" side may be INCOMPLETE (skipped unrepresentable
	 *    indexes, or a transient SHOW INDEX failure - see Schema::from_table()), so
	 *    a reported "added" index may already exist. diff() does not surface that;
	 *    snapshot() does (is_complete()), and reconcile() gates on it. Do not use a
	 *    bare diff() to authorize drops without confirming the capture was complete.
	 *  - Each call runs the introspection queries afresh; diverged() calls diff(),
	 *    so checking both re-introspects. Cache the Patch if you need it twice.
	 *
	 * The returned Patch is bound to this table, so its apply() / to_sql() can run
	 * (or render) the reconciling ALTERs. Apply is additive-and-modify by default;
	 * drops are opt-in (see Patch::apply()), which keeps an incomplete introspection
	 * from authorizing a destructive change.
	 *
	 * @since 3.1.0
	 *
	 * @return Patch
	 */
	public function diff(): Patch {

		// Actual (live) -> desired (declared): the migration direction.
		return $this->patch_against( Schema::from_table( $this->table_name ) );
	}

	/**
	 * Whether the live table differs from its declared schema.
	 *
	 * Sugar over diff(): true when the table needs changes to match the schema.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function diverged(): bool {
		return ! $this->diff()->is_empty();
	}

	/**
	 * Capture this live table's structure with its introspection-completeness signal.
	 *
	 * Sugar over Schema::snapshot() for this table. Unlike diff(), the Snapshot says
	 * whether the introspection was trustworthy (exists(), indexes_complete(),
	 * is_complete()) - which is what reconcile() gates on before acting.
	 *
	 * @since 3.1.0
	 *
	 * @return Snapshot
	 */
	public function snapshot(): Snapshot {
		return Schema::snapshot( $this->table_name );
	}

	/**
	 * Reconcile this live table to its declared schema by applying the difference.
	 *
	 * The declarative counterpart to a hand-written upgrade callback: it diffs the
	 * live table against the declared schema and runs the resulting ALTERs. Completes
	 * the diff() -> diverged() -> reconcile() lexicon.
	 *
	 * Safety:
	 *  - Captures a Snapshot first and DEFERS (returns false, changes nothing) if the
	 *    introspection is not complete - acting on a partial picture produces failed
	 *    ALTERs that never converge. Retry later against a complete capture.
	 *  - Additive by default ('add', 'modify'); drops only happen if named in
	 *    $operations. A no-op (already in sync) is a success.
	 *
	 * This applies only the changes the diff engine SUPPORTS - it does not reconcile
	 * column defaults, charset/collation, comments, scale, or ENUM/SET value lists
	 * (those are intentionally outside the diff), so a true return means "every
	 * supported change applied", not "table is byte-identical to the declaration".
	 *
	 * @since 3.1.0
	 *
	 * @param string[] $operations Operations to apply: 'add', 'modify', 'drop'.
	 *                             Defaults to the safe 'add' + 'modify'.
	 *
	 * @return Result Applied if the table is in sync afterward (or nothing to do);
	 *               deferred if the capture was incomplete (retry later); failed if
	 *               there is no declared schema or an ALTER failed.
	 */
	public function reconcile( array $operations = array( 'add', 'modify' ) ): Result {

		// Nothing to reconcile against without a real declared schema.
		if ( ! ( $this->schema_object instanceof Schema ) ) {
			return Result::failed( 'No declared schema to reconcile against.' );
		}

		// No operations means nothing to do - a no-op, and skip the introspection.
		if ( empty( $operations ) ) {
			return Result::applied( 0 );
		}

		// Capture the live table once, with its completeness signal.
		$snapshot = $this->snapshot();

		// Defer on an untrustworthy capture - never reconcile against a partial picture.
		if ( ! $snapshot->is_complete() ) {
			return Result::deferred();
		}

		// Diff the (complete) actual against the declared schema, then apply.
		return $this->patch_against( $snapshot->schema() )->apply( $operations );
	}

	/**
	 * Build a table-bound Patch diffing a captured "actual" schema against declared.
	 *
	 * Shared by diff() (actual from from_table()) and reconcile() (actual from a
	 * complete snapshot). Returns an empty bound Patch when there is no declared
	 * schema to compare against.
	 *
	 * @since 3.1.0
	 *
	 * @param Schema $actual The introspected (live) schema.
	 *
	 * @return Patch
	 */
	private function patch_against( Schema $actual ): Patch {

		// Nothing to compare against without a real declared schema.
		if ( ! ( $this->schema_object instanceof Schema ) ) {
			return ( new Patch() )->set_table( $this );
		}

		return $actual->diff( $this->schema_object )->set_table( $this );
	}

	/**
	 * Whether upgrade() should reconcile structural drift (the $reconcile opt-in).
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	private function wants_reconcile(): bool {
		return ( true === $this->reconcile )
			|| ( is_array( $this->reconcile ) && ! empty( $this->reconcile ) );
	}

	/**
	 * Resolve the $reconcile opt-in to a concrete operations list.
	 *
	 * An explicit array is used as-is; the bare `true` opt-in uses the safe default.
	 *
	 * @since 3.1.0
	 *
	 * @return string[]
	 */
	private function get_reconcile_operations(): array {
		return is_array( $this->reconcile )
			? $this->reconcile
			: array( 'add', 'modify' );
	}

	/**
	 * Add an index to this database table.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed>|Index $args Index arguments or an Index object.
	 *
	 * @return bool
	 */
	public function add_index( $args = array() ) {

		// Create index object from arguments.
		$index = ( $args instanceof Index )
			? $args
			: new Index( $args );

		// Build the SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->add_index( $this->table_name, $index );

		// Bail if no valid SQL was generated.
		if ( empty( $sql ) ) {
			return false;
		}

		// Was the index added?
		return $this->is_success( $this->db()->query( $sql ) );
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

		// Sanitize the index name.
		$name = $this->sanitize_column_name( $name );

		// Bail if index name is invalid.
		if ( empty( $name ) ) {
			return false;
		}

		// Build the SQL through the grammar (handles DROP PRIMARY KEY).
		$sql = $this->grammar()->drop_index( $this->table_name, $name );

		// Was the index dropped?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Replace an index in place: drop $from and add $to in one atomic ALTER.
	 *
	 * A modified index (same identity, different definition) cannot be reconciled as
	 * a separate drop then add when it is the PRIMARY KEY over an AUTO_INCREMENT
	 * column - MySQL rejects the standalone DROP PRIMARY KEY. Combining both into one
	 * statement never leaves the column unindexed, so it works for the primary key
	 * and any other index.
	 *
	 * @since 3.1.0
	 *
	 * @param Index $from The index to drop.
	 * @param Index $to   The index to add in its place.
	 *
	 * @return bool
	 */
	public function replace_index( Index $from, Index $to ) {

		// Build the combined SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->replace_index( $this->table_name, $from, $to );

		// Bail if no valid SQL was generated.
		if ( empty( $sql ) ) {
			return false;
		}

		// Was the index replaced?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Add this schema's enforced foreign keys to the table, via ALTER TABLE.
	 *
	 * The deferred counterpart to emitting foreign keys inside CREATE TABLE (see
	 * Schema::get_create_table_string()): use this when the referenced tables were
	 * not guaranteed to exist at create time, or for two tables that reference each
	 * other - create both first, then add the keys. Only enforced (enforce => true)
	 * relationships emit anything; a schema with none is a no-op success. Each key
	 * is added independently; a referenced table that still does not exist fails
	 * that one key (MySQL rejects it) without affecting the others.
	 *
	 * @since 3.1.0
	 *
	 * @return bool True if every enforced key was added (or there were none), false
	 *              if any ADD failed or any enforced key could not be resolved.
	 */
	public function add_foreign_keys(): bool {

		// Nothing to add without a real declared schema.
		if ( ! ( $this->schema_object instanceof Schema ) ) {
			return false;
		}

		$success = true;

		/*
		 * An enforced key whose remote table cannot be resolved (its Table is not
		 * registered) is a failure, not a silent skip - report it and mark unsuccess.
		 */
		foreach ( $this->schema_object->get_unresolved_foreign_keys() as $remote_class ) {
			$this->log( 'warning', 'foreign_key', "Enforced foreign key to {$remote_class} not added to {$this->table_name}: remote table not registered." );
			$success = false;
		}

		// Add each resolved foreign key independently.
		foreach ( $this->schema_object->get_foreign_key_strings() as $fragment ) {
			$sql = $this->grammar()->add_foreign_key( $this->table_name, $fragment );

			if ( ( '' !== $sql ) && ! $this->is_success( $this->db()->query( $sql ) ) ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Add a column to this database table.
	 *
	 * Mirrors add_index(). The create string carries the column name, type, and
	 * all of its attributes, so it slots straight into ADD COLUMN.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed>|Column $args Column arguments or a Column object.
	 *
	 * @return bool
	 */
	public function add_column( $args = array() ) {

		// Create column object from arguments.
		$column = ( $args instanceof Column )
			? $args
			: new Column( $args );

		// Build the SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->add_column( $this->table_name, $column );

		// Bail if no valid SQL was generated.
		if ( empty( $sql ) ) {
			return false;
		}

		// Was the column added?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Modify an existing column on this database table in place.
	 *
	 * Runs ALTER TABLE ... MODIFY COLUMN, which keeps the column name (unlike
	 * CHANGE COLUMN) and redefines its type and attributes from the create string.
	 * Narrowing a type can truncate stored data - the caller decides when that is
	 * acceptable (e.g. the 'modify' operation of a Patch).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed>|Column $args Column arguments or a Column object.
	 *
	 * @return bool
	 */
	public function modify_column( $args = array() ) {

		// Create column object from arguments.
		$column = ( $args instanceof Column )
			? $args
			: new Column( $args );

		// Build the SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->modify_column( $this->table_name, $column );

		// Bail if no valid SQL was generated.
		if ( empty( $sql ) ) {
			return false;
		}

		// Was the column modified?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Drop a column from this database table.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name Column name.
	 *
	 * @return bool
	 */
	public function drop_column( $name = '' ) {

		// Sanitize the column name.
		$name = $this->sanitize_column_name( $name );

		// Bail if column name is invalid.
		if ( empty( $name ) ) {
			return false;
		}

		// Build the SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->drop_column( $this->table_name, $name );

		// Was the column dropped?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Return the SQL grammar used to render this table's schema-change statements.
	 *
	 * The single place ALTER syntax is built; the DDL verbs above and any Patch
	 * from diff() render through it, so preview and execution never drift. Speaks
	 * MySQL / MariaDB today - the seam where a future engine would swap in.
	 *
	 * @since 3.1.0
	 *
	 * @return Grammar
	 */
	public function grammar(): Grammar {

		// Lazily create the grammar once.
		if ( ! ( $this->grammar instanceof Grammar ) ) {
			$this->grammar = new Grammar();
		}

		return $this->grammar;
	}

	/**
	 * Set the AUTO_INCREMENT counter for this table.
	 *
	 * Use this to seed the counter at a specific value - for example, to
	 * leave low IDs available for fixture or seed data, or to reseed after
	 * a TRUNCATE. Has no effect if the table has no AUTO_INCREMENT column.
	 *
	 * @since 3.1.0
	 *
	 * @param int $value The next AUTO_INCREMENT value to assign. Must be >= 1.
	 * @return bool
	 */
	public function auto_increment( int $value ): bool {

		// Bail if the value is not positive.
		if ( $value < 1 ) {
			return false;
		}

		// Query statement.
		$sql    = "ALTER TABLE {$this->table_name} AUTO_INCREMENT={$value}";
		$result = $this->db()->query( $sql );

		// Was the counter updated?
		return $this->is_success( $result );
	}

	/**
	 * Convert the storage engine for this table.
	 *
	 * Runs ALTER TABLE ... ENGINE=X. Returns false immediately for engine names
	 * that are not in the recognized set, without issuing a query.
	 *
	 * @since 3.1.0
	 *
	 * @param string $engine Target storage engine (e.g. 'InnoDB', 'MyISAM').
	 * @return bool
	 */
	public function engine( string $engine ): bool {

		/*
		 * Bail on a platform with no storage engines (e.g. SQLite); ENGINE= is a
		 * MySQL/MariaDB concept, so there is nothing to switch.
		 */
		if ( ! $this->platform()->has_storage_engines() ) {
			$this->log( 'warning', 'ddl', 'engine() is unsupported on this platform: storage engines are a MySQL/MariaDB concept.' );
			return false;
		}

		// Sanitize and validate the engine name.
		$engine = $this->sanitize_engine( $engine );

		// Bail if the engine name is not recognized.
		if ( empty( $engine ) ) {
			return false;
		}

		// Query statement.
		$sql    = "ALTER TABLE {$this->table_name} ENGINE={$engine}";
		$result = $this->db()->query( $sql );

		// Was the engine changed?
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

	/**
	 * Truncate this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function truncate() {

		// Query statement.
		$sql    = "TRUNCATE TABLE {$this->table_name}";
		$result = $this->db()->query( $sql );

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

		// Query statement.
		$sql    = "DELETE FROM {$this->table_name}";
		$result = $this->db()->query( $sql );

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

		// Sanitize the new table name.
		$table_name = $this->sanitize_table_name( $new_table_name );

		// Bail if new table name is invalid.
		if ( empty( $table_name ) ) {
			return false;
		}

		// Query statement.
		$table  = $this->table_prefix . $this->apply_prefix( $table_name );
		$sql    = "CREATE TABLE {$table} LIKE {$this->table_name}";
		$result = $this->db()->query( $sql );

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

		// Sanitize the new table name.
		$table_name = $this->sanitize_table_name( $new_table_name );

		// Bail if new table name is invalid.
		if ( empty( $table_name ) ) {
			return false;
		}

		// Query statement.
		$table  = $this->table_prefix . $this->apply_prefix( $table_name );
		$sql    = "INSERT INTO {$table} SELECT * FROM {$this->table_name}";
		$result = $this->db()->query( $sql );

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

		// Query statement.
		$sql    = "SELECT COUNT(*) FROM {$this->table_name}";
		$result = $this->db()->get_var( $sql );

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
	 * After a successful rename, $this->table_name is not updated - callers
	 * are responsible for refreshing any references to the old name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $new_table_name The new name for this table, without any prefix.
	 *
	 * @return bool
	 */
	public function rename( $new_table_name = '' ) {

		// Sanitize the new table name.
		$table_name = $this->sanitize_table_name( $new_table_name );

		// Bail if new table name is invalid.
		if ( empty( $table_name ) ) {
			return false;
		}

		// Query statement.
		$table  = $this->table_prefix . $this->apply_prefix( $table_name );
		$sql    = "ALTER TABLE {$this->table_name} RENAME TO {$table}";
		$result = $this->db()->query( $sql );

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

		// Query statement.
		$sql  = "SHOW COLUMNS FROM {$this->table_name} LIKE %s";
		$name = $this->sanitize_column_name( $name );

		if ( false === $name ) {
			return false;
		}

		$like     = $this->db()->esc_like( $name );
		$prepared = $this->db()->prepare( $sql, $like );
		$result   = ! empty( $prepared ) ? $this->db()->query( $prepared ) : false;

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

		// Limit $column to Key or Column name, until we can do better.
		if ( ! in_array( $column, array( 'Key_name', 'Column_name' ), true ) ) {
			$column = 'Key_name';
		}

		// Query statement.
		$sql  = "SHOW INDEXES FROM {$this->table_name} WHERE {$column} LIKE %s";
		$name = $this->sanitize_column_name( $name );

		if ( false === $name ) {
			return false;
		}

		$like     = $this->db()->esc_like( $name );
		$prepared = $this->db()->prepare( $sql, $like );
		$result   = ! empty( $prepared ) ? $this->db()->query( $prepared ) : false;

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

		// Query statement.
		$sql    = "ANALYZE TABLE {$this->table_name}";
		$query  = (array) $this->db()->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text ) ? $result->Msg_text : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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

		// Query statement.
		$sql    = "CHECK TABLE {$this->table_name}";
		$query  = (array) $this->db()->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text ) ? $result->Msg_text : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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

		// Query statement - CHECKSUM TABLE returns exactly one row per table.
		$sql    = "CHECKSUM TABLE {$this->table_name}";
		$result = $this->db()->get_row( $sql );

		// Return checksum.
		return ( is_object( $result ) && ! empty( $result->Checksum ) ) ? $result->Checksum : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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

		// Query statement.
		$sql    = "OPTIMIZE TABLE {$this->table_name}";
		$query  = (array) $this->db()->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text ) ? $result->Msg_text : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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

		// Query statement.
		$sql    = "REPAIR TABLE {$this->table_name}";
		$query  = (array) $this->db()->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text ) ? $result->Msg_text : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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
