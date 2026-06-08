<?php
/**
 * Tests for Query's relationship accessors (berlindb/core #193).
 *
 * The accessors delegate to Schema::get_relationships(); constructing a Query
 * subclass without arguments triggers setup() but runs no database query, so
 * no live table is required here.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Relationship;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Fixtures — a schema declaring one belongs_to and one has_many relationship.
// ---------------------------------------------------------------------------

/**
 * Schema with two relationships: an implicit-name belongs_to and an
 * explicit-name has_many.
 */
class RelationshipSpySchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'name'   => 'items',
					'query'  => 'EDD\\Database\\Queries\\OrderItem',
					'column' => 'order_id',
					'type'   => 'has_many',
				),
			),
		),
		array(
			'name'          => 'order_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'query'  => 'EDD\\Database\\Queries\\Order',
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),
	);
}

/**
 * Query bound to the relationship spy schema.
 */
class RelationshipSpyQuery extends Query {
	protected $prefix       = 'myapp';
	protected $table_name   = 'orders';
	protected $table_alias  = 'o';
	protected $table_schema = RelationshipSpySchema::class;
	protected $cache_group  = 'orders';
}

/**
 * Tests for Query relationship accessors.
 *
 * @since 3.1.0
 */
class QueryRelationshipsTest extends TestCase {

	/**
	 * Test that get_relationships() returns every declared relationship.
	 *
	 * @since 3.1.0
	 */
	public function test_get_relationships_returns_all() {
		$query         = new RelationshipSpyQuery();
		$relationships = $query->get_relationships();

		$this->assertCount( 2, $relationships );
		$this->assertContainsOnlyInstancesOf( Relationship::class, $relationships );
	}

	/**
	 * Test that get_relationship() returns a relationship by accessor name.
	 *
	 * @since 3.1.0
	 */
	public function test_get_relationship_by_name() {
		$query = new RelationshipSpyQuery();

		$order = $query->get_relationship( 'order' );
		$this->assertInstanceOf( Relationship::class, $order );
		$this->assertSame( 'belongs_to', $order->type );

		$items = $query->get_relationship( 'items' );
		$this->assertInstanceOf( Relationship::class, $items );
		$this->assertSame( 'has_many', $items->type );
	}

	/**
	 * Test that get_relationship() returns false for unknown or empty names.
	 *
	 * @since 3.1.0
	 */
	public function test_get_relationship_unknown_returns_false() {
		$query = new RelationshipSpyQuery();

		$this->assertFalse( $query->get_relationship( 'nope' ) );
		$this->assertFalse( $query->get_relationship( '' ) );
	}

	/**
	 * Test that get_belongs_to_relationships() returns only belongs_to entries.
	 *
	 * @since 3.1.0
	 */
	public function test_get_belongs_to_relationships() {
		$query   = new RelationshipSpyQuery();
		$belongs = $query->get_belongs_to_relationships();

		$this->assertCount( 1, $belongs );
		$this->assertSame( 'order', $belongs[0]->name );
		$this->assertSame( 'belongs_to', $belongs[0]->type );
	}

	/**
	 * Test that get_has_many_relationships() returns only has_many entries.
	 *
	 * @since 3.1.0
	 */
	public function test_get_has_many_relationships() {
		$query    = new RelationshipSpyQuery();
		$has_many = $query->get_has_many_relationships();

		$this->assertCount( 1, $has_many );
		$this->assertSame( 'items', $has_many[0]->name );
		$this->assertSame( 'has_many', $has_many[0]->type );
	}
}
