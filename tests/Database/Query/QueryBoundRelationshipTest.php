<?php
/**
 * Bound relationship resolution (#204 Phase A crux).
 *
 * A preset-composed relationship whose remote Query is a generated instance has no
 * resolvable class name; it carries its resolved remote directly
 * (Relationship::bind()) and resolves to that instance. Because the bound remote
 * lives on the relationship — not in a separate map keyed by accessor — a declared
 * relationship carrying a real class can never be shadowed, and composing a
 * relationship whose accessor is already taken is skipped.
 *
 * Construction-only; no database required.
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
// Remote fixtures
// ---------------------------------------------------------------------------

/** Remote that HAS the referenced 'object_id' column. */
class BoundRemoteSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'meta_id',
			'type'    => 'bigint',
			'primary' => true,
		),
		array(
			'name' => 'object_id',
			'type' => 'bigint',
		),
	);
}
class BoundRemoteQuery extends Query {
	protected $prefix       = 'bnd';
	protected $table_name   = 'remote_has';
	protected $table_schema = BoundRemoteSchema::class;
	protected $cache_group  = 'remote_has';
}

/** Remote that LACKS 'object_id' (only an id). */
class ShadowRemoteSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);
}
class ShadowRemoteQuery extends Query {
	protected $prefix       = 'bnd';
	protected $table_name   = 'remote_lacks';
	protected $table_schema = ShadowRemoteSchema::class;
	protected $cache_group  = 'remote_lacks';
}

// ---------------------------------------------------------------------------
// Local fixtures
// ---------------------------------------------------------------------------

/** Local primary (just a key). */
class BoundLocalSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);
}

/** Composes a bound has_many 'items'; the bound remote HAS 'object_id'. */
class BoundLocalQuery extends Query {
	protected $prefix       = 'bnd';
	protected $table_name   = 'local_bound';
	protected $table_schema = BoundLocalSchema::class;
	protected $cache_group  = 'local_bound';

	protected function init(): void {
		parent::init();

		$relationship = new Relationship(
			array(
				'name'       => 'items',
				'type'       => 'has_many',
				'columns'    => array( 'id' ),
				'references' => array( 'object_id' ),
			)
		);
		$relationship->bind( new BoundRemoteQuery() );

		$this->add_composed_relationship( $relationship );
	}
}

/** Declares 'items' (belongs_to ShadowRemoteQuery) AND tries to compose a same-named bound one. */
class ShadowLocalSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
		array(
			'name'          => 'remote_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'name'   => 'items',
					'query'  => ShadowRemoteQuery::class,
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),
	);
}
class ShadowLocalQuery extends Query {
	protected $prefix       = 'bnd';
	protected $table_name   = 'local_shadow';
	protected $table_schema = ShadowLocalSchema::class;
	protected $cache_group  = 'local_shadow';

	protected function init(): void {
		parent::init();

		// Try to compose a bound 'items' — the accessor is already declared, so
		// this is skipped and logged rather than shadowing the declaration.
		$relationship = new Relationship(
			array(
				'name'       => 'items',
				'type'       => 'has_many',
				'columns'    => array( 'id' ),
				'references' => array( 'object_id' ),
			)
		);
		$relationship->bind( new BoundRemoteQuery() );

		$this->add_composed_relationship( $relationship );
	}
}

/**
 * Tests for bound relationship resolution and the no-shadow guarantee.
 *
 * @since 3.1.0
 */
class QueryBoundRelationshipTest extends TestCase {

	/**
	 * Relationship accessor names for a query.
	 *
	 * @since 3.1.0
	 *
	 * @param Query $query The query.
	 * @return list<string>
	 */
	private function names( Query $query ): array {
		$names = array();

		foreach ( $query->get_relationships() as $relationship ) {
			$names[] = $relationship->name;
		}

		return $names;
	}

	/**
	 * A composed bound relationship resolves to its bound remote.
	 *
	 * @since 3.1.0
	 */
	public function test_bound_relationship_resolves() {
		$query = new BoundLocalQuery();

		$this->assertContains( 'items', $this->names( $query ) );
		$this->assertSame( array(), $query->get_relationship_errors() );
	}

	/**
	 * Composing a relationship whose accessor is taken is skipped (no shadowing).
	 *
	 * The declared 'items' (ShadowRemoteQuery, which lacks 'object_id') survives —
	 * proving the bound instance (which has it) did not replace the declaration.
	 *
	 * @since 3.1.0
	 */
	public function test_composed_relationship_skips_taken_accessor() {
		$query = new ShadowLocalQuery();

		$items = array_values(
			array_filter(
				$query->get_relationships(),
				static function ( $relationship ) {
					return 'items' === $relationship->name;
				}
			)
		);

		$this->assertCount( 1, $items );
		$this->assertFalse( $items[0]->is_bound() );
		$this->assertNotEmpty( $query->get_logs( array( 'code' => 'relationship_accessor_taken' ) ) );
	}

	/**
	 * A bound relationship is exempt from the missing-remote-query-class check.
	 *
	 * @since 3.1.0
	 */
	public function test_bound_relationship_exempt_from_query_check() {
		$relationship = new Relationship(
			array(
				'name'       => 'items',
				'type'       => 'has_many',
				'columns'    => array( 'id' ),
				'references' => array( 'object_id' ),
			)
		);
		$relationship->bind( new BoundRemoteQuery() );

		$this->assertTrue( $relationship->is_bound() );
		$this->assertStringNotContainsString(
			'missing a remote query class',
			implode( ' ', $relationship->get_validation_errors() )
		);
	}
}
