<?php
/**
 * Base Custom Database Table Query Class.
 *
 * @package     Database
 * @subpackage  Query
 * @copyright   Copyright (c) 2021
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */
namespace BerlinDB\Database;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Base class used for querying custom database tables.
 *
 * This class is intended to be extended for each unique database table,
 * including global tables for multisite, and users tables.
 *
 * @since 1.0.0
 *
 * @see Query::__construct() for accepted arguments.
 *
 * @property string $prefix
 * @property string $table_name
 * @property string $table_alias
 * @property string $table_schema
 * @property string $item_name
 * @property string $item_name_plural
 * @property string $item_shape
 * @property string $cache_group
 * @property string $last_changed
 * @property array $columns
 * @property array $query_clauses
 * @property array $request_clauses
 * @property null|Queries\Meta $meta_query
 * @property null|Queries\Date $date_query
 * @property null|Queries\Compare $compare_query
 * @property array $query_vars
 * @property array $query_var_originals
 * @property array $query_var_defaults
 * @property string $query_var_default_value
 * @property array|int $items
 * @property int $found_items
 * @property int $max_num_pages
 * @property string $request
 */
class Query extends Base {

	/** Table Properties ******************************************************/

	/**
	 * Name of the database table to query.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $table_name = '';

	/**
	 * String used to alias the database table in MySQL statement.
	 *
	 * Keep this short, but descriptive. I.E. "tr" for term relationships.
	 *
	 * This is used to avoid collisions with JOINs.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $table_alias = '';

	/**
	 * Name of class used to setup the database schema.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $table_schema = '\\BerlinDB\\Database\\Schema';

	/** Item ******************************************************************/

	/**
	 * Name for a single item.
	 *
	 * Use underscores between words. I.E. "term_relationship"
	 *
	 * This is used to automatically generate hook names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $item_name = 'item';

	/**
	 * Plural version for a group of items.
	 *
	 * Use underscores between words. I.E. "term_relationships"
	 *
	 * This is used to automatically generate hook names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $item_name_plural = 'items';

	/**
	 * Name of class used to turn IDs into first-class objects.
	 *
	 * This is used when looping through return values to guarantee that objects
	 * are the expected class.
	 *
	 * @since 1.0.0
	 * @var   mixed
	 */
	protected $item_shape = '\\BerlinDB\\Database\\Row';

	/** Cache *****************************************************************/

	/**
	 * Group to cache queries and queried items in.
	 *
	 * Use underscores between words. I.E. "some_items"
	 *
	 * Do not use colons: ":". These are reserved for internal use only.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $cache_group = '';

	/**
	 * The last updated time.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $last_changed = '';

	/** Columns ***************************************************************/

	/**
	 * Array of all database column objects.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	protected $columns = array();

	/** Clauses ***************************************************************/

	/**
	 * SQL query clauses.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	protected $query_clauses = array(
		'select'  => '',
		'from'    => '',
		'where'   => array(),
		'groupby' => '',
		'orderby' => '',
		'limits'  => ''
	);

	/**
	 * Request clauses.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	protected $request_clauses = array(
		'select'  => '',
		'from'    => '',
		'where'   => '',
		'groupby' => '',
		'orderby' => '',
		'limits'  => ''
	);

	/**
	 * Meta query container.
	 *
	 * @since 1.0.0
	 * @var   null|object|Queries\Meta
	 */
	protected $meta_query = null;

	/**
	 * Date query container.
	 *
	 * @since 1.0.0
	 * @var   null|object|Queries\Date
	 */
	protected $date_query = null;

	/**
	 * Compare query container.
	 *
	 * @since 1.0.0
	 * @var   null|object|Queries\Compare
	 */
	protected $compare_query = null;

	/** Query Variables *******************************************************/

	/**
	 * Parsed query vars set by the application, possibly filtered and changed.
	 *
	 * This is specifically marked as public, to allow byref actions to change
	 * them from outside the class methods proper and inside filter functions.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	public $query_vars = array();

	/**
	 * Original query vars set by the application.
	 *
	 * These are the original query variables before any filters are applied,
	 * and are the results of merging $query_var_defaults with $query_vars.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	protected $query_var_originals = array();

	/**
	 * Default values for query vars.
	 *
	 * These are computed at runtime based on the registered columns for the
	 * database table this query relates to.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	protected $query_var_defaults = array();

	/**
	 * This private variable temporarily holds onto a random string used as the
	 * default query var value. This is used internally when performing
	 * comparisons, and allows for querying by falsy values.
	 *
	 * @since 1.1.0
	 * @var   string
	 */
	protected $query_var_default_value = '';

	/** Results ***************************************************************/

	/**
	 * List of items retrieved by the query.
	 *
	 * @since 1.0.0
	 * @var   array|int
	 */
	public $items = array();

	/**
	 * The total number of items found by the query.
	 *
	 * This may differ from the item count, depending on the request and whether
	 * 'no_found_rows' is set.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	protected $found_items = 0;

	/**
	 * The number of pages.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	protected $max_num_pages = 0;

	/**
	 * The final SQL string generated by this class.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $request = '';

	/** Methods ***************************************************************/

	/**
	 * Sets up the item query, based on the query vars passed.
	 *
	 * @since 1.0.0
	 *
	 * @param array|string $query {
	 *     Optional. Array or query string of item query parameters.
	 *     Default empty.
	 *
	 *     @type string       $fields            Site fields to return. Accepts 'ids' (returns an array of item IDs)
	 *                                           or empty (returns an array of complete item objects). Default empty.
	 *                                           To do a date query against a field, append the field name with _query
	 *     @type bool         $count             Whether to return a item count (true) or array of item objects.
	 *                                           Default false.
	 *     @type int          $number            Limit number of items to retrieve. Use 0 for no limit.
	 *                                           Default 100.
	 *     @type int          $offset            Number of items to offset the query. Used to build LIMIT clause.
	 *                                           Default 0.
	 *     @type bool         $no_found_rows     Whether to disable the `SQL_CALC_FOUND_ROWS` query.
	 *                                           Default true.
	 *     @type array|string $orderby           Accepts false, an empty array, or 'none' to disable `ORDER BY` clause.
	 *                                           Default '', to primary column ID.
	 *     @type string       $order             How to order retrieved items. Accepts 'ASC', 'DESC'.
	 *                                           Default 'DESC'.
	 *     @type string       $search            Search term(s) to retrieve matching items for.
	 *                                           Default empty.
	 *     @type array        $search_columns    Array of column names to be searched.
	 *                                           Default empty array.
	 *     @type bool         $update_item_cache Whether to prime the cache for found items.
	 *                                           Default false.
	 *     @type bool         $update_meta_cache Whether to prime the meta cache for found items.
	 *                                           Default false.
	 * }
	 */
	public function __construct( $query = array() ) {

		// Setup
		$this->setup();

		// Maybe execute a query if arguments were passed
		if ( ! empty( $query ) ) {
			$this->query( $query );
		}
	}

	/**
	 * Setup the class variables.
	 *
	 * This method is public to allow subclasses to override it, and allow for
	 * it to be called directly on a class that has already been used.
	 *
	 * @since 2.1.0
	 */
	public function setup() {
		$this->set_alias();
		$this->set_prefix();
		$this->set_columns();
		$this->set_item_shape();
		$this->set_query_var_defaults();
	}

	/**
	 * Queries the database and retrieves items or counts.
	 *
	 * This method is public to allow subclasses to perform JIT manipulation
	 * of the parameters passed into it.
	 *
	 * @since 1.0.0
	 *
	 * @param array|string $query Array or URL query string of parameters.
	 * @return array|int List of items, or number of items when 'count' is passed as a query var.
	 */
	public function query( $query = array() ) {
		$this->parse_query( $query );

		return $this->get_items();
	}

	/** Private Setters *******************************************************/

	/**
	 * Set the time when items were last changed.
	 *
	 * We set this locally to avoid inconsistencies between method calls.
	 *
	 * @since 1.0.0
	 */
	private function set_last_changed() {
		$this->last_changed = microtime();
	}

	/**
	 * Set up the table alias if not already set in the class.
	 *
	 * This happens before prefixes are applied.
	 *
	 * @since 1.0.0
	 */
	private function set_alias() {
		if ( empty( $this->table_alias ) ) {
			$this->table_alias = $this->first_letters( $this->table_name );
		}
	}

	/**
	 * Prefix table names, cache groups, and other things.
	 *
	 * This is to avoid conflicts with other plugins or themes that might be
	 * doing their own things.
	 *
	 * @since 1.0.0
	 */
	private function set_prefix() {
		$this->table_name  = $this->apply_prefix( $this->table_name       );
		$this->table_alias = $this->apply_prefix( $this->table_alias      );
		$this->cache_group = $this->apply_prefix( $this->cache_group, '-' );
	}

	/**
	 * Set columns objects.
	 *
	 * @since 1.0.0
	 */
	private function set_columns() {

		// Bail if no table schema
		if ( ! class_exists( $this->table_schema ) ) {
			return;
		}

		// Invoke a new table schema class
		$schema = new $this->table_schema;

		// Maybe get the column objects
		if ( ! empty( $schema->columns ) ) {
			$this->columns = $schema->columns;
		}
	}

	/**
	 * Set the default item shape if none exists.
	 *
	 * @since 1.0.0
	 */
	private function set_item_shape() {
		if ( empty( $this->item_shape ) || ! class_exists( $this->item_shape ) ) {
			$this->item_shape = __NAMESPACE__ . '\\Row';
		}
	}

