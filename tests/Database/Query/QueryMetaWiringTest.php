<?php
/**
 * Meta relationship wiring (#204 Phase A).
 *
 * When a Query's schema supports( 'meta' ), Query::init() composes both bound
 * relationships through the Meta preset: the primary has_many its meta rows, and
 * the generated meta Query belongs_to the primary. Both are bound (neither side
 * needs a resolvable class name). The preset is memoized per primary table, so the
 * generated meta sibling is reused, and the inverse relationship is composed once.
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
use BerlinDB\Database\Presets\Meta;
use PHPUnit\Framework\TestCase;

/** A primary schema opting into meta. */
class MetaWiringSchema extends Schema {
	protected $supports = array( 'meta' );

	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'primary'  => true,
			'extra'    => 'auto_increment',
		),
		array(
			'name'   => 'status',
			'type'   => 'varchar',
			'length' => '20',
		),
	);
}

/** A primary Query bound to the meta-supporting schema. */
class MetaWiringQuery extends Query {
	protected $prefix       = 'mw';
	protected $table_name   = 'orders';
	protected $table_schema = MetaWiringSchema::class;
	protected $item_name    = 'order';
	protected $cache_group  = 'orders';
}

/**
 * Tests for meta relationship wiring.
 *
 * @since 3.1.0
 */
class QueryMetaWiringTest extends TestCase {

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
	 * The primary composes a 'meta' has_many that resolves to the meta sibling.
	 *
	 * @since 3.1.0
	 */
	public function test_primary_has_many_meta_resolves() {
		$query = new MetaWiringQuery();

		$this->assertContains( 'meta', $this->names( $query ) );
		$this->assertSame( array(), $query->get_relationship_errors() );
	}

	/**
	 * The generated meta Query belongs_to the primary and resolves back.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_belongs_to_primary_resolves() {
		$preset = Meta::for_query( new MetaWiringQuery() );
		$meta   = $preset->get_meta_query();

		$this->assertContains( 'order', $this->names( $meta ) );
		$this->assertSame( array(), $meta->get_relationship_errors() );
	}

	/**
	 * The preset and its generated meta Query are memoized per primary table.
	 *
	 * @since 3.1.0
	 */
	public function test_preset_and_meta_query_are_reused() {
		$preset_a = Meta::for_query( new MetaWiringQuery() );
		$preset_b = Meta::for_query( new MetaWiringQuery() );

		$this->assertSame( $preset_a, $preset_b );
		$this->assertSame( $preset_a->get_meta_query(), $preset_b->get_meta_query() );
	}

	/**
	 * The inverse belongs_to is composed exactly once on the shared meta Query.
	 *
	 * @since 3.1.0
	 */
	public function test_inverse_relationship_composed_once() {
		// Construct several primaries against the same (shared) meta query.
		new MetaWiringQuery();
		new MetaWiringQuery();
		$meta = Meta::for_query( new MetaWiringQuery() )->get_meta_query();

		$belongs_to = array_filter(
			$meta->get_relationships(),
			static function ( $relationship ) {
				return 'belongs_to' === $relationship->type;
			}
		);

		$this->assertCount( 1, $belongs_to );
	}
}
