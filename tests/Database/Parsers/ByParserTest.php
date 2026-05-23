<?php
/**
 * By parser integration tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for the By parser, which maps bare column-name query vars to WHERE clauses.
 *
 * Five fixture rows:
 *  - Alpha Widget    | active   | priority 10
 *  - Beta Widget     | active   | priority 20
 *  - Gamma Gadget    | inactive | priority 30
 *  - Delta Gadget    | inactive | priority 40
 *  - Epsilon Widget  | pending  | priority 50
 *
 * The By parser fires for every registered column name used as a query var
 * directly (e.g. 'status' => 'active').
 *
 * @since 3.0.0
 */
class ByParserTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/** @var int[] IDs of the five fixture rows, refreshed in setUp(). */
	private $ids = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new TestQuery();
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

		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Alpha Widget',
				'status'   => 'active',
				'priority' => 10,
			)
		);
		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Beta Widget',
				'status'   => 'active',
				'priority' => 20,
			)
		);
		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Gamma Gadget',
				'status'   => 'inactive',
				'priority' => 30,
			)
		);
		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Delta Gadget',
				'status'   => 'inactive',
				'priority' => 40,
			)
		);
		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Epsilon Widget',
				'status'   => 'pending',
				'priority' => 50,
			)
		);

		wp_cache_flush();
	}

	/**
	 * Test that filtering by a single string column value returns matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_single_string_value_filter() {

		// Assert expected results.
		$results = self::$query->query( array( 'status' => 'active' ) );
		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}

	/**
	 * Test that filtering by integer column value returns matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_single_integer_value_filter() {

		// Assert expected results.
		$results = self::$query->query( array( 'priority' => 30 ) );
		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that filtering by value matching no rows returns empty.
	 *
	 * @since 3.0.0
	 */
	public function test_no_match_returns_empty() {

		// Assert expected results.
		$results = self::$query->query( array( 'status' => 'archived' ) );
		$this->assertCount( 0, $results );
	}

	/**
	 * Test that combining two column filters narrows results.
	 *
	 * @since 3.0.0
	 */
	public function test_combined_column_filters() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'status'   => 'inactive',
				'priority' => 40,
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Delta Gadget', $results[0]->name );
	}

	/**
	 * Test that filtering by id returns the correct single row.
	 *
	 * @since 3.0.0
	 */
	public function test_filter_by_id_column() {

		// Assert expected results.
		$target  = $this->ids[2]; // Gamma Gadget
		$results = self::$query->query( array( 'id' => $target ) );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that count mode returns the correct count for a by-column filter.
	 *
	 * @since 3.0.0
	 */
	public function test_by_filter_with_count_mode() {

		// Assert expected results.
		$count = self::$query->query(
			array(
				'status' => 'inactive',
				'count'  => true,
			)
		);

		$this->assertSame( 2, (int) $count );
	}
}
