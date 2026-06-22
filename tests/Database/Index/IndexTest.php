<?php
/**
 * Index class tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Index;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BerlinDB\Database\Index.
 *
 * @since 3.0.0
 */
class IndexTest extends TestCase {

	/**
	 * Test that default type is key.
	 *
	 * @since 3.0.0
	 */
	public function test_default_type_is_key() {

		// Assert expected results.
		$index = new Index();
		$this->assertSame( 'key', $index->type );
	}

	/**
	 * Test that default columns are empty.
	 *
	 * @since 3.0.0
	 */
	public function test_default_columns_are_empty() {

		// Assert expected results.
		$index = new Index();
		$this->assertSame( array(), $index->columns );
	}

	/**
	 * Test that default unique is false.
	 *
	 * @since 3.0.0
	 */
	public function test_default_unique_is_false() {

		// Assert expected results.
		$index = new Index();
		$this->assertFalse( $index->unique );
	}

	/**
	 * Test that default method is btree.
	 *
	 * @since 3.0.0
	 */
	public function test_default_method_is_btree() {

		// Assert expected results.
		$index = new Index();
		$this->assertSame( 'BTREE', $index->method );
	}

	/**
	 * Test that name is sanitized and lowercased.
	 *
	 * @since 3.0.0
	 */
	public function test_name_is_sanitized_and_lowercased() {

		// Assert expected results.
		$index = new Index( array( 'name' => '  My-Index Name!  ' ) );
		$this->assertSame( 'my_index_name', $index->name );
	}

	/**
	 * Test that columns are sanitized and filtered.
	 *
	 * @since 3.0.0
	 */
	public function test_columns_are_sanitized_and_filtered() {

		// Assert expected results.
		$index = new Index(
			array(
				'columns' => array( ' status ', 'Bad Col!', 42, '', '__' ),
			)
		);

		$this->assertSame( array( 'status', 'bad_col' ), $index->columns );
	}

	/**
	 * Test that type is normalized to lowercase.
	 *
	 * @since 3.0.0
	 */
	public function test_type_is_normalized_to_lowercase() {

		// Assert expected results.
		$index = new Index( array( 'type' => 'FULLTEXT' ) );
		$this->assertSame( 'fulltext', $index->type );
	}

	/**
	 * Test that method and using are normalized to uppercase.
	 *
	 * @since 3.0.0
	 */
	public function test_method_and_using_are_normalized_to_uppercase() {

		// Assert expected results.
		$index = new Index(
			array(
				'method' => 'hash',
				'using'  => 'btree',
			)
		);

		$this->assertSame( 'HASH', $index->method );
		$this->assertSame( 'BTREE', $index->using );
	}

