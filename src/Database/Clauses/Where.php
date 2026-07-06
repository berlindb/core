<?php
/**
 * Where Clause.
 *
 * @package     Database
 * @subpackage  Clauses
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Clauses;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Combines per-parser WHERE fragments into the final WHERE clause list.
 *
 * This is a CLAUSE, not a parser. A parser turns comparisons (key/value/compare)
 * into SQL and emits its own independent fragment; this arranges the fragments
 * other parsers already produced, per an optional 'criteria' boolean tree. Its
 * leaves are parser NAMES pointing at those fragments, not comparisons, so it
 * builds BooleanGroups (clause assembly) rather than parsing query vars.
 *
 * Without a tree it is the historical behavior - every parser fragment AND-ed
 * together. With one, the named parser fragments combine per the tree (AND/OR,
 * nestable), and any active parser the tree did NOT name is AND-ed onto the result
 * (additive - you only restructure what you name). A leaf is a parser name (the
 * public 'columns' aliases the internal 'by' parser); a bare string is a leaf, a
 * nested array is a subgroup.
 *
 * Fails closed ('1 = 0') on a malformed tree, an unknown parser name, or a
 * JOIN-emitting parser placed under an OR - whose JOIN pre-filters rows, so the OR
 * could not widen as written. It never silently widens to all rows. Leaves are
 * whole parser fragments (buckets), NOT individual comparisons: cross-parser OR
 * like status='x' OR meta.rating=5 is expressible, but OR-ing two columns of the
 * same parser is the within-parser relation's job (e.g. compare_query).
 *
 * There is no JOIN sibling: JOINs cannot be AND/OR-ed (a JOIN pre-filters rows),
 * so they are emitted unconditionally and merely de-duplicated, never combined.
 * The WHERE is the only clause with a boolean tree, which is why this stands alone.
 *
 * @since 3.1.0
 * @internal Query collaborator; built from the per-parser fragments by Query.
 */
class Where {

	/**
	 * Public 'criteria' leaf names that alias an internal parser name.
	 *
	 * Most parsers are referenced by their own name (meta, date, compare,
	 * relation, search, in, not_in); 'columns' is the friendly public name for the
	 * 'by' parser - a query's direct column conditions (e.g. status => 'active').
	 *
	 * @since 3.1.0
	 * @var array<string,string>
	 */
	private const ALIASES = array(
		'columns' => 'by',
	);

	/**
	 * The 'criteria' boolean tree, or empty/null when none was provided.
	 *
	 * @since 3.1.0
	 * @var mixed
	 */
	private $tree = null;

	/**
	 * Per-parser JOIN fragments, keyed by parser name.
	 *
	 * @since 3.1.0
	 * @var array<string,string>
	 */
	private $join = array();

	/**
	 * Per-parser WHERE fragments, keyed by parser name.
	 *
	 * @since 3.1.0
	 * @var array<string,string>
	 */
	private $where = array();

