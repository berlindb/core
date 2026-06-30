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
 *                               length and/or a sort direction -- e.g. `'title(191)'`,
 *                               `'priority DESC'`, `'title(191) DESC'`, or keyed
 *                               `'title' => 191` / `'priority' => 'DESC'`.
 *     @type bool     $unique    Is this index unique?
 *     @type string   $method    Index method: BTREE, HASH, etc.
 *     @type string   $comment   Optional comment for the index.
 *     @type string   $using     USING clause for index type (optional).
 * }
 */
class Index {

	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;

	/**
	 * MySQL Index_type values this class can faithfully represent. A SHOW INDEX
	 * group of any other type (e.g. SPATIAL / RTREE) cannot round-trip, so
	 * from_mysql() rejects it rather than emitting wrong DDL.
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private const SUPPORTED_MYSQL_INDEX_TYPES = array( 'BTREE', 'HASH', 'FULLTEXT' );

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
	 * Optional per-column index sort direction ('DESC'), keyed by column name; a
	 * column absent here is ascending (the MySQL default, emitted as nothing).
	 * Derived from the `columns` config (`'priority DESC'` or `'priority' => 'DESC'`).
	 * Descending indexes are a MySQL 8.0 feature; older MySQL/MariaDB accept the
	 * syntax and index ascending. Ignored for FULLTEXT (no per-column order).
	 *
	 * @since 3.1.0
	 * @var   array<string,string>  Default empty array.
	 */
	public $directions = array();

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

	/** Factories *************************************************************/

	/**
	 * Build an Index from the SHOW INDEX rows for a single index.
	 *
	 * MySQL returns one row per column-in-index, so all rows for one index share a
	 * Key_name; this maps that group (ordered by Seq_in_index) into one Index, with
	 * per-column prefix lengths (Sub_part) and DESC directions (Collation 'D'). Only
	 * metadata MySQL reports is populated. The caller groups SHOW INDEX output by
	 * Key_name and calls this once per group.
	 *
	 * Expected per-row keys: Key_name, Non_unique, Seq_in_index, Column_name,
	 * Sub_part, Collation, Index_type, Index_comment.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int,array<string,mixed>> $rows SHOW INDEX rows for one index (same Key_name).
	 * @return self|false The Index, or false if the rows describe no usable index.
	 */
	public static function from_mysql( array $rows ) {

		// Bail if there are no rows.
		if ( empty( $rows ) ) {
			return false;
		}

		// Order the rows by each column's position within the index.
		usort(
			$rows,
			static function ( $a, $b ) {
				return ( (int) ( $a['Seq_in_index'] ?? 0 ) ) <=> ( (int) ( $b['Seq_in_index'] ?? 0 ) );
			}
		);

		// Index-wide metadata is identical on every row; read it from the first.
		$first      = $rows[0];
		$key_name   = (string) ( $first['Key_name'] ?? '' );
		$non_unique = (int) ( $first['Non_unique'] ?? 1 );
		$index_type = strtoupper( (string) ( $first['Index_type'] ?? '' ) );
		$comment    = (string) ( $first['Index_comment'] ?? '' );

		/*
		 * Reject index types this class cannot represent (e.g. SPATIAL / RTREE),
		 * rather than mis-rendering them as a plain KEY.
		 */
		if ( ! in_array( $index_type, self::SUPPORTED_MYSQL_INDEX_TYPES, true ) ) {
			return false;
		}

		// Build the canonical column entries: name, optional (length), optional DESC.
		$columns = array();

		foreach ( $rows as $row ) {
			$column_name = (string) ( $row['Column_name'] ?? '' );

			/*
			 * A key part with no column name is a functional expression
			 * (MySQL reports Column_name NULL + an Expression), which an Index
			 * cannot represent - reject the whole group rather than drop the part.
			 */
			if ( '' === $column_name ) {
				return false;
			}

			$entry = $column_name;

			// Sub_part is the prefix length (null when the column is indexed in full).
			$sub_part = $row['Sub_part'] ?? null;
			if ( ! is_null( $sub_part ) && ( (int) $sub_part > 0 ) ) {
				$entry .= '(' . (int) $sub_part . ')';
			}

			// Collation 'D' is a descending column ('A' ascending, null for HASH/FULLTEXT).
			if ( 'D' === strtoupper( (string) ( $row['Collation'] ?? '' ) ) ) {
				$entry .= ' DESC';
			}

			$columns[] = $entry;
		}

		// The primary key is identified by its reserved name; it carries no own name.
		$is_primary = ( 'PRIMARY' === strtoupper( $key_name ) );

		// Map the MySQL index type to a BerlinDB index type.
		if ( $is_primary ) {
			$type = 'primary';
		} elseif ( 'FULLTEXT' === $index_type ) {
			$type = 'fulltext';
		} else {
			$type = 'key';
		}

		$args = array(
			'type'    => $type,
			'columns' => $columns,
			'unique'  => ( ! $is_primary ) && ( 0 === $non_unique ),
			'comment' => $comment,
		);

		/*
		 * USING is meaningless on a PRIMARY (get_create_string() ignores it there),
		 * so only record a HASH method for a non-primary index.
		 */
		if ( ( ! $is_primary ) && ( 'HASH' === $index_type ) ) {
			$args['using'] = 'HASH';
		}

		/*
		 * The primary key carries no own name (it is identified by type); every
		 * other index keeps its name. An empty name would sanitize to false.
		 */
		if ( ! $is_primary ) {
			$args['name'] = $key_name;
		}

		return new self( $args );
	}

