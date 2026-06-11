<?php
/**
 * Meta Preset.
 *
 * @package     Database
 * @subpackage  Presets
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Presets;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Generates the sibling pieces that give a primary object WordPress-style meta
 * (#204 Phase A).
 *
 * A Schema opts in with supports( 'meta' ); the owning Query/Table — which have the
 * full context this Schema deliberately lacks — ask the preset to compose the meta
 * sibling. This class is the single source of those generated pieces and of the
 * naming derived from the primary.
 *
 * This first slice builds the meta Schema (the trickiest, context-free part: the
 * key/value EAV shape with the foreign key mirrored from the primary key). The
 * registry, the generated Table/Query/Row, and CRUD routing layer on top.
 *
 * The schema carries NO relationships. Both sides — primary has_many meta and meta
 * belongs_to primary — are composed at the Query level as BOUND relationships
 * (the owning Query binds the resolved counterpart instance), because either side
 * may be a config-constructed base Query with no resolvable class name. Keeping the
 * generated schema relationship-free also keeps it installable: a class-name
 * relationship would fail validation (class_exists()) for a base-instance primary.
 *
 * @since 3.1.0
 */
class Meta {

	/**
	 * Per-primary preset registry, keyed by the primary's prefixed table name.
	 *
	 * Memoizes one preset (and therefore one generated meta sibling) per primary
	 * table, so repeated Query construction reuses the same generated pieces rather
	 * than rebuilding them.
	 *
	 * @since 3.1.0
	 * @var   array<string, self>
	 */
	private static $registry = array();

	/**
	 * The primary's primary-key column (for FK shape + relationship references).
	 *
	 * @since 3.1.0
	 * @var   Column
	 */
	private $primary_key;

	/**
	 * The primary's singular object name (e.g. 'order').
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	private $object_name;

	/**
	 * The primary's plugin prefix, applied to the generated meta sibling.
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	private $prefix;

	/**
	 * The generated meta Query, built once on demand.
	 *
	 * @since 3.1.0
	 * @var   Query|null
	 */
	private $meta_query = null;

	/**
	 * Whether the meta Query is currently being built (circular-construction guard).
	 *
	 * @since 3.1.0
	 * @var   bool
	 */
	private $building = false;

	/**
	 * Whether the meta->primary belongs_to has been composed onto the meta Query.
	 *
	 * The meta Query is shared across all primary instances of this table, so its
	 * inverse relationship is composed exactly once (by the first primary to wire).
	 *
	 * @since 3.1.0
	 * @var   bool
	 */
	private $belongs_to_composed = false;

	/**
	 * Private constructor; instances come from for_query().
	 *
	 * @since 3.1.0
	 *
	 * @param Column $primary_key The primary key column.
	 * @param string $object_name The primary's singular object name.
	 * @param string $prefix      The primary's plugin prefix.
	 */
	private function __construct( Column $primary_key, string $object_name, string $prefix ) {
		$this->primary_key = $primary_key;
		$this->object_name = self::normalize_name( $object_name );
		$this->prefix      = $prefix;
	}

	/**
	 * Resolve (memoized) the meta preset for a primary Query.
	 *
	 * Returns null when the primary has no primary-key column (nothing to relate a
	 * meta row to). Keyed by the primary's prefixed table name, so every Query of
	 * the same table shares one preset and one generated meta sibling.
	 *
	 * @since 3.1.0
	 *
	 * @param Query $primary The primary object's Query.
	 * @return self|null
	 */
	public static function for_query( Query $primary ): ?self {

		// Bail without a primary key — a meta row has nothing to point at.
		$primary_key = $primary->get_column_by( array( 'primary' => true ) );
		if ( ! ( $primary_key instanceof Column ) ) {
			return null;
		}

		// Memoize one preset per primary table identity.
		$key = $primary->get_table_name();
		if ( ! isset( self::$registry[ $key ] ) ) {
			self::$registry[ $key ] = new self(
				$primary_key,
				$primary->get_item_name(),
				$primary->get_prefix()
			);
		}

		return self::$registry[ $key ];
	}

	/**
	 * The singular object name this preset derives its naming from.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_object_name(): string {
		return $this->object_name;
	}

	/**
	 * Build (once) and return the generated meta Query.
	 *
	 * The meta Query is a base Query configured from the generated meta schema, so
	 * it has no resolvable class name — which is exactly why both relationship sides
	 * are bound rather than class-resolved. Memoized for idempotence; the building
	 * flag turns an unexpected re-entry into a clear error rather than a loop.
	 *
	 * @since 3.1.0
	 *
	 * @return Query
	 */
	public function get_meta_query(): Query {

		// Return the already-built meta query.
		if ( $this->meta_query instanceof Query ) {
			return $this->meta_query;
		}

		// Guard against re-entry while building (should not happen: the meta schema
		// is type 'meta' and does not support 'meta', so it does not re-trigger).
		if ( $this->building ) {
			throw new \LogicException( "Circular meta preset construction for '{$this->object_name}'." );
		}

		$this->building = true;

		// Compose the meta schema and a base Query around it.
		$schema = self::build_meta_schema( $this->primary_key, $this->object_name );
		$name   = "{$this->object_name}_meta";

		$this->meta_query = new Query(
			array(
				'table_schema'     => $schema,
				'table_name'       => $name,
				'item_name'        => $name,
				'item_name_plural' => $name,
				'cache_group'      => $name,
				'prefix'           => $this->prefix,
			)
		);

		$this->building = false;

		return $this->meta_query;
	}

