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
 * DDL. The shape, however, is intentionally FOREIGN KEY-compatible - local
 * columns, a referenced table and columns, and ON DELETE / ON UPDATE actions -
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
 *     @type string       $name       Optional accessor handle for this relationship
 *                                    (used by get_related() and relation filters).
 *                                    Derived from the local column when omitted.
 *     @type string       $type       Relationship type: 'belongs_to', 'has_many', or 'many_to_many'.
 *     @type list<string> $columns    Local column names that hold the relationship.
 *     @type string       $query      FQCN of the remote (target) Query class.
 *     @type list<string> $references Remote column names this relationship maps to.
 *     @type string       $through            FQCN of the pivot Query class (many_to_many only).
 *     @type list<string> $through_columns    Pivot columns referencing this table (many_to_many hop 1).
 *     @type list<string> $through_references Pivot columns referencing the target (many_to_many hop 2).
 *     @type string       $constraint Optional SQL foreign-key constraint name
 *                                    (used only when enforced).
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
 * @property-read string       $through
 * @property-read list<string> $through_columns
 * @property-read list<string> $through_references
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
	 * Relationship types recognized by BerlinDB.
	 *
	 * Used by sanitize_type() to validate the relationship direction.
	 *
	 * @since 3.1.0
	 * @var   array<int,string>
	 */
	private const TYPES = array( 'belongs_to', 'has_many', 'many_to_many' );

	/**
	 * Referential actions recognized by MySQL / MariaDB for foreign keys.
	 *
	 * Used by sanitize_referential_action() to validate ON DELETE / ON UPDATE
	 * values before they are interpolated into SQL strings.
	 *
	 * @since 3.1.0
	 * @var   array<int,string>
	 */
	private const REFERENTIAL_ACTIONS = array( 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION', 'SET DEFAULT' );

	/** Attributes ************************************************************/

	/**
	 * Relationship name - the accessor handle this relationship is known by.
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
	 * - 'belongs_to' - the local columns hold the foreign key, pointing at one
	 *   remote row (many-to-one / one-to-one owning side).
	 * - 'has_many' - the local columns are the referenced key; many remote rows
	 *   point here (one-to-many inverse side).
	 * - 'many_to_many' - two hops through a pivot table ($through): this table's
	 *   $columns match the pivot's $through_columns, whose $through_references
	 *   match the target's $references. Inferred from a non-empty $through.
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
	 * FQCN of the pivot (junction) Query class, for a many_to_many relationship.
	 *
	 * The intermediate table that carries both foreign keys. A many_to_many is two
	 * hops: this table -> pivot ($columns = $through_columns) -> target
	 * ($through_references = $references). Empty for single-hop belongs_to / has_many.
	 *
	 * @since 3.1.0
	 * @var   string Default empty string.
	 */
	protected $through = '';

	/**
	 * Pivot column names that reference this table (hop 1 of a many_to_many).
	 *
	 * Positionally paired with $columns: a pivot row matches when each
	 * through_column equals the local column it pairs with.
	 *
	 * @since 3.1.0
	 * @var   list<string> Default empty array.
	 */
	protected $through_columns = array();

	/**
	 * Pivot column names that reference the target table (hop 2 of a many_to_many).
	 *
	 * Positionally paired with $references: a target row matches when each remote
	 * column equals the through_reference it pairs with.
	 *
	 * @since 3.1.0
	 * @var   list<string> Default empty array.
	 */
	protected $through_references = array();

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
	 * Off by default, matching WordPress convention. Opt in only when you control the
	 * storage engine.
	 *
	 * Enforcing declares intent; it does NOT create the constraint automatically.
	 * BerlinDB installs tables independently and in no set order, so the key is not
	 * put in CREATE TABLE by default (the referenced table may not exist yet). Create
	 * the constraint by calling Table::add_foreign_keys() once every referenced table
	 * is installed - or set the referencing table's $foreign_keys to 'inline' if you
	 * control install order and want it in CREATE TABLE.
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

	/**
	 * A fixed equality predicate that scopes the related rows, as column => value.
	 *
	 * Models a polymorphic / discriminated ownership pattern - a remote table with an
	 * object_id + object_type pair pointing at different parent types - as ONE
	 * relationship. The condition is appended to every SQL form of the relationship
	 * (get_related() traversal, priming, and the in / join / EXISTS filters, plus the
	 * store-meta reuse) as `AND {remote}.{column} = {value}`, so only the matching rows
	 * are traversed or matched. Example: `array( 'object_type' => 'order' )`.
	 *
	 * A conditioned relationship is application-layer only: a SQL FOREIGN KEY cannot
	 * encode a discriminator, so it is never enforced - init() drops any `enforce`
	 * (see is_foreign_key()). Supported on belongs_to / has_many (single-hop and nested);
	 * a condition on a many_to_many is rejected (get_validation_errors()). For the
	 * query-var forms (get_related() traversal and the `in` filter strategy) a condition
	 * column must be queryable on the remote (declare `in => true`); the join / EXISTS
	 * paths render raw SQL and need no flag. An unknown condition column fails closed
	 * everywhere. Equality-with-scalar-values in this version; richer predicates
	 * (operators, IN) can extend the shape later without breaking it.
	 *
	 * @since 3.1.0
	 * @var   array<string,scalar> Default empty array.
	 */
	protected $condition = array();

	/** Argument validation ***************************************************/

	/**
	 * Sanitization callbacks for a Relationship's configuration arguments.
	 *
	 * Applied by validate_args() (Traits\Configuration) during construction.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed> Map of config key => sanitization callback.
	 */
	protected function get_config_callbacks(): array {
		return array(
			'name'               => array( $this, 'sanitize_name' ),
			'constraint'         => array( $this, 'sanitize_index_name' ),
			'type'               => array( $this, 'sanitize_type' ),
			'columns'            => array( $this, 'sanitize_columns' ),
			'query'              => array( $this, 'sanitize_class_name' ),
			'references'         => array( $this, 'sanitize_columns' ),
			'through'            => array( $this, 'sanitize_class_name' ),
			'through_columns'    => array( $this, 'sanitize_columns' ),
			'through_references' => array( $this, 'sanitize_columns' ),
			'on_delete'          => array( $this, 'sanitize_referential_action' ),
			'on_update'          => array( $this, 'sanitize_referential_action' ),
			'enforce'            => array( $this, 'sanitize_boolean' ),
			'condition'          => array( $this, 'sanitize_condition' ),
		);
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

		/*
		 * A pivot (through) class is exclusive to many_to_many, so its presence
		 * settles the type - the shape is enough, no need to also state
		 * type => 'many_to_many'. An explicit type still works, and this shape
		 * signal is authoritative: it also overrides a present-but-rejected type
		 * (sanitize_type()'s ''), so a valid pivot is an unambiguous many_to_many
		 * with no unknown-type error - the type string is redundant here.
		 */
		if ( '' !== $this->through ) {
			$this->type = 'many_to_many';
		}

		// Derive the accessor from the first local column when unnamed.
		if ( ( '' === $this->name ) && ! empty( $this->columns ) ) {
			$this->name = $this->derive_name( $this->columns[0] );
		}

		/*
		 * A conditioned relationship models a discriminated (object_id + object_type)
		 * link that a SQL FOREIGN KEY cannot express, so it is application-layer only:
		 * drop any enforce, so is_enforced()/is_foreign_key() are false and no invalid
		 * FK DDL is ever emitted for it.
		 */
		if ( ( true === $this->enforce ) && ( array() !== $this->condition ) ) {
			$this->enforce = false;
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
	 * Return whether this relationship emits a real FOREIGN KEY constraint.
	 *
	 * True only for an enforced, owning-side (belongs_to) relationship - the same
	 * conditions under which get_create_string() renders a fragment. A has_many or
	 * an application-level (non-enforced) relationship emits none.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_foreign_key(): bool {
		return $this->is_enforced() && ( 'belongs_to' === $this->type );
	}

	/**
	 * Return whether this relationship carries a fixed condition (scoped / polymorphic).
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function has_condition(): bool {
		return array() !== $this->condition;
	}

	/**
	 * Return the fixed equality predicate that scopes the related rows.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,scalar> Map of column => value (empty when unconditioned).
	 */
	public function get_condition(): array {
		return $this->condition;
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
	 * Return validation errors for this relationship's own shape.
	 *
	 * Only the checks this value object can make in isolation - it has no owning
	 * Schema, so local-column existence is validated by
	 * Schema::get_validation_errors(), and remote resolution / remote-column
	 * existence by Query::get_relationship_errors(). Checks here:
	 * - Declares an unknown type (a present value sanitize_type() rejected to '').
	 * - Declares no local columns.
	 * - Missing remote query class.
	 * - Declares no remote columns (references).
	 * - Local/remote column count mismatch (composite columns pair positionally).
	 *
	 * @since 3.1.0
	 *
	 * @return string[] Array of human-readable error strings. Empty if valid.
	 */
	public function get_validation_errors() {
		$errors = array();

		// A stable label for messages: the accessor name, or a placeholder.
		$label = ( '' !== $this->name )
			? $this->name
			: '(unnamed)';

		// A present but unrecognized type was rejected to '' by sanitize_type().
		if ( ! in_array( $this->type, self::TYPES, true ) ) {
			$errors[] = "Relationship {$label} declares an unknown type.";
		}

		// Must declare at least one local column.
		if ( empty( $this->columns ) ) {
			$errors[] = "Relationship {$label} declares no local columns.";
		}

		// Must target a remote query class.
		if ( '' === $this->query ) {
			$errors[] = "Relationship {$label} is missing a remote query class.";
		}

		// Must map to at least one remote column, or it addresses nothing.
		if ( empty( $this->references ) ) {
			$errors[] = "Relationship {$label} declares no remote columns.";
		}

		/*
		 * Local and remote columns pair up positionally, so a single-hop
		 * relationship must list the same number of columns on each side. A
		 * many_to_many is excluded: its arities are per-hop (columns pair with
		 * through_columns, through_references with references), checked in
		 * get_many_to_many_errors() - columns and references need not match.
		 */
		if (
			( 'many_to_many' !== $this->type )
			&& ! empty( $this->columns )
			&& ! empty( $this->references )
			&& ( count( $this->columns ) !== count( $this->references ) )
		) {
			$errors[] = "Relationship {$label} has mismatched local and remote column counts.";
		}

		// A many_to_many adds a second hop through a pivot table; validate it.
		if ( 'many_to_many' === $this->type ) {
			$errors = array_merge( $errors, $this->get_many_to_many_errors( $label ) );

			/*
			 * A condition on a many_to_many is unsupported in this version (traversal and
			 * filtering fail closed); surface it rather than let it silently do nothing.
			 */
			if ( array() !== $this->condition ) {
				$errors[] = "Relationship {$label} declares a condition on a many_to_many, which is not supported.";
			}
		}

		return $errors;
	}

	/**
	 * Return validation errors specific to a many_to_many relationship's pivot hop.
	 *
	 * A many_to_many is two hops - this table -> pivot -> target - so beyond the
	 * base checks (local columns, target query, references) it must also declare the
	 * pivot query and the two pivot column sets, each pairing positionally with the
	 * hop it belongs to: through_columns with local columns (hop 1), through_references
	 * with remote references (hop 2).
	 *
	 * @since 3.1.0
	 *
	 * @param string $label Stable message label (the accessor name or placeholder).
	 * @return string[] Array of human-readable error strings. Empty if valid.
	 */
	private function get_many_to_many_errors( string $label ): array {
		$errors = array();

		// Must target a pivot (through) query class.
		if ( '' === $this->through ) {
			$errors[] = "Relationship {$label} is a many_to_many but is missing a pivot (through) query class.";
		}

		// Must map the pivot columns that reference this table (hop 1).
		if ( empty( $this->through_columns ) ) {
			$errors[] = "Relationship {$label} is a many_to_many but declares no through_columns.";
		}

		// Must map the pivot columns that reference the target table (hop 2).
		if ( empty( $this->through_references ) ) {
			$errors[] = "Relationship {$label} is a many_to_many but declares no through_references.";
		}

		// Hop 1: local columns pair positionally with the pivot's local-referencing columns.
		if (
			! empty( $this->columns )
			&& ! empty( $this->through_columns )
			&& ( count( $this->columns ) !== count( $this->through_columns ) )
		) {
			$errors[] = "Relationship {$label} has mismatched local and through_columns counts.";
		}

		// Hop 2: the pivot's target-referencing columns pair positionally with the remote columns.
		if (
			! empty( $this->through_references )
			&& ! empty( $this->references )
			&& ( count( $this->through_references ) !== count( $this->references ) )
		) {
			$errors[] = "Relationship {$label} has mismatched through_references and remote column counts.";
		}

		return $errors;
	}

	/**
	 * Get the CREATE clause for this relationship.
	 *
	 * Returns an empty string unless the relationship is enforced and emits a
	 * real, owning-side foreign key. The remote table's physical name must be
	 * supplied by the caller, because this value object addresses the remote
	 * side by Query class and does not resolve it to a table on its own.
	 *
	 * Future-ready, not yet wired: Schema::get_create_table_string() does NOT
	 * call this, so enforced foreign keys are not emitted during table install
	 * (see that method's docblock for why). This renders the fragment for when
	 * DDL emission is added; relationships are application-enforced for now.
	 *
	 * @since 3.1.0
	 *
	 * @param string $remote_table Resolved physical name of the referenced table.
	 * @return string
	 */
	public function get_create_string( $remote_table = '' ) {

		// Bail if not enforced - relationships emit no DDL by default.
		if ( false === $this->is_enforced() ) {
			return '';
		}

		/*
		 * Bail unless this is the owning side; the constraint lives on the
		 * table that holds the foreign key.
		 */
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

		/*
		 * Optionally prefix an explicit constraint name. sanitize_index_name()
		 * can yield false for an unnamed constraint, so guard with empty().
		 */
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
	 * @return string A recognized type, or '' when a present value is unrecognized.
	 */
	private function sanitize_type( $type = '' ): string {

		/*
		 * Reject-not-mutate, to match Column::sanitize_relationships(): a present
		 * but unrecognized type resolves to '' (flagged by get_validation_errors())
		 * rather than being silently coerced to belongs_to (#206). An OMITTED type
		 * never reaches here - validate_args() only runs this callback for a supplied
		 * key, so the property default ('belongs_to') stands - and a set $through
		 * still infers many_to_many in init(), which runs after this.
		 */
		return in_array( $type, self::TYPES, true )
			? (string) $type
			: '';
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
	private function sanitize_name( $name = '' ): string {

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
	private function derive_name( $column = '' ): string {
		$base = preg_replace( '/_(id|uuid)$/', '', (string) $column );

		// preg_replace() returns null on error; fall back to the original column.
		$name = is_string( $base ) ? $base : $column;

		return $this->sanitize_name( $name );
	}

	/**
	 * Sanitize a list of column names.
	 *
	 * @since 3.1.0
	 *
	 * @param list<string> $columns Array of column names.
	 * @return list<string>
	 */
	private function sanitize_columns( $columns = array() ): array {

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
	 * Sanitize a relationship condition into a clean `column => scalar` equality map.
	 *
	 * Each key must sanitize to a valid column name (it renders into SQL as an
	 * identifier) and each value must be a scalar (it is escaped via prepare() at render
	 * time). Anything else is dropped, so a malformed entry cannot reach SQL. This
	 * version accepts equality with scalar values only.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $condition The raw condition config.
	 * @return array<string,scalar> Map of column => value.
	 */
	private function sanitize_condition( $condition = array() ): array {

		// Bail if not an array.
		if ( ! is_array( $condition ) ) {
			return array();
		}

		// Default return value.
		$retval = array();

		// Keep only valid column => scalar pairs (a fixed equality predicate).
		foreach ( $condition as $column => $value ) {

			// A condition column must sanitize to a real column name.
			$name = is_string( $column )
				? $this->sanitize_column_name( $column )
				: '';

			// The value is a fixed scalar; prepare() escapes it at render time.
			if ( is_string( $name ) && ( '' !== $name ) && is_scalar( $value ) ) {
				$retval[ $name ] = $value;
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
	private function sanitize_referential_action( $action = '' ): string {

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
