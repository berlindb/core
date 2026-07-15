<?php
/**
 * Query Filters Trait Class.
 *
 * @package     Database
 * @subpackage  Query
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Query;

use BerlinDB\Database\Kern\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The filter (hook) surface for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Each method wraps an
 * apply_filters() extension point - for an item before save (filter_item),
 * query results (filter_items), the parser list (filter_query_var_parsers),
 * the found-rows COUNT SQL (filter_found_items_query), and the assembled SQL
 * clauses (filter_query_clauses) - so plugins can hook a Query's pipeline.
 *
 * @since 3.1.0
 */
trait Filters {

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
		$filter_name = $this->apply_hook_prefix( 'filter_' . $this->get_item_name() . '_item' );

		if ( '' === $filter_name ) {
			return $item;
		}

		/**
		 * Filters an item before it is inserted or updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed>     $item  The item as an array.
		 * @param Query $query Current query instance.
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
		$filter_name = $this->apply_hook_prefix( 'query_var_parsers' );

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
		$filter_name = $this->apply_hook_prefix( 'the_' . $this->get_item_name_plural() );

		if ( '' === $filter_name ) {
			return $items;
		}

		/**
		 * Filters the object query results after they have been shaped.
		 *
		 * @since 1.0.0
		 *
		 * @param list<object>             $items An array of items.
		 * @param Query $query Current query instance.
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
		$filter_name = $this->apply_hook_prefix( 'found_' . $this->get_item_name_plural() . '_query' );

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
		 * @param Query $query Current query instance.
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
		$filter_name = $this->apply_hook_prefix( $this->get_item_name_plural() . '_query_clauses' );

		if ( '' === $filter_name ) {
			return $clauses;
		}

		/**
		 * Filters the item query clauses.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $clauses An array of query clauses.
		 * @param Query               $query   Current query instance.
		 */
		return (array) apply_filters_ref_array(
			$filter_name,
			array(
				$clauses,
				&$this,
			)
		);
	}
}
