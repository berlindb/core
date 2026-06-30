<?php
/**
 * Base Custom Database Table Column Class.
 *
 * @package     Database
 * @subpackage  Column
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Kern;

use BerlinDB\Database\Presets\Column\Base as ColumnPreset;
use BerlinDB\Database\Presets\Column\Registry as ColumnPresets;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base class used for each column for a custom table.
 *
 * @since 1.0.0
 * @since 3.0.0 Column::args[] stashes parsed & class arguments.
 *
 * @param array|string $args {
 *     Optional. Array or query string of order query parameters. Default empty.
 *
 *     @type string   $name           Name of database column
 *     @type string   $type           Type of database column
 *     @type int      $length         Length of database column
 *     @type bool     $unsigned       Is integer unsigned?
 *     @type bool     $zerofill       Is integer filled with zeroes?
 *     @type bool     $binary         Is data in a binary format?
 *     @type bool     $allow_null     Is null an allowed value?
 *     @type mixed    $default        Typically 0|'', null, or date value
 *     @type string   $extra          auto_increment, etc...
 *     @type string   $encoding       Typically inherited from $db_global
 *     @type string   $collation      Typically inherited from $db_global
 *     @type string   $comment        Typically empty
 *     @type string   $pattern        Pattern used to format the value
 *     @type bool     $primary        Is this the primary column?
 *     @type bool     $created        Is this the column used as a created date?
 *     @type bool     $modified       Is this the column used as a modified date?
 *     @type bool     $uuid           Is this the column used as a universally unique identifier?
 *     @type bool     $searchable     Is this column searchable?
 *     @type bool     $sortable       Is this column used in orderby?
 *     @type bool     $date_query     Is this column a datetime?
 *     @type bool     $in             Is __in supported?
 *     @type bool     $not_in         Is __not_in supported?
 *     @type bool     $cache_key      Is this column queried independently?
 *     @type bool     $transition     Does this column transition between changes?
 *     @type callable $cast           A callback used to cast the value after it is read from the database.
 *     @type callable $validate       A callback function used to validate on save.
 *     @type array    $caps           Array of capabilities to check.
 *     @type array    $aliases        Array of possible column name aliases.
 *     @type array    $relationships  Array of columns in other tables this column relates to.
 * }
 */
class Column {

	/**
	 * Use these traits.
	 *
	 * @since 3.0.0
	 */
	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;

	/** Constants *************************************************************/

	/**
	 * Extra column attributes recognized by BerlinDB.
	 *
	 * Used by sanitize_extra() to validate values before they are interpolated
	 * into SQL strings.
	 *
	 * @since 3.1.0
	 * @var   array<int,string>
	 */
	private const EXTRAS = array(
		'AUTO_INCREMENT',
		'ON UPDATE CURRENT_TIMESTAMP',

		// See: special_args().
		'SERIAL',
		'SERIAL DEFAULT VALUE',
	);

	/**
	 * Printf-style value patterns recognized by BerlinDB.
	 *
	 * Used by sanitize_pattern() to validate the format placeholder.
	 *
	 * @since 3.1.0
	 * @var   array{'%s', '%d', '%f'}
	 */
	private const PATTERNS = array(
		'%s', // String.
		'%d', // Integer (decimal).
		'%f', // Float.
	);

	/**
	 * Coarse type categories - a SQL-type taxonomy used by type-sensitive callers
	 * (e.g. operand function validation) instead of a parallel pile of literals.
	 *
	 * Derived from the column type (sanitize_type_category) but settable, with the
	 * declared type as the sane default; a future ColumnType handler (#194) is the
	 * natural home for this alongside pattern, cast, and the is_*() predicates.
	 *
	 * @since 3.1.0
	 * @var array<int,string>
	 */
	private const CATEGORIES = array( 'numeric', 'string', 'date', 'time', 'year' );

	/**
	 * Relationship types recognized by BerlinDB.
	 *
	 * Used by sanitize_relationships() to validate the relationship direction.
	 * Mirrors Relationship::TYPES; kept as a private const here under the same
	 * encapsulation convention, rather than coupling Column to Relationship.
	 *
	 * @since 3.1.0
	 * @var   array<int,string>
	 */
	private const RELATIONSHIP_TYPES = array( 'belongs_to', 'has_many' );

	/** Attributes ************************************************************/

	/**
	 * Name for the database column.
	 *
	 * Required. Must contain lowercase alphabetical characters only. Use of any
	 * other character (number, ascii, unicode, emoji, etc...) will result in
	 * fatal application errors.
	 *
	 * @since 1.0.0
	 * @var   string Default empty string.
	 */
	public $name = '';

	/**
	 * Column data type.
	 *
	 * Required. Must contain valid data type.
	 *
	 * Note: Magic & Fallback support for data types is only added as needed.
	 *       It is recommended that you explicitly define all Column attributes.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/data-types.html
	 *
	 * @since 1.0.0
	 * @var   string Default empty string.
	 */
	public $type = '';

	/**
	 * Column value length.
	 *
	 * Recommended. Set to a reasonable number for your needs.
	 *
	 * Common usages:
	 *
	 * - bigint:  20  - for primary key IDs (relating ID columns across tables)
	 * - varchar: 20  - for registered object statuses or types
	 * - varchar: 255 - for hashes, user-agents, or URLs
	 * - varchar: 191 - utf8mb4 safe length (for $cache_key usages)
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/storage-requirements.html
	 *
	 * @since 1.0.0
	 * @var   bool|int Default false. Int to set length.
	 */
	public $length = false;

	/**
	 * If integer type, is it unsigned?
	 *
	 * Unsigned integers do not allow negative numbers.
	 *
	 * Set to false to allow negative numbers in int columns.
	 *
	 * Note: MySQL 8.0.17 deprecated unsigned Decimals, and support for them
	 *       here will be appropriately removed.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/numeric-types.html
	 *
	 * @since 1.0.0
	 * @var   bool Default true for all int columns.
	 */
	public $unsigned = true;

	/**
	 * If integer type, fill with zeroes?
	 *
	 * Set to true to always fill numeric $length with zeroes.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/numeric-types.html
	 *
	 * @since 1.0.0
	 * @var   bool Default false for all numeric columns.
	 */
	public $zerofill = false;

	/**
	 * If text type, store in a binary format?
	 *
	 * When used with a TEXT data type, the column is assigned the binary (_bin)
	 * collation of the column character set.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/binary-varbinary.html
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $binary = false;

	/**
	 * Is null an allowed value?
	 *
	 * Set to true to explicitly allow storing a literal null value (which is
	 * likely to be different from the default value for the $type).
	 *
	 * Dev Note: In general, it is considered bad application design for a null
	 *           value to coexist alongside a possible "0" or "''" value.
	 *
	 *           When allowing null values, be sure that other areas of your
	 *           program understand that this column's value could be null.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/data-type-defaults.html
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $allow_null = false;

	/**
	 * Default value when a Row is added without a value for this column.
	 *
	 * Typically "0" or "''", a zero date value, or some other value that is
	 * useful as an intelligent default for your Row objects to contain when
	 * no other value is explicitly assigned to them.
	 *
	 * Can be literal null if $allow_null is truthy.
	 *
	 * Invalid values will be dropped.
	 *
	 * Used by Query::default_item() to create an array full of default values.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/data-type-defaults.html
	 *
	 * @since 1.0.0
	 * @var   bool|int|string|null Default empty string.
	 */
	public $default = '';

	/**
	 * Column extra attributes (e.g. auto_increment).
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/data-type-defaults.html
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Allowed values checked via sanitize_extra()
	 * @since 3.0.0 Special values checked via special_args()
	 * @var   string Default empty string.
	 */
	public $extra = '';

	/**
	 * Typically inherited from the database interface $db_global.
	 *
	 * By default, this will use the globally available database encoding. You
	 * most likely do not want to change this; if you do, you already know what
	 * to do.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/charset-column.html
	 *
	 * @since 1.0.0
	 * @var   string Default empty string.
	 */
	public $encoding = '';

