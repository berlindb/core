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
	 * Extra column attributes recognised by BerlinDB.
	 *
	 * Used by sanitize_extra() to validate values before they are interpolated
	 * into SQL strings.
	 *
	 * @since 3.1.0
	 * @var   array<int, string>
	 */
	private const EXTRAS = array(
		'AUTO_INCREMENT',
		'ON UPDATE CURRENT_TIMESTAMP',

		// See: special_args().
		'SERIAL',
		'SERIAL DEFAULT VALUE',
	);

	/**
	 * Printf-style value patterns recognised by BerlinDB.
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
	 * Relationship types recognised by BerlinDB.
	 *
	 * Used by sanitize_relationships() to validate the relationship direction.
	 * Mirrors Relationship::TYPES; kept as a private const here under the same
	 * encapsulation convention, rather than coupling Column to Relationship.
	 *
	 * @since 3.1.0
	 * @var   array<int, string>
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
	 * @var   array<string, mixed>
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
	 * @var   list<array{query: string, column: string, type: string, name?: string}>
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
	 * @param array<string, mixed> $row SHOW COLUMNS row.
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

				// Empty strings trigger auto-inference inside sanitize_pattern(),
				// sanitize_cast(), and sanitize_validation().
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
	}

	/**
	 * Validate arguments after they are parsed.
	 *
	 * @since 1.0.0 Originally private.
	 * @since 3.0.0 Changed visibility to protected.
	 *
	 * @param array<string, mixed> $args Default empty array.
	 * @return array<string, mixed>
	 */
	protected function validate_args( $args = array() ) {

		// Sanitization callbacks.
		$callbacks = array(

			// Table.
			'name'          => array( $this, 'sanitize_column_name' ),
			'type'          => 'strtoupper',
			'length'        => 'intval',
			'unsigned'      => 'wp_validate_boolean',
			'zerofill'      => 'wp_validate_boolean',
			'binary'        => 'wp_validate_boolean',
			'allow_null'    => 'wp_validate_boolean',
			'default'       => array( $this, 'sanitize_default' ),
			'extra'         => array( $this, 'sanitize_extra' ),
			'encoding'      => 'wp_kses_data',
			'collation'     => 'wp_kses_data',
			'comment'       => array( $this, 'sanitize_comment' ),

			// Special.
			'primary'       => 'wp_validate_boolean',
			'created'       => 'wp_validate_boolean',
			'modified'      => 'wp_validate_boolean',
			'uuid'          => 'wp_validate_boolean',

			// Query.
			'searchable'    => 'wp_validate_boolean',
			'sortable'      => 'wp_validate_boolean',
			'date_query'    => 'wp_validate_boolean',
			'transition'    => 'wp_validate_boolean',
			'in'            => 'wp_validate_boolean',
			'not_in'        => 'wp_validate_boolean',
			'cache_key'     => 'wp_validate_boolean',

			// Extras.
			'pattern'       => array( $this, 'sanitize_pattern' ),
			'cast'          => array( $this, 'sanitize_cast' ),
			'validate'      => array( $this, 'sanitize_validation' ),
			'caps'          => array( $this, 'sanitize_capabilities' ),
			'aliases'       => array( $this, 'sanitize_aliases' ),
			'relationships' => array( $this, 'sanitize_relationships' ),
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

	/**
	 * Handle special column argument values.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/data-type-defaults.html
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Added support for SERIAL "extra" values.
	 * @param array<string, mixed> $args Default empty array.
	 * @return array<string, mixed>
	 */
	protected function special_args( $args = array() ) {

		// Handle specific "extra" aliases.
		if ( ! empty( $args[ 'extra' ] ) ) {

			/**
			 * The special "extra" values below are built into MySQL as
			 * shorthand for commonly used combinations of Column arguments.
			 */
			switch ( strtoupper( $args[ 'extra' ] ) ) {

				// Bigint.
				case 'SERIAL':
					$args[ 'type' ]     = 'bigint';
					$args[ 'length' ]   = '20';
					$args[ 'unsigned' ] = true;
					// No break; keep going.

					// Any int.
				case 'SERIAL DEFAULT VALUE':
					// Skip if not an int type.
					if ( $this->is_int( $args[ 'type' ] ) ) {
						$args[ 'allow_null' ] = false;
						$args[ 'default' ]    = false;
						$args[ 'primary' ]    = true;
						$args[ 'pattern' ]    = '%d';
						$args[ 'extra' ]      = 'AUTO_INCREMENT';
					}
			}
		}

		// Primary columns are expected (by Query) to always be cache keys.
		if ( ! empty( $args[ 'primary' ] ) ) {
			$args[ 'cache_key' ] = true;

			// All UUID columns require these specific criteria.
		} elseif ( ! empty( $args[ 'uuid' ] ) ) {
			$args[ 'name' ]       = 'uuid';
			$args[ 'type' ]       = 'varchar';
			$args[ 'length' ]     = '100';
			$args[ 'pattern' ]    = '%s';
			$args[ 'in' ]         = false;
			$args[ 'not_in' ]     = false;
			$args[ 'searchable' ] = false;
			$args[ 'sortable' ]   = false;
		}

		// Return arguments.
		return (array) $args;
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
	private function is_type( $types = '', $type = '' ) {

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
		return (bool) in_array( $type, $types, true );
	}

	/**
	 * Return if this column has a certain extra value.
	 *
	 * @since 3.0.0
	 * @param array<string>|string $extras The extra value to check.
	 * @param string               $extra  Optional extra value to test. Defaults to $this->extra.
	 * @return bool True if extra matches.
	 */
	private function is_extra( $extras = '', $extra = '' ) {

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
		return (bool) in_array( $extra, $extras, true );
	}

	/** Private Sanitizers ****************************************************/

	/**
	 * Sanitize capabilities array.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $caps Default empty array.
	 * @return array<string, mixed>
	 */
	private function sanitize_capabilities( $caps = array() ) {
		return wp_parse_args(
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
	private function sanitize_aliases( $aliases = array() ) {
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
	 * Invalid or incomplete entries are dropped.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Structured foreign-key declarations.
	 * @param list<mixed> $relationships Default empty array.
	 * @return list<array{query: string, column: string, type: string, name?: string}>
	 */
	private function sanitize_relationships( $relationships = array() ) {

		// Default return value.
		$retval = array();

		// Loop through relationships.
		foreach ( $relationships as $relationship ) {

			// Skip if the entry is not an array.
			if ( ! is_array( $relationship ) ) {
				continue;
			}

			// Skip unless 'query' and 'column' are both non-empty strings.
			if (
				empty( $relationship['query'] ) || ! is_string( $relationship['query'] ) ||
				empty( $relationship['column'] ) || ! is_string( $relationship['column'] )
			) {
				continue;
			}

			// Sanitize 'column' as a valid column name.
			$column = $this->sanitize_column_name( $relationship['column'] );
			if ( empty( $column ) ) {
				continue;
			}

			// Sanitize 'query' as a PHP class name (letters, digits, _, and \).
			$query = preg_replace( '/[^a-zA-Z0-9_\\\\]/', '', $relationship['query'] );
			if ( empty( $query ) ) {
				continue;
			}

			// Sanitize optional 'type', falling back to 'belongs_to'.
			$type = ( isset( $relationship['type'] ) && in_array( $relationship['type'], self::RELATIONSHIP_TYPES, true ) )
				? $relationship['type']
				: 'belongs_to';

			// Build the sanitized entry.
			$entry = array(
				'query'  => $query,
				'column' => $column,
				'type'   => $type,
			);

			// Pass through an optional 'name' (accessor); omitted entries are
			// derived from the local column by the Relationship object.
			if ( ! empty( $relationship['name'] ) && is_string( $relationship['name'] ) ) {
				$name = $this->sanitize_column_name( $relationship['name'] );

				if ( is_string( $name ) && ( '' !== $name ) ) {
					$entry['name'] = $name;
				}
			}

			$retval[] = $entry;
		}

		return $retval;
	}

	/**
	 * Sanitize the extra string.
	 *
	 * @since 3.0.0
	 * @param string $value The value.
	 * @return string
	 */
	private function sanitize_extra( $value = '' ) {

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
	private function sanitize_pattern( $pattern = '%s' ) {

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
	private function sanitize_cast( $callback = '' ) {

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
	 * @return callable|string The most appropriate callback for the value.
	 */
	private function sanitize_validation( $callback = '' ) {

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

	/** Public Validators *****************************************************/

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
	 * to a straight (bool) cast for unrecognised values.
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

		// Already decoded — pass through unchanged.
		if ( is_array( $value ) || is_object( $value ) ) {
			return $value;
		}

		// Null — let the caller decide what null means.
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
	public function validate_json( $value = '' ) {

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

		// Everything else (empty string, non-scalar, invalid JSON) → empty object.
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
	public function validate_null( $value = '' ) {

		// Value is null.
		if ( null === $value ) {

			// If null is allowed, return it.
			if ( true === $this->allow_null ) {
				return null;
			}

			/**
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
	public function validate_datetime( $value = '' ) {

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
	public function validate_decimal( $value = 0, $decimals = 9 ) {

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
	public function validate_numeric( $value = 0, $decimals = false ) {

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
	public function validate_int( $value = 0 ) {
		return (int) $this->validate_numeric( $value, false );
	}

	/**
	 * Validate a UUID.
	 *
	 * Confirms the value is a correctly-prefixed URN UUID string and passes it
	 * through unchanged. Returns the column default for any value that fails the
	 * check — generation is the caller's responsibility (Column::intercept()).
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Pure format validation; generation moved to intercept().
	 *
	 * @param string $value The UUID value to validate.
	 * @return string The original value if valid, or the column default.
	 */
	public function validate_uuid( $value = '' ) {
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
	 * Returns the value to store. The base implementation manages the built-in
	 * "created" and "modified" flags by stamping the current time. It also
	 * returns the unset sentinel for UUID copy operations so add_item() can
	 * regenerate them.
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

		// Copy: clear values that must be regenerated for the new row.
		if ( 'copy' === $method ) {
			if ( ! empty( $this->uuid ) ) {
				return $this->intercept_unset_value;
			}

			return $value;
		}

		// UUID: generate on insert when empty; never touch on update.
		if ( ! empty( $this->uuid ) && ( 'insert' === $method ) && empty( $value ) ) {
			return $this->generate_uuid();
		}

		// Created: stamp on insert when empty or still the column default.
		if ( ! empty( $this->created ) && ( 'insert' === $method ) && ( empty( $value ) || ( $value === $this->default ) ) ) {
			return gmdate( 'Y-m-d H:i:s' );
		}

		// Modified: stamp on every update, and on insert when empty or default.
		if ( ! empty( $this->modified ) ) {
			if ( 'update' === $method ) {
				return gmdate( 'Y-m-d H:i:s' );
			}

			if ( ( 'insert' === $method ) && ( empty( $value ) || ( $value === $this->default ) ) ) {
				return gmdate( 'Y-m-d H:i:s' );
			}
		}

		// No interception: leave the value untouched.
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
	private function get_type_sql() {

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
	private function get_default_sql() {
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

		// Numeric — use 0 unless the column is auto-incrementing.
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
	 * @since 3.0.0
	 *
	 * @param string $alias Optional. Table alias to prefix. Default empty (no alias).
	 *
	 * @return string Quoted SQL reference, e.g. `alias`.`column` or `column`.
	 */
	public function get_name_sql( string $alias = '' ): string {

		// Quote the column name.
		$quoted = $this->quote_identifier( $this->name );

		// Return the column name, optionally prefixed with the quoted alias.
		return ! empty( $alias )
			? $this->quote_identifier( $alias ) . '.' . $quoted
			: $quoted;
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

		/**
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
}
