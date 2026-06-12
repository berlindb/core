<?php
/**
 * Meta as ordinary relationships, via stub classes (#204 Phase A).
 *
 * The meta sibling is a one-line stub naming its primary
 * (protected $primary = Order::class, extending Presets\Meta\Query); the base
 * derives the meta table identity and EAV schema from the primary and declares a
 * belongs_to back. The primary declares the matching has_many in its own schema —
 * an ordinary relationship like any other. Both sides are real classes resolved
 * by class name through the relationship engine; Kern classes carry zero preset
 * knowledge.
 *
 * Construction-only; no database required.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Presets\Meta\Query as MetaQuery;
use PHPUnit\Framework\TestCase;

/** A primary schema — declares the has_many to its meta as an ordinary relationship. */
class WireOrderSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'primary'       => true,
			'extra'         => 'auto_increment',
			'relationships' => array(
				array(
					'query'  => WireOrderMetaQuery::class,
					'column' => 'order_id',
					'type'   => 'has_many',
					'name'   => 'meta',
				),
			),
		),
		array(
			'name'   => 'status',
			'type'   => 'varchar',
			'length' => '20',
		),
	);
}

/** The meta sibling — a one-line stub naming its primary. */
class WireOrderMetaQuery extends MetaQuery {
	protected $primary = WireOrderQuery::class;
}

/** The primary. Meta is wired entirely through the schema relationship above. */
class WireOrderQuery extends Query {
	protected $prefix       = 'wire';
	protected $table_name   = 'orders';
	protected $table_schema = WireOrderSchema::class;
	protected $item_name    = 'order';
	protected $cache_group  = 'orders';
}

/** A misconfigured stub naming no primary at all. */
class WireOrphanMetaQuery extends MetaQuery {}

/** A class that exists but is NOT a Query. */
class WireNotAQuery {}

/** A misconfigured stub whose primary is not a Query. */
class WireNotAQueryMetaQuery extends MetaQuery {
	protected $primary = WireNotAQuery::class;
}

/** A primary schema with no primary-key column. */
class WireKeylessSchema extends Schema {
	public $columns = array(
		array(
			'name'   => 'label',
			'type'   => 'varchar',
			'length' => '20',
		),
	);
}

/** A primary Query with no primary key. */
class WireKeylessQuery extends Query {
	protected $prefix       = 'wire';
	protected $table_name   = 'keyless';
	protected $table_schema = WireKeylessSchema::class;
	protected $item_name    = 'keyless';
	protected $cache_group  = 'keyless';
}

/** A misconfigured stub whose primary has no primary-key column. */
class WireKeylessMetaQuery extends MetaQuery {
	protected $primary = WireKeylessQuery::class;
}

/**
 * Tests for stub-based meta relationships.
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
	 * The primary has_many its meta, resolved by class like any relationship.
	 *
	 * @since 3.1.0
	 */
	public function test_primary_has_many_meta_resolves() {
		$query = new WireOrderQuery();

		$this->assertContains( 'meta', $this->names( $query ) );
		$this->assertSame( array(), $query->get_relationship_errors() );
	}

	/**
	 * The meta stub belongs_to its primary, resolved by class, and resolves back.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_belongs_to_primary_resolves() {
		$meta = new WireOrderMetaQuery();

		$this->assertContains( 'order', $this->names( $meta ) );
		$this->assertSame( array(), $meta->get_relationship_errors() );
	}

	/**
	 * The meta stub configures its identity and EAV schema from the primary.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_query_configures_from_primary() {
		$meta = new WireOrderMetaQuery();

		// Identity derived from the primary's singular name.
		$this->assertSame( 'order_meta', $meta->get_item_name() );

		// The foreign key mirrors the primary's bigint key, not hardcoded.
		$fk = $meta->get_column_by( array( 'name' => 'order_id' ) );
		$this->assertInstanceOf( Column::class, $fk );
		$this->assertSame( 'BIGINT', $fk->type );
		$this->assertFalse( (bool) $fk->primary );

		// Standard EAV columns exist.
		$this->assertInstanceOf( Column::class, $meta->get_column_by( array( 'name' => 'meta_id' ) ) );
		$this->assertInstanceOf( Column::class, $meta->get_column_by( array( 'name' => 'meta_key' ) ) );
		$this->assertInstanceOf( Column::class, $meta->get_column_by( array( 'name' => 'meta_value' ) ) );
	}

	/**
	 * A correctly-configured stub reports success.
	 *
	 * @since 3.1.0
	 */
	public function test_configured_stub_reports_success() {
		$meta = new WireOrderMetaQuery();

		$this->assertTrue( $meta->is_configured_from_primary() );
	}

	/**
	 * A stub naming no primary fails loudly (a structured warning, not silence).
	 *
	 * @since 3.1.0
	 */
	public function test_missing_primary_logs_warning() {
		$meta = new WireOrphanMetaQuery();

		$this->assertNotEmpty( $meta->get_logs( array( 'code' => 'meta_primary_missing' ) ) );
		$this->assertFalse( $meta->is_configured_from_primary() );
	}

	/**
	 * A stub whose primary is not a Query fails loudly.
	 *
	 * @since 3.1.0
	 */
	public function test_primary_not_a_query_logs_warning() {
		$meta = new WireNotAQueryMetaQuery();

		$this->assertNotEmpty( $meta->get_logs( array( 'code' => 'meta_primary_not_a_query' ) ) );
		$this->assertFalse( $meta->is_configured_from_primary() );
	}

	/**
	 * A stub whose primary has no primary-key column fails loudly.
	 *
	 * @since 3.1.0
	 */
	public function test_primary_key_missing_logs_warning() {
		$meta = new WireKeylessMetaQuery();

		$this->assertNotEmpty( $meta->get_logs( array( 'code' => 'meta_primary_key_missing' ) ) );
		$this->assertFalse( $meta->is_configured_from_primary() );
	}
}