	/**
	 * Typically inherited from the database interface $db_global.
	 *
	 * By default, this will use the globally available database collation. You
	 * most likely do not want to change this; if you do, you already know what
	 * to do.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/charset-column.html
	 *
	 * @since 1.0.0
	 * @var   string Default empty string.
	 */
	public $collation = '';

	/**
	 * Typically empty; probably ignore.
	 *
	 * By default, columns do not have comments. This is unused by any other
	 * relative code, but you can include less than 1024 characters here.
	 *
	 * @since 1.0.0
	 * @var   string Default empty string.
	 */
	public $comment = '';

	/** Special Attributes ****************************************************/

	/**
	 * Is this the primary column?
	 *
	 * Typically use this with: bigint, length 20, unsigned, auto_increment.
	 *
	 * By default, columns are not the primary column. This is used by the Query
	 * class for several critical functions, including (but not limited to) the
	 * cache key, meta-key relationships, auto-incrementing, etc...
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $primary = false;

	/**
	 * Is this column constrained to unique values?
	 *
	 * By default, no. `unique => true` makes the Schema derive a single-column UNIQUE
	 * index named after the column, unless an existing index already satisfies it. The
	 * flag is the semantic marker; the derived index emits the DDL. For a column too
	 * long to index in full, declare an explicit Index with a prefix length instead.
	 *
	 * @since 3.1.0
	 * @var   bool Default false.
	 */
	public $unique = false;

	/**
	 * Should this column have its own single-column index?
	 *
	 * By default, no. `index => true` makes the Schema derive a single-column KEY
	 * named after the column, unless an existing index already covers it. A `unique`
	 * column takes precedence (a UNIQUE index already serves as a plain one).
	 *
	 * @since 3.1.0
	 * @var   bool Default false.
	 */
	public $index = false;

	/**
	 * Is this the column used as a created date?
	 *
	 * Use this with the "datetime" column type.
	 *
	 * By default, columns do not represent the date a value was first entered.
	 * This is used by the Query class to set its value automatically to the
	 * current datetime value immediately before insert.
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $created = false;

	/**
	 * Is this the column used as a modified date?
	 *
	 * Use this with the "datetime" column type.
	 *
	 * By default, columns do not represent the date a value was last changed.
	 * This is used by the Query class to update its value automatically to the
	 * current datetime value immediately before insert|update.
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $modified = false;

	/**
	 * Is this the column used as a unique universal identifier?
	 *
	 * By default, columns are not UUIDs. This is used by the Query class to
	 * generate a unique string that can be used to identify a row in a database
	 * table, typically in such a way that is unrelated to the row data itself.
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $uuid = false;

	/**
	 * The column presets active on this column, in precedence order.
	 *
	 * Resolved exactly once at construction: by special_args() (from the pre-shape
	 * declaration) for any column built from args, else by init()'s fallback. The
	 * null default distinguishes "not yet resolved" from "resolved to none", so the
	 * two sites never both fire. Not a configurable property - it rides the
	 * special_args() return through set_vars() rather than being caller-supplied. See
	 * get_active_presets().
	 *
	 * @since 3.1.0
	 * @var   array<int,ColumnPreset>|null
	 */
	protected $active_presets = null;

	/** Query Attributes ******************************************************/

	/**
	 * What is the string-replace format?
	 *
	 * By default, column formats will be guessed based on their type. Set this
	 * manually to "%s|%d|%f" only if you are doing something weird, or are
	 * explicitly storing numeric values in text-based column types.
	 *
	 * See: https://www.php.net/manual/en/function.printf.php
	 *
	 * @since 1.0.0
	 * @var   '%s'|'%d'|'%f' Default empty string.
	 */
	public $pattern = '%s';

	/**
	 * Coarse type category - 'numeric', 'string', 'date', 'time', or 'year'.
	 *
	 * Like $pattern, this is inferred from the column type when not provided
	 * (sanitize_type_category) and used by type-sensitive callers - currently
	 * operand function validation (a date function rejecting a numeric column).
	 * Settable, WordPress-style, for the rare column whose type does not imply the
	 * right category; an empty value triggers auto-inference at construction.
	 *
	 * @since 3.1.0
	 * @var   string One of self::CATEGORIES once inferred; '' until then.
	 */
	public $type_category = '';

	/**
	 * Is this column searchable?
	 *
	 * By default, columns are not searchable. When "true", the Query class will
	 * add this column to the results of search queries.
	 *
	 * Avoid setting to "true" on large blobs of text, unless you've optimized
	 * your database server to accommodate these kinds of queries.
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $searchable = false;

	/**
	 * Is this column a date?
	 *
	 * By default, columns do not support date queries. When "true", the Query
	 * class will accept complex statements to help narrow results down to
	 * specific periods of time for values in this column.
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $date_query = false;

	/**
	 * Is this column used in orderby?
	 *
	 * By default, columns are not sortable. This ensures that the database
	 * table does not perform costly operations on unindexed columns or columns
	 * of an inefficient type.
	 *
	 * You can safely turn this on for most numeric columns, indexed columns,
	 * and text columns with intentionally limited lengths.
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $sortable = false;

	/**
	 * Is __in supported?
	 *
	 * By default, columns support being queried using an "IN" statement. This
	 * allows the Query class to retrieve rows that match your array of values.
	 *
	 * Consider setting this to "false" for longer text columns.
	 *
	 * @since 1.0.0
	 * @var   bool Default true
	 */
	public $in = true;

	/**
	 * Is __not_in supported?
	 *
	 * By default, columns support being queried using a "NOT IN" statement.
	 * This allows the Query class to retrieve rows that do not match your array
	 * of values.
	 *
	 * Consider setting this to "false" for longer text columns.
	 *
	 * @since 1.0.0
	 * @var   bool Default true.
	 */
	public $not_in = true;

	/** Cache Attributes ******************************************************/

	/**
	 * Does this column have its own cache key?
	 *
	 * By default, only primary columns are used as cache keys. If this column
	 * is unique, or is frequently used to get database results, you may want to
	 * consider setting this to true.
	 *
	 * Use in conjunction with a database index for speedy queries.
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $cache_key = false;

	/** Action Attributes *****************************************************/

	/**
	 * Does this column fire a transition action when it's value changes?
	 *
	 * Typically used with: varchar, length 20, cache_key.
	 *
	 * By default, columns do not fire transition actions. In some cases, it may
	 * be desirable to know when a database value changes, and what the old and
	 * new values are when that happens.
	 *
	 * The Query class is responsible for triggering the event action.
	 *
	 * @since 1.0.0
	 * @var   bool Default false.
	 */
	public $transition = false;

	/** Callback Attributes ***************************************************/

	/**
	 * Maybe cast this data after it is read from the database.
	 *
	 * By default, column data is cast based on the type of column that it is.
	 * You can set this to any callable to override the default cast behavior.
	 *
	 * @since 3.0.0
	 * @var   callable|string Default empty string.
	 */
	public $cast = '';

	/**
	 * Maybe validate this data before it is written to the database.
	 *
	 * By default, column data is validated based on the type of column that it
	 * is. You can set this to a callback function of your choice to override
	 * the default validation behavior.
	 *
	 * @since 1.0.0
	 * @var   string Default empty string.
	 */
	public $validate = '';

	/**
	 * Array of capabilities used to interface with this column.
	 *
	 * These are used by the Query class to allow and disallow CRUD access to
	 * column data, typically based on roles or capabilities.
	 *
	 * @since 1.0.0
	 * @var   array<string,mixed>
	 */
	public $caps = array();

	/**
	 * Array of possible aliases this column can be referred to as.
	 *
	 * These are used by the Query class to allow for columns to be renamed
	 * without requiring complex architectural backwards compatibility support.
	 *
	 * @since 1.0.0
	 * @var   list<string>
	 */
	public $aliases = array();

	/**
	 * Array of possible relationships this column has with columns in other
	 * database tables.
	 *
	 * These are typically unenforced foreign keys, and are used by the Query
	 * class to help prime related items. Each entry maps this column to a
	 * column on another table, addressed by that table's Query class. See
	 * sanitize_relationships() for the recognized entry shape.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Structured foreign-key declarations.
	 * @var   list<array{query: string, column: string, type: string, name?: string, enforce?: bool, on_delete?: string, on_update?: string, constraint?: string}>
	 */
	public $relationships = array();

