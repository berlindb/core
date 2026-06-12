<?php
/**
 * Meta preset schema generation (#204 Phase A).
 *
 * Presets\Meta\Query::build_schema() composes the key/value EAV meta schema,
 * mirrors the primary key's storage shape into the foreign-key column, and
 * declares a belongs_to back to the primary (resolved by class name). DB-free.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Relationship;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Presets\Meta\Query as MetaQuery;
use PHPUnit\Framework\TestCase;

/** A real primary, so the generated belongs_to passes class_exists() validation. */
class MetaPresetPrimarySchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);
}
class MetaPresetPrimaryQuery extends Query {
	protected $prefix       = 'mp';
	protected $table_name   = 'things';
	protected $table_schema = MetaPresetPrimarySchema::class;
	protected $item_name    = 'thing';
	protected $cache_group  = 'things';
}

/**
 * Tests for Presets\Meta\Query::build_schema().
 *
 * @since 3.1.0
 */
class MetaPresetTest extends TestCase {

	/**
	 * Find a column by name within a schema.
	 *
	 * @since 3.1.0
	 *
	 * @param Schema $schema The schema.
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
	 * Build a meta schema for a bigint-keyed 'thing'.
	 *
	 * @since 3.1.0
	 *
	 * @return Schema
	 */
	private function thing_meta_schema(): Schema {
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

		return MetaQuery::build_schema( $pk, 'thing', MetaPresetPrimaryQuery::class );
	}

	/**
	 * The generated schema has the standard EAV shape and meta type.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_schema_shape() {
		$schema = $this->thing_meta_schema();

		$this->assertSame( 'meta', $schema->get_type() );

		$meta_id = $this->column( $schema, 'meta_id' );
		$this->assertInstanceOf( Column::class, $meta_id );
		$this->assertSame( 'BIGINT', $meta_id->type );
		$this->assertTrue( (bool) $meta_id->primary );

		$meta_key = $this->column( $schema, 'meta_key' );
		$this->assertSame( 'VARCHAR', $meta_key->type );
		$this->assertSame( '191', (string) $meta_key->length );

		$meta_value = $this->column( $schema, 'meta_value' );
		$this->assertSame( 'LONGTEXT', $meta_value->type );
		$this->assertTrue( (bool) $meta_value->allow_null );

		$this->assertTrue( $schema->has_index( 'thing_id' ) );
		$this->assertTrue( $schema->has_index( 'meta_key' ) );
	}

	/**
	 * The foreign key mirrors a bigint primary key's shape, minus its identity.
	 *
	 * @since 3.1.0
	 */
	public function test_foreign_key_mirrors_bigint_pk() {
		$fk = $this->column( $this->thing_meta_schema(), 'thing_id' );

		$this->assertInstanceOf( Column::class, $fk );
		$this->assertSame( 'BIGINT', $fk->type );
		$this->assertSame( '20', (string) $fk->length );
		$this->assertTrue( (bool) $fk->unsigned );
		$this->assertFalse( (bool) $fk->primary );
		$this->assertNotSame( 'AUTO_INCREMENT', $fk->extra );
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

		$schema = MetaQuery::build_schema( $pk, 'thing', MetaPresetPrimaryQuery::class );
		$fk     = $this->column( $schema, 'thing_id' );

		$this->assertInstanceOf( Column::class, $fk );
		$this->assertSame( 'VARCHAR', $fk->type );
		$this->assertSame( '36', (string) $fk->length );
	}

	/**
	 * The schema declares a belongs_to back to the primary and is installable.
	 *
	 * @since 3.1.0
	 */
	public function test_belongs_to_and_installable() {
		$schema = $this->thing_meta_schema();

		$relationships = $schema->get_relationships();
		$this->assertCount( 1, $relationships );

		$rel = $relationships[0];
		$this->assertInstanceOf( Relationship::class, $rel );
		$this->assertSame( 'belongs_to', $rel->type );
		$this->assertSame( 'thing', $rel->name );
		$this->assertSame( MetaPresetPrimaryQuery::class, $rel->get_query_class() );
		$this->assertSame( array( 'thing_id' ), $rel->columns );
		$this->assertSame( array( 'id' ), $rel->references );

		// Valid + installable (the primary class exists).
		$this->assertSame( array(), $schema->get_validation_errors() );
		$this->assertTrue( $schema->is_valid() );
		$this->assertNotSame( '', $schema->get_create_table_string() );
	}
}
