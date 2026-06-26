<?php
/**
 * Cache-key segmentation guard tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Guards Query::get_cache_key() against the class of bug where a results-invariant
 * query var (one that changes behaviour but NOT which rows a query returns) leaks
 * into the result-cache key and needlessly fragments it.
 *
 * The result cache stores the matching primary-ID set, so two queries that differ
 * only by such a var return identical IDs and MUST share one cache entry. The
 * authoritative exclusion list is Query::RESULTS_INVARIANT_VARS (e.g. 'with',
 * 'index_hints'). These tests:
 *
 *  - prove each listed var really does not segment the key (and a real filter does),
 *  - lock the list against drift, and
 *  - FAIL when a new clause key is introduced without classifying its cache
 *    behaviour - the forcing function the maintainer asked for.
 *
 * @since 3.1.0
 */
class CacheKeyGuardTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/**
	 * The complete set of SQL clause keys the SELECT assembly produces. A new clause
	 * key (the usual home of a new query-var feature) must be added here, which is
	 * the moment to decide whether its backing var belongs in RESULTS_INVARIANT_VARS.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_CLAUSE_KEYS = array(
		'explain',
		'select',
		'distinct',
		'fields',
		'from',
		'index_hints',
		'join',
		'where',
		'groupby',
		'orderby',
		'limits',
	);

	/**
	 * The reviewed set of results-invariant vars (must match RESULTS_INVARIANT_VARS).
	 *
	 * @var list<string>
	 */
	private const EXPECTED_INVARIANT = array( 'fields', 'cache_results', 'with', 'index_hints' );

	/**
	 * Install the fixture table before tests run.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
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
	 * Compute the result-cache key for a set of query args.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return string
	 */
	private function cache_key( array $args ): string {
		$method = new \ReflectionMethod( TestQuery::class, 'get_cache_key' );

		return (string) $method->invoke( new TestQuery( $args ) );
	}

	/**
	 * Read a private value from the Query class via reflection.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	private function results_invariant_vars(): array {
		$const = new \ReflectionClassConstant( Query::class, 'RESULTS_INVARIANT_VARS' );

		/** @var list<string> $value */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$value = $const->getValue();

		return $value;
	}

	/**
	 * Read the Query's merged query_var_defaults keys via reflection.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed>
	 */
	private function query_var_defaults(): array {
		$prop = new \ReflectionProperty( Query::class, 'query_var_defaults' );
		$prop->setAccessible( true );

		/** @var array<string,mixed> $value */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$value = $prop->getValue( new TestQuery() );

		return $value;
	}

	/**
	 * Test that no results-invariant var segments the cache key: a query differing
	 * only by one of them shares the baseline's cache entry.
	 *
	 * @since 3.1.0
	 */
	public function test_results_invariant_vars_do_not_segment_cache_key() {
		$baseline = $this->cache_key( array( 'number' => 10 ) );

		$non_default = array(
			'fields'        => 'id',
			'cache_results' => false,
			'with'          => array( 'rel' ),
			'index_hints'   => array(
				'type'    => 'force',
				'indexes' => array( 'status' ),
			),
		);

		foreach ( $this->results_invariant_vars() as $var ) {
			$this->assertArrayHasKey(
				$var,
				$non_default,
				"Add a non-default sample for the new results-invariant var '{$var}'."
			);

			$variant = $this->cache_key(
				array(
					'number' => 10,
					$var     => $non_default[ $var ],
				)
			);

			$this->assertSame(
				$baseline,
				$variant,
				"Results-invariant var '{$var}' must NOT segment the cache key."
			);
		}
	}

	/**
	 * Test the specific regression: index_hints does not segment the cache key.
	 *
	 * @since 3.1.0
	 */
	public function test_index_hints_does_not_segment_cache_key() {
		$plain = $this->cache_key( array( 'status' => 'active' ) );

		$hinted = $this->cache_key(
			array(
				'status'      => 'active',
				'index_hints' => array(
					'type'    => 'force',
					'indexes' => array( 'status' ),
				),
			)
		);

		$this->assertSame( $plain, $hinted );
	}

	/**
	 * Test the methodology: a results-affecting var DOES segment the cache key, so
	 * the same-key assertions above are meaningful.
	 *
	 * @since 3.1.0
	 */
	public function test_results_affecting_vars_segment_cache_key() {
		$baseline = $this->cache_key( array( 'number' => 10 ) );

		$this->assertNotSame( $baseline, $this->cache_key( array( 'number' => 20 ) ) );
		$this->assertNotSame(
			$baseline,
			$this->cache_key(
				array(
					'number'  => 10,
					'orderby' => 'name',
				)
			)
		);
		$this->assertNotSame(
			$baseline,
			$this->cache_key(
				array(
					'number'   => 10,
					'distinct' => true,
				)
			)
		);
	}

	/**
	 * Test that RESULTS_INVARIANT_VARS matches the reviewed list and contains only
	 * real query vars (no typos, no drift).
	 *
	 * @since 3.1.0
	 */
	public function test_results_invariant_vars_is_classified_and_real() {
		$actual   = $this->results_invariant_vars();
		$expected = self::EXPECTED_INVARIANT;

		sort( $actual );
		sort( $expected );

		$this->assertSame(
			$expected,
			$actual,
			'RESULTS_INVARIANT_VARS changed. Update EXPECTED_INVARIANT here and make sure '
			. 'every entry is a var that does not change which rows a query returns.'
		);

		$defaults = $this->query_var_defaults();

		foreach ( $actual as $var ) {
			$this->assertArrayHasKey(
				$var,
				$defaults,
				"RESULTS_INVARIANT_VARS lists '{$var}', which is not a known query var."
			);
		}
	}

	/**
	 * The forcing function: every SQL clause key must be accounted for here. Adding a
	 * clause key (the usual home of a new query-var feature) fails this test until the
	 * author classifies its cache behaviour - and, if results-invariant, adds the
	 * backing var to Query::RESULTS_INVARIANT_VARS so it is excluded from the key.
	 *
	 * @since 3.1.0
	 */
	public function test_new_clause_keys_are_flagged_for_cache_classification() {
		$parse = new \ReflectionMethod( TestQuery::class, 'parse_query_vars' );

		$clauses = $parse->invoke( new TestQuery( array( 'number' => 1 ) ) );
		$this->assertIsArray( $clauses );

		$actual   = array_keys( $clauses );
		$expected = self::EXPECTED_CLAUSE_KEYS;

		sort( $actual );
		sort( $expected );

		$this->assertSame(
			$expected,
			$actual,
			'A SQL clause key was added or removed. Classify its cache behaviour: if the '
			. "query var backing it does NOT change which rows return (like 'index_hints' "
			. "or 'with'), add that var to Query::RESULTS_INVARIANT_VARS so it is excluded "
			. 'from the result-cache key. Then update EXPECTED_CLAUSE_KEYS here.'
		);
	}
}
