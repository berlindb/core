<?php
/**
 * Composite relationship priming, end to end (#229 phase 4).
 *
 * A child belongs_to a parent on a ( region, account ) pair, and the parent
 * has_many such children. After a query that names the relationship via `with`,
 * get_related() must resolve with ZERO SQL - the composite bulk-prime warmed the
 * exact result caches the per-item lookup reads.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Relationship;
use BerlinDB\Database\Kern\Row;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/** Parent: identified by a ( region, account ) pair, has_many children. */
class PrimeParentSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'     => 'region',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'account',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'       => 'children',
					'query'      => PrimeChildQuery::class,
					'type'       => 'has_many',
					'columns'    => array( 'region', 'account' ),
					'references' => array( 'region', 'account' ),
				)
			),
		);
	}
}

/** Child: ( region, account ) belongs_to the parent's ( region, account ). */
class PrimeChildSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'     => 'region',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'account',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'       => 'parent',
					'query'      => PrimeParentQuery::class,
					'type'       => 'belongs_to',
					'columns'    => array( 'region', 'account' ),
					'references' => array( 'region', 'account' ),
				)
			),
		);
	}
}

class PrimeParentRow extends Row {
	public $id      = 0;
	public $region  = 0;
	public $account = 0;
}
class PrimeChildRow extends Row {
	public $id      = 0;
	public $region  = 0;
	public $account = 0;
}

class PrimeParentQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'cprime_parent_test';
	protected $table_alias      = 'cpp';
	protected $table_schema     = PrimeParentSchema::class;
	protected $item_name        = 'cprime_parent';
	protected $item_name_plural = 'cprime_parents';
	protected $item_shape       = PrimeParentRow::class;
	protected $cache_group      = 'berlindb-cprime-parent';
}
class PrimeChildQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'cprime_child_test';
	protected $table_alias      = 'cpc';
	protected $table_schema     = PrimeChildSchema::class;
	protected $item_name        = 'cprime_child';
	protected $item_name_plural = 'cprime_children';
	protected $item_shape       = PrimeChildRow::class;
	protected $cache_group      = 'berlindb-cprime-child';
}

class PrimeParentTable extends Table {
	protected $schema  = PrimeParentSchema::class;
	protected $name    = 'berlindb_cprime_parent_test';
	protected $version = '202607090';
}
class PrimeChildTable extends Table {
	protected $schema  = PrimeChildSchema::class;
	protected $name    = 'berlindb_cprime_child_test';
	protected $version = '202607090';
}

/** Owner: identified by a non-primary 'code' column. */
class ScOwnerSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'     => 'code',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Doc: owner_code belongs_to the owner's non-primary 'code' (single-column, non-PK). */
class ScDocSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'     => 'owner_code',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'       => 'owner',
					'query'      => ScOwnerQuery::class,
					'type'       => 'belongs_to',
					'columns'    => array( 'owner_code' ),
					'references' => array( 'code' ),
				)
			),
		);
	}
}

class ScOwnerRow extends Row {
	public $id   = 0;
	public $code = 0;
}
class ScDocRow extends Row {
	public $id         = 0;
	public $owner_code = 0;
}

class ScOwnerQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'sc_owner_test';
	protected $table_alias      = 'sco';
	protected $table_schema     = ScOwnerSchema::class;
	protected $item_name        = 'sc_owner';
	protected $item_name_plural = 'sc_owners';
	protected $item_shape       = ScOwnerRow::class;
	protected $cache_group      = 'berlindb-sc-owner';
}
class ScDocQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'sc_doc_test';
	protected $table_alias      = 'scd';
	protected $table_schema     = ScDocSchema::class;
	protected $item_name        = 'sc_doc';
	protected $item_name_plural = 'sc_docs';
	protected $item_shape       = ScDocRow::class;
	protected $cache_group      = 'berlindb-sc-doc';
}

class ScOwnerTable extends Table {
	protected $schema  = ScOwnerSchema::class;
	protected $name    = 'berlindb_sc_owner_test';
	protected $version = '202607090';
}
class ScDocTable extends Table {
	protected $schema  = ScDocSchema::class;
	protected $name    = 'berlindb_sc_doc_test';
	protected $version = '202607090';
}

/**
 * End-to-end relationship priming behavior: composite keys, and a single-column
 * NON-primary belongs_to - both routed through the tuple-priming machinery.
 *
 * @since 3.1.0
 */