	/** Internal Attributes ***************************************************/

	/**
	 * Unique sentinel value that tells Query to remove an intercepted column.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $intercept_unset_value = '';

	/** Factories *************************************************************/

	/**
	 * Build a Column from a single SHOW COLUMNS row.
	 *
	 * Maps the six-field associative array returned by wpdb::get_results()
	 * (Field, Type, Null, Key, Default, Extra) to Column constructor args.
	 *
	 * Only properties reliably derivable from MySQL metadata are populated.
	 * Application-level flags such as searchable, sortable, transition, and
	 * cache_key are left at their defaults and can be configured afterwards.
	 *
	 * Expected $row keys: Field, Type, Null, Key, Default, Extra.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $row SHOW COLUMNS row.
	 * @return self
	 */
	public static function from_mysql( array $row ) {

		// Parse the type string: e.g. "bigint(20) unsigned", "varchar(191)", "datetime".
		$type_raw  = $row['Type'] ?? '';
		$base_type = '';
		$length    = false;
		$unsigned  = false;
		$zerofill  = false;

		if ( preg_match( '/^(\w+)(?:\(([^)]+)\))?\s*(unsigned)?\s*(zerofill)?/i', $type_raw, $m ) ) {
			$base_type = $m[1];

			// Decimal/enum types include a comma; take only the precision part.
			if ( ! empty( $m[2] ) ) {
				$comma_pos = strpos( $m[2], ',' );
				$length    = ( false !== $comma_pos )
					? (int) substr( $m[2], 0, $comma_pos )
					: (int) $m[2];
			}

			$unsigned = ! empty( $m[3] );
			$zerofill = ! empty( $m[4] );
		}

		// Derive flags from the row fields.
		$allow_null = isset( $row['Null'] ) && ( 'YES' === $row['Null'] );
		$primary    = isset( $row['Key'] ) && ( 'PRI' === $row['Key'] );
		$date_query = in_array( strtolower( $base_type ), array( 'date', 'datetime', 'timestamp', 'time', 'year' ), true );

		// A null Default means the column default IS null; a missing key means no default at all.
		$default = array_key_exists( 'Default', $row ) ? $row['Default'] : false;

		/*
		 * Return a new Column instance with the above properties. Note that
		 * some properties are left at their defaults, and can be configured
		 * after the fact.
		 */
		return new self(
			array(
				'name'       => $row['Field'] ?? '',
				'type'       => $base_type,
				'length'     => $length,
				'unsigned'   => $unsigned,
				'zerofill'   => $zerofill,
				'allow_null' => $allow_null,
				'default'    => $default,
				'extra'      => $row['Extra'] ?? '',
				'primary'    => $primary,
				'date_query' => $date_query,

				/*
				 * Empty strings trigger auto-inference inside sanitize_pattern(),
				 * sanitize_cast(), and sanitize_validation().
				 */
				'pattern'    => '',
				'cast'       => '',
				'validate'   => '',
			)
		);
	}

	/**
	 * Initialize generated values.
	 *
	 * @since 3.1.0
	 */
	protected function init(): void {
		$this->intercept_unset_value = $this->generate_random_string();

		/*
		 * special_args() already cached the active presets for any column built from
		 * args. Only fall back to resolving from the configured vars when it never ran
		 * (still null) - e.g. a subclass that hard-codes a special flag and is built
		 * with no construct args - so such a column still generates/stamps on save.
		 */
		if ( null === $this->active_presets ) {
			$this->active_presets = $this->resolve_presets( get_object_vars( $this ) );
		}
	}

	/**
	 * Sanitization callbacks for a Column's configuration arguments.
	 *
	 * Applied by validate_args() (Traits\Configuration) during construction. Every
	 * Column preset's boolean flag is added from the Registry, so a registered preset's
	 * trigger is recognized (not dropped as an unknown key) without editing this map.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed> Map of config key => sanitization callback.
	 */
	protected function get_config_callbacks(): array {
		$callbacks = array(

			// Table.
			'name'          => array( $this, 'sanitize_column_name' ),
			'type'          => 'strtoupper',
			'length'        => 'intval',
			'unsigned'      => array( $this, 'sanitize_boolean' ),
			'zerofill'      => array( $this, 'sanitize_boolean' ),
			'binary'        => array( $this, 'sanitize_boolean' ),
			'allow_null'    => array( $this, 'sanitize_boolean' ),
			'default'       => array( $this, 'sanitize_default' ),
			'extra'         => array( $this, 'sanitize_extra' ),
			'encoding'      => 'wp_kses_data',
			'collation'     => 'wp_kses_data',
			'comment'       => array( $this, 'sanitize_comment' ),

			/*
			 * Special: the property-backed preset flags. The trigger-only preset flags
			 * are added from the Registry at the end of this method, not listed here.
			 */
			'primary'       => array( $this, 'sanitize_boolean' ),
			'unique'        => array( $this, 'sanitize_boolean' ),
			'index'         => array( $this, 'sanitize_boolean' ),
			'created'       => array( $this, 'sanitize_boolean' ),
			'modified'      => array( $this, 'sanitize_boolean' ),
			'uuid'          => array( $this, 'sanitize_boolean' ),

			// Query.
			'searchable'    => array( $this, 'sanitize_boolean' ),
			'sortable'      => array( $this, 'sanitize_boolean' ),
			'date_query'    => array( $this, 'sanitize_boolean' ),
			'transition'    => array( $this, 'sanitize_boolean' ),
			'in'            => array( $this, 'sanitize_boolean' ),
			'not_in'        => array( $this, 'sanitize_boolean' ),
			'cache_key'     => array( $this, 'sanitize_boolean' ),

			// Extras.
			'pattern'       => array( $this, 'sanitize_pattern' ),
			'type_category' => array( $this, 'sanitize_type_category' ),
			'cast'          => array( $this, 'sanitize_cast' ),
			'validate'      => array( $this, 'sanitize_validation' ),
			'caps'          => array( $this, 'sanitize_capabilities' ),
			'aliases'       => array( $this, 'sanitize_aliases' ),
			'relationships' => array( $this, 'sanitize_relationships' ),
		);

		// Recognize every preset's boolean flag (de-dupes against the map above).
		foreach ( ColumnPresets::all() as $preset ) {
			$flag = $preset->flag();

			if ( '' !== $flag ) {
				$callbacks[ $flag ] = array( $this, 'sanitize_boolean' );
			}
		}

		return $callbacks;
	}

	/**
	 * Handle special column argument values.
	 *
	 * Collects every Column preset whose declaration is present (in precedence order),
	 * then merges each preset's forced shape over the incoming args and soft-defaults
	 * the name from the first preset that offers one. More than one preset can apply
	 * (e.g. uuid + primary), and a SHAPE key from a later preset wins.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/data-type-defaults.html
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Added support for SERIAL "extra" values.
	 * @since 3.1.0 Re-homed the per-shape branches into Presets\Column\* presets.
	 * @param array<string,mixed> $args Default empty array.
	 * @return array<string,mixed>
	 */
	protected function special_args( $args = array() ) {
		$args = (array) $args;

		// Collect every preset whose declaration is present, in precedence order.
		$presets = $this->resolve_presets( $args );

		// Merge each preset's forced shape over the incoming args, in order.
		foreach ( $presets as $preset ) {
			$args = $preset->set_args( $args, $this );
		}

		// Soft-default the name from the first preset that offers one, only when none was given.
		if ( empty( $args[ 'name' ] ) ) {
			foreach ( $presets as $preset ) {
				$default = $preset->default_name();

				if ( '' !== $default ) {
					$args[ 'name' ] = $default;
					break;
				}
			}
		}

		/*
		 * Consume each preset's trigger flag that has no backing Column property, so
		 * set_vars() does not create a dynamic property for it. A flag a property DOES
		 * exist for (uuid/created/modified/primary) is left to persist as today.
		 */
		foreach ( ColumnPresets::all() as $preset ) {
			$flag = $preset->flag();

			if ( ( '' !== $flag ) && ! property_exists( $this, $flag ) ) {
				unset( $args[ $flag ] );
			}
		}

		/*
		 * Cache the matched presets HERE, from the pre-shape args, so a preset whose
		 * trigger its own shaping consumes (Serial turns extra=SERIAL into AUTO_INCREMENT)
		 * is not lost. The list rides the return value into set_vars(), so it survives
		 * the configure() snapshot/merge. init() only re-resolves when this never ran.
		 */
		$args[ 'active_presets' ] = $presets;

		// Return arguments.
		return $args;
	}

