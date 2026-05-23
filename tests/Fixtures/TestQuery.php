<?php
/**
 * Test Query fixture for BerlinDB integration tests.
 *
 * @package     BerlinDB\Tests\Fixtures
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests\Fixtures;

use BerlinDB\Database\Kern\Query;

/**
 * Query implementation for the test_widgets table.
 *
 * $table_name must match the $name set in TestTable (= the value registered
 * on $wpdb after TestTable is constructed). Query resolves the full table name
 * via get_db()->{$this->table_name}.
 *
 * @since 2.1.0
 */
class TestQuery extends Query {

	/** @var string */
	protected $prefix = 'berlindb_database';

	/** @var string */
	protected $table_name = 'test_widgets';

	/** @var string */
	protected $table_alias = 'tw';

	/** @var string */
	protected $table_schema = TestSchema::class;

	/** @var string */
	protected $item_name = 'widget';

	/** @var string */
	protected $item_name_plural = 'widgets';

	/** @var string */
	protected $item_shape = TestRow::class;

	/** @var string */
	protected $cache_group = 'berlindb-test-widgets';
}
