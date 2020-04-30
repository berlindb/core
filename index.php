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
	 * Hook into queries, admin screens, and more!
	 *
	 * @since 1.1.0
	 */
	public function __construct( $table = false ) {
		$this->table = $table;
	}

	/** Public Management *****************************************************/

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

		// Get the database interface
		$db = $this->get_db();

		// Bail if no database interface is available
		if ( empty( $db ) ) {
			return;
		}

		// Get all indexes
		$indexes = $this->get_all();

		// Bail if no indexes
		if ( empty( $indexes ) ) {
			return false;
		}

		// Parse the arguments
		$r = array_filter( wp_parse_args( $args, array(

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
		) ) );

		// Bail if not filtering by anything
		if ( empty( $r ) ) {
			return false;
		}

		// Filter the object list
		return wp_filter_object_list( $indexes, $r, $operator );
	}

	/**
	 * Does an index exist?
	 *
	 * @since 1.1.0
	 * @param string $key_name Name of the index.
	 * @return bool
	 */
	public function exists( $key_name = '' ) {

		// Filter indexes by key name
		$indexes = $this->filter( array(
			'Key_name' => $key_name
		) );

		// Success/fail
		return $this->is_success( $indexes );
	}

	/**
	 * Does an index exist for a specific column name?
	 *
	 * @since 1.1.0
	 * @param string $key_name    Name of the index.
	 * @param string $column_name Name of the column.
	 * @return bool
	 */
	public function exists_for_column( $key_name = '', $column_name = '' ) {

		// Filter indexes by key name
		$indexes = $this->filter( array(
			'Key_name'    => $key_name,
			'Column_name' => $column_name
		) );

		// Success/fail
		return $this->is_success( $indexes );
	}
}
