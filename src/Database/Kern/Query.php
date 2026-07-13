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
 *     @type list<string> $with        Relationship names to eager-prime (warms
 *                                      the related items' caches to avoid N+1).
 *                                      Names only; an empty array disables.
 *                                      Default empty array.
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
	use \BerlinDB\Database\Traits\Query\Variables;
	use \BerlinDB\Database\Traits\Query\Operands;

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
		$clause_keys = array( 'explain', 'select', 'distinct', 'fields', 'from', 'index_hints', 'join', 'where', 'groupby', 'having', 'orderby', 'limits' );

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
	 * Return whether this query may write (add / update / delete items).
	 *
	 * Derived from the schema: a Query configured with a read-only schema (a View's
	 * schema, or any reference relation) refuses writes, so the caller never has to
	 * know it is querying a view - declare read_only once on the shared schema and
	 * both the View and any Query using it derive it. The Crud write methods gate on
	 * this.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function can_write(): bool {
		return ! (
			( $this->schema_object instanceof Schema )
			&& $this->schema_object->is_read_only()
		);
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
			'with'              => array(),

			// Cross-parser criteria tree (empty = implicit AND across parsers, the default).
			'criteria'          => array(),

			// Friendlier column-filter container; folds to {column}__in in normalize.
			'by'                => array(),

			// Aggregate container ( alias => { function, column } ); see #225.
			'aggregate'         => array(),

			// HAVING container ( aggregate alias => { compare, value } ); grouped-only, see #225.
			'having'            => array(),
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

	/** Constant Accessors ****************************************************/

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
	 * Supply the aggregate-function allow-list to the Variables trait.
	 *
	 * Traits\Query\Variables reads AGGREGATE_FUNCTIONS through this accessor for
	 * the same reason: a trait cannot declare it as a constant on PHP 8.1.
	 *
	 * @since 3.1.0
	 *
	 * @return string[]
	 */
	protected function get_aggregate_functions(): array {
		return self::AGGREGATE_FUNCTIONS;
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
