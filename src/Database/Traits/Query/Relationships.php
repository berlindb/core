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
		 * the full key.
		 */
		if ( ( 1 === count( $references ) ) && ( $references[0] === $remote->get_primary_column_name() ) ) {
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
}
