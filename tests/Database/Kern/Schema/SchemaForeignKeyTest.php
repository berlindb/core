<?php
/**
 * Enforced foreign-key DDL emission (#205 / #193 Phase 5).
 *
 * An enforced belongs_to relationship now emits a FOREIGN KEY fragment - inside
 * CREATE TABLE (Schema::get_create_table_string()) and as a reusable list
 * (get_foreign_key_strings()), with the remote table resolved from the remote
 * Query class. These are integration tests: resolving the remote name needs the
 * remote table registered on $wpdb, which constructing a TestTable does.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Relationship;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Schema with a composite ( two-column ) enforced belongs_to, declared via
 * get_relationships() ( the per-column shorthand is single-column only ).
 *
 * @since 3.1.0
 */
class SchemaFkCompositeSchema extends Schema {
	public $columns = array(
		array(
			'name' => 'a',
			'type' => 'bigint',
		),
		array(
			'name' => 'b',
			'type' => 'bigint',
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'       => 'comp',
					'query'      => TestQuery::class,
					'type'       => 'belongs_to',
					'columns'    => array( 'a', 'b' ),
					'references' => array( 'x', 'y' ),
					'enforce'    => true,
				)
			),
		);
	}
}

/**
 * Integration tests for enforced foreign-key emission.
 *
 * @since 3.1.0
 */
class SchemaForeignKeyTest extends TestCase {

	/**
	 * Register the remote (test_widgets) table on $wpdb so the remote Query
	 * resolves to a physical table name.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		new TestTable();
	}

	/**
	 * Build a schema with one column carrying a belongs_to relationship.
	 *
	 * @since 3.1.0
	 *
	 * @param bool $enforce Whether the relationship is enforced.
	 * @return Schema
	 */
	private function schema_with_relationship( bool $enforce ): Schema {
		return new Schema(
			array(
				'columns' => array(
					array(
						'name'    => 'id',
						'type'    => 'bigint',
						'primary' => true,
					),
					array(
						'name'          => 'widget_id',
						'type'          => 'bigint',
						'relationships' => array(
							array(
								'type'    => 'belongs_to',
								'query'   => TestQuery::class,
								'column'  => 'id',
								'enforce' => $enforce,
							),
						),
					),
				),
			)
		);
	}

	/**
	 * An enforced relationship yields a FOREIGN KEY fragment with the resolved remote.
	 *
	 * @since 3.1.0
	 */
	public function test_enforced_relationship_yields_a_foreign_key_string() {
		$fragments = $this->schema_with_relationship( true )->get_foreign_key_strings();

		$this->assertCount( 1, $fragments );
		$this->assertStringContainsString( 'FOREIGN KEY', $fragments[0] );
		$this->assertStringContainsString( '`widget_id`', $fragments[0] );
		$this->assertStringContainsString( 'test_widgets', $fragments[0] );
	}

	/**
	 * A composite enforced relationship yields a multi-column FOREIGN KEY fragment.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_enforced_relationship_yields_a_multi_column_foreign_key() {
		$fragments = ( new SchemaFkCompositeSchema() )->get_foreign_key_strings();

		$this->assertCount( 1, $fragments );
		$this->assertStringContainsString( 'FOREIGN KEY (`a`, `b`)', $fragments[0] );
		$this->assertStringContainsString( '(`x`, `y`)', $fragments[0] );
	}

	/**
	 * CREATE TABLE omits foreign keys by default (deferred emission).
	 *
	 * @since 3.1.0
	 */
	public function test_create_table_string_omits_foreign_keys_by_default() {
		$sql = $this->schema_with_relationship( true )->get_create_table_string();

		$this->assertStringNotContainsString( 'FOREIGN KEY', $sql );
	}

