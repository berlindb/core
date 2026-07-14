<?php
/**
 * Compare Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Parsers
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Parsers;

use BerlinDB\Database\Kern\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class used for generating SQL for arbitrary column comparison clauses.
 *
 * This class generates SQL when `key` and `value` arguments are passed,
 * supporting all standard comparison operators via the `compare` key.
 * All required methods are provided by the Traits\Parser trait via Base.
 *
 * Two entry points, both handled by the same SQL path:
 *
 *  - The `compare_query` container var: one clause, or a list/group of clauses,
 *    each carrying `key` / `value` / optional `compare`.
 *  - A per-column `{column}_compare` shorthand, for any column whose `compare`
 *    schema flag is true. normalize_query_vars() folds it into `compare_query`, so
 *    it is exact sugar for the container form.
 *
 * @since 3.0.0
 */
class Compare extends Base {

	/**
	 * Internal identifier for this parser.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'compare';

	/**
	 * Top-level query var key this parser consumes, or null when operating per-column.
	 *
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = 'compare_query';

	/**
	 * Column filter passed to get_column_names() to select relevant columns.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Filters on the `compare` column flag (opt-in), consistent with the
	 *              In (`in`) and Search (`searchable`) parsers.
	 * @var array<string,bool>
	 */
	protected $column_filter = array( 'compare' => true );

	/**
	 * Suffix appended to each matching column name to form the per-column query var key.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '_compare';

	/**
	 * Default value for the query var. Null defers to Query::$query_var_default_value.
	 *
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = null;

	/**
	 * Determines and validates what first-order keys to use.
	 *
	 * @since 3.0.0
	 *
	 * @param list<string> $first_keys Array of first-order keys.
	 *
	 * @return list<string> The first-order keys.
	 */
	protected function get_first_keys( $first_keys = array() ) {
		return array( 'key', 'value' );
	}

	/**
	 * Fold per-column `{column}_compare` shorthand vars into `compare_query` (early, before parsing).
	 *
	 * A compare-enabled column (its `compare` schema flag is true) may be filtered
	 * with a comparison operator through a top-level `{column}_compare` var - a
	 * friendlier alternative to hand-writing a `compare_query` clause. Each such var
	 * is rewritten into a first-order `compare_query` clause with `key` forced to the
	 * column its suffix names, so the shorthand is exact sugar for the container form
	 * and shares its SQL path (and cache key). Accepted value shapes mirror the
	 * container clause (see build_shorthand_clause()); a malformed clause therefore
	 * follows the container's existing semantics, since the folded clause runs the
	 * same get_sql_for_clause() path.
	 *
	 * @since 3.1.0
	 * @internal Query/Parser collaborator API.
	 *
	 * @param array<string,mixed> $query_vars All of the caller's query vars.
	 * @param Query               $caller     The Query being normalized.
	 * @return array<string,mixed> The (possibly modified) query vars.
	 */
	public function normalize_query_vars( array $query_vars, Query $caller ): array {

		// Compare-enabled columns; nothing to fold without any.
		$columns = (array) $caller->get_column_names( $this->column_filter );

		if ( empty( $columns ) ) {
			return $query_vars;
		}

		// Collect one folded first-order clause per set shorthand var.
		$folded = array();

		foreach ( $columns as $column ) {
			$key = "{$column}{$this->column_suffix}";

			// Skip an unset shorthand (still at the query-var default sentinel).
			if (
				! array_key_exists( $key, $query_vars )
				||
				$caller->is_query_var_default_value( $query_vars[ $key ] )
			) {
				continue;
			}

			// Shape the shorthand value into a first-order clause, forcing its key.
			$folded[] = $this->build_shorthand_clause( (string) $column, $query_vars[ $key ] );

			// Consumed: drop the shorthand so it does not linger in the vars.
			unset( $query_vars[ $key ] );
		}

		// Nothing set: leave the vars untouched.
		if ( empty( $folded ) ) {
			return $query_vars;
		}

		/*
		 * AND the folded clauses with any existing compare_query. Nest the existing
		 * value as a subgroup so its own relation (including a top-level
		 * 'relation' => 'OR') is preserved rather than being flattened into the
		 * shorthand's implicit-AND sibling list (mirrors Meta::normalize_query_vars).
		 */
		$existing = $query_vars[ 'compare_query' ] ?? null;

		$query_vars[ 'compare_query' ] = ( is_array( $existing ) && ! empty( $existing ) )
			? array_merge( array( $existing ), $folded )
			: $folded;

		return $query_vars;
	}

	/**
	 * Shape a `{column}_compare` shorthand value into a first-order clause.
	 *
	 * The clause `key` is always the column the suffix names; a `key` supplied in the
	 * value is ignored, since the shorthand already implies its column. Value shapes,
	 * mirroring the container clause:
	 *
	 *  - an associative spec carrying `value` and/or `compare` is used as the clause
	 *    body: `array( 'compare' => '>', 'value' => 20 )` -> `priority > 20`.
	 *  - a bare list becomes the clause `value` (the container defaults it to IN):
	 *    `array( 10, 30 )` -> `priority IN ( 10, 30 )`.
	 *  - a scalar becomes the clause `value` (the container defaults it to `=`):
	 *    `20` -> `priority = 20`.
	 *
	 * @since 3.1.0
	 *
	 * @param string $column The compare-enabled column named by the shorthand suffix.
	 * @param mixed  $value  The raw shorthand value.
	 * @return array<string,mixed> A first-order compare clause.
	 */
	private function build_shorthand_clause( string $column, $value ): array {

		// An associative spec (value and/or compare) is the clause body as-is.
		if (
			is_array( $value )
			&&
			( array_key_exists( 'value', $value ) || array_key_exists( 'compare', $value ) )
		) {
			$clause = $value;

			// The suffix names the column; a value-supplied 'key' does not override it.
			$clause[ 'key' ] = $column;

			return $clause;
		}

		// A bare list or scalar is the clause value (the container defaults the operator).
		return array(
			'key'   => $column,
			'value' => $value,
		);
	}
}
