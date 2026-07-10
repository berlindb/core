<?php
/**
 * Tests for Query's relationship accessors (berlindb/core #193).
 *
 * The accessors delegate to Schema::get_relationships(); constructing a Query
 * subclass without arguments triggers init() but runs no database query, so
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
// Fixtures - a schema declaring one belongs_to and one has_many relationship.
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
 * Schema declaring a many_to_many (pivot) relationship via get_relationships().
 *
 * The per-column shorthand is single-hop only, so a pivot is declared at the
 * Schema level (like composite keys).
 */
class ManyToManyRelationshipSchema extends Schema {
	public $columns = array(
		array(
			'name' => 'id',
			'type' => 'bigint',
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'               => 'tags',
					'columns'            => array( 'id' ),
					'query'              => 'EDD\\Database\\Queries\\Tag',
					'references'         => array( 'id' ),
					'through'            => 'EDD\\Database\\Queries\\PostTag',
					'through_columns'    => array( 'post_id' ),
					'through_references' => array( 'tag_id' ),
				)
			),
		);
	}
}

/**
 * Query bound to the many_to_many schema.
 */
class ManyToManyRelationshipQuery extends Query {
	protected $prefix       = 'myapp';
	protected $table_name   = 'posts';
	protected $table_alias  = 'p';
	protected $table_schema = ManyToManyRelationshipSchema::class;
	protected $cache_group  = 'posts';
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

	/**
	 * Test that get_related() on a many_to_many fails closed (empty child set)
	 * when the pivot class is unresolvable, rather than a wrong single-hop lookup.
	 * (The pivot here names a class that does not exist.)
	 *
	 * @since 3.1.0
	 */
	public function test_get_related_many_to_many_fails_closed_on_unresolvable_pivot() {
		$query = new ManyToManyRelationshipQuery();

		// The pivot relationship is declared and typed correctly.
		$tags = $query->get_relationship( 'tags' );
		$this->assertInstanceOf( Relationship::class, $tags );
		$this->assertSame( 'many_to_many', $tags->type );

		// Unresolvable pivot -> empty array, never a wrong Row.
		$item = (object) array( 'id' => 5 );
		$this->assertSame( array(), $query->get_related( $item, 'tags' ) );
	}
}