class CompositeRelationshipPrimingBehaviorTest extends TestCase {

	/** @var PrimeParentTable */
	private static $parent_table;

	/** @var PrimeChildTable */
	private static $child_table;

	/** @var PrimeParentQuery */
	private static $parents;

	/** @var PrimeChildQuery */
	private static $children;

	/** @var ScOwnerTable */
	private static $owner_table;

	/** @var ScDocTable */
	private static $doc_table;

	/** @var ScOwnerQuery */
	private static $owners;

	/** @var ScDocQuery */
	private static $docs;

	/**
	 * Install the tables.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$parent_table = new PrimeParentTable();
		if ( ! self::$parent_table->exists() ) {
			self::$parent_table->install();
		}
		self::$child_table = new PrimeChildTable();
		if ( ! self::$child_table->exists() ) {
			self::$child_table->install();
		}
		self::$owner_table = new ScOwnerTable();
		if ( ! self::$owner_table->exists() ) {
			self::$owner_table->install();
		}
		self::$doc_table = new ScDocTable();
		if ( ! self::$doc_table->exists() ) {
			self::$doc_table->install();
		}

		self::$parents  = new PrimeParentQuery();
		self::$children = new PrimeChildQuery();
		self::$owners   = new ScOwnerQuery();
		self::$docs     = new ScDocQuery();
	}

	/**
	 * Reset rows before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		self::$child_table->delete_all();
		self::$parent_table->delete_all();
		self::$doc_table->delete_all();
		self::$owner_table->delete_all();
		wp_cache_flush();
	}

	/**
	 * Drop both tables after the class.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$child_table->uninstall();
		self::$parent_table->uninstall();
		self::$doc_table->uninstall();
		self::$owner_table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * A primed composite belongs_to resolves with zero SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_belongs_to_priming_is_a_cache_hit() {
		global $wpdb;

		$parent_id = self::$parents->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);
		self::$children->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);

		// Query the children, priming the parent relationship in bulk.
		$results = self::$children->query( array( 'with' => array( 'parent' ) ) );
		$child   = reset( $results );

		$before = $wpdb->num_queries;
		$parent = self::$children->get_related( $child, 'parent' );
		$this->assertSame( $before, $wpdb->num_queries );

		$this->assertNotNull( $parent );
		$this->assertSame( (int) $parent_id, (int) $parent->id );
	}

	/**
	 * A primed composite has_many resolves the full child set with zero SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_has_many_priming_is_a_cache_hit() {
		global $wpdb;

		self::$parents->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);
		self::$children->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);
		self::$children->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);
		self::$children->add_item(
			array(
				'region'  => 9,
				'account' => 9,
			)
		); // decoy: different pair

		$results = self::$parents->query( array( 'with' => array( 'children' ) ) );
		$parent  = reset( $results );

		$before   = $wpdb->num_queries;
		$children = self::$parents->get_related( $parent, 'children' );
		$this->assertSame( $before, $wpdb->num_queries );

		$this->assertCount( 2, $children );
	}

	/**
	 * Priming returns the FULL child set past the default page limit (number => 0).
	 *
	 * @since 3.1.0
	 */
	public function test_composite_has_many_priming_returns_full_set_past_limit() {
		self::$parents->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);
		for ( $i = 0; $i < 101; $i++ ) {
			self::$children->add_item(
				array(
					'region'  => 5,
					'account' => 7,
				)
			);
		}

		$results = self::$parents->query( array( 'with' => array( 'children' ) ) );
		$parent  = reset( $results );

