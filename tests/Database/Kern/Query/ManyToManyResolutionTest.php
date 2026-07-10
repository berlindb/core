<?php
/**
 * many_to_many (pivot) resolution, end to end (#211 Lever D, phase 2).
 *
 * A Post relates to many Tags through a PostTag pivot table. get_related() must
 * walk both hops - this -> pivot (post_id) -> target (tag_id) - and return the
 * distinct target Rows, or an empty array when there is no relation.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Relationship;
use BerlinDB\Database\Kern\Row;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/** Post: this side; many_to_many Tags through PostTag. */
class M2MPostSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'    => 'slug',
			'type'    => 'varchar',
			'length'  => '100',
			'default' => '',
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'               => 'tags',
					'columns'            => array( 'id' ),
					'query'              => M2MTagQuery::class,
					'references'         => array( 'id' ),
					'through'            => M2MPostTagQuery::class,
					'through_columns'    => array( 'post_id' ),
					'through_references' => array( 'tag_id' ),
				)
			),
		);
	}
}

/** Tag: the target side. */
class M2MTagSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'    => 'name',
			'type'    => 'varchar',
			'length'  => '100',
			'default' => '',
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** PostTag: the pivot / junction table. */
class M2MPostTagSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'     => 'post_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'tag_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

class M2MPostRow extends Row {
	public $id   = 0;
	public $slug = '';
}
class M2MTagRow extends Row {
	public $id   = 0;
	public $name = '';
}
class M2MPostTagRow extends Row {
	public $id      = 0;
	public $post_id = 0;
	public $tag_id  = 0;
}

class M2MPostQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'm2m_post_test';
	protected $table_alias      = 'm2mp';
	protected $table_schema     = M2MPostSchema::class;
	protected $item_name        = 'm2m_post';
	protected $item_name_plural = 'm2m_posts';
	protected $item_shape       = M2MPostRow::class;
	protected $cache_group      = 'berlindb-m2m-post';
}
class M2MTagQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'm2m_tag_test';
	protected $table_alias      = 'm2mt';
	protected $table_schema     = M2MTagSchema::class;
	protected $item_name        = 'm2m_tag';
	protected $item_name_plural = 'm2m_tags';
	protected $item_shape       = M2MTagRow::class;
	protected $cache_group      = 'berlindb-m2m-tag';
}
class M2MPostTagQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'm2m_post_tag_test';
	protected $table_alias      = 'm2mpt';
	protected $table_schema     = M2MPostTagSchema::class;
	protected $item_name        = 'm2m_post_tag';
	protected $item_name_plural = 'm2m_post_tags';
	protected $item_shape       = M2MPostTagRow::class;
	protected $cache_group      = 'berlindb-m2m-post-tag';
}

class M2MPostTable extends Table {
	protected $schema  = M2MPostSchema::class;
	protected $name    = 'berlindb_m2m_post_test';
	protected $version = '202607090';
}
class M2MTagTable extends Table {
	protected $schema  = M2MTagSchema::class;
	protected $name    = 'berlindb_m2m_tag_test';
	protected $version = '202607090';
}
class M2MPostTagTable extends Table {
	protected $schema  = M2MPostTagSchema::class;
	protected $name    = 'berlindb_m2m_post_tag_test';
	protected $version = '202607090';
}

/**
 * End-to-end two-hop many_to_many resolution via get_related().
 *
 * @since 3.1.0
 */
class ManyToManyResolutionTest extends TestCase {

	/** @var M2MPostTable */
	private static $post_table;

	/** @var M2MTagTable */
	private static $tag_table;

	/** @var M2MPostTagTable */
	private static $pivot_table;

	/** @var M2MPostQuery */
	private static $posts;

	/** @var M2MTagQuery */
	private static $tags;

	/** @var M2MPostTagQuery */
	private static $pivots;

	/**
	 * Install the three tables.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$post_table = new M2MPostTable();
		if ( ! self::$post_table->exists() ) {
			self::$post_table->install();
		}
		self::$tag_table = new M2MTagTable();
		if ( ! self::$tag_table->exists() ) {
			self::$tag_table->install();
		}
		self::$pivot_table = new M2MPostTagTable();
		if ( ! self::$pivot_table->exists() ) {
			self::$pivot_table->install();
		}

		self::$posts  = new M2MPostQuery();
		self::$tags   = new M2MTagQuery();
		self::$pivots = new M2MPostTagQuery();
	}

	/**
	 * Reset rows before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		self::$pivot_table->delete_all();
		self::$tag_table->delete_all();
		self::$post_table->delete_all();
		wp_cache_flush();
	}

	/**
	 * Drop the tables after the class.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$pivot_table->uninstall();
		self::$tag_table->uninstall();
		self::$post_table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Return the sorted integer ids of a list of Rows.
	 *
	 * @since 3.1.0
	 *
	 * @param object[] $rows Rows to read ids from.
	 * @return int[] Sorted ids.
	 */
	private function ids( array $rows ): array {
		$ids = array_map(
			static function ( $row ) {
				return (int) $row->id;
			},
			$rows
		);
		sort( $ids );

		return $ids;
	}

