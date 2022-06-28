<?php
/**
 * Base Custom Database Table Column Class.
 *
 * @package     Database
 * @subpackage  Column
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */
namespace BerlinDB\Database;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Base class used for each column for a custom table.
 *
 * @since 1.0.0
 * @since 2.1.0 Column::args[] stashes parsed & class arguments.
 *
 * @see Column::__construct() for accepted arguments.
 */
class Column extends Base {

	/** Table Attributes ******************************************************/

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
	 * @var   bool|int|string Default empty string.
	 */
	public $default = '';

	/**
	 * auto_increment, etc...
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/data-type-defaults.html
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Allowed values checked via sanitize_extra()
	 * @since 2.1.0 Special values checked via special_args()
	 * @var   string Default empty string.
	 */
	public $extra = '';

	/**
	 * Typically inherited from the database interface (wpdb).
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
	 * Typically inherited from the database interface (wpdb).
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
	 * @var   string Default empty string.
	 */
	public $pattern = '';

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
	 * @var   array
	 */
	public $caps = array();

	/**
	 * Array of possible aliases this column can be referred to as.
	 *
	 * These are used by the Query class to allow for columns to be renamed
	 * without requiring complex architectural backwards compatibility support.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	public $aliases = array();

	/**
	 * Array of possible relationships this column has with columns in other
	 * database tables.
	 *
	 * These are typically unenforced foreign keys, and are used by the Query
	 * class to help prime related items.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	public $relationships = array();

	/** Methods ***************************************************************/

	/**
	 * Sets up the order query, based on the query vars passed.
	 *
	 * @since 1.0.0
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
	 *     @type mixed    $default        Typically empty/null, or date value
	 *     @type string   $extra          auto_increment, etc...
	 *     @type string   $encoding       Typically inherited from wpdb
	 *     @type string   $collation      Typically inherited from wpdb
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
	 *     @type string   $validate       A callback function used to validate on save.
	 *     @type array    $caps           Array of capabilities to check.
	 *     @type array    $aliases        Array of possible column name aliases.
	 *     @type array    $relationships  Array of columns in other tables this column relates to.
	 * }
	 */
	public function __construct( $args = array() ) {

		// Parse arguments
		$r = $this->parse_args( $args );

		// Maybe set variables from arguments
		if ( ! empty( $r ) ) {
			$this->set_vars( $r );
		}
	}

	/** Argument Handlers *****************************************************/

	/**
	 * Parse column arguments.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Arguments are stashed. Bails if $args is empty.
	 * @param array $args Default empty array.
	 * @return array
	 */
	private function parse_args( $args = array() ) {

		// Stash the arguments
		$this->stash_args( $args );

		// Bail if no arguments
		if ( empty( $args ) ) {
			return array();
		}

		// Parse arguments
		$r = wp_parse_args( $args, $this->args['class'] );

		// Force some arguments for special column types
		$r = $this->special_args( $r );

		// Set the arguments before they are validated & sanitized
		$this->set_vars( $r );

		// Return array
		return $this->validate_args( $r );
	}

