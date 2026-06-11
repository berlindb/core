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

		return (string) preg_replace( '/[^a-z0-9_]/', '', $name );
	}
}
