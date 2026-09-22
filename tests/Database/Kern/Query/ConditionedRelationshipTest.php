<?php
/**
 * Conditioned relationships: a fixed discriminator (object_type) scopes a polymorphic link.
 *
 * A relationship may carry a `condition` (column => value) so a child table with an
 * object_id + object_type pair can be modeled as ONE relationship, scoped to the matching
 * type. These tests prove the condition applies to BOTH forms - get_related() traversal and
 * the correlated EXISTS filter - using two notes that share an object_id but differ in
 * object_type, so only the condition distinguishes them.
 *
 * Tables are uninstalled after the class (DDL bypasses the per-test rollback).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Relationship;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/*
 * Fixtures: an owner with a conditioned has_many to a polymorphic note table.
 */

/** Owner: has_many notes WHERE object_type = 'owner'. */
class CrOwnerSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'extra'         => 'auto_increment',
			'primary'       => true,
			'relationships' => array(
				array(
					'name'      => 'notes',
					'query'     => CrNoteQuery::class,
					'column'    => 'object_id',
					'type'      => 'has_many',
					'condition' => array( 'object_type' => 'owner' ),
				),
			),
		),
		array(
			'name'   => 'name',
			'type'   => 'varchar',
			'length' => '50',
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Owner whose notes may have either of two object types. */
class CrListOwnerSchema extends CrOwnerSchema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'extra'         => 'auto_increment',
			'primary'       => true,
			'relationships' => array(
				array(
					'name'      => 'notes',
					'query'     => CrNoteQuery::class,
					'column'    => 'object_id',
					'type'      => 'has_many',
					'condition' => array( 'object_type' => array( 'owner', 'task' ) ),
				),
			),
		),
		array(
			'name'   => 'name',
			'type'   => 'varchar',
			'length' => '50',
		),
	);
}

/** Owner whose empty condition list must match no notes. */
class CrEmptyListOwnerSchema extends CrOwnerSchema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'extra'         => 'auto_increment',
			'primary'       => true,
			'relationships' => array(
				array(
					'name'      => 'notes',
					'query'     => CrNoteQuery::class,
					'column'    => 'object_id',
					'type'      => 'has_many',
					'condition' => array( 'object_type' => array() ),
				),
			),
		),
		array(
			'name'   => 'name',
			'type'   => 'varchar',
			'length' => '50',
		),
	);
}

/** Owner whose malformed fixed condition must never become unscoped. */
class CrMalformedOwnerSchema extends CrOwnerSchema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'extra'         => 'auto_increment',
			'primary'       => true,
			'relationships' => array(
				array(
					'name'      => 'notes',
					'query'     => CrNoteQuery::class,
					'column'    => 'object_id',
					'type'      => 'has_many',
					'condition' => array( 'object_type' => array( 'first' => 'owner' ) ),
				),
			),
		),
		array(
			'name'   => 'name',
			'type'   => 'varchar',
			'length' => '50',
		),
	);
}

/** Owner whose notes exclude one object type through a fixed comparison. */
class CrComparedOwnerSchema extends CrOwnerSchema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'extra'         => 'auto_increment',
			'primary'       => true,
			'relationships' => array(
				array(
					'name'      => 'notes',
					'query'     => CrNoteQuery::class,
					'column'    => 'object_id',
					'type'      => 'has_many',
					'condition' => array(
						'object_type' => array(
							'compare' => '!=',
							'value'   => 'task',
						),
					),
				),
			),
		),
		array(
			'name'   => 'name',
			'type'   => 'varchar',
			'length' => '50',
		),
	);
}