	/**
	 * Set default query vars based on columns.
	 *
	 * @since 1.0.0
	 */
	private function set_query_var_defaults() {

		// Default query variable value
		$this->query_var_default_value = function_exists( 'random_bytes' )
			? $this->apply_prefix( bin2hex( random_bytes( 18 ) ) )
			: $this->apply_prefix( uniqid( '_', true ) );

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Default query variables
		$this->query_var_defaults = array(
			'fields'            => '',
			'number'            => 100,
			'offset'            => '',
			'orderby'           => $primary,
			'order'             => 'DESC',
			'groupby'           => '',
			'search'            => '',
			'search_columns'    => array(),
			'count'             => false,

			// Disable SQL_CALC_FOUND_ROWS?
			'no_found_rows'     => true,

			// Queries
			'meta_query'        => null, // See Queries\Meta
			'date_query'        => null, // See Queries\Date
			'compare_query'     => null, // See Queries\Compare

			// Caching
			'update_item_cache' => true,
			'update_meta_cache' => true
		);

		// Direct column names
		$names = $this->get_column_names();
		foreach ( $names as $name ) {
			$this->query_var_defaults[ $name ] = $this->query_var_default_value;
		}

		// Possible ins
		$possible_ins = $this->get_columns( array( 'in' => true ), 'and', 'name' );
		foreach ( $possible_ins as $in ) {
			$key = "{$in}__in";
			$this->query_var_defaults[ $key ] = $this->query_var_default_value;
		}

		// Possible not ins
		$possible_not_ins = $this->get_columns( array( 'not_in' => true ), 'and', 'name' );
		foreach ( $possible_not_ins as $in ) {
			$key = "{$in}__not_in";
			$this->query_var_defaults[ $key ] = $this->query_var_default_value;
		}

		// Possible dates
		$possible_dates = $this->get_columns( array( 'date_query' => true ), 'and', 'name' );
		foreach ( $possible_dates as $date ) {
			$key = "{$date}_query";
			$this->query_var_defaults[ $key ] = $this->query_var_default_value;
		}
	}

	/**
	 * Set the request clauses.
	 *
	 * @since 1.0.0
	 *
	 * @param array $clauses
	 */
	private function set_request_clauses( $clauses = array() ) {

		// Found rows
		$found_rows = empty( $this->query_vars['no_found_rows'] )
			? 'SQL_CALC_FOUND_ROWS'
			: '';

		// Fields
		$fields     = ! empty( $clauses['fields'] )
			? $clauses['fields']
			: '';

		// Join
		$join       = ! empty( $clauses['join'] )
			? $clauses['join']
			: '';

		// Where
		$where      = ! empty( $clauses['where'] )
			? "WHERE {$clauses['where']}"
			: '';

		// Group by
		$groupby    = ! empty( $clauses['groupby'] )
			? "GROUP BY {$clauses['groupby']}"
			: '';

		// Order by
		$orderby    = ! empty( $clauses['orderby'] )
			? "ORDER BY {$clauses['orderby']}"
			: '';

		// Limits
		$limits     = ! empty( $clauses['limits']  )
			? $clauses['limits']
			: '';

		// Select & From
		$table  = $this->get_table_name();
		$select = "SELECT {$found_rows} {$fields}";
		$from   = "FROM {$table} {$this->table_alias} {$join}";

		// Put query into clauses array
		$this->request_clauses['select']  = $select;
		$this->request_clauses['from']    = $from;
		$this->request_clauses['where']   = $where;
		$this->request_clauses['groupby'] = $groupby;
		$this->request_clauses['orderby'] = $orderby;
		$this->request_clauses['limits']  = $limits;
	}

	/**
	 * Set the request.
	 *
	 * @since 1.0.0
	 */
	private function set_request() {
		$filtered      = array_filter( $this->request_clauses );
		$clauses       = array_map( 'trim', $filtered );
		$this->request = implode( ' ', $clauses );
	}

	/**
	 * Set items by mapping them through the single item callback.
	 *
	 * @since 1.0.0
	 * @param array $item_ids
	 */
	private function set_items( $item_ids = array() ) {

		// Bail if counting, to avoid shaping items
		if ( ! empty( $this->query_vars['count'] ) ) {
			$this->items = $item_ids;
			return;
		}

		// Shape item IDs
		$item_ids = array_map( array( $this, 'shape_item_id' ), $item_ids );

		// Prime item caches
		$this->prime_item_caches( $item_ids );

		// Shape the items
		$this->items = $this->shape_items( $item_ids );
	}

	/**
	 * Populates found_items and max_num_pages properties for the current query
	 * if the limit clause was used.
	 *
	 * @todo: make safe for MySQL 8
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $item_ids Optional array of item IDs
	 */
	private function set_found_items( $item_ids = array() ) {

		// Bail if items are empty
		if ( empty( $item_ids ) ) {
			return;
		}

		// Default to number of item IDs
		$this->found_items = count( (array) $item_ids );

		// Count query
		if ( ! empty( $this->query_vars['count'] ) ) {

			// Not grouped
			if ( is_numeric( $item_ids ) && empty( $this->query_vars['groupby'] ) ) {
				$this->found_items = intval( $item_ids );
			}

		// Not a count query, and number of rows is limited
		} elseif (
			is_array( $item_ids )
			&&
			(
				! empty( $this->query_vars['number'] )
				&&
				empty( $this->query_vars['no_found_rows'] )
			)
		) {

			// Get the found items SQL
			$found_items_query = $this->filter_found_items_query();

			// Maybe query for found items
			if ( ! empty( $found_items_query ) ) {
				$this->found_items = (int) $this->get_db()->get_var( $found_items_query );
			}
		}
	}

	/** Public Setters ********************************************************/

	/**
	 * Set a query var, to both defaults and request arrays.
	 *
	 * This method is used to expose the private query_vars array to hooks,
	 * allowing them to manipulate query vars just-in-time.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key
	 * @param string $value
	 */
	public function set_query_var( $key = '', $value = '' ) {
		$this->query_var_defaults[ $key ] = $value;
		$this->query_vars[ $key ]         = $value;
	}

	/**
	 * Check whether a query variable strictly equals the unique default
	 * starting value.
	 *
	 * @since 1.1.0
	 * @param string $key
	 * @return bool
	 */
	public function is_query_var_default( $key = '' ) {
		return (bool) ( $this->query_vars[ $key ] === $this->query_var_default_value );
	}

	/** Private Getters *******************************************************/

	/**
	 * Pass-through method to return a new Meta object.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args See Queries\Meta
	 *
	 * @return Queries\Meta
	 */
	private function get_meta_query( $args = array() ) {
		return new Queries\Meta( $args );
	}

	/**
	 * Pass-through method to return a new Compare object.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args See Queries\Compare
	 *
	 * @return Queries\Compare
	 */
	private function get_compare_query( $args = array() ) {
		return new Queries\Compare( $args );
	}

	/**
	 * Pass-through method to return a new Queries\Date object.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args See Queries\Date
	 *
	 * @return Queries\Date
	 */
	private function get_date_query( $args = array() ) {
		return new Queries\Date( $args );
	}

	/**
	 * Return the current time as a UTC timestamp.
	 *
	 * This is used by add_item() and update_item() and is equivalent to
	 * CURRENT_TIMESTAMP in MySQL, but for the PHP server (not the MySQL one)
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_current_time() {
		return gmdate( "Y-m-d\TH:i:s\Z" );
	}

	/**
	 * Return the literal table name (with prefix) from the database interface.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_table_name() {
		return $this->get_db()->{$this->table_name};
	}

	/**
	 * Return array of column names.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_column_names() {
		return array_flip( $this->get_columns( array(), 'and', 'name' ) );
	}

	/**
	 * Return the primary database column name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Default "id", Primary column name if not empty
	 */
	private function get_primary_column_name() {
		return $this->get_column_field( array( 'primary' => true ), 'name', 'id' );
	}

	/**
	 * Get a column from an array of arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $args    Arguments to get a column by.
	 * @param string $field   Field to get from a column.
	 * @param mixed  $default Default to use if no field is set.
	 * @return mixed Column object, or false
	 */
	private function get_column_field( $args = array(), $field = '', $default = false ) {

		// Get the column
		$column = $this->get_column_by( $args );

		// Return field, or default
		return isset( $column->{$field} )
			? $column->{$field}
			: $default;
	}

	/**
	 * Get a column from an array of arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Arguments to get a column by.
	 * @return mixed Column object, or false
	 */
	private function get_column_by( $args = array() ) {

		// Filter columns
		$filter = $this->get_columns( $args );

		// Return column or false
		return ! empty( $filter )
			? reset( $filter )
			: false;
	}

	/**
	 * Get columns from an array of arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array       $args     Arguments to filter columns by.
	 * @param string      $operator Optional. The logical operation to perform.
	 * @param bool|string $field    Optional. A field from the object to place
	 *                              instead of the entire object. Default false.
	 * @return array Array of column.
	 */
	private function get_columns( $args = array(), $operator = 'and', $field = false ) {

		// Filter columns
		$filter = wp_filter_object_list( $this->columns, $args, $operator, $field );

		// Return columns or empty array
		return ! empty( $filter )
			? array_values( $filter )
			: array();
	}

	/**
	 * Get a field from columns, by the intersection of key and values.
	 *
	 * This is used for retrieving an array of column fields by an array of
	 * other field values.
	 *
	 * Uses get_column_field() to allow passing of a default value.
	 *
	 * @since 2.1.0
	 * @param string $key     Name of property to compare $values to.
	 * @param array  $values  Values to get a column by.
	 * @param string $field   Field to get from a column.
	 * @param mixed  $default Default to use if no field is set.
	 * @return array
	 */
	private function get_columns_field_by( $key = '', $values = array(), $field = '', $default = false ) {

		// Default return value
		$retval = array();

		// Bail if no values
		if ( empty( $values ) ) {
			return $retval;
		}

		// Allow scalar values
		if ( is_scalar( $values ) ) {
			$values = array( $values );
		}

		// Get the column fields
		foreach ( $values as $value ) {
			$args     = array( $key => $value );
			$retval[] = $this->get_column_field( $args, $field, $default );
		}

		// Return fields of columns
		return $retval;
	}

	/**
	 * Get a single database row by any column and value, skipping cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column_name  Name of database column
	 * @param mixed  $column_value Value to query for
	 * @return object|false False if empty/error, Object if successful
	 */
	private function get_item_raw( $column_name = '', $column_value = '' ) {

		// Bail if no name or value
		if ( empty( $column_name ) || empty( $column_value ) ) {
			return false;
		}

		// Bail if values aren't query'able
		if ( ! is_string( $column_name ) || ! is_scalar( $column_value ) ) {
			return false;
		}

		// Get query parts
		$table   = $this->get_table_name();
		$pattern = $this->get_column_field( array( 'name' => $column_name ), 'pattern', '%s' );

		// Query database
		$query   = "SELECT * FROM {$table} WHERE {$column_name} = {$pattern} LIMIT 1";
		$select  = $this->get_db()->prepare( $query, $column_value );
		$result  = $this->get_db()->get_row( $select );

		// Bail on failure
		if ( ! $this->is_success( $result ) ) {
			return false;
		}

		// Return row
		return $result;
	}

