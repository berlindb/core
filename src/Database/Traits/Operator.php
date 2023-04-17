<?php
/**
 * Operator Trait.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database\Traits;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Trait for parsing some $query_vars array into an array of SQL clauses.
 *
 * @since 3.0.0
 */
trait Operator {

	/**
	 * Name of operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = '';

	/**
	 * SQL used for comparison.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = '';

	/**
	 * Is this a "NOT" or "!" type of operator?
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = false;

	/**
	 * Is this an "IN" or "BETWEEN" type of operator?
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $multi = false;

	/**
	 * Is this a ">" or "<" or "BETWEEN" type of operator?
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = false;


	protected function get_sql( $value = null, $pattern = '%s' ) {

	}

	protected function init( $args = array() ) {
		foreach ( $args as $key => $value ) {
			$this->{$key} = $value;
		}
	}
}
