<?php
/**
 * ORDER BY expression (operand-spec orderby) tests.
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
 * Tests for ordering by an operand expression, e.g.
 * `orderby => array( 'operand' => 'func', 'name' => 'LENGTH', ... )` ->
 * `ORDER BY LENGTH( name )`. Operand specs are resolved through the same machinery
 * as WHERE (schema-checked column, allow-listed function), so an unresolvable or
 * non-scalar spec is dropped (ORDER BY never changes which rows match). The SELECT
 * is captured through the WordPress 'query' filter.
 *
 * @since 3.1.0
 */
class OrderByExpressionTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/**
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
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		self::$table->delete_all();
		wp_cache_flush();

		self::$query->add_item(
			array(
				'name'     => 'Alpha',
				'status'   => 'active',
				'priority' => 10,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Bo',
				'status'   => 'active',
				'priority' => 20,
			)
		);

		wp_cache_flush();
	}

	/**
	 * Capture every SQL statement run during the callback, newline-joined.
	 *
	 * @since 3.1.0
	 *
	 * @param callable $callback Code to run while capturing.
	 * @return string
	 */
	private function captured_sql( callable $callback ): string {
		$queries = array();

		$filter = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};

		add_filter( 'query', $filter );
		$callback();
		remove_filter( 'query', $filter );

		return implode( "\n", $queries );
	}

	/**
	 * Run a read with the given orderby/order and return the captured SQL.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed  $orderby The orderby query var.
	 * @param string $order   The order direction.
	 * @return string
	 */
	private function orderby_sql( $orderby, string $order = 'DESC' ): string {
		return $this->captured_sql(
			function () use ( $orderby, $order ) {
				self::$query->query(
					array(
						'orderby'       => $orderby,
						'order'         => $order,
						'cache_results' => false,
					)
				);
			}
		);
	}

	/**
	 * A function operand orders by the rendered expression.
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_function_expression() {
		$sql = $this->orderby_sql(
			array(
				'operand' => 'func',
				'name'    => 'LENGTH',
				'args'    => array(
					array(
						'operand' => 'column',
						'name'    => 'name',
					),
				),
			),
			'ASC'
		);

		$this->assertStringContainsString( 'ORDER BY', $sql );
		$this->assertStringContainsString( 'LENGTH(', $sql );
		$this->assertStringContainsString( '`name`', $sql );
		$this->assertMatchesRegularExpression( '/LENGTH\([^)]*`name`[^)]*\)\s+ASC/i', $sql );
	}

	/**
	 * A column operand orders by the referenced column.
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_column_operand() {
		$sql = $this->orderby_sql(
			array(
				'operand' => 'column',
				'name'    => 'priority',
			),
			'DESC'
		);

		$this->assertStringContainsString( 'ORDER BY', $sql );
		$this->assertMatchesRegularExpression( '/`priority`\s+DESC/i', $sql );
	}

	/**
	 * A list mixes a plain column term and an expression term.
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_mixes_column_and_expression() {
		$sql = $this->orderby_sql(
			array(
				'priority',
				array(
					'operand' => 'func',
					'name'    => 'LENGTH',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'name',
						),
					),
				),
			),
			'ASC'
		);

		$this->assertStringContainsString( '`priority`', $sql );
		$this->assertStringContainsString( 'LENGTH(', $sql );
	}

	/**
	 * An unresolvable expression (unknown column) is dropped, not failed closed -
	 * ORDER BY never changes which rows match.
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_unresolvable_expression_is_dropped() {
		$sql = $this->orderby_sql(
			array(
				'operand' => 'func',
				'name'    => 'LENGTH',
				'args'    => array(
					array(
						'operand' => 'column',
						'name'    => 'nonexistent',
					),
				),
			)
		);

		// The bad term produced no ORDER BY expression, and nothing failed closed.
		$this->assertStringNotContainsString( 'LENGTH(', $sql );
		$this->assertStringNotContainsString( '1 = 0', $sql );
	}

	/**
	 * A non-scalar operand (a list) is not meaningful to sort by and is dropped.
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_non_scalar_operand_is_dropped() {
		$sql = $this->orderby_sql(
			array(
				'operand' => 'list',
				'items'   => array( 1, 2, 3 ),
			)
		);

		$this->assertStringNotContainsString( '( 1, 2, 3 )', $sql );
		$this->assertStringNotContainsString( '1 = 0', $sql );
	}

	/**
	 * A plain column-name orderby still works unchanged.
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_plain_column_still_works() {
		$sql = $this->orderby_sql( 'name', 'ASC' );

		$this->assertMatchesRegularExpression( '/`name`\s+ASC/i', $sql );
	}

	/**
	 * The historical column => direction map still keys by column with per-column
	 * direction (the restructure preserves it exactly).
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_assoc_direction_map_preserved() {
		$sql = $this->orderby_sql(
			array(
				'name'     => 'ASC',
				'priority' => 'DESC',
			)
		);

		$this->assertMatchesRegularExpression( '/`name`\s+ASC/i', $sql );
		$this->assertMatchesRegularExpression( '/`priority`\s+DESC/i', $sql );
	}

	/**
	 * A numeric list of column names still orders each by the shared direction.
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_numeric_list_preserved() {
		$sql = $this->orderby_sql( array( 'name', 'priority' ), 'DESC' );

		$this->assertMatchesRegularExpression( '/`name`\s+DESC/i', $sql );
		$this->assertMatchesRegularExpression( '/`priority`\s+DESC/i', $sql );
	}
}