	/**
	 * Retrieves a list of items matching the query vars.
	 *
	 * @since 1.0.0
	 *
	 * @return array|int List of items, or number of items when 'count' is passed as a query var.
	 */
	private function get_items() {

		/**
		 * Fires before object items are retrieved.
		 *
		 * @since 1.0.0
		 *
		 * @param Query &$this Current instance of Query, passed by reference.
		 */
		do_action_ref_array(
			$this->apply_prefix( "pre_get_{$this->item_name_plural}" ),
			array(
				&$this
			)
		);

		// Never limit, never update item/meta caches when counting
		if ( ! empty( $this->query_vars['count'] ) ) {
			$this->query_vars['number']            = false;
			$this->query_vars['no_found_rows']     = true;
			$this->query_vars['update_item_cache'] = false;
			$this->query_vars['update_meta_cache'] = false;
		}

		// Check the cache
		$cache_key   = $this->get_cache_key();
		$cache_value = $this->cache_get( $cache_key, $this->cache_group );

		// No cache value
		if ( false === $cache_value ) {
			$item_ids = $this->get_item_ids();

			// Set the number of found items
			$this->set_found_items( $item_ids );

			// Format the cached value
			$cache_value = array(
				'item_ids'    => $item_ids,
				'found_items' => intval( $this->found_items ),
			);

			// Add value to the cache
			$this->cache_add( $cache_key, $cache_value, $this->cache_group );

		// Value exists in cache
		} else {
			$item_ids          = $cache_value['item_ids'];
			$this->found_items = intval( $cache_value['found_items'] );
		}

		// Pagination
		if ( ! empty( $this->found_items ) && ! empty( $this->query_vars['number'] ) ) {
			$this->max_num_pages = (int) ceil( $this->found_items / $this->query_vars['number'] );
		}

		// Cast to int if not grouping counts
		if ( ! empty( $this->query_vars['count'] ) && empty( $this->query_vars['groupby'] ) ) {
			$item_ids = intval( $item_ids );
		}

		// Set items from IDs
		$this->set_items( $item_ids );

		// Return array of items
		return $this->items;
	}

	/**
	 * Used internally to get a list of item IDs matching the query vars.
	 *
	 * @since 1.0.0
	 *
	 * @return mixed An array of item IDs if a full query. A single count of
	 *               item IDs if a count query.
	 */
	private function get_item_ids() {

		// Parse all $query_vars
		$this->parse_query_vars();

		// Order & Order By
		$order   = $this->parse_order( $this->query_vars['order'] );
		$orderby = $this->parse_orderby( $order );

		// Where & Join
		$where   = $this->parse_where( $this->query_clauses['where'] );
		$join    = $this->parse_join( $this->query_clauses['join'] );

		// Group by
		$groupby = $this->parse_groupby( $this->query_vars['groupby'] );

		// Fields
		$fields  = $this->parse_fields( $this->query_vars['fields'] );

		// Limits
		$limits  = $this->parse_limits(
			$this->query_vars['number'],
			$this->query_vars['offset']
		);

		// Setup the query array (compact() is too opaque here)
		$query = array(
			'fields'  => $fields,
			'join'    => $join,
			'where'   => $where,
			'orderby' => $orderby,
			'limits'  => $limits,
			'groupby' => $groupby
		);

		// Filter the query clauses
		$clauses = $this->filter_query_clauses( $query );

		// Setup request
		$this->set_request_clauses( $clauses );
		$this->set_request();

		// Return count
		if ( ! empty( $this->query_vars['count'] ) ) {

			// Get vars or results
			$retval = empty( $this->query_vars['groupby'] )
				? $this->get_db()->get_var( $this->request )
				: $this->get_db()->get_results( $this->request, ARRAY_A );

			// Return vars or results
			return $retval;
		}

		// Get IDs
		$item_ids = $this->get_db()->get_col( $this->request );

		// Return parsed IDs
		return wp_parse_list( $item_ids );
	}

	/**
	 * Used internally to generate an SQL string for searching across multiple
	 * columns.
	 *
	 * @since 1.0.0
	 *
	 * @param string $string       Search string.
	 * @param array  $column_names Columns to search.
	 * @return string Search SQL.
	 */
	private function get_search_sql( $string = '', $column_names = array() ) {

		// Bail if malformed string
		if ( empty( $string ) || ! is_scalar( $string ) ) {
			return '';
		}

		// Bail if malformed columns
		if ( empty( $column_names ) || ! is_array( $column_names ) ) {
			return '';
		}

		// Array or String
		$like = ( false !== strpos( $string, '*' ) )
			? '%' . implode( '%', array_map( array( $this->get_db(), 'esc_like' ), explode( '*', $string ) ) ) . '%'
			: '%' . $this->get_db()->esc_like( $string ) . '%';

		// Default array
		$searches = array();

		// Build search SQL
		foreach ( $column_names as $column ) {
			$searches[] = $this->get_db()->prepare( "{$column} LIKE %s", $like );
		}

		// Concatinate
		$values = implode( ' OR ', $searches );
		$retval = '(' . $values . ')';

		// Return the clause
		return $retval;
	}

	/**
	 * Used internally to generate the SQL string for IN and NOT IN clauses.
	 *
	 * The $values being passed in should not be validated, and they will be
	 * escaped before they are concatenated together and returned as a string.
	 *
	 * @since 2.1.0
	 *
	 * @param string       $column_name Column name.
	 * @param array|string $values      List of values.
	 * @param bool         $wrap        To wrap in parenthesis.
	 * @param string       $pattern     Pattern to prepare with.
	 *
	 * @return string Escaped/prepared SQL, possibly wrapped in parenthesis.
	 */
	private function get_in_sql( $column_name = '', $values = array(), $wrap = true, $pattern = '' ) {

		// Default return value
		$retval = '';

		// Bail if no values or column name
		if ( empty( $values ) || empty( $column_name ) ) {
			return $retval;
		}

		// Get the column pattern
		$pattern  = empty( $pattern ) || ! is_string( $pattern )
			? $this->get_column_field( array( 'name' => $column_name ), 'pattern', '%s' )
			: $pattern;
		$count    = count( $values );
		$patterns = array_fill( 0, $count, $pattern );

		// Escape & prepare
		$sql      = implode( ', ', $patterns );
		$values   = $this->get_db()->_escape( $values );
		$retval   = $this->get_db()->prepare( $sql, $values );

		// Wrap them in parenthesis
		if ( true === $wrap ) {
			$retval = "({$retval})";
		}

		// Return in SQL
		return $retval;
	}

	/** Private Parsers *******************************************************/

	/**
	 * Parses arguments passed to the item query with default query parameters.
	 *
	 * @since 1.0.0
	 *
	 * @see Query::__construct()
	 *
	 * @param array|string $query Array or string of Query arguments.
	 */
	private function parse_query( $query = array() ) {

		// Setup the query_vars_original var
		$this->query_var_originals = wp_parse_args( $query );

		// Setup the query_vars parsed var
		$this->query_vars = wp_parse_args(
			$this->query_var_originals,
			$this->query_var_defaults
		);

		/**
		 * Fires after the item query vars have been parsed.
		 *
		 * @since 1.0.0
		 *
		 * @param Query &$this The Query instance (passed by reference).
		 */
		do_action_ref_array(
			$this->apply_prefix( "parse_{$this->item_name_plural}_query" ),
			array(
				&$this
			)
		);
	}

