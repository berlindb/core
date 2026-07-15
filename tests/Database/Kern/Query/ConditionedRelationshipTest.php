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
			 * (get_related traversal / the 'in' strategy); the join/EXISTS path renders
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

	/** Value object: a condition is sanitized and exposed. */
	public function test_condition_is_sanitized_and_exposed(): void {
		$rel = new Relationship(
			array(
				'name'      => 'notes',
				'query'     => CrNoteQuery::class,
				'columns'   => array( 'id' ),
				'references'=> array( 'object_id' ),
				'type'      => 'has_many',
				'condition' => array(
					'object_type' => 'owner',
					'bad_value'   => array( 'not', 'scalar' ), // dropped: non-scalar
					42            => 'skip',                    // dropped: non-string key
				),
			)
		);

		$this->assertTrue( $rel->has_condition() );
		$this->assertSame( array( 'object_type' => 'owner' ), $rel->get_condition() );
	}

	/** Value object: a conditioned relationship can never be a FOREIGN KEY. */
	public function test_conditioned_relationship_is_not_enforceable(): void {
		$rel = new Relationship(
			array(
				'name'      => 'owner',
				'query'     => CrOwnerQuery::class,
				'columns'   => array( 'object_id' ),
				'references'=> array( 'id' ),
				'type'      => 'belongs_to',
				'enforce'   => true,
				'condition' => array( 'object_type' => 'owner' ),
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
				'name'      => 'notes',
				'query'     => CrNoteQuery::class,
				'columns'   => array( 'id' ),
				'references'=> array( 'object_id' ),
				'type'      => 'has_many',
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
		$notes->add_item( array( 'object_id' => $owner_id, 'object_type' => 'owner', 'body' => 'owner-note' ) );
		$notes->add_item( array( 'object_id' => $owner_id, 'object_type' => 'task',  'body' => 'task-note' ) );
		wp_cache_flush();

		$owner = $owners->get_item( $owner_id );
		$found = $owners->get_related( $owner, 'notes' );

		$this->assertIsArray( $found );
		$this->assertCount( 1, $found );
		$this->assertSame( 'owner-note', reset( $found )->body );
	}

	/** Filter: the correlated EXISTS is scoped by the condition. */
	public function test_relation_exists_filter_is_scoped_by_condition(): void {
		$owners = new CrOwnerQuery();
		$notes  = new CrNoteQuery();

		$with_note    = (int) $owners->add_item( array( 'name' => 'HasOwnerNote' ) );
		$without_note = (int) $owners->add_item( array( 'name' => 'OnlyTaskNote' ) );

		// The first owner has an owner-note; the second has only a task-note (excluded).
		$notes->add_item( array( 'object_id' => $with_note,    'object_type' => 'owner', 'body' => 'a' ) );
		$notes->add_item( array( 'object_id' => $without_note, 'object_type' => 'task',  'body' => 'b' ) );
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

	/** Safety: an unknown condition column fails closed (does not widen to all rows). */
	public function test_unknown_condition_column_fails_closed(): void {
		$bad_owners = new CrBadOwnerQuery();
		$notes      = new CrNoteQuery();

		$bad_id = (int) $bad_owners->add_item( array( 'name' => 'Bad' ) );

		// A note that WOULD match on object_id alone; the bogus condition must exclude it.
		$notes->add_item( array( 'object_id' => $bad_id, 'object_type' => 'owner', 'body' => 'x' ) );
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
