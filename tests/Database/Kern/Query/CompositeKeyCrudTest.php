<?php
/**
 * Composite-primary-key CRUD: update/delete a single row by its FULL key, and fail
 * closed on a scalar or partial key (#234); and the plural update_items()/delete_items()
 * resolving each matched row's full composite key (#241).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * A two-column composite primary key ( account_id, user_id ) plus a status column.
 *
 * @since 3.1.0
 */
class CompositeKeySchema extends Schema {
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

/**
 * A composite primary key that (invalidly) includes an auto_increment column.
 *
 * @since 3.1.0
 */
class CompositeAutoIncrementSchema extends Schema {
	public $columns = array(
		array(
			'name'     => 'a',
			'type'     => 'bigint',
			'unsigned' => true,
			'primary'  => true,
			'extra'    => 'auto_increment',
		),
		array(
			'name'     => 'b',
			'type'     => 'bigint',
			'unsigned' => true,
			'primary'  => true,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'a', 'b' ),
		),
	);
}

/**
 * The installable table for the composite schema.
 *
 * @since 3.1.0
 */
class CompositeKeyTable extends Table {
	protected $prefix  = 'berlindb_database';
	protected $name    = 'composite_key_crud';
	protected $version = '1';
	protected $schema  = CompositeKeySchema::class;
}

/**
 * A Query over the composite table.
 *
 * @since 3.1.0
 */
class CompositeKeyQuery extends Query {
	protected $prefix           = 'berlindb_database';
	protected $table_name       = 'composite_key_crud';
	protected $table_alias      = 'ckc';
	protected $table_schema     = CompositeKeySchema::class;
	protected $item_name        = 'composite_key_crud';
	protected $item_name_plural = 'composite_key_cruds';
	protected $cache_group      = 'berlindb-composite-key-crud';
}

/**
 * update_item()/delete_item() address one composite-keyed row by its full key, leave
 * sibling rows (sharing the first key column) untouched, and fail closed on a scalar
 * or partial key.
 *
 * @since 3.1.0
 */
class CompositeKeyCrudTest extends TestCase {