	/**
	 * Validate arguments after they are parsed.
	 *
	 * @since 1.0.0
	 * @param array $args Default empty array.
	 * @return array
	 */
	private function validate_args( $args = array() ) {

		// Sanitization callbacks
		$callbacks = array(

			// Table
			'name'          => array( $this, 'sanitize_column_name' ),
			'type'          => 'strtoupper',
			'length'        => 'intval',
			'unsigned'      => 'wp_validate_boolean',
			'zerofill'      => 'wp_validate_boolean',
			'binary'        => 'wp_validate_boolean',
			'allow_null'    => 'wp_validate_boolean',
			'default'       => array( $this, 'sanitize_default' ),
			'extra'         => array( $this, 'sanitize_extra'   ),
			'encoding'      => 'wp_kses_data',
			'collation'     => 'wp_kses_data',
			'comment'       => 'wp_kses_data',

			// Special
			'primary'       => 'wp_validate_boolean',
			'created'       => 'wp_validate_boolean',
			'modified'      => 'wp_validate_boolean',
			'uuid'          => 'wp_validate_boolean',

			// Query
			'searchable'    => 'wp_validate_boolean',
			'sortable'      => 'wp_validate_boolean',
			'date_query'    => 'wp_validate_boolean',
			'transition'    => 'wp_validate_boolean',
			'in'            => 'wp_validate_boolean',
			'not_in'        => 'wp_validate_boolean',
			'cache_key'     => 'wp_validate_boolean',

			// Extras
			'pattern'       => array( $this, 'sanitize_pattern'       ),
			'validate'      => array( $this, 'sanitize_validation'    ),
			'caps'          => array( $this, 'sanitize_capabilities'  ),
			'aliases'       => array( $this, 'sanitize_aliases'       ),
			'relationships' => array( $this, 'sanitize_relationships' )
		);

		// Default return arguments
		$r = array();

		// Loop through and try to execute callbacks
		foreach ( $args as $key => $value ) {

			// Callback is callable
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

		// Return sanitized arguments
		return $r;
	}

	/**
	 * Handle special special column argument values.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/data-type-defaults.html
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Added support for SERIAL "extra" values.
	 * @param array $args Default empty array.
	 * @return array
	 */
	private function special_args( $args = array() ) {

		// Handle specific "extra" aliases
		if ( ! empty( $args['extra'] ) ) {

			/**
			 * The special "extra" values below are built into MySQL as
			 * shorthand for commonly used combinations of Column arguments.
			 */
			switch ( strtoupper( $args['extra'] ) ) {

				// Bigint
				case 'SERIAL' :
					$args['type']     = 'bigint';
					$args['length']   = '20';
					$args['unsigned'] = true;
					// No break; keep going

				// Any int
				case 'SERIAL DEFAULT VALUE' :

					// Skip if not an int type
					if ( in_array( strtolower( $args['type'] ), array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint' ), true ) ) {
						$args['allow_null'] = false;
						$args['default']    = false;
						$args['primary']    = true;
						$args['pattern']    = '%d';
						$args['extra']      = 'AUTO_INCREMENT';
					}
			}
		}

		// Primary columns are expected (by Query) to always be cache keys
		if ( ! empty( $args['primary'] ) ) {
			$args['cache_key'] = true;

		// All UUID columns require these specific criteria
		} elseif ( ! empty( $args['uuid'] ) ) {
			$args['name']       = 'uuid';
			$args['type']       = 'varchar';
			$args['length']     = '100';
			$args['pattern']    = '%s';
			$args['in']         = false;
			$args['not_in']     = false;
			$args['searchable'] = false;
			$args['sortable']   = false;
		}

		// Return arguments
		return (array) $args;
	}

	/** Public Helpers ********************************************************/

	/**
	 * Return if a column type is a bool.
	 *
	 * @since 2.1.0
	 * @return bool True if bool type only.
	 */
	public function is_bool() {
		return $this->is_type( array(
			'bool'
		) );
	}

	/**
	 * Return if a column type is a date.
	 *
	 * @since 2.1.0
	 * @return bool True if any date or time.
	 */
	public function is_date_time() {
		return $this->is_type( array(
			'date',
			'datetime',
			'timestamp',
			'time',
			'year'
		) );
	}

	/**
	 * Return if a column type is an integer.
	 *
	 * @since 2.1.0
	 * @return bool True if int.
	 */
	public function is_int() {
		return $this->is_type( array(
			'tinyint',
			'smallint',
			'mediumint',
			'int',
			'bigint'
		) );
	}

	/**
	 * Return if a column type is decimal.
	 *
	 * @since 2.1.0
	 * @return bool True if float.
	 */
	public function is_decimal() {
		return $this->is_type( array(
			'float',
			'double',
			'decimal'
		) );
	}

	/**
	 * Return if a column type is numeric.
	 *
	 * Consider using is_int() or is_decimal() for improved specificity.
	 *
	 * @since 1.0.0
	 * @return bool True if bit, int, or float.
	 */
	public function is_numeric() {
		return $this->is_type( array(

			// Bit
			'bit',

			// Ints
			'tinyint',
			'smallint',
			'mediumint',
			'int',
			'bigint',

			// Other
			'float',
			'double',
			'decimal'
		) );
	}

	/**
	 * Return if a column type is a string.
	 *
	 * For binary strings (blobs) use is_binary().
	 *
	 * @since 2.1.0
	 * @return bool True if text.
	 */
	public function is_text() {
		return $this->is_type( array(

			// Char
			'char',
			'varchar',

			// Text
			'tinytext',
			'text',
			'mediumtext',
			'longtext',
		) );
	}

	/**
	 * Return if a column type is binary.
	 *
	 * @since 2.1.0
	 * @return bool True if binary.
	 */
	public function is_binary() {
		return $this->is_type( array(

			// Binary
			'binary',
			'varbinary',

			// Blobs
			'tinyblob',
			'blob',
			'mediumblob',
			'longblob'
		) );
	}

	/** Private Helpers *******************************************************/

	/**
	 * Return if this column is of a certain type.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Empty $type returns false.
	 * @param array[string] $type Default empty string. The type to check. Also
	 *                            accepts an array.
	 * @return bool True if type matches.
	 */
	private function is_type( $type = '' ) {

		// Bail if no type passed
		if ( empty( $type ) ) {
			return false;
		}

		// If string, cast to array
		if ( is_string( $type ) ) {
			$type = (array) $type;
		}

		// Make them lowercase
		$types = array_map( 'strtolower', $type );

		// Return if match
		return (bool) in_array( strtolower( $this->type ), $types, true );
	}

	/**
	 * Return if this column is of a certain type.
	 *
	 * @since 2.1.0
	 * @param array[string] $extra Default empty string. The extra to check.
	 *                             Also accepts an array.
	 * @return bool True if extra matches.
	 */
	private function is_extra( $extra = '' ) {

		// Bail if no extra passed
		if ( empty( $extra ) ) {
			return false;
		}

		// If string, cast to array
		if ( is_string( $extra ) ) {
			$extra = (array) $extra;
		}

		// Make them lowercase
		$extras = array_map( 'strtoupper', $extra );

		// Return if match
		return (bool) in_array( strtolower( $this->extra ), $extras, true );
	}

	/** Private Sanitizers ****************************************************/

	/**
	 * Sanitize capabilities array.
	 *
	 * @since 1.0.0
	 * @param array $caps Default empty array.
	 * @return array
	 */
	private function sanitize_capabilities( $caps = array() ) {
		return wp_parse_args( $caps, array(
			'select' => 'exist',
			'insert' => 'exist',
			'update' => 'exist',
			'delete' => 'exist',
		) );
	}

	/**
	 * Sanitize aliases array.
	 *
	 * An array of other names that this column is known as. Useful for
	 * renaming a Column and wanting to continue supporting the old name(s).
	 *
	 * @since 1.0.0
	 * @param array $aliases Default empty array.
	 * @return array
	 */
	private function sanitize_aliases( $aliases = array() ) {
		$func    = array( $this, 'sanitize_column_name' );
		$aliases = array_filter( $aliases );
		$retval  = array_map( $func, $aliases );

		return $retval;
	}

	/**
	 * Sanitize relationships array.
	 *
	 * @todo
	 * @since 1.0.0
	 * @param array $relationships Default empty array.
	 * @return array
	 */
	private function sanitize_relationships( $relationships = array() ) {
		return array_filter( $relationships );
	}

	/**
	 * Sanitize the extra string.
	 *
	 * @since 2.1.0
	 * @param string $value
	 * @return string
	 */
	private function sanitize_extra( $value = '' ) {

		// Default return value
		$retval = '';

		// Allowed extra values
		$allowed_extras = array(
			'AUTO_INCREMENT',
			'ON UPDATE CURRENT_TIMESTAMP',

			// See: special_args()
			'SERIAL',
			'SERIAL DEFAULT VALUE',
		);

		// Always uppercase
		$value = strtoupper( $value );

		// Set return value if allowed
		if ( in_array( $value, $allowed_extras, true ) ) {
			$retval = $value;
		}

		// Return
		return $retval;
	}

	/**
	 * Sanitize the default value.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses validate()
	 * @param int|string|null $default
	 * @return int|string|null
	 */
	private function sanitize_default( $default = '' ) {
		return $this->validate( $default );
	}

	/**
	 * Sanitize the pattern string.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Falls back to using is_ methods if invalid param
	 * @param string $pattern Default '%s'. Allowed values: %s, %d, $f
	 * @return string Default '%s'.
	 */
	private function sanitize_pattern( $pattern = '%s' ) {

		// Allowed patterns
		$allowed_patterns = array(
			'%s', // String
			'%d', // Integer (decimal)
			'%f', // Float
		);

		// Return pattern if allowed
		if ( in_array( $pattern, $allowed_patterns, true ) ) {
			return $pattern;
		}

		// Default string
		$retval = '%s';

		// Integer
		if ( $this->is_int() ) {
			$retval = '%d';

		// Float
		} elseif ( $this->is_decimal() ) {
			$retval = '%f';
		}

		// Return
		return $retval;
	}

	/**
	 * Sanitize the validation callback.
	 *
	 * This method accepts a function or method, and will return it if it is
	 * callable. If it is not callable, the best fallback callback is
	 * calculated based on varying column properties.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Explicit support for decimal, int, and numeric types.
	 * @param string $callback Default empty string. A callable PHP function
	 *                         name or method.
	 * @return string The most appropriate callback function for the value.
	 */
	private function sanitize_validation( $callback = '' ) {

		// Return callback if it's callable
		if ( is_callable( $callback ) ) {
			return $callback;
		}

		// UUID special column
		if ( true === $this->uuid ) {
			$callback = array( $this, 'validate_uuid' );

		// Datetime explicit fallback
		} elseif ( $this->is_type( 'datetime' ) ) {
			$callback = array( $this, 'validate_datetime' );

		// Intval fallback
		} elseif ( $this->is_int() ) {
			$callback = array( $this, 'validate_int' );

		// Decimal fallback
		} elseif ( $this->is_decimal() ) {
			$callback = array( $this, 'validate_decimal' );

		// Numeric fallback
		} elseif ( $this->is_numeric() ) {
			$callback = array( $this, 'validate_numeric' );

		// Unknown text, string, or other...
		} else {
			$callback = 'wp_kses_data';
		}

		// Return the callback
		return $callback;
	}

	/** Public Validators *****************************************************/

	/**
	 * Validate a value.
	 *
	 * Used by Column::sanitize_default() and Query to prevent invalid and
	 * unexpected values from being saved in the database.
	 *
	 * @since 2.1.0
	 * @param int|string|null $value   Default empty string. Value to validate.
	 * @param int|string|null $default Default empty string. Fallback if invalid.
	 * @return int|string|null
	 */
	public function validate( $value = '', $default = '' ) {

		// Check if a literal null value is allowed
		$value = $this->validate_null( $value );

		// Return null if allowed
		if ( null === $value ) {
			return null;
		}

		// Return the callback (already sanitized as callable)
		if ( ! empty( $this->validate ) ) {
			return call_user_func( $this->validate, $value );
		}

		// Return the default
		return $default;
	}

	/**
	 * Validate a null value.
	 *
	 * Will return the $default if $allow_null is false.
	 *
	 * @since 2.1.0
	 * @param int|string|null $value Default empty string.
	 * @return int|string|null
	 */
	public function validate_null( $value = '' ) {

		// Value is null
		if ( null === $value ) {

			// If null is allowed, return it
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

		// Return
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
	 * See: wpdb::set_sql_mode()
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Add support for CURRENT_TIMESTAMP.
	 * @param string $value Default ''. A datetime value that needs validating.
	 * @return string A valid datetime value.
	 */
	public function validate_datetime( $value = '' ) {

		// Default empty datetime (value with NO_ZERO_DATE off)
		$default_empty = '0000-00-00 00:00:00';

		$fallback = false;

		// Handle current_timestamp MySQL constant
		if ( 'CURRENT_TIMESTAMP' === strtoupper( $value ) ) {
			$value = 'CURRENT_TIMESTAMP';

		// Fallback if "empty" value
		} elseif ( empty( $value ) || ( $default_empty === $value ) ) {
			$fallback = true;

		// All other values
		} else {

			// Check if valid $value
			$timestamp = strtotime( $value );

			// Format if valid
			if ( false !== $timestamp ) {
				$value = gmdate( 'Y-m-d H:i:s', $timestamp );

			// Fallback if invalid
			} else {
				$fallback = true;
			}
		}

		// Fallback to $default or empty string
		if ( $fallback ) {
			$value = (string) $this->default;
		}

		// Return the validated value
		return $value;
	}

	/**
	 * Validate a decimal value.
	 *
	 * Default decimal position is '18,9' for currencies, so that rounding can
	 * be done inside of the application layer and outside of MySQL.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses: validate_numeric().
	 * @param int|string $value    Default empty string. The decimal value to validate.
	 * @param int        $decimals Default 9. The number of decimal points to accept.
	 * @return float Formatted to the number of decimals specified
	 */
	public function validate_decimal( $value = 0, $decimals = 9 ) {

		// Protect against non-numeric decimals
		if ( ! is_numeric( $decimals ) ) {
			$decimals = 9;
		}

		// Validate & return
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
	 * @since 2.1.0
	 * @param int|string $value    Default empty string. The numeric value to validate.
	 * @param int|bool   $decimals Default false. Decimal position will be used, or 0.
	 * @return float
	 */
	public function validate_numeric( $value = 0, $decimals = false ) {

		// Protect against non-numeric values
		if ( ! is_numeric( $value ) ) {
			$value = ( $value !== $this->default )
				? $this->default
				: 0;
		}

		// Is the value negative and allowed to be?
		$negative_exponent = ( ( $value < 0 ) && ! empty( $this->unsigned ) )
			? -1
			: 1;

		// Only numbers and period
		$value = preg_replace( '/[^0-9\.]/', '', (string) $value );

		// Attempt to find the decimal position
		if ( false === $decimals ) {

			// Look for period
			$period   = strpos( $value, '.' );

			// Period position, or 0
			$decimals = ( false !== $period )
				? $period
				: 0;
		}

		// Format to number of decimals
		$formatted = number_format( (float) $value, (int) $decimals, '.', '' );

		// Adjust for negative values
		$retval = ( $formatted * $negative_exponent );

		// Return
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
	 * @since 2.1.0
	 * @param int $value Default zero.
	 * @return int
	 */
	public function validate_int( $value = 0 ) {
		return (int) $this->validate_numeric( $value, false );
	}

	/**
	 * Validate a UUID.
	 *
	 * This uses the v4 algorithm to generate a UUID that is used to uniquely
	 * and universally identify a given database row without any direct
	 * connection or correlation to the data in that row.
	 *
	 * From http://php.net/manual/en/function.uniqid.php#94959
	 *
	 * @since 1.0.0
	 * @param string $value The UUID value (empty on insert, string on update)
	 * @return string Generated UUID.
	 */
	public function validate_uuid( $value = '' ) {

		// Default URN UUID prefix
		$prefix = 'urn:uuid:';

		// Bail if not empty and correctly prefixed
		// (UUIDs should _never_ change once they are set)
		if ( ! empty( $value ) && ( 0 === strpos( $value, $prefix ) ) ) {
			return $value;
		}

		// Put the pieces together
		$value = sprintf( "{$prefix}%04x%04x-%04x-%04x-%04x-%04x%04x%04x",

			// 32 bits for "time_low"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

			// 16 bits for "time_mid"
			mt_rand( 0, 0xffff ),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand( 0, 0x0fff ) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand( 0, 0x3fff ) | 0x8000,

			// 48 bits for "node"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);

		// Return the new UUID
		return $value;
	}

	/** Table Helpers *********************************************************/

	/**
	 * Return a string representation of this column's properties as part of
	 * the "CREATE" string of a Table.
	 *
	 * @since 2.1.0
	 * @return string
	 */
	public function get_create_string() {

		// Create array
		$create = array();

		// Name
		if ( ! empty( $this->name ) ) {
			$create[] = "`{$this->name}`";
		}

		// Type
		if ( ! empty( $this->type ) ) {

			// Lower looks nicer here for some reason...
			$lower = strtolower( $this->type );

			// Length
			$create[] = ! empty( $this->length ) && is_numeric( $this->length )
				? "{$lower}({$this->length})"
				: $lower;

			// Binary column types
			if ( $this->is_binary() ) {
				$create[] = "CHARACTER SET binary";
				$create[] = "COLLATE binary";

			// Non-binary column types
			} else {

				// Encoding
				if ( ! empty( $this->encoding ) ) {
					$create[] = "CHARACTER SET {$this->encoding}";
				}

				// Collation
				if ( ! empty( $this->collation ) ) {

					// Binary text uses "_bin" collation
					$create[] = ( ! empty( $this->binary ) && $this->is_text() )
						? "COLLATE {$this->collation}_bin"
						: "COLLATE {$this->collation}";
				}
			}
		}

		/**
		 * Note: unsigned Decimals are deprecated in MySQL 8.0.17, and this will
		 *       be changed to is_int() at a later date.
		 */
		if ( $this->is_numeric() ) {

			// Unsigned
			if ( ! empty( $this->unsigned ) ) {
				$create[] = 'unsigned';
			}

			// Zerofill
			if ( ! empty( $this->zerofill ) ) {
				$create[] = 'zerofill';
			}
		}

		// Disallow null
		if ( false === $this->allow_null ) {
			$create[] = 'not null';
		}

		// Default supplied, so trust it (for now...)
		if ( ! empty( $this->default ) ) {
			$create[] = "default '{$this->default}'";

		// allow_null with literal null defaults to null
		} elseif ( ( true === $this->allow_null ) && ( null === $this->default ) ) {
			$create[] = "default null";

		// Literal false means no default value
		} elseif ( false !== $this->default ) {

			// Numeric (ints and decimals)
			if ( $this->is_numeric() ) {

				// Default "0" if _not_ autoincrementing (primary)
				if ( ! $this->is_extra( 'AUTO_INCREMENT' ) ) {
					$create[] = "default '0'";
				}

			// Datetime or Timestamp
			} elseif ( $this->is_type( array( 'datetime', 'timestamp' ) ) ) {

				// Using the CURRENT_TIMESTAMP constant
				if ( $this->is_extra( 'ON UPDATE CURRENT_TIMESTAMP' ) ) {
					$create[] = "ON UPDATE current_timestamp()";

				// @todo NO_ZERO_DATE
				} elseif ( $this->is_type( 'datetime' ) ) {
					$create[] = "default '0000-00-00 00:00:00'";
				}

			// All string types (texts and blobs)
			} else {
				$create[] = "default ''";
			}
		}

		// Extra
		if ( ! empty( $this->extra ) ) {
			$create[] = strtoupper( $this->extra );
		}

		// Format return value from create array
		$retval = implode( ' ', $create );

		// Return the create string
		return $retval;
	}
}
