<?php
/**
 * Query Relationships Trait Class.
 *
 * @package     Database
 * @subpackage  Query
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Relationship;
use BerlinDB\Database\Kern\Schema;

/**
 * The relationship API for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Exposes the declared
 * relationships (get_relationships / get_relationship / get_belongs_to_ /
 * get_has_many_), validates their remote tier (get_relationship_errors),
 * resolves a relationship's remote Query (resolve_remote_query), and fetches
 * related rows (get_related). The inert Relationship value objects come from
 * the Schema; relationship-cache priming lives in Traits\Query\Cache.
 *
 * @since 3.1.0
 */
trait Relationships {

	/**
	 * Collision-safe de-duplication hash for a relationship-key tuple.
	 *
	 * Provided by the Cache trait (which also uses it for tuple priming); declared
	 * here so many_to_many resolution can dedupe target keys with the same hash.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $tuple Ordered [ column => value ] tuple.
	 * @return string
	 */
	abstract protected function get_relationship_tuple_hash( array $tuple ): string;

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

			/*
			 * A many_to_many also resolves through a pivot; validate that side first
			 * and independently, so a broken pivot is still reported when the target
			 * remote is ALSO unresolvable (which continues past the checks below).
			 */
			if ( 'many_to_many' === $relationship->type ) {
				$errors = array_merge( $errors, $this->get_pivot_relationship_errors( $relationship, $name ) );
			}

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
	 * Get the remote-tier validation errors for a many_to_many's pivot hop.
	 *
	 * The target-side checks (remote class, remote references) run in
	 * get_relationship_errors() like any relationship; this adds the pivot: the
	 * through class must resolve to a Query, and both pivot column sets
	 * (through_columns, through_references) must exist on the pivot's schema.
	 * A missing through is left to the value object's own get_validation_errors(),
	 * matching how a missing target class is Schema's to report - here we report the
	 * distinct "class exists but is not a Query" case, which only this tier can tell.
	 *
	 * @since 3.1.0
	 *
	 * @param Relationship $relationship The many_to_many relationship.
	 * @param string       $name         Stable label for messages (accessor name).
	 * @return string[] Array of human-readable error strings. Empty if valid.
	 */
	private function get_pivot_relationship_errors( Relationship $relationship, string $name ): array {
		$errors = array();

		// Resolve the pivot (through) query; must be a sibling Query.
		$pivot = $this->instantiate_class( $relationship->through );

		if ( ! ( $pivot instanceof Query ) ) {

			/*
			 * A declared-but-not-a-Query pivot is ours to report; a missing one is
			 * left to the value object's self-validation.
			 */
			if ( ( '' !== $relationship->through ) && class_exists( $relationship->through ) ) {
				$errors[] = "Relationship {$name} pivot class {$relationship->through} is not a Query.";
			}

			return $errors;
		}

		// Both hops' pivot columns must exist on the pivot's own schema.
		$pivot_columns = array_merge( $relationship->through_columns, $relationship->through_references );

		foreach ( $pivot_columns as $column ) {
			if ( ! ( $pivot->get_column_by( array( 'name' => $column ) ) instanceof Column ) ) {
				$errors[] = "Relationship {$name} references unknown pivot column {$column}.";
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
	 * Get the relationships that resolve through a pivot table (two hops).
	 *
	 * @since 3.1.0
	 *
	 * @return Relationship[]
	 */
	public function get_many_to_many_relationships() {
		return $this->get_relationships_by_type( 'many_to_many' );
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
	 * @return Query|null The remote query instance, or null when unresolvable.
	 */
	private function resolve_remote_query( Relationship $relationship ): ?Query {

		// Instantiate the relationship's declared remote class; must be a sibling Query.
		$remote = $this->instantiate_class( $relationship->get_query_class() );

		return ( $remote instanceof Query )
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
	 * a belongs_to relationship this returns the single related Row (or null); for
	 * has_many it returns an array of related Rows; for many_to_many it returns the
	 * array of target Rows resolved through the pivot table (see
	 * get_related_many_to_many()). When the belongs_to side references the remote
	 * primary key, the lookup runs through get_item(), so a previously primed cache
	 * (the 'with' query arg) makes it a cache hit.
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

		/*
		 * many_to_many resolves through a pivot table in two hops (this -> pivot ->
		 * target), so it takes a separate path from the single-hop belongs_to /
		 * has_many key logic below. It is a to-many, returning an array of Rows.
		 */
		if ( 'many_to_many' === $relationship->type ) {

			/*
			 * A condition is not applied to a many_to_many in this version; fail closed
			 * (empty) rather than silently returning unscoped rows.
			 */
			if ( $relationship->has_condition() ) {
				return array();
			}

			return $this->get_related_many_to_many( $item, $relationship );
		}

		// belongs_to or has_many, single- or multi-column ( composite ) key.
		$columns    = $relationship->columns;
		$references = $relationship->references;
		if ( empty( $columns ) || ( count( $columns ) !== count( $references ) ) ) {
			return null;
		}

		/*
		 * Build the remote lookup key from every positional column pair. Any missing
		 * or empty key part means no relation (see is_empty_relationship_key() for the
		 * 0/'0'/''/null policy) - a composite key needs ALL parts present.
		 */
		$key = array();

		foreach ( $columns as $i => $local_col ) {
			if ( ! isset( $item->{$local_col} ) || $this->is_empty_relationship_key( $item->{$local_col} ) ) {
				return ( 'has_many' === $relationship->type )
					? array()
					: null;
			}

			$key[ $references[ $i ] ] = $item->{$local_col};
		}

		// Resolve the remote query instance (guarded; null when unresolvable).
		$remote = $this->resolve_remote_query( $relationship );
		if ( null === $remote ) {
			return null;
		}

		/*
		 * A conditioned relationship scopes the related rows by a fixed discriminator
		 * (e.g. object_type => 'order'). It rides the canonical {column}__in var, whose
		 * suffix means it can never be mistaken for a reserved control var and never
		 * overwrites the FK correlation key. That path is in-filter based, so fail closed
		 * on any condition column that is unknown OR not `in => true` - a typo (or a
		 * missing flag) must not widen to all rows. Priming keys identically, so a primed
		 * and an unprimed get_related() agree.
		 */
		if ( $relationship->has_condition() ) {
			foreach ( $relationship->get_condition() as $condition_col => $condition_value ) {
				if ( empty(
					$remote->get_columns(
						array(
							'name' => $condition_col,
							'in'   => true,
						)
					)
				) ) {
					return ( 'has_many' === $relationship->type )
						? array()
						: null;
				}

				$key[ "{$condition_col}__in" ] = array( $condition_value );
			}
		}

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
			/*
			 * array_merge so the reserved 'number' ( limit ) always wins over a key
			 * column that happened to be named 'number'.
			 */
			$found = $remote->query( array_merge( $key, array( 'number' => 0 ) ) );

			return is_array( $found )
				? $found
				: array();
		}

		/*
		 * belongs_to referencing the remote primary key - cache-friendly get_item().
		 * Single-column only: get_item() takes one id, so a composite key (even one
		 * whose first reference matches the primary column name) must use query() with
		 * the full key. A conditioned relationship also falls through to query(), since
		 * get_item() cannot carry the extra discriminator filter.
		 */
		if ( ( 1 === count( $references ) ) && ( $references[0] === $remote->get_primary_column_name() ) && ! $relationship->has_condition() ) {
			$found = $remote->get_item( reset( $key ) );

			return ! empty( $found )
				? $found
				: null;
		}

		// belongs_to referencing a non-primary (or composite) remote key.
		$found = $remote->query( array_merge( $key, array( 'number' => 1 ) ) );

		return ( is_array( $found ) && ! empty( $found ) )
			? reset( $found )
			: null;
	}

	/**
	 * Resolve a many_to_many relationship for one item, through its pivot table.
	 *
	 * Two hops, keyed positionally at each: hop 1 reads this item's local key
	 * ($columns) and queries the pivot ($through) for the rows whose
	 * $through_columns match it; hop 2 reads each pivot row's $through_references
	 * and fetches the target ($query) rows whose $references match. Returns the
	 * distinct target Rows (an empty array when the item has no local key, the
	 * pivot has no rows, or either remote is unresolvable - a pivot accessor never
	 * returns a wrong Row, only its real child set or nothing).
	 *
	 * Unprimed this is one pivot read plus one read per distinct target; the 'with'
	 * priming phase warms both caches so a later call is a hit. Target rows are
	 * fetched by get_item() when the target key is the remote primary (the common,
	 * cache-friendly case), else by a keyed query().
	 *
	 * @since 3.1.0
	 *
	 * @param object       $item         Item produced by this query.
	 * @param Relationship $relationship The many_to_many relationship.
	 * @return object[] The related target Rows (empty when none).
	 */
	private function get_related_many_to_many( object $item, Relationship $relationship ): array {

		$columns            = $relationship->columns;
		$through_columns    = $relationship->through_columns;
		$through_references = $relationship->through_references;
		$references         = $relationship->references;

		// Bail unless both hops pair up positionally (validated shape, re-guarded).
		if (
			empty( $columns ) || empty( $through_columns )
			|| empty( $through_references ) || empty( $references )
			|| ( count( $columns ) !== count( $through_columns ) )
			|| ( count( $through_references ) !== count( $references ) )
		) {
			return array();
		}

		// Hop 1 key: pivot's $through_columns = this item's local key values.
		$hop1 = array();

		foreach ( $columns as $i => $local_col ) {
			if ( ! isset( $item->{$local_col} ) || $this->is_empty_relationship_key( $item->{$local_col} ) ) {
				return array();
			}

			$hop1[ $through_columns[ $i ] ] = $item->{$local_col};
		}

		// Resolve the pivot (through) query; fail closed when unresolvable.
		$pivot = $this->instantiate_class( $relationship->through );
		if ( ! ( $pivot instanceof Query ) ) {
			return array();
		}

		// Fetch every pivot row for this item (the full set, not a page).
		$pivot_rows = $pivot->query( array_merge( $hop1, array( 'number' => 0 ) ) );
		if ( ! is_array( $pivot_rows ) || empty( $pivot_rows ) ) {
			return array();
		}

		// Collect the distinct target-key tuples the pivot rows point at (hop 2).
		$target_keys = array();

		foreach ( $pivot_rows as $pivot_row ) {
			if ( ! is_object( $pivot_row ) ) {
				continue;
			}

			$key      = array();
			$complete = true;

			foreach ( $through_references as $j => $through_ref ) {
				if ( ! isset( $pivot_row->{$through_ref} ) || $this->is_empty_relationship_key( $pivot_row->{$through_ref} ) ) {
					$complete = false;
					break;
				}

				$key[ $references[ $j ] ] = $pivot_row->{$through_ref};
			}

			// Dedupe: the same target may be reached via more than one pivot row.
			if ( true === $complete ) {
				$target_keys[ $this->get_relationship_tuple_hash( $key ) ] = $key;
			}
		}

		if ( empty( $target_keys ) ) {
			return array();
		}

		// Resolve the target query; fail closed when unresolvable.
		$target = $this->resolve_remote_query( $relationship );
		if ( null === $target ) {
			return array();
		}

		// Hop 2: fetch the target rows for the collected keys, deduped by primary.
		$primary = $target->get_primary_column_name();
		$by_prim = ( 1 === count( $references ) ) && ( $references[0] === $primary );
		$results = array();
		$seen    = array();

		foreach ( $target_keys as $key ) {

			// A single-column primary key resolves through the cache-friendly get_item().
			if ( true === $by_prim ) {
				$found = $target->get_item( reset( $key ) );
				$rows  = ! empty( $found )
					? array( $found )
					: array();

				// A non-primary or composite target key needs a full keyed query.
			} else {
				$found = $target->query( array_merge( $key, array( 'number' => 0 ) ) );
				$rows  = is_array( $found )
					? $found
					: array();
			}

			foreach ( $rows as $row ) {
				if ( ! is_object( $row ) || ! isset( $row->{$primary} ) ) {
					continue;
				}

				$id = (string) $row->{$primary};

				if ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$results[]   = $row;
				}
			}
		}

		return $results;
	}
}
