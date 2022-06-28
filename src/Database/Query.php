<?php
/**
 * Base Custom Database Table Query Class.
 *
 * @package     Database
 * @subpackage  Query
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
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
 * @property array $schema
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

	/** Schema *************************************************************/

	/**
	 * Schema object.
	 *
	 * A collection of Column and Index objects. Set to private so that it is
	 * not touched directly until this can be vetted and opened up.
	 *
	 * @since 2.1.0
	 * @var   Schema
	 */
	private $schema = null;

	/** Clauses ***************************************************************/

	/**
	 * SQL query clauses.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	protected $query_clauses = array();

	/**
	 * SQL request clauses.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	protected $request_clauses = array();

	/** Query Types ***********************************************************/

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
	 * Random default value for all query vars.
	 *
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
	 * The total number of items found by the SQL query.
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

	/**
	 * Array of items retrieved by the SQL query.
	 *
	 * @since 1.0.0
	 * @var   array|int
	 */
	public $items = array();

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
	 *     @type string  $fields            Site fields to return. Accepts 'ids' (returns an array of item IDs)
	 *                                      or empty (returns an array of complete item objects). Default empty.
	 *                                      To do a date query against a field, append the field name with _query
	 *     @type bool    $count             Return an item count (true) or array of item objects.
	 *                                      Default false.
	 *     @type int     $number            Limit number of items to retrieve. Use 0 for no limit.
	 *                                      Default 100.
	 *     @type int     $offset            Number of items to offset the query. Used to build LIMIT clause.
	 *                                      Default 0.
	 *     @type bool    $no_found_rows     Disable the separate COUNT(*) query.
	 *                                      Default true.
	 *     @type string  $orderby           Accepts false, an empty array, or 'none' to disable `ORDER BY` clause.
	 *                                      Default '', to primary column ID.
	 *     @type string  $order             How to order retrieved items. Accepts 'ASC', 'DESC'.
	 *                                      Default 'DESC'.
	 *     @type string  $search            Search term(s) to retrieve matching items for.
	 *                                      Default empty.
	 *     @type array   $search_columns    Array of column names to be searched.
	 *                                      Default empty array.
	 *     @type bool    $update_item_cache Prime the cache for found items.
	 *                                      Default false.
	 *     @type bool    $update_meta_cache Prime the meta cache for found items.
	 *                                      Default false.
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
	 * Setup class attributes that rely on other properties.
	 *
	 * This method is public to allow subclasses to override it, and allow for
	 * it to be called directly on a class that has already been used.
	 *
	 * @since 2.1.0
	 */
	public function setup() {
		$this->set_alias();
		$this->set_prefixes();
		$this->set_schema();
		$this->set_item_shape();
		$this->set_query_var_defaults();
		$this->set_query_clause_defaults();
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
	 * @return array|int Array of items, or number of items when 'count' is passed as a query var.
	 */
	public function query( $query = array() ) {
		$this->parse_query( $query );

		return $this->get_items();
	}

	/** Private Setters *******************************************************/

	/**
	 * Set up the time when items were last changed.
	 *
	 * Avoids inconsistencies between method calls.
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
	 * Set up prefixes on:
	 * - table name
	 * - table alias
	 * - cache group
	 *
	 * This is to avoid conflicts with other plugins or themes that might be
	 * using the global scope for data and cache storage.
	 *
	 * @since 2.1.0
	 */
	private function set_prefixes() {
		$this->table_name  = $this->apply_prefix( $this->table_name       );
		$this->table_alias = $this->apply_prefix( $this->table_alias      );
		$this->cache_group = $this->apply_prefix( $this->cache_group, '-' );
	}

	/**
	 * Set up the Schema.
	 *
	 * @since 2.1.0
	 */
	private function set_schema() {

		// Bail if no table schema
		if ( ! class_exists( $this->table_schema ) ) {
			return;
		}

		// Invoke a new table schema class
		$this->schema = new $this->table_schema;
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
	 * Set default query clauses.
	 *
	 * @since 2.1.0
	 */
	private function set_query_clause_defaults() {

		// Default query clauses
		$this->query_clauses = array(
			'explain' => '',
			'select'  => '',
			'fields'  => '',
			'count'   => '',
			'from'    => '',
			'join'    => array(),
			'where'   => array(),
			'groupby' => '',
			'orderby' => '',
			'limits'  => ''
		);

		// Default request clauses are empty strings
		$this->request_clauses = array_fill_keys(
			array_keys( $this->query_clauses ),
			''
		);
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

			// Statements
			'explain'           => false,
			'select'            => '',

			// Fields
			'fields'            => '',
			'groupby'           => '',

			// Boundaries
			'number'            => 100,
			'offset'            => '',
			'orderby'           => $primary,
			'order'             => 'DESC',

			// Search
			'search'            => '',
			'search_columns'    => array(),

			// COUNT(*)
			'count'             => false,

			// Disable row count
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
		$names = array_flip( $this->get_column_names() );
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
	 * Set $query_clauses by parsing $query_vars.
	 *
	 * @since 2.1.0
	 */
	private function set_query_clauses() {
		$this->query_clauses = $this->parse_query_vars();
	}

	/**
	 * Set the $request_clauses.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses parse_query_clauses() with support for new clauses.
	 */
	private function set_request_clauses() {
		$this->request_clauses = $this->parse_query_clauses();
	}

	/**
	 * Set the $request.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses parse_request_clauses() on $request_clauses.
	 */
	private function set_request() {
		$this->request = $this->parse_request_clauses();
	}

	/**
	 * Set items by mapping them through the single item callback.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Moved 'count' logic back into get_items().
	 * @param array $item_ids
	 */
	private function set_items( $item_ids = array() ) {

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
	 * @since 1.0.0
	 * @since 2.1.0 Uses filter_found_items_query().
	 *
	 * @param mixed $item_ids Optional array of item IDs
	 */
	private function set_found_items( $item_ids = array() ) {

		// Default to number of item IDs
		$this->found_items = count( (array) $item_ids );

		// Count query
		if ( $this->get_query_var( 'count' ) ) {

			// Not grouped
			if ( is_numeric( $item_ids ) && ! $this->get_query_var( 'groupby' ) ) {
				$this->found_items = (int) $item_ids;
			}

		// Not a count query, and number of rows is limited
		} elseif (
			is_array( $item_ids )
			&&
			(
				$this->get_query_var( 'number' ) && ! $this->get_query_var( 'no_found_rows' )
			)
		) {

			// Override a few request clauses
			$r = wp_parse_args(
				array(
					'count'   => 'COUNT(*)',
					'fields'  => '',
					'limits'  => '',
					'orderby' => ''
				),
				$this->request_clauses
			);

			// Parse the new clauses
			$query = $this->parse_request_clauses( $r );

			// Filter the found items query
			$query = $this->filter_found_items_query( $query );

			// Maybe query for found items
			if ( ! empty( $query ) ) {
				$this->found_items = (int) $this->get_db()->get_var( $query );
			}
		}
	}

	/** Public Setters ********************************************************/

	/**
	 * Set a query var, to both defaults and request arrays.
	 *
	 * This method is used to expose the private $query_vars array to hooks,
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
		return (bool) ( $this->get_query_var( $key ) === $this->query_var_default_value );
	}

	/**
	 * Is a column valid?
	 *
	 * @since 2.1.0
	 * @param string $column_name
	 * @return bool
	 */
	private function is_valid_column( $column_name = '' ) {

		// Bail if column name not valid string
		if ( empty( $column_name ) || ! is_string( $column_name ) ) {
			return false;
		}

		// Get all of the column names
		$columns = $this->get_column_names();

		// Return if column name exists
		return isset( $columns[ $column_name ] );
	}

	/** Private Getters *******************************************************/

	/**
	 * Get a query variable.
	 *
	 * @since 2.1.0
	 * @param string $key
	 * @return mixed
	 */
	private function get_query_var( $key = '' ) {
		return isset( $this->query_vars[ $key ] )
			? $this->query_vars[ $key ]
			: null;
	}

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
	 * Return the table name.
	 *
	 * Prefixed by the $table_prefix global, or get_blog_prefix() if
	 * is_multisite().
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
	 * Function arguments are passed into wp_filter_object_list() to filter the
	 * array of columns as needed.
	 *
	 * @since 1.0.0
	 * @since 2.1.0
	 *
	 * @static array       $columns  Local static copy of columns, abstracted to
	 *                               support different storage locations.
	 * @param  array       $args     Arguments to filter columns by.
	 * @param  string      $operator Optional. The logical operation to perform.
	 * @param  bool|string $field    Optional. A field from the object to place
	 *                               instead of the entire object. Default false.
	 * @return array Array of columns.
	 */
	private function get_columns( $args = array(), $operator = 'and', $field = false ) {
		static $columns = null;

		// Setup columns
		if ( null === $columns ) {

			// Default columns
			$columns = array();

			// Legacy columns
			if ( ! empty( $this->columns ) ) {
				$columns = $this->columns;
			}

			// Columns from Schema
			if ( ! empty( $this->schema->columns ) ) {
				$columns = $this->schema->columns;
			}
		}

		// Filter columns
		$filter = wp_filter_object_list( $columns, $args, $operator, $field );

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

		// Maybe fallback to $key
		if ( empty( $field ) ) {
			$field = $key;
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
	 * Get a column name, possibly with the $table_alias append.
	 *
	 * @since 2.1.0
	 * @param string $column_name
	 * @param bool   $alias
	 * @return string
	 */
	private function get_column_name_aliased( $column_name = '', $alias = true ) {

		// Default return value
		$retval = $column_name;

		/**
		 * Maybe append table alias.
		 *
		 * Also append a period, to separate it from the column name.
		 */
		if ( true === $alias ) {
			$retval = "{$this->table_alias}.{$column_name}";
		}

		// Return SQL
		return $retval;
	}

	/**
	 * Get a single database row by any column and value, skipping cache.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses is_valid_column()
	 *
	 * @param string $column_name  Name of database column
	 * @param mixed  $column_value Value to query for
	 * @return object|false False if empty/error, Object if successful
	 */
	private function get_item_raw( $column_name = '', $column_value = '' ) {

		// Bail if empty or non-scalar value
		if ( empty( $column_value ) || ! is_scalar( $column_value ) ) {
			return false;
		}

		// Bail if invalid column
		if ( ! $this->is_valid_column( $column_name ) ) {
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
	 * @return array|int Array of items, or number of items when 'count' is passed as a query var.
	 */
	private function get_items() {

		/**
		 * Fires before object items are retrieved.
		 *
		 * @since 1.0.0
		 *
		 * @param Query &$this Current instance passed by reference.
		 */
		do_action_ref_array(
			$this->apply_prefix( "pre_get_{$this->item_name_plural}" ),
			array(
				&$this
			)
		);

		// Check the cache
		$cache_key   = $this->get_cache_key();
		$cache_value = $this->cache_get( $cache_key, $this->cache_group );

		// No cache value
		if ( false === $cache_value ) {

			// Query for item IDs
			$result = $this->get_item_ids();

			// Set the number of found items
			$this->set_found_items( $result );

			// Format the cached value
			$cache_value = array(
				'item_ids'    => $result,
				'found_items' => (int) $this->found_items,
			);

			// Add value to the cache
			$this->cache_add( $cache_key, $cache_value, $this->cache_group );

		// Value exists in cache
		} else {
			$result            = $cache_value['item_ids'];
			$this->found_items = (int) $cache_value['found_items'];
		}

		// Pagination
		if ( ! empty( $this->found_items ) ) {
			$number = (int) $this->get_query_var( 'number' );

			if ( ! empty( $number ) ) {
				$this->max_num_pages = (int) ceil( $this->found_items / $number );
			}
		}

		// Cast to int if not grouping counts
		if ( $this->get_query_var( 'count' ) ) {

			// Set items
			$this->items = $result;

			// Not grouping, so cast to int
			if ( ! $this->get_query_var( 'groupby' ) ) {
				$this->items = (int) $result;
			}

			// Return
			return $this->items;
		}

		// Set items from result
		$this->set_items( $result );

		// Return array of items
		return $this->items;
	}

	/**
	 * Used internally to get a list of item IDs matching the query vars.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses wp_parse_list() instead of wp_parse_id_list()
	 *
	 * @return mixed An array of item IDs if a full query. A single count of
	 *               item IDs if a count query.
	 */
	private function get_item_ids() {

		// Setup the query clauses
		$this->set_query_clauses();

		// Setup request
		$this->set_request_clauses();
		$this->set_request();

		// Return count
		if ( $this->get_query_var( 'count' ) ) {

			// Get vars or results
			$retval = ! $this->get_query_var( 'groupby' )
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
	 * @since 2.1.0 Bail early if parameters are empty.
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
	 * @param array|string $values      Array of values.
	 * @param bool         $wrap        To wrap in parenthesis.
	 * @param string       $pattern     Pattern to prepare with.
	 *
	 * @return string Escaped/prepared SQL, possibly wrapped in parenthesis.
	 */
	private function get_in_sql( $column_name = '', $values = array(), $wrap = true, $pattern = '' ) {

		// Default return value
		$retval = '';

		// Bail if no values or invalid column
		if ( empty( $values ) || ! $this->is_valid_column( $column_name ) ) {
			return $retval;
		}

		// Fallback to column pattern
		if ( empty( $pattern ) || ! is_string( $pattern ) ) {
			$pattern = $this->get_column_field( array( 'name' => $column_name ), 'pattern', '%s' );
		}

		// Fill an array of patterns to match the number of values
		$count    = count( $values );
		$patterns = array_fill( 0, $count, $pattern );

		// Escape & prepare
		$sql      = implode( ', ', $patterns );
		$values   = $this->get_db()->_escape( $values );       // May quote strings
		$retval   = $this->get_db()->prepare( $sql, $values ); // Catches quoted strings

		// Set return value to empty string if prepare() returns falsy
		if ( empty( $retval ) ) {
			$retval = '';
		}

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
	 * @since 2.1.0 Forces some $query_vars if counting
	 *
	 * @param array|string $query
	 */
	private function parse_query( $query = array() ) {

		// Setup the $query_vars_original var
		$this->query_var_originals = wp_parse_args( $query );

		// Setup the $query_vars parsed var
		$this->query_vars = wp_parse_args(
			$this->query_var_originals,
			$this->query_var_defaults
		);

		// If counting, override some other $query_vars
		if ( $this->get_query_var( 'count' ) ) {
			$this->query_vars['number']            = false;
			$this->query_vars['orderby']           = '';
			$this->query_vars['no_found_rows']     = true;
			$this->query_vars['update_item_cache'] = false;
			$this->query_vars['update_meta_cache'] = false;
		}

		/**
		 * Fires after the item query vars have been parsed.
		 *
		 * @since 1.0.0
		 *
		 * @param Query &$this Current instance passed by reference.
		 */
		do_action_ref_array(
			$this->apply_prefix( "parse_{$this->item_name_plural}_query" ),
			array(
				&$this
			)
		);
	}

	/**
	 * Parse all of the $query_vars.
	 *
	 * Optionally accepts an array of custom $query_vars that can be used
	 * instead of the default ones.
	 *
	 * Calls filter_query_clauses() on the return value.
	 *
	 * @since 2.1.0
	 * @param array $query_vars Optional. Default empty array.
	 *                          Fallback to Query::query_vars.
	 * @return array Query clauses, parsed from Query vars.
	 */
	private function parse_query_vars( $query_vars = array() ) {

		// Maybe fallback to $query_vars
		if ( empty( $query_vars ) && ! empty( $this->query_vars ) ) {
			$query_vars = $this->query_vars;
		}

		// Parse arguments
		$r = wp_parse_args( $query_vars );

		// Parse $query_vars
		$where_join = $this->parse_where_join( $r );

		// Parse all clauses
		$clauses = array(
			'explain' => $this->parse_explain( $r['explain'] ),
			'select'  => $this->parse_select(),
			'fields'  => $this->parse_fields( $r['fields'], $r['count'], $r['groupby'] ),
			'count'   => $this->parse_count( $r['count'], $r['groupby'] ),
			'from'    => $this->parse_from(),
			'join'    => $this->parse_join_clause( $where_join['join'] ),
			'where'   => $this->parse_where_clause( $where_join['where'] ),
			'groupby' => $this->parse_groupby( $r['groupby'], 'GROUP BY ' ),
			'orderby' => $this->parse_orderby( $r['orderby'], $r['order'], 'ORDER BY ' ),
			'limits'  => $this->parse_limits( $r['number'], $r['offset'] )
		);

		// Return clauses
		return $this->filter_query_clauses( $clauses );
	}

	/**
	 * Parse the 'where' and 'join' $query_vars for all known columns.
	 *
	 * @since 2.1.0
	 *
	 * @param array $args Query vars
	 * @return array Array of 'where' and 'join' clauses.
	 */
	private function parse_where_join( $args = array() ) {

		// Maybe fallback to $query_vars
		if ( empty( $args ) && ! empty( $this->query_vars ) ) {
			$args = $this->query_vars;
		}

		// Parse arguments
		$r = wp_parse_args( $args );

		// Defaults
		$where = $join = $date_query = array();

		// Get all of the columns
		$columns = $this->get_columns();

		// Loop through columns
		foreach ( $columns as $column ) {

			// Get column name, pattern, and aliased name
			$name    = $column->name;
			$pattern = $this->get_column_field( array( 'name' => $name ), 'pattern', '%s' );
			$aliased = $this->get_column_name_aliased( $name );

			// Literal column comparison
			if ( false !== $column->by ) {

				// Parse query variable
				$where_id = $name;
				$values   = $this->parse_query_var( $r, $where_id );

				// Parse item for direct clause.
				if ( false !== $values ) {

					// Convert single item arrays to literal column comparisons
					if ( 1 === count( $values ) ) {
						$statement          = "{$aliased} = {$pattern}";
						$column_value       = reset( $values );
						$where[ $where_id ] = $this->get_db()->prepare( $statement, $column_value );

					// Implode
					} else {
						$where_id           = "{$where_id}__in";
						$in_values          = $this->get_in_sql( $name, $values, true, $pattern );
						$where[ $where_id ] = "{$aliased} IN {$in_values}";
					}
				}
			}

			// __in
			if ( true === $column->in ) {

				// Parse query var
				$where_id = "{$name}__in";
				$values   = $this->parse_query_var( $r, $where_id );

				// Parse item for an IN clause.
				if ( false !== $values ) {

					// Convert single item arrays to literal column comparisons
					if ( 1 === count( $values ) ) {
						$statement          = "{$aliased} = {$pattern}";
						$where_id           = $name;
						$column_value       = reset( $values );
						$where[ $where_id ] = $this->get_db()->prepare( $statement, $column_value );

					// Implode
					} else {
						$in_values          = $this->get_in_sql( $name, $values, true, $pattern );
						$where[ $where_id ] = "{$aliased} IN {$in_values}";
					}
				}
			}

			// __not_in
			if ( true === $column->not_in ) {

				// Parse query var
				$where_id = "{$name}__not_in";
				$values   = $this->parse_query_var( $r, $where_id );

				// Parse item for a NOT IN clause.
				if ( false !== $values ) {

					// Convert single item arrays to literal column comparisons
					if ( 1 === count( $values ) ) {
						$statement          = "{$aliased} != {$pattern}";
						$where_id           = $name;
						$column_value       = reset( $values );
						$where[ $where_id ] = $this->get_db()->prepare( $statement, $column_value );

					// Implode
					} else {
						$in_values          = $this->get_in_sql( $name, $values, true, $pattern );
						$where[ $where_id ] = "{$aliased} NOT IN {$in_values}";
					}
				}
			}

			// date_query
			if ( true === $column->date_query ) {
				$where_id    = "{$name}_query";
				$column_date = $this->parse_query_var( $r, $where_id );

				// Parse item
				if ( false !== $column_date ) {

					// Single
					if ( 1 === count( $column_date ) ) {
						$date_query[] = array(
							'column'    => $aliased,
							'before'    => reset( $column_date ),
							'inclusive' => true
						);

					// Multi
					} else {

						// Auto-fill column if empty
						if ( empty( $column_date['column'] ) ) {
							$column_date['column'] = $aliased;
						}

						// Add clause to date query
						$date_query[] = $column_date;
					}
				}
			}
		}

		/** Search ************************************************************/

		// Get names of searchable columns
		$searchable = $this->get_columns( array( 'searchable' => true ), 'and', 'name' );

		// Maybe search if columns are searchable.
		if ( ! empty( $searchable ) && strlen( $r['search'] ) ) {

			// Default to all searchable columns
			$search_columns = $searchable;

			// Intersect against known searchable columns
			if ( ! empty( $r['search_columns'] ) ) {
				$search_columns = array_intersect(
					$r['search_columns'],
					$searchable
				);
			}

			// Filter search columns
			$search_columns = $this->filter_search_columns( $search_columns );

			// Add search query clause
			$where['search'] = $this->get_search_sql( $r['search'], $search_columns );
		}

		/** Query Classes *****************************************************/

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// Get the meta type & table alias
		$table   = $this->get_meta_type();
		$alias   = $this->table_alias;

		// Set the " AND " regex pattern
		$and     = '/^\s*AND\s*/';

		// Maybe perform a meta query.
		$meta_query = $r['meta_query'];
		if ( ! empty( $meta_query ) && is_array( $meta_query ) ) {
			$this->meta_query = $this->get_meta_query( $meta_query );
			$clauses          = $this->meta_query->get_sql( $table, $alias, $primary, $this );

			// Not all objects have meta, so make sure this one exists
			if ( false !== $clauses ) {

				// Set join
				if ( ! empty( $clauses['join'] ) ) {
					$join['meta_query'] = $clauses['join'];
				}

				// Set where
				if ( ! empty( $clauses['where'] ) ) {
					$where['meta_query'] = preg_replace( $and, '', $clauses['where'] );
				}
			}
		}

		// Maybe perform a compare query.
		$compare_query = $r['compare_query'];
		if ( ! empty( $compare_query ) && is_array( $compare_query ) ) {
			$this->compare_query = $this->get_compare_query( $compare_query );
			$clauses             = $this->compare_query->get_sql( $table, $alias, $primary, $this );

			// Not all objects can compare, so make sure this one exists
			if ( false !== $clauses ) {

				// Set join
				if ( ! empty( $clauses['join'] ) ) {
					$join['compare_query'] = $clauses['join'];
				}

				// Set where
				if ( ! empty( $clauses['where'] ) ) {
					$where['compare_query'] = preg_replace( $and, '', $clauses['where'] );
				}
			}
		}

		// Only do a date query with an array
		$date_query = ! empty( $date_query )
			? $date_query
			: $r['date_query'];

		// Maybe perform a date query
		if ( ! empty( $date_query ) && is_array( $date_query ) ) {
			$this->date_query = $this->get_date_query( $date_query );
			$clauses          = $this->date_query->get_sql();

			// Not all objects are dates, so make sure this one exists
			if ( false !== $clauses ) {

				// Set join
				if ( ! empty( $clauses['join'] ) ) {
					$join['date_query'] = $clauses['join'];
				}

				// Set where
				if ( ! empty( $clauses['where'] ) ) {
					$where['date_query'] = preg_replace( $and, '', $clauses['where'] );
				}
			}
		}

		// Return where & join, removing possible empties
		return array(
			'where' => array_filter( $where ),
			'join'  => array_filter( $join  )
		);
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
	 * Parse if query to be EXPLAIN'ed.
	 *
	 * @since 2.1.0
	 * @param bool $explain Default false. True to EXPLAIN.
	 * @return string
	 */
	private function parse_explain( $explain = false ) {

		// Maybe fallback to $query_vars
		if ( empty( $explain ) ) {
			$explain = $this->get_query_var( 'explain' );
		}

		// Default return value
		$retval = '';

		// Maybe explaining
		if ( ! empty( $explain ) ) {
			$retval = 'EXPLAIN';
		}

		// Return SQL
		return $retval;
	}

	/**
	 * Parse the "SELECT" part of the SQL.
	 *
	 * @since 2.1.0
	 * @return string Default "SELECT".
	 */
	private function parse_select() {
		return 'SELECT';
	}

	/**
	 * Parse which fields to query for.
	 *
	 * If making a 'count' request, this will return either an empty string or
	 * the same columns that are being used for the "GROUP BY" to avoid errors.
	 *
	 * If not counting, this always only includes the Primary column to more
	 * predictably hit the cache, but that may change in a future version.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Moved COUNT() SQL to parse_count() and uses parse_groupby()
	 *              when counting to satisfy MySQL 8 and higher.
	 *
	 * @param string[] $fields
	 * @param bool     $count
	 * @param string[] $groupby
	 * @param bool     $alias
	 * @return string
	 */
	private function parse_fields( $fields = array(), $count = false, $groupby = array(), $alias = true ) {

		// Maybe fallback to $query_vars
		if ( empty( $count ) ) {
			$count = $this->get_query_var( 'count' );
		}

		// Default return value
		$retval = '';

		// Counting, so use groupby
		if ( ! empty( $count ) ) {

			// Use groupby instead
			if ( ! empty( $groupby ) ) {
				$retval = $this->parse_groupby( $groupby, '', $alias );
			}

		// Not counting, so use primary column
		} else {

			// Maybe fallback to $query_vars
			if ( empty( $fields ) ) {
				$fields = $this->get_query_var( 'fields' );
			}

			// Get the primary column name
			$primary = $this->get_primary_column_name();

			// Default return value
			$retval = $this->get_column_name_aliased( $primary, $alias );
		}

		// Return fields
		return $retval;
	}

	/**
	 * Parse if counting.
	 *
	 * When counting with groups, parse_fields() will return the required SQL to
	 * prevent errors.
	 *
	 * @since 2.1.0
	 * @param bool   $count
	 * @param string $groupby
	 * @param string $name
	 * @param bool   $alias
	 * @return string
	 */
	private function parse_count( $count = false, $groupby = '', $name = 'count', $alias = true ) {

		// Maybe fallback to $query_vars
		if ( empty( $count ) ) {
			$count = $this->get_query_var( 'count' );
		}

		// Bail if not counting
		if ( empty( $count ) ) {
			return '';
		}

		// Default return value
		$retval = 'COUNT(*)';

		// Check for "GROUP BY"
		$groupby_names = $this->parse_groupby( $groupby, '', $alias );

		// Reformat if grouping counts together
		if ( ! empty( $groupby_names ) ) {
			$retval = ", {$retval} as {$name}";
		}

		// Return SQL
		return $retval;
	}

	/**
	 * Parse which table to query and whether to follow it with an alias.
	 *
	 * @since 2.1.0
	 * @param string $table Optional. Default empty string.
	 *                      Fallback to get_table_name().
	 * @param string $alias Optional. Default empty string.
	 *                      Fallback to $table_alias.
	 * @return string
	 */
	private function parse_from( $table = '', $alias = '' ) {

		// Maybe fallback to get_table_name()
		if ( empty( $table ) ) {
			$table = $this->get_table_name();
		}

		// Maybe fallback to $table_alias
		if ( empty( $alias ) ) {
			$alias = $this->table_alias;
		}

		// Return
		return "FROM {$table} {$alias}";
	}

	/**
	 * Parses and sanitizes the 'groupby' keys passed into the item query.
	 *
	 * @since 1.0.0
	 *
	 * @param string $groupby
	 * @param string $before
	 * @param bool   $alias
	 * @return string
	 */
	private function parse_groupby( $groupby = '', $before = '', $alias = true ) {

		// Maybe fallback to $query_vars
		if ( empty( $groupby ) ) {
			$groupby = $this->get_query_var( 'groupby' );
		}

		// Bail if empty
		if ( empty( $groupby ) ) {
			return '';
		}

		// Maybe cast to array
		if ( ! is_array( $groupby ) ) {
			$groupby = (array) $groupby;
		}

		// Get the intersection of allowed column names to groupby columns
		$intersect = $this->get_columns_field_by( 'name', $groupby );

		// Bail if invalid columns
		if ( empty( $intersect ) ) {
			return '';
		}

		// Column names array
		$names = array();

		// Maybe prepend table alias to key
		foreach ( $intersect as $key ) {
			$names[] = $this->get_column_name_aliased( $key, $alias );
		}

		// Bail if nothing to groupby
		if ( 0 === count( $names ) && ! empty( $before ) ) {
			return '';
		}

		// Format column names
		$retval = implode( ',', $names );

		// Return columns
		return implode( ' ', array( $before, $retval ) ) ;
	}

	/**
	 * Parse the ORDER BY clause.
	 *
	 * @since 1.0.0 As get_order_by
	 * @since 2.1.0 Renamed to parse_orderby and accepts $orderby, $order, $before, and $alias
	 *
	 * @param string $orderby
	 * @param string $order
	 * @param string $before
	 * @param bool   $alias
	 * @return string
	 */
	private function parse_orderby( $orderby = '', $order = '', $before = '', $alias = true ) {

		// Maybe fallback to $query_vars
		if ( empty( $orderby ) ) {
			$orderby = $this->get_query_var( 'orderby' );
		}

		// Bail if counting
		if ( $this->get_query_var( 'count' ) ) {
			return '';
		}

		// Bail if $orderby is a value that could cancel ordering
		if ( in_array( $orderby, array( 'none', array(), false, null ), true ) ) {
			return '';
		}

		// Default return value
		$retval = '';

		// Fallback to default orderby & order
		if ( empty( $orderby ) ) {
			$parsed = $this->parse_single_orderby( $orderby, $alias );
			$order  = $this->parse_order( $order );
			$retval = "{$parsed} {$order}";

		// Ordering by something, so figure it out
		} else {

			// Cast orderby as an array
			$ordersby = (array) $orderby;

			// Fill if numeric
			if ( wp_is_numeric_array( $ordersby ) ) {
				$ordersby = array_fill_keys( $ordersby, $order );
			}

			// Default return value
			$orderby_array = array();

			// Loop through orderby's
			foreach ( $ordersby as $key => $value ) {

				// Parse orderby
				$parsed = $this->parse_single_orderby( $key, $alias );

				// Skip if empty
				if ( empty( $parsed ) ) {
					continue;
				}

				// Append parsed orderby to array
				$orderby_array[] = $parsed . ' ' . $this->parse_order( $value );
			}

			// Only set if valid orderby
			if ( ! empty( $orderby_array ) ) {
				$retval = implode( ', ', $orderby_array );
			}
		}

		// Bail if nothing to orderby
		if ( empty( $retval ) && ! empty( $before ) ) {
			return '';
		}

		// Return parsed orderby
		return implode( ' ', array( $before, $retval ) );
	}

	/**
	 * Parse all of the where clauses.
	 *
	 * @since 2.1.0
	 * @param array $where
	 * @return string A single SQL statement.
	 */
	private function parse_where_clause( $where = array() ) {

		// Bail if no where
		if ( empty( $where ) ) {
			return '';
		}

		// Return SQL
		return 'WHERE ' . implode( ' AND ', $where );
	}

	/**
	 * Parse all of the join clauses.
	 *
	 * @since 2.1.0
	 * @param array $join
	 * @return string A single SQL statement.
	 */
	private function parse_join_clause( $join = array() ) {

		// Return SQL
		return implode( ', ', $join );
	}

	/**
	 * Parse all of the SQL query clauses.
	 *
	 * @since 2.1.0
	 * @param array $clauses
	 * @return array
	 */
	private function parse_query_clauses( $clauses = array() ) {

		// Maybe fallback to $query_clauses
		if ( empty( $clauses ) && ! empty( $this->query_clauses ) ) {
			$clauses = $this->query_clauses;
		}

		// Default return value
		$retval = wp_parse_args( $clauses );

		// Return array of clauses
		return $retval;
	}

	/**
	 * Parse all SQL $request_clauses into a single SQL query string.
	 *
	 * @since 2.1.0
	 * @param array $clauses
	 * @return string A single SQL statement.
	 */
	private function parse_request_clauses( $clauses = array() ) {

		// Maybe fallback to $request_clauses
		if ( empty( $clauses ) && ! empty( $this->request_clauses ) ) {
			$clauses = $this->request_clauses;
		}

		// Bail if empty clauses
		if ( empty( $clauses ) ) {
			return '';
		}

		// Remove empties
		$filtered = array_filter( $clauses );
		$retval   = array_map( 'trim', $filtered );

		// Return SQL
		return implode( ' ', $retval );
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

		// Default return value
		$retval = '';

		// No negative numbers
		$limit  = absint( $number );
		$offset = absint( $offset );

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
	 * Parses and sanitizes a single 'orderby' key passed to the item query.
	 *
	 * This method assumes that $orderby is a valid Column name.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses get_in_sql()
	 *
	 * @param string $orderby Field for the items to be ordered by.
	 * @param bool   $alias   Whether to append the table alias.
	 * @return string Value to used in the ORDER BY clause.
	 */
	private function parse_single_orderby( $orderby = '', $alias = true ) {

		// Fallback to primary column
		if ( empty( $orderby ) ) {
			$orderby = $this->get_primary_column_name();
		}

		// Default return value
		$retval = '';

		// Get possible columns an $orderby can belong to
		$ins       = $this->get_columns( array( 'in'       => true ), 'and', 'name' );
		$sortables = $this->get_columns( array( 'sortable' => true ), 'and', 'name' );

		// __in column
		if ( false !== strstr( $orderby, '__in' ) ) {

			// Get column name from $orderby clause
			$column_name = str_replace( '__in', '', $orderby );

			// Get values if valid column
			if ( in_array( $column_name, $ins, true ) ) {
				$values  = $this->get_query_var( $orderby );
				$item_in = $this->get_in_sql( $column_name, $values, false );
				$aliased = $this->get_column_name_aliased( $column_name, $alias );
				$retval  = "FIELD( {$aliased}, {$item_in} )";
			}

		// Specific sortable column
		} elseif ( in_array( $orderby, $sortables, true ) ) {
			$retval = $this->get_column_name_aliased( $orderby, $alias );
		}

		// Return SQL
		return $retval;
	}

	/**
	 * Parses an 'order' query variable and cast it to 'ASC' or 'DESC' as
	 * necessary.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Default to 'DESC'
	 *
	 * @param string $order The 'order' query variable.
	 * @return string The sanitized 'order' query variable.
	 */
	private function parse_order( $order  = 'DESC' ) {

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
	 * If using the "fields" query_var, results will be an array of stdClass
	 * objects with keys based on fields.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Added $fields parameter.
	 *
	 * @param array $items  Array of items to shape.
	 * @param array $fields Fields to get from items.
	 * @return array
	 */
	private function shape_items( $items = array(), $fields = array() ) {

		// Maybe fallback to $query_vars
		if ( empty( $fields ) ) {
			$fields = $this->get_query_var( 'fields' );
		}

		// Force to stdClass if querying for fields
		if ( ! empty( $fields ) ) {
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
		if ( ! empty( $fields ) ) {
			$retval = $this->get_item_fields( $retval, $fields );
		}

		// Return shaped items
		return $retval;
	}

	/**
	 * Get specific fields from an array of items.
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

		// Maybe fallback to $query_vars
		if ( empty( $fields ) ) {
			$fields = $this->get_query_var( 'fields' );
		}

		// Bail if no fields to get
		if ( empty( $fields ) ) {
			return $retval;
		}

		// Maybe cast to array
		if ( ! is_array( $fields ) ) {
			$fields = (array) $fields;
		}

		// Get the primary column name
		$primary = $this->get_primary_column_name();

		// 'ids' is numerically keyed
		if ( ( 1 === count( $fields ) ) && ( 'ids' === $fields[0] ) ) {
			$retval = wp_list_pluck( $items, $primary );

		// Get fields from items
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
	 * @since 2.1.0 Uses validate_item_field() instead of intval.
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
	 * preferably with a cache group for that column.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $column_name  Name of database column
	 * @param int|string $column_value Value to query for
	 * @return object|false False if empty/error, Object if successful
	 */
	public function get_item_by( $column_name = '', $column_value = '' ) {

		// Bail if empty or non-scalar value
		if ( empty( $column_value ) || ! is_scalar( $column_value ) ) {
			return false;
		}

		// Bail if column does not exist
		if ( ! $this->is_valid_column( $column_name ) ) {
			return false;
		}

		// Default return value
		$retval = false;

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
	 * @param mixed $item ID of item, or row from database
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
	 * Return an item comprised of all Column names as keys and their defaults
	 * as values.
	 *
	 * This is used by `add_item()` to get an array of default item values that
	 * can be compared against, to determine if any values need to be saved into
	 * meta data instead.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses array_combine()
	 *
	 * @param array $args Default empty array. Parsed & passed into get_columns().
	 * @return array
	 */
	private function default_item( $args = array() ) {

		// Parse arguments
		$r = wp_parse_args( $args );

		// Get the column names and their defaults
		$names    = $this->get_columns( $r, 'and', 'name'    );
		$defaults = $this->get_columns( $r, 'and', 'default' );

		// Combine them
		$retval   = array_combine( $names, $defaults );

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
	 * @since 1.0.0
	 * @since 2.1.0 Minor refactor to improve readability.
	 *
	 * @return bool|string Table name if exists, False if not.
	 */
	private function get_meta_table_name() {

		// Default return value
		$retval = false;

		// Get the meta type
		$type   = $this->get_meta_type();

		// Append "meta" to end of meta type
		$table  = "{$type}meta";

		// Variable'ize the database interface, to use inside empty()
		$db     = $this->get_db();

		// If not empty, return table name
		if ( ! empty( $db->{$table} ) ) {
			$retval = $db->{$table};
		}

		// Return
		return $retval;
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
	 * Get cache key from $query_vars and $query_var_defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group
	 * @return string
	 */
	private function get_cache_key( $group = '' ) {

		// Slice $query_vars by default keys
		$slice = wp_array_slice_assoc( $this->query_vars, array_keys( $this->query_var_defaults ) );

		// Unset "fields" so it does not effect the cache key
		unset( $slice['fields'] );

		// Setup key & last_changed
		$key          = md5( serialize( $slice ) );
		$last_changed = $this->get_last_changed_cache( $group );

		// Return the concatenated cache key
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
	 * Maybe prime item & item-meta caches.
	 *
	 * Accepts a single ID, or an array of IDs.
	 *
	 * The reason this accepts only IDs is because it gets called immediately
	 * after an item is inserted in the database, but before items have been
	 * "shaped" into proper objects, so object properties may not be set yet.
	 *
	 * Queries the database 1 time for all non-cached item objects and 1 time
	 * for all non-cached item meta.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses get_meta_table_name() to
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
		 * Uses get_non_cached_ids() to remove item IDs that already exist in
		 * in the cache, then performs direct database query for the remaining
		 * IDs, and caches them.
		 */
		if ( ! empty( $force ) || $this->get_query_var( 'update_item_cache' ) ) {

			// Look for non-cached IDs
			$ids = $this->get_non_cached_ids( $item_ids, $this->cache_group );

			// Proceed if non-cached IDs exist
			if ( ! empty( $ids ) ) {

				// Get query parts
				$table   = $this->get_table_name();
				$primary = $this->get_primary_column_name();
				$ids     = $this->get_in_sql( $primary, $ids );

				// Query database
				$query   = "SELECT * FROM {$table} WHERE {$primary} IN %s";
				$prepare = sprintf( $query, $ids );
				$results = $this->get_db()->get_results( $prepare );

				// Update item cache(s)
				$this->update_item_cache( $results );
			}
		}

		/**
		 * Update meta data caches.
		 *
		 * Uses update_meta_cache() because it politely handles all of the
		 * non-cached ID logic. This allows us to use the original (and likely
		 * larger) $item_ids array instead of $ids, thus ensuring the everything
		 * is cached according to our expectations.
		 */
		if ( ! empty( $force ) || $this->get_query_var( 'update_meta_cache' ) ) {

			// Proceed if meta table exists
			if ( $this->get_meta_table_name() ) {
				$meta_type = $this->get_meta_type();
				update_meta_cache( $meta_type, $item_ids );
			}
		}

		// Return true because something was cached
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
	 * @since 2.1.0 Uses shape_item_id() if $items is scalar
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
	 * @since 2.1.0 No longer uses shape_item_id()
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
	 * @since 2.1.0
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
	 * @since 2.1.0
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
	 * @param string $sql
	 * @return string
	 */
	public function filter_found_items_query( $sql = '' ) {

		/**
		 * Filters the query used to retrieve the found item count.
		 *
		 * @since 1.0.0
		 * @since 2.1.0 Supports MySQL 8 by removing FOUND_ROWS() and uses
		 *              $request_clauses instead.
		 *
		 * @param string $query SQL query. Default 'SELECT FOUND_ROWS()'.
		 * @param Query  &$this Current instance passed by reference.
		 */
		return (string) apply_filters_ref_array(
			$this->apply_prefix( "found_{$this->item_name_plural}_query" ),
			array(
				$sql,
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

	/**
	 * Filters the columns to search by.
	 *
	 * @since 2.1.0
	 *
	 * @param array $search_columns All of the columns to search.
	 * @return array
	 */
	public function filter_search_columns( $search_columns = array() ) {

		/**
		 * Filters the columns to search by.
		 *
		 * @since 1.0.0
		 * @since 2.1.0 Uses apply_filters_ref_array() instead of apply_filters()
		 *
		 * @param array $search_columns Array of column names to be searched.
		 * @param Query &$this          Current instance passed by reference.
		 */
		return (array) apply_filters_ref_array(
			$this->apply_prefix( "{$this->item_name_plural}_search_columns" ),
			array(
				$search_columns,
				&$this
			)
		);
	}

	/** General ***************************************************************/

	/**
	 * Fetch raw results directly from the database.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Uses query()
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