	/**
	 * CREATE TABLE includes the enforced foreign key when opted in.
	 *
	 * @since 3.1.0
	 */
	public function test_create_table_string_includes_the_foreign_key_when_opted_in() {
		$sql = $this->schema_with_relationship( true )->get_create_table_string( true );

		$this->assertStringContainsString( 'FOREIGN KEY', $sql );
		$this->assertStringContainsString( '`widget_id`', $sql );
	}

	/**
	 * When the remote is registered, nothing is unresolved (the happy path).
	 *
	 * @since 3.1.0
	 */
	public function test_no_unresolved_foreign_keys_when_remote_registered() {
		$this->assertSame(
			array(),
			$this->schema_with_relationship( true )->get_unresolved_foreign_keys()
		);
	}

	/**
	 * A non-enforced relationship stays application-level and emits no DDL.
	 *
	 * @since 3.1.0
	 */
	public function test_non_enforced_relationship_emits_nothing() {
		$schema = $this->schema_with_relationship( false );

		$this->assertSame( array(), $schema->get_foreign_key_strings() );
		$this->assertStringNotContainsString( 'FOREIGN KEY', $schema->get_create_table_string() );
	}

	/**
	 * Build a schema whose enforced belongs_to uses ON DELETE SET NULL, with the local
	 * (foreign-key) column's nullability controlled - to exercise the "SET NULL needs a
	 * nullable local column" validation (see #205 for the opt-in FK DDL rollout).
	 *
	 * @since 3.1.0
	 *
	 * @param bool $nullable Whether the local column allows null.
	 * @return Schema
	 */
	private function schema_with_set_null( bool $nullable ): Schema {
		return new Schema(
			array(
				'columns' => array(
					array(
						'name'    => 'id',
						'type'    => 'bigint',
						'primary' => true,
					),
					array(
						'name'          => 'widget_id',
						'type'          => 'bigint',
						'allow_null'    => $nullable,
						'relationships' => array(
							array(
								'type'      => 'belongs_to',
								'query'     => TestQuery::class,
								'column'    => 'id',
								'enforce'   => true,
								'on_delete' => 'SET NULL',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * An enforced ON DELETE SET NULL on a NON-nullable local column is a validation
	 * error - MySQL would reject the emitted FOREIGN KEY (#205).
	 *
	 * @since 3.1.0
	 */
	public function test_set_null_on_non_nullable_local_column_is_a_validation_error() {
		$errors = implode( ' | ', $this->schema_with_set_null( false )->get_validation_errors() );

		$this->assertStringContainsString( 'SET NULL', $errors );
		$this->assertStringContainsString( 'widget_id', $errors );
	}

	/**
	 * The same SET NULL on a NULLABLE, non-primary local column is fully valid.
	 *
	 * @since 3.1.0
	 */
	public function test_set_null_on_nullable_local_column_is_valid() {
		$this->assertSame(
			array(),
			$this->schema_with_set_null( true )->get_validation_errors()
		);
	}

	/**
	 * A local column in the PRIMARY KEY is implicitly NOT NULL to MySQL even when it
	 * declares allow_null => true, so an enforced SET NULL on it is still a validation
	 * error (#205). Guards the primary-key false negative.
	 *
	 * @since 3.1.0
	 */
	public function test_set_null_on_primary_key_local_column_is_a_validation_error() {
		$schema = new Schema(
			array(
				'columns' => array(
					array(
						'name'          => 'widget_id',
						'type'          => 'bigint',
						'allow_null'    => true,
						'relationships' => array(
							array(
								'type'      => 'belongs_to',
								'query'     => TestQuery::class,
								'column'    => 'id',
								'enforce'   => true,
								'on_delete' => 'SET NULL',
							),
						),
					),
				),
				'indexes' => array(
					array(
						'type'    => 'primary',
						'columns' => array( 'widget_id' ),
					),
				),
			)
		);

		$errors = implode( ' | ', $schema->get_validation_errors() );

		$this->assertStringContainsString( 'SET NULL', $errors );
		$this->assertStringContainsString( 'widget_id', $errors );
	}
}
