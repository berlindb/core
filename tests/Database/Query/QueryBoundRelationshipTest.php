<?php
/**
 * Bound relationship resolution (#204 Phase A crux).
 *
 * A preset-composed relationship whose remote Query is a generated instance has no
 * resolvable class name; it is flagged is_bound() and resolves through the owning
 * Query's internal accessor map (bind_remote_query()). Crucially, only bound
 * relationships consult that map, so a declared relationship carrying a real class
 * is never shadowed by a same-named bound entry.
 *
 * These tests exercise resolution through the public get_relationship_errors()
 * (construction-only; no database required).
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

/** Local primary schema (just a key). */
class BoundLocalSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);
}

/**
 * Binds a bound has_many to 'items'; the bound remote HAS 'object_id'.
 */
class BoundLocalQuery extends Query {
	protected $prefix       = 'bnd';
	protected $table_name   = 'local_bound';
	protected $table_schema = BoundLocalSchema::class;
	protected $cache_group  = 'local_bound';

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'       => 'items',
					'type'       => 'has_many',
					'columns'    => array( 'id' ),
					'references' => array( 'object_id' ),
					'bound'      => true,
				)
			),
		);
	}

	protected function init(): void {
		parent::init();
		$this->bind_remote_query( 'items', new BoundRemoteQuery() );
	}
}

/**
 * A DECLARED (non-bound) relationship named 'items' pointing at a class that LACKS
 * 'object_id' — while ALSO binding a same-named instance that HAS it. Resolution
 * must use the declared class (and therefore report the missing column), proving
 * the bound entry does not shadow the declaration.
 */
class ShadowLocalQuery extends Query {
	protected $prefix       = 'bnd';
	protected $table_name   = 'local_shadow';
	protected $table_schema = BoundLocalSchema::class;
	protected $cache_group  = 'local_shadow';

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'       => 'items',
					'type'       => 'has_many',
					'columns'    => array( 'id' ),
					'query'      => ShadowRemoteQuery::class,
					'references' => array( 'object_id' ),
				)
			),
		);
	}

	protected function init(): void {
		parent::init();
		$this->bind_remote_query( 'items', new BoundRemoteQuery() );
	}
}

/**
 * Tests for bound relationship resolution and no-shadow behavior.
 *
 * @since 3.1.0
 */
class QueryBoundRelationshipTest extends TestCase {

	/**
	 * A bound relationship resolves through the internal map (remote column found).
	 *
	 * @since 3.1.0
	 */
	public function test_bound_relationship_resolves_via_map() {
		$query = new BoundLocalQuery();

		$this->assertSame( array(), $query->get_relationship_errors() );
	}

	/**
	 * A declared relationship is NOT shadowed by a same-named bound entry.
	 *
	 * The declared class (ShadowRemoteQuery) lacks 'object_id', so resolution by
	 * class surfaces the error — proving the bound instance (which has the column)
	 * was not used.
	 *
	 * @since 3.1.0
	 */
	public function test_declared_relationship_not_shadowed_by_bound() {
		$query = new ShadowLocalQuery();

		$this->assertStringContainsString(
			'unknown remote column object_id',
			implode( ' ', $query->get_relationship_errors() )
		);
	}
}