	/** Presets ***************************************************************/

	/**
	 * Resolve the Column presets whose declaration is present, in precedence order.
	 *
	 * Precedence is the Registry's stable order (Registry::all()). Shared by
	 * special_args() (shaping from the incoming args) and init()'s fallback (resolving
	 * from the configured column's own vars), so both see the same set.
	 *
	 * @since 3.1.0
	 * @param array<string,mixed> $args Column args, or the column's own vars.
	 * @return array<int,ColumnPreset>
	 */
	private function resolve_presets( array $args ): array {
		$presets = array();

		foreach ( ColumnPresets::all() as $preset ) {
			if ( $preset->matches( $args ) ) {
				$presets[] = $preset;
			}
		}

		return $presets;
	}

	/**
	 * Return the Column presets active on this column, in precedence order.
	 *
	 * The set that matched the column's declaration (including a Serial whose trigger
	 * its own shaping later consumes). Used by the value seams; exposed for tooling
	 * and tests.
	 *
	 * @since 3.1.0
	 * @return array<int,ColumnPreset>
	 */
	public function get_active_presets(): array {
		return (array) $this->active_presets;
	}

	/** Public Helpers ********************************************************/

	/**
	 * Return if a column type is JSON.
	 *
	 * @since 3.0.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool True if json type only.
	 */
	public function is_json( $type = '' ) {
		return $this->is_type(
			array(
				'json',
			),
			$type
		);
	}

	/**
	 * Return if a column type is a bool.
	 *
	 * @since 3.0.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool True if bool type only.
	 */
	public function is_bool( $type = '' ) {
		return $this->is_type(
			array(
				'bool',
			),
			$type
		);
	}

	/**
	 * Return if a column type is a date.
	 *
	 * @since 3.0.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool True if any date or time.
	 */
	public function is_date_time( $type = '' ) {
		return $this->is_type(
			array(
				'date',
				'datetime',
				'timestamp',
				'time',
				'year',
			),
			$type
		);
	}

	/**
	 * Return if a column type is date-bearing (date, datetime, or timestamp).
	 *
	 * The date-bearing subset of is_date_time() - excludes time-only and year, so
	 * a date-part function (YEAR, MONTH, DAYOF*) can require a real date.
	 *
	 * @since 3.1.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool
	 */
	public function is_date( $type = '' ) {
		return $this->is_type(
			array(
				'date',
				'datetime',
				'timestamp',
			),
			$type
		);
	}

	/**
	 * Return if a column type is time-only.
	 *
	 * @since 3.1.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool
	 */
	public function is_time( $type = '' ) {
		return $this->is_type( array( 'time' ), $type );
	}

	/**
	 * Return if a column type is year.
	 *
	 * @since 3.1.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool
	 */
	public function is_year( $type = '' ) {
		return $this->is_type( array( 'year' ), $type );
	}

	/**
	 * Return if a column type is an integer.
	 *
	 * @since 3.0.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool True if int.
	 */
	public function is_int( $type = '' ) {
		return $this->is_type(
			array(
				'tinyint',
				'smallint',
				'mediumint',
				'int',
				'bigint',
			),
			$type
		);
	}

	/**
	 * Return if a column type is decimal.
	 *
	 * @since 3.0.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool True if float.
	 */
	public function is_decimal( $type = '' ) {
		return $this->is_type(
			array(
				'float',
				'double',
				'decimal',
			),
			$type
		);
	}

	/**
	 * Return if a column type is numeric.
	 *
	 * Consider using is_int() or is_decimal() for improved specificity.
	 *
	 * @since 1.0.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool True if bit, int, or float.
	 */
	public function is_numeric( $type = '' ) {
		return $this->is_type(
			array(

				// Bit.
				'bit',

				// Ints.
				'tinyint',
				'smallint',
				'mediumint',
				'int',
				'bigint',

				// Other.
				'float',
				'double',
				'decimal',
			),
			$type
		);
	}

	/**
	 * Return if a column type is a string.
	 *
	 * For binary strings (blobs) use is_binary().
	 *
	 * @since 3.0.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool True if text.
	 */
	public function is_text( $type = '' ) {
		return $this->is_type(
			array(

				// Char.
				'char',
				'varchar',

				// Text.
				'tinytext',
				'text',
				'mediumtext',
				'longtext',
			),
			$type
		);
	}

	/**
	 * Return if a column type is binary.
	 *
	 * @since 3.0.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool True if binary.
	 */
	public function is_binary( $type = '' ) {
		return $this->is_type(
			array(

				// Binary.
				'binary',
				'varbinary',

				// Blobs.
				'tinyblob',
				'blob',
				'mediumblob',
				'longblob',
			),
			$type
		);
	}

	/**
	 * Return if a column type is a length-bounded string (char/varchar/binary/varbinary).
	 *
	 * The bounded subset of is_text() + is_binary(): these carry a maximum length and
	 * can be indexed in full, unlike the unbounded TEXT/BLOB types, which MySQL rejects
	 * in a plain key (a key prefix length is required) even when a length is declared.
	 *
	 * @since 3.1.0
	 * @param string $type Optional type string to test. Defaults to $this->type.
	 * @return bool True if a bounded char/varchar/binary/varbinary.
	 */
	public function is_bounded_string( $type = '' ) {
		return $this->is_type(
			array(
				'char',
				'varchar',
				'binary',
				'varbinary',
			),
			$type
		);
	}

	/**
	 * Whether this column auto-increments (the database assigns its value).
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_auto_increment(): bool {
		return $this->is_extra( 'AUTO_INCREMENT' );
	}

	/** Private Helpers *******************************************************/

	/**
	 * Return if this column is of a certain type.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Empty $types returns false; optional $type needle added.
	 * @param array<string>|string $types The types to check.
	 * @param string               $type  Optional type to test. Defaults to $this->type.
	 * @return bool True if type matches.
	 */
	private function is_type( $types = '', $type = '' ): bool {

		// Bail if no type passed.
		if ( empty( $types ) ) {
			return false;
		}

		// If string, cast to array.
		if ( is_string( $types ) ) {
			$types = (array) $types;
		}

		// Make them lowercase.
		$types = array_map( 'strtolower', $types );

		// Use the provided type or fall back to this column's type.
		$type = strtolower( ! empty( $type ) ? $type : $this->type );

		// Return if match.
		return in_array( $type, $types, true );
	}

	/**
	 * Return if this column has a certain extra value.
	 *
	 * @since 3.0.0
	 * @param array<string>|string $extras The extra value to check.
	 * @param string               $extra  Optional extra value to test. Defaults to $this->extra.
	 * @return bool True if extra matches.
	 */
	private function is_extra( $extras = '', $extra = '' ): bool {

		// Bail if no extra passed.
		if ( empty( $extras ) ) {
			return false;
		}

		// If string, cast to array.
		if ( is_string( $extras ) ) {
			$extras = (array) $extras;
		}

		// Make them lowercase.
		$extras = array_map( 'strtoupper', $extras );

		// Use the provided extra or fall back to this column's extra.
		$extra = strtoupper( ! empty( $extra ) ? $extra : $this->extra );

		// Return if match.
		return in_array( $extra, $extras, true );
	}

	/** Private Sanitizers ****************************************************/

	/**
	 * Sanitize capabilities array.
	 *
	 * @since 1.0.0
	 * @param array<string,mixed> $caps Default empty array.
	 * @return array<string,mixed>
	 */
	private function sanitize_capabilities( $caps = array() ): array {
		return $this->parse_args(
			$caps,
			array(
				'select' => 'exist',
				'insert' => 'exist',
				'update' => 'exist',
				'delete' => 'exist',
			)
		);
	}

