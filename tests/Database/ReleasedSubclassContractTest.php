<?php
/**
 * Released extension signatures and override dispatch.
 *
 * @package BerlinDB\Tests
 * @since 3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Operators\Comparisons\Equal;
use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestSchema;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * A schema using the signatures shipped in 3.0.0.
 *
 * @since 3.1.0
 */
class ReleasedSchemaOverrides extends TestSchema {

	/** @var int */
	public $column_reads = 0;

	/**
	 * Get items through the released collection hook.
	 *
	 * @since 3.1.0
	 * @param string $type Collection type.
	 * @return array
	 */
	public function get_items( $type = 'columns' ) {
		return parent::get_items( $type );
	}

	/**
	 * Count column reads through the released hook.
	 *
	 * @since 3.1.0
	 * @return array
	 */
	public function get_columns() {
		++$this->column_reads;
		return parent::get_columns();
	}

	/**
	 * Get indexes through the released hook.
	 *
	 * @since 3.1.0
	 * @return array
	 */
	public function get_indexes() {
		return parent::get_indexes();
	}

	/**
	 * Supply custom DDL through the released hook.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function get_create_table_string() {
		return '`custom` bigint';
	}
}

/**
 * A column retaining the extension signatures that predate 3.0.0.
 *
 * @since 3.1.0
 */
class ReleasedColumnOverrides extends Column {

	/**
	 * Preserve the released predicate override.
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function is_numeric() {
		return parent::is_numeric();
	}

	/**
	 * Preserve the released success check override.
	 *
	 * @since 3.1.0
	 * @param mixed $value Result to check.
	 * @return bool
	 */
	protected function is_success( $value = false ) {
		return parent::is_success( $value );
	}
}

/**
 * A query with untyped released overrides.
 *
 * @since 3.1.0
 */
class ReleasedQueryOverrides extends TestQuery {

	/**
	 * Keep the released untyped signature.
	 *
	 * @since 3.1.0
	 * @param array $args Filters.
	 * @param string $operator Match logic.
	 * @param bool|string $field Field to return.
	 * @return array
	 */
	public function get_columns( $args = array(), $operator = 'and', $field = false ) {
		return parent::get_columns( $args, $operator, $field );
	}

	/**
	 * Keep the released untyped signature.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function get_item_name_plural() {
		return parent::get_item_name_plural();
	}
}

/**
 * An operator using the released rendering signature.
 *
 * @since 3.1.0
 */
class ReleasedOperatorOverride extends Equal {

	/**
	 * Supply a custom expression through the released hook.
	 *
	 * @since 3.1.0
	 * @param Column $col Schema column.
	 * @param string $alias Table alias.
	 * @param mixed $value Value to compare.
	 * @return string
	 */
	public function get_sql( Column $col, string $alias = '', $value = null ): string {
		return 'custom = 1';
	}
}

/**
 * Check extension loading and dispatch, not only reflection signatures.
 *
 * @since 3.1.0
 */
class ReleasedSubclassContractTest extends TestCase {

	/**
	 * New schema helpers preserve the released override points.
	 *
	 * @since 3.1.0
	 */
	public function test_filtered_schema_reads_honor_released_overrides(): void {
		$schema = new ReleasedSchemaOverrides();
		$before = $schema->column_reads;

		$columns = $schema->get_filtered_columns( array( 'name' => 'status' ) );
		$this->assertCount( 1, $columns );
		$this->assertSame( 'status', $columns[0]->name );
		$this->assertGreaterThan( $before, $schema->column_reads );
	}

	/**
	 * Column casts still work and operator helpers retain the released renderer.
	 *
	 * @since 3.1.0
	 */
	public function test_sql_helpers_honor_released_overrides(): void {
		$column = new ReleasedColumnOverrides(
			array(
				'name' => 'total',
				'type' => 'int',
			)
		);
		$this->assertSame( 'CAST(`total` AS SIGNED)', $column->get_name_sql( '', 'SIGNED' ) );
		$this->assertSame( 'custom = 1', ( new ReleasedOperatorOverride() )->get_sql_with_cast( $column, '', 1 ) );
		$this->assertNotEmpty( ( new ReleasedQueryOverrides() )->get_columns() );
	}

	/**
	 * Validators that predate 3.0 remain callable by plugins.
	 *
	 * @since 3.1.0
	 */
	public function test_older_validators_remain_public(): void {
		$column = new Column(
			array(
				'name' => 'value',
				'type' => 'varchar',
			)
		);
		foreach ( array( 'validate_datetime', 'validate_decimal', 'validate_uuid' ) as $method ) {
			$this->assertTrue( is_callable( array( $column, $method ) ) );
		}
	}
}
