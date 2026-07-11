<?php
/**
 * Regression tests for the CRUD audit fixes.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Row;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Single-primary-key schema exercising three fixes: a RESERVED-word column
 * (`order`), a numeric column (`priority`), and a NULLABLE transition column
 * (`state`).
 */
class CrudAuditSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'primary'   => true,
			'cache_key' => true,
		),
		array(
			'name'    => 'order',
			'type'    => 'varchar',
			'length'  => '40',
			'default' => '',
		),
		array(
			'name'     => 'priority',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'       => 'state',
			'type'       => 'varchar',
			'length'     => '20',
			'allow_null' => true,
			'transition' => true,
		),
		array(
			'name'       => 'rank',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'default'    => 0,
			'transition' => true,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}
class CrudAuditRow extends Row {
	public $id       = 0;
	public $order    = '';
	public $priority = 0;
	public $state    = null;
	public $rank     = 0;
}
class CrudAuditQuery extends Query {
	protected $prefix           = 'berlindb_database';
	protected $table_name       = 'crud_audit_test';
	protected $table_alias      = 'cat';
	protected $table_schema     = CrudAuditSchema::class;
	protected $item_name        = 'crud_audit';
	protected $item_name_plural = 'crud_audits';
	protected $item_shape       = CrudAuditRow::class;
	protected $cache_group      = 'berlindb-crud-audit';
}
class CrudAuditTable extends Table {
	protected $schema  = CrudAuditSchema::class;
	protected $name    = 'berlindb_database_crud_audit_test';
	protected $version = '202607111';
}

/** Composite (two-column) primary key - for the by-id write guard. No table needed. */
class CrudAuditCompositeSchema extends Schema {
	public $columns = array(
		array(
			'name'     => 'account_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'primary'  => true,
		),
		array(
			'name'     => 'user_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'primary'  => true,
		),
		array(
			'name'    => 'status',
			'type'    => 'varchar',
			'length'  => '20',
			'default' => '',
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'account_id', 'user_id' ),
		),
	);
}
class CrudAuditCompositeQuery extends Query {
	protected $prefix           = 'berlindb_database';
	protected $table_name       = 'crud_audit_composite_test';
	protected $table_alias      = 'cac';
	protected $table_schema     = CrudAuditCompositeSchema::class;
	protected $item_name        = 'crud_audit_composite';
	protected $item_name_plural = 'crud_audit_composites';
	protected $cache_group      = 'berlindb-crud-audit-composite';
}

/**
 * Locks the CRUD audit fixes: reserved-column quoting, matched-no-change updates,
 * transitions to null, and the composite-primary-key by-id write guard.
 *
 * @since 3.1.0
 */
class CrudAuditFixesTest extends TestCase {

	/** @var CrudAuditTable */
	private static $table;

