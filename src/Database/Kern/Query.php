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
 * @property array<string,\BerlinDB\Database\Parsers\Base> $parsers
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
 *     @type list<string>|false $with  Relationship names to eager-prime (warms
 *                                      the related items' caches to avoid N+1).
 *                                      Names only; false disables. Default false.
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
	use \BerlinDB\Database\Traits\Query\Cache;
	use \BerlinDB\Database\Traits\Query\Meta;
	use \BerlinDB\Database\Traits\Query\Hydration;
	use \BerlinDB\Database\Traits\Query\Relationships;
	use \BerlinDB\Database\Traits\Query\Columns;
	use \BerlinDB\Database\Traits\Query\Filters;
	use \BerlinDB\Database\Traits\Query\Clauses;
	use \BerlinDB\Database\Traits\Query\Execution;
	use \BerlinDB\Database\Traits\Query\Aggregates;
	use \BerlinDB\Database\Traits\Query\Crud;

	/** Constants *************************************************************/

	/**
	 * The SQL aggregate functions the 'aggregate' container supports.
	 *
	 * @since 3.1.0
	 * @var string[]
	 */
	private const AGGREGATE_FUNCTIONS = array( 'SUM', 'AVG', 'MAX', 'MIN', 'COUNT' );

	/**
	 * Query vars that change behavior but NOT which rows (IDs) a query returns,
	 * so they must be excluded from the result-cache key.
	 *
	 * get_cache_key() keys on the matching-ID set, so two queries differing only by
	 * one of these return identical IDs and MUST share one cache entry. Rationale:
	 *  - 'fields'        - whole items are cached by primary ID, never by field list.
	 *  - 'cache_results' - toggles cache USE, not which rows match.
	 *  - 'with'          - relationship cache PRIMING side effect only.
	 *  - 'index_hints'   - an optimizer hint changes the PLAN, never the result set.
	 *
	 * Adding a results-invariant query var (e.g. a new hint or priming directive)?
	 * Add it here. CacheKeyGuardTest fails until a new clause-backed var is classified.
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private const RESULTS_INVARIANT_VARS = array( 'fields', 'cache_results', 'with', 'index_hints' );

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
	 * pattern) or a Schema instance built at runtime - e.g. from a constructor
	 * argument or a Schema::from_table() call.
	 *
	 * @since 1.0.0
	 * @var   class-string<Schema>|Schema
	 */
	protected $table_schema = Schema::class;

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
	 * @var   class-string<Row>|class-string
	 */
	protected $item_shape = Row::class;

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
	 * @var   array<string,mixed>
	 */
	public $query_vars = array();

	/**
	 * Default values for query vars.
	 *
	 * These are computed at runtime based on the registered columns for the
	 * database table this query relates to.
	 *
	 * @since 1.0.0
	 * @var   array<string,mixed>
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
	 * Subclasses can override this property before init() runs to replace
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
	 * Never mutated after that - see $current[ 'parsers' ] for per-query instances.
	 *
	 * @since 3.0.0
	 * @var   array<string,\BerlinDB\Database\Parsers\Base>
	 */
	protected $parsers = array();

	/** Results ***************************************************************/

	/**
	 * Array of items retrieved by the SQL query.
	 *
	 * @since 1.0.0
	 * @var   list<object>|array<int|string,mixed>|int
	 */
	public $items = array();

	/** Methods ***************************************************************/

	/**
	 * Set up class attributes that rely on the configured properties.
	 *
	 * Overrides Boot::init(): runs after configure() so it sees the query's
	 * identity, and before consume_args() so the schema and parsers exist before a
	 * query runs. Protected so subclasses can extend it without exposing it as
	 * part of the public query API.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Renamed from sunrise() (Boot lifecycle).
	 */
	protected function init(): void {
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
	 * Whether construct args are a definition (config), not query vars.
	 *
	 * A Query is the one class whose construct args may be EITHER an explicit
	 * definition (identity properties) OR query vars. Overriding this decision
	 * hook - rather than configure() itself - lets Boot apply config through the
	 * shared pipeline (no trait aliasing needed) and hand query vars to
	 * consume_args() when this returns false.
	 *
	 * A definition carries a schema; query vars never do. So the decision keys on
	 * `table_schema`, but it reads CONTEXT, not just the value - which lets it
	 * defend BOTH ambiguous edges at once:
	 *
	 * - The value IS a Schema (instance or class-string) - unambiguously a
	 *   definition (including an explicit override of a subclass's schema).
	 * - The value is NOT a Schema, and the class already declares its own schema
	 *   - a query var filtering a same-named column (e.g. an information_schema
	 *   mirror with a literal `table_schema` column). Stays query vars.
	 * - The value is NOT a Schema, and the class has no schema of its own (still
	 *   the base default) - broken config: routed to the definition path so
	 *   set_schema() reports the bad class instead of silently misrouting a typo
	 *   onto the query-var path.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Construction arguments.
	 * @return bool
	 */
	protected function is_configuration( array $args ): bool {

		// Bail if no schema reference - query vars never carry one.
		if ( ! isset( $args[ 'table_schema' ] ) ) {
			return false;
		}

		$schema = $args[ 'table_schema' ];

		// An actual Schema value is unambiguously a definition (or an override).
		if ( $schema instanceof Schema ) {
			return true;
		}
		if ( is_string( $schema ) && class_exists( $schema ) && is_a( $schema, Schema::class, true ) ) {
			return true;
		}

		/*
		 * The value is not a Schema. When the class already declares its own
		 * schema, treat it as a query var (a filter on a same-named column);
		 * otherwise the class has no schema to query, so a present-but-invalid
		 * value is broken config - route it to the definition path. The base
		 * default mirrors the $table_schema property default above.
		 */
		$declares_own_schema = ! empty( $this->table_schema )
			&& ( ( __NAMESPACE__ . '\\Schema' ) !== $this->table_schema );

		return ! $declares_own_schema;
	}

	/**
	 * Sanitization callbacks for a Query's definition (config) arguments.
	 *
	 * Applied by validate_args() on the config path (via configure()); query vars
	 * are validated by their parsers, not here.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed> Map of config key => sanitization callback.
	 */
	protected function get_config_callbacks(): array {
		return array(
			'table_name'       => array( $this, 'sanitize_table_name' ),
			'table_alias'      => array( $this, 'sanitize_table_alias' ),
			'table_schema'     => '',                                      // Schema instance/class; set_schema() validates
			'item_name'        => array( $this, 'sanitize_key' ),
			'item_name_plural' => array( $this, 'sanitize_key' ),
			'item_shape'       => array( $this, 'sanitize_class_name' ),
			'cache_group'      => array( $this, 'sanitize_key' ),
			'prefix'           => array( $this, 'sanitize_key' ),
		);
	}

	/**
	 * Consume query args and run the query.
	 *
	 * Overrides Boot::consume_args().
	 *
	 * Receives whatever configure() did not claim as configuration. Empty when
	 * the Query was constructed from a definition (so no query runs).
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Renamed from parse_args().
	 *
	 * @param array<string,mixed> $args Query vars.
	 */
	protected function consume_args( array $args = array() ): void {

		// Bail if there are no query vars (e.g. a configured-only construction).
		if ( empty( $args ) ) {
			return;
		}

		// Parse the query and get items.
		$this->query( $args );
	}

	/**
	 * Reset per-run ephemeral state at the start of each action.
	 *
	 * Called by boot() during construction and by query() before each run.
	 * Initializes $current with all keys that are rebuilt on every run so
	 * that stale state from a prior call can never bleed through.
	 *
	 * @since 3.0.0
	 */
	protected function start(): void {
		$clause_keys = array( 'explain', 'select', 'distinct', 'fields', 'from', 'index_hints', 'join', 'where', 'groupby', 'orderby', 'limits' );

		$this->init_current(
			array(
				'parsers'             => array(),
				'item_shape'          => $this->item_shape,
				'query_var_originals' => array(),
				'query_clauses'       => array_merge(
					array_fill_keys( $clause_keys, '' ),
					array(
						'join'  => array(),
						'where' => array(),
					)
				),
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
	 * @param array<string,mixed>|string $query Array or URL query string of parameters.
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

	/** Boot / Setup *********************************************************/

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

		// Maybe invoke a new table schema class (instances were returned above).
		if ( is_string( $this->table_schema ) && ! empty( $this->table_schema ) ) {
			try {
				$this->schema_object = $this->instantiate_class( $this->table_schema );
				$log_error           = ( null === $this->schema_object );
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
	 * the entire list by declaring the property before init() is called.
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
		$this->query_var_default_value = $this->generate_random_string();

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Default query variables.
		$this->query_var_defaults = array(

			// Statements.
			'explain'           => false,
			'distinct'          => false,
			'select'            => '',
			'index_hints'       => array(),

			// COUNT(*).
			'count'             => false,

			// Fields.
			'fields'            => '',
			'groupby'           => '',

			// Boundaries.
			'number'            => 100,
			'offset'            => '',
			'orderby'           => $primary,
			'order'             => 'DESC',

			// Disable row count.
			'no_found_rows'     => true,

			// Caching.
			'cache_results'     => true,
			'update_item_cache' => true,
			'update_meta_cache' => true,

			// Relationship priming (quiet by default; array of names to prime).
			'with'              => false,

			// Cross-parser criteria tree (empty = implicit AND across parsers, the default).
			'criteria'          => array(),

			// Friendlier column-filter container; folds to {column}__in in normalize.
			'by'                => array(),

			// Aggregate container ( alias => { function, column } ); see #225.
			'aggregate'         => array(),
		);

		/*
		 * The keys above are reserved control vars. Capture them before the parsers
		 * register their per-column shorthands, so a column named like one of them
		 * (e.g. a 'count', 'order', or 'number' column) cannot clobber its default in
		 * the loop below - the control var keeps precedence.
		 */
		$protected = array_keys( $this->query_var_defaults );

		/* Query Parsers ******************************************************/

		// Setup parsers array.
		$this->parsers = array();

		// Loop through query var parsers.
		foreach ( $this->query_var_parsers as $class ) {

			/*
			 * Instantiate the descriptor with its caller (this Query), so every
			 * parser method can read $this->caller - descriptors and the transient
			 * parse-time instances alike. Skip a non-parser class.
			 */
			$parser = $this->instantiate_class( $class, '', array(), $this );
			if ( ! ( $parser instanceof \BerlinDB\Database\Parsers\Base ) ) {
				continue;
			}

			// Setup the parser.
			$this->parsers[ $parser->name ] = $parser;

			// Register every query var key the parser claims, at its own default.
			$default = $parser->get_query_var_default();

			foreach ( $parser->get_query_var_keys() as $key ) {

				/*
				 * A per-column shorthand must not overwrite a reserved control var's
				 * default. A column named like one (e.g. a 'count', 'order', or
				 * 'number' column) keeps the control meaning; the column is still
				 * filterable via its '{column}__in' shorthand. Log the collision so a
				 * schema author can see why the bare-name filter is unavailable.
				 */
				if ( in_array( $key, $protected, true ) ) {
					$this->log( 'warning', 'query_var', "Column '{$key}' collides with the reserved '{$key}' query var; the control var keeps precedence. Filter this column via '{$key}__in'." );
					continue;
				}

				$this->query_var_defaults[ $key ] = $default;
			}
		}
	}

	/** Public Parsers ********************************************************/

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
	protected function get_query_var_parser_classes() {

		// Default set of query parser classes.
		$parsers = array(
			'BerlinDB\\Database\\Parsers\\By',
			'BerlinDB\\Database\\Parsers\\In',
			'BerlinDB\\Database\\Parsers\\NotIn',
			'BerlinDB\\Database\\Parsers\\Search',
			'BerlinDB\\Database\\Parsers\\Date',
			'BerlinDB\\Database\\Parsers\\Meta',
			'BerlinDB\\Database\\Parsers\\Compare',
			'BerlinDB\\Database\\Parsers\\Relationship',
		);

		// Return the query var parser classes, filtered.
		return $this->filter_query_var_parsers( $parsers );
	}

	/**
	 * Get registered parsers, optionally filtered by property values.
	 *
	 * Mirrors get_columns() - pass an $args array of property => value pairs
	 * to narrow the result set. For example:
	 *
	 *   get_parsers( array( 'sortable' => true ) )
	 *
	 * returns only parsers that contribute ORDER BY SQL via get_orderby_sql().
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $args     Optional property => value pairs to filter by.
	 * @param string               $operator Comparison operator: 'and' or 'or'. Default 'and'.
	 * @param mixed                $field    Optional. Return this property from each match instead of the full object.
	 *
	 * @return list<\BerlinDB\Database\Parsers\Base> Filtered array of parser objects (or field values).
	 */
	protected function get_parsers( $args = array(), $operator = 'and', $field = false ) {

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

	/** Identity *************************************************************/

	/**
	 * Return the fully-qualified table name for use in SQL statements.
	 *
	 * The WordPress table prefix ($wpdb->prefix) is resolved by looking up
	 * $wpdb->{$this->table_name} - a dynamic property that Table::set_db_interface()
	 * registers when the corresponding Table class is instantiated. This means
	 * multisite prefix changes (triggered by the switch_blog action) are always
	 * reflected here automatically, because Table owns and updates that property.
	 *
	 * If $wpdb does not have the property registered (i.e. the Table class has
	 * not been instantiated), this falls back to $this->table_name, which carries
	 * only the plugin prefix - not the WordPress table prefix.
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
	 * The alias is set during init() and carries only the plugin prefix
	 * ($this->prefix). It is never looked up via $wpdb - aliases are
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
	public function get_item_name_plural(): string {
		return (string) $this->item_name_plural;
	}

	/**
	 * Return this query's plugin prefix.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_prefix(): string {
		return (string) $this->prefix;
	}

	/** Results ***************************************************************/

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

	/** Query Variables *******************************************************/

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

	/**
	 * Get the full array of query variables for the current run.
	 *
	 * Exposed as public so parser hooks can read the query vars set for the current
	 * run.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed> Parsed query vars for the current run.
	 */
	public function get_query_vars(): array {
		return $this->query_vars;
	}

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
	public function is_query_var_default( $key = '' ): bool {
		return ( $this->get_query_var( $key ) === $this->query_var_default_value );
	}

	/**
	 * Check whether a raw query variable value strictly equals the unique default
	 * starting value.
	 *
	 * Internal collaborator API for Query parsers; public so parser objects can
	 * identify the unset sentinel without knowing its generated value.
	 *
	 * @since 3.1.0
	 * @internal
	 *
	 * @param mixed $value Query variable value.
	 * @return bool
	 */
	public function is_query_var_default_value( $value = null ): bool {
		return ( $value === $this->query_var_default_value );
	}

	/**
	 * Return the query-var default sentinel (the "unset" marker).
	 *
	 * Internal collaborator API for Query parsers; public so a parser can resolve
	 * its registered default (Parsers\Base::get_query_var_default()) without
	 * reaching into a protected Query property.
	 *
	 * @since 3.1.0
	 * @internal
	 *
	 * @return string
	 */
	public function get_query_var_default_value(): string {
		return $this->query_var_default_value;
	}

	/**
	 * Supply the results-invariant vars to the Cache trait.
	 *
	 * Traits\Query\Cache reads the exclusion list through this accessor because a
	 * trait cannot declare RESULTS_INVARIANT_VARS as a constant on PHP 8.1.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	protected function get_results_invariant_vars(): array {
		return self::RESULTS_INVARIANT_VARS;
	}

	/**
	 * Parses arguments passed to the item query with default query parameters.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Forces some $query_vars if counting
	 *
	 * @param array<string,mixed>|string $query Query arguments array or string.
	 */
	private function parse_query( $query = array() ): void {

		// Stash the raw query args before any defaults are merged in.
		$this->set_current( 'query_var_originals', $this->parse_args( $query ) );

		// Setup the $query_vars parsed var.
		$this->query_vars = $this->parse_args(
			$this->get_current_array( 'query_var_originals' ),
			$this->query_var_defaults
		);

		/*
		 * Canonicalize the type-stable structural query vars (so semantically
		 * identical queries hash to the same cache key).
		 */
		$this->query_vars = $this->validate_query_vars( $this->query_vars );

		/*
		 * Normalize the query vars BEFORE the action (count overrides + high-level
		 * directive translation), so hooks and the SQL parsers see canonical vars
		 * rather than raw directive state.
		 */
		$this->query_vars = $this->normalize_query_vars( $this->query_vars );

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
	 * Canonicalize the type-stable structural query vars.
	 *
	 * Coerces the fixed, framework-level query vars to their canonical types
	 * (ints, booleans, ASC/DESC) so that semantically identical queries - e.g.
	 * number '5' vs 5, order 'asc' vs 'ASC' - produce the SAME cache key instead
	 * of fragmenting it, and so consumers always see a clean type.
	 *
	 * Deliberately scoped: only the closed set of structural vars is touched.
	 * Column/clause vars ({col}, date_query, meta_query, orderby/fields shapes,
	 * etc.) are left to their parsers, preserving the engine's fail-open routing.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The merged query vars.
	 * @return array<string,mixed> The query vars with structural keys canonicalized.
	 */
	private function validate_query_vars( array $query_vars = array() ): array {

		// Structural query var => canonicalizing callback.
		$callbacks = array(
			'number'            => 'intval',
			'order'             => array( $this, 'parse_order' ),
			'explain'           => array( $this, 'sanitize_boolean' ),
			'distinct'          => array( $this, 'sanitize_boolean' ),
			'count'             => array( $this, 'sanitize_boolean' ),
			'no_found_rows'     => array( $this, 'sanitize_boolean' ),
			'cache_results'     => array( $this, 'sanitize_boolean' ),
			'update_item_cache' => array( $this, 'sanitize_boolean' ),
			'update_meta_cache' => array( $this, 'sanitize_boolean' ),
		);

		// Coerce each present structural var to its canonical type.
		foreach ( $callbacks as $key => $callback ) {
			if ( array_key_exists( $key, $query_vars ) ) {
				$query_vars[ $key ] = call_user_func( $callback, $query_vars[ $key ] );
			}
		}

		return $query_vars;
	}

	/**
	 * Normalize the query vars early, before parsing.
	 *
	 * The all-vars counterpart to each parser's own var-local parse_query_vars()
	 * (which runs later, isolated to its single var). Here every registered parser descriptor
	 * may rewrite the FULL query vars - translating a high-level directive into
	 * another parser's canonical var (e.g. store-backed meta_query -> relation_query,
	 * or 'relation' -> {fk}__in / relation_query). Runs BEFORE the
	 * parse_{items}_query action, so the action and the SQL parsers see canonical
	 * vars. A descriptor may return a 'query_filter_short_circuit' sentinel to fail
	 * the query closed; it is consumed here. See berlindb/core #204.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The validated query vars.
	 * @return array<string,mixed> The normalized query vars.
	 */
	private function normalize_query_vars( array $query_vars = array() ): array {

		// One per-run reset for every normalizer below.
		$this->set_current( 'query_filter_short_circuit', false );

		/*
		 * Counting overrides the other structural vars (count was canonicalized
		 * to a boolean by validate_query_vars()).
		 */
		if ( ! empty( $query_vars[ 'count' ] ) ) {
			$query_vars[ 'number' ]            = false;
			$query_vars[ 'fields' ]            = '';
			$query_vars[ 'orderby' ]           = '';
			$query_vars[ 'no_found_rows' ]     = true;
			$query_vars[ 'update_item_cache' ] = false;
			$query_vars[ 'update_meta_cache' ] = false;
		}

		// Fold the 'by' column-filter container into canonical {column}__in vars.
		$query_vars = $this->normalize_by_container( $query_vars );

		// Canonicalize the 'aggregate' container to alias => { function, column }.
		$query_vars = $this->normalize_aggregate_container( $query_vars );

		// Each registered parser descriptor may rewrite the full query vars.
		foreach ( $this->parsers as $descriptor ) {
			$query_vars = $descriptor->normalize_query_vars( $query_vars, $this );
		}

		// Apply any fail-closed sentinel a descriptor returned.
		return $this->consume_query_filter_sentinel( $query_vars );
	}

	/**
	 * Fold the 'by' column-filter container into canonical {column}__in vars.
	 *
	 * The 'by' container is a friendlier, collision-proof column-filter shorthand -
	 * array( 'by' => array( 'status' => 3, 'type' => array( 1, 2 ) ) ). Each entry is
	 * rewritten to the In parser's canonical '{column}__in' var (which renders
	 * '= value' for a single value and 'IN (...)' for a list), so a column whose bare
	 * name collides with a reserved control var (e.g. a 'count' or 'order' column)
	 * stays filterable. It lives here, not in a parser, because a parser has no logging
	 * channel of its own; a By parser could not surface these diagnostics on the Query.
	 *
	 * Rules: only In-supported columns (in => true) are translated; an explicit
	 * top-level '{column}__in' wins over the container entry; an empty value, unknown
	 * column, or malformed container is logged and ignored. The container is consumed.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The query vars, mid-normalize.
	 * @return array<string,mixed> The query vars with 'by' folded away.
	 */
	private function normalize_by_container( array $query_vars = array() ): array {

		// Consume the container up front, whatever its shape.
		$by = $query_vars[ 'by' ] ?? array();
		unset( $query_vars[ 'by' ] );

		/*
		 * A non-array 'by' is malformed (the default and an explicit list are both
		 * arrays). Check the type BEFORE emptiness, so a falsy scalar - 0, false, ''
		 * - is reported rather than silently dropped as "empty".
		 */
		if ( ! is_array( $by ) ) {
			$this->log( 'warning', 'by', 'The "by" query var must be an array of column => value(s); ignoring it.' );
			return $query_vars;
		}

		// An empty container (the default, or an explicit empty array) is a no-op.
		if ( array() === $by ) {
			return $query_vars;
		}

		// The unset-var sentinel, to tell an explicit {column}__in from a default one.
		$sentinel = $this->get_query_var_default_value();

		// Fold each column entry into its canonical {column}__in var.
		foreach ( $by as $column => $value ) {

			// Skip an empty value (no filter), matching the engine's empty-filter behavior.
			if ( ( '' === $value ) || ( array() === $value ) || ( null === $value ) ) {
				continue;
			}

			// Only translate an In-supported column; log and ignore anything else.
			if ( empty(
				$this->get_columns(
					array(
						'name' => (string) $column,
						'in'   => true,
					)
				)
			) ) {
				$this->log( 'warning', 'by', "The 'by' entry '{$column}' is not an in-filterable column; ignoring it." );
				continue;
			}

			/*
			 * An explicit top-level {column}__in wins over the container entry. The
			 * key is always present (In registers its default), so an explicit value
			 * is any that is not the unset sentinel - read it directly rather than
			 * with ??, so an explicit null is honored, not treated as absent.
			 */
			$key      = "{$column}__in";
			$existing = array_key_exists( $key, $query_vars )
				? $query_vars[ $key ]
				: $sentinel;

			if ( $existing !== $sentinel ) {
				continue;
			}

			// Rewrite to the canonical In var.
			$query_vars[ $key ] = (array) $value;
		}

		return $query_vars;
	}

	/**
	 * Canonicalize the 'aggregate' container to alias => array( function, column ).
	 *
	 * Accepts the shorthand array( 'sum' => 'amount' ) (the key is the function, the
	 * value the column, the alias defaults to the function) and the aliased forms
	 * array( 'revenue' => array( 'sum', 'amount' ) ) or array( 'revenue' => array(
	 * 'function' => 'sum', 'column' => 'amount' ) ). Each entry is validated against the
	 * aggregate function allow-list and the schema columns; an invalid entry or a
	 * duplicate alias is logged and dropped. The result replaces the container so the
	 * execution path (see #225) reads one canonical shape.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The query vars, mid-normalize.
	 * @return array<string,mixed> The query vars with a canonical 'aggregate' container.
	 */
	private function normalize_aggregate_container( array $query_vars = array() ): array {

		// Consume the container up front, whatever its shape.
		$aggregate = $query_vars[ 'aggregate' ] ?? array();
		unset( $query_vars[ 'aggregate' ] );

		// A non-array 'aggregate' is malformed.
		if ( ! is_array( $aggregate ) ) {
			$this->log( 'warning', 'aggregate', 'The "aggregate" query var must be an array of aggregates; ignoring it.' );
			return $query_vars;
		}

		// An empty container (the default, or an explicit empty array) is a no-op.
		if ( array() === $aggregate ) {
			return $query_vars;
		}

		// Canonicalize each entry to alias => array( function, column ).
		$canonical = array();

		foreach ( $aggregate as $key => $spec ) {
			$entry = $this->canonicalize_aggregate_entry( (string) $key, $spec );

			// Skip an invalid entry (already logged).
			if ( null === $entry ) {
				continue;
			}

			// Reject a duplicate alias rather than silently overwriting it.
			if ( array_key_exists( $entry[ 'alias' ], $canonical ) ) {
				$this->log( 'warning', 'aggregate', "Duplicate aggregate alias '{$entry[ 'alias' ]}'; keeping the first." );
				continue;
			}

			$canonical[ $entry[ 'alias' ] ] = array(
				'function' => $entry[ 'function' ],
				'column'   => $entry[ 'column' ],
			);
		}

		/*
		 * Set the container only when something survived, so an all-invalid container
		 * behaves like an absent one (a normal query, not an empty aggregate) and does
		 * not leak an empty 'aggregate' into the cache key.
		 */
		if ( array() !== $canonical ) {
			$query_vars[ 'aggregate' ] = $canonical;
		}

		return $query_vars;
	}

	/**
	 * Resolve one 'aggregate' entry into an { alias, function, column } triple.
	 *
	 * @since 3.1.0
	 *
	 * @param string $key  The container key (the function for shorthand, else the alias).
	 * @param mixed  $spec The column name (shorthand) or a { function, column } spec.
	 * @return array{alias: string, function: string, column: string}|null The resolved
	 *                                                                      triple, or null when invalid.
	 */
	private function canonicalize_aggregate_entry( string $key, $spec ): ?array {

		// Shorthand: array( 'sum' => 'amount' ) - key is the function, value the column.
		if ( is_string( $spec ) ) {
			$alias    = $key;
			$function = $key;
			$column   = $spec;

			// Aliased: array( 'revenue' => array( 'sum', 'amount' ) ) or a named spec.
		} elseif ( is_array( $spec ) ) {
			$alias    = $key;
			$function = (string) ( $spec[ 'function' ] ?? ( $spec[ 0 ] ?? '' ) );
			$column   = (string) ( $spec[ 'column' ] ?? ( $spec[ 1 ] ?? '' ) );

			// Anything else is malformed.
		} else {
			$this->log( 'warning', 'aggregate', "Aggregate entry '{$key}' must be a column name or a { function, column } spec; ignoring it." );
			return null;
		}

		// Validate the function against the aggregate allow-list (case-insensitive).
		$function = strtoupper( $function );

		if ( ! in_array( $function, self::AGGREGATE_FUNCTIONS, true ) ) {
			$this->log( 'warning', 'aggregate', "Unsupported aggregate function for '{$alias}'; ignoring it." );
			return null;
		}

		/*
		 * COUNT( * ) is the one aggregate with no column - a row count. Every other
		 * form needs a real column (a bare '*' is only valid for COUNT); SUM and AVG
		 * additionally need a numeric one. MAX/MIN/COUNT work on any column.
		 */
		$counts_rows = ( 'COUNT' === $function ) && ( '*' === $column );

		if ( false === $counts_rows ) {

			if ( '*' === $column ) {
				$this->log( 'warning', 'aggregate', "Aggregate '{$alias}' ({$function}) needs a column, not '*'; ignoring it." );
				return null;
			}

			$column_object = $this->get_column_by( array( 'name' => $column ) );

			if ( ! ( $column_object instanceof Column ) ) {
				$this->log( 'warning', 'aggregate', "Aggregate '{$alias}' references unknown column '{$column}'; ignoring it." );
				return null;
			}

			if ( in_array( $function, array( 'SUM', 'AVG' ), true ) && ! $column_object->is_numeric() ) {
				$this->log( 'warning', 'aggregate', "Aggregate '{$alias}' ({$function}) needs a numeric column; '{$column}' is not. Ignoring it." );
				return null;
			}
		}

		return array(
			'alias'    => $alias,
			'function' => $function,
			'column'   => $column,
		);
	}

	/**
	 * Consume a fail-closed sentinel a normalizer left in the query vars.
	 *
	 * A parser descriptor cannot reach Query's private short-circuit helper, so it
	 * signals fail-closed by returning a 'query_filter_short_circuit' query var
	 * (array{source, reason}); this applies and removes it.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The normalized query vars.
	 * @return array<string,mixed> The query vars without the sentinel.
	 */
	private function consume_query_filter_sentinel( array $query_vars ): array {

		$sentinel = $query_vars[ 'query_filter_short_circuit' ] ?? null;

		// Nothing to consume.
		if ( empty( $sentinel ) ) {
			return $query_vars;
		}

		// Remove it so it never reaches the cache key or the SQL parsers.
		unset( $query_vars[ 'query_filter_short_circuit' ] );

		$source = is_array( $sentinel ) ? (string) ( $sentinel[ 'source' ] ?? 'query_filter' ) : 'query_filter';
		$reason = is_array( $sentinel ) ? (string) ( $sentinel[ 'reason' ] ?? '' ) : '';

		$this->short_circuit_query_filter( $source, $reason );

		return $query_vars;
	}

	/**
	 * Flag the current run to return no rows (fail-closed query filter).
	 *
	 * Shared by the high-level query-filter translators (relationship filters and
	 * meta_query translation). An empty $reason marks a legitimate empty match (no
	 * log); a non-empty $reason marks a misconfigured filter and is logged as a
	 * warning under the $source channel so the failure is attributable.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $source  Log channel/code (e.g. 'relation_filter', 'meta_query').
	 * @param string               $reason  Why the filter could not be applied.
	 * @param array<string,mixed> $context Optional log context.
	 */
	private function short_circuit_query_filter( string $source, string $reason = '', array $context = array() ): void {

		// Flag the current run to return no rows.
		$this->set_current( 'query_filter_short_circuit', true );

		// Log misconfigured filters; an empty reason is a legitimate no-match.
		if ( '' !== $reason ) {
			$this->log( 'warning', $source, $reason, $context );
		}
	}

	/** Found Rows ************************************************************/

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

		// Aggregate mode has no row count (and builds no request_clauses to reuse).
		if ( 'aggregate' === $this->get_query_mode() ) {
			$this->set_current( 'found_items', 0 );
			return;
		}

		/*
		 * Count mode: a plain count IS the found-items total; a grouped count reports
		 * the number of group rows it returned.
		 */
		if ( 'count' === $this->get_query_mode() ) {
			$retval = ( is_numeric( $item_ids ) && ! $this->get_query_var( 'groupby' ) )
				? $item_ids
				: count( (array) $item_ids );

			$this->set_current( 'found_items', (int) $retval );
			return;
		}

		// Rows mode: this page's rows, or the supplementary total when paginating.
		$this->set_current( 'found_items', $this->count_found_items( $item_ids ) );
	}

	/**
	 * Count the total matching rows for a rows-mode query.
	 *
	 * Defaults to the number of primary IDs this page returned. When the query is
	 * paginated - a 'number' limit with found-rows enabled - it instead runs the
	 * supplementary count that reuses the request clauses: parse_count() renders
	 * COUNT(DISTINCT primary) under DISTINCT so a row-multiplying JOIN does not inflate
	 * the total, and LIMIT / ORDER BY / the standalone DISTINCT keyword are dropped.
	 * get_items() turns the result into max_num_pages. A rows-mode concern only - count
	 * and aggregate never reach it.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $item_ids The primary IDs this page returned.
	 * @return int The total matching rows.
	 */
	private function count_found_items( $item_ids ): int {

		// This page's rows - the total unless a paginated query needs the full count.
		$retval = count( (array) $item_ids );

		// No pagination requested: the page count is the total.
		if ( ! empty( $this->get_query_var( 'no_found_rows' ) ) || empty( $this->get_query_var( 'number' ) ) ) {
			return $retval;
		}

		// Reuse the request clauses, overriding a few to make it a clean COUNT.
		$r = $this->parse_args(
			array(
				'fields'   => $this->parse_count( true ),
				'limits'   => '',
				'orderby'  => '',
				'distinct' => '',
			),
			$this->get_current_array( 'request_clauses' )
		);

		// Build and filter the found-items query.
		$query = $this->filter_found_items_query( $this->parse_request_clauses( $r ) );

		// Run it when there is one; otherwise keep this page's count.
		return ! empty( $query )
			? (int) $this->db()->get_var( $query )
			: $retval;
	}

	/** General ***************************************************************/

	/**
	 * Fetch raw results directly from the database.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses query()
	 *
	 * @param list<string>         $cols       Columns for `SELECT`.
	 * @param array<string,mixed> $where_cols Where clauses. Each key-value pair in the array
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
		$r = $this->parse_args(
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
