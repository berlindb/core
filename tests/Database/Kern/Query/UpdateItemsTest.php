<?php
/**
 * update_items() Operation tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * End-to-end tests for Query::update_items() and the Operations\Update it drives.
 *
 * update_items() resolves its input to a list of primary IDs and writes the same
 * $data to each through update_item(), so per-item validation, cache cleanup, and
 * the transition_{item}_{key} actions still fire. The input is a single ID, a list
 * of IDs, or a query-var filter (compiled to a WHERE via the parsers).
 *
 * Five fixture rows (name | status | priority):
 *  - Alpha Widget   | active   | 10
 *  - Beta Widget    | active   | 20
 *  - Gamma Gadget   | inactive | 30
 *  - Delta Gadget   | inactive | 40
 *  - Epsilon Widget | pending  | 50
 *
 * @since 3.1.0
 */
class UpdateItemsTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/** @var array<string,int> Fixture row name => primary ID, rebuilt each test. */
	private $ids = array();

	/**
	 * Install the fixture table and query object before tests run.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new TestQuery();
	}

	/**
	 * Uninstall the fixture table after tests complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset fixture data before each test, capturing each row's primary ID.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		self::$table->delete_all();
		wp_cache_flush();

		$this->ids = array();

		foreach (
			array(
				array( 'Alpha Widget', 'active', 10 ),
				array( 'Beta Widget', 'active', 20 ),
				array( 'Gamma Gadget', 'inactive', 30 ),
				array( 'Delta Gadget', 'inactive', 40 ),
				array( 'Epsilon Widget', 'pending', 50 ),
			) as $row
		) {
			$this->ids[ $row[0] ] = self::$query->add_item(
				array(
					'name'     => $row[0],
					'status'   => $row[1],
					'priority' => $row[2],
				)
			);
		}

		wp_cache_flush();
	}

	/**
	 * Get the status of a fixture row by name (fresh from the query).
	 *
	 * @since 3.1.0
	 *
	 * @param string $name Row name.
	 * @return string|null The status, or null if not found.
	 */
	private function status_of( string $name ): ?string {
		foreach ( self::$query->query( array( 'number' => 100 ) ) as $row ) {
			if ( $row->name === $name ) {
				return $row->status;
			}
		}

		return null;
	}

	/**
	 * Get the names of all rows with a given status, sorted.
	 *
	 * @since 3.1.0
	 *
	 * @param string $status Status to match.
	 * @return list<string>
	 */
	private function names_with_status( string $status ): array {
		$names = array();

		foreach ( self::$query->query( array( 'number' => 100 ) ) as $row ) {
			if ( $row->status === $status ) {
				$names[] = $row->name;
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * Test updating a single item by its primary ID.
	 *
	 * @since 3.1.0
	 */
	public function test_update_single_id() {
		$updated = self::$query->update_items( $this->ids[ 'Alpha Widget' ], array( 'status' => 'archived' ) );

		$this->assertSame( 1, $updated );
		$this->assertSame( 'archived', $this->status_of( 'Alpha Widget' ) );
		$this->assertSame( 'active', $this->status_of( 'Beta Widget' ) );
	}

	/**
	 * Test updating several items by a list of primary IDs.
	 *
	 * @since 3.1.0
	 */
	public function test_update_list_of_ids() {
		$updated = self::$query->update_items(
			array(
				$this->ids[ 'Alpha Widget' ],
				$this->ids[ 'Beta Widget' ],
			),
			array( 'status' => 'archived' )
		);

		$this->assertSame( 2, $updated );
		$this->assertSame(
			array( 'Alpha Widget', 'Beta Widget' ),
			$this->names_with_status( 'archived' )
		);
	}

	/**
	 * Test updating by a query-var filter: every active row becomes archived.
	 *
	 * @since 3.1.0
	 */
	public function test_update_by_filter() {
		$updated = self::$query->update_items( array( 'status' => 'active' ), array( 'status' => 'archived' ) );

		$this->assertSame( 2, $updated );
		$this->assertSame( array( 'Alpha Widget', 'Beta Widget' ), $this->names_with_status( 'archived' ) );
		$this->assertSame( array(), $this->names_with_status( 'active' ) );
	}

	/**
	 * Test that a non-sequential, integer-keyed ID array is treated as IDs.
	 *
	 * @since 3.1.0
	 */
	public function test_update_by_numeric_keyed_id_array() {
		$ids = array(
			2 => $this->ids[ 'Alpha Widget' ],
			5 => $this->ids[ 'Gamma Gadget' ],
		);

		$updated = self::$query->update_items( $ids, array( 'status' => 'archived' ) );

		$this->assertSame( 2, $updated );
		$this->assertSame( array( 'Alpha Widget', 'Gamma Gadget' ), $this->names_with_status( 'archived' ) );
	}

	/**
	 * Test that updating routes through update_item(): the transition action fires
	 * for the changed column (a bulk UPDATE would not fire it).
	 *
	 * @since 3.1.0
	 */
	public function test_update_routes_through_update_item() {
		$fired = array();

		add_action(
			'berlindb_database_transition_widget_status',
			static function ( $old, $new, $item_id ) use ( &$fired ) {
				$fired[] = array( $old, $new, $item_id );
			},
			10,
			3
		);

		$updated = self::$query->update_items( $this->ids[ 'Gamma Gadget' ], array( 'status' => 'archived' ) );

		$this->assertSame( 1, $updated );
		$this->assertCount( 1, $fired );
		$this->assertSame( 'inactive', $fired[0][0] );
		$this->assertSame( 'archived', $fired[0][1] );
	}

	/**
	 * Test that empty $data updates nothing.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_data_updates_nothing() {
		$this->assertFalse( self::$query->update_items( $this->ids[ 'Alpha Widget' ], array() ) );
		$this->assertSame( 'active', $this->status_of( 'Alpha Widget' ) );
	}

	/**
	 * Test that empty input updates nothing - the empty set never means "all".
	 *
	 * @since 3.1.0
	 */
	public function test_empty_input_updates_nothing() {
		$this->assertFalse( self::$query->update_items( array(), array( 'status' => 'archived' ) ) );
		$this->assertSame( array(), $this->names_with_status( 'archived' ) );
	}

	/**
	 * Test that a filter compiling to no WHERE is refused (never updates all).
	 *
	 * @since 3.1.0
	 */
	public function test_unconstrained_filter_is_refused() {
		$this->assertFalse(
			self::$query->update_items( array( 'not_a_real_column' => 'x' ), array( 'status' => 'archived' ) )
		);
		$this->assertSame( array(), $this->names_with_status( 'archived' ) );
	}
}
