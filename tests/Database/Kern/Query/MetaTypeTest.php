<?php
/**
 * The meta type source of truth: the explicit `$meta_type` property vs the item-name fallback (#243).
 *
 * `get_meta_type()` keys the WordPress metadata API, the meta table name (`{type}meta`), and
 * its object-id column (`{type}_id`). Historically it string-munged `item_name`, which is
 * correct only when `item_name` equals the WordPress object type. These tests prove the new
 * explicit `$meta_type` property is honored across the WP-metadata path (type, table name,
 * meta_query, and delete cleanup), while an unset property preserves the item-name fallback.
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
 * A Query whose meta type is set via the `$meta_type` PROPERTY (not a get_meta_type()
 * override), with an `item_name` that deliberately differs from the WordPress object type -
 * the #243 case (an existing table whose item name is namespaced). `meta_type = 'post'` routes
 * WordPress metadata to the real wp_postmeta table that exists in every WP test environment.
 *
 * @since 3.1.0
 */
class MtqPostMetaQuery extends TestQuery {
	protected $item_name = 'mtq_namespaced_post';
	protected $meta_type = 'post';
}

/**
 * @since 3.1.0
 */
class MetaTypeTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var MtqPostMetaQuery */
	private static $query;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new MtqPostMetaQuery();
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

	/** The explicit property is the meta type, even when item_name differs. */
	public function test_meta_type_property_is_honored(): void {
		$this->assertSame( 'post', self::$query->get_meta_type() );
	}

	/** An unset property preserves the legacy prefixed-item-name derivation (backward-compat). */
	public function test_meta_type_defaults_to_prefixed_item_name(): void {
		// TestQuery: prefix 'berlindb_database', item_name 'widget', no $meta_type.
		$this->assertSame( 'berlindb_database_widget', ( new TestQuery() )->get_meta_type() );
	}

	/** meta_query filters through the WP meta engine resolved by the property (wp_postmeta). */
	public function test_meta_query_filters_via_meta_type_property(): void {
		$blue = (int) self::$query->add_item(
			array(
				'name'   => 'Blue',
				'status' => 'active',
			)
		);
		$red  = (int) self::$query->add_item(
			array(
				'name'   => 'Red',
				'status' => 'active',
			)
		);

		// wp_postmeta has no FK constraint; key meta by the widget's own numeric ID.
		add_metadata( 'post', $blue, 'color', 'blue' );
		add_metadata( 'post', $red, 'color', 'red' );
		wp_cache_flush();

		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'   => 'color',
						'value' => 'blue',
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Blue', reset( $results )->name );
	}

	/** Deleting an item removes its meta via the {meta_type}_id column (post_id), not item_name. */
	public function test_delete_item_removes_meta_via_meta_type_column(): void {
		$id = (int) self::$query->add_item(
			array(
				'name'   => 'Doomed',
				'status' => 'active',
			)
		);

		add_metadata( 'post', $id, 'keep_me', 'nope' );
		$this->assertSame( 'nope', get_metadata( 'post', $id, 'keep_me', true ) );

		self::$query->delete_item( $id );

		// If delete_all_item_meta queried the wrong column, the meta would survive.
		$this->assertSame( '', get_metadata( 'post', $id, 'keep_me', true ) );
	}
}
