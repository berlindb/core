<?php
/**
 * Class Query Registry
 *
 * @since   2.0.0
 * @package BerlinDB\Database\Queries
 */


namespace BerlinDB\Database\Queries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers different query processors
 *
 *
 * @since 2.0.0
 * @package
 */
class Query_Registry extends \ArrayIterator {

	private $query_args = array();

	public function __construct( $query_args ) {
		parent::__construct();
		$this->query_args = $query_args;

		// Register processors
		foreach ( (array) apply_filters( 'berlin_db_query_processors', array() ) as $key => $class ) {
			$this->add( $key, $class );
		}
	}

	/**
	 * Adds an item to the registry
	 *
	 * @since 2.0.0
	 *
	 * @param string $key   The key to use when referencing this class
	 * @param mixed  $class The class name to instantiate when this key is called.
	 * @return boolean true if the item was set, otherwise false.
	 */
	public function add( $key, $class ) {
		$valid = is_subclass_of( $class, 'BerlinDB\Database\Queries\Query_Processor' );

		if ( $valid ) {
			$this[ $key ] = $class;
		}

		return $valid;
	}

	/**
	 * Retrieves a registered item.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key The identifier for the item.
	 * @return Query_Processor|false the item value, if it is set. Otherwise, false.
	 */
	public function get( $key ) {
		if ( isset( $this[ $key ] ) && is_string( $this[ $key ] ) ) {
			$this[ $key ] = new $this[ $key ]( $this->query_args );

			return $this[ $key ];
		}

		return false;
	}
}