		$this->assertCount( 101, self::$parents->get_related( $parent, 'children' ) );
	}

	/**
	 * A primed single-column NON-primary belongs_to resolves with zero SQL.
	 *
	 * The reference is a non-primary column, so get_related() resolves via query()
	 * (not the by-id fast path) - which the tuple-priming path now warms.
	 *
	 * @since 3.1.0
	 */
	public function test_single_column_non_primary_belongs_to_priming_is_a_cache_hit() {
		global $wpdb;

		$owner_id = self::$owners->add_item( array( 'code' => 42 ) );
		self::$docs->add_item( array( 'owner_code' => 42 ) );

		// Query the docs, priming the (non-primary) owner relationship in bulk.
		$results = self::$docs->query( array( 'with' => array( 'owner' ) ) );
		$doc     = reset( $results );

		$before = $wpdb->num_queries;
		$owner  = self::$docs->get_related( $doc, 'owner' );
		$this->assertSame( $before, $wpdb->num_queries );

		$this->assertNotNull( $owner );
		$this->assertSame( (int) $owner_id, (int) $owner->id );
		$this->assertSame( 42, (int) $owner->code );
	}

	// -------------------------------------------------------------------------
	// Phase 5: invalidation - a primed relationship reflects later writes.
	// -------------------------------------------------------------------------

	/**
	 * The ( region, account ) => ( 5, 7 ) key pair reused across these tests.
	 *
	 * @since 3.1.0
	 * @return array<string,int>
	 */
	private function pair_5_7(): array {
		return array(
			'region'  => 5,
			'account' => 7,
		);
	}

	/**
	 * A primed composite has_many reflects a child inserted afterwards.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_has_many_priming_reflects_a_later_insert() {
		self::$parents->add_item( $this->pair_5_7() );
		self::$children->add_item( $this->pair_5_7() );

		$results = self::$parents->query( array( 'with' => array( 'children' ) ) );
		$parent  = reset( $results );

		// Primed: the first lookup is a zero-SQL hit.
		global $wpdb;
		$before = $wpdb->num_queries;
		$this->assertCount( 1, self::$parents->get_related( $parent, 'children' ) );
		$this->assertSame( $before, $wpdb->num_queries );

		// Inserting a matching child rotates last_changed, invalidating the prime.
		self::$children->add_item( $this->pair_5_7() );
		$this->assertCount( 2, self::$parents->get_related( $parent, 'children' ) );
	}

	/**
	 * A primed composite has_many reflects a child deleted afterwards.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_has_many_priming_reflects_a_later_delete() {
		self::$parents->add_item( $this->pair_5_7() );
		$child_id = self::$children->add_item( $this->pair_5_7() );
		self::$children->add_item( $this->pair_5_7() );

		$results = self::$parents->query( array( 'with' => array( 'children' ) ) );
		$parent  = reset( $results );

		// Primed: the first lookup is a zero-SQL hit.
		global $wpdb;
		$before = $wpdb->num_queries;
		$this->assertCount( 2, self::$parents->get_related( $parent, 'children' ) );
		$this->assertSame( $before, $wpdb->num_queries );

		self::$children->delete_item( $child_id );
		$this->assertCount( 1, self::$parents->get_related( $parent, 'children' ) );
	}

	/**
	 * A primed composite belongs_to MISS reflects a parent inserted afterwards.
	 *
	 * The negative-cached "no relation" must not stick once the parent exists.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_belongs_to_negative_prime_reflects_a_later_insert() {
		self::$children->add_item( $this->pair_5_7() );

		$results = self::$children->query( array( 'with' => array( 'parent' ) ) );
		$child   = reset( $results );

		// Primed negative: the "no relation" lookup is itself a zero-SQL hit.
		global $wpdb;
		$before = $wpdb->num_queries;
		$this->assertNull( self::$children->get_related( $child, 'parent' ) );
		$this->assertSame( $before, $wpdb->num_queries );

		// Inserting the matching parent invalidates the negative cache.
		$parent_id = self::$parents->add_item( $this->pair_5_7() );
		$parent    = self::$children->get_related( $child, 'parent' );

		$this->assertNotNull( $parent );
		$this->assertSame( (int) $parent_id, (int) $parent->id );
	}

	/**
	 * A primed composite belongs_to HIT reflects a parent key change afterwards.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_belongs_to_priming_reflects_a_parent_update() {
		$parent_id = self::$parents->add_item( $this->pair_5_7() );
		self::$children->add_item( $this->pair_5_7() );

		$results = self::$children->query( array( 'with' => array( 'parent' ) ) );
		$child   = reset( $results );

		// Primed: the first lookup is a zero-SQL hit.
		global $wpdb;
		$before = $wpdb->num_queries;
		$this->assertSame( (int) $parent_id, (int) self::$children->get_related( $child, 'parent' )->id );
		$this->assertSame( $before, $wpdb->num_queries );

		// Move the parent's key so it no longer matches the child.
		self::$parents->update_item( $parent_id, array( 'account' => 99 ) );
		$this->assertNull( self::$children->get_related( $child, 'parent' ) );
	}
}