	/** @var CompositeKeyTable */
	private static $table;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new CompositeKeyTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
	}

	public static function tearDownAfterClass(): void {
		self::$table->uninstall();

		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		/*
		 * Seed three rows; account_id = 1 appears TWICE (users 10 and 20). A working
		 * composite WHERE must touch exactly one of them, never both.
		 */
		global $wpdb;
		$wpdb->query( 'INSERT INTO `' . esc_sql( self::$table->get_table_name() ) . "` ( account_id, user_id, status ) VALUES ( 1, 10, 'active' ), ( 1, 20, 'active' ), ( 2, 10, 'active' )" );
	}

	/**
	 * Read a row's status straight from the DB, or null when the row is gone.
	 *
	 * @since 3.1.0
	 *
	 * @param int $account_id Account id.
	 * @param int $user_id    User id.
	 * @return string|null
	 */
	private function status_of( int $account_id, int $user_id ): ?string {
		global $wpdb;

		$status = $wpdb->get_var(
			$wpdb->prepare( 'SELECT status FROM `' . esc_sql( self::$table->get_table_name() ) . '` WHERE account_id = %d AND user_id = %d', $account_id, $user_id )
		);

		return ( null === $status )
			? null
			: (string) $status;
	}

	/**
	 * update_item() with the full key updates exactly the addressed row.
	 *
	 * @since 3.1.0
	 */
	public function test_update_by_full_key_targets_one_row() {
		$query = new CompositeKeyQuery();

		$this->assertTrue(
			$query->update_item(
				array(
					'account_id' => 1,
					'user_id'    => 10,
				),
				array( 'status' => 'updated' )
			)
		);

		$this->assertSame( 'updated', $this->status_of( 1, 10 ) );
		$this->assertSame( 'active', $this->status_of( 1, 20 ) );
		$this->assertSame( 'active', $this->status_of( 2, 10 ) );
	}

	/**
	 * delete_item() with the full key deletes exactly the addressed row.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_by_full_key_targets_one_row() {
		$query = new CompositeKeyQuery();

		$this->assertTrue(
			$query->delete_item(
				array(
					'account_id' => 1,
					'user_id'    => 10,
				)
			)
		);

		$this->assertNull( $this->status_of( 1, 10 ) );
		$this->assertSame( 'active', $this->status_of( 1, 20 ) );
		$this->assertSame( 'active', $this->status_of( 2, 10 ) );
	}

	/**
	 * A scalar id still fails closed on a composite key, touching nothing.
	 *
	 * @since 3.1.0
	 */
	public function test_scalar_id_fails_closed() {
		$query = new CompositeKeyQuery();

		$this->assertFalse( $query->update_item( 1, array( 'status' => 'nope' ) ) );
		$this->assertFalse( $query->delete_item( 1 ) );

		$this->assertSame( 'active', $this->status_of( 1, 10 ) );
		$this->assertSame( 'active', $this->status_of( 1, 20 ) );
	}

	/**
	 * A partial composite key (missing a key column) fails closed, touching nothing.
	 *
	 * @since 3.1.0
	 */
	public function test_partial_key_fails_closed() {
		$query = new CompositeKeyQuery();

		$this->assertFalse( $query->update_item( array( 'account_id' => 1 ), array( 'status' => 'nope' ) ) );
		$this->assertFalse( $query->delete_item( array( 'account_id' => 1 ) ) );

		$this->assertSame( 'active', $this->status_of( 1, 10 ) );
		$this->assertSame( 'active', $this->status_of( 1, 20 ) );
	}

	/**
	 * A full key that matches no row returns false without error.
	 *
	 * @since 3.1.0
	 */
	public function test_update_nonexistent_row_returns_false() {
		$query = new CompositeKeyQuery();

		$this->assertFalse(
			$query->update_item(
				array(
					'account_id' => 99,
					'user_id'    => 99,
				),
				array( 'status' => 'nope' )
			)
		);
	}

	/**
	 * After a composite update, a fresh Query reading the same key sees the new value
	 * (the group cache generation was bumped, so the read is not stale).
	 *
	 * @since 3.1.0
	 */
	public function test_read_after_update_is_coherent() {
		$writer = new CompositeKeyQuery();
		$this->assertTrue(
			$writer->update_item(
				array(
					'account_id' => 1,
					'user_id'    => 20,
				),
				array( 'status' => 'coherent' )
			)
		);

		$reader = new CompositeKeyQuery(
			array(
				'account_id' => 1,
				'user_id'    => 20,
			)
		);

		$statuses = wp_list_pluck( $reader->items, 'status' );
		$this->assertContains( 'coherent', $statuses );
	}

	/**
	 * A false or empty-string key value fails closed (never a formatted "= 0"),
	 * touching nothing.
	 *
	 * @since 3.1.0
	 */
	public function test_false_and_empty_key_values_fail_closed() {
		$query = new CompositeKeyQuery();

		$this->assertFalse(
			$query->update_item(
				array(
					'account_id' => false,
					'user_id'    => 10,
				),
				array( 'status' => 'nope' )
			)
		);
		$this->assertFalse(
			$query->delete_item(
				array(
					'account_id' => 1,
					'user_id'    => '',
				)
			)
		);

		$this->assertSame( 'active', $this->status_of( 1, 10 ) );
	}

	/**
	 * On a composite key, a non-column (meta) key can't be honored (meta needs one
	 * object id), so the whole update is REJECTED rather than silently partly applied.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_rejected_on_composite_key() {
		$query = new CompositeKeyQuery();

		$this->assertFalse(
			$query->update_item(
				array(
					'account_id' => 1,
					'user_id'    => 10,
				),
				array(
					'status'    => 'updated',
					'some_meta' => 'value',
				)
			)
		);

		// Nothing was applied (the column write was rejected along with the meta).
		$this->assertSame( 'active', $this->status_of( 1, 10 ) );
		$this->assertNotEmpty( $query->get_logs( array( 'code' => 'crud' ) ) );
	}

	/**
	 * The {item}_deleted action receives the FULL composite key, so a listener can
	 * identify exactly which row was removed.
	 *
	 * @since 3.1.0
	 */
	public function test_deleted_action_receives_full_composite_key() {
		$received = null;
		add_action(
			'berlindb_database_composite_key_crud_deleted',
			function ( $id ) use ( &$received ) {
				$received = $id;
			},
			10,
			1
		);

		$query = new CompositeKeyQuery();
		$this->assertTrue(
			$query->delete_item(
				array(
					'account_id' => 1,
					'user_id'    => 10,
				)
			)
		);

		$this->assertSame(
			array(
				'account_id' => 1,
				'user_id'    => 10,
			),
			$received
		);
	}

	/**
	 * A composite primary key that includes an auto_increment column is invalid at
	 * the schema (non-portable + contradictory) - fail at declaration, not at write.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_primary_key_with_auto_increment_is_invalid() {
		$schema = new CompositeAutoIncrementSchema();

		$this->assertFalse( $schema->is_valid() );
		$this->assertNotEmpty( preg_grep( '/AUTO_INCREMENT/', $schema->get_validation_errors() ) );
	}

	/**
	 * delete_items() by a query-var FILTER resolves each matched row's FULL composite
	 * key and deletes exactly those rows, not every row sharing the first key column (#241).
	 *
	 * @since 3.1.0
	 */
	public function test_delete_items_by_filter_targets_composite_rows() {
		$query = new CompositeKeyQuery();

		// account_id = 1 matches TWO rows: (1,10) and (1,20).
		$this->assertSame( 2, $query->delete_items( array( 'account_id' => 1 ) ) );

		$this->assertNull( $this->status_of( 1, 10 ) );
		$this->assertNull( $this->status_of( 1, 20 ) );
		$this->assertSame( 'active', $this->status_of( 2, 10 ) );
	}

	/**
	 * update_items() by a query-var FILTER writes $data to exactly the matched
	 * composite rows (#241).
	 *
	 * @since 3.1.0
	 */
	public function test_update_items_by_filter_targets_composite_rows() {
		$query = new CompositeKeyQuery();

		$this->assertSame( 2, $query->update_items( array( 'account_id' => 1 ), array( 'status' => 'updated' ) ) );

		$this->assertSame( 'updated', $this->status_of( 1, 10 ) );
		$this->assertSame( 'updated', $this->status_of( 1, 20 ) );
		$this->assertSame( 'active', $this->status_of( 2, 10 ) );
	}

	/**
	 * delete_items() accepts an explicit LIST of full composite-key maps and removes
	 * exactly those rows (#241).
	 *
	 * @since 3.1.0
	 */
	public function test_delete_items_by_explicit_key_list() {
		$query = new CompositeKeyQuery();

		$deleted = $query->delete_items(
			array(
				array(
					'account_id' => 1,
					'user_id'    => 10,
				),
				array(
					'account_id' => 2,
					'user_id'    => 10,
				),
			)
		);

		$this->assertSame( 2, $deleted );
		$this->assertNull( $this->status_of( 1, 10 ) );
		$this->assertSame( 'active', $this->status_of( 1, 20 ) );
		$this->assertNull( $this->status_of( 2, 10 ) );
	}

	/**
	 * A lone associative array is interpreted as a FILTER (not one literal key) - which
	 * for a full composite key matches exactly that one row and deletes it (#241).
	 *
	 * @since 3.1.0
	 */
	public function test_delete_items_lone_key_map_is_a_filter() {
		$query = new CompositeKeyQuery();

		$this->assertSame(
			1,
			$query->delete_items(
				array(
					'account_id' => 1,
					'user_id'    => 10,
				)
			)
		);

		$this->assertNull( $this->status_of( 1, 10 ) );
		$this->assertSame( 'active', $this->status_of( 1, 20 ) );
		$this->assertSame( 'active', $this->status_of( 2, 10 ) );
	}
}
