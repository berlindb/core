<?php
/**
 * Base Custom Database Table Query Class.
 *
 * @package     Database
 * @subpackage  Query
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Kern;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base class used for querying custom database tables.
 *
 * This class is intended to be extended for each unique database table,
 * including global tables for multisite, and users tables.
 *
 * @since 1.0.0
 *
 * @property array<string, \BerlinDB\Database\Parsers\Base> $parsers
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
 *     @type bool    $update_item_cache Prime the cache for found items.
 *                                      Default false.
 *     @type bool    $update_meta_cache Prime the meta cache for found items.
 *                                      Default false.
 * }
 */
class Query {

	/**
	 * Use these traits.
	 *
	 * @since 3.0.0
	 */
	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;

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
	 * Schema class name or Schema object used to configure columns and indexes.
	 *
	 * Accepts either a fully-qualified class name string (the classic subclass
	 * pattern) or a Schema instance built at runtime — e.g. from a constructor
	 * argument or a Schema::from_table() call.
	 *
	 * @since 1.0.0
	 * @var   string|Schema
	 */
	protected $table_schema = __NAMESPACE__ . '\\Schema';

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
	 * @var   string
	 */
	protected $item_shape = __NAMESPACE__ . '\\Row';

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

	/** Schema *************************************************************/

	/**
	 * Schema object.
	 *
	 * A collection of Column and Index objects. Set to private so that it is
	 * not touched directly until this can be vetted and opened up.
	 *
	 * @since 3.0.0
	 * @var   Schema|null|object
	 */
	private $schema_object = null;

	/** Query Variables *******************************************************/

	/**
	 * Parsed query vars set by the application, possibly filtered and changed.
	 *
	 * This is specifically marked as public, to allow byref actions to change
	 * them from outside the class methods proper and inside filter functions.
	 *
	 * @since 1.0.0
	 * @var   array<string, mixed>
	 */
	public $query_vars = array();

	/**
	 * Default values for query vars.
	 *
	 * These are computed at runtime based on the registered columns for the
	 * database table this query relates to.
	 *
	 * @since 1.0.0
	 * @var   array<string, mixed>
	 */
	protected $query_var_defaults = array();

	/**
	 * Random default value for all query vars.
	 *
	 * This protected variable temporarily holds onto a random string used as
	 * the default query var value. This is used internally when performing
	 * comparisons, and allows for querying by falsy values.
	 *
	 * @since 1.1.0
	 * @var   string
	 */
	protected $query_var_default_value = '';

	/**
	 * Ordered list of fully-qualified Parser class names.
	 *
	 * Each entry must be the name of a class that extends Parsers\Base and
	 * declares its own descriptor properties ($name, $query_var, etc.).
	 * Subclasses can override this property before sunrise() runs to replace
	 * or extend the default set of parsers.
	 *
	 * @since 3.0.0
	 * @var   string[]
	 */
	protected $query_var_parsers = array();

	/**
	 * Map of instantiated parser descriptor objects, keyed by parser name.
	 *
	 * Populated once during set_query_var_defaults() from $query_var_parsers.
	 * Never mutated after that — see $current[ 'parsers' ] for per-query instances.
	 *
	 * @since 3.0.0
	 * @var   array<string, \BerlinDB\Database\Parsers\Base>
	 */
	protected $parsers = array();

	/** Results ***************************************************************/

	/**
	 * Array of items retrieved by the SQL query.
	 *
	 * @since 1.0.0
	 * @var   list<object>|array<int|string, mixed>|int
	 */
	public $items = array();

	/** Methods ***************************************************************/

	/**
	 * Setup class attributes that rely on other properties.
	 *
	 * This method is protected to allow subclasses to override setup without
	 * exposing it as part of the public query API.
	 *
	 * @since 3.0.0
	 */
	protected function sunrise(): void {
		$this->set_table_name();
		$this->set_table_alias();
		$this->set_cache_group();
		$this->set_prefixes();
		$this->set_schema();
		$this->set_item_shape();
		$this->set_query_var_parsers();
		$this->set_query_var_defaults();
	}

	/**
	 * Parse the query arguments.
	 *
	 * Overrides Boot::parse_args(). Runs the query immediately and returns an
	 * empty array so Boot's boot() loop skips the set_vars() call — Query
	 * manages its own state via query() rather than via property assignment.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $args Array of arguments.
	 * @return array<string, mixed> Always empty — Boot should not call set_vars() for queries.
	 */
	protected function parse_args( $args = array() ) {

		// Bail if no args.
		if ( empty( $args ) ) {
			return array();
		}

		// Parse the query and get items.
		$this->query( $args );

		return array();
	}

	/**
	 * Reset per-run ephemeral state at the start of each action.
	 *
	 * Called by boot() during construction and by query() before each run.
	 * Initialises $current with all keys that are rebuilt on every run so
	 * that stale state from a prior call can never bleed through.
	 *
	 * @since 3.0.0
	 */
	protected function start(): void {
		$clause_keys = array( 'explain', 'select', 'fields', 'from', 'join', 'where', 'groupby', 'orderby', 'limits' );

		$this->init_current(
			array(
				'parsers'             => array(),
				'item_shape'          => $this->item_shape,
				'query_var_originals' => array(),
				'query_clauses'       => array_combine( $clause_keys, array( '', '', '', '', array(), array(), '', '', '' ) ),
				'request_clauses'     => array_fill_keys( $clause_keys, '' ),
				'request'             => '',
				'found_items'         => 0,
				'max_num_pages'       => 0,
			)
		);
	}

	/**
	 * Queries the database and retrieves items or counts.
	 *
	 * This method is public to allow subclasses to perform JIT manipulation
	 * of the parameters passed into it.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses run() to manage lifecycle, and parse_query() and
	 *              get_items() to manage query parsing and retrieval.
	 *
	 * @param array<string, mixed>|string $query Array or URL query string of parameters.
	 * @return list<object>|int Array of items, or number of items when 'count' is passed as a query var.
	 */
	public function query( $query = array() ) {
		$result = $this->run(
			function () use ( $query ) {
				$this->parse_query( $query );

				return $this->get_items();
			}
		);

		/** @var list<object>|int $result */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		return $result;
	}

	/** Private Setters *******************************************************/

	/**
	 * Set up the table name if not already set in the class.
	 *
	 * Falls back to item_name_plural (with hyphens replaced by underscores),
	 * then to a snake_case derivation of the short class name.
	 *
	 * This happens before the table alias and prefixes are applied.
	 *
	 * @since 3.0.0
	 */
	private function set_table_name(): void {

		// Bail if table name already set.
		if ( ! empty( $this->table_name ) ) {
			return;
		}

		// Derive from item_name_plural if possible, replacing "-" with "_".
		if ( ! empty( $this->item_name_plural ) ) {
			$this->table_name = str_replace( '-', '_', $this->item_name_plural );
			return;
		}

		// Derive from the short class name as a last resort.
		$parts            = explode( '\\', static::class );
		$short            = end( $parts );
		$short            = (string) preg_replace( '/Query$/i', '', $short );
		$this->table_name = strtolower( (string) preg_replace( '/([A-Z])/', '_$1', lcfirst( $short ) ) );
	}

	/**
	 * Set up the table alias if not already set in the class.
	 *
	 * This happens before prefixes are applied.
	 *
	 * @since 3.0.0
	 */
	private function set_table_alias(): void {
		if ( empty( $this->table_alias ) ) {
			$this->table_alias = $this->first_letters( $this->table_name );
		}
	}

