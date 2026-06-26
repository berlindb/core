<?php
/**
 * Base Parser Class.
 *
 * @package     Database
 * @subpackage  Parsers
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Parsers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for all query var parsers.
 *
 * Owns the Traits\Parser use so that concrete parsers only need to extend
 * this class. Traits\Parser provides a Column-aware default implementation of
 * get_sql_for_clause(); concrete parsers override it only when they require
 * specialized JOIN logic, type casting, or column handling.
 *
 * @since 3.0.0
 *
 * @property-read string $name
 * @property-read string|null $query_var
 * @property-read mixed $default
 * @property-read array<string,bool> $column_filter
 * @property-read string $column_suffix
 */
abstract class Base {

	use \BerlinDB\Database\Traits\Parser;

	/**
	 * Internal identifier for this parser.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = '';

	/**
	 * Top-level query var key this parser consumes, or null when the parser
	 * operates directly on per-column query vars (e.g. By).
	 *
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = null;

	/**
	 * Column filter passed to get_column_names() to select relevant columns.
	 * An empty array means all columns are considered.
	 *
	 * @since 3.0.0
	 * @var array<string,bool>
	 */
	protected $column_filter = array();

	/**
	 * Suffix appended to each matching column name to form the per-column
	 * query var key (e.g. '_search', '__in').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '';

	/**
	 * Default value for the query var. Null defers to
	 * Query::$query_var_default_value.
	 *
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = null;

	/**
	 * Whether this parser contributes ORDER BY SQL via get_orderby_sql().
	 * Parsers that override get_orderby_sql() should set this to true so
	 * Query::get_parsers( array( 'sortable' => true ) ) can find them
	 * without iterating every registered parser.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $sortable = false;

	/** Methods ***************************************************************/

	/**
	 * Return the top-level query var key this parser consumes.
	 *
	 * Returns null for parsers that operate on per-column query vars (e.g. By).
	 *
	 * @since 3.0.0
	 * @api
	 *
	 * @return string|null
	 */
	public function get_query_var() {
		return $this->query_var;
	}

	/**
	 * Get every top-level query var key this parser claims.
	 *
	 * The container var (when the parser uses one, e.g. 'compare_query') plus the
	 * per-column shorthand keys ({column}{column_suffix}, e.g. 'status__in' or
	 * 'date_created_query') for each column matching this parser's column_filter.
	 *
	 * This is the single source of truth for "which query vars route to this
	 * parser": Query consumes it when building its query var defaults, and any
	 * introspection (REST, validation, docs) can ask the same question without
	 * reassembling the rule from the raw descriptor properties.
	 *
	 * Reads $this->caller for the schema's columns, so the parser must have been
	 * constructed with its Query (descriptors are now born caller-bearing).
	 *
	 * @since 3.1.0
	 * @api
	 *
	 * @return list<string> The query var keys.
	 */
	public function get_query_var_keys(): array {

		// Default return value.
		$retval = array();

		/*
		 * The container var, when this parser uses one. Via the accessor so a
		 * subclass that overrides get_query_var() is honored (Magic __get prefers
		 * the getter; reading $this->query_var raw would bypass that override).
		 */
		$query_var = $this->get_query_var();

		if ( ! empty( $query_var ) ) {
			$retval[] = $query_var;
		}

		/*
		 * Per-column shorthand, but only for parsers that actually have one: a
		 * non-empty suffix to append, OR no container var at all (a per-column-
		 * only parser like By, whose shorthand IS the bare column name). A
		 * container-only parser that inherits the empty Base suffix (e.g.
		 * Relationship) claims no per-column keys - it would otherwise grab every
		 * column name, since an empty column_filter matches all columns.
		 */
		if ( ( '' !== $this->column_suffix ) || empty( $query_var ) ) {
			foreach ( (array) $this->caller?->get_column_names( $this->column_filter ) as $column ) {
				$retval[] = "{$column}{$this->column_suffix}";
			}
		}

		// Return the query var keys.
		return $retval;
	}

	/**
	 * Get the default value to register for this parser's query vars.
	 *
	 * The parser's own $default when it declares one, otherwise the caller's
	 * query-var default sentinel (the "unset" marker the Query uses to tell a
	 * deliberately-set var from an absent one). Like get_query_var_keys(), this
	 * lets Query ask the parser rather than owning the null-fallback itself.
	 *
	 * @since 3.1.0
	 *
	 * @return mixed
	 */
	public function get_query_var_default() {
		return ( null === $this->default )
			? $this->caller?->get_query_var_default_value()
			: $this->default;
	}

	/**
	 * Recover a column name from a per-column query var key.
	 *
	 * Strips this parser's {@see $column_suffix} from the end of the key - e.g.
	 * 'status__in' => 'status', 'date_created_query' => 'date_created' - so the
	 * several per-column parsers (In, NotIn, Search, Date) don't each re-implement
	 * the same string surgery. Returns false when the key does not carry this
	 * parser's suffix, so callers can skip keys that aren't theirs.
	 *
	 * Validating the resulting name against the schema is left to the caller,
	 * because each parser scopes columns differently: In/NotIn trust their
	 * first_keys, Search intersects search_columns, and Date validates via
	 * get_column_sql(). This helper only does the (shared) suffix removal.
	 *
	 * @since 3.1.0
	 *
	 * @param string $key The per-column query var key.
	 * @return string|false The column name with the suffix removed, or false.
	 */
	protected function strip_column_suffix( $key ) {

		// Bail on non-strings.
		if ( ! is_string( $key ) || ( '' === $key ) ) {
			return false;
		}

		$suffix = $this->column_suffix;

		// No suffix to strip (e.g. By): the key is the column name as-is.
		if ( '' === $suffix ) {
			return $key;
		}

		// Only keys carrying this parser's suffix belong to it.
		if ( ! str_ends_with( $key, $suffix ) ) {
			return false;
		}

		$name = substr( $key, 0, -strlen( $suffix ) );

		return ( '' !== $name )
			? $name
			: false;
	}