	/**
	 * Valid parser names a leaf may reference (active or not).
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private $parsers = array();

	/**
	 * Misconfiguration reasons recorded during the walk, for the caller to log.
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private $warnings = array();

	/**
	 * Build the clause from a key-value argument array.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args See init().
	 */
	public function __construct( array $args = array() ) {
		if ( ! empty( $args ) ) {
			$this->init( $args );
		}
	}

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type mixed                $tree    The 'criteria' value (a boolean tree, or empty for none).
	 *     @type array<string,string> $join    Per-parser JOIN fragments, keyed by parser name.
	 *     @type array<string,string> $where   Per-parser WHERE fragments, keyed by parser name.
	 *     @type list<string>         $parsers Valid parser names a leaf may reference.
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$this->tree    = $args[ 'tree' ] ?? null;
		$this->join    = $this->string_map( $args[ 'join' ] ?? array() );
		$this->where   = $this->string_map( $args[ 'where' ] ?? array() );
		$this->parsers = $this->string_list( $args[ 'parsers' ] ?? array() );
	}

	/**
	 * Combine the per-parser WHERE fragments into the final WHERE list.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string> The WHERE fragments to AND together, or array( '1 = 0' )
	 *                      when the tree is malformed or unsafe (fail closed).
	 */
	public function get_clauses(): array {

		// Absent (null, or the empty-array default): historical AND of every fragment.
		if ( ( null === $this->tree ) || ( array() === $this->tree ) ) {
			return array_values( $this->where );
		}

		/*
		 * Anything else that is not a (populated) array is a malformed directive, not
		 * "no directive" - fail closed rather than silently running a normal query. An
		 * empty scalar ('', 0, false, '0') is malformed too, not absence.
		 */
		if ( ! is_array( $this->tree ) ) {
			$this->fail( 'criteria must be an array tree' );

			return array( '1 = 0' );
		}

		// Walk the tree, accumulating referenced parser names. null === fail closed.
		$referenced = array();
		$combined   = $this->build_group( $this->tree, false, $referenced );

		if ( null === $combined ) {
			return array( '1 = 0' );
		}

		// Additive: AND on any active parser the tree did not name.
		$retval = array( $combined );

		foreach ( $this->where as $name => $fragment ) {
			if ( ! isset( $referenced[ $name ] ) ) {
				$retval[] = $fragment;
			}
		}

		return $retval;
	}

	/**
	 * Misconfiguration reasons recorded while building, for the caller to log.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	public function get_warnings(): array {
		return $this->warnings;
	}

	/**
	 * Recursively render one 'criteria' group to SQL.
	 *
	 * A group is array( 'relation' => 'AND'|'OR'|'XOR', 'not' => bool, <item>, <item>, ... )
	 * where each positional item is either a parser-name string (a leaf) or a nested
	 * group. The optional 'not' flag wraps the whole group in NOT( ... ). Note the
	 * standard SQL three-valued semantics: NOT( col = x ) excludes rows where col IS
	 * NULL (NULL is not "not equal", it is unknown), so negate deliberately.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed              $node        The group node to render.
	 * @param bool               $join_unsafe Whether an ancestor (OR or NOT) makes JOIN-emitting leaves unsafe.
	 * @param array<string,bool> $referenced  Set of referenced parser names, by reference.
	 * @return string|null Rendered SQL, or null to fail the whole query closed.
	 */
	private function build_group( $node, bool $join_unsafe, array &$referenced ): ?string {

		// A group must be an array.
		if ( ! is_array( $node ) ) {
			return $this->fail( 'criteria group must be an array' );
		}

		// Resolve and validate the group relation.
		$relation = ( isset( $node[ 'relation' ] ) && is_string( $node[ 'relation' ] ) )
			? strtoupper( $node[ 'relation' ] )
			: 'AND';

		if ( ! in_array( $relation, array( 'AND', 'OR', 'XOR' ), true ) ) {
			return $this->fail( "criteria relation must be AND, OR, or XOR, got '{$relation}'" );
		}

		// Optional group negation (wraps the group in NOT).
		$negated = ! empty( $node[ 'not' ] );

		/*
		 * A non-AND relation ( OR / XOR ) or a NOT anywhere in the ancestry makes
		 * JOIN-emitting leaves unsafe: a JOIN's INNER pre-filtering cannot be widened
		 * by OR / XOR nor inverted by NOT.
		 */
		$join_unsafe = $join_unsafe || ( 'AND' !== $relation ) || $negated;

		// Render each positional item (the 'relation'/'not' keys are not items).
		$rendered = array();

		foreach ( $node as $key => $item ) {
			if ( ( 'relation' === $key ) || ( 'not' === $key ) ) {
				continue;
			}

			// A string item is a parser leaf; an array item is a nested group.
			if ( is_string( $item ) ) {
				$fragment = $this->resolve_leaf( $item, $join_unsafe, $referenced );
			} elseif ( is_array( $item ) ) {
				$fragment = $this->build_group( $item, $join_unsafe, $referenced );
			} else {
				return $this->fail( 'criteria item must be a parser name or a nested group' );
			}

			// Propagate a fail-closed from any descendant.
			if ( null === $fragment ) {
				return null;
			}

			$rendered[] = $fragment;
		}

		// An empty group names nothing - malformed.
		if ( empty( $rendered ) ) {
			return $this->fail( 'criteria group has no items' );
		}

		return BooleanGroup::combine( $relation, $rendered, $negated );
	}

	/**
	 * Resolve a 'criteria' leaf (a parser name) to its WHERE fragment.
	 *
	 * @since 3.1.0
	 *
	 * @param string             $name        The leaf's parser name (public 'columns' aliases 'by').
	 * @param bool               $join_unsafe Whether this leaf sits under an OR or NOT.
	 * @param array<string,bool> $referenced  Set of referenced parser names, by reference.
	 * @return string|null The fragment ('' when the parser is inactive), or null to fail closed.
	 */
	private function resolve_leaf( string $name, bool $join_unsafe, array &$referenced ): ?string {

		// Map the public name to the internal parser name.
		$parser = self::ALIASES[ $name ] ?? $name;

		// An unknown parser name is a misconfiguration - fail closed.
		if ( ! in_array( $parser, $this->parsers, true ) ) {
			return $this->fail( "criteria references unknown parser '{$name}'" );
		}

		// A JOIN-emitting parser cannot sit under an OR or NOT: its JOIN pre-filters rows.
		if ( $join_unsafe && ! empty( $this->join[ $parser ] ) ) {
			return $this->fail( "criteria cannot OR/NOT the JOIN-emitting '{$name}' parser" );
		}

		// Remember it so the additive pass does not AND it on again.
		$referenced[ $parser ] = true;

		// Active parser -> its fragment; inactive -> '' (dropped by BooleanGroup).
		return $this->where[ $parser ] ?? '';
	}

	/**
	 * Record a misconfiguration reason and signal fail-closed.
	 *
	 * @since 3.1.0
	 *
	 * @param string $reason Human-readable reason, drained by the caller into its log.
	 * @return null Always null, so callers can `return $this->fail( ... )`.
	 */
	private function fail( string $reason ) {
		$this->warnings[] = $reason;

		return null;
	}

	/**
	 * Keep only string => string pairs from a mixed value.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value Candidate map.
	 * @return array<string,string>
	 */
	private function string_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$retval = array();

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && is_string( $item ) ) {
				$retval[ $key ] = $item;
			}
		}

		return $retval;
	}

	/**
	 * Keep only string values from a mixed value, as a list.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value Candidate list.
	 * @return list<string>
	 */
	private function string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$retval = array();

		foreach ( $value as $item ) {
			if ( is_string( $item ) ) {
				$retval[] = $item;
			}
		}

		return $retval;
	}
}
