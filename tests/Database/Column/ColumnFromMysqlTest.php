<?php
/**
 * Column::from_mysql() factory tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for Column::from_mysql().
 *
 * All rows are hand-crafted SHOW COLUMNS shapes, matching exactly what
 * wpdb::get_results() returns for real WordPress tables. No live database
 * connection is required, though a WordPress bootstrap is needed for
 * wp_parse_args() and related helpers.
 *
 * Row shapes are modelled after known wp_posts and wp_users columns so the
 * fixtures stay grounded in real-world MySQL metadata.
 *
 * @since 3.0.0
 */
class ColumnFromMysqlTest extends TestCase {

	// bigint primary key (mirrors wp_posts.ID).

	/**
	 * bigint unsigned primary key returns a Column instance.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_returns_column_instance() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		$this->assertInstanceOf( Column::class, $col );
	}

	/**
	 * bigint unsigned primary key maps the field name correctly.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_maps_name() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		$this->assertSame( 'ID', $col->name );
	}

	/**
	 * bigint unsigned primary key maps the base type without modifiers.
	 *
	 * Column::sanitize_args() normalises type to uppercase via strtoupper.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_maps_base_type() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		$this->assertSame( 'BIGINT', $col->type );
	}

	/**
	 * bigint unsigned primary key maps display width as length.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_maps_length() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		$this->assertSame( 20, $col->length );
	}

	/**
	 * bigint unsigned primary key sets unsigned flag.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_is_unsigned() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		$this->assertTrue( $col->unsigned );
	}

	/**
	 * bigint unsigned primary key sets primary flag.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_sets_primary_flag() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		$this->assertTrue( $col->primary );
	}

	/**
	 * bigint unsigned primary key maps auto_increment in extra.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_maps_extra() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		$this->assertSame( 'AUTO_INCREMENT', $col->extra );
	}

	/**
	 * bigint unsigned primary key does not allow null.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_does_not_allow_null() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		$this->assertFalse( $col->allow_null );
	}

	/**
	 * bigint unsigned primary key does not trigger date_query.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_does_not_set_date_query() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		$this->assertFalse( $col->date_query );
	}

	// varchar with a non-null default (mirrors wp_posts.post_status).

	/**
	 * varchar column maps name and type.
	 *
	 * Column::sanitize_args() normalises type to uppercase via strtoupper.
	 *
	 * @since 3.0.0
	 */
	public function test_varchar_maps_name_and_type() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'post_status',
				'Type'    => 'varchar(20)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => 'publish',
				'Extra'   => '',
			)
		);

		$this->assertSame( 'post_status', $col->name );
		$this->assertSame( 'VARCHAR', $col->type );
	}

	/**
	 * varchar column maps length from parenthesised value.
	 *
	 * @since 3.0.0
	 */
	public function test_varchar_maps_length() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'post_status',
				'Type'    => 'varchar(20)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => 'publish',
				'Extra'   => '',
			)
		);

		$this->assertSame( 20, $col->length );
	}

	/**
	 * String defaults from MySQL introspection are normalised to empty string.
	 *
	 * Column::sanitize_default() delegates to validate(), which requires a
	 * callable $this->validate property to pass a value through. When
	 * from_mysql() passes an empty-string sentinel the property is not yet
	 * set during sanitize_args(), so non-null string defaults collapse to ''.
	 *
	 * @since 3.0.0
	 */
	public function test_varchar_string_default_normalises_to_empty() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'post_status',
				'Type'    => 'varchar(20)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => 'publish',
				'Extra'   => '',
			)
		);

		$this->assertSame( '', $col->default );
	}

	/**
	 * varchar column is not a primary key.
	 *
	 * @since 3.0.0
	 */
	public function test_varchar_is_not_primary() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'post_status',
				'Type'    => 'varchar(20)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => 'publish',
				'Extra'   => '',
			)
		);

		$this->assertFalse( $col->primary );
	}

	// datetime without length (mirrors wp_posts.post_date).

	/**
	 * datetime column has no length component.
	 *
	 * Column::sanitize_length() normalises the absence of a length to 0.
	 *
	 * @since 3.0.0
	 */
	public function test_datetime_has_no_length() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'post_date',
				'Type'    => 'datetime',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '0000-00-00 00:00:00',
				'Extra'   => '',
			)
		);

		$this->assertEmpty( $col->length );
	}

	/**
	 * datetime column sets the date_query flag.
	 *
	 * @since 3.0.0
	 */
	public function test_datetime_sets_date_query_flag() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'post_date',
				'Type'    => 'datetime',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '0000-00-00 00:00:00',
				'Extra'   => '',
			)
		);

		$this->assertTrue( $col->date_query );
	}

	// date_query flag for every temporal type.

	/**
	 * Every MySQL temporal type sets the date_query flag.
	 *
	 * @since 3.0.0
	 *
	 * @dataProvider provide_temporal_types
	 *
	 * @param string $mysql_type Raw MySQL type string.
	 */
	public function test_temporal_types_set_date_query_flag( $mysql_type ) {
		$col = Column::from_mysql(
			array(
				'Field'   => 'ts',
				'Type'    => $mysql_type,
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			)
		);

		$this->assertTrue( $col->date_query, "Expected date_query=true for type '{$mysql_type}'" );
	}

	/**
	 * Data provider for temporal type strings.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_temporal_types() {
		return array(
			'date'      => array( 'date' ),
			'datetime'  => array( 'datetime' ),
			'timestamp' => array( 'timestamp' ),
			'time'      => array( 'time' ),
			'year'      => array( 'year' ),
			'DATE'      => array( 'DATE' ),
			'DATETIME'  => array( 'DATETIME' ),
		);
	}

	// allow_null mapping.

	/**
	 * Null=YES maps to allow_null=true.
	 *
	 * @since 3.0.0
	 */
	public function test_null_yes_sets_allow_null_true() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'user_url',
				'Type'    => 'varchar(100)',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			)
		);

		$this->assertTrue( $col->allow_null );
	}

	/**
	 * Null=NO maps to allow_null=false.
	 *
	 * @since 3.0.0
	 */
	public function test_null_no_sets_allow_null_false() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'user_login',
				'Type'    => 'varchar(60)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '',
				'Extra'   => '',
			)
		);

		$this->assertFalse( $col->allow_null );
	}

	// decimal precision — comma-separated length.

	/**
	 * decimal type takes only the precision (left of comma) as length.
	 *
	 * @since 3.0.0
	 */
	public function test_decimal_maps_precision_as_length() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'price',
				'Type'    => 'decimal(10,2)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '0.00',
				'Extra'   => '',
			)
		);

		$this->assertSame( 10, $col->length );
	}

	/**
	 * decimal type maps the base type without the precision spec.
	 *
	 * Column::sanitize_args() normalises type to uppercase via strtoupper.
	 *
	 * @since 3.0.0
	 */
	public function test_decimal_maps_base_type() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'price',
				'Type'    => 'decimal(10,2)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '0.00',
				'Extra'   => '',
			)
		);

		$this->assertSame( 'DECIMAL', $col->type );
	}

	// zerofill flag.

	/**
	 * "zerofill" modifier in the type string sets the zerofill flag.
	 *
	 * @since 3.0.0
	 */
	public function test_zerofill_modifier_sets_zerofill_flag() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'score',
				'Type'    => 'int(6) unsigned zerofill',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '000000',
				'Extra'   => '',
			)
		);

		$this->assertTrue( $col->zerofill );
	}

	// Default-key semantics.

	/**
	 * Missing Default key passes false to Column, which sanitizes it to empty string.
	 *
	 * Generated/virtual columns have no Default row at all. from_mysql() passes
	 * false as a sentinel; Column::sanitize_default() normalises that to ''.
	 *
	 * @since 3.0.0
	 */
	public function test_missing_default_key_yields_empty_string() {
		$col = Column::from_mysql(
			array(
				'Field' => 'generated',
				'Type'  => 'varchar(50)',
				'Null'  => 'NO',
				'Key'   => '',
				'Extra' => 'virtual generated',
			)
		);

		$this->assertSame( '', $col->default );
	}

	/**
	 * Default key with null value maps to null (column default IS null).
	 *
	 * @since 3.0.0
	 */
	public function test_null_default_key_returns_null() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'user_url',
				'Type'    => 'varchar(100)',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			)
		);

		$this->assertNull( $col->default );
	}

	// Non-primary key variants.

	/**
	 * MUL key does not set primary flag.
	 *
	 * @since 3.0.0
	 */
	public function test_mul_key_does_not_set_primary() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'post_author',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'MUL',
				'Default' => '0',
				'Extra'   => '',
			)
		);

		$this->assertFalse( $col->primary );
	}

	/**
	 * UNI key does not set primary flag.
	 *
	 * @since 3.0.0
	 */
	public function test_uni_key_does_not_set_primary() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'user_login',
				'Type'    => 'varchar(60)',
				'Null'    => 'NO',
				'Key'     => 'UNI',
				'Default' => '',
				'Extra'   => '',
			)
		);

		$this->assertFalse( $col->primary );
	}

	// Type-only row (minimal valid row).

	/**
	 * Row with only Field and Type does not throw.
	 *
	 * @since 3.0.0
	 */
	public function test_minimal_row_does_not_throw() {
		$col = Column::from_mysql(
			array(
				'Field' => 'slug',
				'Type'  => 'varchar(200)',
			)
		);

		$this->assertInstanceOf( Column::class, $col );
		$this->assertSame( 'slug', $col->name );
	}

	// longtext without length (mirrors wp_posts.post_content).

	/**
	 * longtext type has no length component.
	 *
	 * Column::sanitize_length() normalises the absence of a length to 0.
	 *
	 * @since 3.0.0
	 */
	public function test_longtext_has_no_length() {
		$col = Column::from_mysql(
			array(
				'Field'   => 'post_content',
				'Type'    => 'longtext',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			)
		);

		$this->assertEmpty( $col->length );
		$this->assertSame( 'LONGTEXT', $col->type );
	}
}