	/**
	 * Set up the cache group if not already set in the class.
	 *
	 * Falls back to item_name_plural (with underscores replaced by hyphens),
	 * then to table_name (already resolved by set_table_name() at this point).
	 *
	 * This happens after the table name is resolved and before prefixes are
	 * applied, so set_prefixes() always sees a non-empty cache group.
	 *
	 * @since 3.0.0
	 */
	private function set_cache_group(): void {

		// Bail if cache group already set.
		if ( ! empty( $this->cache_group ) ) {
			return;
		}

		// Derive from item_name_plural if possible, replacing "_" with "-".
		if ( ! empty( $this->item_name_plural ) ) {
			$this->cache_group = str_replace( '_', '-', $this->item_name_plural );
			return;
		}

		// Fall back to the table name.
		$this->cache_group = str_replace( '_', '-', $this->table_name );
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
	 * @since 3.0.0
	 */
	private function set_prefixes(): void {
		$this->table_name  = $this->apply_prefix( $this->table_name );
		$this->table_alias = $this->apply_prefix( $this->table_alias );
		$this->cache_group = $this->apply_prefix( $this->cache_group, '-' );
	}

	/**
	 * Set up the Schema object.
	 *
	 * @since 3.0.0
	 */
	private function set_schema(): void {

		// Accept a Schema object passed directly via constructor or property assignment.
		if ( $this->table_schema instanceof Schema ) {
			$this->schema_object = $this->table_schema;
			return;
		}

		// Default log context.
		$log_error = true;
		$context   = array(
			'schema' => $this->table_schema,
		);

		// Maybe invoke a new table schema class.
		if ( ! empty( $this->table_schema ) && class_exists( $this->table_schema ) ) {
			try {
				$this->schema_object = new $this->table_schema();
				$log_error           = false;
			} catch ( \Throwable $exception ) {
				$context['exception']         = get_class( $exception );
				$context['exception_message'] = $exception->getMessage();
			}
		}

		// A schema without get_columns() is not usable by Query.
		if ( ( false === $log_error ) && ! is_callable( array( $this->schema_object, 'get_columns' ) ) ) {
			$log_error         = true;
			$context['method'] = 'get_columns';
		}

		// Maybe log schema setup failure.
		if ( true === $log_error ) {
			$this->log( 'error', 'query_schema_unavailable', 'Query schema could not be loaded.', $context );
		}
	}

	/**
	 * Set the default item shape if none exists.
	 *
	 * @since 1.0.0
	 */
	private function set_item_shape(): void {

		// Item shape.
		if ( empty( $this->item_shape ) || ! class_exists( $this->item_shape ) ) {
			$this->item_shape = __NAMESPACE__ . '\\Row';
		}
	}

	/**
	 * Populate $query_var_parsers with the default set of Parser class names.
	 *
	 * Only runs when $query_var_parsers is empty, so a subclass can replace
	 * the entire list by declaring the property before sunrise() is called.
	 *
	 * @since 3.0.0
	 */
	private function set_query_var_parsers(): void {
		if ( empty( $this->query_var_parsers ) ) {
			$this->query_var_parsers = $this->get_query_var_parser_classes();
		}
	}

	/**
	 * Set default query vars based on columns.
	 *
	 * @since 1.0.0
	 * @since 3.0.0
	 */
	private function set_query_var_defaults(): void {

		// Default query variable value.
		$this->query_var_default_value = function_exists( 'random_bytes' )
			? $this->apply_prefix( bin2hex( random_bytes( 18 ) ) )
			: $this->apply_prefix( uniqid( '_', true ) );

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Default query variables.
		$this->query_var_defaults = array(

			// Statements.
			'explain'           => false,
			'select'            => '',

			// Fields.
			'fields'            => '',
			'groupby'           => '',

			// Boundaries.
			'number'            => 100,
			'offset'            => '',
			'orderby'           => $primary,
			'order'             => 'DESC',

			// COUNT(*).
			'count'             => false,

			// Disable row count.
			'no_found_rows'     => true,

			// Caching.
			'cache_results'     => true,
			'update_item_cache' => true,
			'update_meta_cache' => true,
		);

		/* Query Parsers ******************************************************/

		// Setup parsers array.
		$this->parsers = array();

		// Loop through query var parsers.
		foreach ( $this->query_var_parsers as $class ) {

			// Skip if no class.
			if ( ! class_exists( $class ) ) {
				continue;
			}

			// Instantiate to read descriptor properties.
			/** @var \BerlinDB\Database\Parsers\Base $parser */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$parser = new $class();

			// Setup the parser.
			$this->parsers[ $parser->name ] = $parser;

			// Maybe add query var alone.
			if ( ! empty( $parser->query_var ) ) {
				$this->query_var_defaults[ $parser->query_var ] = ( null === $parser->default )
					? $this->query_var_default_value
					: $parser->default;
			}

			// Get column names.
			$columns = $this->get_column_names( $parser->column_filter );

			// Add to defaults.
			if ( ! empty( $columns ) ) {
				foreach ( $columns as $column ) {
					$key                              = "{$column}{$parser->column_suffix}";
					$this->query_var_defaults[ $key ] = ( null === $parser->default )
						? $this->query_var_default_value
						: $parser->default;
				}
			}
		}
	}

	/**
	 * Set query_clauses by parsing $query_vars.
	 *
	 * @since 3.0.0
	 */
	private function set_query_clauses(): void {
		$this->set_current( 'query_clauses', $this->parse_query_vars() );
	}

	/**
	 * Set the request_clauses.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses parse_query_clauses() with support for new clauses.
	 */
	private function set_request_clauses(): void {
		$this->set_current( 'request_clauses', $this->parse_query_clauses() );
	}

	/**
	 * Set the request SQL string.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses parse_request_clauses() on request_clauses.
	 */
	private function set_request(): void {
		$this->set_current( 'request', $this->parse_request_clauses() );
	}

	/**
	 * Set items by mapping them through the single item callback.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Moved 'count' logic back into get_items().
	 * @param list<int|string> $item_ids List of item IDs.
	 */
	private function set_items( $item_ids = array() ): void {

		// Validate primary column values.
		$callback = array( $this, 'shape_item_id' );
		$item_ids = array_map( $callback, $item_ids );

		// Prime item caches.
		$this->prime_item_caches( $item_ids );

		// Shape the items.
		$this->items = $this->shape_items( $item_ids );
	}

	/**
	 * Populates found_items for the current query.
	 *
	 * If the limit clause was used.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses filter_found_items_query().
	 *
	 * @param mixed $item_ids Optional array of item IDs, or count from a COUNT query.
	 */
	private function set_found_items( $item_ids = array() ): void {

		/**
		 * Default to count of item IDs.
		 *
		 * This is relevant for any kind of query. Either it is literal item IDs
		 * or it is the number of results returned by a 'count' and 'groupby'
		 * query.
		 */
		$retval = count( (array) $item_ids );

		/**
		 * Count query.
		 *
		 * Possibly grouping results by some other columns.
		 */
		if ( $this->get_query_var( 'count' ) ) {

			// Not grouped.
			if ( is_numeric( $item_ids ) && ! $this->get_query_var( 'groupby' ) ) {
				$retval = $item_ids;
			}

			/**
			 * Maybe perform a second COUNT(*) query immediately if:
			 *
			 * - 'count' query var is not truthy
			 * - 'no_found_row' query var is not truthy
			 * - 'number' query var is not falsy
			 *
			 * This second query uses most of the previously parsed $request_clauses
			 * and overrides a few to correct the SQL syntax.
			 *
			 * @since 3.0.0 Performs a COUNT(*) query using $request_clauses.
			 */
		} elseif ( ! $this->get_query_var( 'no_found_rows' ) && $this->get_query_var( 'number' ) ) {

			// Override a few request clauses.
			$r = wp_parse_args(
				array(
					'fields'  => 'COUNT(*)',
					'limits'  => '',
					'orderby' => '',
				),
				$this->get_current_array( 'request_clauses' )
			);

			// Parse the new clauses.
			$query = $this->parse_request_clauses( $r );

			// Filter the found items query.
			$query = $this->filter_found_items_query( $query );

			// Get the database interface.
			$db = $this->db();

			// Maybe query for found items.
			if ( ! empty( $query ) ) {
				$retval = $this->db()->get_var( $query );
			}
		}

		// Set found items.
		$this->set_current( 'found_items', (int) $retval );
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
	 * @param string $key Query variable key.
	 * @param string $value The value.
	 */
	public function set_query_var( $key = '', $value = '' ): void {
		$this->query_var_defaults[ $key ] = $value;
		$this->query_vars[ $key ]         = $value;
	}

	/**
	 * Check whether a query variable strictly equals the unique default
	 * starting value.
	 *
	 * @since 1.1.0
	 * @param string $key Query variable key.
	 * @return bool
	 */
	public function is_query_var_default( $key = '' ) {
		return (bool) ( $this->get_query_var( $key ) === $this->query_var_default_value );
	}

	/**
	 * Is a column valid?
	 *
	 * @since 3.0.0
	 * @param string $column_name Column name.
	 * @return bool
	 */
	private function is_valid_column( $column_name = '' ) {

		// Bail if column name not valid string.
		if ( empty( $column_name ) || ! is_string( $column_name ) ) {
			return false;
		}

		// Return if column exists.
		return (bool) $this->get_column_by( array( 'name' => $column_name ) );
	}

	/** Public Columns ********************************************************/

	/**
	 * Return array of column names.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Pass $args and $operator to filter names.
	 *              No longer calls array_flip().
	 *
	 * @param array<string, mixed> $args     Arguments to filter columns by.
	 * @param string               $operator Optional. The logical operation to perform.
	 * @return list<string>
	 */
	public function get_column_names( $args = array(), $operator = 'and' ) {
		return array_values( array_filter( $this->get_columns( $args, $operator, 'name' ), 'is_string' ) );
	}

	/**
	 * Return the primary database column name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Default "id", Primary column name if not empty
	 */
	public function get_primary_column_name() {
		return $this->get_column_field( array( 'primary' => true ), 'name', 'id' );
	}

	/**
	 * Get a column from an array of arguments.
	 *
	 * @since 1.0.0
	 *
	 * @template TDefault
	 * @param array<string, mixed> $args     Arguments to get a column by.
	 * @param string               $field    Field to get from a column.
	 * @param TDefault             $fallback Fallback to use if no field is set.
	 * @return mixed Value of the requested field, or $fallback if not found.
	 * @phpstan-return ($fallback is false ? mixed : TDefault)
	 */
	public function get_column_field( $args = array(), $field = '', $fallback = false ) {

		// Get the column.
		$column = $this->get_column_by( $args );

		// Return field, or fallback.
		return isset( $column->{$field} )
			? $column->{$field}
			: $fallback;
	}

	/**
	 * Get a column from an array of arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Arguments to get a column by.
	 * @return \BerlinDB\Database\Kern\Column|false Column object, or false if not found.
	 */
	public function get_column_by( $args = array() ) {

		// Filter columns.
		$filter = $this->get_columns( $args );

		// Return column or false.
		$column = ! empty( $filter )
			? reset( $filter )
			: false;

		return $column instanceof Column ? $column : false;
	}

	/**
	 * Get columns from an array of arguments.
	 *
	 * Function arguments are passed into wp_filter_object_list() to filter the
	 * array of columns as needed.
	 *
	 * @since 1.0.0
	 * @since 3.0.0
	 *
	 * @static array               $columns  Local static copy of columns, abstracted to
	 *                                       support different storage locations.
	 * @param array<string, mixed> $args     Arguments to filter columns by.
	 * @param string               $operator Optional. The logical operation to perform.
	 * @param bool|string          $field    Optional. A field from the object to place
	 *                                       instead of the entire object. Default false.
	 * @return Column[]|list<mixed> Array of Column objects, or field values if $field is set.
	 */
	public function get_columns( $args = array(), $operator = 'and', $field = false ) {

		// Default columns.
		$columns = array();

		// Legacy columns.
		if ( ! empty( $this->columns ) ) {
			$columns = $this->columns;
		}

		// Columns from Schema.
		if ( is_callable( array( $this->schema_object, 'get_columns' ) ) ) {

			// Get the columns from the schema object method.
			$schema_columns = $this->schema_object->get_columns();

			// Use column objects from the schema if not empty.
			if ( ! empty( $schema_columns ) ) {
				$columns = $schema_columns;
			}
		}

		// Column::$type is stored uppercase; match that convention so callers can
		// pass either case (e.g. 'json' or 'JSON') and get consistent results.
		if ( isset( $args[ 'type' ] ) && is_string( $args[ 'type' ] ) ) {
			$args[ 'type' ] = strtoupper( $args[ 'type' ] );
		}

		// Filter columns.
		$filter = wp_filter_object_list( $columns, $args, $operator, $field );

		// Return columns or empty array.
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
	 * @since 3.0.0
	 * @template TDefault
	 * @param string              $key      Name of property to compare $values to.
	 * @param array<mixed>|string $values   Values to get a column by. Scalar values are wrapped in an array.
	 * @param string              $field    Field to get from a column.
	 * @param TDefault            $fallback Fallback to use if no field is set.
	 * @return list<mixed>
	 * @phpstan-return ($fallback is false ? list<mixed> : list<TDefault>)
	 */
	public function get_columns_field_by( $key = '', $values = array(), $field = '', $fallback = false ) {

		// Bail if no values.
		if ( empty( $values ) ) {
			return array();
		}

		// Allow scalar values.
		if ( is_scalar( $values ) ) {
			$values = array( $values );
		}

		// Maybe fallback to $key.
		if ( empty( $field ) ) {
			$field = $key;
		}

		// Default return value.
		$retval = array();

		// Get the column fields.
		foreach ( $values as $value ) {
			$args     = array( $key => $value );
			$retval[] = $this->get_column_field( $args, $field, $fallback );
		}

		// Return fields of columns.
		return $retval;
	}

	/**
	 * Get a column name, possibly with the $table_alias append.
	 *
	 * @since 3.0.0
	 * @param string $column_name Column name.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	public function get_column_name_aliased( $column_name = '', $alias = true ) {

		// Default return value.
		$retval = $column_name;

		/**
		 * Maybe prepend the table alias.
		 *
		 * Also add a period as a separator.
		 */
		if ( true === $alias ) {
			$retval = $this->get_table_alias() . '.' . $column_name;
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Get the backtick-quoted alias.column_name string.
	 *
	 * @since 3.0.0
	 * @param string $column_name Column name.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	public function get_quoted_column_name_aliased( $column_name = '', $alias = true ) {

		// Delegate to the Column object when one exists in the schema.
		$column_object = $this->get_column_by( array( 'name' => $column_name ) );

		// Column object exists.
		if ( ! empty( $column_object ) ) {

			// Maybe get the table alias for the column name.
			$table_alias = ( true === $alias )
				? $this->get_table_alias()
				: '';

			// Return the column name, with alias if requested.
			return $column_object->get_name_sql( $table_alias );
		}

		// Fallback for non-schema identifiers (e.g. meta table columns).
		$retval = $this->quote_identifier( $column_name );

		// Maybe prepend the quoted table alias.
		if ( true === $alias ) {
			$retval = $this->quote_identifier( $this->get_table_alias() ) . '.' . $retval;
		}

		// Return SQL.
		return $retval;
	}

	/** Public Parsers ********************************************************/

	/**
	 * Get registered parsers, optionally filtered by property values.
	 *
	 * Mirrors get_columns() — pass an $args array of property => value pairs
	 * to narrow the result set. For example:
	 *
	 *   get_parsers( array( 'sortable' => true ) )
	 *
	 * returns only parsers that contribute ORDER BY SQL via get_orderby_sql().
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $args     Optional property => value pairs to filter by.
	 * @param string               $operator Comparison operator: 'and' or 'or'. Default 'and'.
	 * @param mixed                $field    Optional. Return this property from each match instead of the full object.
	 *
	 * @return list<\BerlinDB\Database\Parsers\Base> Filtered array of parser objects (or field values).
	 */
	public function get_parsers( $args = array(), $operator = 'and', $field = false ) {

		// Determine source.
		$current_parsers = $this->get_current_array( 'parsers' );
		$source          = ! empty( $current_parsers )
			? $current_parsers
			: $this->parsers;

		// Filter parsers.
		$field_val = is_string( $field ) ? $field : false;
		$filter    = wp_filter_object_list( $source, $args, $operator, $field_val );

		// Return parsers or empty array.
		return ! empty( $filter )
			? array_values( $filter )
			: array();
	}

	/** Public Getters ********************************************************/

	/**
	 * Return the fully-qualified table name for use in SQL statements.
	 *
	 * The WordPress table prefix ($wpdb->prefix) is resolved by looking up
	 * $wpdb->{$this->table_name} — a dynamic property that Table::set_db_interface()
	 * registers when the corresponding Table class is instantiated. This means
	 * multisite prefix changes (triggered by the switch_blog action) are always
	 * reflected here automatically, because Table owns and updates that property.
	 *
	 * If $wpdb does not have the property registered (i.e. the Table class has
	 * not been instantiated), this falls back to $this->table_name, which carries
	 * only the plugin prefix — not the WordPress table prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_table_name() {

		// Return SQL.
		return $this->db()->get_table_prefix( $this->table_name );
	}

	/**
	 * Return the table alias for use in SQL statements.
	 *
	 * The alias is set during sunrise() and carries only the plugin prefix
	 * ($this->prefix). It is never looked up via $wpdb — aliases are
	 * SQL-local and do not require the WordPress table prefix.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_table_alias() {

		// Return SQL.
		return $this->table_alias;
	}

	/**
	 * Return the final SQL string from the most recent query() call.
	 *
	 * @since 3.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_request() {
		return $this->get_current_string( 'request' ) ?? '';
	}

	/**
	 * Return the total number of items found by the most recent query() call.
	 *
	 * @since 3.0.0
	 * @api
	 *
	 * @return int
	 */
	public function get_found_items() {
		return $this->get_current_int( 'found_items' );
	}

	/**
	 * Return the number of pages from the most recent query() call.
	 *
	 * @since 3.0.0
	 * @api
	 *
	 * @return int
	 */
	public function get_max_num_pages() {
		return $this->get_current_int( 'max_num_pages' );
	}

	/**
	 * Return the singular item name.
	 *
	 * @since 3.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_item_name() {
		return $this->item_name;
	}

	/**
	 * Return the plural item name.
	 *
	 * @since 3.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_item_name_plural() {
		return $this->item_name_plural;
	}

	/**
	 * Get the default query parser class list.
	 *
	 * This is filterable so plugins can register additional parser classes
	 * without replacing the entire Query implementation.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public function get_query_var_parser_classes() {

		// Default set of query parser classes.
		$parsers = array(
			'BerlinDB\\Database\\Parsers\\By',
			'BerlinDB\\Database\\Parsers\\In',
			'BerlinDB\\Database\\Parsers\\NotIn',
			'BerlinDB\\Database\\Parsers\\Search',
			'BerlinDB\\Database\\Parsers\\Date',
			'BerlinDB\\Database\\Parsers\\Meta',
			'BerlinDB\\Database\\Parsers\\Compare',
		);

		// Return the query var parser classes, filtered.
		return $this->filter_query_var_parsers( $parsers );
	}

	/**
	 * Get the value of a single query variable by key.
	 *
	 * Returns null when the key is not present in $query_vars. Exposed as
	 * public so parser hooks (e.g. In::get_orderby_sql()) can read the
	 * query vars set for the current run.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key Query var key.
	 * @return mixed Value, or null if not set.
	 */
	public function get_query_var( $key = '' ) {
		return isset( $this->query_vars[ $key ] )
			? $this->query_vars[ $key ]
			: null;
	}

	/** Private Getters *******************************************************/

	/**
	 * Return the current UTC time in MySQL DATETIME format.
	 *
	 * Used by add_item() and update_item() as the PHP-side equivalent of
	 * MySQL's CURRENT_TIMESTAMP. Always UTC, never local server time.
	 *
	 * @since 1.0.0
	 *
	 * @return string Current UTC time as 'Y-m-d H:i:s'.
	 */
	private function get_current_time() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Get a single database row by any column and value, skipping cache.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses is_valid_column()
	 *
	 * @param string $column_name  Name of database column.
	 * @param mixed  $column_value Value to query for.
	 * @return object|false False if empty/error, Object if successful
	 */
	private function get_item_raw( $column_name = '', $column_value = '' ) {

		// Bail if value is non-scalar, boolean false, or empty string.
		// Intentionally allows 0 and '0' — both are valid column values.
		if ( ! is_scalar( $column_value ) || false === $column_value || '' === $column_value ) {
			return false;
		}

		// Bail if invalid column.
		if ( ! $this->is_valid_column( $column_name ) ) {
			return false;
		}

		// Get query parts.
		$table       = $this->get_table_name();
		$pattern_str = $this->get_column_field( array( 'name' => $column_name ), 'pattern', '%s' );

		// Query database.
		$query  = "SELECT * FROM {$table} WHERE {$column_name} = {$pattern_str} LIMIT 1";
		$select = $this->db()->prepare( $query, $column_value );
		$result = $this->db()->get_row( $select );

		// Bail on failure.
		if ( ! $this->is_success( $result ) || ! is_object( $result ) ) {
			return false;
		}

		// Return row.
		return $result;
	}



	/**
	 * Retrieves a list of items matching the query vars.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int|string, mixed>|int Array of items, or number of items when 'count' is passed as a query var.
	 */
	private function get_items() {

		// Generate action name based on the plural item name.
		$action_name = $this->apply_prefix( 'pre_get_' . $this->get_item_name_plural() );

		/**
		 * Fires before object items are retrieved.
		 *
		 * @since 1.0.0
		 *
		 * @param \BerlinDB\Database\Kern\Query $query Current instance passed by reference.
		 */
		if ( '' !== $action_name ) {
			do_action_ref_array(
				$action_name,
				array(
					&$this,
				)
			);
		}

		// Check the cache.
		$cache_results = (bool) $this->get_query_var( 'cache_results' );
		$cache_key     = $this->get_cache_key();
		$cache_value   = ( true === $cache_results )
			? $this->cache_get( $cache_key, $this->cache_group )
			: false;

		// No cache value.
		if ( false === $cache_value ) {

			// Query for item IDs.
			$result = $this->get_item_ids();

			// Set the number of found items.
			$this->set_found_items( $result );

			// Format the cached value.
			$cache_value = array(
				'item_ids'    => $result,
				'found_items' => $this->get_current_int( 'found_items' ),
			);

			// Only store when caching is enabled for this query.
			if ( true === $cache_results ) {
				$this->cache_add( $cache_key, $cache_value, $this->cache_group );
			}

			// Value exists in cache.
		} elseif ( is_array( $cache_value ) ) {
			$result          = $cache_value[ 'item_ids' ] ?? array();
			$found_items_val = $cache_value[ 'found_items' ] ?? 0;
			$this->set_current( 'found_items', (int) $found_items_val );
		} else {
			$result = array();
			$this->set_current( 'found_items', 0 );
		}

		// Pagination.
		$found_items = $this->get_current_int( 'found_items' );
		if ( ! empty( $found_items ) ) {
			$number = $this->get_query_var( 'number' );

			if ( is_int( $number ) || is_string( $number ) ) {
				$number_int = (int) $number;
				if ( ! empty( $number_int ) ) {
					$this->set_current( 'max_num_pages', (int) ceil( $found_items / $number_int ) );
				}
			}
		}

		// Return count results directly — already int (get_var) or array (groupby).
		if ( $this->get_query_var( 'count' ) ) {
			$this->items = $result;
			return $this->items;
		}

		// Set items from result.
		if ( is_array( $result ) ) {
			/** @var list<int|string> $result */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$this->set_items( $result );
		} else {
			$this->set_items( array() );
		}

		// Return array of items.
		return is_array( $this->items ) ? $this->items : array();
	}

	/**
	 * Used internally to get a list of item IDs matching the query vars.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses wp_parse_list() instead of wp_parse_id_list()
	 *
	 * @return array<bool|float|int|string>|array<string, mixed>[]|int Array of item IDs for a full query, or int/rows for a count query.
	 */
	private function get_item_ids() {

		// Setup the query clauses.
		$this->set_query_clauses();

		// Setup request.
		$this->set_request_clauses();
		$this->set_request();

		// Get the request SQL string.
		$request = $this->get_current_string( 'request' );

		// Return count.
		if ( $this->get_query_var( 'count' ) ) {

			// Get vars or results.
			$retval = ! $this->get_query_var( 'groupby' )
				? (int) $this->db()->get_var( $request )
				: (array) $this->db()->get_results( $request, ARRAY_A );

			// Return vars or results.
			return $retval;
		}

		// Get IDs.
		$item_ids = $this->db()->get_col( $request );

		// Return parsed IDs.
		return wp_parse_list( $item_ids );
	}

	/**
	 * Used internally to generate the SQL string for IN and NOT IN clauses.
	 *
	 * The $values being passed in should not be validated, and they will be
	 * escaped before they are concatenated together and returned as a string.
	 *
	 * @since 3.0.0
	 *
	 * @param string                  $column_name Column name.
	 * @param list<int|string>|string $values      Array of values.
	 * @param bool                    $wrap        To wrap in parenthesis.
	 * @param string                  $pattern     Pattern to prepare with.
	 *
	 * @return string Escaped/prepared SQL, possibly wrapped in parenthesis.
	 */
	public function get_in_sql( $column_name = '', $values = array(), $wrap = true, $pattern = '' ) {

		// Bail if no values or invalid column.
		if ( empty( $values ) || ! $this->is_valid_column( $column_name ) ) {
			return '';
		}

		// Fallback to column pattern.
		if ( empty( $pattern ) || ! is_string( $pattern ) ) {
			$pattern = $this->get_column_field( array( 'name' => $column_name ), 'pattern', '%s' );
		}

		// Fill an array of patterns to match the number of values.
		$values   = (array) $values;
		$count    = count( $values );
		$patterns = array_fill( 0, $count, $pattern );

		// Prepare.
		$sql    = implode( ', ', $patterns );
		$retval = $this->db()->prepare( $sql, ...$values );

		// Set return value to empty string if prepare() returns falsy.
		if ( empty( $retval ) ) {
			$retval = '';
		}

		// Wrap them in parenthesis.
		if ( true === $wrap ) {
			$retval = "({$retval})";
		}

		// Return in SQL.
		return $retval;
	}

	/** Private Parsers *******************************************************/

	/**
	 * Parses arguments passed to the item query with default query parameters.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Forces some $query_vars if counting
	 *
	 * @param array<string, mixed>|string $query Query arguments array or string.
	 */
	private function parse_query( $query = array() ): void {

		// Stash the raw query args before any defaults are merged in.
		$this->set_current( 'query_var_originals', wp_parse_args( $query ) );

		// Setup the $query_vars parsed var.
		$this->query_vars = wp_parse_args(
			$this->get_current_array( 'query_var_originals' ),
			$this->query_var_defaults
		);

		// If counting, override some other $query_vars.
		if ( $this->get_query_var( 'count' ) ) {
			$this->query_vars[ 'number' ]            = false;
			$this->query_vars[ 'fields' ]            = '';
			$this->query_vars[ 'orderby' ]           = '';
			$this->query_vars[ 'no_found_rows' ]     = true;
			$this->query_vars[ 'update_item_cache' ] = false;
			$this->query_vars[ 'update_meta_cache' ] = false;
		}

		// Generate action name based on the plural item name.
		$action_name = $this->apply_prefix( 'parse_' . $this->get_item_name_plural() . '_query' );

		/**
		 * Fires after the item query vars have been parsed.
		 *
		 * @since 1.0.0
		 *
		 * @param \BerlinDB\Database\Kern\Query $query Current instance passed by reference.
		 */
		if ( '' !== $action_name ) {
			do_action_ref_array(
				$action_name,
				array(
					&$this,
				)
			);
		}
	}

	/**
	 * Parse all of the $query_vars.
	 *
	 * Optionally accepts an array of custom $query_vars that can be used
	 * instead of the default ones.
	 *
	 * Calls filter_query_clauses() on the return value.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $query_vars Optional. Default empty array.
	 *                                         Fallback to Query::query_vars.
	 * @return array<string, mixed> Query clauses, parsed from Query vars.
	 */
	private function parse_query_vars( $query_vars = array() ) {

		// Maybe fallback to $query_vars.
		if ( empty( $query_vars ) && ! empty( $this->query_vars ) ) {
			$query_vars = $this->query_vars;
		}

		// Parse arguments.
		$r = wp_parse_args( $query_vars );

		// Parse $query_vars.
		$join_where = $this->parse_join_where( $r );

		// Parse all clauses.
		$clauses = array(
			'explain' => $this->parse_explain( $r[ 'explain' ] ),
			'select'  => $this->parse_select(),
			'fields'  => $this->parse_fields( $r[ 'fields' ], $r[ 'count' ], $r[ 'groupby' ] ),
			'from'    => $this->parse_from(),
			'join'    => $this->parse_join_clause( $join_where[ 'join' ] ),
			'where'   => $this->parse_where_clause( $join_where[ 'where' ] ),
			'groupby' => $this->parse_groupby( $r[ 'groupby' ], 'GROUP BY' ),
			'orderby' => $this->parse_orderby( $r[ 'orderby' ], $r[ 'order' ], 'ORDER BY' ),
			'limits'  => $this->parse_limits( $r[ 'number' ], $r[ 'offset' ] ),
		);

		// Return clauses.
		return $this->filter_query_clauses( $clauses );
	}

	/**
	 * Parse the 'join' and 'where' $query_vars for all known columns.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $args Query vars.
	 * @return array{join: list<string>, where: list<string>} Array of 'join' and 'where' clauses.
	 */
	private function parse_join_where( $args = array() ) {

		// Maybe fallback to $query_vars.
		if ( empty( $args ) && ! empty( $this->query_vars ) ) {
			$args = $this->query_vars;
		}

		// Parse arguments.
		$r = wp_parse_args( $args );

		// Parse the join/where parsers.
		$parsers = $this->parse_join_where_parsers( $r );

		// Default return value.
		$retval = array(
			'join'  => array(),
			'where' => array(),
		);

		// Set join subclauses — strip string keys so parse_join_clause() receives a plain list.
		if ( ! empty( $parsers[ 'join' ] ) ) {
			$retval[ 'join' ] = array_values( $parsers[ 'join' ] );
		}

		// Set where subclauses — strip string keys so parse_where_clause() receives a plain list.
		if ( ! empty( $parsers[ 'where' ] ) ) {
			$retval[ 'where' ] = array_values( $parsers[ 'where' ] );
		}

		// Return join and where clauses.
		return $retval;
	}

	/**
	 * Parse join/where subclauses for query var parser objects.
	 *
	 * Used by parse_join_where().
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $query_vars Query vars.
	 * @return array{join: array<string, mixed>, where: array<string, string>}
	 */
	private function parse_join_where_parsers( $query_vars = array() ) {

		// Bail if no parsers.
		if ( empty( $this->parsers ) ) {
			return array(
				'join'  => array(),
				'where' => array(),
			);
		}

		// Default values.
		$join    = array();
		$where   = array();
		$parsers = array();

		// Loop through parsers.
		foreach ( $this->parsers as $key => $descriptor ) {

			// Derive the class from the already-instantiated descriptor.
			$class = get_class( $descriptor );

			// Default to all $query_vars.
			$qv = $query_vars;

			// Check if $query_vars contains the query_var for this parser.
			$parser_query_var = $descriptor->get_query_var();
			if ( ! is_null( $parser_query_var ) && ! empty( $query_vars[ $parser_query_var ] ) ) {

				/**
				 * Narrow the scope to just this parser's query_var sub-array,
				 * but only when the user has explicitly set it to an array value
				 * (i.e. not the default sentinel and not a scalar). This restricts
				 * narrowing to Meta, Date, and Compare parsers, which expect an
				 * array of clauses (e.g. meta_query, date_query, compare_query).
				 *
				 * By has a null query_var so this branch never fires.
				 * In/NotIn: users set {col}__in at the top level; in_query stays
				 *   at its sentinel so the sentinel check below stays false.
				 * Search: uses a scalar 'search' key at the top level of
				 *   $query_vars; it needs the full array so its clause handler
				 *   can read $clause[ 'search' ] and $clause[ 'search_columns' ].
				 *   The is_array() guard keeps it on the full $query_vars.
				 */
				if (
					$this->query_var_default_value !== $query_vars[ $parser_query_var ]
					&&
					is_array( $query_vars[ $parser_query_var ] )
				) {
					$qv = $query_vars[ $parser_query_var ];
				}
			}

			// Instantiate the active parser for this query run.
			$parsers[ $key ] = new $class( $qv, $this );
			$new_parser      = $parsers[ $key ];

			// Default no subclauses.
			$subclauses = false;

			// Set the callback.
			$callback = array( $new_parser, 'get_join_where_clauses' );

			// Try to get the SQL subclauses.
			if ( is_callable( $callback ) ) {
				$subclauses = call_user_func( $callback );
			}

			// Skip if no SQL subclauses.
			if ( false === $subclauses ) {
				continue;
			}

			// Set join.
			if ( ! empty( $subclauses[ 'join' ] ) ) {
				$join[ $key ] = $subclauses[ 'join' ];
			}

			// Set where (removing " AND " from subclauses).
			if ( ! empty( $subclauses[ 'where' ] ) ) {
				$where[ $key ] = (string) preg_replace( '/^\s*AND\s*/', '', $subclauses[ 'where' ] );
			}
		}

		// Store completed parser instances so post-parse hooks can read their state.
		$this->set_current( 'parsers', $parsers );

		// Return join/where subclauses.
		return array(
			'join'  => $join,
			'where' => $where,
		);
	}

	/**
	 * Parse a single query variable value.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $query_vars Array of query variables.
	 * @param string               $key Query variable key.
	 *
	 * @return bool|int|string|array<mixed> False if not set or default.
	 *                                      Value if object or array.
	 *                                      Attempts to parse a comma-separated string
	 *                                      of possible keys or numbers.
	 */
	public function parse_query_var( $query_vars = array(), $key = '' ) {

		// Bail if no query vars exist for that ID.
		if ( ! isset( $query_vars[ $key ] ) ) {
			return false;
		}

		// Get the value.
		$value = $query_vars[ $key ];

		// Bail if equal to the exact default random value.
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
			is_int( $value )
			||
			is_numeric( $value )
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

			// Bail if string is over 200s chars long.
			if ( strlen( $value ) > 200 ) {
				return array( $value );
			}

			// Contains comma?
			$comma = strpos( $value, ',' );

			// Bail if no comma.
			if ( false === $comma ) {
				return array( $value );
			}

			// Contains space?
			$space = strpos( $value, ' ' );

			// Bail if space is before comma.
			if ( ( false !== $space ) && ( $space < $comma ) ) {
				return array( $value );
			}

			// Bail if first comma is more than 20 letters in.
			if ( $comma >= 20 ) {
				return array( $value );
			}

			// Split by comma (and maybe spaces).
			return preg_split( '#,\s*#', $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		// Pass the value through.
		return array( $value );
	}

	/**
	 * Parse if query to be EXPLAIN'ed.
	 *
	 * @since 3.0.0
	 * @param bool $explain Default false. True to EXPLAIN.
	 * @return string
	 */
	private function parse_explain( $explain = false ) {

		// Maybe fallback to $query_vars.
		if ( empty( $explain ) ) {
			$explain = $this->get_query_var( 'explain' );
		}

		// Default return value.
		$retval = '';

		// Maybe explaining.
		if ( ! empty( $explain ) ) {
			$retval = 'EXPLAIN';
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Parse the "SELECT" part of the SQL.
	 *
	 * @since 3.0.0
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
	 * @since 3.0.0 Moved COUNT() SQL to parse_count() and uses parse_groupby()
	 *              when counting to satisfy MySQL 8 and higher.
	 *
	 * @param string|string[] $fields Field or fields to return.
	 * @param bool            $count Whether to return a count instead of results.
	 * @param string|string[] $groupby Column name to group results by.
	 * @param bool            $alias Whether to include the table alias prefix.
	 * @return string
	 */
	private function parse_fields( $fields = '', $count = false, $groupby = '', $alias = true ) {

		// Maybe fallback to $query_vars.
		if ( empty( $count ) ) {
			$count = $this->get_query_var( 'count' );
		}

		// Default return value.
		$retval = '';

		// Counting, so use groupby.
		if ( ! empty( $count ) ) {

			// Use count instead.
			$retval = $this->parse_count( (bool) $count, is_array( $groupby ) ? implode( ', ', $groupby ) : $groupby );

			// Not counting, so use primary column.
		} else {

			// Maybe fallback to $query_vars.
			if ( empty( $fields ) ) {
				$fields = $this->get_query_var( 'fields' );
			}

			// Get the primary column name.
			$primary = $this->get_primary_column_name();

			// Default return value.
			$retval = $this->get_quoted_column_name_aliased( $primary, $alias );
		}

		// Return fields.
		return $retval;
	}

	/**
	 * Parse if counting.
	 *
	 * When counting with groups, parse_fields() will return the required SQL to
	 * prevent errors.
	 *
	 * @since 3.0.0
	 * @param bool   $count Whether to return a count instead of results.
	 * @param string $groupby Column name to group results by.
	 * @param string $name Column name.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	private function parse_count( $count = false, $groupby = '', $name = 'count', $alias = true ) {

		// Maybe fallback to $query_vars.
		if ( empty( $count ) ) {
			$count = $this->get_query_var( 'count' );
		}

		// Bail if not counting.
		if ( empty( $count ) ) {
			return '';
		}

		// Default return value.
		$retval = 'COUNT(*)';

		// Check for "GROUP BY".
		$groupby_names = $this->parse_groupby( $groupby, '', $alias );

		// Reformat if grouping counts together.
		if ( ! empty( $groupby_names ) ) {
			$retval = "{$groupby_names}, {$retval} as {$name}";
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Parse which table to query and whether to follow it with an alias.
	 *
	 * @since 3.0.0
	 * @param string $table Optional. Default empty string.
	 *                      Fallback to get_table_name().
	 * @param string $alias Optional. Default empty string.
	 *                      Fallback to get_table_alias().
	 * @return string
	 */
	private function parse_from( $table = '', $alias = '' ) {

		// Maybe fallback to get_table_name().
		if ( empty( $table ) ) {
			$table = $this->get_table_name();
		}

		// Maybe fallback to get_table_alias().
		if ( empty( $alias ) ) {
			$alias = $this->get_table_alias();
		}

		// Return.
		return "FROM {$table} {$alias}";
	}

	/**
	 * Parses and sanitizes the 'groupby' keys passed into the item query.
	 *
	 * @since 1.0.0
	 *
	 * @param string $groupby Column name to group results by.
	 * @param string $before SQL fragment to prepend.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	private function parse_groupby( $groupby = '', $before = '', $alias = true ) {

		// Maybe fallback to $query_vars.
		if ( empty( $groupby ) ) {
			$groupby = $this->get_query_var( 'groupby' );
		}

		// Bail if empty.
		if ( empty( $groupby ) ) {
			return '';
		}

		// Maybe cast to array.
		if ( ! is_array( $groupby ) ) {
			$groupby = (array) $groupby;
		}

		$groupby = array_values( $groupby );

		// Get the intersection of allowed column names to groupby columns.
		$intersect = $this->get_columns_field_by( 'name', $groupby );

		// Bail if invalid columns.
		if ( empty( $intersect ) ) {
			return '';
		}

		// Column names array.
		$names = array();

		// Maybe prepend table alias to key.
		foreach ( $intersect as $key ) {
			$names[] = $this->get_quoted_column_name_aliased( $key, $alias );
		}

		// Format column names.
		$retval = implode( ',', $names );

		// Return columns.
		return implode( ' ', array( $before, $retval ) );
	}

	/**
	 * Parse the ORDER BY clause.
	 *
	 * @since 1.0.0 As get_order_by
	 * @since 3.0.0 Renamed to parse_orderby and accepts $orderby, $order, $before, and $alias
	 *
	 * @param string $orderby Column name to order results by.
	 * @param string $order Sort direction (ASC or DESC).
	 * @param string $before SQL fragment to prepend.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	private function parse_orderby( $orderby = '', $order = '', $before = '', $alias = true ) {

		// Maybe fallback to $query_vars.
		if ( empty( $orderby ) ) {
			$orderby = $this->get_query_var( 'orderby' );
		}

		// Bail if counting.
		if ( $this->get_query_var( 'count' ) ) {
			return '';
		}

		// Bail if $orderby is a value that could cancel ordering.
		if ( in_array( $orderby, array( 'none', array(), false, null ), true ) ) {
			return '';
		}

		// Default return value.
		$retval = '';

		// Fallback to default orderby & order.
		if ( empty( $orderby ) ) {
			$parsed = $this->parse_single_orderby( (string) $orderby, $alias );
			$order  = $this->parse_order( $order );
			$retval = "{$parsed} {$order}";

			// Ordering by something, so figure it out.
		} else {

			// Cast orderby as an array.
			$ordersby = (array) $orderby;

			// Fill if numeric.
			if ( wp_is_numeric_array( $ordersby ) ) {
				$ordersby = array_fill_keys( $ordersby, $order );
			}

			// Default return value.
			$orderby_array = array();

			// Loop through orderby's.
			foreach ( $ordersby as $key => $value ) {

				// Parse orderby.
				$parsed = $this->parse_single_orderby( $key, $alias );

				// Skip if empty.
				if ( empty( $parsed ) ) {
					continue;
				}

				// Append parsed orderby to array.
				$orderby_array[] = $parsed . ' ' . $this->parse_order( $value );
			}

			// Only set if valid orderby.
			if ( ! empty( $orderby_array ) ) {
				$retval = implode( ', ', $orderby_array );
			}
		}

		// Bail if nothing to orderby.
		if ( empty( $retval ) && ! empty( $before ) ) {
			return '';
		}

		// Return parsed orderby.
		return implode( ' ', array( $before, $retval ) );
	}

	/**
	 * Parse all of the where clauses.
	 *
	 * @since 3.0.0
	 * @param list<string> $where WHERE SQL clause fragments.
	 * @return string A single SQL statement.
	 */
	private function parse_where_clause( $where = array() ) {

		// Bail if no where.
		if ( empty( $where ) ) {
			return '';
		}

		// Return SQL.
		return 'WHERE ' . implode( ' AND ', $where );
	}

	/**
	 * Parse all of the join clauses.
	 *
	 * @since 3.0.0
	 * @param list<string> $join JOIN SQL clause fragments.
	 * @return string A single SQL statement.
	 */
	private function parse_join_clause( $join = array() ) {

		// Bail if no join.
		if ( empty( $join ) ) {
			return '';
		}

		// Return SQL.
		return implode( ' ', $join );
	}

	/**
	 * Parse all of the SQL query clauses.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $clauses SQL clause fragments.
	 * @return array<string, mixed>
	 */
	private function parse_query_clauses( $clauses = array() ) {

		// Maybe fallback to query_clauses.
		if ( empty( $clauses ) ) {
			$clauses = $this->get_current_array( 'query_clauses' );
		}

		// Default return value.
		$retval = wp_parse_args( $clauses );

		// Return array of clauses.
		return $retval;
	}

	/**
	 * Parse all SQL $request_clauses into a single SQL query string.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $clauses SQL clause fragments.
	 * @return string A single SQL statement.
	 */
	private function parse_request_clauses( $clauses = array() ) {

		// Maybe fallback to request_clauses.
		if ( empty( $clauses ) ) {
			$clauses = $this->get_current_array( 'request_clauses' );
		}

		// Bail if empty clauses.
		if ( empty( $clauses ) ) {
			return '';
		}

		// Remove empties.
		$filtered = array_filter( $clauses );
		$retval   = array_map( 'trim', $filtered );

		// Return SQL.
		return implode( ' ', $retval );
	}

	/**
	 * Parses the 'number' and 'offset' keys passed to the item query.
	 *
	 * @since 3.0.0
	 *
	 * @param int $number Maximum number of items to return.
	 * @param int $offset Number of items to skip.
	 * @return string
	 */
	private function parse_limits( $number = 0, $offset = 0 ) {

		// Default return value.
		$retval = '';

		// No negative numbers.
		$limit  = absint( $number );
		$offset = absint( $offset );

		// Only limit & offset if not limit empty.
		if ( ! empty( $limit ) ) {
			$retval = ! empty( $offset )
				? "LIMIT {$offset}, {$limit}"
				: "LIMIT {$limit}";
		}

		// Return.
		return $retval;
	}

	/**
	 * Parses and sanitizes a single 'orderby' key passed to the item query.
	 *
	 * This method assumes that $orderby is a valid Column name.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses get_in_sql()
	 *
	 * @param string $orderby Field for the items to be ordered by.
	 * @param bool   $alias   Whether to append the table alias.
	 * @return string Value to used in the ORDER BY clause.
	 */
	private function parse_single_orderby( $orderby = '', $alias = true ) {

		// Fallback to primary column.
		if ( empty( $orderby ) ) {
			$orderby = $this->get_primary_column_name();
		}

		// Default return value.
		$retval = '';

		// Ask each sortable parser if it handles this orderby value.
		foreach ( $this->get_parsers( array( 'sortable' => true ) ) as $parser ) {

			// Maybe get the SQL for this parser's orderby.
			$sql = $parser->get_orderby_sql( $orderby, $alias );
			if ( ! empty( $sql ) ) {
				$retval = $sql;
				break;
			}
		}

		// Specific sortable column (only when no parser claimed it).
		if ( empty( $retval ) ) {
			$sortables = $this->get_column_names( array( 'sortable' => true ) );
			if ( in_array( $orderby, $sortables, true ) ) {
				$retval = $this->get_quoted_column_name_aliased( $orderby, $alias );
			}
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Parses an 'order' query variable and cast it to 'ASC' or 'DESC' as
	 * necessary.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Default to 'DESC'
	 *
	 * @param string $order The 'order' query variable.
	 * @return string The sanitized 'order' query variable.
	 */
	private function parse_order( $order = 'DESC' ) {

		// Bail if malformed.
		if ( empty( $order ) || ! is_string( $order ) ) {
			return 'DESC';
		}

		// Ascending or Descending.
		return ( 'ASC' === strtoupper( $order ) )
			? 'ASC'
			: 'DESC';
	}

	/** Private Shapers *******************************************************/

	/**
	 * Shape an item from the database into the type of object it always wanted
	 * to be when it grew up.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $item ID of item, or row from database.
	 * @return object Shaped item object.
	 */
	private function shape_item( $item = 0 ) {

		// Get the item from an ID.
		if ( is_numeric( $item ) ) {
			$item = $this->get_item( (int) $item );
		}

		/*
		 * Decode JSON columns before any early-return or wrapping.
		 *
		 * The database returns raw rows as stdClass objects (via get_row()), so
		 * we must handle both array and object forms here. cast_json() is
		 * idempotent — calling it on an already-decoded array is a no-op.
		 */
		$json_columns = $this->get_columns( array( 'type' => 'json' ) );

		if ( ! empty( $json_columns ) ) {
			if ( is_array( $item ) ) {
				foreach ( $json_columns as $column ) {
					if ( isset( $item[ $column->name ] ) ) {
						$item[ $column->name ] = $column->cast( $item[ $column->name ] );
					}
				}
			} elseif ( is_object( $item ) ) {
				foreach ( $json_columns as $column ) {
					if ( isset( $item->{$column->name} ) ) {
						$item->{$column->name} = $column->cast( $item->{$column->name} );
					}
				}
			}
		}

		// Return the item if it's already shaped.
		$item_shape = $this->get_current_string( 'item_shape' );
		if ( ! empty( $item_shape ) && $item instanceof $item_shape ) {
			return $item;
		}

		// stdClass does not hydrate constructor arguments into properties.
		if ( 'stdClass' === $item_shape ) {
			return (object) $item;
		}

		// Shape the item as needed.
		$item = ! empty( $item_shape )
			? new $item_shape( $item )
			: (object) $item;

		// Return the item object.
		return $item;
	}

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
	 * @since 3.0.0 Added $fields parameter.
	 *
	 * @param list<int|string> $items  Array of item IDs to shape.
	 * @param list<string>     $fields Fields to get from items.
	 * @return array<int|string, mixed>
	 */
	private function shape_items( $items = array(), $fields = array() ) {

		// Maybe fallback to $query_vars.
		if ( empty( $fields ) ) {
			$fields = $this->get_query_var( 'fields' );
		}

		// Force to stdClass if querying for fields.
		$this->set_current( 'item_shape', ! empty( $fields ) ? 'stdClass' : $this->item_shape );

		// Default return value.
		$retval = array();

		// Loop through items and get each item individually.
		if ( ! empty( $items ) ) {
			foreach ( $items as $item ) {
				$shaped = $this->get_item( $item );
				if ( false !== $shaped ) {
					$retval[] = $shaped;
				}
			}
		}

		// Filter the items.
		$retval = $this->filter_items( $retval );

		// Maybe return specific fields.
		if ( ! empty( $fields ) ) {
			if ( is_array( $fields ) ) {
				$fields_list = array_values( array_filter( $fields, 'is_string' ) );
			} elseif ( is_string( $fields ) ) {
				$fields_list = array( $fields );
			} else {
				$fields_list = array();
			}
			$retval = $this->get_item_fields( $retval, $fields_list );
		}

		// Return shaped items.
		return $retval;
	}

	/**
	 * Validate the primary column value of an item.
	 *
	 * Accepts an object, array, or numeric value.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses validate_item_field()
	 *
	 * @param  array<string, mixed>|object|scalar $item The item object or array.
	 * @return int|string
	 */
	private function shape_item_id( $item = 0 ) {

		// Default return value.
		$retval = $item;

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Object item.
		if ( is_object( $item ) && isset( $item->{$primary} ) ) {
			$retval = $item->{$primary};

			// Array item.
		} elseif ( is_array( $item ) && isset( $item[ $primary ] ) ) {
			$retval = $item[ $primary ];
		}

		// Return the validated item ID.
		$validated = $this->validate_item_field( $retval, $primary );
		return ( is_int( $validated ) || is_string( $validated ) ) ? $validated : ( is_scalar( $validated ) ? (string) $validated : 0 );
	}

	/**
	 * Validate a single field of an item.
	 *
	 * Calls Column::validate() on the column.
	 *
	 * @since 3.0.0
	 * @param mixed  $value       Value to validate.
	 * @param string $column_name Name of column.
	 * @return mixed A validated value
	 */
	private function validate_item_field( $value = '', $column_name = '' ) {

		// Get the column.
		$column = $this->get_column_by( array( 'name' => $column_name ) );

		// Bail if no column found.
		if ( empty( $column ) ) {
			return false;
		}

		// Validate.
		return $column->validate( $value );
	}

	/**
	 * Get specific fields from an array of items.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Bails early if empty $fields.
	 *
	 * @param list<object> $items  Array of items to get fields from.
	 * @param list<string> $fields Fields to get from items.
	 * @return list<object>|array<string|int, object>
	 */
	private function get_item_fields( $items = array(), $fields = array() ) {

		// Maybe fallback to $query_vars.
		if ( empty( $fields ) ) {
			$fields = $this->get_query_var( 'fields' );
		}

		// Bail if no fields to get.
		if ( empty( $fields ) ) {
			return $items;
		}

		// Maybe cast to array.
		if ( ! is_array( $fields ) ) {
			$fields = (array) $fields;
		}

		// Default return value.
		$retval = $items;

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// 'ids' is numerically keyed.
		if ( ( 1 === count( $fields ) ) && ( 'ids' === $fields[0] ) ) {
			$retval = wp_list_pluck( $items, $primary );

			// Get fields from items.
		} else {
			$retval         = array();
			$fields_to_flip = array_values(
				array_filter(
					$fields,
					function ( $v ) {
						return is_int( $v ) || is_string( $v );
					}
				)
			);
			/** @var array<int|string> $fields_to_flip */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$fields = array_flip( $fields_to_flip );

			// Loop through items and pluck out the fields.
			foreach ( $items as $item ) {
				$retval[ $item->{$primary} ] = (object) array_intersect_key( (array) $item, $fields );
			}
		}

		// Return the item fields.
		return $retval;
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
	 * @param int|string|array<string, mixed>|object $item_id The ID of the item.
	 * @return object|false False if empty/error, Object if successful.
	 */
	public function get_item( $item_id = 0 ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item to get by.
		if ( empty( $item_id ) ) {
			return false;
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Get item by ID.
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
	 * @param string     $column_name  Name of database column.
	 * @param int|string $column_value Value to query for.
	 * @return object|false False if empty/error, Object if successful
	 */
	public function get_item_by( $column_name = '', $column_value = '' ) {

		// Default return value.
		$retval = false;

		// Bail if value is non-scalar, boolean false, or empty string.
		// Intentionally allows 0 and '0' — both are valid column values.
		if ( ! is_scalar( $column_value ) || false === $column_value || '' === $column_value ) {
			return $retval;
		}

		// Bail if column does not exist.
		if ( ! $this->is_valid_column( $column_name ) ) {
			return $retval;
		}

		// Resolve the cache group for this column (empty if not a cache_key column).
		$primary    = $this->get_primary_column_name();
		$is_primary = (bool) ( $column_name === $primary );
		$groups     = $this->get_cache_groups();
		$group      = isset( $groups[ $column_name ] ) ? $groups[ $column_name ] : '';

		/*
		 * Cache key. The primary column is stable and unique, so its value (the
		 * id) is used directly as a by-id object-cache key. Secondary cache_key
		 * columns use a dedicated "{cache_group}-by-{name}" group and a value
		 * hash salted with last_changed.
		 */
		$cache_key = $column_value;
		if ( ( false === $is_primary ) && ! empty( $group ) ) {
			$cache_key = $this->get_item_cache_key( $column_value, $group );
		}

		// Check cache.
		if ( ! empty( $group ) ) {
			$retval = $this->cache_get( $cache_key, $group );
		}

		// Secondary cache hits store the primary ID; resolve via by-id cache.
		if ( ( false === $is_primary ) && false !== $retval ) {
			return $this->get_item( $retval );
		}

		// Item not cached.
		if ( false === $retval ) {

			// Get item by column name & value (from database, not cache).
			$retval = $this->get_item_raw( $column_name, $column_value );

			// Bail on failure.
			if ( ! $this->is_success( $retval ) ) {
				return false;
			}

			// Cache the result — read path, do not bump last_changed.
			if ( is_object( $retval ) ) {

				// Always warm the canonical primary by-id object cache.
				$this->update_item_cache( $retval, false );

				// For secondary cache_key columns, store only the primary ID.
				if ( ( false === $is_primary ) && ! empty( $group ) && isset( $retval->{$primary} ) ) {
					$this->cache_set( $cache_key, $retval->{$primary}, $group );
				}
			}
		}

		// Reduce the item.
		if ( is_array( $retval ) || is_object( $retval ) ) {
			/** @var array<string, mixed>|object $reduce_target */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$reduce_target = $retval;
			$retval        = $this->reduce_item( 'select', $reduce_target );
		}

		// Return result.
		return $this->shape_item( $retval );
	}

	/**
	 * Add an item to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Item data.
	 * @return int|false Item ID if successful, false if not
	 */
	public function add_item( $data = array() ) {

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// If data includes primary column, check if item already exists.
		if ( ! empty( $data[ $primary ] ) ) {

			// Shape the primary item ID.
			$primary_val = $data[ $primary ];
			if ( is_object( $primary_val ) ) {
				$item_id = $this->shape_item_id( $primary_val );
			} elseif ( is_array( $primary_val ) ) {
				/** @var array<string, mixed> $primary_arr */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
				$primary_arr = $primary_val;
				$item_id     = $this->shape_item_id( $primary_arr );
			} elseif ( is_scalar( $primary_val ) ) {
				$item_id = $this->shape_item_id( $primary_val );
			} else {
				$item_id = 0;
			}

			// Get item by ID (from database, not cache).
			$item = $this->get_item_raw( $primary, $item_id );

			// Bail if item already exists.
			if ( ! empty( $item ) ) {
				return false;
			}

			// Set data primary ID to newly shaped ID.
			$data[ $primary ] = $item_id;
		}

		// Get default values for item (from columns).
		$item = $this->default_item();

		// Unset the primary key if not part of data array (auto-incremented).
		if ( empty( $data[ $primary ] ) ) {
			unset( $item[ $primary ] );
		}

		// Slice data that has columns, and cut out non-keys for meta.
		$columns = array_flip( $this->get_column_names() );
		$data    = array_merge( $item, $data );
		$meta    = array_diff_key( $data, $columns );
		$save    = array_intersect_key( $data, $columns );

		// Bail if nothing to save.
		if ( empty( $save ) && empty( $meta ) ) {
			return false;
		}

		// Get the current time (maybe used by created/modified).
		$time = $this->get_current_time();

		// If date-created exists, but is empty or default, use the current time.
		$created = $this->get_column_by( array( 'created' => true ) );
		if ( ! empty( $created ) && ( empty( $save[ $created->name ] ) || ( $save[ $created->name ] === $created->default ) ) ) {
			$save[ $created->name ] = $time;
		}

		// If date-modified exists, but is empty or default, use the current time.
		$modified = $this->get_column_by( array( 'modified' => true ) );
		if ( ! empty( $modified ) && ( empty( $save[ $modified->name ] ) || ( $save[ $modified->name ] === $modified->default ) ) ) {
			$save[ $modified->name ] = $time;
		}

		// Reduce & validate.
		$reduce = $this->reduce_item( 'insert', $save );
		$save   = $this->validate_item( $reduce );

		// Default return value.
		$retval = false;

		// Try to save.
		if ( ! empty( $save ) ) {
			$table       = $this->get_table_name();
			$names       = array_keys( $save );
			$save_format = $this->get_columns_field_by( 'name', $names, 'pattern', '%s' );
			$retval      = $this->db()->insert( $table, $save, $save_format );
		}

		// Bail on failure.
		if ( ! $this->is_success( $retval ) ) {
			return false;
		}

		// Get the new item ID.
		$retval = $this->db()->get_insert_id();

		// Maybe save meta keys.
		if ( ! empty( $meta ) ) {
			$this->save_extra_item_meta( $retval, $meta );
		}

		// Update item cache(s). A new row can become the first match for any
		// value, so rotate every secondary lookup group.
		$this->update_item_cache( $retval );
		$this->update_secondary_last_changed_caches();

		// Transition item data.
		$this->transition_item( $retval, $save, array() );

		// Return.
		return $retval;
	}

	/**
	 * Copy an item in the database to a new item.
	 *
	 * @since 1.1.0
	 *
	 * @param int|string           $item_id Item ID.
	 * @param array<string, mixed> $data Item data.
	 * @return int|false Item ID if successful, false if not
	 */
	public function copy_item( $item_id = 0, $data = array() ) {

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Shape the primary item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Get item by ID (from database, not cache).
		$item = $this->get_item_raw( $primary, $item_id );

		// Bail if item does not exist.
		if ( empty( $item ) ) {
			return false;
		}

		// Cast object to array.
		$save = (array) $item;

		/*
		 * Strip the UUID so add_item() generates a fresh one via validate_uuid().
		 * A UUID explicitly provided in $data will be restored by the merge below.
		 */
		unset( $save['uuid'] );

		// Maybe merge data with original item.
		if ( ! empty( $data ) && is_array( $data ) ) {
			$save = array_merge( $save, $data );
		}

		// Unset the primary key.
		unset( $save[ $primary ] );

		// Return result of add_item().
		return $this->add_item( $save );
	}

	/**
	 * Update an item in the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string           $item_id Item ID.
	 * @param array<string, mixed> $data Item data.
	 * @return bool
	 */
	public function update_item( $item_id = 0, $data = array() ) {

		// Bail early if no data to update.
		if ( empty( $data ) ) {
			return false;
		}

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID.
		if ( empty( $item_id ) ) {
			return false;
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Get item to update (from database, not cache).
		$item = $this->get_item_raw( $primary, $item_id );

		// Bail if item does not exist to update.
		if ( empty( $item ) ) {
			return false;
		}

		// Cast as an array for easier manipulation.
		$item = (array) $item;

		// Unset the primary key from item & data.
		unset(
			$data[ $primary ],
			$item[ $primary ]
		);

		// Slice data that has columns, and cut out non-keys for meta.
		$columns = array_flip( $this->get_column_names() );
		/** @var array<string, string> $data_cast */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$data_cast = array_map( 'strval', array_filter( $data, 'is_scalar' ) );
		/** @var array<string, string> $item_cast */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$item_cast = array_map( 'strval', array_filter( $item, 'is_scalar' ) );
		$diff_keys = array_keys( array_diff_assoc( $data_cast, $item_cast ) );
		foreach ( $data as $k => $v ) {
			if ( ! is_scalar( $v ) ) {
				$diff_keys[] = $k;
			}
		}
		$data = array_intersect_key( $data, array_flip( $diff_keys ) );
		$meta = array_diff_key( $data, $columns );
		$save = array_intersect_key( $data, $columns );

		// Maybe save meta keys.
		if ( ! empty( $meta ) ) {
			$this->save_extra_item_meta( $item_id, $meta );
		}

		// Bail if nothing to save.
		if ( empty( $save ) ) {
			return false;
		}

		// If date-modified exists, use the current time.
		$modified = $this->get_column_by( array( 'modified' => true ) );
		if ( ! empty( $modified ) ) {
			$save[ $modified->name ] = $this->get_current_time();
		}

		// Reduce & validate.
		$reduce = $this->reduce_item( 'update', $save );
		$save   = $this->validate_item( $reduce );

		// Default return value.
		$retval = false;

		// Try to update.
		if ( ! empty( $save ) ) {
			$table        = $this->get_table_name();
			$where        = array( $primary => $item_id );
			$names        = array_keys( $save );
			$save_format  = $this->get_columns_field_by( 'name', $names, 'pattern', '%s' );
			$where_format = $this->get_columns_field_by( 'name', $primary, 'pattern', '%s' );
			$retval       = $this->db()->update( $table, $save, $where, $save_format, $where_format );
		}

		// Bail on failure.
		if ( ! $this->is_success( $retval ) ) {
			return false;
		}

		// Refresh the primary by-id cache and rotate the Query group's salt.
		$this->update_item_cache( $item_id );

		// Rotate only the secondary lookup groups whose cache_key value changed.
		// Lookups by columns that did not change stay warm and still resolve
		// fresh objects through the by-id cache refreshed above.
		$changed = array_values( array_intersect( array_keys( $save ), array_keys( $this->get_cache_groups() ) ) );
		$this->update_secondary_last_changed_caches( $changed );

		// Transition item data.
		$this->transition_item( $item_id, $save, $item );

		// Return.
		return (bool) $retval;
	}

	/**
	 * Delete an item from the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id Item ID.
	 * @return bool
	 */
	public function delete_item( $item_id = 0 ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID.
		if ( empty( $item_id ) ) {
			return false;
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Get item by ID (from database, not cache).
		$item = $this->get_item_raw( $primary, $item_id );

		// Bail if item does not exist to delete.
		if ( empty( $item ) ) {
			return false;
		}

		/*
		 * Reduce to the columns the current user can delete; bail if none
		 * allowed. Keep the original object for cache cleanup — reduce_item
		 * returns an array, but clean_item_cache needs the object to look up
		 * cache keys by property.
		 */
		$reduced = $this->reduce_item( 'delete', $item );
		if ( empty( $reduced ) ) {
			return false;
		}

		// Try to delete.
		$table        = $this->get_table_name();
		$where        = array( $primary => $item_id );
		$where_format = $this->get_columns_field_by( 'name', $primary, 'pattern', '%s' );
		$retval       = $this->db()->delete( $table, $where, $where_format );

		// Bail on failure.
		if ( ! $this->is_success( $retval ) ) {
			return false;
		}

		// Clean caches on successful delete. The removed row's value-to-ID
		// mappings are now gone, so rotate every secondary lookup group.
		$this->delete_all_item_meta( $item_id );
		$this->clean_item_cache( $item );
		$this->update_secondary_last_changed_caches();

		// Get the action name with prefix and item name.
		$action_name = $this->apply_prefix( $this->get_item_name() . '_deleted' );

		/**
		 * Fires after an object has been deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param int  $item_id The ID of the item that was deleted.
		 * @param bool $result  Whether the item was successfully deleted.
		 */
		if ( '' !== $action_name ) {
			do_action(
				$action_name,
				(int) $item_id,
				(bool) $retval
			);
		}

		// Return.
		return (bool) $retval;
	}

	/**
	 * Validate an item before it is updated in or added to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $item The item object or array.
	 * @return array<string, mixed> Validated item array.
	 */
	private function validate_item( $item = array() ) {

		// Bail if item is empty or not an array.
		if ( empty( $item ) || ! is_array( $item ) ) {
			return $item;
		}

		// Validate all item fields.
		foreach ( $item as $key => $value ) {
			$item[ $key ] = $this->validate_item_field( $value, $key );
		}

		// Return the validated item.
		return $this->filter_item( $item );
	}

	/**
	 * Reduce an item down to the keys and values the current user has the
	 * appropriate capabilities to select|insert|update|delete.
	 *
	 * Always returns an array. Columns not present in the schema are also
	 * removed — no caps entry resolves to an empty capability string, which
	 * fails the current_user_can check.
	 *
	 * @since 1.0.0
	 *
	 * @param string                      $method select|insert|update|delete.
	 * @param object|array<string, mixed> $item   Object or array of keys/values to reduce.
	 *
	 * @return array<string, mixed> Item with capability-restricted keys removed.
	 */
	private function reduce_item( $method = 'update', $item = array() ) {

		// Bail if item is empty.
		if ( empty( $item ) ) {
			return array();
		}

		// Normalise to an array for uniform processing.
		if ( is_object( $item ) ) {
			$work = (array) $item;
		} elseif ( is_array( $item ) ) {
			$work = $item;
		} else {
			return array();
		}

		// Loop through columns and remove any the current user cannot access.
		foreach ( array_keys( $work ) as $key ) {

			// Get the caps for this column.
			$caps = $this->get_column_field( array( 'name' => $key ), 'caps' );

			// Get the capability for this method, if it exists.
			$method_cap = ( is_array( $caps ) && isset( $caps[ $method ] ) && is_string( $caps[ $method ] ) )
				? $caps[ $method ]
				: '';

			// Remove any columns the current user cannot access.
			if ( empty( $method_cap ) || ! current_user_can( $method_cap ) ) {
				unset( $work[ $key ] );
			}
		}

		// Return the reduced item.
		return $work;
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
	 * @since 3.0.0 Uses array_combine()
	 *
		 * @param array<string, mixed> $args Default empty array. Parsed & used to filter columns.
	 * @return array<string, mixed>
	 */
	private function default_item( $args = array() ) {

		// Parse arguments.
		$r = wp_parse_args( $args );

		// Get the column names and their defaults.
		$names    = $this->get_column_names( $r );
		$defaults = $this->get_columns( $r, 'and', 'default' );

		// Combine them.
		$retval = array_combine( $names, $defaults );

		// Return.
		return ! empty( $retval )
			? $retval
			: array();
	}

	/**
	 * Transition an item when adding or updating.
	 *
	 * This method takes the data being saved, looks for any columns that are
	 * known to transition between values, and fires actions on them.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string           $item_id Item ID.
	 * @param array<string, mixed> $new_data New item data.
	 * @param array<string, mixed> $old_data Old item data.
	 */
	private function transition_item( $item_id = 0, $new_data = array(), $old_data = array() ): void {

		// Look for transition columns.
		$columns = $this->get_column_names( array( 'transition' => true ) );

		// Bail if no columns to transition.
		if ( empty( $columns ) ) {
			return;
		}

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID.
		if ( empty( $item_id ) ) {
			return;
		}

		// If no old value(s), it's new.
		if ( empty( $old_data ) || ! is_array( $old_data ) ) {
			$old_data = $new_data;

			// Set all old values to "new".
			foreach ( $old_data as $key => $value ) {
				$value            = 'new';
				$old_data[ $key ] = $value;
			}
		}

		// Compare.
		$keys = array_flip( $columns );
		$new  = array_intersect_key( $new_data, $keys );
		$old  = array_intersect_key( $old_data, $keys );

		// Filter to scalar values to allow safe array_diff.
		$new_scalars = array_filter( $new, 'is_scalar' );
		$old_scalars = array_filter( $old, 'is_scalar' );

		// Get the difference.
		$diff = array_diff( $new_scalars, $old_scalars );

		// Bail if nothing is changing.
		if ( empty( $diff ) ) {
			return;
		}

		// Get the item name for the key action name.
		$item_name = $this->get_item_name();

		// Do the actions.
		foreach ( $diff as $key => $value ) {
			$old_value  = $old_data[ $key ];
			$new_value  = $new_data[ $key ];
			$key_action = $this->apply_prefix( 'transition_' . $item_name . '_' . $key );

			/**
			 * Fires after an object value has transitioned.
			 *
			 * @since 1.0.0
			 *
			 * @param mixed $old_value The value being transitioned FROM.
			 * @param mixed $new_value The value being transitioned TO.
			 * @param int   $item_id   The ID of the item that is transitioning.
			 */
			if ( '' !== $key_action ) {
				do_action( $key_action, $old_value, $new_value, (int) $item_id );
			}
		}
	}

	/** Meta ******************************************************************/

	/**
	 * Add meta data to an item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id Item ID.
	 * @param string     $meta_key Meta key.
	 * @param string     $meta_value Meta value.
	 * @param bool       $unique Whether the meta key should be unique per item.
	 * @return int|false The meta ID on success, false on failure.
	 */
	protected function add_item_meta( $item_id = 0, $meta_key = '', $meta_value = '', $unique = false ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no meta to add, or if the ID is not an integer (metadata requires integer IDs).
		if ( ! is_int( $item_id ) || empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		// Return results of adding meta data.
		return add_metadata( $meta_type, $item_id, $meta_key, $meta_value, $unique );
	}

	/**
	 * Get meta data for an item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id Item ID.
	 * @param string     $meta_key Meta key.
	 * @param bool       $single Whether to return a single value.
	 * @return mixed Single metadata value, or array of values
	 */
	protected function get_item_meta( $item_id = 0, $meta_key = '', $single = false ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no meta was returned, or if the ID is not an integer (metadata requires integer IDs).
		if ( ! is_int( $item_id ) || empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		// Return results of getting meta data.
		return get_metadata( $meta_type, $item_id, $meta_key, $single );
	}

	/**
	 * Update meta data for an item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id Item ID.
	 * @param string     $meta_key Meta key.
	 * @param string     $meta_value Meta value.
	 * @param string     $prev_value Previous meta value to target when updating.
	 * @return bool True on successful update, false on failure.
	 */
	protected function update_item_meta( $item_id = 0, $meta_key = '', $meta_value = '', $prev_value = '' ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no meta was returned, or if the ID is not an integer (metadata requires integer IDs).
		if ( ! is_int( $item_id ) || empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		// Return results of updating meta data.
		return (bool) update_metadata( $meta_type, $item_id, $meta_key, $meta_value, $prev_value );
	}

	/**
	 * Delete meta data for an item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id Item ID.
	 * @param string     $meta_key Meta key.
	 * @param string     $meta_value Meta value.
	 * @param bool       $delete_all Whether to delete all entries regardless of value.
	 * @return bool True on successful delete, false on failure.
	 */
	protected function delete_item_meta( $item_id = 0, $meta_key = '', $meta_value = '', $delete_all = false ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no meta was returned, or if the ID is not an integer (metadata requires integer IDs).
		if ( ! is_int( $item_id ) || empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		// Return results of deleting meta data.
		return delete_metadata( $meta_type, $item_id, $meta_key, $meta_value, $delete_all );
	}

	/**
	 * Get registered meta data keys.
	 *
	 * @since 1.0.0
	 *
	 * @param string $object_subtype The sub-type of meta keys.
	 *
	 * @return array<string, mixed>
	 */
	private function get_registered_meta_keys( $object_subtype = '' ) {

		// Get the object type.
		$object_type = $this->get_meta_type();

		// Return the keys.
		return get_registered_meta_keys( $object_type, $object_subtype );
	}

	/**
	 * Maybe update meta values on item update/save.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string           $item_id Item ID.
	 * @param array<string, mixed> $meta Array of meta key/value pairs.
	 */
	private function save_extra_item_meta( $item_id = 0, $meta = array() ): void {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if there is no bulk meta to save.
		if ( empty( $item_id ) || empty( $meta ) ) {
			return;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return;
		}

		// Only save registered keys.
		$keys = $this->get_registered_meta_keys();
		$meta = array_intersect_key( $meta, $keys );

		// Bail if no registered meta keys.
		if ( empty( $meta ) ) {
			return;
		}

		// Save or delete meta data.
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
	 * @param int|string $item_id Item ID.
	 */
	private function delete_all_item_meta( $item_id = 0 ): void {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID.
		if ( empty( $item_id ) ) {
			return;
		}

		// Get the meta table name.
		$table = $this->get_meta_table_name();

		// Bail if no meta table exists.
		if ( empty( $table ) ) {
			return;
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Guess the item ID column for the meta table.
		$item_name       = $this->get_item_name();
		$item_id_column  = $this->apply_prefix( $item_name . '_' . $primary );
		$item_id_pattern = $this->get_column_field( array( 'name' => $primary ), 'pattern', '%s' );

		// Get meta IDs.
		$query    = "SELECT meta_id FROM {$table} WHERE {$item_id_column} = {$item_id_pattern}";
		$prepared = $this->db()->prepare( $query, $item_id );
		$meta_ids = $this->db()->get_col( $prepared );

		// Bail if no meta IDs to delete.
		if ( empty( $meta_ids ) ) {
			return;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		// Delete all meta data for this item ID.
		foreach ( $meta_ids as $mid ) {
			delete_metadata_by_mid( $meta_type, $mid );
		}
	}

	/**
	 * Get the meta table for this query.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Minor refactor to improve readability.
	 *
	 * @return bool|string Table name if exists, False if not.
	 */
	private function get_meta_table_name() {

		// Get the meta type.
		$type = $this->get_meta_type();

		// Append "meta" to end of meta type.
		$table = "{$type}meta";

		// If not empty, return table name.
		$table_name = $this->db()->get_table_prefix( $table );
		if ( ! empty( $table_name ) ) {
			return $table_name;
		}

		// Return.
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
	public function get_meta_type() {
		return $this->apply_prefix( $this->item_name );
	}

	/** Cache *****************************************************************/

	/**
	 * Get cache key from $query_vars and $query_var_defaults.
	 *
	 * Performs the following operations to create a consistent cache-key:
	 * - Removes the "fields" query_var, because whole objects/items are cached
	 * - Removes unknown or unregistered query_var keys
	 * - Sorts query_vars by query_var_default keys
	 * - Removes query_vars with default values
	 * - Serializes and md5 hashes query_vars
	 * - Combines plural name, key, and last_changed for cache group
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Correctly removes unique query_var_default_value values
	 *
	 * @param string $group Cache group name.
	 * @return string
	 */
	private function get_cache_key( $group = '' ) {

		// Default slice.
		$slice = array();

		// Slice query_vars by query_var_defaults keys, ordered by defaults.
		foreach ( $this->query_var_defaults as $key => $_default ) {

			// Skip vars that change behaviour but must not segment the cache key.
			if ( 'fields' === $key || 'cache_results' === $key ) {
				continue;
			}

			// Skip if no query_var array key exists, allowing null values.
			if ( ! array_key_exists( $key, $this->query_vars ) ) {
				continue;
			}

			// Skip default random query_var values.
			if ( $this->query_vars[ $key ] === $this->query_var_default_value ) {
				continue;
			}

			// Add key & value to slice.
			$slice[ $key ] = $this->query_vars[ $key ];
		}

		// Hash the sliced query vars. serialize() is intentional and safe here.
		$hash = md5( serialize( $slice ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		// Return the namespaced, salted cache key.
		return "get_{$this->get_item_name_plural()}:{$hash}:" . $this->get_last_changed_cache( $group );
	}

	/**
	 * Build a last_changed-salted cache key for a secondary get_item_by() lookup.
	 *
	 * The cache group already identifies the lookup column, so the key only
	 * needs the looked-up value and table-wide generation salt. The cached value
	 * is the primary ID; the object itself lives in the canonical by-id cache.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed  $column_value Value being looked up.
	 * @param string $group        Secondary lookup group to salt from. Default empty.
	 * @return string
	 */
	private function get_item_cache_key( $column_value = '', $group = '' ): string {
		return md5( (string) $column_value ) . ':' . $this->get_last_changed_cache( $group );
	}

	/**
	 * Normalize a cache group name.
	 *
	 * Empty values and the primary column name both resolve to the table's
	 * canonical item cache group. Non-primary values are treated as explicit
	 * cache-group names, such as secondary "{cache_group}-by-{column}" groups.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Cache group name, or a column name.
	 * @return string
	 */
	private function get_cache_group( $group = '' ) {

		// Get the primary column.
		$primary = $this->get_primary_column_name();

		// Default return value.
		$retval = $this->cache_group;

		// Treat the primary column name as an alias for the Query cache group.
		if ( ! empty( $group ) && ( $group !== $primary ) ) {
			$retval = $group;
		}

		// Return the group.
		return $retval;
	}

	/**
	 * Get array of which database columns have uniquely cached groups.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private function get_cache_groups() {

		// Default return value.
		$retval = array();

		// Get the cache groups.
		$groups = $this->get_column_names( array( 'cache_key' => true ) );

		// Bail if no cache groups.
		if ( empty( $groups ) ) {
			return $retval;
		}

		// Setup return values.
		foreach ( $groups as $name ) {
			$retval[ $name ] = $this->get_cache_group_for_column( $name );
		}

		// Return cache groups array.
		return $retval;
	}

	/**
	 * Get the cache group used by a cache-key column.
	 *
	 * The primary column uses the canonical item cache group. Secondary columns
	 * use their own lookup groups so value-to-ID entries do not share a bucket
	 * with by-id item objects.
	 *
	 * @since 3.1.0
	 *
	 * @param string $column_name Column name.
	 * @return string
	 */
	private function get_cache_group_for_column( $column_name = '' ) {

		// Bail if no column name.
		if ( empty( $column_name ) ) {
			return '';
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Resolve column to group, then normalize through the shared helper.
		$group = ( $primary === $column_name )
			? $column_name
			: "{$this->cache_group}-by-{$column_name}";

		return $this->get_cache_group( $group );
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
	 * @since 3.0.0 Uses get_meta_table_name() to
	 *
	 * @param list<int|string> $item_ids List of item IDs.
	 * @param bool             $force Whether to bypass caching.
	 *
	 * @return bool False if empty
	 */
	private function prime_item_caches( $item_ids = array(), $force = false ) {

		// Bail if no items to cache.
		if ( empty( $item_ids ) ) {
			return false;
		}

		// Accepts single values, so cast to array.
		$item_ids = (array) $item_ids;

		/**
		 * Update item caches.
		 *
		 * Uses get_non_cached_ids() to remove item IDs that already exist in
		 * in the cache, then performs direct database query for the remaining
		 * IDs, and caches them.
		 */
		if ( ! empty( $force ) || $this->get_query_var( 'update_item_cache' ) ) {

			// Look for non-cached IDs.
			$ids = $this->get_non_cached_ids( $item_ids );

			// Proceed if non-cached IDs exist.
			if ( ! empty( $ids ) ) {

				// Get query parts.
				$table   = $this->get_table_name();
				$primary = $this->get_primary_column_name();
				$ids     = $this->get_in_sql( $primary, $ids );

				// Query database.
				$query   = "SELECT * FROM {$table} WHERE {$primary} IN {$ids}";
				$results = $this->db()->get_results( $query );

				// Update item cache(s) — read path, do not bump last_changed.
				if ( ! empty( $results ) && is_array( $results ) ) {
					/** @var list<object> $results */
					$this->update_item_cache( $results, false );
				}
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

			// Proceed if meta table exists.
			if ( $this->get_meta_table_name() ) {
				$meta_type = $this->get_meta_type();
				$int_ids   = array_values( array_filter( $item_ids, 'is_int' ) );
				if ( ! empty( $int_ids ) ) {
					update_meta_cache( $meta_type, $int_ids );
				}
			}
		}

		// Return true because something was cached.
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
	 * @since 3.0.0 Uses shape_item_id() if $items is scalar
	 *
	 * @param int|string|object|list<object> $items             Primary ID or key if scalar. Row if object. Array of objects if array.
	 * @param bool                           $bump_last_changed Whether to bump the last-changed cache value.
	 */
	private function update_item_cache( $items = array(), $bump_last_changed = true ): void {

		// Maybe query for single item.
		if ( is_scalar( $items ) ) {

			// Get the primary column name.
			$primary = $this->get_primary_column_name();

			// Shape the primary item ID.
			$item_id = $this->shape_item_id( $items );

			// Get item by ID (from database, not cache).
			$items = $this->get_item_raw( $primary, $item_id );
		}

		// Bail if no items to cache.
		if ( empty( $items ) ) {
			return;
		}

		// Make sure items are an array (without casting objects to arrays).
		if ( ! is_array( $items ) ) {
			$items = array( $items );
		}

		// Get the cache groups and the primary column name.
		$groups  = $this->get_cache_groups();
		$primary = $this->get_primary_column_name();

		/*
		 * Warm the primary by-id object cache only. Secondary cache_key lookups
		 * are salted and lazily populated by get_item_by(); proactively warming
		 * them here would write keys the salted reads never hit, and overwrite
		 * non-unique lookups with the last-written row.
		 */
		foreach ( $items as $item ) {

			// Skip if item is not an object.
			if ( ! is_object( $item ) ) {
				continue;
			}

			// Warm the primary by-id object cache.
			if ( isset( $groups[ $primary ], $item->{$primary} ) ) {
				$this->cache_set( $item->{$primary}, $item, $groups[ $primary ] );
			}
		}

		/*
		 * Only bump last_changed for mutations; read-path warming must not
		 * invalidate the list cache that was just stored.
		 */
		if ( true === $bump_last_changed ) {
			$this->update_primary_last_changed_cache();
		}
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
	 * @param mixed $items Single object item, or Array of object items.
	 *
	 * @return bool
	 */
	private function clean_item_cache( $items = array() ) {

		// Bail if no items to clean.
		if ( empty( $items ) ) {
			return false;
		}

		// Make sure items are an array.
		if ( ! is_array( $items ) ) {
			$items = array( $items );
		}

		// Get the cache groups and the primary column name.
		$groups  = $this->get_cache_groups();
		$primary = $this->get_primary_column_name();

		// Delete the primary by-id object cache for each item.
		foreach ( $items as $item ) {

			// Skip if item is not an object.
			if ( ! is_object( $item ) ) {
				continue;
			}

			// Delete the primary by-id object cache.
			if ( isset( $groups[ $primary ], $item->{$primary} ) ) {
				$this->cache_delete( $item->{$primary}, $groups[ $primary ] );
			}
		}

		/*
		 * Rotate the Query (primary) group's salt, invalidating the result-list
		 * cache. Secondary lookup groups are rotated by the caller via
		 * update_secondary_last_changed_caches(), since only the caller knows the
		 * operation.
		 */
		$this->update_primary_last_changed_cache();

		return true;
	}

	/**
	 * Set the last_changed generation for a cache group to the current time.
	 *
	 * Low-level primitive. Prefer the semantic wrappers
	 * update_primary_last_changed_cache() and
	 * update_secondary_last_changed_caches() at write sites; this is also used
	 * by get_last_changed_cache() to lazily initialise a group.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Cache group. Defaults to $this->cache_group.
	 * @return string The new last_changed value.
	 */
	private function set_last_changed( $group = '' ) {
		$last_changed = microtime();

		$this->cache_set( 'last_changed', $last_changed, $group );

		return $last_changed;
	}

	/**
	 * Rotate the Query (primary) group's last_changed salt.
	 *
	 * Invalidates the result-list cache. Called on every write, since any column
	 * change can affect query ordering or membership.
	 *
	 * @since 3.1.0
	 *
	 * @return string The new last_changed value.
	 */
	private function update_primary_last_changed_cache() {
		return $this->set_last_changed();
	}

	/**
	 * Rotate the last_changed salt for secondary cache_key lookup groups.
	 *
	 * Each secondary cache_key column has its own "{cache_group}-by-{column}"
	 * group with an independent last_changed generation, so a get_item_by()
	 * lookup for one column survives writes that cannot affect it. The Query
	 * (primary) group's salt is rotated separately by update_item_cache() and
	 * clean_item_cache() on every write.
	 *
	 * Pass the specific column names whose values changed (an update), or null
	 * to rotate every secondary group (an insert or delete, either of which can
	 * change which row is the first match for any value).
	 *
	 * @since 3.1.0
	 *
	 * @param list<string>|null $columns Column names to invalidate, or null for all.
	 */
	private function update_secondary_last_changed_caches( $columns = null ): void {

		// Get the cache groups and the primary column name.
		$groups  = $this->get_cache_groups();
		$primary = $this->get_primary_column_name();

		// Rotate the salt for each affected secondary group.
		foreach ( $groups as $name => $group ) {

			// The primary/Query group is rotated separately, on every write.
			if ( $name === $primary ) {
				continue;
			}

			// Rotate all secondary groups (null), or only the named ones.
			if ( ( null === $columns ) || in_array( $name, $columns, true ) ) {
				$this->set_last_changed( $group );
			}
		}
	}

	/**
	 * Get the last_changed key for a cache group.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Cache group. Defaults to $this->cache_group.
	 *
	 * @return string The last time a cache group was changed.
	 */
	private function get_last_changed_cache( $group = '' ) {

		// Get the last changed cache value.
		$last_changed = $this->cache_get( 'last_changed', $group );

		// Maybe initialise the last changed value.
		if ( false === $last_changed ) {
			$last_changed = $this->set_last_changed( $group );
		}

		// Return the last changed value for the cache group.
		return (string) $last_changed;
	}

	/**
	 * Get array of non-cached item IDs.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 $item_ids expected to be shaped.
	 *
	 * @param list<int|string> $item_ids Array of shaped item IDs.
	 * @param string           $group    Cache group. Defaults to $this->cache_group.
	 *
	 * @return list<int|string>
	 */
	private function get_non_cached_ids( $item_ids = array(), $group = '' ) {

		// Bail if no item IDs.
		if ( empty( $item_ids ) ) {
			return array();
		}

		// Default return value.
		$retval = array();

		// Loop through item IDs.
		foreach ( $item_ids as $id ) {
			if ( false === $this->cache_get( $id, $group ) ) {
				$retval[] = $id;
			}
		}

		// Return array of non-cached IDs.
		return $retval;
	}

	/**
	 * Add a cache value for a key and group.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed  $value  Cache value.
	 * @param string $group  Cache group. Defaults to $this->cache_group.
	 * @param int    $expire Expiration.
	 */
	private function cache_add( $key = '', $value = '', $group = '', $expire = 0 ): void {

		// Bail if cache invalidation is suspended.
		if ( wp_suspend_cache_addition() ) {
			return;
		}

		// Bail if no cache key. Allow 0 and '0' — both are valid cache keys.
		if ( false === $key || '' === $key ) {
			return;
		}

		// Get the cache group.
		$group = $this->get_cache_group( $group );

		// Add to the cache.
		wp_cache_add( $key, $value, $group, $expire );
	}

	/**
	 * Get a cache value for a key and group.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group. Defaults to $this->cache_group.
	 * @param bool       $force Whether to bypass caching.
	 * @return mixed
	 */
	private function cache_get( $key = '', $group = '', $force = false ) {

		// Bail if no cache key. Return false (not null) so callers using
		// strict false === checks correctly detect a cache miss.
		if ( false === $key || '' === $key ) {
			return false;
		}

		// Get the cache group.
		$group = $this->get_cache_group( $group );

		// Return from the cache.
		return wp_cache_get( $key, $group, $force );
	}

	/**
	 * Set a cache value for a key and group.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed  $value  Cache value.
	 * @param string $group  Cache group. Defaults to $this->cache_group.
	 * @param int    $expire Expiration.
	 */
	private function cache_set( $key = '', $value = '', $group = '', $expire = 0 ): void {

		// Bail if cache invalidation is suspended.
		if ( wp_suspend_cache_addition() ) {
			return;
		}

		// Bail if no cache key. Allow 0 and '0' — both are valid cache keys.
		if ( false === $key || '' === $key ) {
			return;
		}

		// Get the cache group.
		$group = $this->get_cache_group( $group );

		// Update the cache.
		wp_cache_set( $key, $value, $group, $expire );
	}

	/**
	 * Delete a cache key for a group.
	 *
	 * @since 1.0.0
	 *
	 * @global bool $_wp_suspend_cache_invalidation
	 *
	 * @param int|string $key   Cache key.
	 * @param string $group Cache group. Defaults to $this->cache_group.
	 */
	private function cache_delete( $key = '', $group = '' ): void {
		global $_wp_suspend_cache_invalidation;

		// Bail if cache invalidation is suspended.
		if ( ! empty( $_wp_suspend_cache_invalidation ) ) {
			return;
		}

		// Bail if no cache key. Allow 0 and '0' — both are valid cache keys.
		if ( false === $key || '' === $key ) {
			return;
		}

		// Get the cache group.
		$group = $this->get_cache_group( $group );

		// Delete the cache.
		wp_cache_delete( $key, $group );
	}

	/** Filters ***************************************************************/

	/**
	 * Filter an item before it is inserted or updated in the database.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $item The item data.
	 * @return array<string, mixed>
	 */
	public function filter_item( $item = array() ) {

		// Generate filter name based on the singular item name.
		$filter_name = $this->apply_prefix( 'filter_' . $this->get_item_name() . '_item' );

		if ( '' === $filter_name ) {
			return $item;
		}

		/**
		 * Filters an item before it is inserted or updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed>     $item  The item as an array.
		 * @param \BerlinDB\Database\Query $query Current query instance.
		 */
		return (array) apply_filters_ref_array(
			$filter_name,
			array(
				$item,
				&$this,
			)
		);
	}

	/**
	 * Filter the default query parser class list.
	 *
	 * Allows plugins to modify the list of parser classes used to parse query vars.
	 *
	 * @since 3.0.0
	 *
	 * @param string[] $parsers Array of fully-qualified Parser class names.
	 * @return string[] Filtered array of fully-qualified Parser class names.
	 */
	public function filter_query_var_parsers( $parsers = array() ) {

		// Generate filter name with a prefix.
		$filter_name = $this->apply_prefix( 'query_var_parsers' );

		if ( '' === $filter_name ) {
			return $parsers;
		}

		/**
		 * Filter the default query parser class list.
		 *
		 * @since 3.0.0
		 * @param string[] $parsers Array of fully-qualified Parser class names.
		 * @param Query    $query   Current Query instance.
		 */
		return (array) apply_filters_ref_array(
			$filter_name,
			array(
				$parsers,
				&$this,
			)
		);
	}

	/**
	 * Filter all shaped items after they are retrieved from the database.
	 *
	 * @since 3.0.0
	 *
	 * @param list<object> $items The item data.
	 * @return list<object>
	 */
	public function filter_items( $items = array() ) {

		// Generate filter name based on the plural item name.
		$filter_name = $this->apply_prefix( 'the_' . $this->get_item_name_plural() );

		if ( '' === $filter_name ) {
			return $items;
		}

		/**
		 * Filters the object query results after they have been shaped.
		 *
		 * @since 1.0.0
		 *
		 * @param list<object>             $items An array of items.
		 * @param \BerlinDB\Database\Query $query Current query instance.
		 */
		return (array) apply_filters_ref_array(
			$filter_name,
			array(
				$items,
				&$this,
			)
		);
	}

	/**
	 * Filter the found items query.
	 *
	 * @since 3.0.0
	 * @param string $sql SQL query string.
	 * @return string
	 */
	public function filter_found_items_query( $sql = '' ) {

		// Generate filter name based on the plural item name.
		$filter_name = $this->apply_prefix( 'found_' . $this->get_item_name_plural() . '_query' );

		if ( '' === $filter_name ) {
			return $sql;
		}

		/**
		 * Filters the query used to retrieve the found item count.
		 *
		 * @since 1.0.0
		 * @since 3.0.0 Supports MySQL 8 by removing FOUND_ROWS() and uses
		 *              $request_clauses instead.
		 *
		 * @param string                   $sql   SQL query.
		 * @param \BerlinDB\Database\Query $query Current query instance.
		 */
		return (string) apply_filters_ref_array(
			$filter_name,
			array(
				$sql,
				&$this,
			)
		);
	}

	/**
	 * Filter the query clauses before they are parsed into a SQL string.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $clauses All of the SQL query clauses.
	 * @return array<string, mixed>
	 */
	public function filter_query_clauses( $clauses = array() ) {

		// Generate filter name based on the plural item name.
		$filter_name = $this->apply_prefix( $this->get_item_name_plural() . '_query_clauses' );

		if ( '' === $filter_name ) {
			return $clauses;
		}

		/**
		 * Filters the item query clauses.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed>     $clauses An array of query clauses.
		 * @param \BerlinDB\Database\Query $query   Current query instance.
		 */
		return (array) apply_filters_ref_array(
			$filter_name,
			array(
				$clauses,
				&$this,
			)
		);
	}

	/** General ***************************************************************/

	/**
	 * Fetch raw results directly from the database.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses query()
	 *
	 * @param list<string>         $cols       Columns for `SELECT`.
	 * @param array<string, mixed> $where_cols Where clauses. Each key-value pair in the array
	 *                                         represents a column and a comparison.
	 * @param int                  $limit      Optional. LIMIT value. Default 25.
	 * @param int|null             $offset     Optional. OFFSET value. Default null.
	 * @param string               $output     Optional. Any of ARRAY_A | ARRAY_N | OBJECT | OBJECT_K constants.
	 *                                         Default OBJECT.
	 *                                         With one of the first three, return an array of
	 *                                         rows indexed from 0 by SQL result row number.
	 *                                         Each row is an associative array (column => value, ...),
	 *                                         a numerically indexed array (0 => value, ...),
	 *                                         or an object. ( ->column = value ), respectively.
	 *                                         With OBJECT_K, return an associative array of
	 *                                         row objects keyed by the value of each row's
	 *                                         first column's value.
	 *
	 * @return list<object>|int Database query results.
	 */
	public function get_results( $cols = array(), $where_cols = array(), $limit = 25, $offset = null, $output = OBJECT ) {

		// Parse arguments.
		$r = wp_parse_args(
			$where_cols,
			array(
				'fields'            => $cols,
				'number'            => $limit,
				'offset'            => $offset,
				'output'            => $output,
				'update_item_cache' => false,
				'update_meta_cache' => false,
			)
		);

		// Get items.
		return $this->query( $r );
	}
}
