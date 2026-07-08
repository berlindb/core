<?php
/**
 * Tests for Column::intercept().
 *
 * intercept() is the per-column save-time value hook: it stamps created/modified
 * timestamps and passes every other column's value through unchanged. Built-in
 * behavior keys off the existing created/modified flags.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Column value-interception hook.
 *
 * @since 3.1.0
 */
class ColumnInterceptTest extends TestCase {

	/** Matches a "Y-m-d H:i:s" datetime. */
	private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

	// -------------------------------------------------------------------------
	// created.
	// -------------------------------------------------------------------------

	/**
	 * A created column stamps the current time on insert when the value is empty.
	 *
	 * @since 3.1.0
	 */
	public function test_created_stamps_on_insert_when_empty(): void {
		$column = new Column(
			array(
				'name'    => 'date_created',
				'type'    => 'datetime',
				'created' => true,
			)
		);

		$this->assertMatchesRegularExpression( self::DATETIME_PATTERN, $column->intercept( 'insert', '' ) );
	}

	/**
	 * A created column leaves an explicitly-provided value untouched on insert.
	 *
	 * @since 3.1.0
	 */
	public function test_created_preserves_explicit_value_on_insert(): void {
		$column = new Column(
			array(
				'name'    => 'date_created',
				'type'    => 'datetime',
				'created' => true,
			)
		);

		$this->assertSame( '2020-06-15 12:00:00', $column->intercept( 'insert', '2020-06-15 12:00:00' ) );
	}

	/**
	 * A created column does not touch the value on update.
	 *
	 * @since 3.1.0
	 */
	public function test_created_does_not_stamp_on_update(): void {
		$column = new Column(
			array(
				'name'    => 'date_created',
				'type'    => 'datetime',
				'created' => true,
			)
		);

		$this->assertSame( '2020-06-15 12:00:00', $column->intercept( 'update', '2020-06-15 12:00:00' ) );
	}

	/**
	 * A created column stamps when the caller did not provide it, even if a
	 * (default-seeded) value is present - omission is by key presence, not value.
	 *
	 * @since 3.1.0
	 */
	public function test_created_stamps_on_insert_when_not_provided(): void {
		$column = new Column(
			array(
				'name'    => 'date_created',
				'type'    => 'datetime',
				'created' => true,
			)
		);

		$result = $column->intercept( 'insert', '2020-06-15 12:00:00', false );

		$this->assertMatchesRegularExpression( self::DATETIME_PATTERN, $result );
		$this->assertNotSame( '2020-06-15 12:00:00', $result );
	}

	// -------------------------------------------------------------------------
	// modified.
	// -------------------------------------------------------------------------

	/**
	 * A modified column stamps the current time on every update.
	 *
	 * @since 3.1.0
	 */
	public function test_modified_stamps_on_update(): void {
		$column = new Column(
			array(
				'name'     => 'date_modified',
				'type'     => 'datetime',
				'modified' => true,
			)
		);

		$this->assertMatchesRegularExpression( self::DATETIME_PATTERN, $column->intercept( 'update', '2020-06-15 12:00:00' ) );
	}

	/**
	 * A modified column stamps the current time on insert when empty.
	 *
	 * @since 3.1.0
	 */
	public function test_modified_stamps_on_insert_when_empty(): void {
		$column = new Column(
			array(
				'name'     => 'date_modified',
				'type'     => 'datetime',
				'modified' => true,
			)
		);

		$this->assertMatchesRegularExpression( self::DATETIME_PATTERN, $column->intercept( 'insert', '' ) );
	}

	/**
	 * A modified column stamps on insert when not provided, even with a value.
	 *
	 * @since 3.1.0
	 */
	public function test_modified_stamps_on_insert_when_not_provided(): void {
		$column = new Column(
			array(
				'name'     => 'date_modified',
				'type'     => 'datetime',
				'modified' => true,
			)
		);

		$result = $column->intercept( 'insert', '2020-06-15 12:00:00', false );

		$this->assertMatchesRegularExpression( self::DATETIME_PATTERN, $result );
		$this->assertNotSame( '2020-06-15 12:00:00', $result );
	}

	/**
	 * A modified column honors an explicitly-provided value on insert.
	 *
	 * @since 3.1.0
	 */
	public function test_modified_preserves_explicit_value_on_insert(): void {
		$column = new Column(
			array(
				'name'     => 'date_modified',
				'type'     => 'datetime',
				'modified' => true,
			)
		);

		$this->assertSame( '2020-06-15 12:00:00', $column->intercept( 'insert', '2020-06-15 12:00:00' ) );
	}

	// -------------------------------------------------------------------------
	// Pass-through and copying.
	// -------------------------------------------------------------------------

	/**
	 * A plain column returns its value unchanged for every method.
	 *
	 * @since 3.1.0
	 */
	public function test_plain_column_passes_value_through(): void {
		$column = new Column(
			array(
				'name' => 'name',
				'type' => 'varchar',
			)
		);

		$this->assertSame( 'widget', $column->intercept( 'insert', 'widget' ) );
		$this->assertSame( 'widget', $column->intercept( 'update', 'widget' ) );
		$this->assertSame( 'widget', $column->intercept( 'select', 'widget' ) );
		$this->assertSame( 'widget', $column->intercept( 'copy', 'widget' ) );
	}

	// -------------------------------------------------------------------------
	// Unset sentinel.
	// -------------------------------------------------------------------------

	/**
	 * intercept_unset_value is private - direct property access is impossible.
	 *
	 * This guards against regressions to protected/public visibility, which
	 * would mean Query (outside the class hierarchy) was silently reading an
	 * uninitialised empty string instead of the generated sentinel.
	 *
	 * @since 3.1.0
	 */
	public function test_intercept_unset_value_property_is_private(): void {
		$prop = new \ReflectionProperty( Column::class, 'intercept_unset_value' );
		$this->assertTrue( $prop->isPrivate() );
	}

	/**
	 * is_unset_sentinel() identifies the generated unset sentinel.
	 *
	 * @since 3.1.0
	 */
	public function test_is_unset_sentinel_identifies_unset_value(): void {
		$column = new Column(
			array(
				'uuid' => true,
			)
		);

		$sentinel = $column->intercept( 'copy', '9a130ddc-0194-4e65-bd97-e2bd42259614' );

		$this->assertTrue( $column->is_unset_sentinel( $sentinel ) );
		$this->assertFalse( $column->is_unset_sentinel( '' ) );
	}

	/**
	 * A UUID column returns its unset sentinel when copied.
	 *
	 * @since 3.1.0
	 */
	public function test_uuid_column_returns_unset_sentinel_on_copy(): void {
		$column = new Column(
			array(
				'name' => 'uuid',
				'type' => 'varchar',
				'uuid' => true,
			)
		);

		$this->assertTrue(
			$column->is_unset_sentinel(
				$column->intercept( 'copy', '9a130ddc-0194-4e65-bd97-e2bd42259614' )
			)
		);
	}
}
