<?php
/**
 * Schema::from_table() factory integration tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Schema;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Integration tests for Schema::from_table().
 *
 * These tests issue live SHOW COLUMNS queries against the WordPress test
 * database, so they require a full WordPress integration environment.
 * The assertions are grounded in the well-known structure of wp_posts and
 * wp_users, which are guaranteed to exist in every WordPress installation.
 *
 * @since 3.0.0
 */
class SchemaFromTableTest extends TestCase {

	// Guard rails — empty / nonexistent table.

	/**
	 * Empty table name returns an empty Schema.
	 *
	 * @since 3.0.0
	 */
	public function test_empty_table_name_returns_schema_instance() {
		$schema = Schema::from_table( '' );

		$this->assertInstanceOf( Schema::class, $schema );
	}

	/**
	 * Empty table name yields zero columns.
	 *
	 * @since 3.0.0
	 */
	public function test_empty_table_name_yields_no_columns() {
		$schema = Schema::from_table( '' );

		$this->assertEmpty( $schema->columns );
	}

	/**
	 * Nonexistent table name returns a Schema instance.
	 *
	 * from_table() suppresses wpdb errors internally, so no noise is emitted.
	 *
	 * @since 3.0.0
	 */
	public function test_nonexistent_table_returns_schema_instance() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->prefix . 'does_not_exist_berlin_test' );

		$this->assertInstanceOf( Schema::class, $schema );
	}

	/**
	 * Nonexistent table name yields zero columns.
	 *
	 * @since 3.0.0
	 */
	public function test_nonexistent_table_yields_no_columns() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->prefix . 'does_not_exist_berlin_test' );

		$this->assertEmpty( $schema->columns );
	}

	// wp_posts — existence and shape.

	/**
	 * from_table( wp_posts ) returns a Schema instance.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_returns_schema_instance() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );

		$this->assertInstanceOf( Schema::class, $schema );
	}

	/**
	 * from_table( wp_posts ) produces at least one column.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_has_columns() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );

		$this->assertNotEmpty( $schema->columns );
	}

	/**
	 * Every column in the wp_posts schema is a Column instance.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_columns_are_column_instances() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );

		foreach ( $schema->columns as $column ) {
			$this->assertInstanceOf( Column::class, $column );
		}
	}

	/**
	 * wp_posts has at least the 23 columns present since WordPress 3.5.
	 *
	 * WordPress has defined at least 23 columns on wp_posts since 3.5.
	 * Any installation running a supported version will pass this floor.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_has_at_least_twenty_three_columns() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );

		$this->assertGreaterThanOrEqual( 23, count( $schema->columns ) );
	}

	// wp_posts.ID — primary bigint unsigned.

	/**
	 * wp_posts.ID column is present in the schema.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_has_id_column() {
		global $wpdb;
		$schema  = Schema::from_table( $wpdb->posts );
		$id_cols = array_filter(
			$schema->columns,
			static function ( Column $c ) {
				return 'ID' === $c->name;
			}
		);

		$this->assertCount( 1, $id_cols );
	}

	/**
	 * wp_posts.ID is the primary key.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_id_is_primary() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );
		$id_col = $this->get_column( $schema, 'ID' );

		$this->assertTrue( $id_col->primary );
	}

	/**
	 * wp_posts.ID has type BIGINT (Column stores types in uppercase).
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_id_is_bigint() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );
		$id_col = $this->get_column( $schema, 'ID' );

		$this->assertSame( 'BIGINT', $id_col->type );
	}

	/**
	 * wp_posts.ID is unsigned.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_id_is_unsigned() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );
		$id_col = $this->get_column( $schema, 'ID' );

		$this->assertTrue( $id_col->unsigned );
	}

	/**
	 * wp_posts.ID does not allow null.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_id_does_not_allow_null() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );
		$id_col = $this->get_column( $schema, 'ID' );

		$this->assertFalse( $id_col->allow_null );
	}

	/**
	 * wp_posts.ID carries auto_increment in extra.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_id_has_auto_increment_extra() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );
		$id_col = $this->get_column( $schema, 'ID' );

		$this->assertSame( 'AUTO_INCREMENT', $id_col->extra );
	}

	/**
	 * wp_posts.ID does not set the date_query flag.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_id_does_not_set_date_query() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );
		$id_col = $this->get_column( $schema, 'ID' );

		$this->assertFalse( $id_col->date_query );
	}

	// wp_posts.post_date — datetime.

	/**
	 * wp_posts.post_date sets the date_query flag.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_post_date_sets_date_query() {
		global $wpdb;
		$schema   = Schema::from_table( $wpdb->posts );
		$date_col = $this->get_column( $schema, 'post_date' );

		$this->assertTrue( $date_col->date_query );
	}

	/**
	 * wp_posts.post_date has type DATETIME (Column stores types in uppercase).
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_post_date_is_datetime() {
		global $wpdb;
		$schema   = Schema::from_table( $wpdb->posts );
		$date_col = $this->get_column( $schema, 'post_date' );

		$this->assertSame( 'DATETIME', $date_col->type );
	}

	/**
	 * wp_posts.post_date is not a primary key.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_post_date_is_not_primary() {
		global $wpdb;
		$schema   = Schema::from_table( $wpdb->posts );
		$date_col = $this->get_column( $schema, 'post_date' );

		$this->assertFalse( $date_col->primary );
	}

	// wp_posts.post_status — varchar.

	/**
	 * wp_posts.post_status has type VARCHAR (Column stores types in uppercase).
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_post_status_is_varchar() {
		global $wpdb;
		$schema     = Schema::from_table( $wpdb->posts );
		$status_col = $this->get_column( $schema, 'post_status' );

		$this->assertSame( 'VARCHAR', $status_col->type );
	}

	/**
	 * wp_posts.post_status does not set the date_query flag.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_post_status_does_not_set_date_query() {
		global $wpdb;
		$schema     = Schema::from_table( $wpdb->posts );
		$status_col = $this->get_column( $schema, 'post_status' );

		$this->assertFalse( $status_col->date_query );
	}

	// wp_users — separate table introspection.

	/**
	 * from_table( wp_users ) returns a Schema instance.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_users_returns_schema_instance() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->users );

		$this->assertInstanceOf( Schema::class, $schema );
	}

	/**
	 * wp_users has at least the ten columns present since WordPress 3.0.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_users_has_at_least_ten_columns() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->users );

		$this->assertGreaterThanOrEqual( 10, count( $schema->columns ) );
	}

	/**
	 * wp_users.ID is the primary key.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_users_id_is_primary() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->users );
		$id_col = $this->get_column( $schema, 'ID' );

		$this->assertTrue( $id_col->primary );
	}

	/**
	 * wp_users.user_registered sets the date_query flag.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_users_user_registered_sets_date_query() {
		global $wpdb;
		$schema  = Schema::from_table( $wpdb->users );
		$reg_col = $this->get_column( $schema, 'user_registered' );

		$this->assertTrue( $reg_col->date_query );
	}

	/**
	 * wp_users.user_login is not a primary key.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_users_user_login_is_not_primary() {
		global $wpdb;
		$schema    = Schema::from_table( $wpdb->users );
		$login_col = $this->get_column( $schema, 'user_login' );

		$this->assertFalse( $login_col->primary );
	}

	// Column order is preserved.

	/**
	 * Columns are returned in the same order as SHOW COLUMNS.
	 *
	 * wp_posts always starts with ID as its first column.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_posts_first_column_is_id() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->posts );

		$this->assertSame( 'ID', $schema->columns[0]->name );
	}

	/**
	 * wp_users always starts with ID as its first column.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_users_first_column_is_id() {
		global $wpdb;
		$schema = Schema::from_table( $wpdb->users );

		$this->assertSame( 'ID', $schema->columns[0]->name );
	}

	/** Helpers ***************************************************************/

	/**
	 * Return a named Column from a Schema, failing if it is absent.
	 *
	 * @since 3.0.0
	 *
	 * @param Schema $schema Schema to search.
	 * @param string $name   Column name to find.
	 * @return Column
	 */
	private function get_column( Schema $schema, string $name ): Column {
		foreach ( $schema->columns as $column ) {
			if ( $column->name === $name ) {
				return $column;
			}
		}

		$this->fail( "Column '{$name}' not found in schema." );
	}
}