	/**
	 * Whether the meta->primary inverse relationship has been composed yet.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_inverse_composed(): bool {
		return $this->belongs_to_composed;
	}

	/**
	 * Mark the meta->primary inverse relationship as composed (once-only).
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	public function mark_inverse_composed(): void {
		$this->belongs_to_composed = true;
	}

	/**
	 * Build the meta sibling Schema for a primary object.
	 *
	 * Produces the standard WordPress-style key/value EAV shape:
	 *   meta_id      bigint unsigned PK auto_increment
	 *   {object}_id  the foreign key, mirroring the primary key's column shape
	 *   meta_key     varchar(191)  (full-column index; under the utf8mb4 limit)
	 *   meta_value   longtext, nullable
	 * plus indexes on {object}_id and meta_key. Relationships are NOT declared here
	 * (see the class docblock) — they are composed, bound, at the Query level.
	 *
	 * The foreign key copies the primary key's storage shape (type/length/unsigned/
	 * etc.) so values and relationship comparisons line up for any key type
	 * (bigint, UUID/varchar, …) — but never the primary key's primary/auto_increment
	 * identity.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $primary_key The primary object's primary-key column.
	 * @param string $object_name The primary object's singular name (e.g. 'order').
	 *                            Expected pre-sanitized (the preset feeds item_name);
	 *                            normalized here defensively.
	 * @return Schema
	 */
	public static function build_meta_schema( Column $primary_key, string $object_name ): Schema {

		// Normalize defensively; the preset feeds an already-sanitized item name.
		$object_name = self::normalize_name( $object_name );

		// Foreign-key column name (e.g. 'order_id').
		$fk_name = "{$object_name}_id";

		// The foreign key: the primary key's storage shape, under the FK name.
		$foreign_key = array_merge(
			self::mirror_key_shape( $primary_key ),
			array( 'name' => $fk_name )
		);

		// Compose the meta schema (columns + indexes only; no relationships).
		return new Schema(
			array(
				'type'    => 'meta',
				'columns' => array(
					array(
						'name'     => 'meta_id',
						'type'     => 'bigint',
						'length'   => '20',
						'unsigned' => true,
						'primary'  => true,
						'extra'    => 'auto_increment',
					),
					$foreign_key,
					array(
						'name'   => 'meta_key',
						'type'   => 'varchar',
						'length' => '191',
					),
					array(
						'name'       => 'meta_value',
						'type'       => 'longtext',
						'allow_null' => true,
					),
				),
				'indexes' => array(
					array(
						'name'    => $fk_name,
						'columns' => array( $fk_name ),
					),
					array(
						'name'    => 'meta_key',
						'columns' => array( 'meta_key' ),
					),
				),
			)
		);
	}

	/**
	 * Copy a column's storage shape (not its identity) for use as a foreign key.
	 *
	 * Mirrors the fields that make two columns comparable for storage and joins —
	 * never primary/auto_increment/unique semantics, which belong only to the key
	 * itself. The foreign key is non-null (0 means "no relation").
	 *
	 * @since 3.1.0
	 *
	 * @param Column $column The column whose shape to copy (the primary key).
	 * @return array<string, mixed>
	 */
	private static function mirror_key_shape( Column $column ): array {
		return array(
			'type'       => $column->type,
			'length'     => $column->length,
			'unsigned'   => $column->unsigned,
			'zerofill'   => $column->zerofill,
			'binary'     => $column->binary,
			'pattern'    => $column->pattern,
			'cast'       => $column->cast,
			'encoding'   => $column->encoding,
			'collation'  => $column->collation,
			'allow_null' => false,
		);
	}

	/**
	 * Normalize an object name to a safe identifier fragment.
	 *
	 * Lower-cases and strips anything but a-z, 0-9, and underscore, so deriving
	 * "{object}_id" cannot yield surprising column names. The preset feeds an
	 * already-sanitized item name; this is a defensive backstop.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name The object name.
	 * @return string
	 */
	private static function normalize_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		$name = (string) preg_replace( '/[^a-z0-9_]/', '', $name );

		// Fall back rather than derive a bare '_id' (which would collide with 'id').
		return ( '' !== $name )
			? $name
			: 'object';
	}
}