	/** @var CrudAuditQuery */
	private static $query;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$table = new CrudAuditTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new CrudAuditQuery();
	}

	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 1 );
		self::$table->delete_all();
		wp_cache_flush();
	}

	/**
	 * #1: get_item_by() on a RESERVED-word column produces valid SQL (the column is
	 * quoted), rather than a `WHERE order = ...` syntax error.
	 *
	 * @since 3.1.0
	 */
	public function test_get_item_by_reserved_column_name_is_quoted() {
		$id = self::$query->add_item( array( 'order' => 'abc' ) );
		$this->assertNotEmpty( $id );

		$found = self::$query->get_item_by( 'order', 'abc' );

		$this->assertNotEmpty( $found );
		$this->assertSame( (int) $id, (int) $found->id );
	}

	/**
	 * #3: a matched update whose column value nets 0 changed rows (an invalid value
	 * that validates back to the stored one) is a SUCCESS, not a failure.
	 *
	 * @since 3.1.0
	 */
	public function test_update_item_matched_no_change_returns_true() {
		$id = self::$query->add_item( array( 'priority' => 0 ) );

		// 'not-a-number' validates back to 0 on a bigint column, so 0 rows change.
		$result = self::$query->update_item( $id, array( 'priority' => 'not-a-number' ) );

		$this->assertTrue( $result );
	}

	/**
	 * #4: a transition column changing TO null fires its transition action (the old
	 * is_scalar() filter dropped null and missed it).
	 *
	 * @since 3.1.0
	 */
	public function test_transition_fires_on_change_to_null() {
		$id = self::$query->add_item( array( 'state' => 'active' ) );

		$fired = false;
		$old   = 'unset';
		$new   = 'unset';

		add_action(
			'berlindb_database_transition_crud_audit_state',
			function ( $o, $n ) use ( &$fired, &$old, &$new ) {
				$fired = true;
				$old   = $o;
				$new   = $n;
			},
			10,
			2
		);

		self::$query->update_item( $id, array( 'state' => null ) );

		$this->assertTrue( $fired );
		$this->assertSame( 'active', $old );
		$this->assertNull( $new );
	}

	/**
	 * #4 (companion): a transition column changing FROM null to a value also fires -
	 * the null-aware diff keeps null distinct from '' on the old side too.
	 *
	 * @since 3.1.0
	 */
	public function test_transition_fires_on_change_from_null() {
		$id = self::$query->add_item( array( 'state' => null ) );

		$fired = false;
		$old   = 'unset';
		$new   = 'unset';

		add_action(
			'berlindb_database_transition_crud_audit_state',
			function ( $o, $n ) use ( &$fired, &$old, &$new ) {
				$fired = true;
				$old   = $o;
				$new   = $n;
			},
			10,
			2
		);

		self::$query->update_item( $id, array( 'state' => 'active' ) );

		$this->assertTrue( $fired );
		$this->assertNull( $old );
		$this->assertSame( 'active', $new );
	}

	/**
	 * #2: by-id update_item()/delete_item() fail CLOSED on a composite primary key -
	 * a single ID cannot address one row, so they refuse rather than write every row
	 * sharing the first primary column's value.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_primary_key_refuses_by_id_writes() {
		$query = new CrudAuditCompositeQuery();

		$this->assertFalse( $query->update_item( 1, array( 'status' => 'archived' ) ) );
		$this->assertFalse( $query->delete_item( 1 ) );
	}

	/**
	 * #3 x #4 interaction: a transition column written with a value that VALIDATES BACK
	 * to the stored one (0 rows changed) falls through to transition_item() under the new
	 * return path, but its null-aware diff is empty - so NO transition fires. Guards
	 * against the fall-through spuriously firing transitions on a no-op write.
	 *
	 * @since 3.1.0
	 */
	public function test_no_op_column_write_leaves_transition_unfired() {
		$id = self::$query->add_item( array( 'rank' => 0 ) );

		$fired = false;
		add_action(
			'berlindb_database_transition_crud_audit_rank',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		// 'not-a-number' validates back to 0 on a bigint, so the row matches, 0 rows change.
		$result = self::$query->update_item( $id, array( 'rank' => 'not-a-number' ) );

		$this->assertTrue( $result );
		$this->assertFalse( $fired );
	}

	/**
	 * Transitions are scoped to their own column: a real change to a NON-transition
	 * column (priority) writes the row and returns true, but fires no transition.
	 *
	 * @since 3.1.0
	 */
	public function test_updating_non_transition_column_leaves_transition_unfired() {
		$id = self::$query->add_item(
			array(
				'state'    => 'active',
				'priority' => 1,
			)
		);

		$fired = false;
		add_action(
			'berlindb_database_transition_crud_audit_state',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$result = self::$query->update_item( $id, array( 'priority' => 2 ) );

		$this->assertTrue( $result );
		$this->assertFalse( $fired );
	}

	/**
	 * The return-path change must not break cache rotation: after a real update the
	 * by-id cache is refreshed, so a subsequent get_item() resolves the NEW value.
	 *
	 * @since 3.1.0
	 */
	public function test_real_update_refreshes_cached_item() {
		$id = self::$query->add_item( array( 'priority' => 1 ) );

		// Warm the by-id cache.
		$this->assertSame( 1, (int) self::$query->get_item( $id )->priority );

		self::$query->update_item( $id, array( 'priority' => 9 ) );

		// The cached copy must reflect the write, not the stale 1.
		$this->assertSame( 9, (int) self::$query->get_item( $id )->priority );
	}
}