	/**
	 * Build this parser's first-order keys from its column scope.
	 *
	 * The per-column parsers (By, In, NotIn, Search) all derive their first-order
	 * keys identically: every column matching {@see $column_filter}, suffixed with
	 * {@see $column_suffix} - e.g. 'status__in', 'name_search', or the bare column
	 * name for By. This shared default replaces those near-identical overrides; it
	 * is the inverse of {@see strip_column_suffix()}.
	 *
	 * Parsers whose clauses are not per-column (Compare, Date, Meta) override this
	 * with a static key list. An explicitly supplied $first_keys is honored as-is,
	 * preserving the engine contract from Traits\Parser.
	 *
	 * @since 3.1.0
	 *
	 * @param list<string> $first_keys Optional. Explicit first-order keys to use as-is.
	 * @return list<string> The first-order keys.
	 */
	protected function get_first_keys( $first_keys = array() ) {

		// Honor an explicitly supplied set.
		if ( ! empty( $first_keys ) && is_array( $first_keys ) ) {
			return $first_keys;
		}

		// Default values.
		$keys    = array();
		$columns = (array) $this->caller?->get_columns( $this->column_filter, 'and', 'name' );

		// Each column in scope, plus this parser's suffix.
		foreach ( $columns as $column ) {
			if ( is_string( $column ) ) {
				$keys[] = "{$column}{$this->column_suffix}";
			}
		}

		return $keys;
	}

	/**
	 * Get the default operator class list.
	 *
	 * This is filterable so individual parser families can register custom
	 * operators without replacing the shared parser contract.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	protected function get_operator_classes() {

		// Default set of operator classes.
		$operators = array(
			'BerlinDB\\Database\\Operators\\Between',
			'BerlinDB\\Database\\Operators\\Equal',
			'BerlinDB\\Database\\Operators\\Exists',
			'BerlinDB\\Database\\Operators\\GreaterThan',
			'BerlinDB\\Database\\Operators\\GreaterThanOrEqual',
			'BerlinDB\\Database\\Operators\\In',
			'BerlinDB\\Database\\Operators\\IsNotNull',
			'BerlinDB\\Database\\Operators\\IsNull',
			'BerlinDB\\Database\\Operators\\LessThan',
			'BerlinDB\\Database\\Operators\\LessThanOrEqual',
			'BerlinDB\\Database\\Operators\\Like',
			'BerlinDB\\Database\\Operators\\NotBetween',
			'BerlinDB\\Database\\Operators\\NotEqual',
			'BerlinDB\\Database\\Operators\\NotExists',
			'BerlinDB\\Database\\Operators\\NotIn',
			'BerlinDB\\Database\\Operators\\NotLike',
			'BerlinDB\\Database\\Operators\\NotRegexp',
			'BerlinDB\\Database\\Operators\\Regexp',
			'BerlinDB\\Database\\Operators\\Rlike',
		);

		/**
		 * Filter the default operator class list.
		 *
		 * @since 3.0.0
		 * @param string[] $operators Array of fully-qualified Operator class names.
		 * @param Base     $parser    Current Parser instance.
		 */
		return (array) apply_filters_ref_array(
			'berlindb_database_operator_classes',
			array(
				$operators,
				&$this,
			)
		);
	}

	/**
	 * Populate $this->operators with one shared instance per Operator class.
	 *
	 * Defined here on the concrete base class (not in Traits\Parser) so that
	 * the static cache is scoped to this class definition and shared across
	 * all subclasses, giving a true per-process singleton. A static variable
	 * inside a trait method gets one copy per using class, which would cause
	 * all 17 operators to be re-instantiated for each of the 7 parser classes.
	 *
	 * @since 3.0.0
	 */
	protected function set_operators(): void {
		static $instances = array();

		$classes = $this->get_operator_classes();
		$key     = md5( maybe_serialize( $classes ) );

		if ( ! isset( $instances[ $key ] ) ) {
			$instances[ $key ] = array();

			foreach ( $classes as $class ) {
				$operator = $this->instantiate_class( $class );

				if ( $operator instanceof \BerlinDB\Database\Operators\Base ) {
					$instances[ $key ][] = $operator;
				}
			}
		}

		// Set operators.
		$this->operators = $instances[ $key ];
	}

	/**
	 * Read a clause group's boolean relation: 'OR' when set, else 'AND' (default).
	 *
	 * `relation` is a group directive (not a clause); callers unset it after
	 * reading. Shared by the relationship and meta clause-group builders.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $clauses A clause group.
	 * @return string 'OR' or 'AND'.
	 */
	protected function get_clause_relation( array $clauses ): string {
		return ( isset( $clauses[ 'relation' ] ) && is_string( $clauses[ 'relation' ] ) && ( 'OR' === strtoupper( $clauses[ 'relation' ] ) ) )
			? 'OR'
			: 'AND';
	}
}
