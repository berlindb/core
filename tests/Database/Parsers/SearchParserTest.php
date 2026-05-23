<?php
/**
 * Search parser integration tests.
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
 * Tests for the Search parser via the 'search' query var.
 *
 * Five fixture rows:
 *  - Alpha Widget    | active   | priority 10
 *  - Beta Widget     | active   | priority 20
 *  - Gamma Gadget    | inactive | priority 30
 *  - Delta Gadget    | inactive | priority 40
 *  - Epsilon Widget  | pending  | priority 50
 *
 * The TestSchema marks only the 'name' column as searchable.
 * The Search parser uses LIKE with % wildcards around each term by default;
 * passing a leading/trailing '*' anchors the search.
 *
 * @since 3.0.0
 */
class SearchParserTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

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

		self::$query->add_item(
			array(
				'name'     => 'Alpha Widget',
				'status'   => 'active',
				'priority' => 10,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Beta Widget',
				'status'   => 'active',
				'priority' => 20,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Gamma Gadget',
				'status'   => 'inactive',
				'priority' => 30,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Delta Gadget',
				'status'   => 'inactive',
				'priority' => 40,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Epsilon Widget',
				'status'   => 'pending',
				'priority' => 50,
			)
		);

		wp_cache_flush();
	}

	/**
	 * Test that searching for a substring returns all rows whose name contains it.
	 *
	 * @since 3.0.0
	 */
	public function test_substring_search_returns_matching_rows() {

		// Assert expected results.
		$results = self::$query->query( array( 'search' => 'Widget' ) );
		$this->assertCount( 3, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that a search term matching no rows returns empty.
	 *
	 * @since 3.0.0
	 */
	public function test_no_match_returns_empty() {

		// Assert expected results.
		$results = self::$query->query( array( 'search' => 'Zeta' ) );
		$this->assertCount( 0, $results );
	}

	/**
	 * Test that searching for a term common to all names returns all rows.
	 *
	 * @since 3.0.0
	 */
	public function test_common_term_returns_all_rows() {

		// "e" appears in every fixture name (widget/gadget contain 'e'; epsilon starts with 'E').
		$results = self::$query->query( array( 'search' => 'e' ) );
		$this->assertCount( 5, $results );
	}

	/**
	 * Test that a leading wildcard anchors the search to the end of the value.
	 *
	 * @since 3.0.0
	 */
	public function test_leading_wildcard_anchors_suffix() {

		// "*Widget" should only match names that end with "Widget".
		$results = self::$query->query( array( 'search' => '*Widget' ) );
		$this->assertCount( 3, $results );

		$names = wp_list_pluck( $results, 'name' );
		foreach ( $names as $name ) {
			$this->assertStringEndsWith( 'Widget', $name );
		}
	}

	/**
	 * Test that a trailing wildcard anchors the search to the beginning of the value.
	 *
	 * @since 3.0.0
	 */
	public function test_trailing_wildcard_anchors_prefix() {

		// "Alpha*" should only match names that start with "Alpha".
		$results = self::$query->query( array( 'search' => 'Alpha*' ) );
		$this->assertCount( 1, $results );
		$this->assertStringStartsWith( 'Alpha', $results[0]->name );
	}

	/**
	 * Test that search is case-insensitive (MySQL LIKE default).
	 *
	 * @since 3.0.0
	 */
	public function test_search_is_case_insensitive() {

		// Assert expected results.
		$results = self::$query->query( array( 'search' => 'gadget' ) );
		$this->assertCount( 2, $results );
	}

	/**
	 * Test that searching combined with another filter narrows results.
	 *
	 * @since 3.0.0
	 */
	public function test_search_combined_with_status_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'search' => 'Widget',
				'status' => 'active',
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}
}
