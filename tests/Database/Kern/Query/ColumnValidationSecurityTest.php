<?php
/**
 * Column-validation security tests.
 *
 * is_valid_column() gates a column name that flows into SQL verbatim downstream
 * (get_item_raw() interpolates it). Validation must therefore match the raw name
 * exactly, NOT a normalized form: Schema::has_column() sanitizes its input (e.g.
 * "id-- " becomes "id"), so delegating validation to it would accept a string that
 * is then interpolated as-is, commenting out the predicate. This guards against
 * re-introducing that bypass.
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
 * Tests that column validation rejects names that only pass after sanitization.
 *
 * @since 3.1.0
 */
class ColumnValidationSecurityTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/**
	 * Install the test table once.
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
	 * A column name that only matches after identifier sanitization is rejected.
	 *
	 * "id" is a real column; "id-- " sanitizes to "id" (the trailing "-- " becomes
	 * a trimmed underscore), so a name-normalizing check would validate it. The raw
	 * "id-- " would then comment out the WHERE predicate, so it must be rejected.
	 *
	 * @since 3.1.0
	 */
	public function test_get_item_by_rejects_sanitization_bypass_column() {
		$query = new TestQuery();

		$this->assertFalse( $query->get_item_by( 'id-- ', 1 ) );
		$this->assertFalse( $query->get_item_by( 'id ', 1 ) );
	}
}