	/**
	 * Parse the where clauses for all known columns.
	 *
	 * @todo split this method into smaller parts
	 *
	 * @since 1.0.0
	 */
	private function parse_query_vars() {

		// Defaults
		$where = $join = $searchable = $date_query = array();

		// Loop through columns
		foreach ( $this->columns as $column ) {

			// Maybe add name to searchable array
			if ( true === $column->searchable ) {
				$searchable[] = $column->name;
			}

			// Get pattern
			$pattern = $this->get_column_field( array( 'name' => $column->name ), 'pattern', '%s' );

			// Literal column comparison
			if ( false !== $column->by ) {

				// Parse query variable
				$where_id = $column->name;
				$values   = $this->parse_query_var( $this->query_vars, $where_id );

				// Parse item for direct clause.
				if ( false !== $values ) {

					// Convert single item arrays to literal column comparisons
					if ( 1 === count( $values ) ) {
						$statement          = "{$this->table_alias}.{$column->name} = {$pattern}";
						$column_value       = reset( $values );
						$where[ $where_id ] = $this->get_db()->prepare( $statement, $column_value );

					// Implode
					} else {
						$where_id           = "{$where_id}__in";
						$in_values          = $this->get_in_sql( $column->name, $values, true, $pattern );
						$where[ $where_id ] = "{$this->table_alias}.{$column->name} IN {$in_values}";
					}
				}
			}

			// __in
			if ( true === $column->in ) {

				// Parse query var
				$where_id = "{$column->name}__in";
				$values   = $this->parse_query_var( $this->query_vars, $where_id );

				// Parse item for an IN clause.
				if ( false !== $values ) {

					// Convert single item arrays to literal column comparisons
					if ( 1 === count( $values ) ) {
						$statement          = "{$this->table_alias}.{$column->name} = {$pattern}";
						$where_id           = $column->name;
						$column_value       = reset( $values );
						$where[ $where_id ] = $this->get_db()->prepare( $statement, $column_value );

					// Implode
					} else {
						$in_values          = $this->get_in_sql( $column->name, $values, true, $pattern );
						$where[ $where_id ] = "{$this->table_alias}.{$column->name} IN {$in_values}";
					}
				}
			}

			// __not_in
			if ( true === $column->not_in ) {

				// Parse query var
				$where_id = "{$column->name}__not_in";
				$values   = $this->parse_query_var( $this->query_vars, $where_id );

				// Parse item for a NOT IN clause.
				if ( false !== $values ) {

					// Convert single item arrays to literal column comparisons
					if ( 1 === count( $values ) ) {
						$statement          = "{$this->table_alias}.{$column->name} != {$pattern}";
						$where_id           = $column->name;
						$column_value       = reset( $values );
						$where[ $where_id ] = $this->get_db()->prepare( $statement, $column_value );

					// Implode
					} else {
						$in_values          = $this->get_in_sql( $column->name, $values, true, $pattern );
						$where[ $where_id ] = "{$this->table_alias}.{$column->name} NOT IN {$in_values}";
					}
				}
			}

			// date_query
			if ( true === $column->date_query ) {
				$where_id    = "{$column->name}_query";
				$column_date = $this->query_vars[ $where_id ];

				// Parse item
				if ( ! empty( $column_date ) && ! $this->is_query_var_default( $where_id ) ) {

					// Default arguments
					$defaults = array(
						'column'    => "{$this->table_alias}.{$column->name}",
						'before'    => $column_date,
						'inclusive' => true
					);

					// Default date query
					if ( is_string( $column_date ) ) {
						$date_query[] = $defaults;

					// Array query var
					} elseif ( is_array( $column_date ) ) {

						// Auto-fill column if empty
						if ( empty( $column_date['column'] ) ) {
							$column_date['column'] = $defaults['column'];
						}

						// Add clause to date query
						$date_query[] = $column_date;
					}
				}
			}
		}

		// Maybe search if columns are searchable.
		if ( ! empty( $searchable ) && strlen( $this->query_vars['search'] ) ) {
			$search_columns = array();

			// Intersect against known searchable columns
			if ( ! empty( $this->query_vars['search_columns'] ) ) {
				$search_columns = array_intersect(
					$this->query_vars['search_columns'],
					$searchable
				);
			}

			// Default to all searchable columns
			if ( empty( $search_columns ) ) {
				$search_columns = $searchable;
			}

			/**
			 * Filters the columns to search in a Query search.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $search_columns Array of column names to be searched.
			 * @param string $search         Text being searched.
			 * @param Query  $this           The current Query instance.
			 */
			$search_columns = (array) apply_filters(
				$this->apply_prefix( "{$this->item_name_plural}_search_columns" ),
				$search_columns,
				$this->query_vars['search'],
				$this
			);

			// Add search query clause
			$where['search'] = $this->get_search_sql( $this->query_vars['search'], $search_columns );
		}

		/** Query Classes *****************************************************/

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Get the meta table
		$table   = $this->get_meta_type();

		// Set the " AND " regex pattern
		$and     = '/^\s*AND\s*/';

		// Maybe perform a meta query.
		$meta_query = $this->query_vars['meta_query'];
		if ( ! empty( $meta_query ) && is_array( $meta_query ) ) {
			$this->meta_query = $this->get_meta_query( $meta_query );
			$clauses          = $this->meta_query->get_sql( $table, $this->table_alias, $primary, $this );

			// Not all objects have meta, so make sure this one exists
			if ( false !== $clauses ) {

				// Set join
				if ( ! empty( $clauses['join'] ) ) {
					$join['meta_query'] = $clauses['join'];
				}

				// Set where
				if ( ! empty( $clauses['where'] ) ) {

					// Remove " AND " from query query where clause
					$where['meta_query'] = preg_replace( $and, '', $clauses['where'] );
				}
			}
		}

		// Maybe perform a compare query.
		$compare_query = $this->query_vars['compare_query'];
		if ( ! empty( $compare_query ) && is_array( $compare_query ) ) {
			$this->compare_query = $this->get_compare_query( $compare_query );
			$clauses             = $this->compare_query->get_sql( $table, $this->table_alias, $primary, $this );

			// Not all objects can compare, so make sure this one exists
			if ( false !== $clauses ) {

				// Set join
				if ( ! empty( $clauses['join'] ) ) {
					$join['compare_query'] = $clauses['join'];
				}

				// Set where
				if ( ! empty( $clauses['where'] ) ) {

					// Remove " AND " from query where clause.
					$where['compare_query'] = preg_replace( $and, '', $clauses['where'] );
				}
			}
		}

		// Only do a date query with an array
		$date_query = ! empty( $date_query )
			? $date_query
			: $this->query_vars['date_query'];

		// Maybe perform a date query
		if ( ! empty( $date_query ) && is_array( $date_query ) ) {
			$this->date_query = $this->get_date_query( $date_query );
			$clauses          = $this->date_query->get_sql( $this->table_name, $this->table_alias, $primary, $this );

			// Not all objects are dates, so make sure this one exists
			if ( false !== $clauses ) {

				// Set join
				if ( ! empty( $clauses['join'] ) ) {
					$join['date_query'] = $clauses['join'];
				}

				// Set where
				if ( ! empty( $clauses['where'] ) ) {

					// Remove " AND " from query where clause.
					$where['date_query'] = preg_replace( $and, '', $clauses['where'] );
				}
			}
		}

		// Set where and join clauses, removing possible empties
		$this->query_clauses['where'] = array_filter( $where );
		$this->query_clauses['join']  = array_filter( $join  );
	}