	/**
	 * Split the canonical column entries into the column-name list, the per-column
	 * prefix lengths, and the per-column sort directions.
	 *
	 * Runs after configure() has sanitized `columns` into canonical form, so the
	 * derived $columns / $lengths / $directions cannot be clobbered by the config
	 * defaults merge.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	protected function init(): void {
		$names      = array();
		$lengths    = array();
		$directions = array();

		foreach ( $this->columns as $column ) {
			list( $name, $length, $direction ) = $this->split_column_entry( $column );

			$names[] = $name;

			if ( $length > 0 ) {
				$lengths[ $name ] = $length;
			}

			if ( 'DESC' === $direction ) {
				$directions[ $name ] = 'DESC';
			}
		}

		$this->columns    = $names;
		$this->lengths    = $lengths;
		$this->directions = $directions;
	}

	/** Argument Validation ***************************************************/

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
	 * Get the index name as it appears in SQL.
	 *
	 * A PRIMARY KEY is always the reserved index name PRIMARY in MySQL, whatever the
	 * declared $name (which is empty for a primary); every other index uses its
	 * declared name. Used where an index must be referenced by name, e.g. index hints.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_index_name(): string {
		return ( 'PRIMARY' === strtoupper( $this->type ) )
			? 'PRIMARY'
			: $this->name;
	}

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
		 * Back-tick each column, appending any prefix length and DESC direction.
		 * FULLTEXT indexes whole columns in their own order, so MySQL rejects both a
		 * length and a direction there - never emit either for FULLTEXT.
		 */
		$columns = array();

		foreach ( $this->columns as $name ) {
			$column = $this->quote_identifier( $name );

			if ( 'FULLTEXT' !== $type ) {
				if ( isset( $this->lengths[ $name ] ) ) {
					$column .= '(' . $this->lengths[ $name ] . ')';
				}

				if ( isset( $this->directions[ $name ] ) ) {
					$column .= ' ' . $this->directions[ $name ];
				}
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
	 * Sanitize the columns array into canonical `name(length) DESC` entries.
	 *
	 * A column may carry an optional prefix length and/or sort direction. The keyed
	 * form takes one or the other (`'title' => 191`, `'priority' => 'DESC'`); the
	 * string form takes both (`'title(191) DESC'`); and either mixes with plain
	 * names. Names are sanitized; a positive integer length and a `DESC` direction
	 * are kept (ASC is the default and dropped). The canonical entries are split
	 * into $columns (names) + $lengths + $directions by init(), which runs after the
	 * config merge (so the result cannot be clobbered by it).
	 *
	 * @since 3.0.0
	 *
	 * @param mixed $columns Array of column names, optionally with prefix lengths / directions.
	 * @return list<string> Canonical `name` / `name(length)` / `name DESC` entries.
	 */
	private function sanitize_columns( $columns = array() ): array {

		// Bail if not an array.
		if ( ! is_array( $columns ) ) {
			return array();
		}

		// Default return value.
		$sanitized = array();

		// Split each entry into a name plus an optional prefix length and direction.
		foreach ( $columns as $key => $value ) {

			// Keyed form: 'name' => length, or 'name' => 'ASC'|'DESC'.
			if ( is_string( $key ) ) {
				$raw_name  = $key;
				$length    = is_numeric( $value ) ? (int) $value : 0;
				$direction = ( is_string( $value ) && ( 'DESC' === strtoupper( $value ) ) ) ? 'DESC' : '';

				// String form: 'name', 'name(length)', 'name DESC', 'name(length) DESC'.
			} elseif ( is_string( $value ) ) {
				list( $raw_name, $length, $direction ) = $this->split_column_entry( $value );

				// Anything else is not a column.
			} else {
				continue;
			}

			// Sanitize the column name; skip anything that does not survive.
			$name = $this->sanitize_index_name( $raw_name );

			if ( ! is_string( $name ) || ( '' === $name ) ) {
				continue;
			}

			// Re-emit the canonical form for init() to split.
			$canonical = $name;

			if ( $length > 0 ) {
				$canonical .= "({$length})";
			}

			if ( 'DESC' === $direction ) {
				$canonical .= ' DESC';
			}

			$sanitized[] = $canonical;
		}

		return $sanitized;
	}

	/**
	 * Split a column entry into its name, optional prefix length, and direction.
	 *
	 * The single place the `(length)` and ` ASC|DESC` suffixes are parsed, shared by
	 * sanitize_columns() (the string input form) and init() (the canonical entries).
	 * ASC is normalized away (the default), so direction is `'DESC'` or `''`.
	 *
	 * @since 3.1.0
	 *
	 * @param string $entry A column entry, e.g. `name`, `name(191)`, `name DESC`, `name(191) DESC`.
	 * @return array{0: string, 1: int, 2: string} The [ name, length, direction ] triple.
	 */
	private function split_column_entry( string $entry ): array {
		$length    = 0;
		$direction = '';

		// Tolerate incidental padding, like the column-name sanitizer does.
		$entry = trim( $entry );

		// Peel a trailing ASC/DESC direction (ASC is the default, so dropped).
		if ( preg_match( '/^(.+?)\s+(ASC|DESC)$/i', $entry, $matches ) ) {
			$entry = $matches[ 1 ];

			if ( 'DESC' === strtoupper( $matches[ 2 ] ) ) {
				$direction = 'DESC';
			}
		}

		// Peel a trailing (length) prefix.
		if ( preg_match( '/^(.+)\((\d+)\)$/', $entry, $matches ) ) {
			$entry  = $matches[ 1 ];
			$length = (int) $matches[ 2 ];
		}

		return array( $entry, $length, $direction );
	}
}