/** Polymorphic note: object_id + object_type point at different parent types. */
class CrNoteSchema extends Schema {
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'primary'  => true,
		),
		array(
			'name'     => 'object_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
			'in'       => true,
		),
		array(
			/*
			 * in => true so the discriminator is filterable via the query-var paths
			 * (get_related traversal); the join/EXISTS path renders
			 * raw SQL and does not require it.
			 */
			'name'   => 'object_type',
			'type'   => 'varchar',
			'length' => '20',
			'in'     => true,
		),
		array(
			'name'   => 'body',
			'type'   => 'varchar',
			'length' => '100',
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Owner whose condition names a column that does not exist on the remote note. */
class CrBadOwnerSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'extra'         => 'auto_increment',
			'primary'       => true,
			'relationships' => array(
				array(
					'name'      => 'notes',
					'query'     => CrNoteQuery::class,
					'column'    => 'object_id',
					'type'      => 'has_many',
					'condition' => array( 'nonexistent_column' => 'owner' ),
				),
			),
		),
		array(
			'name'   => 'name',
			'type'   => 'varchar',
			'length' => '50',
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

class CrOwnerQuery extends Query {
	protected $prefix       = 'cr';
	protected $table_name   = 'owners';
	protected $table_schema = CrOwnerSchema::class;
	protected $item_name    = 'owner';
	protected $cache_group  = 'cr_owners';
}

class CrListOwnerQuery extends CrOwnerQuery {
	protected $table_schema = CrListOwnerSchema::class;
}

class CrEmptyListOwnerQuery extends CrOwnerQuery {
	protected $table_schema = CrEmptyListOwnerSchema::class;
}

class CrMalformedOwnerQuery extends CrOwnerQuery {
	protected $table_schema = CrMalformedOwnerSchema::class;
}

class CrComparedOwnerQuery extends CrOwnerQuery {
	protected $table_schema = CrComparedOwnerSchema::class;
}

class CrBadOwnerQuery extends Query {
	protected $prefix       = 'cr';
	protected $table_name   = 'bad_owners';
	protected $table_schema = CrBadOwnerSchema::class;
	protected $item_name    = 'bad_owner';
	protected $cache_group  = 'cr_bad_owners';
}

class CrBadOwnerTable extends Table {
	protected $prefix  = 'cr';
	protected $name    = 'bad_owners';
	protected $version = '1.0.0';
	protected $schema  = CrBadOwnerSchema::class;
}

class CrNoteQuery extends Query {
	protected $prefix       = 'cr';
	protected $table_name   = 'notes';
	protected $table_schema = CrNoteSchema::class;
	protected $item_name    = 'note';
	protected $cache_group  = 'cr_notes';
}

class CrOwnerTable extends Table {
	protected $prefix  = 'cr';
	protected $name    = 'owners';
	protected $version = '1.0.0';
	protected $schema  = CrOwnerSchema::class;
}

class CrNoteTable extends Table {
	protected $prefix  = 'cr';
	protected $name    = 'notes';
	protected $version = '1.0.0';
	protected $schema  = CrNoteSchema::class;
}

/**
 * @since 3.1.0
 */
class ConditionedRelationshipTest extends TestCase {

	/** @var CrOwnerTable */
	private static $owner_table;

	/** @var CrNoteTable */
	private static $note_table;

	/** @var CrBadOwnerTable */
	private static $bad_owner_table;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$owner_table     = new CrOwnerTable();
		self::$note_table      = new CrNoteTable();
		self::$bad_owner_table = new CrBadOwnerTable();

		if ( ! self::$owner_table->exists() ) {
			self::$owner_table->install();
		}
		if ( ! self::$note_table->exists() ) {
			self::$note_table->install();
		}
		if ( ! self::$bad_owner_table->exists() ) {
			self::$bad_owner_table->install();
		}
	}

	public static function tearDownAfterClass(): void {
		self::$bad_owner_table->uninstall();
		self::$note_table->uninstall();
		self::$owner_table->uninstall();
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 1 );
		self::$owner_table->delete_all();
		self::$note_table->delete_all();
		wp_cache_flush();
	}

	/** Value object: numeric-key condition lists are reindexed and exposed. */
	public function test_condition_is_sanitized_and_exposed(): void {
		$rel = new Relationship(
			array(
				'name'       => 'notes',
				'query'      => CrNoteQuery::class,
				'columns'    => array( 'id' ),
				'references' => array( 'object_id' ),
				'type'       => 'has_many',
				'condition'  => array(
					'object_type' => 'owner',
					'allowed'     => array(
						1 => 'owner',
						3 => 'task',
					),
					'excluded'    => array(
						'compare' => 'not in',
						'value'   => array(
							1 => 'task',
							3 => 'other',
						),
					),
				),
			)
		);

		$this->assertTrue( $rel->has_condition() );
		$this->assertSame(
			array(
				'object_type' => 'owner',
				'allowed'     => array( 'owner', 'task' ),
				'excluded'    => array(
					'compare' => 'NOT IN',
					'value'   => array( 'task', 'other' ),
				),
			),
			$rel->get_condition()
		);
	}

	/** Malformed condition entries keep the relationship scoped to no rows. */
	public function test_malformed_condition_is_rejected(): void {
		$invalid = array(
			array( 'object_type' => array( 'first' => 'owner' ) ),
			array( 'object_type' => array( 'owner', array( 'nested' ) ) ),
			array(
				'object_type' => 'owner',
				42            => 'skip',
			),
			array(
				'object_type' => array(
					'compare' => 'bogus',
					'value'   => 'owner',
				),
			),
			array(
				'object_type' => array(
					'compare' => 'NOT IN',
					'value'   => array(),
				),
			),
			array( 'relation' => 'OR' ),
			'owner',
		);

		foreach ( $invalid as $condition ) {
			$rel = new Relationship(
				array(
					'name'       => 'notes',
					'query'      => CrNoteQuery::class,
					'columns'    => array( 'id' ),
					'references' => array( 'object_id' ),
					'type'       => 'has_many',
					'condition'  => $condition,
				)
			);

			$this->assertTrue( $rel->has_condition() );
			$this->assertSame( array( '' => array() ), $rel->get_condition() );
			$this->assertContains( 'Relationship notes declares a malformed condition.', $rel->get_validation_errors() );
		}
	}

	/** Value object: a conditioned relationship can never be a FOREIGN KEY. */
	public function test_conditioned_relationship_is_not_enforceable(): void {
		$rel = new Relationship(
			array(
				'name'       => 'owner',
				'query'      => CrOwnerQuery::class,
				'columns'    => array( 'object_id' ),
				'references' => array( 'id' ),
				'type'       => 'belongs_to',
				'enforce'    => true,
				'condition'  => array( 'object_type' => 'owner' ),
			)
		);

		$this->assertFalse( $rel->is_enforced() );
		$this->assertFalse( $rel->is_foreign_key() );
		$this->assertSame( '', $rel->get_create_string( 'wp_cr_owners' ) );
	}

	/** Value object: no condition means unconditioned. */
	public function test_unconditioned_relationship_reports_no_condition(): void {
		$rel = new Relationship(
			array(
				'name'       => 'notes',
				'query'      => CrNoteQuery::class,
				'columns'    => array( 'id' ),
				'references' => array( 'object_id' ),
				'type'       => 'has_many',
			)
		);

		$this->assertFalse( $rel->has_condition() );
		$this->assertSame( array(), $rel->get_condition() );
	}

	/** Traversal: get_related() returns only the notes matching the condition. */
	public function test_get_related_is_scoped_by_condition(): void {
		$owners = new CrOwnerQuery();
		$notes  = new CrNoteQuery();

		$owner_id = (int) $owners->add_item( array( 'name' => 'Acme' ) );

		// Two notes share object_id but differ in object_type; only 'owner' should traverse.
		$notes->add_item(
			array(
				'object_id'   => $owner_id,
				'object_type' => 'owner',
				'body'        => 'owner-note',
			)
		);
		$notes->add_item(
			array(
				'object_id'   => $owner_id,
				'object_type' => 'task',
				'body'        => 'task-note',
			)
		);
		wp_cache_flush();

		$owner = $owners->get_item( $owner_id );
		$found = $owners->get_related( $owner, 'notes' );

		$this->assertIsArray( $found );
		$this->assertCount( 1, $found );
		$this->assertSame( 'owner-note', reset( $found )->body );
	}

	/** List condition: traversal and EXISTS both include the same two types. */
	public function test_list_condition_scopes_traversal_and_filter(): void {
		$owners   = new CrListOwnerQuery();
		$notes    = new CrNoteQuery();
		$owner_id = (int) $owners->add_item( array( 'name' => 'Acme' ) );

		foreach ( array( 'owner', 'task', 'other' ) as $type ) {
			$notes->add_item(
				array(
					'object_id'   => $owner_id,
					'object_type' => $type,
					'body'        => $type,
				)
			);
		}

		wp_cache_flush();

		$found = $owners->get_related( $owners->get_item( $owner_id ), 'notes' );
		$this->assertCount( 2, $found );
		$bodies = array_column( $found, 'body' );
		sort( $bodies );
		$this->assertSame( array( 'owner', 'task' ), $bodies );

		$ids = $owners->query(
			array(
				'relation' => array( 'name' => 'notes' ),
				'fields'   => 'ids',
				'number'   => 0,
			)
		);

		$this->assertSame( array( $owner_id ), array_map( 'intval', (array) $ids ) );
	}

	/** Empty IN conditions fail closed for both relationship access paths. */
	public function test_empty_list_condition_matches_nothing(): void {
		$owners   = new CrEmptyListOwnerQuery();
		$notes    = new CrNoteQuery();
		$owner_id = (int) $owners->add_item( array( 'name' => 'Acme' ) );

		$notes->add_item(
			array(
				'object_id'   => $owner_id,
				'object_type' => 'owner',
				'body'        => 'present',
			)
		);

		wp_cache_flush();

		$this->assertSame( array(), $owners->get_related( $owners->get_item( $owner_id ), 'notes' ) );
		$this->assertSame(
			array(),
			$owners->query(
				array(
					'relation' => array( 'name' => 'notes' ),
					'fields'   => 'ids',
					'number'   => 0,
				)
			)
		);
	}

	/** A malformed condition must not expose rows through traversal or filtering. */
	public function test_malformed_condition_fails_closed(): void {
		$owners   = new CrMalformedOwnerQuery();
		$notes    = new CrNoteQuery();
		$owner_id = (int) $owners->add_item( array( 'name' => 'Acme' ) );

		$notes->add_item(
			array(
				'object_id'   => $owner_id,
				'object_type' => 'owner',
				'body'        => 'present',
			)
		);

		wp_cache_flush();

		$this->assertSame( array(), $owners->get_related( $owners->get_item( $owner_id ), 'notes' ) );
		$this->assertSame(
			array(),
			$owners->query(
				array(
					'relation' => array( 'name' => 'notes' ),
					'fields'   => 'ids',
					'number'   => 0,
				)
			)
		);
	}

	/** Explicit comparisons scope both relationship access paths. */
	public function test_comparison_condition_scopes_traversal_and_filter(): void {
		$owners      = new CrComparedOwnerQuery();
		$notes       = new CrNoteQuery();
		$matching_id = (int) $owners->add_item( array( 'name' => 'Matching' ) );
		$other_id    = (int) $owners->add_item( array( 'name' => 'Other' ) );

		foreach (
			array(
				array(
					'id'   => $matching_id,
					'type' => 'owner',
				),
				array(
					'id'   => $matching_id,
					'type' => 'task',
				),
				array(
					'id'   => $other_id,
					'type' => 'task',
				),
			) as $note
		) {
			$notes->add_item(
				array(
					'object_id'   => $note[ 'id' ],
					'object_type' => $note[ 'type' ],
					'body'        => $note[ 'type' ],
				)
			);
		}

		wp_cache_flush();

		$related = $owners->get_related( $owners->get_item( $matching_id ), 'notes' );
		$this->assertCount( 1, $related );
		$this->assertSame( 'owner', reset( $related )->body );
		$this->assertSame( array(), $owners->get_related( $owners->get_item( $other_id ), 'notes' ) );
		$this->assertSame(
			array( $matching_id ),
			array_map(
				'intval',
				(array) $owners->query(
					array(
						'relation' => array( 'name' => 'notes' ),
						'fields'   => 'ids',
						'number'   => 0,
					)
				)
			)
		);
	}

	/** Filter: the correlated EXISTS is scoped by the condition. */
	public function test_relation_exists_filter_is_scoped_by_condition(): void {
		$owners = new CrOwnerQuery();
		$notes  = new CrNoteQuery();

		$with_note    = (int) $owners->add_item( array( 'name' => 'HasOwnerNote' ) );
		$without_note = (int) $owners->add_item( array( 'name' => 'OnlyTaskNote' ) );

		// The first owner has an owner-note; the second has only a task-note (excluded).
		$notes->add_item(
			array(
				'object_id'   => $with_note,
				'object_type' => 'owner',
				'body'        => 'a',
			)
		);
		$notes->add_item(
			array(
				'object_id'   => $without_note,
				'object_type' => 'task',
				'body'        => 'b',
			)
		);
		wp_cache_flush();

		/*
		 * No explicit strategy: a conditioned relationship auto-defaults to the 'join'
		 * (correlated EXISTS) strategy, where the fixed discriminator is rendered as raw SQL.
		 */
		$ids = $owners->query(
			array(
				'relation' => array( 'name' => 'notes' ),
				'fields'   => 'ids',
				'number'   => 0,
				'orderby'  => 'id',
			)
		);

		$ids = array_map( 'intval', (array) $ids );

		$this->assertSame( array( $with_note ), $ids );
	}

	/** A remote mutation invalidates a cached relationship-filtered owner query. */
	public function test_relation_filter_cache_tracks_remote_writes(): void {
		$owners   = new CrOwnerQuery();
		$notes    = new CrNoteQuery();
		$owner_id = (int) $owners->add_item( array( 'name' => 'CachedOwner' ) );
		$note_id  = (int) $notes->add_item(
			array(
				'object_id'   => $owner_id,
				'object_type' => 'owner',
				'body'        => 'before',
			)
		);

		$args = array(
			'fields'   => 'ids',
			'number'   => 0,
			'relation' => array( 'name' => 'notes' ),
		);

		$this->assertSame( array( $owner_id ), array_map( 'intval', $owners->query( $args ) ) );
		$this->assertTrue( $notes->update_item( $note_id, array( 'object_type' => 'task' ) ) );
		$this->assertSame( array(), $owners->query( $args ) );
	}

	/** An empty cached relationship result must become visible after a remote insert. */
	public function test_empty_relation_filter_cache_tracks_remote_inserts(): void {
		$owners   = new CrOwnerQuery();
		$notes    = new CrNoteQuery();
		$owner_id = (int) $owners->add_item( array( 'name' => 'InitiallyUnmatched' ) );
		$args     = array(
			'fields'   => 'ids',
			'number'   => 0,
			'relation' => array( 'name' => 'notes' ),
		);

		$this->assertSame( array(), $owners->query( $args ) );
		$this->assertSame( array(), $owners->query( $args ) );

		$notes->add_item(
			array(
				'object_id'   => $owner_id,
				'object_type' => 'owner',
				'body'        => 'new',
			)
		);

		$this->assertSame( array( $owner_id ), array_map( 'intval', $owners->query( $args ) ) );
	}

	/** Empty direct clauses are unfiltered; malformed non-empty clauses fail closed. */
	public function test_direct_relation_query_shape(): void {
		$owners   = new CrOwnerQuery();
		$owner_id = (int) $owners->add_item( array( 'name' => 'DirectRelationShape' ) );
		$args     = array(
			'fields' => 'ids',
			'number' => 0,
		);

		$this->assertSame(
			array( $owner_id ),
			array_map( 'intval', $owners->query( array_merge( $args, array( 'relation_query' => array() ) ) ) )
		);
		$this->assertSame(
			array(),
			$owners->query( array_merge( $args, array( 'relation_query' => 'notes' ) ) )
		);
	}

	/** Safety: an unknown condition column fails closed (does not widen to all rows). */
	public function test_unknown_condition_column_fails_closed(): void {
		$bad_owners = new CrBadOwnerQuery();
		$notes      = new CrNoteQuery();

		$bad_id = (int) $bad_owners->add_item( array( 'name' => 'Bad' ) );

		// A note that WOULD match on object_id alone; the bogus condition must exclude it.
		$notes->add_item(
			array(
				'object_id'   => $bad_id,
				'object_type' => 'owner',
				'body'        => 'x',
			)
		);
		wp_cache_flush();

		$owner = $bad_owners->get_item( $bad_id );
		$found = $bad_owners->get_related( $owner, 'notes' );

		$this->assertSame( array(), $found );
	}

	/** Safety: a condition on a many_to_many is rejected as a validation error. */
	public function test_condition_on_many_to_many_is_rejected(): void {
		$rel = new Relationship(
			array(
				'name'               => 'x',
				'type'               => 'many_to_many',
				'query'              => CrNoteQuery::class,
				'columns'            => array( 'id' ),
				'through'            => CrNoteQuery::class,
				'through_columns'    => array( 'object_id' ),
				'through_references' => array( 'id' ),
				'references'         => array( 'id' ),
				'condition'          => array( 'object_type' => 'owner' ),
			)
		);

		$found = false;
		foreach ( $rel->get_validation_errors() as $error ) {
			if ( false !== strpos( $error, 'many_to_many, which is not supported' ) ) {
				$found = true;
			}
		}

		$this->assertTrue( $found, 'expected a validation error rejecting the condition on a many_to_many' );
	}
}