	/**
	 * Test that get create string returns empty without columns.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_returns_empty_without_columns() {

		// Assert expected results.
		$index = new Index( array( 'name' => 'status_idx' ) );
		$this->assertSame( '', $index->get_create_string() );
	}

	/**
	 * Test that primary index create string is generated.
	 *
	 * @since 3.0.0
	 */
	public function test_primary_index_create_string_is_generated() {

		// Assert expected results.
		$index = new Index(
			array(
				'type'    => 'primary',
				'columns' => array( 'id' ),
			)
		);

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'PRIMARY KEY (`id`)', $sql );
		$this->assertStringNotContainsString( 'USING', $sql );
	}

	/**
	 * Test that unique index create string is generated.
	 *
	 * @since 3.0.0
	 */
	public function test_unique_index_create_string_is_generated() {

		// Assert expected results.
		$index = new Index(
			array(
				'name'    => 'status_idx',
				'type'    => 'unique',
				'columns' => array( 'status' ),
			)
		);

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'UNIQUE KEY `status_idx` (`status`)', $sql );
	}

	/**
	 * Test that fulltext index create string is generated.
	 *
	 * @since 3.0.0
	 */
	public function test_fulltext_index_create_string_is_generated() {

		// Assert expected results.
		$index = new Index(
			array(
				'name'    => 'name_idx',
				'type'    => 'fulltext',
				'columns' => array( 'name' ),
			)
		);

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'FULLTEXT KEY `name_idx` (`name`)', $sql );
	}

	/**
	 * Test that standard key create string is generated.
	 *
	 * @since 3.0.0
	 */
	public function test_standard_key_create_string_is_generated() {

		// Assert expected results.
		$index = new Index(
			array(
				'name'    => 'status_idx',
				'type'    => 'key',
				'columns' => array( 'status' ),
			)
		);

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'KEY `status_idx` (`status`)', $sql );
	}

	/**
	 * Test that unique true forces unique key SQL.
	 *
	 * @since 3.0.0
	 */
	public function test_unique_true_forces_unique_key_sql() {

		// Assert expected results.
		$index = new Index(
			array(
				'name'    => 'status_idx',
				'type'    => 'key',
				'unique'  => true,
				'columns' => array( 'status' ),
			)
		);

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'UNIQUE KEY `status_idx` (`status`)', $sql );
	}

	/**
	 * Test that create string returns empty when key name is missing.
	 *
	 * @since 3.0.0
	 */
	public function test_create_string_returns_empty_when_key_name_is_missing() {

		// Assert expected results.
		$index = new Index(
			array(
				'type'    => 'key',
				'columns' => array( 'status' ),
			)
		);

		$this->assertSame( '', $index->get_create_string() );
	}

	/**
	 * Test that using overrides method in create SQL.
	 *
	 * @since 3.0.0
	 */
	public function test_using_overrides_method_in_create_sql() {

		// Assert expected results.
		$index = new Index(
			array(
				'name'    => 'status_idx',
				'type'    => 'key',
				'columns' => array( 'status' ),
				'method'  => 'HASH',
				'using'   => 'BTREE',
			)
		);

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'USING BTREE', $sql );
		$this->assertStringNotContainsString( 'USING HASH', $sql );
	}

	/**
	 * Test that comment is escaped in create SQL.
	 *
	 * @since 3.0.0
	 */
	public function test_comment_is_escaped_in_create_sql() {

		// Assert expected results.
		$index = new Index(
			array(
				'name'    => 'status_idx',
				'type'    => 'key',
				'columns' => array( 'status' ),
				'comment' => "owner's index",
			)
		);

		$sql = $index->get_create_string();
		$this->assertStringContainsString( "COMMENT 'owner\\'s index'", $sql );
	}

	/**
	 * Test that a composite index includes all column names in the create string.
	 *
	 * @since 3.0.0
	 */
	public function test_composite_index_includes_all_columns() {

		// Assert expected results.
		$index = new Index(
			array(
				'name'    => 'name_status_idx',
				'type'    => 'key',
				'columns' => array( 'name', 'status', 'priority' ),
			)
		);

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'KEY `name_status_idx` (`name`, `status`, `priority`)', $sql );
	}

	/**
	 * Test that no USING clause is added when both method and using are empty.
	 *
	 * @since 3.0.0
	 */
	public function test_no_using_clause_when_method_and_using_are_empty() {

		// Assert expected results.
		$index = new Index(
			array(
				'name'    => 'status_idx',
				'type'    => 'key',
				'columns' => array( 'status' ),
				'method'  => '',
				'using'   => '',
			)
		);

		$sql = $index->get_create_string();
		$this->assertStringNotContainsString( 'USING', $sql );
	}

	/**
	 * Test that to array includes key attributes.
	 *
	 * @since 3.0.0
	 */
	public function test_to_array_includes_key_attributes() {

		// Assert expected results.
		$index = new Index(
			array(
				'name'    => 'status_idx',
				'type'    => 'key',
				'columns' => array( 'status' ),
			)
		);

		$arr = $index->to_array();
		$this->assertArrayHasKey( 'name', $arr );
		$this->assertArrayHasKey( 'type', $arr );
		$this->assertArrayHasKey( 'columns', $arr );
	}

	/**
	 * Test a prefix length in string form: name stays in $columns, length in
	 * $lengths, and the CREATE renders `col`(length).
	 *
	 * @since 3.1.0
	 */
	public function test_prefix_length_string_form() {
		$index = new Index(
			array(
				'name'    => 'title_idx',
				'type'    => 'key',
				'columns' => array( 'title(191)' ),
			)
		);

		$this->assertSame( array( 'title' ), $index->columns );
		$this->assertSame( array( 'title' => 191 ), $index->lengths );
		$this->assertStringContainsString( 'KEY `title_idx` (`title`(191))', $index->get_create_string() );
	}

	/**
	 * Test a prefix length in keyed form ( 'name' => length ).
	 *
	 * @since 3.1.0
	 */
	public function test_prefix_length_keyed_form() {
		$index = new Index(
			array(
				'name'    => 'title_idx',
				'type'    => 'key',
				'columns' => array( 'title' => 191 ),
			)
		);

		$this->assertSame( array( 'title' ), $index->columns );
		$this->assertSame( array( 'title' => 191 ), $index->lengths );
		$this->assertStringContainsString( '(`title`(191))', $index->get_create_string() );
	}

	/**
	 * Test that plain names, string-form, and keyed-form prefixes mix in one index.
	 *
	 * @since 3.1.0
	 */
	public function test_prefix_lengths_mixed_forms() {
		$index = new Index(
			array(
				'name'    => 'mix_idx',
				'type'    => 'key',
				'columns' => array(
					'status',
					'slug(10)',
					'title' => 191,
				),
			)
		);

		$this->assertSame( array( 'status', 'slug', 'title' ), $index->columns );
		$this->assertSame(
			array(
				'slug'  => 10,
				'title' => 191,
			),
			$index->lengths
		);
		$this->assertStringContainsString(
			'KEY `mix_idx` (`status`, `slug`(10), `title`(191))',
			$index->get_create_string()
		);
	}

	/**
	 * Test that FULLTEXT indexes never emit a prefix length (MySQL rejects them).
	 *
	 * @since 3.1.0
	 */
	public function test_fulltext_ignores_prefix_length() {
		$index = new Index(
			array(
				'name'    => 'body_idx',
				'type'    => 'fulltext',
				'columns' => array( 'body(191)' ),
			)
		);

		// The length is still parsed, but never rendered for FULLTEXT.
		$this->assertSame( array( 'body' => 191 ), $index->lengths );

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'FULLTEXT KEY `body_idx` (`body`)', $sql );
		$this->assertStringNotContainsString( '(191)', $sql );
	}

	/**
	 * Test that a non-positive or non-numeric prefix length means the whole column.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_prefix_length_is_whole_column() {
		$index = new Index(
			array(
				'name'    => 'idx',
				'type'    => 'key',
				'columns' => array(
					'a' => 0,
					'b' => -5,
					'c' => 'nope',
				),
			)
		);

		$this->assertSame( array( 'a', 'b', 'c' ), $index->columns );
		$this->assertSame( array(), $index->lengths );
		$this->assertStringContainsString( '(`a`, `b`, `c`)', $index->get_create_string() );
	}
}
