<?php
/**
 * Base Custom Database Table Index Class.
 *
 * @package     Database
 * @subpackage  Index
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Base class used for each index for a custom table.
 *
 * Mirrors Column class, but for index registration & management.
 *
 * @since 3.0.0
 *
 * @param array|string $args {
 *
 *     Optional. Array or query string of index parameters. Default empty.
 *
 *     @type string   $name      Name of the index.
 *     @type string   $type      Index type: primary, unique, key, fulltext.
 *     @type array    $columns   Array of column names included in this index.
 *     @type bool     $unique    Is this index unique?
 *     @type string   $method    Index method: BTREE, HASH, etc.
 *     @type string   $comment   Optional comment for the index.
 *     @type string   $using     USING clause for index type (optional).
 * }
 */
class Index {

	use Traits\Base;
	use Traits\Boot;

	/** Attributes ************************************************************/

	/**
	 * Name for the database index.
	 *
	 * @since 3.0.0
	 * @var   string Default empty string.
	 */
	public $name = '';

	/**
	 * Index type (primary, unique, key, fulltext).
	 *
	 * @since 3.0.0
	 * @var   string Default 'key'.
	 */
	public $type = 'key';

	/**
	 * Array of columns the index consists of.
	 *
	 * @since 3.0.0
	 * @var   array  Default empty array.
	 */
	public $columns = array();

	/**
	 * Is this index unique?
	 *
	 * @since 3.0.0
	 * @var   bool   Default false.
	 */
	public $unique = false;

	/**
	 * Index method (BTREE, HASH, etc.)
	 *
	 * @since 3.0.0
	 * @var   string Default 'BTREE'.
	 */
	public $method = 'BTREE';

	/**
	 * Optional comment for the index.
	 *
	 * @since 3.0.0
	 * @var   string Default empty string.
	 */
	public $comment = '';

	/**
	 * Optional USING clause for advanced index type specification.
	 *
	 * @since 3.0.0
	 * @var   string Default empty string.
	 */
	public $using = '';

	/** Argument validation ***************************************************/

	/**
	* Normalize and sanitize all arguments passed to Index.
	*
	* @since 3.0.0
	*
	* @param array $args
	*
	* @return array
	*/
	protected function validate_args( $args = array() ) {

		// Array of callbacks for specific keys.
		$callbacks = array(
			'name'    => array( $this, 'sanitize_index_name' ),
			'type'    => 'strtolower',
			'unique'  => 'wp_validate_boolean',
			'method'  => 'strtoupper',
			'comment' => 'wp_kses_data',
			'using'   => 'strtoupper',
			'columns' => array( $this, 'sanitize_columns' ),
		);

		// Default values for all keys.
		$r = array();

		// Loop through each argument, sanitize if possible.
		foreach ( $args as $key => $value ) {

			// If a callback is set for this key, use it.
			if ( isset( $callbacks[ $key ] ) && is_callable( $callbacks[ $key ] ) ) {
				$r[ $key ] = call_user_func( $callbacks[ $key ], $value );

			// Otherwise assign the value as-is.
			} else {
				$r[ $key ] = $value;
			}
		}

		// Return validated arguments.
		return $r;
	}

	/** Public Helpers ********************************************************/

	/**
	* Get the CREATE clause for this index.
	*
	* @since 3.0.0
	*
	* @return string
	*/
	public function get_create_string() {

		// If name or columns are empty, no valid CREATE syntax can be constructed.
		if ( empty( $this->name ) || empty( $this->columns ) ) {
			return '';
		}

		// Prepare the column list as back-ticked for SQL.
		$columns = array_map( function( $col ) {
			return "`$col`";
		}, $this->columns );

		// Standardize the index type and prepare base SQL fragment.
		$type = strtoupper( $this->type );
		$sql  = '';

		// Choose the SQL clause based on type.
		if ( 'PRIMARY' === $type ) {
			$sql = 'PRIMARY KEY (' . implode( ', ', $columns ) . ')';

		} elseif ( true === $this->unique || 'UNIQUE' === $type ) {
			$sql = 'UNIQUE KEY `' . $this->name . '` (' . implode( ', ', $columns ) . ')';

		} elseif ( 'FULLTEXT' === $type ) {
			$sql = 'FULLTEXT KEY `' . $this->name . '` (' . implode( ', ', $columns ) . ')';

		} else {
			$sql = 'KEY `' . $this->name . '` (' . implode( ', ', $columns ) . ')';
		}

		// Optionally specify index method if set.
		if ( '' !== $this->method ) {
			$sql .= ' USING ' . $this->method;
		}

		// Optionally specify comment if set.
		if ( '' !== $this->comment ) {
			$sql .= ' COMMENT ' . "'{$this->comment}'";
		}

		return $sql;
	}

	/** Private Sanitizers ****************************************************/

	/**
	* Sanitize the index name.
	*
	* @since 3.0.0
	*
	* @param string $name
	* @return string
	*/
	private function sanitize_index_name( $name = '' ) {

		// Only allow alphanumeric and underscores; convert everything else to
		// underscore and lowercase.
		return strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '_', $name ) );
	}

	/**
	* Sanitize the columns array.
	*
	* @since 3.0.0
	*
	* @param array $columns
	* @return array
	*/
	private function sanitize_columns( $columns = array() ) {

		// Filter to ensure only string column names, remove empty ones and
		// reset array keys.
		return array_values( array_filter( (array) $columns, 'is_string' ) );
	}
}