	/**
	 * Sanitize aliases array.
	 *
	 * An array of other names that this column is known as. Useful for
	 * renaming a Column and wanting to continue supporting the old name(s).
	 *
	 * @since 1.0.0
	 * @param list<string> $aliases Default empty array.
	 * @return list<string>
	 */
	private function sanitize_aliases( $aliases = array() ): array {
		$func    = array( $this, 'sanitize_column_name' );
		$aliases = array_filter( $aliases );
		$mapped  = array_map( $func, $aliases );
		$retval  = array_filter( $mapped, 'is_string' );

		return array_values( $retval );
	}

	/**
	 * Sanitize the relationships array.
	 *
	 * Each relationship declares an (unenforced) foreign key from this column to
	 * a column on another table, addressed by that table's Query class.
	 * Reading: "this column's value maps to {column} on the table managed by
	 * {query}." See berlindb/core #193 for the integration roadmap.
	 *
	 * Recognized entry keys:
	 *
	 * - 'query'  (string) Required. FQCN of the remote Query class.
	 * - 'column' (string) Required. Column on that table this value maps to.
	 * - 'type'   (string) Optional. 'belongs_to' (this column holds the FK) or
	 *                     'has_many' (this column is the referenced key).
	 *                     Defaults to 'belongs_to'.
	 * - 'name'   (string) Optional. Accessor handle for the relationship. When
	 *                     omitted, the Relationship derives it from the local
	 *                     column (trailing _id / _uuid removed).
	 *
	 * Optional enforced-FK attributes (passed through for the Relationship to
	 * validate; only meaningful when emitting real FOREIGN KEY DDL):
	 *
	 * - 'enforce'    (bool)   Emit a real FOREIGN KEY constraint. Default false.
	 * - 'on_delete'  (string) Referential action on delete.
	 * - 'on_update'  (string) Referential action on update.
	 * - 'constraint' (string) Explicit SQL constraint name.
	 *
	 * Invalid or incomplete entries are dropped (fail-closed) and logged as a
	 * warning with a stable reason code at the point of rejection.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Structured foreign-key declarations.
	 * @since 3.1.0 Dropped declarations are logged with a stable reason code (#206).
	 * @param list<mixed> $relationships Default empty array.
	 * @return list<array{query: string, column: string, type: string, name?: string, enforce?: bool, on_delete?: string, on_update?: string, constraint?: string}>
	 */
	private function sanitize_relationships( $relationships = array() ): array {

		// Default return value.
		$retval = array();

		/*
		 * Keep the valid entries; log a stable warning for each dropped one at the
		 * point of rejection. The drop is fail-closed - the warning only makes the
		 * loss visible. These logs survive construction because configure()
		 * excludes the log store from the config snapshot it merges over.
		 */
		foreach ( $relationships as $relationship ) {

			// The entry must be an array.
			if ( ! is_array( $relationship ) ) {
				$this->log(
					'warning',
					'relationship_invalid_declaration',
					'Relationship declaration is not an array; dropped.',
					array( 'column' => $this->name )
				);
				continue;
			}

			// 'query' and 'column' are both required, as non-empty strings.
			if (
				empty( $relationship['query'] ) || ! is_string( $relationship['query'] ) ||
				empty( $relationship['column'] ) || ! is_string( $relationship['column'] )
			) {
				$this->log(
					'warning',
					'relationship_missing_key',
					'Relationship declaration is missing a required "query" or "column"; dropped.',
					array( 'column' => $this->name )
				);
				continue;
			}

			// 'column' must sanitize to a valid column name.
			$column = $this->sanitize_column_name( $relationship['column'] );
			if ( empty( $column ) ) {
				$this->log(
					'warning',
					'relationship_invalid_column',
					'Relationship declares an invalid remote column name; dropped.',
					array(
						'column' => $this->name,
						'value'  => $relationship['column'],
					)
				);
				continue;
			}

			/*
			 * Validate 'query' as a PHP class reference. sanitize_class_name()
			 * REJECTS (doesn't strip) anything invalid, returning '' - so a
			 * malformed value like 'Order; DROP TABLE' drops the whole relationship
			 * rather than mutating into a different, real class.
			 */
			$query = $this->sanitize_class_name( $relationship['query'] );
			if ( '' === $query ) {
				$this->log(
					'warning',
					'relationship_invalid_query_class',
					'Relationship declares an invalid remote query class; dropped.',
					array(
						'column' => $this->name,
						'value'  => $relationship['query'],
					)
				);
				continue;
			}

			/*
			 * Resolve optional 'type'. An OMITTED type defaults to 'belongs_to'
			 * (the common case). A PRESENT-but-invalid type (e.g. a typo like
			 * 'has-many') is a misconfiguration: drop the whole relationship rather
			 * than silently coercing it to the wrong direction - the reject-not-
			 * mutate stance taken for 'query' above.
			 */
			if ( ! isset( $relationship['type'] ) ) {
				$type = 'belongs_to';
			} elseif ( in_array( $relationship['type'], self::RELATIONSHIP_TYPES, true ) ) {
				$type = $relationship['type'];
			} else {
				$this->log(
					'warning',
					'relationship_invalid_type',
					'Relationship declares an unknown "type"; dropped.',
					array(
						'column' => $this->name,
						'value'  => $relationship['type'],
					)
				);
				continue;
			}

			// Build the sanitized entry.
			$entry = array(
				'query'  => $query,
				'column' => $column,
				'type'   => $type,
			);

			/*
			 * Pass through an optional 'name' (accessor); omitted entries are
			 * derived from the local column by the Relationship object.
			 */
			if ( ! empty( $relationship['name'] ) && is_string( $relationship['name'] ) ) {
				$name = $this->sanitize_column_name( $relationship['name'] );

				if ( is_string( $name ) && ( '' !== $name ) ) {
					$entry['name'] = $name;
				}
			}

			// Pass through an optional enforced-FK flag; Relationship validates it.
			if ( isset( $relationship['enforce'] ) ) {
				$entry['enforce'] = $this->sanitize_boolean( $relationship['enforce'] );
			}

			/*
			 * Pass through optional DDL attributes for real FOREIGN KEYs; the
			 * Relationship is the authority that validates their values.
			 */
			foreach ( array( 'on_delete', 'on_update', 'constraint' ) as $ddl_key ) {
				if ( ! empty( $relationship[ $ddl_key ] ) && is_string( $relationship[ $ddl_key ] ) ) {
					$entry[ $ddl_key ] = $relationship[ $ddl_key ];
				}
			}

			// Append the sanitized entry.
			$retval[] = $entry;
		}

		// Return the sanitized relationships.
		return $retval;
	}

	/**
	 * Sanitize the extra string.
	 *
	 * @since 3.0.0
	 * @param string $value The value.
	 * @return string
	 */
	private function sanitize_extra( $value = '' ): string {

		// Default return value.
		$retval = '';

		// Always uppercase.
		$value = strtoupper( $value );

		// Set return value if allowed.
		if ( in_array( $value, self::EXTRAS, true ) ) {
			$retval = $value;
		}

		// Return.
		return $retval;
	}

	/**
	 * Sanitize the default value.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses validate()
	 * @param mixed $value Default value for the column.
	 * @return mixed
	 */
	private function sanitize_default( $value = '' ) {
		return $this->validate( $value, $value );
	}

	/**
	 * Sanitize the pattern string.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Falls back to using is_ methods if invalid param
	 * @param string $pattern Default '%s'. Allowed values: %s, %d, %f.
	 * @return '%s'|'%d'|'%f' Default '%s'.
	 */
	private function sanitize_pattern( $pattern = '%s' ): string {

		// Return pattern if allowed.
		if ( in_array( $pattern, self::PATTERNS, true ) ) {
			return $pattern;
		}

		// Default string.
		$retval = '%s';

		// Integer.
		if ( $this->is_int() ) {
			$retval = '%d';

			// Float.
		} elseif ( $this->is_decimal() ) {
			$retval = '%f';
		}

		// Return.
		return $retval;
	}

