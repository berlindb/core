<?php
/**
 * Meta preset schema generation (#204 Phase A).
 *
 * Presets\Meta::build_meta_schema() composes the key/value EAV meta schema and
 * mirrors the primary key's storage shape into the foreign-key column — the
 * context-free, DB-free core of the preset. It declares NO relationships (both
 * sides are composed, bound, at the Query level), so the schema is installable on
 * its own. Registry/wiring/CRUD layer on top.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Presets\Meta;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Presets\Meta::build_meta_schema().
 *
 * @since 3.1.0
 */
class MetaPresetTest extends TestCase {

	/**
	 * Find a column by name within a schema.
	 *
	 * @since 3.1.0
	 *
	 * @param Schema $schema The schema to search.
	 * @param string $name   The column name.
	 * @return Column|null
	 */
	private function column( Schema $schema, string $name ): ?Column {
		foreach ( $schema->get_columns() as $column ) {
			if ( $column->name === $name ) {
				return $column;
			}
		}

		return null;
	}

	/**
	 * Build a meta schema for a bigint-keyed 'order'.
	 *
	 * @since 3.1.0
	 *
	 * @return Schema
	 */
	private function order_meta_schema(): Schema {
		$pk = new Column(
			array(
				'name'     => 'id',
				'type'     => 'bigint',
				'length'   => '20',
				'unsigned' => true,
				'primary'  => true,
				'extra'    => 'auto_increment',
			)
		);

		return Meta::build_meta_schema( $pk, 'order' );
	}

	/**
	 * The generated schema has the standard EAV shape and meta type.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_schema_shape() {
		$schema = $this->order_meta_schema();

		$this->assertSame( 'meta', $schema->get_type() );

		// meta_id: bigint unsigned PK auto_increment (Column upper-cases the type).
		$meta_id = $this->column( $schema, 'meta_id' );
		$this->assertInstanceOf( Column::class, $meta_id );
		$this->assertSame( 'BIGINT', $meta_id->type );
		$this->assertTrue( (bool) $meta_id->primary );
		$this->assertSame( 'AUTO_INCREMENT', $meta_id->extra );

		// meta_key varchar(191), meta_value longtext nullable.
		$meta_key = $this->column( $schema, 'meta_key' );
		$this->assertSame( 'VARCHAR', $meta_key->type );
		$this->assertSame( '191', (string) $meta_key->length );

		$meta_value = $this->column( $schema, 'meta_value' );
		$this->assertSame( 'LONGTEXT', $meta_value->type );
		$this->assertTrue( (bool) $meta_value->allow_null );
	}

	/**
	 * The schema declares no relationships and is valid + installable on its own.
	 *
	 * @since 3.1.0
	 */
	public function test_schema_is_valid_and_installable() {
		$schema = $this->order_meta_schema();

		$this->assertSame( array(), $schema->get_relationships() );
		$this->assertSame( array(), $schema->get_validation_errors() );
		$this->assertTrue( $schema->is_valid() );
		$this->assertNotSame( '', $schema->get_create_table_string() );

		// The named indexes are part of the preset's locked-down public shape.
		$this->assertTrue( $schema->has_index( 'order_id' ) );
		$this->assertTrue( $schema->has_index( 'meta_key' ) );
	}

	/**
	 * An empty object name falls back rather than deriving a bare '_id' column.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_object_name_falls_back() {
		$pk = new Column(
			array(
				'name'    => 'id',
				'type'    => 'bigint',
				'primary' => true,
			)
		);

		$schema = Meta::build_meta_schema( $pk, '   ' );

		$this->assertInstanceOf( Column::class, $this->column( $schema, 'object_id' ) );
		$this->assertNull( $this->column( $schema, '_id' ) );
	}

	/**
	 * The foreign key mirrors a bigint primary key's shape, minus its identity.
	 *
	 * @since 3.1.0
	 */
	public function test_foreign_key_mirrors_bigint_pk() {
		$schema = $this->order_meta_schema();

		$fk = $this->column( $schema, 'order_id' );
		$this->assertInstanceOf( Column::class, $fk );
		$this->assertSame( 'BIGINT', $fk->type );
		$this->assertSame( '20', (string) $fk->length );
		$this->assertTrue( (bool) $fk->unsigned );

		// Shape only — never the primary key's identity.
		$this->assertFalse( (bool) $fk->primary );
		$this->assertNotSame( 'AUTO_INCREMENT', $fk->extra );
		$this->assertFalse( (bool) $fk->allow_null );
	}

	/**
	 * The foreign key mirrors a varchar/UUID primary key's shape (not hardcoded).
	 *
	 * @since 3.1.0
	 */
	public function test_foreign_key_mirrors_varchar_pk() {
		$pk = new Column(
			array(
				'name'    => 'id',
				'type'    => 'varchar',
				'length'  => '36',
				'primary' => true,
			)
		);

		$schema = Meta::build_meta_schema( $pk, 'thing' );

		$fk = $this->column( $schema, 'thing_id' );
		$this->assertInstanceOf( Column::class, $fk );
		$this->assertSame( 'VARCHAR', $fk->type );
		$this->assertSame( '36', (string) $fk->length );
		$this->assertFalse( (bool) $fk->primary );
	}
}
