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

	/**
	 * Column built from a bigint unsigned primary key row (mirrors wp_posts.ID).
	 *
	 * @since 3.0.0
	 * @var Column
	 */
	private static $id_col;

	/**
	 * Column built from a varchar row with a string default (mirrors wp_posts.post_status).
	 *
	 * @since 3.0.0
	 * @var Column
	 */
	private static $status_col;

	/**
	 * Column built from a datetime row (mirrors wp_posts.post_date).
	 *
	 * @since 3.0.0
	 * @var Column
	 */
	private static $date_col;

	/**
	 * Build shared fixture columns once before the suite runs.
	 *
	 * @since 3.0.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$id_col = Column::from_mysql(
			array(
				'Field'   => 'ID',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			)
		);

		self::$status_col = Column::from_mysql(
			array(
				'Field'   => 'post_status',
				'Type'    => 'varchar(20)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => 'publish',
				'Extra'   => '',
			)
		);

		self::$date_col = Column::from_mysql(
			array(
				'Field'   => 'post_date',
				'Type'    => 'datetime',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '0000-00-00 00:00:00',
				'Extra'   => '',
			)
		);
	}

	// bigint primary key (mirrors wp_posts.ID).

	/**
	 * bigint unsigned primary key returns a Column instance.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_returns_column_instance() {
		$this->assertInstanceOf( Column::class, self::$id_col );
	}

	/**
	 * bigint unsigned primary key maps the field name correctly.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_maps_name() {
		$this->assertSame( 'ID', self::$id_col->name );
	}

	/**
	 * bigint unsigned primary key maps the base type without modifiers.
	 *
	 * Column::sanitize_args() normalises type to uppercase via strtoupper.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_maps_base_type() {
		$this->assertSame( 'BIGINT', self::$id_col->type );
	}

	/**
	 * bigint unsigned primary key maps display width as length.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_maps_length() {
		$this->assertSame( 20, self::$id_col->length );
	}

	/**
	 * bigint unsigned primary key sets unsigned flag.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_is_unsigned() {
		$this->assertTrue( self::$id_col->unsigned );
	}

	/**
	 * bigint unsigned primary key sets primary flag.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_sets_primary_flag() {
		$this->assertTrue( self::$id_col->primary );
	}

	/**
	 * bigint unsigned primary key maps auto_increment in extra.
	 *
	 * Column::sanitize_extra() normalises extra to uppercase.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_maps_extra() {
		$this->assertSame( 'AUTO_INCREMENT', self::$id_col->extra );
	}

	/**
	 * bigint unsigned primary key does not allow null.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_does_not_allow_null() {
		$this->assertFalse( self::$id_col->allow_null );
	}

	/**
	 * bigint unsigned primary key does not trigger date_query.
	 *
	 * @since 3.0.0
	 */
	public function test_bigint_primary_key_does_not_set_date_query() {
		$this->assertFalse( self::$id_col->date_query );
	}

	// varchar with a string default (mirrors wp_posts.post_status).

	/**
	 * varchar column maps name and type.
	 *
	 * Column::sanitize_args() normalises type to uppercase via strtoupper.
	 *
	 * @since 3.0.0
	 */
	public function test_varchar_maps_name_and_type() {
		$this->assertSame( 'post_status', self::$status_col->name );
		$this->assertSame( 'VARCHAR', self::$status_col->type );
	}

	/**
	 * varchar column maps length from parenthesised value.
	 *
	 * @since 3.0.0
	 */
	public function test_varchar_maps_length() {
		$this->assertSame( 20, self::$status_col->length );
	}

	/**
	 * varchar column preserves the MySQL default value string.
	 *
	 * sanitize_default() passes the value through validate(), which returns the
	 * raw value when no validate callback is registered yet at construction time.
	 *
	 * @since 3.0.0
	 */
	public function test_varchar_preserves_default_value() {
		$this->assertSame( 'publish', self::$status_col->default );
	}

	/**
	 * varchar column is not a primary key.
	 *
	 * @since 3.0.0
	 */
	public function test_varchar_is_not_primary() {
		$this->assertFalse( self::$status_col->primary );
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
		$this->assertEmpty( self::$date_col->length );
	}

	/**
	 * datetime column sets the date_query flag.
	 *
	 * @since 3.0.0
	 */
	public function test_datetime_sets_date_query_flag() {
		$this->assertTrue( self::$date_col->date_query );
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
	 * Missing Default key passes false to Column, which is preserved as false.
	 *
	 * Generated/virtual columns have no Default row at all. from_mysql() passes
	 * false as a sentinel, and sanitize_default() returns it unchanged because
	 * false is not null and no validate callback is registered at construction.
	 *
	 * @since 3.0.0
	 */
	public function test_missing_default_key_returns_false() {
		$col = Column::from_mysql(
			array(
				'Field' => 'generated',
				'Type'  => 'varchar(50)',
				'Null'  => 'NO',
				'Key'   => '',
				'Extra' => 'virtual generated',
			)
		);

		$this->assertFalse( $col->default );
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