	/**
	 * Sanitize the type category, inferring it from the column type when absent.
	 *
	 * Mirrors sanitize_pattern(): an explicit, recognized category is honored;
	 * anything else (the common case - not provided) is inferred from the declared
	 * type via the is_*() predicates. Date-bearing / time-only / year are kept
	 * distinct so a date function does not accept a TIME or YEAR column.
	 *
	 * @since 3.1.0
	 *
	 * @param string $category Default empty string. A category to honor as-is, if recognized.
	 * @return string One of self::CATEGORIES.
	 */
	private function sanitize_type_category( $category = '' ): string {

		// Honor an explicit, recognized category.
		if ( is_string( $category ) && in_array( $category, self::CATEGORIES, true ) ) {
			return $category;
		}

		// Otherwise infer from the column type.
		if ( $this->is_date() ) {
			return 'date';
		}

		if ( $this->is_time() ) {
			return 'time';
		}

		if ( $this->is_year() ) {
			return 'year';
		}

		if ( $this->is_numeric() ) {
			return 'numeric';
		}

		return 'string';
	}

	/**
	 * Sanitize the cast callback.
	 *
	 * Returns the callback if callable. Otherwise infers a sensible default
	 * from the column type. Returns null for types with no default cast
	 * (datetime, binary, etc.) so that cast() is a no-op for them.
	 *
	 * @since 3.0.0
	 * @param callable|string $callback Default empty string.
	 * @return callable|null
	 */
	private function sanitize_cast( $callback = '' ): ?callable {

		// Return callback if it's callable.
		if ( is_callable( $callback ) ) {
			return $callback;
		}

		// JSON.
		if ( $this->is_json() ) {
			return array( $this, 'cast_json' );
		}

		// Bool.
		if ( $this->is_bool() ) {
			return array( $this, 'cast_bool' );
		}

		// Integer.
		if ( $this->is_int() ) {
			return 'intval';
		}

		// Decimal.
		if ( $this->is_decimal() ) {
			return 'floatval';
		}

		// Text.
		if ( $this->is_text() ) {
			return 'strval';
		}

		// No default cast for other types (datetime, binary, etc.).
		return null;
	}

	/**
	 * Sanitize the validation callback.
	 *
	 * This method accepts a function or method, and will return it if it is
	 * callable. If it is not callable, the best fallback callback is
	 * calculated based on varying column properties.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Explicit support for decimal, int, and numeric types.
	 * @param callable|string $callback Default empty string. A callable or
	 *                                  the name of a callable function.
	 * @return callable The most appropriate callback for the value.
	 */
	private function sanitize_validation( $callback = '' ): callable {

		// Return callback if it's callable.
		if ( is_callable( $callback ) ) {
			return $callback;
		}

		// UUID special column.
		if ( true === $this->uuid ) {
			$callback = array( $this, 'validate_uuid' );

			// JSON explicit fallback.
		} elseif ( $this->is_json() ) {
			$callback = array( $this, 'validate_json' );

			// Datetime explicit fallback.
		} elseif ( $this->is_type( 'datetime' ) ) {
			$callback = array( $this, 'validate_datetime' );

			// Intval fallback.
		} elseif ( $this->is_int() ) {
			$callback = array( $this, 'validate_int' );

			// Decimal fallback.
		} elseif ( $this->is_decimal() ) {
			$callback = array( $this, 'validate_decimal' );

			// Numeric fallback.
		} elseif ( $this->is_numeric() ) {
			$callback = array( $this, 'validate_numeric' );

			// Unknown text, string, or other...
		} else {
			$callback = 'wp_kses_data';
		}

		// Return the callback.
		return $callback;
	}

	/** Validators ************************************************************/

	/**
	 * Cast a value after it is read from the database.
	 *
	 * @since 3.0.0
	 * @param mixed $value Default empty string. Value to cast.
	 * @return mixed
	 */
	public function cast( $value = '' ) {

		// Return the callback (already sanitized as callable).
		if ( ! empty( $this->cast ) && is_callable( $this->cast ) ) {
			return call_user_func( $this->cast, $value );
		}

		// Return the value.
		return $value;
	}

	/**
	 * Cast a value to boolean.
	 *
	 * Uses filter_var() to correctly handle common string representations
	 * ('yes'/'no', 'on'/'off', 'true'/'false', '1'/'0') before falling back
	 * to a straight (bool) cast for unrecognized values.
	 *
	 * @since 3.0.0
	 * @param mixed $value Value to cast.
	 * @return bool
	 */
	public function cast_bool( $value = false ) {

		// Already a bool.
		if ( is_bool( $value ) ) {
			return $value;
		}

		// Try filter_var first for known string representations.
		$result = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		// Return filter result, or fall back to (bool) for anything else.
		return ( null !== $result )
			? $result
			: (bool) $value;
	}

