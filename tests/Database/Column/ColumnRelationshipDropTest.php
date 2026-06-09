<?php
/**
 * Column relationship-drop diagnostics (#206).
 *
 * Column::sanitize_relationships() stays fail-closed (malformed entries are
 * dropped), but now logs a structured warning at each drop so a misdeclared
 * relationship is visible instead of vanishing silently.
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
 * Tests for the structured drop warnings in Column::sanitize_relationships().
 *
 * @since 3.1.0
 */
class ColumnRelationshipDropTest extends TestCase {

	/**
	 * Build a Column with one relationship declaration.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $relationship A single relationship declaration (any shape).
	 * @return Column
	 */
	private function make_column( $relationship ): Column {
		return new Column(
			array(
				'name'          => 'order_id',
				'type'          => 'bigint',
				'relationships' => array( $relationship ),
			)
		);
	}

	/**
	 * Assert exactly one warning with the given code was logged on the column.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $column The column to inspect.
	 * @param string $code   Expected log code.
	 */
	private function assertLoggedCode( Column $column, string $code ): void {
		$logs = $column->get_logs( array( 'code' => $code ) );

		$this->assertNotEmpty( $logs, "Expected a '{$code}' warning to be logged." );
		$this->assertSame( 'warning', $logs[0]['level'] );
		$this->assertSame( 'order_id', $logs[0]['context']['column'] );
	}

	/**
	 * A non-array declaration is dropped and logged.
	 *
	 * @since 3.1.0
	 */
	public function test_non_array_declaration_logs() {
		$column = $this->make_column( 'not-an-array' );

		$this->assertSame( array(), $column->relationships );
		$this->assertLoggedCode( $column, 'relationship_invalid_declaration' );
	}

	/**
	 * A declaration missing 'query'/'column' is dropped and logged.
	 *
	 * @since 3.1.0
	 */
	public function test_missing_key_logs() {
		$column = $this->make_column( array( 'type' => 'belongs_to' ) );

		$this->assertSame( array(), $column->relationships );
		$this->assertLoggedCode( $column, 'relationship_missing_key' );
	}

	/**
	 * A declaration with an unusable remote column name is dropped and logged.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_column_logs() {
		$column = $this->make_column(
			array(
				'query'  => 'BerlinDB\\Tests\\SomeRemoteQuery',
				'column' => '!!!',
			)
		);

		$this->assertSame( array(), $column->relationships );
		$this->assertLoggedCode( $column, 'relationship_invalid_column' );
	}

	/**
	 * A declaration with an invalid remote query class is dropped and logged.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_query_class_logs() {
		$column = $this->make_column(
			array(
				'query'  => 'Order; DROP TABLE wp_posts',
				'column' => 'id',
			)
		);

		$this->assertSame( array(), $column->relationships );
		$this->assertLoggedCode( $column, 'relationship_invalid_query_class' );
	}

	/**
	 * A declaration with a present-but-unknown type is dropped and logged.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_type_logs() {
		$column = $this->make_column(
			array(
				'query'  => 'BerlinDB\\Tests\\SomeRemoteQuery',
				'column' => 'id',
				'type'   => 'has-many',
			)
		);

		$this->assertSame( array(), $column->relationships );
		$this->assertLoggedCode( $column, 'relationship_invalid_type' );
	}

	/**
	 * A well-formed declaration survives and logs no warning.
	 *
	 * @since 3.1.0
	 */
	public function test_valid_declaration_survives_without_warning() {
		$column = $this->make_column(
			array(
				'query'  => 'BerlinDB\\Tests\\SomeRemoteQuery',
				'column' => 'id',
				'type'   => 'belongs_to',
			)
		);

		$this->assertCount( 1, $column->relationships );
		$this->assertEmpty( $column->get_logs( array( 'level' => 'warning' ) ) );
	}
}
