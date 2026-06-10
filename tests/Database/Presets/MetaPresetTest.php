<?php
/**
 * Meta preset schema generation (#204 Phase A).
 *
 * Presets\Meta::build_meta_schema() composes the key/value EAV meta schema and
 * mirrors the primary key's storage shape into the foreign-key column — the
 * context-free, DB-free core of the preset. Registry/wiring/CRUD layer on top.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Relationship;
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
	 * The generated schema has the standard EAV shape and meta type.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_schema_shape() {
		$pk     = new Column(
			array(
				'name'     => 'id',
				'type'     => 'bigint',
				'length'   => '20',
				'unsigned' => true,
				'primary'  => true,
				'extra'    => 'auto_increment',
			)
		);
		$schema = Meta::build_meta_schema( $pk, 'order', 'BerlinDB\\Tests\\OrderQueryFixture' );

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
	 * The foreign key mirrors a bigint primary key's shape, minus its identity.
	 *
	 * @since 3.1.0
	 */
	public function test_foreign_key_mirrors_bigint_pk() {
		$pk     = new Column(
			array(
				'name'     => 'id',
				'type'     => 'bigint',
				'length'   => '20',
				'unsigned' => true,
				'primary'  => true,
				'extra'    => 'auto_increment',
			)
		);
		$schema = Meta::build_meta_schema( $pk, 'order', 'BerlinDB\\Tests\\OrderQueryFixture' );

		$fk = $this->column( $schema, 'order_id' );
		$this->assertInstanceOf( Column::class, $fk );
		$this->assertSame( 'BIGINT', $fk->type );
		$this->assertSame( '20', (string) $fk->length );
		$this->assertTrue( (bool) $fk->unsigned );

		// Shape only — never the primary key's identity.
		$this->assertFalse( (bool) $fk->primary );
		$this->assertNotSame( 'auto_increment', $fk->extra );
		$this->assertFalse( (bool) $fk->allow_null );
	}

	/**
	 * The foreign key mirrors a varchar/UUID primary key's shape (not hardcoded).
	 *
	 * @since 3.1.0
	 */
	public function test_foreign_key_mirrors_varchar_pk() {
		$pk     = new Column(
			array(
				'name'    => 'id',
				'type'    => 'varchar',
				'length'  => '36',
				'primary' => true,
			)
		);
		$schema = Meta::build_meta_schema( $pk, 'thing', 'BerlinDB\\Tests\\ThingQueryFixture' );

		$fk = $this->column( $schema, 'thing_id' );
		$this->assertInstanceOf( Column::class, $fk );
		$this->assertSame( 'VARCHAR', $fk->type );
		$this->assertSame( '36', (string) $fk->length );
		$this->assertFalse( (bool) $fk->primary );
	}

	/**
	 * The foreign key declares a belongs_to back to the primary query.
	 *
	 * @since 3.1.0
	 */
	public function test_belongs_to_back_to_primary() {
		$pk     = new Column(
			array(
				'name'     => 'id',
				'type'     => 'bigint',
				'length'   => '20',
				'unsigned' => true,
				'primary'  => true,
			)
		);
		$schema = Meta::build_meta_schema( $pk, 'order', 'BerlinDB\\Tests\\OrderQueryFixture' );

		$relationships = $schema->get_relationships();
		$this->assertCount( 1, $relationships );

		$rel = $relationships[0];
		$this->assertInstanceOf( Relationship::class, $rel );
		$this->assertSame( 'belongs_to', $rel->type );
		$this->assertSame( 'order', $rel->name );
		$this->assertSame( 'BerlinDB\\Tests\\OrderQueryFixture', $rel->get_query_class() );
		$this->assertSame( array( 'order_id' ), $rel->columns );
		$this->assertSame( array( 'id' ), $rel->references );
	}
}