	/**
	 * Cast a JSON string to a PHP array after it is read from the database.
	 *
	 * Idempotent: if the value is already an array or object it is returned
	 * as-is, so double-casting (e.g. from both the Column and a Row subclass)
	 * is safe.
	 *
	 * @since 3.0.0
	 * @param mixed $value Value to cast.
	 * @return array<mixed>|mixed Decoded PHP array, or the original value on failure.
	 */
	public function cast_json( $value = '' ) {

		// Already decoded - pass through unchanged.
		if ( is_array( $value ) || is_object( $value ) ) {
			return $value;
		}

		// Null - let the caller decide what null means.
		if ( null === $value ) {
			return null;
		}

		// Decode non-empty strings.
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );

			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}

		// Fallback to an empty array for anything else.
		return array();
	}

	/**
	 * Validate a value before it is written to a JSON column.
	 *
	 * Arrays and objects are encoded with wp_json_encode(). Strings are
	 * accepted as-is when they contain valid JSON; an empty object `{}` is
	 * substituted for invalid or empty strings. Non-scalar, non-array values
	 * fall back to `{}` as well.
	 *
	 * @since 3.0.0
	 * @param mixed $value Value to validate.
	 * @return string JSON-encoded string ready for storage.
	 */
	protected function validate_json( $value = '' ) {

		// Array or object: encode to a JSON string.
		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );
			return ( false !== $encoded ) ? $encoded : '{}';
		}

		// Valid non-empty JSON string: pass through unchanged.
		if ( is_string( $value ) && '' !== $value ) {
			json_decode( $value );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $value;
			}
		}

		// Everything else (empty string, non-scalar, invalid JSON) -> empty object.
		return '{}';
	}

	/**
	 * Validate a value.
	 *
	 * Used by Column::sanitize_default() and Query to prevent invalid and
	 * unexpected values from being saved in the database.
	 *
	 * @since 3.0.0
	 * @param mixed $value    Default empty string. Value to validate.
	 * @param mixed $fallback Default empty string. Fallback if invalid.
	 * @return mixed
	 */
	public function validate( $value = '', $fallback = '' ) {

		// Check if a literal null value is allowed.
		$value = $this->validate_null( $value );

		// Return null if allowed.
		if ( null === $value ) {
			return null;
		}

		// Return the callback (already sanitized as callable).
		if ( ! empty( $this->validate ) && is_callable( $this->validate ) ) {
			return call_user_func( $this->validate, $value );
		}

		// Return the fallback.
		return $fallback;
	}

	/**
	 * Validate a null value.
	 *
	 * Will return the $default if $allow_null is false.
	 *
	 * @since 3.0.0
	 * @param mixed $value Default empty string.
	 * @return mixed
	 */
	protected function validate_null( $value = '' ) {

		// Value is null.
		if ( null === $value ) {

			// If null is allowed, return it.
			if ( true === $this->allow_null ) {
				return null;
			}

			/*
			 * Null was passed but is not allowed, so fallback to the default
			 * (but only if it is also not null.)
			 *
			 * If the default is null and null is not allowed, fallback to an
			 * empty string and allow MySQL to sort it out.
			 *
			 * Future versions of this validation method will attempt to return
			 * a less ambiguous value.
			 */
			$value = ( null !== $this->default )
				? $this->default
				: '';
		}

		// Return.
		return $value;
	}

	/**
	 * Validate a datetime value.
	 *
	 * This assumes the following MySQL modes:
	 * - NO_ZERO_DATE is off (double negative is proof positive!)
	 * - ALLOW_INVALID_DATES is off
	 *
	 * When MySQL drops support for zero dates, this method will need to be
	 * updated to support different default values based on the environment.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html#sqlmode_allow_invalid_dates
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Add support for CURRENT_TIMESTAMP.
	 * @param string $value Default ''. A datetime value that needs validating.
	 * @return string A valid datetime value.
	 */
	protected function validate_datetime( $value = '' ) {

		// Default empty datetime (value with NO_ZERO_DATE off).
		$default_empty = '0000-00-00 00:00:00';

		// Not using the $default yet.
		$use_default = false;

		// Handle current_timestamp MySQL constant.
		if ( 'CURRENT_TIMESTAMP' === strtoupper( $value ) ) {
			$value = 'CURRENT_TIMESTAMP';

			// Fallback if "empty" value.
		} elseif ( empty( $value ) || ( $default_empty === $value ) ) {
			$use_default = true;

			// All other values.
		} else {

			// Check if valid $value.
			$timestamp = strtotime( $value );

			// Format if valid.
			if ( false !== $timestamp ) {
				$value = gmdate( 'Y-m-d H:i:s', $timestamp );

				// Fallback if invalid.
			} else {
				$use_default = true;
			}
		}

		// Fallback to $default.
		if ( ! empty( $use_default ) ) {
			$value = (string) $this->default;
		}

		// Return the validated value.
		return $value;
	}

	/**
	 * Validate a decimal value.
	 *
	 * Default decimal position is '18,9' for currencies, so that rounding can
	 * be done inside of the application layer and outside of MySQL.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses: validate_numeric().
	 * @param int|string $value    Default empty string. The decimal value to validate.
	 * @param int        $decimals Default 9. The number of decimal points to accept.
	 * @return float Formatted to the number of decimals specified
	 */
	protected function validate_decimal( $value = 0, $decimals = 9 ) {

		// Protect against non-numeric decimals.
		if ( ! is_numeric( $decimals ) ) {
			$decimals = 9;
		}

		// Validate & return.
		return $this->validate_numeric( $value, $decimals );
	}

	/**
	 * Validate a numeric value.
	 *
	 * This is used to validate a mixed value before it is saved into any
	 * numeric column in a database table.
	 *
	 * Uses number_format() (without a thousands separator) which does rounding
	 * to the last decimal if the value is longer than specified.
	 *
	 * @since 3.0.0
	 * @param int|string $value    Default empty string. The numeric value to validate.
	 * @param int|bool   $decimals Default false. Decimal position will be used, or 0.
	 * @return float
	 */
	protected function validate_numeric( $value = 0, $decimals = false ) {

		// Protect against non-numeric values.
		if ( ! is_numeric( $value ) ) {
			$value = ( $value !== $this->default )
				? $this->default
				: 0;
		}

		// Is the value negative and allowed to be?
		$negative_exponent = ( ( $value < 0 ) && ! empty( $this->unsigned ) )
			? -1
			: 1;

		// Only numbers and period.
		$value = preg_replace( '/[^0-9\.]/', '', (string) $value ) ?? '';

		// Attempt to find the decimal position.
		if ( false === $decimals ) {

			// Look for period.
			$period = strpos( $value, '.' );

			// Count the digits after the period, or 0 if no period.
			if ( false !== $period ) {
				$decimals = strlen( $value ) - $period - 1;
			} else {
				$decimals = 0;
			}
		}

		// Format to number of decimals.
		$formatted = number_format( (float) $value, (int) $decimals, '.', '' );

		// Adjust for negative values.
		$retval = ( $formatted * $negative_exponent );

		// Return.
		return $retval;
	}

	/**
	 * Validate an integer value.
	 *
	 * This is used to validate an integer value before it is saved into any
	 * integer column in a database table.
	 *
	 * Uses: validate_numeric() to guard against non-numeric, invalid values
	 *       being cast to a 1 when a fallback to $default is expected.
	 *
	 * @since 3.0.0
	 * @param int $value Default zero.
	 * @return int
	 */
	protected function validate_int( $value = 0 ) {
		return (int) $this->validate_numeric( $value, false );
	}

	/**
	 * Validate a UUID.
	 *
	 * Confirms the value is a correctly-prefixed URN UUID string and passes it
	 * through unchanged. Returns the column default for any value that fails the
	 * check - generation is the caller's responsibility (Column::intercept()).
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Pure format validation; generation moved to intercept().
	 *
	 * @param string $value The UUID value to validate.
	 * @return string The original value if valid, or the column default.
	 */
	protected function validate_uuid( $value = '' ) {
		$prefix = 'urn:uuid:';

		// Return early if valid UUID string with correct prefix.
		if ( is_string( $value ) && ( 0 === strpos( $value, $prefix ) ) ) {
			return $value;
		}

		// Return the default if the value is invalid.
		return (string) $this->default;
	}

	/**
	 * Intercept this column's value for a save operation.
	 *
	 * Threads the value through each active Column preset's intercept(), in precedence
	 * order, so a preset can generate (UUID on insert), stamp (created/modified dates),
	 * or return the unset sentinel (UUID on copy) for the caller to remove the field.
	 *
	 * Contract: returns the (possibly replaced) value to store. The caller
	 * (Query::intercept_item()) writes it back only when it differs from the
	 * incoming value, and unsets the column when the unset sentinel is returned.
	 *
	 * @since 3.1.0
	 *
	 * @param string $method One of insert|update|select|delete|copy.
	 * @param mixed  $value  Incoming value (null when the column was not supplied).
	 * @return mixed
	 */
	public function intercept( $method = 'insert', $value = null ) {

		// Let each active preset shape the value in turn.
		foreach ( (array) $this->active_presets as $preset ) {
			$value = $preset->intercept( (string) $method, $value, $this );
		}

		// Return the (possibly replaced) value to store.
		return $value;
	}

	/** Table Helpers *********************************************************/

	/**
	 * Return the SQL type fragment for this column, including character set
	 * and collation where applicable.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	private function get_type_sql(): string {

		// Bail if no type.
		if ( empty( $this->type ) ) {
			return '';
		}

		// Lowercase looks nicer in DDL.
		$lower = strtolower( $this->type );
		$parts = array();

		// JSON takes no length and no character-set clause.
		if ( $this->is_json() ) {
			return $lower;
		}

		// Type with optional length.
		$parts[] = ! empty( $this->length ) && is_numeric( $this->length )
			? "{$lower}({$this->length})"
			: $lower;

		// Binary column types use fixed charset/collation.
		if ( $this->is_binary() ) {
			$parts[] = 'CHARACTER SET binary';
			$parts[] = 'COLLATE binary';

			// Non-binary column types.
		} else {

			// Encoding.
			if ( ! empty( $this->encoding ) ) {
				$parts[] = "CHARACTER SET {$this->encoding}";
			}

			// Collation.
			if ( ! empty( $this->collation ) ) {

				// Binary text uses "_bin" collation.
				$parts[] = ( ! empty( $this->binary ) && $this->is_text() )
					? "COLLATE {$this->collation}_bin"
					: "COLLATE {$this->collation}";
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Return the SQL DEFAULT clause fragment for this column.
	 *
	 * Returns an empty string when no default clause should be emitted
	 * (e.g. AUTO_INCREMENT columns, or when $default is literal false).
	 *
	 * @since 3.0.0
	 * @return string
	 */
	private function get_default_sql(): string {
		/*
		 * Literal false: suppress the default clause entirely.
		 *
		 * Not reachable via the constructor (sanitize_default() converts false
		 * to ''), but honored when $default is assigned directly by a subclass
		 * or plugin.
		 */
		if ( false === $this->default ) {
			return '';
		}

		// Null default: emit 'default null' only when null is allowed.
		if ( ( true === $this->allow_null ) && ( null === $this->default ) ) {
			return 'default null';
		}

		// Explicit default: trust it when not auto-incrementing.
		if ( ! empty( $this->default ) && ! $this->is_extra( 'AUTO_INCREMENT' ) ) {
			return "default '{$this->default}'";
		}

		// JSON columns cannot carry a string-literal default in MySQL DDL.
		if ( $this->is_json() ) {
			return '';
		}

		// Numeric - use 0 unless the column is auto-incrementing.
		if ( $this->is_numeric() ) {
			return $this->is_extra( 'AUTO_INCREMENT' ) ? '' : "default '0'";
		}

		// Datetime or timestamp.
		if ( $this->is_type( array( 'datetime', 'timestamp' ) ) ) {
			if ( $this->is_extra( 'ON UPDATE CURRENT_TIMESTAMP' ) ) {
				return 'ON UPDATE current_timestamp()';
			}

			// @todo NO_ZERO_DATE
			if ( $this->is_type( 'datetime' ) ) {
				return "default '0000-00-00 00:00:00'";
			}

			return '';
		}

		// All other types (strings, binary, etc.).
		return "default ''";
	}

	/**
	 * Return the backtick-quoted column name for use in query expressions.
	 *
	 * When $alias is provided it is quoted and prepended, producing the fully
	 * qualified form used in WHERE and SELECT clauses: `alias`.`column`.
	 *
	 * When $cast is a valid CAST target the reference is wrapped in
	 * CAST( ... AS $cast ). $cast is sanitized here (sanitize_sql_cast_type()), so
	 * this public helper does not trust its caller - an invalid value is safely
	 * ignored (no cast). Casting is opt-in and never applied by default. CHAR is a
	 * real target (string-semantics comparison), not a no-op.
	 *
	 * @since 3.0.0
	 *
	 * @param string $alias Optional. Table alias to prefix. Default empty (no alias).
	 * @param string $cast  Optional. A CAST target; sanitized internally (invalid => no cast). Default empty.
	 *
	 * @return string Quoted SQL reference, e.g. `alias`.`column` or `column`.
	 */
	public function get_name_sql( string $alias = '', string $cast = '' ): string {

		// Quote the column name.
		$quoted = $this->quote_identifier( $this->name );

		// Optionally prefix with the quoted alias.
		$reference = ! empty( $alias )
			? $this->quote_identifier( $alias ) . '.' . $quoted
			: $quoted;

		// Sanitize the cast at this public boundary; an invalid value => no cast.
		$cast = $this->sanitize_sql_cast_type( $cast );

		// Optionally wrap in a CAST() - a no-op only when $cast is empty.
		return $this->cast_reference( $reference, $cast );
	}

	/**
	 * Derive a safe MySQL CAST() target from this column's own declared type.
	 *
	 * The SQL-side sibling of sanitize_cast(): where that picks a PHP coercion
	 * callback (intval/floatval/...) from the column's type, this picks the SQL
	 * CAST target. Native string types (text, char), and any type with no useful
	 * cast (binary, json, bool, ...), return '' (no cast).
	 *
	 * This is a building block for callers that opt in to casting; it is never
	 * applied automatically.
	 *
	 * @since 3.1.0
	 *
	 * @return string A CAST target (e.g. 'SIGNED', 'DECIMAL', 'DATETIME'), or ''.
	 */
	public function get_sql_cast_type(): string {

		// Integers cast to (UN)SIGNED based on the column's signedness.
		if ( $this->is_int() ) {
			return ! empty( $this->unsigned )
				? 'UNSIGNED'
				: 'SIGNED';
		}

		// Floats and decimals cast to DECIMAL.
		if ( $this->is_decimal() ) {
			return 'DECIMAL';
		}

		// Date/time types cast to the closest CAST target by subtype.
		if ( $this->is_date_time() ) {
			$type = strtolower( (string) $this->type );

			if ( 'date' === $type ) {
				return 'DATE';
			}

			if ( 'time' === $type ) {
				return 'TIME';
			}

			return 'DATETIME';
		}

		// Everything else (text, binary, json, bool, ...) has no useful cast.
		return '';
	}

	/**
	 * Return this column's effective type category - 'date', 'time', 'year',
	 * 'numeric', or 'string'. Lets a type-sensitive caller validate a column
	 * without re-deriving type knowledge (e.g. a date function rejecting a numeric
	 * column).
	 *
	 * Without a cast, this returns the $type_category property (set explicitly or
	 * inferred from the declared type by sanitize_type_category). An optional CAST
	 * overrides it - mirroring get_name_sql(), so the category matches the SQL that
	 * will actually render: a SIGNED/DECIMAL cast is 'numeric', a DATETIME cast is
	 * 'date', etc.
	 *
	 * @since 3.1.0
	 *
	 * @param string $cast Optional. A normalized CAST target overriding the declared type.
	 * @return string One of self::CATEGORIES (a manually-set $type_category may be
	 *                any string; callers match it against a known category list).
	 */
	public function get_type_category( string $cast = '' ): string {

		/*
		 * Normalize the cast exactly as get_name_sql() does before rendering, so
		 * the category matches the SQL: a sanitizable cast (e.g. ' signed ')
		 * overrides the type, while an invalid one (rejected to '') is ignored -
		 * the declared type then decides, just as the dropped cast would render.
		 */
		$cast = ( '' !== $cast )
			? $this->sanitize_sql_cast_type( $cast )
			: '';

		// A valid explicit cast determines the effective category.
		if ( '' !== $cast ) {

			// SIGNED / UNSIGNED / DECIMAL -> numeric.
			if ( str_starts_with( $cast, 'SIGNED' ) || str_starts_with( $cast, 'UNSIGNED' ) || str_starts_with( $cast, 'DECIMAL' ) ) {
				return 'numeric';
			}

			// DATE / DATETIME -> date (date-bearing); TIME -> time.
			if ( str_starts_with( $cast, 'DATE' ) ) {
				return 'date';
			}

			if ( str_starts_with( $cast, 'TIME' ) ) {
				return 'time';
			}

			// CHAR / BINARY -> string.
			return 'string';
		}

		// No cast: the (type-inferred or explicitly set) category property decides.
		return ( '' !== $this->type_category )
			? $this->type_category
			: $this->sanitize_type_category();
	}

	/**
	 * Return a string representation of this column's properties as part of
	 * the "CREATE" string of a Table.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	public function get_create_string() {

		// Create array.
		$create = array();

		// Name.
		if ( ! empty( $this->name ) ) {
			$create[] = $this->get_name_sql();
		}

		// Type.
		$type_sql = $this->get_type_sql();
		if ( ! empty( $type_sql ) ) {
			$create[] = $type_sql;
		}

		/*
		 * Note: unsigned Decimals are deprecated in MySQL 8.0.17, and this will
		 *       be changed to is_int() at a later date.
		 */
		if ( $this->is_numeric() ) {

			// Unsigned.
			if ( ! empty( $this->unsigned ) ) {
				$create[] = 'unsigned';
			}

			// Zerofill.
			if ( ! empty( $this->zerofill ) ) {
				$create[] = 'zerofill';
			}
		}

		// Disallow null.
		if ( false === $this->allow_null ) {
			$create[] = 'not null';
		}

		// Default.
		$default_sql = $this->get_default_sql();
		if ( ! empty( $default_sql ) ) {
			$create[] = $default_sql;
		}

		// Extra.
		if ( ! empty( $this->extra ) ) {
			$create[] = strtoupper( $this->extra );
		}

		// Comment (already sanitized; escape quotes for SQL, as Index does).
		if ( '' !== $this->comment ) {
			$create[] = "COMMENT '" . addslashes( $this->comment ) . "'";
		}

		// Format return value from create array.
		$retval = implode( ' ', $create );

		// Return the create string.
		return $retval;
	}

	/**
	 * Return whether a value is this column's unset sentinel.
	 *
	 * @since 3.1.0
	 * @param mixed $value Value to compare.
	 * @return bool
	 */
	public function is_unset_sentinel( $value ): bool {
		return ( $value === $this->intercept_unset_value );
	}

	/**
	 * Return this column's unset sentinel value.
	 *
	 * Lets an intercepting collaborator (e.g. a Presets\Column preset) ask this
	 * column to drop a field - returning the sentinel from intercept() removes it,
	 * mirroring the in-class UUID-on-copy behavior.
	 *
	 * @since 3.1.0
	 * @internal Collaborator-facing; pairs with is_unset_sentinel().
	 * @return string
	 */
	public function get_unset_sentinel(): string {
		return $this->intercept_unset_value;
	}
}