	/**
	 * get_related() resolves the target rows through the pivot table.
	 *
	 * @since 3.1.0
	 */
	public function test_resolves_targets_through_pivot() {
		$post_id = self::$posts->add_item( array( 'slug' => 'hello' ) );
		$tag_a   = self::$tags->add_item( array( 'name' => 'alpha' ) );
		$tag_b   = self::$tags->add_item( array( 'name' => 'beta' ) );
		self::$tags->add_item( array( 'name' => 'gamma' ) ); // unlinked decoy

		self::$pivots->add_item(
			array(
				'post_id' => $post_id,
				'tag_id'  => $tag_a,
			)
		);
		self::$pivots->add_item(
			array(
				'post_id' => $post_id,
				'tag_id'  => $tag_b,
			)
		);

		$post = self::$posts->get_item( $post_id );
		$tags = self::$posts->get_related( $post, 'tags' );

		$this->assertIsArray( $tags );
		$this->assertSame(
			array( (int) $tag_a, (int) $tag_b ),
			$this->ids( $tags )
		);
	}

	/**
	 * A post with no pivot rows resolves to an empty target set.
	 *
	 * @since 3.1.0
	 */
	public function test_no_pivot_rows_resolves_empty() {
		$post_id = self::$posts->add_item( array( 'slug' => 'lonely' ) );
		self::$tags->add_item( array( 'name' => 'alpha' ) ); // exists but unlinked

		$post = self::$posts->get_item( $post_id );

		$this->assertSame( array(), self::$posts->get_related( $post, 'tags' ) );
	}

	/**
	 * A target reached via more than one pivot row appears only once.
	 *
	 * @since 3.1.0
	 */
	public function test_duplicate_pivot_rows_dedupe_target() {
		$post_id = self::$posts->add_item( array( 'slug' => 'dupes' ) );
		$tag_a   = self::$tags->add_item( array( 'name' => 'alpha' ) );

		// Two pivot rows point at the same tag.
		self::$pivots->add_item(
			array(
				'post_id' => $post_id,
				'tag_id'  => $tag_a,
			)
		);
		self::$pivots->add_item(
			array(
				'post_id' => $post_id,
				'tag_id'  => $tag_a,
			)
		);

		$post = self::$posts->get_item( $post_id );
		$tags = self::$posts->get_related( $post, 'tags' );

		$this->assertCount( 1, $tags );
		$this->assertSame( (int) $tag_a, (int) reset( $tags )->id );
	}

	/**
	 * Only the queried post's tags come back, not another post's.
	 *
	 * @since 3.1.0
	 */
	public function test_isolates_by_post() {
		$post_a = self::$posts->add_item( array( 'slug' => 'a' ) );
		$post_b = self::$posts->add_item( array( 'slug' => 'b' ) );
		$tag_a  = self::$tags->add_item( array( 'name' => 'alpha' ) );
		$tag_b  = self::$tags->add_item( array( 'name' => 'beta' ) );

		self::$pivots->add_item(
			array(
				'post_id' => $post_a,
				'tag_id'  => $tag_a,
			)
		);
		self::$pivots->add_item(
			array(
				'post_id' => $post_b,
				'tag_id'  => $tag_b,
			)
		);

		$post = self::$posts->get_item( $post_a );
		$tags = self::$posts->get_related( $post, 'tags' );

		$this->assertSame( array( (int) $tag_a ), $this->ids( $tags ) );
	}

	/**
	 * After a query that primes the relationship via `with`, get_related() resolves
	 * both hops with ZERO SQL - the pivot and target caches are already warm.
	 *
	 * @since 3.1.0
	 */
	public function test_priming_makes_get_related_a_cache_hit() {
		global $wpdb;

		$post_id = self::$posts->add_item( array( 'slug' => 'hello' ) );
		$tag_a   = self::$tags->add_item( array( 'name' => 'alpha' ) );
		$tag_b   = self::$tags->add_item( array( 'name' => 'beta' ) );

		self::$pivots->add_item(
			array(
				'post_id' => $post_id,
				'tag_id'  => $tag_a,
			)
		);
		self::$pivots->add_item(
			array(
				'post_id' => $post_id,
				'tag_id'  => $tag_b,
			)
		);

		// Query the posts, priming the tags relationship in bulk.
		$results = self::$posts->query( array( 'with' => array( 'tags' ) ) );
		$post    = reset( $results );

		$before = $wpdb->num_queries;
		$tags   = self::$posts->get_related( $post, 'tags' );
		$this->assertSame( $before, $wpdb->num_queries );

		$this->assertSame(
			array( (int) $tag_a, (int) $tag_b ),
			$this->ids( $tags )
		);
	}

	/**
	 * A primed post with no tags resolves to an empty set with ZERO SQL - the pivot
	 * hop is seeded even for a no-match key (negative caching).
	 *
	 * @since 3.1.0
	 */
	public function test_priming_childless_post_is_a_cache_hit() {
		global $wpdb;

		$post_id = self::$posts->add_item( array( 'slug' => 'lonely' ) );
		self::$tags->add_item( array( 'name' => 'alpha' ) ); // exists but unlinked

		$results = self::$posts->query( array( 'with' => array( 'tags' ) ) );
		$post    = reset( $results );

		$before = $wpdb->num_queries;
		$tags   = self::$posts->get_related( $post, 'tags' );
		$this->assertSame( $before, $wpdb->num_queries );

		$this->assertSame( array(), $tags );
	}
}
