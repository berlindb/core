<?php
/**
 * meta_query translation for store-backed objects (#204 Phase B).
 *
 * A query whose 'meta' relationship resolves to a MetaStore has its
 * meta_query / meta_key / meta_value vars translated into relationship EXISTS
 * filters against the custom sibling table (Query::resolve_meta_query_filters()).
 * These tests prove behavioral parity with the WordPress meta_query surface by
 * asserting the rows returned against a real installed sibling table — the SQL is
 * relationship EXISTS rather than the bespoke JOIN engine, so results, not SQL,
 * are what must match.
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
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use BerlinDB\Database\Presets\Meta\Query as MetaQuery;
use BerlinDB\Database\Presets\Meta\Table as MetaTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

// ---------------------------------------------------------------------------
// Fixtures: a store-backed primary + meta sibling.
// ---------------------------------------------------------------------------

/** Primary schema declaring the has_many to its meta sibling. */
class MqThingSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'primary'       => true,
			'extra'         => 'auto_increment',
			'relationships' => array(
				array(
					'query'  => MqThingMetaQuery::class,
					'column' => 'thing_id',
					'type'   => 'has_many',
					'name'   => 'meta',
				),
			),
		),
		array(
			'name'   => 'label',
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

/** Primary Query (store-backed: it declares a 'meta' has_many). */
class MqThingQuery extends Query {
	protected $prefix       = 'mqt';
	protected $table_name   = 'things';
	protected $table_schema = MqThingSchema::class;
	protected $item_name    = 'thing';
	protected $cache_group  = 'mqt_things';

	/** Expose a (post-normalization) query var for the cache-safety assertion. */
	public function peek_query_var( $key ) {
		return $this->get_query_var( $key );
	}
}

/** Primary Table. */
class MqThingTable extends Table {
	protected $prefix  = 'mqt';
	protected $name    = 'things';
	protected $version = '1.0.0';
	protected $schema  = MqThingSchema::class;
}

/** Meta Query + Table stubs. */
class MqThingMetaQuery extends MetaQuery {
	protected $primary_query_class = MqThingQuery::class;
}
class MqThingMetaTable extends MetaTable {
	protected $meta_query_class = MqThingMetaQuery::class;
}

/** A schema/query with NO meta relationship (the legacy, no-store path). */
class MqNoStoreSchema extends Schema {
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'primary'  => true,
			'extra'    => 'auto_increment',
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}
class MqNoStoreQuery extends Query {
	protected $prefix       = 'mqt';
	protected $table_name   = 'nostore';
	protected $table_schema = MqNoStoreSchema::class;
	protected $item_name    = 'nostore';
	protected $cache_group  = 'mqt_nostore';

	/** Expose a query var after construction for the no-store assertion. */
	public function peek_query_var( $key ) {
		return $this->get_query_var( $key );
	}
}

/** Table for the no-store query, so constructing it does not hit a missing table. */
class MqNoStoreTable extends Table {
	protected $prefix  = 'mqt';
	protected $name    = 'nostore';
	protected $version = '1.0.0';
	protected $schema  = MqNoStoreSchema::class;
}

/**
 * Tests for meta_query translation.
 *
 * @since 3.1.0
 */
class MetaQueryTranslationTest extends TestCase {

	/** @var MqThingTable */
	private static $table;

	/** @var MqThingMetaTable */
	private static $meta_table;

	/** @var MqNoStoreTable */
	private static $no_store_table;

	/** @var array<string, int> label => thing ID for the current test. */
	private $ids = array();

	/**
	 * Install the tables once.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table          = new MqThingTable();
		self::$meta_table     = new MqThingMetaTable();
		self::$no_store_table = new MqNoStoreTable();

		if ( ! self::$table->exists() ) {
			self::$table->install();
		}

		if ( ! self::$meta_table->exists() ) {
			self::$meta_table->install();
		}

		// Installed so the no-store query has a real table to construct against.
		if ( ! self::$no_store_table->exists() ) {
			self::$no_store_table->install();
		}
	}

	/**
	 * Uninstall the tables after the class.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$no_store_table->uninstall();
		self::$meta_table->uninstall();
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Seed three things with meta before each test.
	 *
	 * A: color=blue, size=large, score=10
	 * B: color=red,  size=large, score=100
	 * C: (no color), size=small, score=9
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		self::$table->delete_all();
		self::$meta_table->delete_all();
		wp_cache_flush();

		$things = new MqThingQuery();
		$store  = new MqThingMetaQuery();

		foreach ( array( 'A', 'B', 'C' ) as $label ) {
			$this->ids[ $label ] = (int) $things->add_item( array( 'label' => $label ) );
		}

		$store->add_meta( $this->ids['A'], 'color', 'blue' );
		$store->add_meta( $this->ids['A'], 'size', 'large' );
		$store->add_meta( $this->ids['A'], 'score', '10' );

		$store->add_meta( $this->ids['B'], 'color', 'red' );
		$store->add_meta( $this->ids['B'], 'size', 'large' );
		$store->add_meta( $this->ids['B'], 'score', '100' );

		$store->add_meta( $this->ids['C'], 'size', 'small' );
		$store->add_meta( $this->ids['C'], 'score', '9' );

		wp_cache_flush();
	}

	/**
	 * Run a query and return the matched labels, sorted.
	 *
	 * @param array<string, mixed> $args Query vars.
	 * @return list<string>
	 */
	private function labels( array $args ): array {
		$ordered = $this->ordered_labels( $args );

		sort( $ordered );

		return $ordered;
	}

	/**
	 * Run a query and return the matched labels in RESULT ORDER (for orderby).
	 *
	 * @param array<string, mixed> $args Query vars.
	 * @return list<string>
	 */
	private function ordered_labels( array $args ): array {
		$results = ( new MqThingQuery() )->query( $args );
		$labels  = array();

		foreach ( (array) $results as $row ) {
			$labels[] = $row->label;
		}

		return $labels;
	}

	/**
	 * A first-order associative meta_query is treated as one clause (not widened).
	 *
	 * @since 3.1.0
	 */
	public function test_first_order_associative_meta_query() {
		$this->assertSame(
			array( 'A' ),
			$this->labels(
				array(
					'meta_query' => array(
						'key'   => 'color',
						'value' => 'blue',
					),
				)
			)
		);
	}

	/**
	 * Shorthand meta_key / meta_value filters.
	 *
	 * @since 3.1.0
	 */
	public function test_shorthand_key_value() {
		$this->assertSame(
			array( 'B' ),
			$this->labels(
				array(
					'meta_key'   => 'color',
					'meta_value' => 'red',
				)
			)
		);
	}

	/**
	 * Shorthand combined with an explicit meta_query (AND).
	 *
	 * @since 3.1.0
	 */
	public function test_shorthand_plus_explicit_meta_query() {
		$this->assertSame(
			array( 'B' ),
			$this->labels(
				array(
					'meta_key'   => 'size',
					'meta_value' => 'large',
					'meta_query' => array(
						array(
							'key'   => 'color',
							'value' => 'red',
						),
					),
				)
			)
		);
	}

	/**
	 * An OR group matches a different related row per branch.
	 *
	 * @since 3.1.0
	 */
	public function test_or_group() {
		$this->assertSame(
			array( 'A', 'C' ),
			$this->labels(
				array(
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'   => 'color',
							'value' => 'blue',
						),
						array(
							'key'   => 'size',
							'value' => 'small',
						),
					),
				)
			)
		);
	}

	/**
	 * A translated meta_query ANDs with an existing OR relation_query, not OR.
	 *
	 * The pre-existing relation_query is a top-level OR group (has 'color' OR
	 * size='small' → A, B, C). The meta_query (size='large' → A, B) must AND with
	 * that whole group, giving (A,B,C) AND (A,B) = A, B. The earlier bug appended
	 * the meta clause INTO the OR list, widening the result to A, B, C
	 * (color OR small OR large).
	 *
	 * @since 3.1.0
	 */
	public function test_meta_query_ands_with_existing_or_relation_query() {
		$this->assertSame(
			array( 'A', 'B' ),
			$this->labels(
				array(
					'relation_query' => array(
						'relation' => 'OR',
						array(
							'name'  => 'meta',
							'where' => array(
								'meta_key' => 'color',
							),
						),
						array(
							'name'  => 'meta',
							'where' => array(
								'meta_key'   => 'size',
								'meta_value' => array(
									'compare' => '=',
									'value'   => 'small',
								),
							),
						),
					),
					'meta_query'     => array(
						array(
							'key'   => 'size',
							'value' => 'large',
						),
					),
				)
			)
		);
	}

	/**
	 * EXISTS / NOT EXISTS on a key.
	 *
	 * @since 3.1.0
	 */
	public function test_exists_and_not_exists() {
		$this->assertSame(
			array( 'A', 'B' ),
			$this->labels(
				array(
					'meta_query' => array(
						array(
							'key'     => 'color',
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);

		$this->assertSame(
			array( 'C' ),
			$this->labels(
				array(
					'meta_query' => array(
						array(
							'key'     => 'color',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			)
		);
	}

	/**
	 * A numeric type casts the value column, distinguishing lexical from numeric.
	 *
	 * score >= 10 numeric matches 10 and 100 but NOT 9; lexically '9' >= '10' is
	 * true, so a passing result proves the CAST was applied.
	 *
	 * @since 3.1.0
	 */
	public function test_numeric_type_casts_value() {
		$this->assertSame(
			array( 'A', 'B' ),
			$this->labels(
				array(
					'meta_query' => array(
						array(
							'key'     => 'score',
							'value'   => 10,
							'compare' => '>=',
							'type'    => 'NUMERIC',
						),
					),
				)
			)
		);
	}

	/**
	 * LIKE and IN value comparisons (reusing the shared Operators).
	 *
	 * @since 3.1.0
	 */
	public function test_like_and_in_value() {
		$this->assertSame(
			array( 'A' ),
			$this->labels(
				array(
					'meta_query' => array(
						array(
							'key'     => 'color',
							'value'   => 'lu',
							'compare' => 'LIKE',
						),
					),
				)
			)
		);

		$this->assertSame(
			array( 'A', 'B' ),
			$this->labels(
				array(
					'meta_query' => array(
						array(
							'key'     => 'color',
							'value'   => array( 'blue', 'red' ),
							'compare' => 'IN',
						),
					),
				)
			)
		);
	}

	/**
	 * A malformed meta_query member fails closed (no rows), not widened.
	 *
	 * @since 3.1.0
	 */
	public function test_malformed_member_fails_closed() {
		$this->assertSame(
			array(),
			$this->labels(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						'not-an-array-member',
					),
				)
			)
		);
	}

	/**
	 * Every unsupported negative compare_key fails closed (no rows) and logs.
	 *
	 * meta_key_condition() only translates =, EXISTS, IN, LIKE, REGEXP, RLIKE; every
	 * other (negative) key comparison returns null so the whole translation fails
	 * closed rather than mistranslating. Covers the breadth beyond NOT LIKE.
	 *
	 * @dataProvider provide_unsupported_compare_keys
	 * @since 3.1.0
	 *
	 * @param string $compare_key The unsupported key comparison.
	 */
	public function test_unsupported_compare_key_fails_closed( string $compare_key ) {
		$query = new MqThingQuery();

		$results = $query->query(
			array(
				'meta_query' => array(
					array(
						'key'         => 'color',
						'compare_key' => $compare_key,
					),
				),
			)
		);

		$this->assertSame( array(), (array) $results, "{$compare_key} should fail closed" );
		$this->assertNotEmpty( $query->get_logs( array( 'code' => 'meta_query' ) ), "{$compare_key} should log a meta_query warning" );
	}

	/**
	 * Unsupported negative key comparisons (the meta_key_condition() default arm).
	 *
	 * @return array<string, array{string}>
	 */
	public function provide_unsupported_compare_keys(): array {
		return array(
			'not equal'  => array( '!=' ),
			'not in'     => array( 'NOT IN' ),
			'not like'   => array( 'NOT LIKE' ),
			'not regexp' => array( 'NOT REGEXP' ),
			'not exists' => array( 'NOT EXISTS' ),
		);
	}

	/**
	 * A query with no meta store does NOT have its meta vars translated/stripped.
	 *
	 * @since 3.1.0
	 */
	public function test_no_store_path_is_untouched() {
		$query = new MqNoStoreQuery(
			array(
				'meta_key'   => 'color',
				'meta_value' => 'blue',
			)
		);

		// The mapper bailed (no store), so the meta vars survive for the Meta parser.
		$this->assertSame( 'color', $query->peek_query_var( 'meta_key' ) );
		$this->assertSame( 'blue', $query->peek_query_var( 'meta_value' ) );
	}

	/**
	 * orderby => 'meta_value' sorts store-backed rows LEXICALLY by the key's value.
	 *
	 * scores '10','100','9' sort lexically as 10 < 100 < 9 → A, B, C ascending.
	 *
	 * @since 3.1.0
	 */
	public function test_store_orderby_meta_value_lexical() {
		$args = array(
			'meta_key' => 'score',
			'orderby'  => 'meta_value',
			'order'    => 'ASC',
		);

		$this->assertSame( array( 'A', 'B', 'C' ), $this->ordered_labels( $args ) );

		$args[ 'order' ] = 'DESC';
		$this->assertSame( array( 'C', 'B', 'A' ), $this->ordered_labels( $args ) );
	}

	/**
	 * orderby => 'meta_value_num' sorts NUMERICALLY (CAST … AS SIGNED).
	 *
	 * scores 9,10,100 sort numerically → C, A, B ascending — distinct from the
	 * lexical order above, proving the SIGNED cast is applied.
	 *
	 * @since 3.1.0
	 */
	public function test_store_orderby_meta_value_num_numeric() {
		$args = array(
			'meta_key' => 'score',
			'orderby'  => 'meta_value_num',
			'order'    => 'ASC',
		);

		$this->assertSame( array( 'C', 'A', 'B' ), $this->ordered_labels( $args ) );

		$args[ 'order' ] = 'DESC';
		$this->assertSame( array( 'B', 'A', 'C' ), $this->ordered_labels( $args ) );
	}

	/**
	 * The store meta-orderby directive is cache-safe BECAUSE relation_query carries
	 * the ordered key.
	 *
	 * The build-time `meta_orderby` directive is unregistered (not in the
	 * cache key). It is safe anyway: ordering by different keys produces different
	 * `relation_query` (the EXISTS filter, which IS registered and cache-keyed), so
	 * the two queries never collide. meta_key itself is stripped during translation.
	 *
	 * @since 3.1.0
	 */
	public function test_store_meta_orderby_key_is_cache_safe_because_relation_query_carries_key() {
		$by_color = new MqThingQuery(
			array(
				'meta_key' => 'color',
				'orderby'  => 'meta_value',
				'fields'   => 'ids',
			)
		);

		$by_size = new MqThingQuery(
			array(
				'meta_key' => 'size',
				'orderby'  => 'meta_value',
				'fields'   => 'ids',
			)
		);

		// meta_key was stripped; the registered, cache-keyed relation_query differs.
		$this->assertNull( $by_color->peek_query_var( 'meta_key' ) );
		$this->assertNotEquals(
			$by_color->peek_query_var( 'relation_query' ),
			$by_size->peek_query_var( 'relation_query' )
		);
	}

	/**
	 * For a multi-valued key, the orderby subquery uses the OLDEST row (meta_id ASC).
	 *
	 * C is given a second, larger score (999) with a newer meta_id. Ordering by
	 * meta_value_num ASC must still place C first (its older value, 9) — if the
	 * newer row won, C (999) would sort last.
	 *
	 * @since 3.1.0
	 */
	public function test_store_orderby_uses_oldest_meta_id_for_multivalued_key() {
		( new MqThingMetaQuery() )->add_meta( $this->ids['C'], 'score', '999' );

		$this->assertSame(
			array( 'C', 'A', 'B' ),
			$this->ordered_labels(
				array(
					'meta_key' => 'score',
					'orderby'  => 'meta_value_num',
					'order'    => 'ASC',
				)
			)
		);
	}

	/**
	 * orderby by a NAMED meta_query clause filters and sorts by that clause's key.
	 *
	 * The named 'score_clause' (type NUMERIC) both filters (has score) and is the
	 * sort key; its NUMERIC type casts to SIGNED → numeric order 9,10,100 → C,A,B.
	 *
	 * @since 3.1.0
	 */
	public function test_store_orderby_named_clause() {
		$this->assertSame(
			array( 'C', 'A', 'B' ),
			$this->ordered_labels(
				array(
					'meta_query' => array(
						'score_clause' => array(
							'key'  => 'score',
							'type' => 'NUMERIC',
						),
					),
					'orderby'    => 'score_clause',
					'order'      => 'ASC',
				)
			)
		);
	}

	/**
	 * The array orderby form ( 'clause' => 'DESC' ) resolves the named clause too.
	 *
	 * @since 3.1.0
	 */
	public function test_store_orderby_named_clause_array_form() {
		$this->assertSame(
			array( 'B', 'A', 'C' ),
			$this->ordered_labels(
				array(
					'meta_query' => array(
						'score_clause' => array(
							'key'  => 'score',
							'type' => 'NUMERIC',
						),
					),
					'orderby'    => array( 'score_clause' => 'DESC' ),
				)
			)
		);
	}
}
