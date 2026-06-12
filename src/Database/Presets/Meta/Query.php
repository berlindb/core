<?php
/**
 * Meta preset: base Query for a key/value meta sibling table.
 *
 * @package     Database
 * @subpackage  Presets
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Presets\Meta;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Query as KernQuery;
use BerlinDB\Database\Kern\Schema;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base Query for a WordPress-style key/value meta sibling table (#204 Phase A).
 *
 * Extend it with a one-line stub that names its primary object's Query:
 *
 *     class Order_Meta extends \BerlinDB\Database\Presets\Meta\Query {
 *         protected $primary = Order::class;
 *     }
 *
 * The primary declares the matching has_many in its own schema, on its primary
 * key column — an ordinary relationship like any other:
 *
 *     'relationships' => array(
 *         array(
 *             'query'  => Order_Meta::class,
 *             'column' => 'order_id',
 *             'type'   => 'has_many',
 *             'name'   => 'meta',
 *         ),
 *     ),
 *
 * From the primary, the base derives its table identity ({object}_meta) and
 * generates the EAV schema — meta_id, {object}_id (mirroring the primary key's
 * shape), meta_key, meta_value — plus a belongs_to back to the primary.
 *
 * Because both the primary and the meta sibling are real classes, the two
 * relationships resolve by class name through the ordinary relationship engine —
 * no instance binding, no registry, and no preset knowledge in any Kern class.
 * Meta is "just relationships."
 *
 * @since 3.1.0
 */
class Query extends KernQuery {

	/**
	 * FQCN of the primary object's Query class.
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	protected $primary = '';

	/**
	 * Derive identity and schema from the primary before normal setup.
	 *
	 * @since 3.1.0
	 */
	protected function init(): void {
		$this->configure_from_primary();

		parent::init();
	}

	/**
	 * Configure this meta query's identity and schema from its primary.
	 *
	 * Instantiating the primary is one-directional and terminates: the primary's
	 * own init() only adds a has_many naming THIS class (a class string, not an
	 * instance), so it never constructs a meta query back.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	private function configure_from_primary(): void {

		// Bail (loudly) unless a primary Query class is named and exists.
		if ( ( '' === $this->primary ) || ! class_exists( $this->primary ) ) {
			$this->log(
				'warning',
				'meta_primary_missing',
				'Meta query names no usable primary Query class; not configured.',
				array( 'primary' => $this->primary )
			);

			return;
		}

		// Bail (loudly) unless the primary is a sibling Query.
		$primary = new $this->primary();
		if ( ! ( $primary instanceof KernQuery ) ) {
			$this->log(
				'warning',
				'meta_primary_not_a_query',
				'Meta query primary class is not a Query; not configured.',
				array( 'primary' => $this->primary )
			);

			return;
		}

		// Bail (loudly) unless the primary has a primary-key column to relate to.
		$primary_key = $primary->get_column_by( array( 'primary' => true ) );
		if ( ! ( $primary_key instanceof Column ) ) {
			$this->log(
				'warning',
				'meta_primary_key_missing',
				'Meta query primary has no primary-key column; not configured.',
				array( 'primary' => $this->primary )
			);

			return;
		}

		// Derive identity from the primary's singular name (e.g. 'order' -> 'order_meta').
		$object = $primary->get_item_name();
		$name   = "{$object}_meta";

		// Late static binding, so a stub may override build_schema() to customize.
		$this->prefix           = $primary->get_prefix();
		$this->table_name       = $name;
		$this->item_name        = $name;
		$this->item_name_plural = $name;
		$this->cache_group      = $name;
		$this->table_schema     = static::build_schema( $primary_key, $object, $this->primary );
	}

	/**
	 * Build the meta EAV schema for a primary object.
	 *
	 * Standard WordPress-style key/value shape:
	 *   meta_id      bigint unsigned PK auto_increment
	 *   {object}_id  the foreign key, mirroring the primary key's column shape
	 *   meta_key     varchar(191)  (full-column index; under the utf8mb4 limit)
	 *   meta_value   longtext, nullable
	 * with named indexes on {object}_id and meta_key, and a belongs_to back to the
	 * primary declared on the foreign-key column (resolved by class name).
	 *
	 * The foreign key copies the primary key's storage shape (type/length/unsigned/
	 * etc.) so values and relationship comparisons line up for any key type — but
	 * never its primary/auto_increment identity.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $primary_key         The primary object's primary-key column.
	 * @param string $object_name         The primary object's singular name (e.g. 'order').
	 * @param string $primary_query_class FQCN of the primary object's Query class.
	 * @return Schema
	 */
	public static function build_schema( Column $primary_key, string $object_name, string $primary_query_class ): Schema {

		// Normalize defensively; the primary feeds an already-sanitized item name.
		$object_name = self::normalize_name( $object_name );
		$fk_name     = "{$object_name}_id";

		// The foreign key: the primary key's shape + a belongs_to back to it.
		$foreign_key = array_merge(
			self::mirror_key_shape( $primary_key ),
			array(
				'name'          => $fk_name,
				'relationships' => array(
					array(
						'query'  => $primary_query_class,
						'column' => $primary_key->name,
						'type'   => 'belongs_to',
						'name'   => $object_name,
					),
				),
			)
		);

		return new Schema(
			array(
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
	 * @since 3.1.0
	 *
	 * @param string $name The object name.
	 * @return string
	 */
	private static function normalize_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		$name = (string) preg_replace( '/[^a-z0-9_]/', '', $name );

		return ( '' !== $name )
			? $name
			: 'object';
	}
}
