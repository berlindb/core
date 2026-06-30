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

	/**
	 * Test that a plain multi-column index keeps clean names, no lengths, and lists
	 * every column in declared order.
	 *
	 * @since 3.1.0
	 */
	public function test_plain_multi_column_has_no_lengths() {
		$index = new Index(
			array(
				'name'    => 'multi_idx',
				'type'    => 'key',
				'columns' => array( 'name', 'status', 'priority' ),
			)
		);

		$this->assertSame( array( 'name', 'status', 'priority' ), $index->columns );
		$this->assertSame( array(), $index->lengths );
		$this->assertStringContainsString(
			'KEY `multi_idx` (`name`, `status`, `priority`)',
			$index->get_create_string()
		);
	}

	/**
	 * Test a composite UNIQUE index where only one of several columns has a prefix.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_index_with_one_prefixed_column() {
		$index = new Index(
			array(
				'name'    => 'name_slug_idx',
				'type'    => 'unique',
				'columns' => array( 'name', 'slug(50)', 'status' ),
			)
		);

		$this->assertSame( array( 'name', 'slug', 'status' ), $index->columns );
		$this->assertSame( array( 'slug' => 50 ), $index->lengths );
		$this->assertStringContainsString(
			'UNIQUE KEY `name_slug_idx` (`name`, `slug`(50), `status`)',
			$index->get_create_string()
		);
	}

	/**
	 * Test a composite PRIMARY KEY with a prefix length on one column.
	 *
	 * @since 3.1.0
	 */
	public function test_primary_composite_with_prefix() {
		$index = new Index(
			array(
				'type'    => 'primary',
				'columns' => array( 'tenant_id', 'slug(20)' ),
			)
		);

		$this->assertSame( array( 'tenant_id', 'slug' ), $index->columns );
		$this->assertSame( array( 'slug' => 20 ), $index->lengths );
		$this->assertStringContainsString( 'PRIMARY KEY (`tenant_id`, `slug`(20))', $index->get_create_string() );
	}

	/**
	 * Test a DESC direction in string form: name stays clean, direction in
	 * $directions, and the CREATE renders `col` DESC.
	 *
	 * @since 3.1.0
	 */
	public function test_direction_string_form() {
		$index = new Index(
			array(
				'name'    => 'pri_idx',
				'type'    => 'key',
				'columns' => array( 'priority DESC' ),
			)
		);

		$this->assertSame( array( 'priority' ), $index->columns );
		$this->assertSame( array( 'priority' => 'DESC' ), $index->directions );
		$this->assertStringContainsString( 'KEY `pri_idx` (`priority` DESC)', $index->get_create_string() );
	}

	/**
	 * Test a DESC direction in keyed form ( 'name' => 'DESC' ).
	 *
	 * @since 3.1.0
	 */
	public function test_direction_keyed_form() {
		$index = new Index(
			array(
				'name'    => 'pri_idx',
				'type'    => 'key',
				'columns' => array( 'priority' => 'DESC' ),
			)
		);

		$this->assertSame( array( 'priority' ), $index->columns );
		$this->assertSame( array( 'priority' => 'DESC' ), $index->directions );
		$this->assertStringContainsString( '(`priority` DESC)', $index->get_create_string() );
	}

	/**
	 * Test a column carrying both a prefix length and a DESC direction (string form).
	 *
	 * @since 3.1.0
	 */
	public function test_direction_with_prefix_length() {
		$index = new Index(
			array(
				'name'    => 'title_idx',
				'type'    => 'key',
				'columns' => array( 'title(191) DESC' ),
			)
		);

		$this->assertSame( array( 'title' ), $index->columns );
		$this->assertSame( array( 'title' => 191 ), $index->lengths );
		$this->assertSame( array( 'title' => 'DESC' ), $index->directions );
		$this->assertStringContainsString( '(`title`(191) DESC)', $index->get_create_string() );
	}

	/**
	 * Test that ASC is the default and emitted as nothing (string and keyed forms).
	 *
	 * @since 3.1.0
	 */
	public function test_asc_is_default_and_omitted() {
		$string = new Index(
			array(
				'name'    => 's_idx',
				'type'    => 'key',
				'columns' => array( 'name ASC' ),
			)
		);
		$keyed  = new Index(
			array(
				'name'    => 'k_idx',
				'type'    => 'key',
				'columns' => array( 'name' => 'ASC' ),
			)
		);

		foreach ( array( $string, $keyed ) as $index ) {
			$this->assertSame( array( 'name' ), $index->columns );
			$this->assertSame( array(), $index->directions );
			$this->assertStringNotContainsString( 'ASC', $index->get_create_string() );
		}
	}

	/**
	 * Test that FULLTEXT indexes never emit a direction (MySQL rejects them there).
	 *
	 * @since 3.1.0
	 */
	public function test_fulltext_ignores_direction() {
		$index = new Index(
			array(
				'name'    => 'body_idx',
				'type'    => 'fulltext',
				'columns' => array( 'body DESC' ),
			)
		);

		// Parsed into $directions, but never rendered for FULLTEXT.
		$this->assertSame( array( 'body' => 'DESC' ), $index->directions );

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'FULLTEXT KEY `body_idx` (`body`)', $sql );
		$this->assertStringNotContainsString( 'DESC', $sql );
	}

	/**
	 * Test a composite index mixing a plain column, a prefixed column, and a DESC
	 * column - lengths and directions land on the right columns and render in order.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_mixed_lengths_and_directions() {
		$index = new Index(
			array(
				'name'    => 'mix_idx',
				'type'    => 'key',
				'columns' => array(
					'name',
					'slug(10)',
					'priority' => 'DESC',
				),
			)
		);

		$this->assertSame( array( 'name', 'slug', 'priority' ), $index->columns );
		$this->assertSame( array( 'slug' => 10 ), $index->lengths );
		$this->assertSame( array( 'priority' => 'DESC' ), $index->directions );
		$this->assertStringContainsString(
			'KEY `mix_idx` (`name`, `slug`(10), `priority` DESC)',
			$index->get_create_string()
		);
	}

	/**
	 * Test that incidental padding around a string-form entry is tolerated, just as
	 * it is for a plain column name - the direction is still parsed, not folded into
	 * the column name.
	 *
	 * @since 3.1.0
	 */
	public function test_direction_string_form_tolerates_padding() {
		$index = new Index(
			array(
				'name'    => 'pad_idx',
				'type'    => 'key',
				'columns' => array( '  priority DESC  ' ),
			)
		);

		$this->assertSame( array( 'priority' ), $index->columns );
		$this->assertSame( array( 'priority' => 'DESC' ), $index->directions );
		$this->assertStringContainsString( '(`priority` DESC)', $index->get_create_string() );
	}

	/**
	 * Build one SHOW INDEX row, overriding any fields.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private function show_index_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'Table'         => 'wp_things',
				'Non_unique'    => 1,
				'Key_name'      => 'idx',
				'Seq_in_index'  => 1,
				'Column_name'   => 'col',
				'Collation'     => 'A',
				'Sub_part'      => null,
				'Index_type'    => 'BTREE',
				'Index_comment' => '',
			),
			$overrides
		);
	}

	/**
	 * Test that from_mysql() returns false for empty input.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_empty_rows_returns_false() {
		$this->assertFalse( Index::from_mysql( array() ) );
	}

	/**
	 * Test that a PRIMARY group becomes a primary index with no own name.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_builds_primary_key() {
		$index = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'    => 'PRIMARY',
						'Non_unique'  => 0,
						'Column_name' => 'id',
					)
				),
			)
		);

		$this->assertInstanceOf( Index::class, $index );
		$this->assertSame( 'primary', $index->type );
		$this->assertEmpty( $index->name );
		$this->assertSame( 'PRIMARY', $index->get_index_name() );
		$this->assertStringContainsString( 'PRIMARY KEY (`id`)', $index->get_create_string() );
	}

	/**
	 * Test that Non_unique = 0 (non-primary) becomes a UNIQUE KEY.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_builds_unique_key() {
		$index = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'    => 'email',
						'Non_unique'  => 0,
						'Column_name' => 'email',
					)
				),
			)
		);

		$this->assertInstanceOf( Index::class, $index );
		$this->assertTrue( $index->unique );
		$this->assertStringContainsString( 'UNIQUE KEY `email` (`email`)', $index->get_create_string() );
	}

	/**
	 * Test that Sub_part and a descending collation become a prefix length and DESC.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_prefix_length_and_desc() {
		$index = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'     => 'idx',
						'Seq_in_index' => 1,
						'Column_name'  => 'title',
						'Sub_part'     => 191,
					)
				),
				$this->show_index_row(
					array(
						'Key_name'     => 'idx',
						'Seq_in_index' => 2,
						'Column_name'  => 'priority',
						'Collation'    => 'D',
					)
				),
			)
		);

		$this->assertInstanceOf( Index::class, $index );
		$this->assertSame( array( 'title', 'priority' ), $index->columns );
		$this->assertSame( array( 'title' => 191 ), $index->lengths );
		$this->assertSame( array( 'priority' => 'DESC' ), $index->directions );

		$sql = $index->get_create_string();
		$this->assertStringContainsString( '`title`(191)', $sql );
		$this->assertStringContainsString( '`priority` DESC', $sql );
	}

	/**
	 * Test that columns are ordered by Seq_in_index regardless of input order.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_orders_columns_by_seq_in_index() {
		$index = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'     => 'multi',
						'Seq_in_index' => 2,
						'Column_name'  => 'b',
					)
				),
				$this->show_index_row(
					array(
						'Key_name'     => 'multi',
						'Seq_in_index' => 1,
						'Column_name'  => 'a',
					)
				),
			)
		);

		$this->assertInstanceOf( Index::class, $index );
		$this->assertSame( array( 'a', 'b' ), $index->columns );
	}

	/**
	 * Test that a FULLTEXT index type becomes a FULLTEXT KEY.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_builds_fulltext_key() {
		$index = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'    => 'ft',
						'Column_name' => 'body',
						'Index_type'  => 'FULLTEXT',
						'Collation'   => null,
					)
				),
			)
		);

		$this->assertInstanceOf( Index::class, $index );
		$this->assertSame( 'fulltext', $index->type );
		$this->assertStringContainsString( 'FULLTEXT KEY `ft` (`body`)', $index->get_create_string() );
		$this->assertStringNotContainsString( 'USING', $index->get_create_string() );
	}

	/**
	 * Test that an Index_comment is carried into the create string.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_carries_index_comment() {
		$index = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'      => 'idx',
						'Column_name'   => 'col',
						'Index_comment' => 'lookup index',
					)
				),
			)
		);

		$this->assertInstanceOf( Index::class, $index );
		$this->assertStringContainsString( "COMMENT 'lookup index'", $index->get_create_string() );
	}

	/**
	 * Test that a functional key part (no column name) rejects the whole index.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_functional_key_part_returns_false() {
		$result = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'     => 'idx',
						'Seq_in_index' => 1,
						'Column_name'  => 'name',
					)
				),
				$this->show_index_row(
					array(
						'Key_name'     => 'idx',
						'Seq_in_index' => 2,
						'Column_name'  => null,
						'Expression'   => 'lower(`name`)',
					)
				),
			)
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test that an unrepresentable index type (SPATIAL) is rejected.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_spatial_index_returns_false() {
		$result = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'    => 'geo',
						'Column_name' => 'location',
						'Index_type'  => 'SPATIAL',
					)
				),
			)
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test that a HASH primary key records no USING (get_create_string ignores it).
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_hash_primary_omits_using() {
		$index = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'    => 'PRIMARY',
						'Non_unique'  => 0,
						'Column_name' => 'id',
						'Index_type'  => 'HASH',
					)
				),
			)
		);

		$this->assertInstanceOf( Index::class, $index );
		$this->assertSame( 'primary', $index->type );

		$sql = $index->get_create_string();
		$this->assertStringContainsString( 'PRIMARY KEY (`id`)', $sql );
		$this->assertStringNotContainsString( 'USING', $sql );
	}

	/**
	 * Test that a HASH non-primary index records a USING HASH method.
	 *
	 * @since 3.1.0
	 */
	public function test_from_mysql_hash_non_primary_uses_hash() {
		$index = Index::from_mysql(
			array(
				$this->show_index_row(
					array(
						'Key_name'    => 'lookup',
						'Column_name' => 'token',
						'Index_type'  => 'HASH',
					)
				),
			)
		);

		$this->assertInstanceOf( Index::class, $index );
		$this->assertStringContainsString( 'USING HASH', $index->get_create_string() );
	}
}
