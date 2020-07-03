<?php
/**
 * Query_Processor Class
 *
 * @since 2.0.0
 * @package BerlinDB\Database\Queries
 */


namespace BerlinDB\Database\Queries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes custom extendable query params
 *
 *
 * @since 2.0.0
 * @package BerlinDB\Database\Queries
 */
abstract class Query_Processor extends Base {

	/**
	 * The arguments passed by the query
	 *
	 * @since 2.0.0
	 *
	 * @var array array of arguments.
	 */
	private $query_args = array();

	/**
	 * Query_Processor constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param $query_args array of query arguments.
	 */
	public function __construct( $query_args ) {
		$this->query_args = $query_args;
	}

	/**
	 * Parse the where clause specific to this processor.
	 *
	 * @since 2.0.0
	 *
	 * @param array $column The current column.
	 * @return string The where clause for this column. If left empty, the clause will not be added.
	 */
	abstract public function parse_where( $column );

	/**
	 * Parse the join clause specific to this processor.
	 *
	 * @since 2.0.0
	 *
	 * @return string The join clause for this processor.
	 */
	abstract public function parse_join();
}