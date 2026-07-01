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

use BerlinDB\Database\Operands\Column as ColumnOperand;
use BerlinDB\Database\Operands\Func as FuncOperand;

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

	/** Meta ******************************************************************/

	/**
	 * Memoized 'meta' relationship store, resolved lazily by get_meta_store().
	 *
	 * Reused across this instance's *_item_meta() calls because building the store
	 * instantiates the remote meta Query (and its primary) - wasteful to repeat.
	 *
	 * Three states in one property: the sentinel `false` means "not resolved yet";
	 * `null` is a valid resolution (this object has no meta store); a MetaStore is
	 * the resolved store.
	 *
	 * @since 3.1.0
	 * @var   \BerlinDB\Database\Interfaces\MetaStore|null|false
	 */
	private $meta_store = false;

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

			// Relationship priming (quiet by default; array of names to prime).
			'with'              => false,

			// Cross-parser criteria tree (empty = implicit AND across parsers, the default).
			'criteria'          => array(),
		);

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
				$this->query_var_defaults[ $key ] = $default;
			}
		}
	}

	/** Public Columns ********************************************************/

	/**
	 * Is a column valid?
	 *
	 * @since 3.0.0
	 * @param string $column_name Column name.
	 * @return bool
	 */
	private function is_valid_column( $column_name = '' ): bool {

		// Bail if column name not valid string.
		if ( empty( $column_name ) || ! is_string( $column_name ) ) {
			return false;
		}

		/*
		 * Exact match on purpose: this gates a column name that flows into SQL
		 * downstream as given. Schema::has_column() normalizes its input (e.g.
		 * "id-- " sanitizes to "id"), which would validate a string that is then
		 * interpolated verbatim - so validation must match the raw name exactly.
		 */
		return ( $this->get_column_by( array( 'name' => $column_name ) ) instanceof Column );
	}

	/**
	 * Return array of column names.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Pass $args and $operator to filter names.
	 *              No longer calls array_flip().
	 *
	 * @param array<string,mixed> $args     Arguments to filter columns by.
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

		// Prefer the schema's own answer when it exposes one.
		if ( is_callable( array( $this->schema_object, 'get_primary_column_name' ) ) ) {

			/** @var string $name */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$name = $this->schema_object->get_primary_column_name();

			return $name;
		}

		// Fall back to the primary-flagged column for a get_columns()-only schema.
		return $this->get_column_field( array( 'primary' => true ), 'name', 'id' );
	}

	/**
	 * Get a column from an array of arguments.
	 *
	 * @since 1.0.0
	 *
	 * @template TDefault
	 * @param array<string,mixed> $args     Arguments to get a column by.
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
	 * @param array<string,mixed> $args Arguments to get a column by.
	 * @return \BerlinDB\Database\Kern\Column|false Column object, or false if not found.
	 */
	public function get_column_by( $args = array() ) {

		// Filter columns.
		$filter = $this->get_columns( $args );

		// Return column or false.
		$column = ! empty( $filter )
			? reset( $filter )
			: false;

		return ( $column instanceof Column )
			? $column
			: false;
	}

	/**
	 * Get columns from the schema, optionally filtered.
	 *
	 * Delegates to the schema object's get_columns(): $args and $operator filter the
	 * columns via wp_filter_object_list() (the schema normalizes a `type` arg to the
	 * stored uppercase), and $field plucks a property from each match.
	 *
	 * @since 1.0.0
	 * @since 3.0.0
	 * @since 3.1.0 Delegates to Schema::get_columns(); dropped the legacy inline-$columns source.
	 *
	 * @param array<string,mixed> $args     Arguments to filter columns by.
	 * @param string              $operator Optional. The logical operation to perform.
	 * @param bool|string         $field    Optional. A field from the object to place
	 *                                      instead of the entire object. Default false.
	 * @return Column[]|list<mixed> Array of Column objects, or field values if $field is set.
	 */
	public function get_columns( $args = array(), $operator = 'and', $field = false ): array {

		// Without a schema there are no columns to return.
		if ( ! is_callable( array( $this->schema_object, 'get_columns' ) ) ) {
			return array();
		}

		/** @var Column[]|list<mixed> $columns */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$columns = $this->schema_object->get_columns( $args, $operator, $field );

		return $columns;
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
	protected function get_columns_field_by( $key = '', $values = array(), $field = '', $fallback = false ) {

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
	protected function get_column_name_aliased( $column_name = '', $alias = true ): string {

		// Default return value.
		$retval = $column_name;

		/*
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
	 * @since 3.1.0 An empty $column_name defaults to the primary column.
	 * @param string $column_name Column name. Defaults to the primary column.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	public function get_quoted_column_name_aliased( $column_name = '', $alias = true ): string {

		// Default to the primary column when no name is given.
		if ( '' === $column_name ) {
			$column_name = $this->get_primary_column_name();
		}

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

	/** Public Relationships **************************************************/

	/**
	 * Get every relationship declared by this query's schema.
	 *
	 * Delegates to Schema::get_relationships(), which compiles each column's
	 * shorthand declarations into Relationship value objects. The objects stay
	 * inert here; runtime resolution (remote table, cache priming, lazy Row
	 * loading) is a query-level concern layered on top. See berlindb/core #193.
	 *
	 * @since 3.1.0
	 *
	 * @return Relationship[]
	 */
	public function get_relationships() {

		// Bail with no relationships unless the schema can supply them.
		if ( ! ( $this->schema_object instanceof Schema ) ) {
			return array();
		}

		return $this->schema_object->get_relationships();
	}

	/**
	 * Get a single relationship by its accessor name.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name Relationship accessor name (e.g. 'customer').
	 * @return Relationship|false The matching Relationship, or false if none.
	 */
	public function get_relationship( $name = '' ) {

		// Bail if no name to match.
		if ( ! is_string( $name ) || ( '' === $name ) ) {
			return false;
		}

		// Return the first relationship whose accessor name matches.
		foreach ( $this->get_relationships() as $relationship ) {
			if ( $relationship->name === $name ) {
				return $relationship;
			}
		}

		// Not found.
		return false;
	}

	/**
	 * Return validation errors that need this query's remote context to detect.
	 *
	 * The remote tier of relationship validation (see #206). Schema validates the
	 * local, context-free declaration (Schema::get_validation_errors()); this
	 * resolves each remote Query and checks what only it can see:
	 * - The remote class exists but is NOT a sibling Query.
	 * - A referenced remote column does not exist on the remote schema.
	 *
	 * On demand by design: call it from a plugin's tests or dev tooling. (Local
	 * column type vs. remote type compatibility is intentionally NOT checked yet -
	 * exact-type equality produces false positives across int/bigint/unsigned and
	 * aliases; a family-based check is a follow-up.)
	 *
	 * @since 3.1.0
	 *
	 * @return string[] Array of human-readable error strings. Empty if valid.
	 */
	public function get_relationship_errors(): array {
		$errors = array();

		// Check each declared relationship against its resolved remote query.
		foreach ( $this->get_relationships() as $relationship ) {

			// A stable label for messages: the accessor name, or a placeholder.
			$name = ( '' !== $relationship->name )
				? $relationship->name
				: '(unnamed)';

			// Resolve the remote query (a fresh, guarded instance; null when not).
			$remote = $this->resolve_remote_query( $relationship );

			/*
			 * Unresolvable. A missing class is Schema's to report; the distinct
			 * "class exists but is not a Query" case is ours - this is the tier
			 * that instantiates and can actually tell. Either way, without a
			 * remote query the remote-column check cannot run.
			 */
			if ( null === $remote ) {
				$class = $relationship->get_query_class();

				if ( ( '' !== $class ) && class_exists( $class ) ) {
					$errors[] = "Relationship {$name} remote class {$class} is not a Query.";
				}

				continue;
			}

			// Every referenced remote column must exist on the remote schema.
			foreach ( $relationship->references as $reference ) {
				if ( ! ( $remote->get_column_by( array( 'name' => $reference ) ) instanceof Column ) ) {
					$errors[] = "Relationship {$name} references unknown remote column {$reference}.";
				}
			}
		}

		return $errors;
	}

	/**
	 * Get the relationships where this query's rows hold the foreign key.
	 *
	 * @since 3.1.0
	 *
	 * @return Relationship[]
	 */
	public function get_belongs_to_relationships() {
		return $this->get_relationships_by_type( 'belongs_to' );
	}

	/**
	 * Get the relationships where remote rows point back at this query.
	 *
	 * @since 3.1.0
	 *
	 * @return Relationship[]
	 */
	public function get_has_many_relationships() {
		return $this->get_relationships_by_type( 'has_many' );
	}

	/**
	 * Filter this query's relationships by type.
	 *
	 * @since 3.1.0
	 *
	 * @param string $type Relationship type ('belongs_to' or 'has_many').
	 * @return Relationship[]
	 */
	private function get_relationships_by_type( $type = '' ): array {

		// Default return value.
		$retval = array();

		// Collect relationships whose type matches.
		foreach ( $this->get_relationships() as $relationship ) {
			if ( $relationship->type === $type ) {
				$retval[] = $relationship;
			}
		}

		return $retval;
	}

	/**
	 * Resolve a relationship's remote Query class to a fresh, guarded instance.
	 *
	 * Returns null when the relationship names no class, the class does not exist,
	 * or it is not a sibling Query - so callers fail closed on a misdeclared or
	 * missing remote. Instantiation is setup-only (no query). Preset-composed
	 * relationships (e.g. meta) name a real class too, so they resolve here exactly
	 * like declared ones.
	 *
	 * @since 3.1.0
	 *
	 * @param Relationship $relationship The relationship whose remote query to build.
	 * @return self|null The remote query instance, or null when unresolvable.
	 */
	private function resolve_remote_query( Relationship $relationship ): ?self {

		// Instantiate the relationship's declared remote class; must be a sibling Query.
		$remote = $this->instantiate_class( $relationship->get_query_class() );

		return ( $remote instanceof self )
			? $remote
			: null;
	}

	/**
	 * Whether a local key value represents "no relation".
	 *
	 * POLICY: a foreign key of 0, '0', '', null (or any other empty() value) is
	 * treated as unset - there is no related row - mirroring WordPress's
	 * convention that 0 is the no-parent/no-object value. This is the single,
	 * named home for that rule, used by get_related() and the priming collectors,
	 * so the choice is explicit and testable. If a scheme ever needs a literal
	 * 0/'0' key to be a valid relation, change it here.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value The local key value to test.
	 * @return bool True when the value is an empty/no-relation key.
	 */
	private function is_empty_relationship_key( $value ): bool {
		return empty( $value );
	}

	/**
	 * Get the related data for one of this query's items, by relationship name.
	 *
	 * Explicit accessor for a declared relationship (see berlindb/core #193). For
	 * a belongs_to relationship this returns the single related Row (or null);
	 * for has_many it returns an array of related Rows. When the belongs_to side
	 * references the remote primary key, the lookup runs through get_item(), so a
	 * previously primed cache (the 'with' query arg) makes it a cache hit.
	 *
	 * The Relationship value object stays inert: this method does the remote
	 * resolution, keeping Row a pure data object.
	 *
	 * @since 3.1.0
	 *
	 * @param object $item Item produced by this query.
	 * @param string $name Relationship accessor name (e.g. 'parent').
	 * @return object|object[]|null Related Row for belongs_to (or null); array of
	 *                              Rows for has_many; null when not resolvable.
	 */
	public function get_related( $item = null, $name = '' ) {

		// Bail without an item object or a relationship name.
		if ( ! is_object( $item ) || ! is_string( $name ) || ( '' === $name ) ) {
			return null;
		}

		// Bail unless the relationship is declared.
		$relationship = $this->get_relationship( $name );
		if ( ! ( $relationship instanceof Relationship ) ) {
			return null;
		}

		// Only single-column relationships are resolved for now.
		$columns    = $relationship->columns;
		$references = $relationship->references;
		if ( ( count( $columns ) !== 1 ) || ( count( $references ) !== 1 ) ) {
			return null;
		}

		/*
		 * Bail unless the local key is present and represents an actual relation
		 * (see is_empty_relationship_key() for the 0/'0'/''/null policy).
		 */
		if ( ! isset( $item->{$columns[0]} ) || $this->is_empty_relationship_key( $item->{$columns[0]} ) ) {
			return ( 'has_many' === $relationship->type )
				? array()
				: null;
		}

		// Resolve the remote query instance (guarded; null when unresolvable).
		$remote = $this->resolve_remote_query( $relationship );
		if ( null === $remote ) {
			return null;
		}

		// Local key value to match against the remote side.
		$local_value = $item->{$columns[0]};

		/*
		 * has_many: many remote rows point back at this item's key. Resolve via
		 * the remote query's own result cache, which a prior 'with' prime warms
		 * in bulk (one query per value, keyed identically to this call).
		 *
		 * 'number' => 0 (no limit): a relationship accessor returns the FULL child
		 * set, not a paginated page. This must match the priming side
		 * (prime_has_many) exactly, or a primed call (all children) and an
		 * unprimed call (the default 100-row page) would disagree. Pagination is
		 * the caller's job via a direct query().
		 */
		if ( 'has_many' === $relationship->type ) {
			$found = $remote->query(
				array(
					$references[0] => $local_value,
					'number'       => 0,
				)
			);

			return is_array( $found )
				? $found
				: array();
		}

		// belongs_to referencing the remote primary key - cache-friendly.
		if ( $references[0] === $remote->get_primary_column_name() ) {
			$found = $remote->get_item( $local_value );

			return ! empty( $found )
				? $found
				: null;
		}

		// belongs_to referencing a non-primary remote column.
		$found = $remote->query(
			array(
				$references[0] => $local_value,
				'number'       => 1,
			)
		);

		return ( is_array( $found ) && ! empty( $found ) )
			? reset( $found )
			: null;
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

	/** Select Execution *****************************************************/

	/**
	 * Fire the "pre_get_{plural}" action, where installs scope a query just in time.
	 *
	 * Shared by get_items() (the SELECT path) and select_ids() (delete-by-filter), so
	 * an install's pre-get scoping constrains both equally.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	private function pre_get_items(): void {

		// Generate action name based on the plural item name.
		$action_name = $this->apply_prefix( 'pre_get_' . $this->get_item_name_plural() );

		// Bail if no action name.
		if ( '' === $action_name ) {
			return;
		}

		/**
		 * Fires before object items are retrieved.
		 *
		 * @since 1.0.0
		 *
		 * @param \BerlinDB\Database\Kern\Query $query Current instance passed by reference.
		 */
		do_action_ref_array(
			$action_name,
			array(
				&$this,
			)
		);
	}

	/**
	 * Get the items, populate them, and return them.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int|string,mixed>|int Array of items, or number of items when 'count' is passed as a query var.
	 */
	private function get_items(): array|int {

		// Fire the pre-get action, where installs scope the query just in time.
		$this->pre_get_items();

		/*
		 * A normalized query directive resolved to no possible matches: return
		 * nothing without caching (an empty resolved set must never widen to all rows).
		 */
		if ( true === $this->get_current( 'query_filter_short_circuit', false ) ) {
			$this->set_found_items( array() );

			/*
			 * Mirror get_items()'s count/non-count return shapes for empty: a plain
			 * count is 0; a grouped count or a non-count query is an empty array.
			 */
			$is_plain_count = $this->get_query_var( 'count' ) && ! $this->get_query_var( 'groupby' );

			$this->items = $is_plain_count
				? 0
				: array();

			return $this->items;
		}

		/*
		 * Check the cache. EXPLAIN returns a plan (not rows) and must reflect the
		 * current optimizer state, so it is never served from or stored in the cache.
		 */
		$cache_results = (bool) $this->get_query_var( 'cache_results' )
			&& empty( $this->get_query_var( 'explain' ) );
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

		// Return count results directly - already int (get_var) or array (groupby).
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
	 * Used internally to get a list of item IDs matching the query vars.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses wp_parse_list() instead of wp_parse_id_list()
	 *
	 * @return array<bool|float|int|string>|array<string,mixed>[]|int Array of item IDs for a full query, or int/rows for a count query.
	 */
	private function get_item_ids(): array|int {

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
	 * Select the primary IDs of the rows matching a set of query-var filters.
	 *
	 * A narrow, side-effect-free companion to get_item_ids(): it compiles the
	 * passed vars into a JOIN/WHERE (running the parsers + Clauses\Builder, the
	 * same construction the SELECT path uses) and returns the distinct primary IDs,
	 * without the get_item_ids() lifecycle - no cache, no found_items, no count or
	 * pagination handling, and no mutation of the query's stored clauses/request.
	 *
	 * Fails closed: if the filters compile to no WHERE, this refuses and returns an
	 * empty array rather than selecting every row. A malformed 'criteria' tree
	 * compiles to a non-empty "1 = 0" WHERE (the Clauses\Where safeguard) and so
	 * simply matches nothing. DISTINCT guards against JOINs multiplying rows.
	 *
	 * @since 3.1.0
	 * @internal Operation collaborator (Operations\Delete, and later Update).
	 *
	 * @param array<string,mixed> $query_vars Query-var filters (same vocabulary as query()).
	 * @return array<int,int|string> Distinct primary IDs, or an empty array when refused / unmatched.
	 */
	public function select_ids( array $query_vars = array() ): array {

		/*
		 * Mirror the SELECT path's parse_query preparation, but ON $this->query_vars
		 * and snapshot/restore so it leaves no trace. Parser callbacks read state
		 * back off the Query (e.g. the Relationship parser fetches its translated
		 * relation_query via caller()->get_query_var()), so the normalized vars must
		 * be the Query's vars for the whole build, not just a local copy.
		 */
		/*
		 * Snapshot both the vars and their defaults: a scoping hook may call
		 * set_query_var(), which writes both, and this transient ID selection must
		 * leave no trace on the reused Query instance.
		 */
		$saved_query_vars     = $this->query_vars;
		$saved_query_defaults = $this->query_var_defaults;

		try {

			/*
			 * Run the SELECT path's full preparation on $this->query_vars: merge
			 * defaults, canonicalize, normalize high-level directives (relation,
			 * store-backed meta_query, ...) AND fire the parse_{plural}_query action,
			 * where installs scope the query via set_query_var(). Parser callbacks read
			 * these vars back off the Query, so skipping any step would let a delete
			 * reach rows a normal query() would have excluded.
			 */
			$this->parse_query( $query_vars );

			// Fire the same pre-get scoping action a normal read does, before SQL.
			$this->pre_get_items();

			// A filter normalized to "no possible matches" resolves to nothing.
			if ( true === $this->get_current( 'query_filter_short_circuit', false ) ) {
				return array();
			}

			// Compile the JOIN/WHERE via the parsers and Clauses\Builder.
			$join_where = $this->parse_join_where( $this->query_vars );

			// Render the WHERE and JOIN fragments.
			$where = $this->parse_where_clause( $join_where[ 'where' ] );
			$join  = $this->parse_join_clause( $join_where[ 'join' ] );

			/*
			 * Fail closed: a delete must never resolve to "all rows", so require a WHERE
			 * constraint. A JOIN alone is not safe to rely on -- a LEFT JOIN does not
			 * constrain the primary table, and even an INNER JOIN's effect depends on
			 * its ON clause -- so a filter that compiles to only a JOIN deletes nothing
			 * rather than risk an unbounded delete. (A relationship filter that bounds
			 * solely through a JOIN, with no remote WHERE, is therefore a no-op here for
			 * now; constrain via a WHERE, or add explicit JOIN-strategy support later.)
			 */
			if ( '' === $where ) {
				$this->log( 'warning', 'operation', 'Refusing to select IDs without a WHERE clause.' );
				return array();
			}

			/*
			 * Assemble a narrow "SELECT DISTINCT primary" clause set, in the same shape
			 * the SELECT path produces (so the query-clauses filter sees every key).
			 */
			$clauses = $this->distinct_id_clauses(
				array(
					'join'  => $join,
					'where' => $where,
				)
			);

			/*
			 * Apply the same {plural}_query_clauses filter the SELECT path runs, so any
			 * install-level scoping (tenant/ownership/status/capability predicates a site
			 * adds to WHERE/JOIN) also constrains which rows can be resolved and deleted.
			 * The empty-WHERE refusal above is intentionally checked on the user's filter
			 * BEFORE this: site scoping narrows a delete, it never enables an unbounded one.
			 */
			$clauses = $this->filter_query_clauses( $clauses );

			// Join the non-empty fragments into a single statement.
			$request = $this->parse_request_clauses( $clauses );

			// Execute, then shape each raw ID to the primary column type.
			$item_ids = $this->db()->get_col( $request );

			return array_map( array( $this, 'shape_item_id' ), wp_parse_list( $item_ids ) );

		} finally {

			// Always restore the Query's own vars and defaults, even on an early return.
			$this->query_vars         = $saved_query_vars;
			$this->query_var_defaults = $saved_query_defaults;
		}
	}

	/**
	 * Reshape a clause set to select the DISTINCT primary key.
	 *
	 * Forces the id-selection shape: DISTINCT on, fields set to the aliased primary key,
	 * a plain SELECT over the base table, no hints. It keeps the clauses that decide
	 * WHICH rows match - JOIN and WHERE - so a query-clauses filter's scoping still
	 * applies, but drops the clauses that reshape the RESULT - GROUP BY, ORDER BY, LIMIT
	 * - because those would narrow the id set (one key per group, or a truncated page)
	 * and undercount an aggregate.
	 *
	 * Like the SELECT path, this assumes a query-clauses filter SCOPES the base query -
	 * adding JOIN/WHERE predicates against the base table's alias - and reads FROM the
	 * base table; it deliberately does not honor a filter that rewrites FROM to a
	 * different source (the field/alias references are the base table's throughout, as
	 * everywhere else in Query). select_ids() passes just its compiled JOIN/WHERE; an
	 * aggregate over a fan-out JOIN passes its whole filtered clause set (see
	 * aggregate_via_subquery()). The canonical clause order is fixed here, so
	 * the imploded SQL is well-formed no matter the caller's key order.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,string> $clauses A (possibly partial) clause set to reshape.
	 *
	 * @return array<string,string> The clause set selecting DISTINCT the primary key.
	 */
	private function distinct_id_clauses( array $clauses ): array {
		return array(
			'explain'     => '',
			'select'      => $this->parse_select(),
			'distinct'    => $this->parse_distinct( true ),
			'fields'      => $this->get_quoted_column_name_aliased(),
			'from'        => $this->parse_from(),
			'index_hints' => '', // ID resolution is deliberately un-hinted.
			'join'        => $clauses[ 'join' ] ?? '',
			'where'       => $clauses[ 'where' ] ?? '',
			'groupby'     => '',
			'orderby'     => '',
			'limits'      => '',
		);
	}

	/** Aggregates ************************************************************/

	/**
	 * Return the SUM of a numeric column across the matching rows.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $column     Column name to sum.
	 * @param array<string,mixed>  $query_vars Optional query vars to filter the rows.
	 *
	 * @return float|null The sum, or null if the column is unknown/non-numeric or no
	 *                    rows matched.
	 */
	public function get_sum( string $column, array $query_vars = array() ): ?float {
		$value = $this->aggregate( 'SUM', $column, $query_vars );

		return ( null === $value )
			? null
			: (float) $value;
	}

	/**
	 * Return the AVG of a numeric column across the matching rows.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $column     Column name to average.
	 * @param array<string,mixed>  $query_vars Optional query vars to filter the rows.
	 *
	 * @return float|null The average, or null if the column is unknown/non-numeric or
	 *                    no rows matched.
	 */
	public function get_avg( string $column, array $query_vars = array() ): ?float {
		$value = $this->aggregate( 'AVG', $column, $query_vars );

		return ( null === $value )
			? null
			: (float) $value;
	}

	/**
	 * Return the MAX of a column across the matching rows.
	 *
	 * Works on any comparable column (numeric, date, string), so the raw scalar is
	 * returned as-is; cast at the call site when a specific type is expected.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $column     Column name.
	 * @param array<string,mixed>  $query_vars Optional query vars to filter the rows.
	 *
	 * @return string|null The maximum value, or null if the column is unknown or no
	 *                     rows matched.
	 */
	public function get_max( string $column, array $query_vars = array() ): ?string {
		return $this->aggregate( 'MAX', $column, $query_vars );
	}

	/**
	 * Return the MIN of a column across the matching rows.
	 *
	 * Works on any comparable column (numeric, date, string), so the raw scalar is
	 * returned as-is; cast at the call site when a specific type is expected.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $column     Column name.
	 * @param array<string,mixed>  $query_vars Optional query vars to filter the rows.
	 *
	 * @return string|null The minimum value, or null if the column is unknown or no
	 *                     rows matched.
	 */
	public function get_min( string $column, array $query_vars = array() ): ?string {
		return $this->aggregate( 'MIN', $column, $query_vars );
	}

	/**
	 * Run a scalar aggregate ( FUNC( column ) ) over the matching rows.
	 *
	 * The aggregate expression is rendered through the same operand value objects the
	 * clause builder uses (Operands\Func over Operands\Column), so the column is
	 * resolved and quoted exactly as it is everywhere else. The row set is built the
	 * same way select_ids() does - full parse_query preparation, scoping action, and
	 * JOIN/WHERE compilation - on a snapshot of the Query's vars, restored afterward.
	 *
	 * A filter that produces a JOIN (a meta / relationship filter, or a scoping hook)
	 * can fan out base rows one-to-many, so aggregating over the joined rows directly
	 * would double-count SUM/AVG. When a JOIN is present the aggregate is wrapped
	 * around a distinct-primary subquery (see aggregate_via_subquery()),
	 * so each base row is counted once; a plain or column-filtered aggregate has no
	 * JOIN and runs in place.
	 *
	 * Fails closed (returns null) on an unknown column, a non-numeric column when the
	 * aggregate requires one, or a filter that short-circuits to no rows.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $function   The SQL aggregate (SUM/AVG/MAX/MIN);
	 *                                         SUM/AVG require a numeric column.
	 * @param string               $column     Column name to aggregate.
	 * @param array<string,mixed>  $query_vars Query vars to filter the rows.
	 *
	 * @return string|null The raw scalar result, or null.
	 */
	private function aggregate( string $function, string $column, array $query_vars ): ?string {

		// Resolve and validate the column; fail closed on an unknown or wrong type.
		$column_object = $this->get_column_by( array( 'name' => $column ) );

		if ( ! ( $column_object instanceof Column ) ) {
			return null;
		}

		// SUM and AVG need a numeric column; MAX and MIN work on any comparable one.
		$requires_numeric = in_array( $function, array( 'SUM', 'AVG' ), true );

		if ( ( true === $requires_numeric ) && ! $column_object->is_numeric() ) {
			return null;
		}

		// Render FUNC( column ) through the operand value objects (one render path).
		$operand = new ColumnOperand(
			array(
				'column' => $column_object,
				'alias'  => $this->get_table_alias(),
			)
		);
		$fields  = ( new FuncOperand(
			array(
				'sql'  => $function,
				'args' => array( $operand ),
			)
		) )->get_sql();

		/*
		 * Build the row set the way select_ids() does: run the full SELECT-path
		 * preparation on a snapshot of the Query's own vars (so parser callbacks read
		 * consistent state), then restore. Unlike select_ids(), an empty WHERE is
		 * allowed - an aggregate over the whole table is legitimate.
		 */
		$saved_query_vars     = $this->query_vars;
		$saved_query_defaults = $this->query_var_defaults;

		try {
			$this->parse_query( $query_vars );
			$this->pre_get_items();

			// A filter normalized to "no possible matches" resolves to nothing.
			if ( true === $this->get_current( 'query_filter_short_circuit', false ) ) {
				return null;
			}

			$join_where = $this->parse_join_where( $this->query_vars );
			$where      = $this->parse_where_clause( $join_where[ 'where' ] );
			$join       = $this->parse_join_clause( $join_where[ 'join' ] );

			// Assemble a scalar-aggregate clause set, in the SELECT path's shape.
			$clauses = $this->aggregate_clauses( $fields, $join, $where );

			// Apply the same query-clauses filter the SELECT path runs (site scoping).
			$clauses = $this->filter_query_clauses( $clauses );

			/*
			 * A JOIN-producing filter (a meta / relationship filter, or a scoping hook)
			 * can fan out base rows one-to-many, so aggregating over the joined rows
			 * would count a row once per match and inflate SUM/AVG. When a JOIN is
			 * present, wrap the aggregate around a distinct-primary subquery so each base
			 * row is counted once; with no JOIN there is nothing to fan out.
			 */
			if ( '' !== trim( (string) ( $clauses[ 'join' ] ?? '' ) ) ) {
				$clauses = $this->aggregate_via_subquery( $fields, $clauses );
			}

			// Join the non-empty fragments and read the single scalar.
			$request = $this->parse_request_clauses( $clauses );

			return $this->db()->get_var( $request );

		} finally {

			// Always restore the Query's own vars and defaults.
			$this->query_vars         = $saved_query_vars;
			$this->query_var_defaults = $saved_query_defaults;
		}
	}

	/**
	 * Wrap a scalar aggregate around a distinct-primary subquery.
	 *
	 * A filter that joins the base table one-to-many (a meta / relationship filter,
	 * or a scoping hook) fans out its rows, so aggregating over the joined result
	 * double-counts. Given the compiled, already site-scoped clause set, this returns
	 * a new clause set whose inner subquery resolves each matching primary key once
	 * (via distinct_id_clauses(), which keeps the filter's JOIN/WHERE scoping),
	 * and whose outer FUNC( column ) runs over just the base rows in that set - each
	 * counted once, with no JOIN to fan out.
	 *
	 * The base table keeps its alias inside the subquery: the compiled JOIN/WHERE
	 * already reference it, and the subquery's own FROM re-scopes it, so the outer
	 * "primary IN ( ... )" stays uncorrelated.
	 *
	 * @since 3.1.0
	 *
	 * @param string                $fields  The rendered FUNC( column ) expression.
	 * @param array<string,string>  $clauses The compiled aggregate clause set; its
	 *                                       JOIN and WHERE carry the filter + scoping.
	 *
	 * @return array<string,string> A clause set that aggregates over base rows bounded
	 *                              by a distinct-primary IN subquery, with no JOIN.
	 */
	private function aggregate_via_subquery( string $fields, array $clauses ): array {

		$primary_ref = $this->get_quoted_column_name_aliased();

		/*
		 * The inner subquery reshapes the already-filtered clause set to select each
		 * matching primary key once, keeping the JOIN/WHERE scoping but dropping the
		 * result-shaping clauses (the shared shape select_ids() also uses).
		 */
		$inner         = $this->distinct_id_clauses( $clauses );
		$inner_request = $this->parse_request_clauses( $inner );

		/*
		 * The outer aggregate runs over the base table with no JOIN, bounded to the
		 * distinct primary keys the subquery resolved - one row each, no fan-out.
		 */
		return $this->aggregate_clauses( $fields, '', "WHERE {$primary_ref} IN ( {$inner_request} )" );
	}

	/**
	 * Build the clause set for a scalar aggregate over the base table.
	 *
	 * The SELECT-path clause shape (so the query-clauses filter sees every key) with
	 * the aggregate expression as its fields and no DISTINCT / GROUP BY / ORDER BY /
	 * LIMIT. aggregate() passes the compiled JOIN/WHERE; aggregate_via_subquery()
	 * passes no JOIN and a "primary IN ( ... )" WHERE.
	 *
	 * @since 3.1.0
	 *
	 * @param string $fields The rendered aggregate expression, e.g. SUM( `t`.`col` ).
	 * @param string $join   The JOIN fragment (empty for the subquery-bounded outer).
	 * @param string $where  The WHERE fragment.
	 *
	 * @return array<string,string> The aggregate clause set.
	 */
	private function aggregate_clauses( string $fields, string $join, string $where ): array {
		return array(
			'explain'     => '',
			'select'      => $this->parse_select(),
			'distinct'    => '',
			'fields'      => $fields,
			'from'        => $this->parse_from(),
			'index_hints' => '',
			'join'        => $join,
			'where'       => $where,
			'groupby'     => '',
			'orderby'     => '',
			'limits'      => '',
		);
	}

	/**
	 * Used internally to generate the SQL string for IN and NOT IN clauses.
	 *
	 * The $values being passed in should not be validated, and they will be
	 * escaped before they are concatenated together and returned as a string.
	 *
	 * @since 3.0.0
	 *
	 * @param string                          $column_name Column name.
	 * @param array<array-key,mixed>|string  $values      Value(s) to escape. Arrays are
	 *                                                     flattened into the prepared statement.
	 * @param bool                            $wrap        To wrap in parenthesis.
	 * @param string                          $pattern     Pattern to prepare with.
	 *
	 * @return string Escaped/prepared SQL, possibly wrapped in parenthesis.
	 */
	public function get_in_sql( $column_name = '', $values = array(), $wrap = true, $pattern = '' ): string {

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

		// Each registered parser descriptor may rewrite the full query vars.
		foreach ( $this->parsers as $descriptor ) {
			$query_vars = $descriptor->normalize_query_vars( $query_vars, $this );
		}

		// Apply any fail-closed sentinel a descriptor returned.
		return $this->consume_query_filter_sentinel( $query_vars );
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

	/** Clause Parsers ********************************************************/

	/**
	 * Parse all of the $query_vars.
	 *
	 * Optionally accepts an array of custom $query_vars that can be used
	 * instead of the default ones.
	 *
	 * Calls filter_query_clauses() on the return value.
	 *
	 * @since 3.0.0
	 * @param array<string,mixed> $query_vars Optional. Default empty array.
	 *                                         Fallback to Query::query_vars.
	 * @return array<string,mixed> Query clauses, parsed from Query vars.
	 */
	private function parse_query_vars( $query_vars = array() ): array {

		// Maybe fallback to $query_vars.
		if ( empty( $query_vars ) && ! empty( $this->query_vars ) ) {
			$query_vars = $this->query_vars;
		}

		// Parse arguments.
		$r = $this->parse_args( $query_vars );

		// Parse $query_vars.
		$join_where = $this->parse_join_where( $r );

		// Parse all clauses.
		$clauses = array(
			'explain'     => $this->parse_explain( $r[ 'explain' ] ),
			'select'      => $this->parse_select(),
			'distinct'    => $this->parse_distinct( $r[ 'distinct' ], $r[ 'count' ] ),
			'fields'      => $this->parse_fields( $r[ 'fields' ], $r[ 'count' ], $r[ 'groupby' ] ),
			'from'        => $this->parse_from(),
			'index_hints' => $this->parse_index_hints( $r[ 'index_hints' ] ),
			'join'        => $this->parse_join_clause( $join_where[ 'join' ] ),
			'where'       => $this->parse_where_clause( $join_where[ 'where' ] ),
			'groupby'     => $this->parse_groupby( $r[ 'groupby' ], 'GROUP BY' ),
			'orderby'     => $this->parse_orderby( $r[ 'orderby' ], $r[ 'order' ], 'ORDER BY' ),
			'limits'      => $this->parse_limits( $r[ 'number' ], $r[ 'offset' ] ),
		);

		// Return clauses.
		return $this->filter_query_clauses( $clauses );
	}

	/**
	 * Parse the 'join' and 'where' $query_vars for all known columns.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query_vars Already-parsed query vars (from parse_query_vars()).
	 * @return array{join: list<string>, where: list<string>} Array of 'join' and 'where' clauses.
	 */
	private function parse_join_where( array $query_vars = array() ): array {

		// Phase 1: run the parsers (the sole caller passes already-parsed vars).
		$parsers = $this->parse_join_where_parsers( $query_vars );

		/*
		 * Read the cross-parser directive from the passed vars only when 'criteria'
		 * is NOT a real column. A same-named column makes the var a column filter
		 * (the 'by' parser handles it); its value - or its unset sentinel - must not
		 * be mistaken for the directive and failed closed. With no such column, the
		 * directive is read as given (an array tree applies; a malformed scalar fails
		 * closed). Reading it from $query_vars (rather than $this) keeps this method
		 * pure on its input, so Operations can compile a WHERE from arbitrary vars.
		 */
		$criteria = $this->is_valid_column( 'criteria' )
			? array()
			: ( $query_vars[ 'criteria' ] ?? array() );

		// Phase 2: assemble their per-parser fragments into the final clause lists.
		$builder = new \BerlinDB\Database\Clauses\Builder(
			array(
				'criteria' => $criteria,
				'join'     => $parsers[ 'join' ],
				'where'    => $parsers[ 'where' ],
				'parsers'  => array_keys( $this->parsers ),
			)
		);

		// Surface any criteria misconfiguration the builder recorded.
		foreach ( $builder->get_warnings() as $warning ) {
			$this->log( 'warning', 'criteria', $warning );
		}

		return array(
			'join'  => $builder->get_join_clauses(),
			'where' => $builder->get_where_clauses(),
		);
	}

	/**
	 * Parse join/where subclauses for query var parser objects.
	 *
	 * Used by parse_join_where().
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query_vars Query vars.
	 * @return array{join: array<string,mixed>, where: array<string,string>}
	 */
	private function parse_join_where_parsers( $query_vars = array() ): array {

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

		/*
		 * Every parser's dedicated container query var (e.g. compare_query,
		 * date_query, meta_query, relation_query). A parser handed the FULL
		 * query_vars must not see another parser's container, or it can recurse
		 * into a clause it does not own (cross-parser bleed). Collected once and
		 * stripped per parser below.
		 */
		$container_vars = array();
		foreach ( $this->parsers as $descriptor ) {
			$container_var = $descriptor->get_query_var();
			if ( is_string( $container_var ) && ( '' !== $container_var ) ) {
				$container_vars[] = $container_var;
			}
		}

		// Loop through parsers.
		foreach ( $this->parsers as $key => $descriptor ) {

			// Derive the class from the already-instantiated descriptor.
			$class = get_class( $descriptor );

			// Default to all $query_vars.
			$qv       = $query_vars;
			$narrowed = false;

			// Check if $query_vars contains the query_var for this parser.
			$parser_query_var = $descriptor->get_query_var();
			if ( ! is_null( $parser_query_var ) && ! empty( $query_vars[ $parser_query_var ] ) ) {

				/*
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
					$qv       = $query_vars[ $parser_query_var ];
					$narrowed = true;
				}
			}

			/*
			 * Cross-parser isolation: when a parser operates on the full
			 * query_vars (it was not narrowed to its own sub-array), strip the
			 * sibling container vars so it cannot recurse into a clause owned by
			 * another parser. This is what fed e.g. a compare_query clause to the
			 * Date parser. A parser's own container is kept; per-column keys
			 * (status__in, name_search, date_created_query, ...) are not
			 * containers and are untouched.
			 */
			if ( false === $narrowed ) {
				foreach ( $container_vars as $container_var ) {
					if ( $container_var !== $parser_query_var ) {
						unset( $qv[ $container_var ] );
					}
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
	 * Parse if query to be EXPLAIN'ed.
	 *
	 * @since 3.0.0
	 * @param bool $explain Default false. True to EXPLAIN.
	 * @return string
	 */
	private function parse_explain( $explain = false ): string {

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
	private function parse_select(): string {
		return 'SELECT';
	}

	/**
	 * Parse whether the "SELECT" should be DISTINCT.
	 *
	 * Driven by the 'distinct' query var (like 'explain'); Operations\Delete's ID
	 * selection also asks for it explicitly by passing true. Suppressed when counting:
	 * COUNT carries its own DISTINCT (see parse_count()), so a standalone SELECT
	 * DISTINCT keyword would be redundant. Owning that guard here keeps the clause
	 * assembly a clean one-liner (the same way parse_fields() reads 'count').
	 *
	 * @since 3.1.0
	 * @param bool $distinct Default false. True to de-duplicate the selected rows.
	 * @param bool $count    Default false. True when this is a COUNT request.
	 * @return string Default empty string, or "DISTINCT".
	 */
	private function parse_distinct( $distinct = false, $count = false ): string {

		// A COUNT carries its own DISTINCT; no standalone keyword.
		if ( ! empty( $count ) ) {
			return '';
		}

		// Maybe fallback to $query_vars.
		if ( empty( $distinct ) ) {
			$distinct = $this->get_query_var( 'distinct' );
		}

		// Return SQL.
		return ! empty( $distinct )
			? 'DISTINCT'
			: '';
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
	private function parse_fields( $fields = '', $count = false, $groupby = '', $alias = true ): string {

		// Maybe fallback to $query_vars.
		if ( empty( $count ) ) {
			$count = $this->get_query_var( 'count' );
		}

		// Default return value.
		$retval = '';

		// Counting, so use groupby.
		if ( ! empty( $count ) ) {

			// Use count instead.
			$groupby_sql = is_array( $groupby ) ? implode( ', ', $groupby ) : $groupby;
			$retval      = $this->parse_count( (bool) $count, $groupby_sql );

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
	 * @since 3.1.0 Added the $distinct parameter.
	 * @param bool   $count Whether to return a count instead of results.
	 * @param string $groupby Column name to group results by.
	 * @param string $name Column name.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @param bool   $distinct Count distinct primary IDs (COUNT(DISTINCT id)) instead of rows.
	 * @return string
	 */
	private function parse_count( $count = false, $groupby = '', $name = 'count', $alias = true, $distinct = false ): string {

		// Maybe fallback to $query_vars.
		if ( empty( $count ) ) {
			$count = $this->get_query_var( 'count' );
		}

		// Bail if not counting.
		if ( empty( $count ) ) {
			return '';
		}

		// Maybe fallback to $query_vars.
		if ( empty( $distinct ) ) {
			$distinct = $this->get_query_var( 'distinct' );
		}

		/*
		 * Count distinct primary IDs when DISTINCT is requested, so a JOIN that
		 * multiplies rows does not inflate the total; a bare COUNT(*) would. The
		 * DISTINCT belongs inside the COUNT, not as a standalone SELECT keyword.
		 */
		$retval = ! empty( $distinct )
			? 'COUNT(DISTINCT ' . $this->get_quoted_column_name_aliased( $this->get_primary_column_name(), $alias ) . ')'
			: 'COUNT(*)';

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
	private function parse_from( $table = '', $alias = '' ): string {

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
	private function parse_groupby( $groupby = '', $before = '', $alias = true ): string {

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
	private function parse_orderby( $orderby = '', $order = '', $before = '', $alias = true ): string {

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
			$retval = $this->parse_single_orderby_fragment( $parsed, $order );

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

				// Append the orderby fragment (with optional NULLS emulation) to array.
				$orderby_array[] = $this->parse_single_orderby_fragment( $parsed, $value );
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
	private function parse_where_clause( $where = array() ): string {

		// Combine the parser WHERE fragments with a boolean AND.
		$sql = ( new \BerlinDB\Database\Clauses\BooleanGroup(
			array(
				'relation' => 'AND',
				'items'    => array_values( (array) $where ),
			)
		) )->get_sql();

		// Prefix WHERE only when there is something to filter.
		return ( '' === $sql )
			? ''
			: "WHERE {$sql}";
	}

	/**
	 * Parse all of the join clauses.
	 *
	 * @since 3.0.0
	 * @param list<string> $join JOIN SQL clause fragments.
	 * @return string A single SQL statement.
	 */
	private function parse_join_clause( $join = array() ): string {

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
	 * @param array<string,mixed> $clauses SQL clause fragments.
	 * @return array<string,mixed>
	 */
	private function parse_query_clauses( $clauses = array() ): array {

		// Maybe fallback to query_clauses.
		if ( empty( $clauses ) ) {
			$clauses = $this->get_current_array( 'query_clauses' );
		}

		// Default return value.
		$retval = $this->parse_args( $clauses );

		// Return array of clauses.
		return $retval;
	}

	/**
	 * Parse all SQL $request_clauses into a single SQL query string.
	 *
	 * @since 3.0.0
	 * @param array<string,mixed> $clauses SQL clause fragments.
	 * @return string A single SQL statement.
	 */
	private function parse_request_clauses( $clauses = array() ): string {

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
	private function parse_limits( $number = 0, $offset = 0 ): string {

		// Default return value.
		$retval = '';

		// No negative numbers.
		$limit  = $this->sanitize_absint( $number );
		$offset = $this->sanitize_absint( $offset );

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
	private function parse_single_orderby( $orderby = '', $alias = true ): string {

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
	private function parse_order( $order = 'DESC' ): string {

		// Bail if malformed.
		if ( empty( $order ) || ! is_string( $order ) ) {
			return 'DESC';
		}

		// Compare the leading word, so a trailing NULLS FIRST/LAST is ignored here.
		return ( 'ASC' === strtoupper( (string) strtok( trim( $order ), ' ' ) ) )
			? 'ASC'
			: 'DESC';
	}

	/**
	 * Build one column's ORDER BY fragment, emulating an optional NULLS FIRST/LAST.
	 *
	 * Combines the already-resolved column SQL with its ASC/DESC direction. MySQL has
	 * no NULLS FIRST/LAST syntax (it groups NULLs first under ASC, last under DESC),
	 * so when the direction value carries a trailing "NULLS FIRST"/"NULLS LAST" (e.g.
	 * 'ASC NULLS LAST'), a leading ISNULL( col ) sort key forces the grouping
	 * deterministically -- ISNULL is 1 for NULL, so DESC floats nulls first and ASC
	 * sinks them last.
	 *
	 * @since 3.1.0
	 *
	 * @param string $column_sql The already-resolved column SQL to order by.
	 * @param string $value      Direction ('ASC'/'DESC'), optionally with 'NULLS FIRST'/'NULLS LAST'.
	 * @return string The ORDER BY fragment ('col ASC|DESC', optionally ISNULL-prefixed).
	 */
	private function parse_single_orderby_fragment( string $column_sql, $value = '' ): string {

		// The ASC/DESC direction; parse_order() reads the leading word, ignoring NULLS.
		$order = $this->parse_order( $value );

		// The single place the NULLS FIRST/LAST suffix is parsed; absent -> plain term.
		if ( ! is_string( $value ) || ! preg_match( '/\bNULLS\s+(FIRST|LAST)\b/i', $value, $matches ) ) {
			return "{$column_sql} {$order}";
		}

		// ISNULL( col ) is 1 for NULL, so DESC floats nulls first, ASC sinks them last.
		$nulls_order = ( 'FIRST' === strtoupper( $matches[ 1 ] ) ) ? 'DESC' : 'ASC';

		return "ISNULL( {$column_sql} ) {$nulls_order}, {$column_sql} {$order}";
	}

	/** Index Hints ***********************************************************/

	/**
	 * Sanitize the 'index_hints' query var into a clean list of validated specs.
	 *
	 * Accepts a single associative spec or a list of them. Each spec shapes to:
	 *   array(
	 *     'type'    => 'use' | 'force' | 'ignore',
	 *     'indexes' => list of declared index names (or 'primary'),
	 *     'for'     => '' | 'join' | 'order by' | 'group by',
	 *   )
	 *
	 * A hint never affects which rows return, so this fails OPEN: an unknown index
	 * name, an unknown type, or a USE/FORCE conflict drops the offending name/spec
	 * and logs it rather than failing the query. Index names are validated against
	 * the schema's declared indexes plus PRIMARY, which also closes off injection.
	 *
	 * MySQL forbids mixing USE and FORCE on one table reference, so the first of the
	 * two seen wins and later conflicting specs are dropped. IGNORE always coexists.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $hints A single hint spec, or a list of them.
	 * @return array<int,array{type:string,indexes:list<string>,for:string}> Clean specs, possibly empty.
	 */
	private function sanitize_index_hints( $hints = array() ): array {

		// Nothing to do for an empty or non-array value.
		if ( empty( $hints ) || ! is_array( $hints ) ) {
			return array();
		}

		// A single associative spec (carrying a recognized key) is a one-element list.
		if ( isset( $hints[ 'type' ] ) || isset( $hints[ 'indexes' ] ) || isset( $hints[ 'for' ] ) ) {
			$hints = array( $hints );
		}

		// Allowed type vocabulary, and the FOR-scope vocabulary (with friendly aliases).
		$types   = array( 'use', 'force', 'ignore' );
		$for_map = array(
			''         => '',
			'join'     => 'join',
			'order by' => 'order by',
			'group by' => 'group by',
			'orderby'  => 'order by',
			'groupby'  => 'group by',
		);

		// The schema owns index identity; null if unavailable (every name then drops).
		$schema = ( $this->schema_object instanceof Schema )
			? $this->schema_object
			: null;

		// Normalize each spec.
		$clean     = array();
		$exclusive = ''; // First of use|force wins; the other conflicts (USE+FORCE is a MySQL error).

		foreach ( $hints as $hint ) {

			// A spec must be an array carrying a type.
			if ( ! is_array( $hint ) || ! isset( $hint[ 'type' ] ) ) {
				$this->log( 'warning', 'index_hints', 'Dropped a malformed index hint (not a spec).' );
				continue;
			}

			// Type must be one of the closed set.
			$type = is_string( $hint[ 'type' ] )
				? strtolower( trim( $hint[ 'type' ] ) )
				: '';

			if ( ! in_array( $type, $types, true ) ) {
				$this->log( 'warning', 'index_hints', 'Dropped an index hint with an unknown type.' );
				continue;
			}

			// USE and FORCE cannot be mixed for the same table; the first one wins.
			if ( in_array( $type, array( 'use', 'force' ), true ) ) {
				if ( '' === $exclusive ) {
					$exclusive = $type;
				} elseif ( $type !== $exclusive ) {
					$this->log( 'warning', 'index_hints', 'Dropped a conflicting index hint (USE and FORCE cannot be mixed).' );
					continue;
				}
			}

			// FOR scope is optional; an unknown value coerces to no scope.
			$raw_for = isset( $hint[ 'for' ] ) && is_string( $hint[ 'for' ] )
				? strtolower( trim( $hint[ 'for' ] ) )
				: '';

			if ( ! array_key_exists( $raw_for, $for_map ) ) {
				$this->log( 'warning', 'index_hints', 'Ignored an unknown FOR scope on an index hint.' );
				$raw_for = '';
			}

			// Validate each index name against declared indexes (+ PRIMARY); dedupe.
			$raw_indexes = isset( $hint[ 'indexes' ] )
				? (array) $hint[ 'indexes' ]
				: array();

			$names = array();

			foreach ( $raw_indexes as $name ) {
				$canonical = ( null !== $schema )
					? $schema->canonical_index_name( $name )
					: '';

				if ( '' === $canonical ) {
					$this->log( 'warning', 'index_hints', 'Dropped an unknown index name from an index hint.' );
					continue;
				}

				if ( ! in_array( $canonical, $names, true ) ) {
					$names[] = $canonical;
				}
			}

			// A hint with no valid index is dropped (v1 does not emit USE INDEX ()).
			if ( empty( $names ) ) {
				$this->log( 'warning', 'index_hints', 'Dropped an index hint with no valid indexes.' );
				continue;
			}

			$clean[] = array(
				'type'    => $type,
				'indexes' => $names,
				'for'     => $for_map[ $raw_for ],
			);
		}

		return $clean;
	}

	/**
	 * Render the 'index_hints' query var as the SQL that follows the table reference.
	 *
	 * Self-sanitizing: it runs sanitize_index_hints() itself rather than trusting the
	 * caller, because a parse_{plural}_query / pre_get_{plural} hook can replace the
	 * 'index_hints' var via set_query_var() after validation, and raw input reaching
	 * MySQL would break fail-open. Keeping the "raw input -> safe SQL" boundary inside
	 * the renderer means no call site can bypass it. Specs are declarative, not
	 * sequential - MySQL collects them by type and scope - so the order here is
	 * cosmetic. PRIMARY is emitted bare; every other name is quoted. The fragment has
	 * NO leading space (it is its own clause slot; the assembler space-joins clauses).
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $hints The 'index_hints' query var (raw or sanitized).
	 * @return string e.g. "FORCE INDEX FOR JOIN (`idx_status`)", or '' when there are none.
	 */
	private function parse_index_hints( $hints = array() ): string {

		// Sanitize at the render boundary (handles hook-mutated raw input).
		$hints = $this->sanitize_index_hints( $hints );

		// Nothing to render.
		if ( empty( $hints ) ) {
			return '';
		}

		// Keyword + scope vocabularies (INDEX and KEY are synonyms; we emit INDEX).
		$keywords = array(
			'use'    => 'USE INDEX',
			'force'  => 'FORCE INDEX',
			'ignore' => 'IGNORE INDEX',
		);
		$scopes   = array(
			'join'     => ' FOR JOIN',
			'order by' => ' FOR ORDER BY',
			'group by' => ' FOR GROUP BY',
		);

		// Render each spec.
		$parts = array();

		foreach ( $hints as $hint ) {

			// Defensive: the sanitizer guarantees the shape, but never trust blindly.
			if ( ! is_array( $hint ) ) {
				continue;
			}

			$type    = isset( $hint[ 'type' ] ) && is_string( $hint[ 'type' ] ) ? $hint[ 'type' ] : '';
			$for     = isset( $hint[ 'for' ] ) && is_string( $hint[ 'for' ] ) ? $hint[ 'for' ] : '';
			$indexes = isset( $hint[ 'indexes' ] ) && is_array( $hint[ 'indexes' ] ) ? $hint[ 'indexes' ] : array();

			if ( ! isset( $keywords[ $type ] ) || empty( $indexes ) ) {
				continue;
			}

			// Quote each index name (PRIMARY is a special name and stays bare).
			$names = array();

			foreach ( $indexes as $name ) {
				$name    = (string) $name;
				$names[] = ( 'PRIMARY' === $name )
					? 'PRIMARY'
					: $this->quote_identifier( $name );
			}

			// Assemble: KEYWORD [FOR SCOPE] (name, ...).
			$scope   = ( '' !== $for ) && isset( $scopes[ $for ] ) ? $scopes[ $for ] : '';
			$parts[] = $keywords[ $type ] . $scope . ' (' . implode( ', ', $names ) . ')';
		}

		// Bail if nothing rendered.
		if ( empty( $parts ) ) {
			return '';
		}

		// No leading space: this is its own clause slot, space-joined by the assembler.
		return implode( ' ', $parts );
	}

	/** Hydration *************************************************************/

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

		// Prime caches for declared relationships (quiet unless requested).
		$this->prime_relationship_caches();
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

		/*
		 * Default to count of item IDs.
		 *
		 * This is relevant for any kind of query. Either it is literal item IDs
		 * or it is the number of results returned by a 'count' and 'groupby'
		 * query.
		 */
		$retval = count( (array) $item_ids );

		/*
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

			/*
			 * The found-items count reuses the request clauses with a few overrides.
			 * parse_count() renders COUNT(DISTINCT primary) when DISTINCT is active, so
			 * a row-multiplying JOIN does not inflate the total; the standalone DISTINCT
			 * keyword is dropped (it belongs inside the COUNT, not before it).
			 */
			$r = $this->parse_args(
				array(
					'fields'   => $this->parse_count( true ),
					'limits'   => '',
					'orderby'  => '',
					'distinct' => '',
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

	/**
	 * Shape an item from the database into the type of object it always wanted
	 * to be when it grew up.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $item ID of item, or row from database.
	 * @return object Shaped item object.
	 */
	private function shape_item( $item = 0 ): object {

		/*
		 * Fetch the row when given an ID (int or string/UUID key); rows already
		 * arrive from the database as an object or array.
		 */
		if ( ! is_object( $item ) && ! is_array( $item ) ) {
			$item = $this->get_item( $item );
		}

		/*
		 * Decode JSON columns before any early-return or wrapping.
		 *
		 * The database returns raw rows as stdClass objects (via get_row()), so
		 * we must handle both array and object forms here. cast_json() is
		 * idempotent - calling it on an already-decoded array is a no-op.
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
		$shaped = ! empty( $item_shape )
			? $this->instantiate_class( $item_shape, '', $item )
			: null;
		$item   = ( null !== $shaped )
			? $shaped
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
	 * @return array<int|string,mixed>
	 */
	private function shape_items( $items = array(), $fields = array() ): array {

		// Maybe fallback to $query_vars.
		if ( empty( $fields ) ) {
			$fields = $this->get_query_var( 'fields' );
		}

		// Force to stdClass if querying for fields.
		$item_shape = ! empty( $fields ) ? 'stdClass' : $this->item_shape;
		$this->set_current( 'item_shape', $item_shape );

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
	 * @param  array<string,mixed>|object|scalar $item The item object or array.
	 * @return int|string
	 */
	private function shape_item_id( $item = 0 ): int|string {

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

		/*
		 * Return the validated item ID: an int/string passes through, another scalar
		 * is stringified, and anything else falls back to 0.
		 */
		$validated = $this->validate_item_field( $retval, $primary );

		if ( is_int( $validated ) || is_string( $validated ) ) {
			return $validated;
		}

		if ( is_scalar( $validated ) ) {
			return (string) $validated;
		}

		return 0;
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
	 * @return list<object>|array<string|int,object>
	 */
	private function get_item_fields( $items = array(), $fields = array() ): array {

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
	 * Get a single database row by any column and value, skipping cache.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses is_valid_column()
	 *
	 * @param string $column_name  Name of database column.
	 * @param mixed  $column_value Value to query for.
	 * @return object|false False if empty/error, Object if successful
	 */
	private function get_item_raw( $column_name = '', $column_value = '' ): object|false {

		/*
		 * Bail if value is non-scalar, boolean false, or empty string.
		 * Intentionally allows 0 and '0' - both are valid column values.
		 */
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
	 * Get a single database row by the primary column ID, possibly from cache.
	 *
	 * Accepts an integer, object, or array, and attempts to get the ID from it,
	 * then attempts to retrieve that item fresh from the database or cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|array<string,mixed>|object $item_id The ID of the item.
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

		/*
		 * Bail if value is non-scalar, boolean false, or empty string.
		 * Intentionally allows 0 and '0' - both are valid column values.
		 */
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

			// Cache the result - read path, do not bump last_changed.
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
			/** @var array<string,mixed>|object $reduce_target */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
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
	 * @param array<string,mixed> $data Item data.
	 * @return int|string|false New item ID if successful (the auto-increment value,
	 *                          or the supplied primary key for a string/UUID key),
	 *                          false if not.
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
				/** @var array<string,mixed> $primary_arr */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
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

		// Reduce (caps), let columns intercept generated values, then validate.
		$reduce = $this->reduce_item( 'insert', $save );
		$save   = $this->intercept_item( 'insert', $reduce );
		$save   = $this->validate_item( $save );

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

		/*
		 * Get the new item ID: the supplied primary key (e.g. a string/UUID) when
		 * one was given, otherwise the auto-increment value from the insert.
		 */
		$retval = ! empty( $save[ $primary ] )
			? $save[ $primary ]
			: $this->db()->get_insert_id();

		// Maybe save meta keys.
		if ( ! empty( $meta ) ) {
			$this->save_extra_item_meta( $retval, $meta );
		}

		/*
		 * Update item cache(s). A new row can become the first match for any
		 * value, so rotate every secondary lookup group.
		 */
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
	 * @param int|string          $item_id Item ID.
	 * @param array<string,mixed> $data Item data.
	 * @return int|string|false New item ID if successful (int auto-increment or a
	 *                          string/UUID key), false if not.
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

		// Let columns intercept copied values before overrides are restored.
		$save = $this->intercept_item( 'copy', $save );

		// Maybe merge data with original item.
		if ( ! empty( $data ) && is_array( $data ) ) {
			$save = array_merge( $save, $data );
		}

		/*
		 * Drop the copied primary key when the column auto-increments (the DB
		 * regenerates it - a supplied override is ignored, preserving long-standing
		 * behavior) OR when the caller supplied no replacement. A manual key (e.g. a
		 * string/UUID) cannot be auto-generated, so a caller-supplied one is kept.
		 */
		$primary_column = $this->get_column_by( array( 'name' => $primary ) );
		$auto_increment = ( $primary_column instanceof Column ) && $primary_column->is_auto_increment();

		if ( $auto_increment || empty( $data[ $primary ] ) ) {
			unset( $save[ $primary ] );
		}

		// Return result of add_item().
		return $this->add_item( $save );
	}

	/**
	 * Update an item in the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string          $item_id Item ID.
	 * @param array<string,mixed> $data Item data.
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
		/** @var array<string,string> $data_cast */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$data_cast = array_map( 'strval', array_filter( $data, 'is_scalar' ) );
		/** @var array<string,string> $item_cast */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
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
		$meta_saved = ! empty( $meta )
			? $this->save_extra_item_meta( $item_id, $meta )
			: false;

		// Bail if no columns to save - but report a successful meta-only save.
		if ( empty( $save ) ) {
			return $meta_saved;
		}

		// Reduce (caps), let columns intercept generated values, then validate.
		$reduce = $this->reduce_item( 'update', $save );
		$save   = $this->intercept_item( 'update', $reduce );
		$save   = $this->validate_item( $save );

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

		/*
		 * Rotate the secondary lookup groups for the columns that changed. The
		 * helper ignores any names that are not cache_key columns, so the written
		 * column names can be passed as-is. Lookups by unchanged columns stay
		 * warm and still resolve fresh objects through the by-id cache above.
		 */
		$this->update_secondary_last_changed_caches( array_keys( $save ) );

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
		 * allowed. Keep the original object for cache cleanup - reduce_item
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

		/*
		 * Clean caches on successful delete. The removed row's value-to-ID
		 * mappings are now gone, so rotate every secondary lookup group.
		 */
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
		 * @param int|string $item_id The ID of the item that was deleted.
		 * @param bool       $result  Whether the item was successfully deleted.
		 */
		if ( '' !== $action_name ) {
			do_action(
				$action_name,
				$item_id,
				(bool) $retval
			);
		}

		// Return.
		return (bool) $retval;
	}

	/**
	 * Delete a set of items, named by ID(s) or by a query-var filter.
	 *
	 * The plural companion to delete_item(): the input resolves to a list of
	 * primary IDs and each is removed through delete_item(), so per-item capability
	 * reduction, meta cleanup, cache invalidation, and the {item}_deleted action
	 * all still fire. The input may be:
	 *
	 *  - a single ID            - delete_items( 5 )
	 *  - a list of IDs          - delete_items( array( 5, 6, 7 ) )
	 *  - a query-var filter     - delete_items( array( 'status__in' => array( 'spam' ) ) )
	 *
	 * An empty input, or a filter that compiles to no WHERE, deletes nothing - the
	 * empty set never widens to "delete everything".
	 *
	 * @since 3.1.0
	 *
	 * @param int|string|array<int|string,mixed> $query_vars A single ID, a list of IDs, or a query-var filter array.
	 * @return int|false Number of items deleted, or false when there was nothing to delete.
	 */
	public function delete_items( $query_vars = array() ) {
		return ( new \BerlinDB\Database\Operations\Delete( $this ) )->delete( $query_vars );
	}

	/**
	 * Update a set of items, named by ID(s) or by a query-var filter.
	 *
	 * The write companion to delete_items(): the input resolves to a list of
	 * primary IDs and the same $data is written to each through update_item(), so
	 * per-item validation, capability reduction, meta handling, cache invalidation,
	 * and the transition actions all still fire. The input may be:
	 *
	 *  - a single ID         - update_items( 5, $data )
	 *  - a list of IDs       - update_items( array( 5, 6, 7 ), $data )
	 *  - a query-var filter  - update_items( array( 'status' => 'draft' ), $data )
	 *
	 * Empty $data, an empty input, or a filter that compiles to no WHERE updates
	 * nothing - the empty set never widens to "update everything".
	 *
	 * @since 3.1.0
	 *
	 * @param int|string|array<int|string,mixed> $query_vars A single ID, a list of IDs, or a query-var filter array.
	 * @param array<string,mixed>                $data       Column => value pairs to write to each matched item.
	 * @return int|false Number of items updated, or false when there was nothing to update.
	 */
	public function update_items( $query_vars = array(), $data = array() ) {
		return ( new \BerlinDB\Database\Operations\Update( $this ) )->update( $query_vars, $data );
	}

	/**
	 * Add a set of new items, one per data array.
	 *
	 * The create companion to delete_items() and update_items(): each element of
	 * $rows is one new item's data and is inserted through add_item(), so per-item
	 * default values, primary-key/UUID generation, sanitization, meta handling, and
	 * cache priming all still happen. Unlike the other two, the input is not a set
	 * selector - the rows do not exist yet - so it is always a list of data arrays:
	 *
	 *  - add_items( array( array( 'name' => 'A' ), array( 'name' => 'B' ) ) )
	 *
	 * Because the new IDs are the point of a batch insert, this returns them in
	 * input order rather than a count: each slot holds the new item ID, or false
	 * where that one insert failed. An empty input inserts nothing and returns array().
	 *
	 * Like its siblings this loops the per-item primitive rather than emitting a
	 * single multi-row INSERT, trading raw throughput for per-row correctness.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int,array<string,mixed>> $rows List of data arrays, one per new item.
	 * @return array<int,int|string|false> New item IDs in input order; false in any slot whose insert failed.
	 */
	public function add_items( $rows = array() ): array {
		return ( new \BerlinDB\Database\Operations\Add( $this ) )->add( (array) $rows );
	}

	/**
	 * Validate an item before it is updated in or added to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $item The item object or array.
	 * @return array<string,mixed> Validated item array.
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
	 * Let each column intercept its value for a save operation.
	 *
	 * Loops every column (not just the keys present in $item, so it can inject
	 * values the caller omitted - e.g. created/modified) and delegates to
	 * Column::intercept().
	 *
	 * Sits beside reduce_item() and validate_item() in the save pipeline:
	 * reduce (caps) -> intercept (generated values) -> validate (sanitize).
	 *
	 * @since 3.1.0
	 *
	 * @param string               $method One of insert|update|select|delete|copy.
	 * @param array<string,mixed> $item   Item field key/value pairs.
	 * @return array<string,mixed>
	 */
	private function intercept_item( $method = 'insert', $item = array() ) {

		// Bail if item is empty or not an array.
		if ( empty( $item ) || ! is_array( $item ) ) {
			return $item;
		}

		// Let each column intercept its value.
		foreach ( $this->get_columns() as $column ) {
			$name    = $column->name;
			$current = $item[ $name ] ?? null;
			$new     = $column->intercept( $method, $current );

			// The column's generated unset sentinel removes the column entirely.
			if ( $column->is_unset_sentinel( $new ) ) {
				unset( $item[ $name ] );
				continue;
			}

			// Only write back when interception changed the value.
			if ( $new !== $current ) {
				$item[ $name ] = $new;
			}
		}

		// Return the intercepted item.
		return $item;
	}

	/**
	 * Reduce an item down to the keys and values the current user has the
	 * appropriate capabilities to select|insert|update|delete.
	 *
	 * Always returns an array. Columns not present in the schema are also
	 * removed - no caps entry resolves to an empty capability string, which
	 * fails the current_user_can check.
	 *
	 * @since 1.0.0
	 *
	 * @param string                      $method select|insert|update|delete.
	 * @param object|array<string,mixed> $item   Object or array of keys/values to reduce.
	 *
	 * @return array<string,mixed> Item with capability-restricted keys removed.
	 */
	private function reduce_item( $method = 'update', $item = array() ): array {

		// Bail if item is empty.
		if ( empty( $item ) ) {
			return array();
		}

		// Normalize to an array for uniform processing.
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
		 * @param array<string,mixed> $args Default empty array. Parsed & used to filter columns.
	 * @return array<string,mixed>
	 */
	private function default_item( $args = array() ): array {

		// Parse arguments.
		$r = $this->parse_args( $args );

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
	 * @param array<string,mixed> $new_data New item data.
	 * @param array<string,mixed> $old_data Old item data.
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
			 * @param mixed      $old_value The value being transitioned FROM.
			 * @param mixed      $new_value The value being transitioned TO.
			 * @param int|string $item_id   The ID of the item that is transitioning.
			 */
			if ( '' !== $key_action ) {
				do_action( $key_action, $old_value, $new_value, $item_id );
			}
		}
	}

	/** Meta ******************************************************************/

	/**
	 * Resolve this object's meta store, when it has one (memoized per instance).
	 *
	 * The *_item_meta() methods are routers: when this object's relationship
	 * named 'meta' resolves to a remote implementing Interfaces\MetaStore, meta
	 * operations delegate to that store (the custom sibling-table path);
	 * otherwise they fall through to the legacy WordPress metadata API. Both
	 * checks are required - the accessor name picks WHICH relationship is the
	 * canonical meta relationship; the interface proves the remote can actually
	 * perform meta operations.
	 *
	 * The result is memoized on this instance ($meta_store, with `false` as the
	 * "not resolved yet" sentinel since `null` validly means "no store") and reused
	 * by every subsequent *_item_meta() call. Why: resolving the store
	 * instantiates the remote meta Query - which, for the Meta preset, also builds
	 * its primary Query and schema (~0.5ms) - so a loop of per-item meta operations
	 * on the same Query would otherwise pay that cost on every call.
	 *
	 * Reuse is safe: the store is addressed by object ID *per method call* (nothing
	 * is baked into the instance for a specific object), each store operation runs
	 * its own lifecycle (per-run ephemeral state is reset by run()), and its reads
	 * go through the standard query cache with last_changed invalidation - so a
	 * memoized store never serves stale meta. (The test suite already reuses a
	 * single store instance across many operations, exercising this.) The cache is
	 * not invalidated during the instance's life because a Query's declared
	 * relationships are fixed at construction.
	 *
	 * @since 3.1.0
	 *
	 * @return \BerlinDB\Database\Interfaces\MetaStore|null The store, or null.
	 */
	private function get_meta_store(): ?\BerlinDB\Database\Interfaces\MetaStore {

		/*
		 * Return the memoized result. `false` means "not resolved yet"; `null` is a
		 * valid resolution (this object has no meta store).
		 */
		if ( false !== $this->meta_store ) {
			return $this->meta_store;
		}

		// Resolve the remote behind the relationship named 'meta', if declared.
		$relationship = $this->get_relationship( 'meta' );
		$remote       = ( $relationship instanceof Relationship )
			? $this->resolve_remote_query( $relationship )
			: null;

		// Cache the store (it must prove capability via the contract), or null.
		$this->meta_store = ( $remote instanceof \BerlinDB\Database\Interfaces\MetaStore )
			? $remote
			: null;

		return $this->meta_store;
	}

	/**
	 * Add meta data to an item.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
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

		// Bail without a usable item ID or a meta key.
		if ( empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Route to the meta store when one is declared (accepts a string/UUID ID).
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			return $store->add_meta( $item_id, $meta_key, $meta_value, (bool) $unique );
		}

		// The legacy WordPress metadata fallback is integer-keyed ({type}meta).
		if ( ! is_int( $item_id ) ) {
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
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
	 *
	 * @param int|string $item_id Item ID.
	 * @param string     $meta_key Meta key.
	 * @param bool       $single Whether to return a single value.
	 * @return mixed Single metadata value, or array of values
	 */
	protected function get_item_meta( $item_id = 0, $meta_key = '', $single = false ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		/*
		 * Bail without a usable item ID. An empty meta key IS allowed: it reads ALL
		 * meta for the item, a shape both the meta store and get_metadata() support.
		 */
		if ( empty( $item_id ) ) {
			return false;
		}

		// Route to the meta store when one is declared (accepts a string/UUID ID).
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			return $store->get_meta( $item_id, $meta_key, (bool) $single );
		}

		// The legacy WordPress metadata fallback is integer-keyed ({type}meta).
		if ( ! is_int( $item_id ) ) {
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
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
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

		// Bail without a usable item ID or a meta key.
		if ( empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Route to the meta store when one is declared (accepts a string/UUID ID).
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			return $store->update_meta( $item_id, $meta_key, $meta_value, $prev_value );
		}

		// The legacy WordPress metadata fallback is integer-keyed ({type}meta).
		if ( ! is_int( $item_id ) ) {
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
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
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

		// A meta key is always required.
		if ( empty( $meta_key ) ) {
			return false;
		}

		/*
		 * A global purge ($delete_all) deletes the key across every object, so it
		 * ignores the item ID - the store and delete_metadata() both do. Otherwise
		 * require a valid integer ID (metadata requires integer IDs).
		 */
		if ( empty( $delete_all ) && empty( $item_id ) ) {
			return false;
		}

		// Route to the meta store when one is declared (accepts a string/UUID ID).
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			return $store->delete_meta( $item_id, $meta_key, $meta_value, (bool) $delete_all );
		}

		/*
		 * The legacy WordPress metadata fallback is integer-keyed ({type}meta); a
		 * global purge ignores the ID, so a non-int ID only blocks per-object deletes.
		 */
		if ( empty( $delete_all ) && ! is_int( $item_id ) ) {
			return false;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		/*
		 * Return results of deleting meta data. The id is an int on the per-object
		 * path (guarded above) and ignored by delete_metadata() when $delete_all.
		 */
		return delete_metadata( $meta_type, (int) $item_id, $meta_key, $meta_value, $delete_all );
	}

	/**
	 * Get registered meta data keys.
	 *
	 * @since 1.0.0
	 *
	 * @param string $object_subtype The sub-type of meta keys.
	 *
	 * @return array<string,mixed>
	 */
	private function get_registered_meta_keys( $object_subtype = '' ): array {

		// Get the object type.
		$object_type = $this->get_meta_type();

		// Return the keys.
		return get_registered_meta_keys( $object_type, $object_subtype );
	}

	/**
	 * Maybe update meta values on item update/save.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Routes to the meta store when one is declared (see
	 *              get_meta_store()), and returns whether any write succeeded.
	 *
	 * @param int|string           $item_id Item ID.
	 * @param array<string,mixed> $meta Array of meta key/value pairs.
	 * @return bool True when any per-key write (update or delete) succeeded.
	 */
	private function save_extra_item_meta( $item_id = 0, $meta = array() ): bool {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if there is no bulk meta to save.
		if ( empty( $item_id ) || empty( $meta ) ) {
			return false;
		}

		/*
		 * The legacy WordPress path applies two gates: a registered {type}meta
		 * table must exist, and only register_meta()'d keys are saved. When a
		 * meta store is declared, both are intentionally skipped - the WP
		 * registry is a WP-core-types concept, and for a custom sibling table
		 * the declared 'meta' relationship IS the registration.
		 */
		$store = $this->get_meta_store();
		if ( null === $store ) {

			// Bail if no meta table exists.
			if ( false === $this->get_meta_table_name() ) {
				return false;
			}

			// Only save registered keys.
			$keys = $this->get_registered_meta_keys();
			$meta = array_intersect_key( $meta, $keys );

			// Bail if no registered meta keys.
			if ( empty( $meta ) ) {
				return false;
			}
		}

		// Default return value.
		$retval = false;

		/*
		 * Save or delete meta data - directly on the store when one is declared
		 * (resolved once above), else through the legacy WordPress helpers.
		 */
		foreach ( $meta as $key => $value ) {

			if ( null !== $store ) {
				$saved = ! empty( $value )
					? $store->update_meta( $item_id, $key, $value )
					: $store->delete_meta( $item_id, $key );
			} else {
				$saved = ! empty( $value )
					? $this->update_item_meta( $item_id, $key, $value )
					: $this->delete_item_meta( $item_id, $key );
			}

			if ( ! empty( $saved ) ) {
				$retval = true;
			}
		}

		return $retval;
	}

	/**
	 * Delete all meta data for an item.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
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

		// Route to the meta store when one is declared.
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			$store->delete_all_meta( $item_id );

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
	 * @return string|false Table name if exists, false if not.
	 */
	private function get_meta_table_name(): string|false {

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
	private function get_cache_key( $group = '' ): string {

		// Default slice.
		$slice = array();

		// Slice query_vars by query_var_defaults keys, ordered by defaults.
		foreach ( $this->query_var_defaults as $key => $_default ) {

			// Skip results-invariant vars (a hint or priming flag returns the same rows).
			if ( in_array( $key, self::RESULTS_INVARIANT_VARS, true ) ) {
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
	private function get_cache_group( $group = '' ): string {

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
	 * @return array<string,string>
	 */
	private function get_cache_groups(): array {

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
	private function get_cache_group_for_column( $column_name = '' ): string {

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
	private function prime_item_caches( $item_ids = array(), $force = false ): bool {

		// Bail if no items to cache.
		if ( empty( $item_ids ) ) {
			return false;
		}

		// Accepts single values, so cast to array.
		$item_ids = (array) $item_ids;

		/*
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

				// Update item cache(s) - read path, do not bump last_changed.
				if ( ! empty( $results ) && is_array( $results ) ) {
					/** @var list<object> $results */
					$this->update_item_cache( $results, false );
				}
			}
		}

		/*
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
	 * Prime this query's item caches for a known set of primary IDs.
	 *
	 * Public entry point so other Query instances (e.g. relationship cache
	 * priming) can warm this query's item cache in a single bulk read. Forces
	 * the prime regardless of the update_item_cache query var, because the
	 * calling query is the one expressing the intent.
	 *
	 * @since 3.1.0
	 *
	 * @param list<int|string> $ids Primary-key values to prime.
	 * @return void
	 */
	protected function prime_items( $ids = array() ) {
		$this->prime_item_caches( $ids, true );
	}

	/**
	 * Prime caches for related items referenced by the current result set.
	 *
	 * For each named relationship, warms caches so later access avoids N+1
	 * lookups: belongs_to warms the remote item caches for the parents this
	 * result set points at; has_many warms each item's child collection.
	 *
	 * Quiet by default: runs only when 'with' names one or more relationships.
	 * See berlindb/core #193.
	 *
	 * @since 3.1.0
	 */
	private function prime_relationship_caches(): void {

		// Relationship names requested for priming.
		$with = $this->get_query_var( 'with' );

		// Bail unless a non-empty list of names was requested.
		if ( ! is_array( $with ) || empty( $with ) ) {
			return;
		}

		// Bail unless we have an array of shaped items to read foreign keys from.
		if ( empty( $this->items ) || ! is_array( $this->items ) ) {
			return;
		}

		// Capture locally so the array type survives the method calls below.
		$items = $this->items;

		// Prime each named belongs_to relationship (warm the parents' caches).
		foreach ( $this->get_belongs_to_relationships() as $relationship ) {
			if ( in_array( $relationship->name, $with, true ) ) {
				$this->prime_belongs_to_relationship( $relationship, $items );
			}
		}

		// Prime each named has_many relationship (warm the child collections).
		foreach ( $this->get_has_many_relationships() as $relationship ) {
			if ( in_array( $relationship->name, $with, true ) ) {
				$this->prime_has_many_relationship( $relationship, $items );
			}
		}
	}

	/**
	 * Warm the remote query's item cache for a single belongs_to relationship.
	 *
	 * @since 3.1.0
	 *
	 * @param Relationship                 $relationship The relationship to prime.
	 * @param array<int|string,mixed>     $items        Shaped result items to read foreign keys from.
	 */
	private function prime_belongs_to_relationship( Relationship $relationship, array $items ): void {

		$columns    = $relationship->columns;
		$references = $relationship->references;

		// Only single-column foreign keys are primed for now.
		if ( ( count( $columns ) !== 1 ) || ( count( $references ) !== 1 ) ) {
			return;
		}

		// Resolve the remote query instance (guarded; null when unresolvable).
		$remote = $this->resolve_remote_query( $relationship );

		/*
		 * Priming warms the remote primary-key cache, so the relationship must
		 * resolve to a sibling Query that references the remote primary column.
		 */
		if ( ( null === $remote ) || ( $references[0] !== $remote->get_primary_column_name() ) ) {
			return;
		}

		// Warm the remote primary-key cache from this side's foreign-key values.
		$values = $this->get_local_relationship_key_values( $items, $columns[0] );

		if ( ! empty( $values ) ) {
			$remote->prime_items( $values );
		}
	}

	/**
	 * Warm the remote child collections for a single has_many relationship.
	 *
	 * Collects this side's key values (the values the remote foreign key points
	 * at) and asks the remote query to prime every matching child collection in
	 * one bulk read.
	 *
	 * @since 3.1.0
	 *
	 * @param Relationship             $relationship The relationship to prime.
	 * @param array<int|string,mixed> $items        Shaped result items to read keys from.
	 */
	private function prime_has_many_relationship( Relationship $relationship, array $items ): void {

		$columns    = $relationship->columns;
		$references = $relationship->references;

		// Only single-column relationships are primed for now.
		if ( ( count( $columns ) !== 1 ) || ( count( $references ) !== 1 ) ) {
			return;
		}

		// Resolve the remote query instance (guarded; null when unresolvable).
		$remote = $this->resolve_remote_query( $relationship );

		if ( null === $remote ) {
			return;
		}

		// Warm the child collections from this side's key values.
		$values = $this->get_local_relationship_key_values( $items, $columns[0] );

		if ( ! empty( $values ) ) {
			$remote->prime_has_many( $references[0], $values );
		}
	}

	/**
	 * Return the distinct, non-empty local relationship-key values from items.
	 *
	 * "local" is the relationship side this query holds (vs the remote/related
	 * Query). Shared by the single-column relationship priming paths: reads $column
	 * off each item, skips items that do not expose it, drops empty relationship
	 * keys (see is_empty_relationship_key()), and de-duplicates by string value.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $items  Shaped result items to read keys from.
	 * @param string                  $column The local relationship key column to read.
	 * @return list<mixed> The distinct, non-empty local key values.
	 */
	private function get_local_relationship_key_values( array $items, string $column ): array {
		$values = array();

		foreach ( $items as $item ) {

			// Skip items that do not expose the local column.
			if ( ! is_object( $item ) || ! isset( $item->{$column} ) ) {
				continue;
			}

			$value = $item->{$column};

			// Skip empty keys (no relation), de-duplicate the rest by string value.
			if ( ! $this->is_empty_relationship_key( $value ) ) {
				$values[ (string) $value ] = $value;
			}
		}

		return array_values( $values );
	}

	/**
	 * Prime this query's native result cache for a set of foreign-key values.
	 *
	 * Public entry point used by has_many relationship priming. Performs one
	 * bulk read of every row whose $fk_column is in $values, warms the by-id
	 * item cache for those rows, then warms this query's own result-list cache
	 * for each per-value query - "{$fk_column} => value" - including empty
	 * results, so childless parents are a cache hit too.
	 *
	 * Because the result cache is reused (rather than a bespoke collection
	 * cache), a later get_related() / query() for one value resolves natively
	 * and inherits the standard last_changed invalidation.
	 *
	 * @since 3.1.0
	 *
	 * @param string                  $fk_column Foreign-key column on this table.
	 * @param list<int|string>|string $values    Foreign-key values to prime.
	 * @return void
	 */
	protected function prime_has_many( $fk_column = '', $values = array() ) {

		// Bail without a valid column or any values.
		if ( ! $this->is_valid_column( $fk_column ) || empty( $values ) ) {
			return;
		}

		// De-duplicate the values.
		$values = array_values( array_unique( (array) $values ) );

		// Build the escaped IN() clause for the foreign-key column.
		$in = $this->get_in_sql( $fk_column, $values );

		if ( '' === $in ) {
			return;
		}

		// One bulk read of every related row.
		$table   = $this->get_table_name();
		$results = $this->db()->get_results( "SELECT * FROM {$table} WHERE {$fk_column} IN {$in}" );

		// Normalize to an array of rows.
		$rows = ( ! empty( $results ) && is_array( $results ) )
			? $results
			: array();

		// Warm the by-id item cache for every related row.
		if ( ! empty( $rows ) ) {
			/** @var list<object> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$this->update_item_cache( $rows, false );
		}

		// Group related primary IDs by foreign-key value.
		$primary = $this->get_primary_column_name();
		$grouped = array();

		foreach ( $rows as $row ) {
			if ( is_object( $row ) && isset( $row->{$fk_column}, $row->{$primary} ) ) {
				$grouped[ (string) $row->{$fk_column} ][] = $row->{$primary};
			}
		}

		/*
		 * Warm each value's native result cache - including empties. 'number' => 0
		 * (no limit) must match get_related()'s has_many query exactly, so the
		 * primed key equals the key that lookup computes (the full child set).
		 */
		foreach ( $values as $value ) {
			$ids = $grouped[ (string) $value ] ?? array();
			$this->prime_query(
				array(
					$fk_column => $value,
					'number'   => 0,
				),
				$ids
			);
		}
	}

	/**
	 * Warm this query's result-list cache for a set of query vars.
	 *
	 * Runs the same parse + cache-key path as query() so the cached entry is
	 * keyed identically to a real query() call - but skips the database read,
	 * storing the supplied item IDs instead. A later query() with the same vars
	 * is then a cache hit.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars Query vars to cache under.
	 * @param list<int|string>     $item_ids   Item IDs the query resolves to.
	 * @return void
	 */
	protected function prime_query( $query_vars = array(), $item_ids = array() ) {
		$this->run(
			function () use ( $query_vars, $item_ids ) {

				// Parse vars exactly as query() does, so the cache key matches.
				$this->parse_query( $query_vars );

				// Respect the per-query caching flag.
				if ( true !== (bool) $this->get_query_var( 'cache_results' ) ) {
					return;
				}

				// Store the known IDs under the same key query() would compute.
				$ids = array_values( $item_ids );

				$this->cache_set(
					$this->get_cache_key(),
					array(
						'item_ids'    => $ids,
						'found_items' => count( $ids ),
					),
					$this->cache_group
				);
			}
		);
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
	private function clean_item_cache( $items = array() ): bool {

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
	 * by get_last_changed_cache() to lazily initialize a group.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Cache group. Defaults to $this->cache_group.
	 * @return string The new last_changed value.
	 */
	private function set_last_changed( $group = '' ): string {
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
	private function update_primary_last_changed_cache(): string {
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
	private function get_last_changed_cache( $group = '' ): string {

		// Get the last changed cache value.
		$last_changed = $this->cache_get( 'last_changed', $group );

		// Maybe initialize the last changed value.
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
	private function get_non_cached_ids( $item_ids = array(), $group = '' ): array {

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

		// Bail if no cache key. Allow 0 and '0' - both are valid cache keys.
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

		/*
		 * Bail if no cache key. Return false (not null) so callers using
		 * strict false === checks correctly detect a cache miss.
		 */
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

		// Bail if no cache key. Allow 0 and '0' - both are valid cache keys.
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

		// Bail if no cache key. Allow 0 and '0' - both are valid cache keys.
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
	 * @param array<string,mixed> $item The item data.
	 * @return array<string,mixed>
	 */
	protected function filter_item( $item = array() ) {

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
		 * @param array<string,mixed>     $item  The item as an array.
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
	protected function filter_query_var_parsers( $parsers = array() ) {

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
	protected function filter_items( $items = array() ) {

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
	protected function filter_found_items_query( $sql = '' ) {

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
	 * @param array<string,mixed> $clauses All of the SQL query clauses.
	 * @return array<string,mixed>
	 */
	protected function filter_query_clauses( $clauses = array() ) {

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
		 * @param array<string,mixed>     $clauses An array of query clauses.
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