	/**
	 * Parse a single query variable value.
	 *
	 * @since 2.1.0
	 *
	 * @param int|string|array $query_vars
	 * @param string           $key
	 * @return int|string|array False if not set or default.
	 *                          Value if object or array.
	 *                          Attempts to parse a comma-separated string of
	 *                          possible keys or numbers.
	 */
	private function parse_query_var( $query_vars = '', $key = '' ) {

		// Bail if no query vars exist for that ID
		if ( ! isset( $query_vars[ $key ] ) ) {
			return false;
		}

		// Get the value
		$value = $query_vars[ $key ];

		// Bail if equal to the exact default random value
		if ( $value === $this->query_var_default_value ) {
			return false;
		}

		/**
		 * Early return objects, arrays, numerics, integers, or bools.
		 *
		 * These values assume the caller knew what it was doing, and simply
		 * pass themselves through as "parsed" without any extra handling.
		 */
		if (
			is_object( $value )
			||
			is_array( $value )
			||
			is_numeric( $value )
			||
			is_int( $value )
			||
			is_bool( $value )
		) {
			return array( $value );
		}

		/**
		 * Attempt to determine if a string contains a comma separated list of
		 * values that should be split into an array of values for an __in type
		 * of query.
		 */
		if ( is_string( $value ) ) {

			// Bail if string is over 100 chars long
			if ( strlen( $value ) > 100 ) {
				return $value;
			}

			// Contains comma?
			$comma = strpos( $value, ',' );

			// Bail if no comma
			if ( false === $comma ) {
				return array( $value );
			}

			// Contains space?
			$space = strpos( $value, ' ' );

			// Bail if space is before comma
			if ( $space < $comma ) {
				return array( $value );
			}

			// Bail if first comma is more than 20 letters in
			if ( $comma >= 20 ) {
				return array( $value );
			}

			// Split by comma (and maybe spaces)
			return preg_split( '#,\s*#', $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		// Pass the value through
		return array( $value );
	}

	/**
	 * Parse which fields to query for.
	 *
	 * When counting, will add "COUNT(*)" to return value.
	 *
	 * Note: currently this always only includes the Primary column, to more
	 *       predictably hit the cache. This may change is a future version.
	 *
	 * @since 1.0.0
	 *
	 * @param string $fields
	 * @param bool   $alias
	 * @return string
	 */
	private function parse_fields( $fields = '', $alias = true ) {

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Maybe fallback to query_vars
		if ( empty( $fields ) && ! empty( $this->query_vars['fields'] ) ) {
			$fields = $this->query_vars['fields'];
		}

		// Default return value
		$retval = ( true === $alias )
			? "{$this->table_alias}.{$primary}"
			: $primary;

		// If counting, add COUNT(*) instead
		if ( ! empty( $this->query_vars['count'] ) ) {

			// Possible fields to group by
			$groupby_names = $this->parse_groupby( $this->query_vars['groupby'], $alias );

			// Group by or total count
			$retval = ! empty( $groupby_names )
				? "{$groupby_names}, COUNT(*) as count"
				: 'COUNT(*)';
		}

		// Return fields
		return $retval;
	}

	/**
	 * Parses and sanitizes the 'groupby' keys passed into the item query.
	 *
	 * @since 1.0.0
	 *
	 * @param string $groupby
	 * @param bool   $alias
	 * @return string
	 */
	private function parse_groupby( $groupby = '', $alias = true ) {

		// Maybe fallback to query_vars
		if ( empty( $groupby ) && ! empty( $this->query_vars['groupby'] ) ) {
			$groupby = $this->query_vars['groupby'];
		}

		// Bail if empty
		if ( empty( $groupby ) ) {
			return '';
		}

		// Sanitize groupby columns
		$groupby   = (array) array_map( 'sanitize_key', (array) $groupby );

		// Re'flip column names back around
		$columns   = array_flip( $this->get_column_names() );

		// Get the intersection of allowed column names to groupby columns
		$intersect = array_intersect( $groupby, $columns );

		// Bail if invalid column
		if ( empty( $intersect ) ) {
			return '';
		}

		// Default return value
		$retval = array();

		// Maybe prepend table alias to key
		foreach ( $intersect as $key ) {
			$retval[] = ( true === $alias )
				? "{$this->table_alias}.{$key}"
				: $key;
		}

		// Separate sanitized columns
		return implode( ',', array_values( $retval ) );
	}

	/**
	 * Parse the ORDER BY clause.
	 *
	 * @since 1.0.0 As get_order_by
	 * @since 2.1.0 Renamed to parse_orderby
	 *
	 * @param string $order
	 * @return string
	 */
	private function parse_orderby( $order = '' ) {

		// Default orderby primary column
		$parsed  = $this->parse_single_orderby();
		$orderby = "{$parsed} {$order}";

		// Disable ORDER BY if counting, or: 'none', an empty array, or false.
		if (

			! empty( $this->query_vars['count'] )

			||

			in_array( $this->query_vars['orderby'], array( 'none', array(), false ), true )
		) {
			$orderby = '';

		// Ordering by something, so figure it out
		} elseif ( ! empty( $this->query_vars['orderby'] ) ) {

			// Array of keys, or comma separated
			$ordersby = $this->parse_query_var( $this->query_vars, 'orderby' );

			$orderby_array = array();
			$possible_ins  = $this->get_columns( array( 'in'       => true ), 'and', 'name' );
			$sortables     = $this->get_columns( array( 'sortable' => true ), 'and', 'name' );

			// Loop through possible order by's
			foreach ( $ordersby as $_key => $_value ) {

				// Skip if empty
				if ( empty( $_value ) ) {
					continue;
				}

				// Key is numeric
				if ( is_int( $_key ) ) {
					$_orderby = $_value;
					$_item    = $order;

				// Key is string
				} else {
					$_orderby = $_key;
					$_item    = $_value;
				}

				// Skip if not sortable
				if ( ! in_array( $_value, $sortables, true ) ) {
					continue;
				}

				// Parse orderby
				$parsed = $this->parse_single_orderby( $_orderby );

				// Skip if empty
				if ( empty( $parsed ) ) {
					continue;
				}

				// Set if __in
				if ( in_array( $_orderby, $possible_ins, true ) ) {
					$orderby_array[] = "{$parsed} {$order}";
					continue;
				}

				// Append parsed orderby to array
				$orderby_array[] = $parsed . ' ' . $this->parse_order( $_item );
			}

			// Only set if valid orderby
			if ( ! empty( $orderby_array ) ) {
				$orderby = implode( ', ', $orderby_array );
			}
		}

		// Return parsed orderby
		return $orderby;
	}

	/**
	 * Parse all of the where clauses.
	 *
	 * @since 2.1.0
	 * @param array $where
	 * @return string
	 */
	private function parse_where( $where = array() ) {
		return implode( ' AND ', $where );
	}

	/**
	 * Parse all of the join clauses.
	 *
	 * @since 2.1.0
	 * @param array $join
	 * @return string
	 */
	private function parse_join( $join = array() ) {
		return implode( ', ', $join );
	}

	/**
	 * Parses the 'number' and 'offset' keys passed to the item query.
	 *
	 * @since 2.1.0
	 *
	 * @param int $number
	 * @param int $offset
	 * @return string
	 */
	private function parse_limits( $number = 0, $offset = 0 ) {

		// No negative numbers
		$limit  = absint( $number );
		$offset = absint( $offset );
		$retval = '';

		// Only limit & offset if not limit empty
		if ( ! empty( $limit ) ) {
			$retval = ! empty( $offset )
				? "LIMIT {$offset}, {$limit}"
				: "LIMIT {$limit}";
		}

		// Return
		return $retval;
	}

	/**
	 * Parses and sanitizes 'orderby' keys passed to the item query.
	 *
	 * @since 1.0.0
	 *
	 * @param string $orderby Field for the items to be ordered by.
	 * @return string Value to used in the ORDER clause.
	 */
	private function parse_single_orderby( $orderby = '' ) {

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Default to primary column
		if ( empty( $orderby ) ) {
			$orderby = $primary;
		}

		// __in
		if ( false !== strstr( $orderby, '__in' ) ) {
			$column_name = str_replace( '__in', '', $orderby );
			$item_in     = $this->get_in_sql( $column_name, $this->query_vars[ $orderby ], false );
			$parsed      = "FIELD( {$this->table_alias}.{$column_name}, {$item_in} )";

		// Specific column
		} else {

			// Orderby is a literal, sortable column name
			$sortables = $this->get_columns( array( 'sortable' => true ), 'and', 'name' );
			if ( in_array( $orderby, $sortables, true ) ) {
				$parsed = "{$this->table_alias}.{$orderby}";
			}
		}

		// Return parsed value
		return $parsed;
	}

	/**
	 * Parses an 'order' query variable and cast it to 'ASC' or 'DESC' as
	 * necessary.
	 *
	 * @since 1.0.0
	 *
	 * @param string $order The 'order' query variable.
	 * @return string The sanitized 'order' query variable.
	 */
	private function parse_order( $order  = '' ) {

		// Bail if malformed
		if ( empty( $order ) || ! is_string( $order ) ) {
			return 'DESC';
		}

		// Ascending or Descending
		return ( 'ASC' === strtoupper( $order ) )
			? 'ASC'
			: 'DESC';
	}

	/** Private Shapers *******************************************************/

	/**
	 * Shape items into their most relevant objects.
	 *
	 * This will try to use item_shape, but will fallback to a private
	 * method for querying and caching items.
	 *
	 * If using the `fields` parameter, results will have unique shapes based on
	 * exactly what was requested.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items
	 * @return array
	 */
	private function shape_items( $items = array() ) {

		// Force to stdClass if querying for fields
		if ( ! empty( $this->query_vars['fields'] ) ) {
			$this->item_shape = 'stdClass';
		}

		// Default return value
		$retval = array();

		// Loop through items and get each item individually
		if ( ! empty( $items ) ) {
			foreach ( $items as $item ) {
				$retval[] = $this->get_item( $item );
			}
		}

		// Filter the items
		$retval = $this->filter_items( $retval );

		// Maybe return specific fields
		if ( ! empty( $this->query_vars['fields'] ) ) {
			$retval = $this->get_item_fields( $retval, $this->query_vars['fields'] );
		}

		// Return shaped items
		return $retval;
	}

	/**
	 * Get specific item fields based on query_vars['fields'].
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Bails early if empty $fields.
	 *
	 * @param array $items  Array of items to get fields from.
	 * @param array $fields Fields to get from items.
	 * @return array
	 */
	private function get_item_fields( $items = array(), $fields = array() ) {

		// Default return value
		$retval = $items;

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Fallback to query_vars fields
		if ( empty( $fields ) && ! empty( $this->query_vars['fields'] ) ) {
			$fields = $this->query_vars['fields'];
		}

		// Bail if no fields to get
		if ( empty( $fields ) ) {
			return $retval;
		}

		// Sanitize fields
		$fields = (array) array_map( 'sanitize_key', (array) $fields );

		// 'ids' is numerically keyed
		if ( ( 1 === count( $fields ) ) && ( 'ids' === $fields[0] ) ) {
			$retval = wp_list_pluck( $items, $primary );

		// Get fields from
		} else {
			$retval = array();
			$fields = array_flip( $fields );

			// Loop through items and pluck out the fields
			foreach ( $items as $item ) {
				$retval[ $item->{$primary} ] = (object) array_intersect_key( (array) $item, $fields );
			}
		}

		// Return the item fields
		return $retval;
	}

	/**
	 * Shape an item ID from an object, array, or numeric value.
	 *
	 * @since 1.0.0
	 *
	 * @param  array|object|scalar $item
	 * @return int|string
	 */
	private function shape_item_id( $item = 0 ) {

		// Default return value
		$retval  = $item;

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Object item
		if ( is_object( $item ) && isset( $item->{$primary} ) ) {
			$retval = $item->{$primary};

		// Array item
		} elseif ( is_array( $item ) && isset( $item[ $primary ] ) ) {
			$retval = $item[ $primary ];
		}

		// Return the validated item ID
		return $this->validate_item_field( $retval, $primary );
	}

	/**
	 * Validate a single field of an item.
	 *
	 * Calls Column::validate() on the column.
	 *
	 * @since 2.1.0
	 * @param mixed  $value       Value to validate.
	 * @param string $column_name Name of column.
	 * @return mixed A validated value
	 */
	private function validate_item_field( $value = '', $column_name = '' ) {

		// Get the column
		$column = $this->get_column_by( array( 'name' => $column_name ) );

		// Bail if no column found
		if ( empty( $column ) ) {
			return false;
		}

		// Validate
		return $column->validate( $value );
	}

	/** Queries ***************************************************************/

	/**
	 * Get a single database row by the primary column ID, possibly from cache.
	 *
	 * Accepts an integer, object, or array, and attempts to get the ID from it,
	 * then attempts to retrieve that item fresh from the database or cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int|array|object $item_id The ID of the item
	 * @return object|false False if empty/error, Object if successful
	 */
	public function get_item( $item_id = 0 ) {

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item to get by
		if ( empty( $item_id ) ) {
			return false;
		}

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Get item by ID
		return $this->get_item_by( $primary, $item_id );
	}

	/**
	 * Get a single database row by any column and value, possibly from cache.
	 *
	 * Take care to only use this method on columns with unique values,
	 * preferably with a cache group for that column. See: get_item().
	 *
	 * @since 1.0.0
	 *
	 * @param string     $column_name  Name of database column
	 * @param int|string $column_value Value to query for
	 * @return object|false False if empty/error, Object if successful
	 */
	public function get_item_by( $column_name = '', $column_value = '' ) {

		// Default return value
		$retval = false;

		// Bail if no key or value
		if ( empty( $column_name ) || empty( $column_value ) ) {
			return $retval;
		}

		// Bail if name is not a string
		if ( ! is_string( $column_name ) ) {
			return $retval;
		}

		// Bail if value is not scalar (null values also not allowed)
		if ( ! is_scalar( $column_value ) ) {
			return $retval;
		}

		// Get all of the column names
		$columns = $this->get_column_names();

		// Bail if column does not exist
		if ( ! isset( $columns[ $column_name ] ) ) {
			return $retval;
		}

		// Get all of the cache groups
		$groups = $this->get_cache_groups();

		// Check cache
		if ( ! empty( $groups[ $column_name ] ) ) {
			$retval = $this->cache_get( $column_value, $groups[ $column_name ] );
		}

		// Item not cached
		if ( false === $retval ) {

			// Get item by column name & value (from database, not cache)
			$retval = $this->get_item_raw( $column_name, $column_value );

			// Bail on failure
			if ( ! $this->is_success( $retval ) ) {
				return false;
			}

			// Update item cache(s)
			$this->update_item_cache( $retval );
		}

		// Reduce the item
		$retval = $this->reduce_item( 'select', $retval );

		// Return result
		return $this->shape_item( $retval );
	}

	/**
	 * Add an item to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data
	 * @return bool|int
	 */
	public function add_item( $data = array() ) {

		// Default return value
		$retval = false;

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// If data includes primary column, check if item already exists
		if ( ! empty( $data[ $primary ] ) ) {

			// Shape the primary item ID
			$item_id = $this->shape_item_id( $data[ $primary ] );

			// Get item by ID (from database, not cache)
			$item    = $this->get_item_raw( $primary, $item_id );

			// Bail if item already exists
			if ( ! empty( $item ) ) {
				return false;
			}

			// Set data primary ID to newly shaped ID
			$data[ $primary ] = $item_id;
		}

		// Get default values for item (from columns)
		$item = $this->default_item();

		// Unset the primary key if not part of data array (auto-incremented)
		if ( empty( $data[ $primary ] ) ) {
			unset( $item[ $primary ] );
		}

		// Slice data that has columns, and cut out non-keys for meta
		$columns = $this->get_column_names();
		$data    = array_merge( $item, $data );
		$meta    = array_diff_key( $data, $columns );
		$save    = array_intersect_key( $data, $columns );

		// Bail if nothing to save
		if ( empty( $save ) && empty( $meta ) ) {
			return false;
		}

		// Get the current time (maybe used by created/modified)
		$time = $this->get_current_time();

		// If date-created exists, but is empty or default, use the current time
		$created = $this->get_column_by( array( 'created' => true ) );
		if ( ! empty( $created ) && ( empty( $save[ $created->name ] ) || ( $save[ $created->name ] === $created->default ) ) ) {
			$save[ $created->name ] = $time;
		}

		// If date-modified exists, but is empty or default, use the current time
		$modified = $this->get_column_by( array( 'modified' => true ) );
		if ( ! empty( $modified ) && ( empty( $save[ $modified->name ] ) || ( $save[ $modified->name ] === $modified->default ) ) ) {
			$save[ $modified->name ] = $time;
		}

		// Reduce & validate
		$reduce = $this->reduce_item( 'insert', $save );
		$save   = $this->validate_item( $reduce );

		// Try to save
		if ( ! empty( $save ) ) {
			$table       = $this->get_table_name();
			$names       = array_keys( $save );
			$save_format = $this->get_columns_field_by( 'name', $names, 'pattern', '%s' );
			$retval      = $this->get_db()->insert( $table, $save, $save_format );
		}

		// Bail on failure
		if ( ! $this->is_success( $retval ) ) {
			return false;
		}

		// Get the new item ID
		$retval = $this->get_db()->insert_id;

		// Maybe save meta keys
		if ( ! empty( $meta ) ) {
			$this->save_extra_item_meta( $retval, $meta );
		}

		// Update item cache(s)
		$this->update_item_cache( $retval );

		// Transition item data
		$this->transition_item( $retval, $save, array() );

		// Return
		return $retval;
	}

	/**
	 * Copy an item in the database to a new item.
	 *
	 * @since 1.1.0
	 *
	 * @param int|string $item_id
	 * @param array $data
	 * @return bool|int
	 */
	public function copy_item( $item_id = 0, $data = array() ) {

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Shape the primary item ID
		$item_id = $this->shape_item_id( $item_id );

		// Get item by ID (from database, not cache)
		$item    = $this->get_item_raw( $primary, $item_id );

		// Bail if item does not exist
		if ( empty( $item ) ) {
			return false;
		}

		// Cast object to array
		$save = (array) $item;

		// Maybe merge data with original item
		if ( ! empty( $data ) && is_array( $data ) ) {
			$save = array_merge( $save, $data );
		}

		// Unset the primary key
		unset( $save[ $primary ] );

		// Return result of add_item()
		return $this->add_item( $save );
	}

	/**
	 * Update an item in the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id
	 * @param array $data
	 * @return bool
	 */
	public function update_item( $item_id = 0, $data = array() ) {

		// Default return value
		$retval = false;

		// Bail early if no data to update
		if ( empty( $data ) ) {
			return false;
		}

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID
		if ( empty( $item_id ) ) {
			return false;
		}

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Get item to update (from database, not cache)
		$item    = $this->get_item_raw( $primary, $item_id );

		// Bail if item does not exist to update
		if ( empty( $item ) ) {
			return false;
		}

		// Cast as an array for easier manipulation
		$item = (array) $item;

		// Unset the primary key from item & data
		unset(
			$data[ $primary ],
			$item[ $primary ]
		);

		// Slice data that has columns, and cut out non-keys for meta
		$columns = $this->get_column_names();
		$data    = array_diff_assoc( $data, $item );
		$meta    = array_diff_key( $data, $columns );
		$save    = array_intersect_key( $data, $columns );

		// Maybe save meta keys
		if ( ! empty( $meta ) ) {
			$this->save_extra_item_meta( $item_id, $meta );
		}

		// Bail if nothing to save
		if ( empty( $save ) ) {
			return false;
		}

		// If date-modified exists, use the current time
		$modified = $this->get_column_by( array( 'modified' => true ) );
		if ( ! empty( $modified ) ) {
			$save[ $modified->name ] = $this->get_current_time();
		}

		// Reduce & validate
		$reduce = $this->reduce_item( 'update', $save );
		$save   = $this->validate_item( $reduce );

		// Try to update
		if ( ! empty( $save ) ) {
			$table        = $this->get_table_name();
			$where        = array( $primary => $item_id );
			$names        = array_keys( $save );
			$save_format  = $this->get_columns_field_by( 'name', $names,   'pattern', '%s' );
			$where_format = $this->get_columns_field_by( 'name', $primary, 'pattern', '%s' );
			$retval       = $this->get_db()->update( $table, $save, $where, $save_format, $where_format );
		}

		// Bail on failure
		if ( ! $this->is_success( $retval ) ) {
			return false;
		}

		// Update item cache(s)
		$this->update_item_cache( $item_id );

		// Transition item data
		$this->transition_item( $item_id, $save, $item );

		// Return
		return $retval;
	}

	/**
	 * Delete an item from the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id
	 * @return bool
	 */
	public function delete_item( $item_id = 0 ) {

		// Default return value
		$retval = false;

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID
		if ( empty( $item_id ) ) {
			return false;
		}

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Get item by ID (from database, not cache)
		$item = $this->get_item_raw( $primary, $item_id );

		// Bail if item does not exist to delete
		if ( empty( $item ) ) {
			return false;
		}

		// Attempt to reduce this item
		$item = $this->reduce_item( 'delete', $item );

		// Bail if item was reduced to nothing
		if ( empty( $item ) ) {
			return false;
		}

		// Try to delete
		$table        = $this->get_table_name();
		$where        = array( $primary => $item_id );
		$where_format = $this->get_columns_field_by( 'name', $primary, 'pattern', '%s' );
		$retval       = $this->get_db()->delete( $table, $where, $where_format );

		// Bail on failure
		if ( ! $this->is_success( $retval ) ) {
			return false;
		}

		// Clean caches on successful delete
		$this->delete_all_item_meta( $item_id );
		$this->clean_item_cache( $item );

		/**
		 * Fires after an object has been deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $item_id The ID of the item that was deleted.
		 * @param bool  $result  Whether the item was successfully deleted.
		 */
		do_action(
			$this->apply_prefix( "{$this->item_name}_deleted" ),
			$item_id,
			$retval
		);

		// Return
		return $retval;
	}

	/**
	 * Shape an item from the database into the type of object it always wanted
	 * to be when it grew up.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed ID of item, or row from database
	 * @return mixed False on error, Object of single-object class type on success
	 */
	private function shape_item( $item = 0 ) {

		// Get the item from an ID
		if ( is_numeric( $item ) ) {
			$item = $this->get_item( $item );
		}

		// Return the item if it's already shaped
		if ( $item instanceof $this->item_shape ) {
			return $item;
		}

		// Shape the item as needed
		$item = ! empty( $this->item_shape )
			? new $this->item_shape( $item )
			: (object) $item;

		// Return the item object
		return $item;
	}

	/**
	 * Validate an item before it is updated in or added to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item
	 * @return array|false False on error, Array of validated values on success
	 */
	private function validate_item( $item = array() ) {

		// Bail if item is empty or not an array
		if ( empty( $item ) || ! is_array( $item ) ) {
			return $item;
		}

		// Validate all item fields
		foreach ( $item as $key => $value ) {
			$item[ $key ] = $this->validate_item_field( $value, $key );
		}

		// Return the validated item
		return $this->filter_item( $item );
	}

	/**
	 * Reduce an item down to the keys and values the current user has the
	 * appropriate capabilities to select|insert|update|delete.
	 *
	 * Note that internally, this method works with both arrays and objects of
	 * any type, and also resets the key values. It looks weird, but is
	 * currently by design to protect the integrity of the return value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method select|insert|update|delete
	 * @param mixed  $item   Object|Array of keys/values to reduce
	 *
	 * @return mixed Object|Array without keys the current user does not have caps for
	 */
	private function reduce_item( $method = 'update', $item = array() ) {

		// Bail if item is empty
		if ( empty( $item ) ) {
			return $item;
		}

		// Loop through item attributes
		foreach ( $item as $key => $value ) {

			// Get capabilities for this column
			$caps = $this->get_column_field( array( 'name' => $key ), 'caps' );

			// Unset if not explicitly allowed
			if ( empty( $caps[ $method ] ) || ! current_user_can( $caps[ $method ] ) ) {
				if ( is_array( $item ) ) {
					unset( $item[ $key ] );
				} elseif ( is_object( $item ) ) {
					$item->{$key} = null;
				}

			// Set if explicitly allowed
			} elseif ( is_array( $item ) ) {
				$item[ $key ] = $value;
			} elseif ( is_object( $item ) ) {
				$item->{$key} = $value;
			}
		}

		// Return the reduced item
		return $item;
	}

	/**
	 * Return an item comprised of all default values.
	 *
	 * This is used by `add_item()` to populate known default values, to ensure
	 * new item data is always what we expect it to be.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function default_item() {

		// Default return value
		$retval   = array();

		// Get the column names and their defaults
		$names    = $this->get_columns( array(), 'and', 'name'    );
		$defaults = $this->get_columns( array(), 'and', 'default' );

		// Put together an item using default values
		foreach ( $names as $key => $name ) {
			$retval[ $name ] = $defaults[ $key ];
		}

		// Return
		return $retval;
	}

	/**
	 * Transition an item when adding or updating.
	 *
	 * This method takes the data being saved, looks for any columns that are
	 * known to transition between values, and fires actions on them.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id
	 * @param array $new_data
	 * @param array $old_data
	 */
	private function transition_item( $item_id = 0, $new_data = array(), $old_data = array() ) {

		// Look for transition columns
		$columns = $this->get_columns( array( 'transition' => true ), 'and', 'name' );

		// Bail if no columns to transition
		if ( empty( $columns ) ) {
			return;
		}

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID
		if ( empty( $item_id ) ) {
			return;
		}

		// If no old value(s), it's new
		if ( empty( $old_data ) || ! is_array( $old_data ) ) {
			$old_data = $new_data;

			// Set all old values to "new"
			foreach ( $old_data as $key => $value ) {
				$value            = 'new';
				$old_data[ $key ] = $value;
			}
		}

		// Compare
		$keys = array_flip( $columns );
		$new  = array_intersect_key( $new_data, $keys );
		$old  = array_intersect_key( $old_data, $keys );

		// Get the difference
		$diff = array_diff( $new, $old );

		// Bail if nothing is changing
		if ( empty( $diff ) ) {
			return;
		}

		// Do the actions
		foreach ( $diff as $key => $value ) {
			$old_value  = $old_data[ $key ];
			$new_value  = $new_data[ $key ];
			$key_action = $this->apply_prefix( "transition_{$this->item_name}_{$key}" );

			/**
			 * Fires after an object value has transitioned.
			 *
			 * @since 1.0.0
			 *
			 * @param mixed $old_value The value being transitioned FROM.
			 * @param mixed $new_value The value being transitioned TO.
			 * @param int   $item_id   The ID of the item that is transitioning.
			 */
			do_action( $key_action, $old_value, $new_value, $item_id );
		}
	}

	/** Meta ******************************************************************/

	/**
	 * Add meta data to an item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id
	 * @param string     $meta_key
	 * @param string     $meta_value
	 * @param bool       $unique
	 * @return int|false The meta ID on success, false on failure.
	 */
	protected function add_item_meta( $item_id = 0, $meta_key = '', $meta_value = '', $unique = false ) {

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no meta to add
		if ( empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Bail if no meta table exists
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type
		$meta_type = $this->get_meta_type();

		// Return results of adding meta data
		return add_metadata( $meta_type, $item_id, $meta_key, $meta_value, $unique );
	}

	/**
	 * Get meta data for an item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id
	 * @param string     $meta_key
	 * @param bool       $single
	 * @return mixed Single metadata value, or array of values
	 */
	protected function get_item_meta( $item_id = 0, $meta_key = '', $single = false ) {

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no meta was returned
		if ( empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Bail if no meta table exists
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type
		$meta_type = $this->get_meta_type();

		// Return results of getting meta data
		return get_metadata( $meta_type, $item_id, $meta_key, $single );
	}

	/**
	 * Update meta data for an item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id
	 * @param string     $meta_key
	 * @param string     $meta_value
	 * @param string     $prev_value
	 * @return bool True on successful update, false on failure.
	 */
	protected function update_item_meta( $item_id = 0, $meta_key = '', $meta_value = '', $prev_value = '' ) {

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no meta was returned
		if ( empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Bail if no meta table exists
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type
		$meta_type = $this->get_meta_type();

		// Return results of updating meta data
		return update_metadata( $meta_type, $item_id, $meta_key, $meta_value, $prev_value );
	}

	/**
	 * Delete meta data for an item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id
	 * @param string     $meta_key
	 * @param string     $meta_value
	 * @param bool       $delete_all
	 * @return bool True on successful delete, false on failure.
	 */
	protected function delete_item_meta( $item_id = 0, $meta_key = '', $meta_value = '', $delete_all = false ) {

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no meta was returned
		if ( empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Bail if no meta table exists
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type
		$meta_type = $this->get_meta_type();

		// Return results of deleting meta data
		return delete_metadata( $meta_type, $item_id, $meta_key, $meta_value, $delete_all );
	}

	/**
	 * Get registered meta data keys.
	 *
	 * @since 1.0.0
	 *
	 * @param string $object_subtype The sub-type of meta keys
	 *
	 * @return array
	 */
	private function get_registered_meta_keys( $object_subtype = '' ) {

		// Get the object type
		$object_type = $this->get_meta_type();

		// Return the keys
		return get_registered_meta_keys( $object_type, $object_subtype );
	}

	/**
	 * Maybe update meta values on item update/save.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id
	 * @param array      $meta
	 */
	private function save_extra_item_meta( $item_id = 0, $meta = array() ) {

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if there is no bulk meta to save
		if ( empty( $item_id ) || empty( $meta ) ) {
			return;
		}

		// Bail if no meta table exists
		if ( false === $this->get_meta_table_name() ) {
			return;
		}

		// Only save registered keys
		$keys = $this->get_registered_meta_keys();
		$meta = array_intersect_key( $meta, $keys );

		// Bail if no registered meta keys
		if ( empty( $meta ) ) {
			return;
		}

		// Save or delete meta data
		foreach ( $meta as $key => $value ) {
			! empty( $value )
				? $this->update_item_meta( $item_id, $key, $value )
				: $this->delete_item_meta( $item_id, $key );
		}
	}

	/**
	 * Delete all meta data for an item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id
	 */
	private function delete_all_item_meta( $item_id = 0 ) {

		// Shape the item ID
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID
		if ( empty( $item_id ) ) {
			return;
		}

		// Get the meta table name
		$table = $this->get_meta_table_name();

		// Bail if no meta table exists
		if ( empty( $table ) ) {
			return;
		}

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Guess the item ID column for the meta table
		$item_id_column  = $this->apply_prefix( "{$this->item_name}_{$primary}" );
		$item_id_pattern = $this->get_column_field( array( 'name' => $primary ), 'pattern', '%s' );

		// Get meta IDs
		$query    = "SELECT meta_id FROM {$table} WHERE {$item_id_column} = {$item_id_pattern}";
		$prepared = $this->get_db()->prepare( $query, $item_id );
		$meta_ids = $this->get_db()->get_col( $prepared );

		// Bail if no meta IDs to delete
		if ( empty( $meta_ids ) ) {
			return;
		}

		// Get the meta type
		$meta_type = $this->get_meta_type();

		// Delete all meta data for this item ID
		foreach ( $meta_ids as $mid ) {
			delete_metadata_by_mid( $meta_type, $mid );
		}
	}

	/**
	 * Get the meta table for this query.
	 *
	 * Forked from WordPress\_get_meta_table() so it can be more accurately
	 * predicted in a future iteration and default to returning false.
	 *
	 * @since 1.0.0
	 *
	 * @return mixed Table name if exists, False if not
	 */
	private function get_meta_table_name() {

		// Get the meta-type
		$type       = $this->get_meta_type();

		// Append "meta" to end of meta-type
		$table_name = "{$type}meta";

		// Variable'ize the database interface, to use inside empty()
		$db         = $this->get_db();

		// If not empty, return table name
		if ( ! empty( $db->{$table_name} ) ) {
			return $db->{$table_name};
		}

		// Default return false
		return false;
	}

	/**
	 * Get the meta type for this query.
	 *
	 * This method exists to reduce some duplication for now. Future iterations
	 * will likely use Column::relationships to more reliably predict this.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	private function get_meta_type() {
		return $this->apply_prefix( $this->item_name );
	}

	/** Cache *****************************************************************/

	/**
	 * Get cache key from query_vars and query_var_defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group
	 * @return string
	 */
	private function get_cache_key( $group = '' ) {

		// Slice query vars
		$slice = wp_array_slice_assoc( $this->query_vars, array_keys( $this->query_var_defaults ) );

		// Unset `fields` so it does not effect the cache key
		unset( $slice['fields'] );

		// Setup key & last_changed
		$key          = md5( serialize( $slice ) );
		$last_changed = $this->get_last_changed_cache( $group );

		// Concatenate and return cache key
		return "get_{$this->item_name_plural}:{$key}:{$last_changed}";
	}

	/**
	 * Get the cache group, or fallback to the primary one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group
	 * @return string
	 */
	private function get_cache_group( $group = '' ) {

		// Get the primary column
		$primary = $this->get_primary_column_name();

		// Default return value
		$retval  = $this->cache_group;

		// Only allow non-primary groups
		if ( ! empty( $group ) && ( $group !== $primary ) ) {
			$retval = $group;
		}

		// Return the group
		return $retval;
	}

	/**
	 * Get array of which database columns have uniquely cached groups.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_cache_groups() {

		// Return value
		$cache_groups = array();

		// Get the cache groups
		$groups = $this->get_columns( array( 'cache_key' => true ), 'and', 'name' );

		if ( ! empty( $groups ) ) {

			// Get the primary column name
			$primary = $this->get_primary_column_name();

			// Setup return values
			foreach ( $groups as $name ) {
				if ( $primary !== $name ) {
					$cache_groups[ $name ] = "{$this->cache_group}-by-{$name}";
				} else {
					$cache_groups[ $name ] = $this->cache_group;
				}
			}
		}

		// Return cache groups array
		return $cache_groups;
	}

	/**
	 * Maybe prime item & item-meta caches by querying 1 time for all un-cached
	 * items.
	 *
	 * Accepts a single ID, or an array of IDs.
	 *
	 * The reason this accepts only IDs is because it gets called immediately
	 * after an item is inserted in the database, but before items have been
	 * "shaped" into proper objects, so object properties may not be set yet.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item_ids
	 * @param bool  $force
	 *
	 * @return bool False if empty
	 */
	private function prime_item_caches( $item_ids = array(), $force = false ) {

		// Bail if no items to cache
		if ( empty( $item_ids ) ) {
			return false;
		}

		// Accepts single values, so cast to array
		$item_ids = (array) $item_ids;

		/**
		 * Update item caches.
		 *
		 * Uses our own get_non_cached_ids() method to avoid
		 */
		if ( ! empty( $force ) || ! empty( $this->query_vars['update_item_cache'] ) ) {

			// Look for non-cached IDs
			$ids = $this->get_non_cached_ids( $item_ids, $this->cache_group );

			// Bail if IDs are cached
			if ( empty( $ids ) ) {
				return false;
			}

			// Get query parts
			$table   = $this->get_table_name();
			$primary = $this->get_primary_column_name();

			// Query database
			$query   = "SELECT * FROM {$table} WHERE {$primary} IN %s";
			$ids     = $this->get_in_sql( $primary, $ids );
			$prepare = sprintf( $query, $ids );
			$results = $this->get_db()->get_results( $prepare );

			// Update item cache(s)
			$this->update_item_cache( $results );
		}

		/**
		 * Update meta data caches.
		 *
		 * Uses update_meta_cache() because it politely handles all of the
		 * uncached ID logic. This allows us to use the original (and likely
		 * larger) $item_ids array instead of $ids, thus ensuring the everything
		 * is cached according to our expectations.
		 */
		if ( ! empty( $this->query_vars['update_meta_cache'] ) ) {
			$singular = rtrim( $this->table_name, 's' ); // sic
			update_meta_cache( $singular, $item_ids );
		}

		return true;
	}

	/**
	 * Update the cache for an item. Does not update item-meta cache.
	 *
	 * Accepts a single object, or an array of objects.
	 *
	 * The reason this does not accept ID's is because this gets called
	 * after an item is already updated in the database, so we want to avoid
	 * querying for it again. It's just safer this way.
	 *
	 * @since 1.0.0
	 *
	 * @param int|object|array $items Primary ID if int. Row if object. Array
	 *                                of objects if array.
	 */
	private function update_item_cache( $items = array() ) {

		// Maybe query for single item
		if ( is_scalar( $items ) ) {

			// Get the primary column name
			$primary = $this->get_primary_column_name();

			// Shape the primary item ID
			$item_id = $this->shape_item_id( $items );

			// Get item by ID (from database, not cache)
			$items   = $this->get_item_raw( $primary, $item_id );
		}

		// Bail if no items to cache
		if ( empty( $items ) ) {
			return false;
		}

		// Make sure items are an array (without casting objects to arrays)
		if ( ! is_array( $items ) ) {
			$items = array( $items );
		}

		// Get the cache groups
		$groups = $this->get_cache_groups();

		// Loop through all items and cache them
		foreach ( $items as $item ) {

			// Skip if item is not an object
			if ( ! is_object( $item ) ) {
				continue;
			}

			// Loop through groups and set cache
			if ( ! empty( $groups ) ) {
				foreach ( $groups as $key => $group ) {
					$this->cache_set( $item->{$key}, $item, $group );
				}
			}
		}

		// Update last changed
		$this->update_last_changed_cache();
	}

	/**
	 * Clean the cache for an item. Does not clean item-meta.
	 *
	 * Accepts a single object, or an array of objects.
	 *
	 * The reason this does not accept ID's is because this gets called
	 * after an item is already deleted from the database, so it cannot be
	 * queried and may not exist in the cache. It's just safer this way.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $items Single object item, or Array of object items
	 *
	 * @return bool
	 */
	private function clean_item_cache( $items = array() ) {

		// Bail if no items to clean
		if ( empty( $items ) ) {
			return false;
		}

		// Make sure items are an array
		if ( ! is_array( $items ) ) {
			$items = array( $items );
		}

		// Get the cache groups
		$groups = $this->get_cache_groups();

		// Loop through all items and clean them
		foreach ( $items as $item ) {

			// Skip if item is not an object
			if ( ! is_object( $item ) ) {
				continue;
			}

			// Loop through groups and delete cache
			if ( ! empty( $groups ) ) {
				foreach ( $groups as $key => $group ) {
					$this->cache_delete( $item->{$key}, $group );
				}
			}
		}

		// Update last changed
		$this->update_last_changed_cache();

		return true;
	}

	/**
	 * Update the last_changed key for the cache group.
	 *
	 * @since 1.0.0
	 *
	 * @return string The last time a cache group was changed.
	 */
	private function update_last_changed_cache( $group = '' ) {

		// Fallback to microtime
		if ( empty( $this->last_changed ) ) {
			$this->set_last_changed();
		}

		// Set the last changed time for this cache group
		$this->cache_set( 'last_changed', $this->last_changed, $group );

		// Return the last changed time
		return $this->last_changed;
	}

	/**
	 * Get the last_changed key for a cache group.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Cache group. Defaults to $this->cache_group
	 *
	 * @return string The last time a cache group was changed.
	 */
	private function get_last_changed_cache( $group = '' ) {

		// Get the last changed cache value
		$last_changed = $this->cache_get( 'last_changed', $group );

		// Maybe update the last changed value
		if ( false === $last_changed ) {
			$last_changed = $this->update_last_changed_cache( $group );
		}

		// Return the last changed value for the cache group
		return $last_changed;
	}

	/**
	 * Get array of non-cached item IDs.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $item_ids Array of item IDs
	 * @param string $group    Cache group. Defaults to $this->cache_group
	 *
	 * @return array
	 */
	private function get_non_cached_ids( $item_ids = array(), $group = '' ) {

		// Default return value
		$retval = array();

		// Bail if no item IDs
		if ( empty( $item_ids ) ) {
			return $retval;
		}

		// Loop through item IDs
		foreach ( $item_ids as $id ) {

			// Add to return value if not cached
			if ( false === $this->cache_get( $id, $group ) ) {
				$retval[] = $id;
			}
		}

		// Return array of IDs
		return $retval;
	}

	/**
	 * Add a cache value for a key and group.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $value  Cache value.
	 * @param string $group  Cache group. Defaults to $this->cache_group
	 * @param int    $expire Expiration.
	 */
	private function cache_add( $key = '', $value = '', $group = '', $expire = 0 ) {

		// Bail if cache invalidation is suspended
		if ( wp_suspend_cache_addition() ) {
			return;
		}

		// Bail if no cache key
		if ( empty( $key ) ) {
			return;
		}

		// Get the cache group
		$group = $this->get_cache_group( $group );

		// Add to the cache
		wp_cache_add( $key, $value, $group, $expire );
	}

	/**
	 * Get a cache value for a key and group.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group. Defaults to $this->cache_group
	 * @param bool       $force
	 */
	private function cache_get( $key = '', $group = '', $force = false ) {

		// Bail if no cache key
		if ( empty( $key ) ) {
			return;
		}

		// Get the cache group
		$group = $this->get_cache_group( $group );

		// Return from the cache
		return wp_cache_get( $key, $group, $force );
	}

	/**
	 * Set a cache value for a key and group.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $value  Cache value.
	 * @param string $group  Cache group. Defaults to $this->cache_group
	 * @param int    $expire Expiration.
	 */
	private function cache_set( $key = '', $value = '', $group = '', $expire = 0 ) {

		// Bail if cache invalidation is suspended
		if ( wp_suspend_cache_addition() ) {
			return;
		}

		// Bail if no cache key
		if ( empty( $key ) ) {
			return;
		}

		// Get the cache group
		$group = $this->get_cache_group( $group );

		// Update the cache
		wp_cache_set( $key, $value, $group, $expire );
	}

	/**
	 * Delete a cache key for a group.
	 *
	 * @since 1.0.0
	 *
	 * @global bool $_wp_suspend_cache_invalidation
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group. Defaults to $this->cache_group
	 */
	private function cache_delete( $key = '', $group = '' ) {
		global $_wp_suspend_cache_invalidation;

		// Bail if cache invalidation is suspended
		if ( ! empty( $_wp_suspend_cache_invalidation ) ) {
			return;
		}

		// Bail if no cache key
		if ( empty( $key ) ) {
			return;
		}

		// Get the cache group
		$group = $this->get_cache_group( $group );

		// Delete the cache
		wp_cache_delete( $key, $group );
	}

	/** Filters ***************************************************************/

	/**
	 * Filter an item before it is inserted or updated in the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item The item data.
	 * @return array
	 */
	public function filter_item( $item = array() ) {

		/**
		 * Filters an item before it is inserted or updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $item  The item as an array.
		 * @param Query &$this Current instance passed by reference.
		 */
		return (array) apply_filters_ref_array(
			$this->apply_prefix( "filter_{$this->item_name}_item" ),
			array(
				$item,
				&$this
			)
		);
	}

	/**
	 * Filter all shaped items after they are retrieved from the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items The item data.
	 * @return array
	 */
	public function filter_items( $items = array() ) {

		/**
		 * Filters the object query results after they have been shaped.
		 *
		 * @since 1.0.0
		 *
		 * @param array $retval An array of items.
		 * @param Query &$this  Current instance passed by reference.
		 */
		return (array) apply_filters_ref_array(
			$this->apply_prefix( "the_{$this->item_name_plural}" ),
			array(
				$items,
				&$this
			)
		);
	}

	/**
	 * Filter the found items query.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function filter_found_items_query() {

		/**
		 * Filters the query used to retrieve the found item count.
		 *
		 * @since 1.0.0
		 *
		 * @param string $query SQL query. Default 'SELECT FOUND_ROWS()'.
		 * @param Query &$this  Current instance passed by reference.
		 */
		return (string) apply_filters_ref_array(
			$this->apply_prefix( "found_{$this->item_name_plural}_query" ),
			array(
				'SELECT FOUND_ROWS()',
				&$this
			)
		);
	}

	/**
	 * Filter the query clauses before they are parsed into a SQL string.
	 *
	 * @since 2.1.0
	 *
	 * @param array $clauses All of the SQL query clauses.
	 * @return array
	 */
	public function filter_query_clauses( $clauses = array() ) {

		/**
		 * Filters the item query clauses.
		 *
		 * @since 1.0.0
		 *
		 * @param array $clauses An array of query clauses.
		 * @param Query &$this   Current instance passed by reference.
		 */
		return (array) apply_filters_ref_array(
			$this->apply_prefix( "{$this->item_name_plural}_query_clauses" ),
			array(
				$clauses,
				&$this
			)
		);
	}

	/** General ***************************************************************/

	/**
	 * Fetch raw results directly from the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $cols       Columns for `SELECT`.
	 * @param array  $where_cols Where clauses. Each key-value pair in the array
	 *                           represents a column and a comparison.
	 * @param int    $limit      Optional. LIMIT value. Default 25.
	 * @param null   $offset     Optional. OFFSET value. Default null.
	 * @param string $output     Optional. Any of ARRAY_A | ARRAY_N | OBJECT | OBJECT_K constants.
	 *                           Default OBJECT.
	 *                           With one of the first three, return an array of
	 *                           rows indexed from 0 by SQL result row number.
	 *                           Each row is an associative array (column => value, ...),
	 *                           a numerically indexed array (0 => value, ...),
	 *                           or an object. ( ->column = value ), respectively.
	 *                           With OBJECT_K, return an associative array of
	 *                           row objects keyed by the value of each row's
	 *                           first column's value.
	 *
	 * @return array|object|null Database query results.
	 */
	public function get_results( $cols = array(), $where_cols = array(), $limit = 25, $offset = null, $output = OBJECT ) {

		// Parse arguments
		$r = wp_parse_args( $where_cols, array(
			'fields'            => $cols,
			'number'            => $limit,
			'offset'            => $offset,
			'output'            => $output,
			'update_item_cache' => false,
			'update_meta_cache' => false,
		) );

		// Get items
		return $this->query( $r );
	}
}
