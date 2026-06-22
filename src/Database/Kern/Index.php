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

declare( strict_types = 1 );

namespace BerlinDB\Database\Kern;

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
 *     @type array    $columns   Column names in this index; a name may carry a prefix
 *                               length as `'title(191)'` or `'title' => 191`.
 *     @type bool     $unique    Is this index unique?
 *     @type string   $method    Index method: BTREE, HASH, etc.
 *     @type string   $comment   Optional comment for the index.
 *     @type string   $using     USING clause for index type (optional).
 * }
 */
class Index {

	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;

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
	 * @var   list<string>  Default empty array.
	 */
	public $columns = array();

	/**
	 * Optional per-column index prefix lengths (MySQL "Sub_part"), keyed by column
	 * name; a column absent here is indexed in full. Derived from the `columns`
	 * config (`'title(191)'` or `'title' => 191`). Only meaningful for string/blob
	 * columns - MySQL rejects a prefix on other types (the caller's responsibility,
	 * as an Index does not know column types) and on FULLTEXT indexes (ignored here).
	 *
	 * @since 3.1.0
	 * @var   array<string,int>  Default empty array.
	 */
	public $lengths = array();

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
	 * Sanitization callbacks for an Index's configuration arguments.
	 *
	 * Applied by validate_args() (Traits\Configuration) during construction.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed> Map of config key => sanitization callback.
	 */
	protected function get_config_callbacks(): array {
		return array(
			'name'    => array( $this, 'sanitize_index_name' ),
			'type'    => 'strtolower',
			'unique'  => array( $this, 'sanitize_boolean' ),
			'method'  => 'strtoupper',
			'comment' => array( $this, 'sanitize_comment' ),
			'using'   => 'strtoupper',
			'columns' => array( $this, 'sanitize_columns' ),
		);
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

		// Bail if no columns are provided.
		if ( empty( $this->columns ) ) {
			return '';
		}

		// Standardize the index type up front.
		$type = strtoupper( $this->type );

		/*
		 * Back-tick each column, appending any prefix length. FULLTEXT indexes whole
		 * columns, so MySQL rejects a length there - never emit one for FULLTEXT.
		 */
		$columns = array();

		foreach ( $this->columns as $name ) {
			$column = $this->quote_identifier( $name );

			if ( ( 'FULLTEXT' !== $type ) && isset( $this->lengths[ $name ] ) ) {
				$column .= '(' . $this->lengths[ $name ] . ')';
			}

			$columns[] = $column;
		}

		// Prepare base SQL fragment.
		$sql  = '';
		$csql = implode( ', ', $columns );

		// Choose the SQL clause based on type.
		if ( 'PRIMARY' === $type ) {
			$sql = 'PRIMARY KEY (' . $csql . ')';

		} elseif ( true === $this->unique || 'UNIQUE' === $type ) {

			// Bail if no name.
			if ( empty( $this->name ) ) {
				return '';
			}

			$sql = 'UNIQUE KEY `' . $this->name . '` (' . $csql . ')';

		} elseif ( 'FULLTEXT' === $type ) {

			// Bail if no name.
			if ( empty( $this->name ) ) {
				return '';
			}

			$sql = 'FULLTEXT KEY `' . $this->name . '` (' . $csql . ')';

		} else {

			// Bail if no name.
			if ( empty( $this->name ) ) {
				return '';
			}

			$sql = 'KEY `' . $this->name . '` (' . $csql . ')';
		}

		// USING is only valid for regular KEY and UNIQUE KEY - not PRIMARY or FULLTEXT.
		if ( ! in_array( $type, array( 'PRIMARY', 'FULLTEXT' ), true ) ) {

			// Prefer explicit "using" over the default method.
			$algorithm = ! empty( $this->using )
				? $this->using
				: $this->method;

			// Append USING clause if method is specified.
			if ( '' !== $algorithm ) {
				$sql .= ' USING ' . $algorithm;
			}
		}

		// Optionally specify comment if set.
		if ( '' !== $this->comment ) {
			$sql .= ' COMMENT ' . "'" . addslashes( $this->comment ) . "'";
		}

		return $sql;
	}

	/** Private Sanitizers ****************************************************/

	/**
	 * Sanitize the columns array into canonical `name` / `name(length)` entries.
	 *
	 * A column may carry an optional prefix length in either the keyed form
	 * (`'title' => 191`) or the string form (`'title(191)'`); the two forms may be
	 * mixed with plain names. Names are sanitized and a positive integer length is
	 * kept (anything else means the whole column). The canonical entries are split
	 * into $columns (names) + $lengths by init(), which runs after the config merge
	 * (so the result cannot be clobbered by it).
	 *
	 * @since 3.0.0
	 *
	 * @param mixed $columns Array of column names, optionally carrying prefix lengths.
	 * @return list<string> Canonical `name` / `name(length)` entries.
	 */
	private function sanitize_columns( $columns = array() ): array {

		// Bail if not an array.
		if ( ! is_array( $columns ) ) {
			return array();
		}

		// Default return value.
		$sanitized = array();

		// Split each entry into a column name and an optional prefix length.
		foreach ( $columns as $key => $value ) {

			// Keyed form: 'name' => length.
			if ( is_string( $key ) ) {
				$raw_name = $key;
				$length   = is_numeric( $value ) ? (int) $value : 0;

				// String form: 'name', or 'name(length)'.
			} elseif ( is_string( $value ) ) {
				list( $raw_name, $length ) = $this->split_column_length( $value );

				// Anything else is not a column.
			} else {
				continue;
			}

			// Sanitize the column name; skip anything that does not survive.
			$name = $this->sanitize_index_name( $raw_name );

			if ( ! is_string( $name ) || ( '' === $name ) ) {
				continue;
			}

			// Emit the canonical form ('name' or 'name(length)') for init() to split.
			$sanitized[] = ( $length > 0 )
				? "{$name}({$length})"
				: $name;
		}

		return $sanitized;
	}

	/**
	 * Split the canonical `name(length)` column entries into the column-name list
	 * and the per-column prefix lengths.
	 *
	 * Runs after configure() has sanitized `columns` into canonical form, so the
	 * derived $columns / $lengths cannot be clobbered by the config defaults merge.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	protected function init(): void {
		$names   = array();
		$lengths = array();

		foreach ( $this->columns as $column ) {
			list( $name, $length ) = $this->split_column_length( $column );

			$names[] = $name;

			if ( $length > 0 ) {
				$lengths[ $name ] = $length;
			}
		}

		$this->columns = $names;
		$this->lengths = $lengths;
	}

	/**
	 * Split a `name` or `name(length)` string into its column name and prefix length.
	 *
	 * The single place the `(length)` suffix is parsed, shared by sanitize_columns()
	 * (the string input form) and init() (the canonical entries).
	 *
	 * @since 3.1.0
	 *
	 * @param string $entry A column entry, optionally with a `(length)` suffix.
	 * @return array{0: string, 1: int} The [ name, length ] pair; length 0 = whole column.
	 */
	private function split_column_length( string $entry ): array {
		return preg_match( '/^(.+)\((\d+)\)$/', $entry, $matches )
			? array( $matches[ 1 ], (int) $matches[ 2 ] )
			: array( $entry, 0 );
	}
}
