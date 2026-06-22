<?php
/**
 * Query get_results() wrapper tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use PHPUnit\Framework\TestCase;

/**
 * Test subject that captures arguments passed from get_results() to query().
 *
 * @since 3.0.0
 */
class QueryGetResultsTestSubject extends TestQuery {

	/**
	 * Last query arguments received by query().
	 *
	 * @since 3.0.0
	 * @var array<string, mixed>
	 */
	public $last_query_args = array();

	/**
	 * Capture query arguments without touching the database.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $query Query arguments.
	 * @return array<string, mixed>
	 */
	public function query( $query = array() ) {
		$this->last_query_args = $query;

		return $query;
	}
}

/**
 * Tests for Query::get_results().
 *
 * @since 3.0.0
 */
class QueryGetResultsTest extends TestCase {

	/**
	 * get_results() maps its convenience parameters to query() arguments.
	 *
	 * @since 3.0.0
	 */
	public function test_get_results_maps_parameters_to_query_arguments() {
		$query = new QueryGetResultsTestSubject();

		$result = $query->get_results(
			array( 'id', 'name' ),
			array( 'status' => 'active' ),
			10,
			20,
			ARRAY_A
		);

		$this->assertSame( $result, $query->last_query_args );
		$this->assertSame( array( 'id', 'name' ), $result['fields'] );
		$this->assertSame( 10, $result['number'] );
		$this->assertSame( 20, $result['offset'] );
		$this->assertSame( ARRAY_A, $result['output'] );
		$this->assertSame( 'active', $result['status'] );
		$this->assertFalse( $result['update_item_cache'] );
		$this->assertFalse( $result['update_meta_cache'] );
	}

	/**
	 * Values in $where_cols override the convenience defaults after parsing.
	 *
	 * @since 3.0.0
	 */
	public function test_where_cols_override_convenience_defaults() {
		$query = new QueryGetResultsTestSubject();

		$result = $query->get_results(
			array( 'id' ),
			array(
				'fields'            => 'ids',
				'number'            => 3,
				'offset'            => 6,
				'output'            => OBJECT_K,
				'update_item_cache' => true,
				'update_meta_cache' => true,
			),
			10,
			20,
			ARRAY_A
		);

		$this->assertSame( 'ids', $result['fields'] );
		$this->assertSame( 3, $result['number'] );
		$this->assertSame( 6, $result['offset'] );
		$this->assertSame( OBJECT_K, $result['output'] );
		$this->assertTrue( $result['update_item_cache'] );
		$this->assertTrue( $result['update_meta_cache'] );
	}

	/**
	 * get_results() defaults to a limited object query with cache priming off.
	 *
	 * @since 3.0.0
	 */
	public function test_get_results_default_arguments() {
		$query = new QueryGetResultsTestSubject();

		$result = $query->get_results();

		$this->assertSame( array(), $result['fields'] );
		$this->assertSame( 25, $result['number'] );
		$this->assertNull( $result['offset'] );
		$this->assertSame( OBJECT, $result['output'] );
		$this->assertFalse( $result['update_item_cache'] );
		$this->assertFalse( $result['update_meta_cache'] );
	}
}
