<?php
/**
 * Reserved control vars are not clobbered by same-named columns.
 *
 * The By parser registers every column name as a bare query-var shorthand. A column
 * named like a reserved control var (count, order, number, ...) must NOT overwrite
 * that var's default during parser registration: the control var keeps precedence
 * and the collision is logged, so a schema author can see why the bare-name filter
 * is unavailable (the column stays filterable via its `{column}__in` shorthand).
 *
 * Construction-only; no database required.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use PHPUnit\Framework\TestCase;

/** A schema whose columns collide with reserved control vars. */
class ProtectedVarSchema extends Schema {
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'primary'  => true,
			'extra'    => 'auto_increment',
		),
		array(
			'name'   => 'count', // Collides with the COUNT-request var.
			'type'   => 'bigint',
			'length' => '20',
		),
		array(
			'name'   => 'order', // Collides with the ORDER-direction var.
			'type'   => 'varchar',
			'length' => '20',
		),
		array(
			'name'   => 'status', // Ordinary column, no collision.
			'type'   => 'varchar',
			'length' => '20',
		),
	);
}

/** A Query over the colliding schema, exposing its var defaults for the test. */
class ProtectedVarQuery extends Query {
	protected $prefix           = 'pv';
	protected $table_name       = 'protected_vars';
	protected $table_schema     = ProtectedVarSchema::class;
	protected $item_name        = 'protected_var';
	protected $item_name_plural = 'protected_vars';
	protected $cache_group      = 'pv_protected_vars';

	/**
	 * Expose a registered query-var default for assertions.
	 *
	 * @param string $key Query var key.
	 * @return mixed The registered default, or null when unregistered.
	 */
	public function peek_default( $key ) {
		return $this->query_var_defaults[ $key ] ?? null;
	}

	/**
	 * Whether a query-var default key is registered.
	 *
	 * @param string $key Query var key.
	 * @return bool
	 */
	public function has_default( $key ): bool {
		return array_key_exists( $key, $this->query_var_defaults );
	}
}

/**
 * Tests for the reserved-control-var precedence guard.
 *
 * @since 3.1.0
 */
class ProtectedQueryVarTest extends TestCase {

	/**
	 * A 'count' column does not clobber the COUNT-request var's default.
	 *
	 * @since 3.1.0
	 */
	public function test_count_column_does_not_clobber_count_default() {
		$query = new ProtectedVarQuery();
		$this->assertFalse( $query->peek_default( 'count' ) );
	}

	/**
	 * An 'order' column does not clobber the ORDER-direction var's default.
	 *
	 * @since 3.1.0
	 */
	public function test_order_column_does_not_clobber_order_default() {
		$query = new ProtectedVarQuery();
		$this->assertSame( 'DESC', $query->peek_default( 'order' ) );
	}

	/**
	 * Each collision is logged (code 'query_var').
	 *
	 * @since 3.1.0
	 */
	public function test_collision_is_logged() {
		$query = new ProtectedVarQuery();
		$this->assertNotEmpty( $query->get_logs( array( 'code' => 'query_var' ) ) );
	}

	/**
	 * A non-colliding column is still registered as a bare shorthand.
	 *
	 * @since 3.1.0
	 */
	public function test_non_colliding_column_still_registers() {
		$query = new ProtectedVarQuery();
		$this->assertTrue( $query->has_default( 'status' ) );
	}
}
