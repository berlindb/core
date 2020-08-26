<?php
/**
 * Base Custom Database Index Class.
 *
 * @package     Database
 * @subpackage  Index
 * @copyright   Copyright (c) 2020
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.1.0
 */
namespace BerlinDB\Database;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * A base database index class, which facilitates the creation of (and changes
 * to) table indexes.
 *
 * It exists to make managing database table indexes as easy as possible.
 *
 * @since 1.1.0
 */
abstract class Index extends Base {

	/**
	 * Table name, without the global table prefix.
	 *
	 * @since 1.1.0
	 * @var   bool|object
	 */
	protected $table = false;

	/** Methods ***************************************************************/

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args
	 */
	public function __construct( $args = array() ) {

		// Maybe initialize
		if ( ! empty( $args ) ) {
			$this->init( $args );
		}
	}

	/**
	 * Initialize properties based on passed arguments.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args
	 */
	private function init( $args = array() ) {
		$this->set_vars( $args );
	}

	/** Public Management *****************************************************/

	/**
	 * Does an index exist?
	 *
	 * @since 1.1.0
	 * @param string|string[] $args Name of the key, or array of values.
	 * @return bool
	 */
	public function exists( $args = array() ) {

		// Default array
		if ( is_string( $args ) ) {
			$args = array(
				'Key_name' => sanitize_key( $args )
			);
		}

		// Filter indexes by arguments
		$indexes = $this->filter( $args );

		// Success/fail
		return $this->is_success( $indexes );
	}

	/**
	 * Get all of the indexes from this table.
	 *
	 * @since 1.1.0
	 * @return array|void
	 */
	public function get_all() {

		// Get the database interface
		$db = $this->get_db();

		// Bail if no database interface is available
		if ( empty( $db ) ) {
			return;
		}

		// Bail if no table name is set
		if ( empty( $this->table->name ) ) {
			return;
		}

		// Query statement
		$query    = "SHOW INDEXES FROM {$this->table->name}";
		$prepared = $db->prepare( $query );
		$result   = $db->get_results( $prepared );

		// Did the query successfully get any indexes?
		$success = $this->is_success( $result );

		// Return the result, or empty array
		return ( true === $success )
			? (array) $result
			: array();
	}

	/**
	 * Filter all of the table indexes down to only specific keys and values.
	 *
	 * This uses WordPress specific functions, so either shim them or avoid
	 * using until WordPress has included /wp-includes/functions.php.
	 *
	 * @since 1.1.0
	 * @param array $args
	 * @return bool|void
	 */
	public function filter( $args = array(), $operator = 'and' ) {

		// Parse the filter arguments
		$r = $this->parse_filter_args( $args );

		// Bail if not filtering by anything
		if ( empty( $r ) ) {
			return;
		}

		// Get all indexes
		$indexes = $this->get_all();

		// Bail if no indexes
		if ( empty( $indexes ) ) {
			return;
		}

		// Filter the object list
		return wp_filter_object_list( $indexes, $r, $operator );
	}

	/**
	 * Parse filter arguments.
	 *
	 * @since 1.1.0
	 * @param array $args
	 * @return boolean
	 */
	public function parse_filter_args( $args = array() ) {

		// Bail if not an array
		if ( empty( $args ) ) {
			return;
		}

		// Parse the arguments
		$retval = wp_parse_args( $args, array(

			// You probably want to use these
			'Key_name'      => '',
			'Column_name'   => '',

			// You probably do not want to filter by these
			'Table'         => null,
			'Non_unique'    => null,
			'Seq_in_index'  => null,
			'Collation'     => null,
			'Cardinality'   => null,
			'Sub_part'      => null,
			'Packed'        => null,
			'Null'          => null,
			'Index_type'    => null,
			'Comment'       => null,
			'Index_comment' => null,
			'Visible'       => null,
			'Expression'    => null
		) );

		// Return arguments
		return array_filter( $retval );
	}
}
