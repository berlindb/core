<?php
/**
 * Base Custom Database Table Relationship Class.
 *
 * @package     Database
 * @subpackage  Relationship
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Kern;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base class used for each relationship between tables.
 *
 * Mirrors the Index class, but for (typically unenforced) foreign keys between
 * a local table and a remote table that is managed by a Query class.
 *
 * WordPress deliberately avoids real FOREIGN KEY constraints (historical MyISAM
 * support, fragile bulk operations, application-layer integrity). BerlinDB
 * matches that convention: relationships are unenforced by default and emit no
 * DDL. The shape, however, is intentionally FOREIGN KEY-compatible — local
 * columns, a referenced table and columns, and ON DELETE / ON UPDATE actions —
 * so that:
 *
 *   - the opt-in `enforce` flag can emit real constraint DDL,
 *   - MySQL introspection can read constraints back into the same shape, and
 *   - the vocabulary is familiar to anyone who already knows SQL.
 *
 * The "extra magic" (a Query-class-addressed remote table, belongs_to/has_many
 * semantics, cache priming, and lazy Row loading) layers on top of those
 * FOREIGN KEY-compatible bones rather than replacing them. See berlindb/core
 * #193 for the full roadmap.
 *
 * @since 3.1.0
 *
 * @param array|string $args {
 *
 *     Optional. Array or query string of relationship parameters. Default empty.
 *
 *     @type string       $name       Optional constraint name (used only when enforced).
 *     @type string       $type       Relationship type: 'belongs_to' or 'has_many'.
 *     @type list<string> $columns    Local column names that hold the relationship.
 *     @type string       $query      FQCN of the remote Query class.
 *     @type list<string> $references Remote column names this relationship maps to.
 *     @type string       $on_delete  Referential action: RESTRICT, CASCADE, SET NULL, NO ACTION, SET DEFAULT.
 *     @type string       $on_update  Referential action: RESTRICT, CASCADE, SET NULL, NO ACTION, SET DEFAULT.
 *     @type bool         $enforce    Emit real FOREIGN KEY DDL? Default false.
 * }
 *
 * Properties are protected and read through the Magic trait's __get(), so a
 * subclass can override how any value appears externally with a get_{$name}()
 * method. These @property-read tags keep that access typed for static analysis.
 *
 * @property-read string       $name
 * @property-read string       $type
 * @property-read list<string> $columns
 * @property-read string       $query
 * @property-read list<string> $references
 * @property-read string       $on_delete
 * @property-read string       $on_update
 * @property-read bool         $enforce
 * @property-read string       $constraint
 */
class Relationship {

	/**
	 * Use these traits.
	 *
	 * @since 3.1.0
	 */
	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;

	/** Constants *************************************************************/

	/**
	 * Relationship types recognised by BerlinDB.
	 *
	 * Used by sanitize_type() to validate the relationship direction.
	 *
	 * @since 3.1.0
	 * @var   array<int, string>
	 */
	private const TYPES = array( 'belongs_to', 'has_many' );

