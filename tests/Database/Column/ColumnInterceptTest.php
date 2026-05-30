<?php
/**
 * Tests for Column::intercept().
 *
 * intercept() is the per-column save-time value hook: it stamps created/modified
 * timestamps and passes every other column's value through unchanged. Built-in
 * behaviour keys off the existing created/modified flags.
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

	/**
	 * A UUID column clears its value when copied.
	 *
	 * @since 3.1.0
	 */
	public function test_uuid_column_unsets_value_on_copy(): void {
		$column = new Column(
			array(
				'name' => 'uuid',
				'type' => 'varchar',
				'uuid' => true,
			)
		);

		$this->assertNull( $column->intercept( 'copy', '9a130ddc-0194-4e65-bd97-e2bd42259614' ) );
	}
}