	/**
	 * Referential actions recognised by MySQL / MariaDB for foreign keys.
	 *
	 * Used by sanitize_referential_action() to validate ON DELETE / ON UPDATE
	 * values before they are interpolated into SQL strings.
	 *
	 * @since 3.1.0
	 * @var   array<int, string>
	 */
	private const REFERENTIAL_ACTIONS = array( 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION', 'SET DEFAULT' );

	/** Attributes ************************************************************/

	/**
	 * Relationship name — the accessor handle this relationship is known by.
	 *
	 * The semantic name (e.g. 'customer', 'orders') used to reach the related
	 * data, and the property a related Row is loaded through (e.g.
	 * $order->customer). Must be unique among a Schema's relationships.
	 *
	 * When not provided, it is derived from the first local column with any
	 * trailing _id / _uuid removed (e.g. customer_id becomes 'customer'). This
	 * naturally disambiguates multiple relationships to the same target (e.g.
	 * created_by_user_id vs assigned_to_user_id).
	 *
	 * Not to be confused with $constraint, the SQL FOREIGN KEY constraint name.
	 *
	 * @since 3.1.0
	 * @var   string Default derived from the first local column.
	 */
	protected $name = '';

	/**
	 * Relationship type.
	 *
	 * - 'belongs_to' — the local columns hold the foreign key, pointing at one
	 *   remote row (many-to-one / one-to-one owning side).
	 * - 'has_many' — the local columns are the referenced key; many remote rows
	 *   point here (one-to-many inverse side).
	 *
	 * @since 3.1.0
	 * @var   string Default 'belongs_to'.
	 */
	protected $type = 'belongs_to';

	/**
	 * Local column names the relationship consists of.
	 *
	 * @since 3.1.0
	 * @var   list<string> Default empty array.
	 */
	protected $columns = array();

	/**
	 * FQCN of the remote Query class this relationship targets.
	 *
	 * The physical remote table name is intentionally not stored here; it can
	 * always be derived from the Query class, while the reverse is not true.
	 *
	 * @since 3.1.0
	 * @var   string Default empty string.
	 */
	protected $query = '';

	/**
	 * Remote column names this relationship maps to.
	 *
	 * Positionally paired with $columns for composite relationships.
	 *
	 * @since 3.1.0
	 * @var   list<string> Default empty array.
	 */
	protected $references = array();

	/**
	 * Referential action on delete of the referenced row.
	 *
	 * @since 3.1.0
	 * @var   string Default empty string.
	 */
	protected $on_delete = '';

	/**
	 * Referential action on update of the referenced row.
	 *
	 * @since 3.1.0
	 * @var   string Default empty string.
	 */
	protected $on_update = '';

	/**
	 * Emit a real FOREIGN KEY constraint in table DDL?
	 *
	 * Off by default, matching WordPress convention. Opt in only when you
	 * control the storage engine.
	 *
	 * @since 3.1.0
	 * @var   bool Default false.
	 */
	protected $enforce = false;

	/**
	 * Optional SQL constraint name, used only when the relationship is enforced.
	 *
	 * When empty, MySQL assigns a constraint name automatically. The Table layer
	 * may derive a stable, table-qualified name at enforce time, since safe
	 * uniqueness requires the local table name this value object does not hold.
	 *
	 * @since 3.1.0
	 * @var   string Default empty string.
	 */
	protected $constraint = '';

	/** Argument validation ***************************************************/

	/**
	 * Normalize and sanitize all arguments passed to Relationship.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $args Array of arguments.
	 *
	 * @return array<string, mixed>
	 */
	protected function validate_args( $args = array() ) {

		// Array of callbacks for specific keys.
		$callbacks = array(
			'name'       => array( $this, 'sanitize_name' ),
			'constraint' => array( $this, 'sanitize_index_name' ),
			'type'       => array( $this, 'sanitize_type' ),
			'columns'    => array( $this, 'sanitize_columns' ),
			'query'      => array( $this, 'sanitize_query_class' ),
			'references' => array( $this, 'sanitize_columns' ),
			'on_delete'  => array( $this, 'sanitize_referential_action' ),
			'on_update'  => array( $this, 'sanitize_referential_action' ),
			'enforce'    => 'wp_validate_boolean',
		);

		// Default return value.
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

	/**
	 * Initialize after arguments are set.
	 *
	 * Derives the accessor name from the first local column when one was not
	 * explicitly provided.
	 *
	 * @since 3.1.0
	 */
	protected function init(): void {

		// Derive the accessor from the first local column when unnamed.
		if ( ( '' === $this->name ) && ! empty( $this->columns ) ) {
			$this->name = $this->derive_name( $this->columns[0] );
		}
	}

	/** Public Helpers ********************************************************/

	/**
	 * Return whether this relationship is enforced via a real FOREIGN KEY.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_enforced() {
		return ( true === $this->enforce );
	}

	/**
	 * Return the FQCN of the remote Query class.
	 *
	 * Consumers (cache priming, parsers, lazy Row loading) operate through this
	 * class to resolve the physical table, columns, and cache group.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_query_class() {
		return $this->query;
	}

	/**
	 * Get the CREATE clause for this relationship.
	 *
	 * Returns an empty string unless the relationship is enforced and emits a
	 * real, owning-side foreign key. The remote table's physical name must be
	 * supplied by the caller, because this value object addresses the remote
	 * side by Query class and does not resolve it to a table on its own.
	 *
	 * @since 3.1.0
	 *
	 * @param string $remote_table Resolved physical name of the referenced table.
	 * @return string
	 */
	public function get_create_string( $remote_table = '' ) {

		// Bail if not enforced — relationships emit no DDL by default.
		if ( false === $this->is_enforced() ) {
			return '';
		}

		// Bail unless this is the owning side; the constraint lives on the
		// table that holds the foreign key.
		if ( 'belongs_to' !== $this->type ) {
			return '';
		}

		// Bail if there is no remote table to reference.
		if ( ! is_string( $remote_table ) || ( '' === $remote_table ) ) {
			return '';
		}

		// Bail if either side is empty, or the two sides do not pair up.
		if (
			empty( $this->columns )
			|| empty( $this->references )
			|| ( count( $this->columns ) !== count( $this->references ) )
		) {
			return '';
		}

		// Back-tick the local and remote column lists.
		$local  = implode( ', ', array_map( array( $this, 'quote_identifier' ), $this->columns ) );
		$remote = implode( ', ', array_map( array( $this, 'quote_identifier' ), $this->references ) );

		// Optionally prefix an explicit constraint name. sanitize_index_name()
		// can yield false for an unnamed constraint, so guard with empty().
		$prefix = ! empty( $this->constraint )
			? 'CONSTRAINT ' . $this->quote_identifier( $this->constraint ) . ' '
			: '';

		// Assemble the FOREIGN KEY clause.
		$sql = $prefix
			. 'FOREIGN KEY (' . $local . ') REFERENCES '
			. $this->quote_identifier( $remote_table ) . ' (' . $remote . ')';

		// Append referential actions when present.
		if ( '' !== $this->on_delete ) {
			$sql .= ' ON DELETE ' . $this->on_delete;
		}

		if ( '' !== $this->on_update ) {
			$sql .= ' ON UPDATE ' . $this->on_update;
		}

		return $sql;
	}

	/** Private Sanitizers ****************************************************/

	/**
	 * Sanitize the relationship type.
	 *
	 * @since 3.1.0
	 *
	 * @param string $type Relationship type.
	 * @return string 'belongs_to' or 'has_many'. Defaults to 'belongs_to'.
	 */
	private function sanitize_type( $type = '' ) {
		return in_array( $type, self::TYPES, true )
			? (string) $type
			: 'belongs_to';
	}

	/**
	 * Sanitize the relationship name (the accessor handle).
	 *
	 * Accessor names follow the same identifier rules as column names, so they
	 * are safe to expose as a Row property during lazy loading.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name Relationship name.
	 * @return string Sanitized name, or empty string on failure.
	 */
	private function sanitize_name( $name = '' ) {

		// Bail if not a string.
		if ( ! is_string( $name ) ) {
			return '';
		}

		// Reuse column-name identifier rules.
		$retval = $this->sanitize_column_name( $name );

		return is_string( $retval )
			? $retval
			: '';
	}

	/**
	 * Derive an accessor name from a local column name.
	 *
	 * Strips a trailing _id or _uuid so a foreign-key column reads as the thing
	 * it points at (e.g. customer_id becomes 'customer').
	 *
	 * @since 3.1.0
	 *
	 * @param string $column Local column name.
	 * @return string
	 */
	private function derive_name( $column = '' ) {
		$base = preg_replace( '/_(id|uuid)$/', '', (string) $column );

		return $this->sanitize_name( is_string( $base ) ? $base : $column );
	}

	/**
	 * Sanitize the remote Query class as a PHP class name.
	 *
	 * Keeps letters, digits, underscores, and namespace separators.
	 *
	 * @since 3.1.0
	 *
	 * @param string $query Remote Query class name.
	 * @return string Sanitized class name, or empty string on failure.
	 */
	private function sanitize_query_class( $query = '' ) {

		// Bail if not a string.
		if ( ! is_string( $query ) ) {
			return '';
		}

		// Strip anything that cannot appear in a PHP class name.
		$retval = preg_replace( '/[^a-zA-Z0-9_\\\\]/', '', $query );

		return is_string( $retval )
			? $retval
			: '';
	}

	/**
	 * Sanitize a list of column names.
	 *
	 * @since 3.1.0
	 *
	 * @param list<string> $columns Array of column names.
	 * @return list<string>
	 */
	private function sanitize_columns( $columns = array() ) {

		// Bail if not an array.
		if ( ! is_array( $columns ) ) {
			return array();
		}

		// Default return value.
		$retval = array();

		// Loop through columns and sanitize each one.
		foreach ( $columns as $column ) {

			// Skip non-string entries.
			if ( ! is_string( $column ) ) {
				continue;
			}

			// Sanitize the column name.
			$name = $this->sanitize_column_name( $column );

			// Only include valid column names.
			if ( is_string( $name ) && ( '' !== $name ) ) {
				$retval[] = $name;
			}
		}

		return $retval;
	}

	/**
	 * Sanitize a referential action (ON DELETE / ON UPDATE).
	 *
	 * @since 3.1.0
	 *
	 * @param string $action Referential action.
	 * @return string Normalized action, or empty string when unrecognized.
	 */
	private function sanitize_referential_action( $action = '' ) {

		// Bail if not a string.
		if ( ! is_string( $action ) ) {
			return '';
		}

		// Normalize whitespace and case.
		$normalized = strtoupper( trim( preg_replace( '/\s+/', ' ', $action ) ?? '' ) );

		return in_array( $normalized, self::REFERENTIAL_ACTIONS, true )
			? $normalized
			: '';
	}
}
