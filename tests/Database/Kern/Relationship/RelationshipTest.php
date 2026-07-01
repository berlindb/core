<?php
/**
 * Relationship class tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Relationship;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BerlinDB\Database\Kern\Relationship.
 *
 * Relationship is a pure data container; these tests do not require a database
 * connection, only a WordPress bootstrap for functions like wp_parse_args and
 * wp_validate_boolean.
 *
 * @since 3.1.0
 */
class RelationshipTest extends TestCase {

	// Default property values.

	/**
	 * Test that the default type is belongs_to.
	 *
	 * @since 3.1.0
	 */
	public function test_default_type_is_belongs_to() {
		$relationship = new Relationship();
		$this->assertSame( 'belongs_to', $relationship->type );
	}

	/**
	 * Test that default columns, references, name, and actions are empty.
	 *
	 * @since 3.1.0
	 */
	public function test_default_collections_and_strings_are_empty() {
		$relationship = new Relationship();
		$this->assertSame( array(), $relationship->columns );
		$this->assertSame( array(), $relationship->references );
		$this->assertSame( '', $relationship->name );
		$this->assertSame( '', $relationship->query );
		$this->assertSame( '', $relationship->on_delete );
		$this->assertSame( '', $relationship->on_update );
		$this->assertSame( '', $relationship->constraint );
	}

	/**
	 * Test that enforce defaults to false.
	 *
	 * @since 3.1.0
	 */
	public function test_default_enforce_is_false() {
		$relationship = new Relationship();
		$this->assertFalse( $relationship->enforce );
		$this->assertFalse( $relationship->is_enforced() );
	}

	// Sanitization.

	/**
	 * Test that a recognized type is preserved.
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_type_is_preserved() {
		$relationship = new Relationship( array( 'type' => 'has_many' ) );
		$this->assertSame( 'has_many', $relationship->type );
	}

	/**
	 * Test that an unrecognized type falls back to belongs_to.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_type_falls_back_to_belongs_to() {
		$relationship = new Relationship( array( 'type' => 'nonsense' ) );
		$this->assertSame( 'belongs_to', $relationship->type );
	}

	/**
	 * Test that a clean fully-qualified Query class name is accepted unchanged.
	 *
	 * @since 3.1.0
	 */
	public function test_query_class_name_valid_is_accepted() {
		$relationship = new Relationship(
			array( 'query' => 'EDD\\Database\\Queries\\Order' )
		);
		$this->assertSame( 'EDD\\Database\\Queries\\Order', $relationship->query );
	}

	/**
	 * Test that a Query class name with invalid characters is REJECTED to '',
	 * not silently mutated into a different class name.
	 *
	 * @since 3.1.0
	 */
	public function test_query_class_name_invalid_is_rejected() {
		$relationship = new Relationship(
			array( 'query' => 'EDD\\Database\\Queries\\Order; DROP TABLE' )
		);
		$this->assertSame( '', $relationship->query );
	}

	/**
	 * Test that columns are sanitized and non-string entries dropped.
	 *
	 * @since 3.1.0
	 */
	public function test_columns_are_sanitized() {
		$relationship = new Relationship(
			array(
				'columns' => array( 'order-id', 123, 'tenant_id' ),
			)
		);
		$this->assertSame( array( 'order_id', 'tenant_id' ), $relationship->columns );
	}

	/**
	 * Test that references are sanitized like columns.
	 *
	 * @since 3.1.0
	 */
	public function test_references_are_sanitized() {
		$relationship = new Relationship(
			array(
				'references' => array( 'id' ),
			)
		);
		$this->assertSame( array( 'id' ), $relationship->references );
	}

	/**
	 * Test that referential actions are normalized and uppercased.
	 *
	 * @since 3.1.0
	 */
	public function test_referential_actions_are_normalized() {
		$relationship = new Relationship(
			array(
				'on_delete' => 'cascade',
				'on_update' => 'set null',
			)
		);
		$this->assertSame( 'CASCADE', $relationship->on_delete );
		$this->assertSame( 'SET NULL', $relationship->on_update );
	}

	/**
	 * Test that an unrecognized referential action becomes an empty string.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_referential_action_is_dropped() {
		$relationship = new Relationship( array( 'on_delete' => 'explode' ) );
		$this->assertSame( '', $relationship->on_delete );
	}

	/**
	 * Test that enforce is coerced to a boolean.
	 *
	 * @since 3.1.0
	 */
	public function test_enforce_is_coerced_to_boolean() {
		$relationship = new Relationship( array( 'enforce' => '1' ) );
		$this->assertTrue( $relationship->enforce );
		$this->assertTrue( $relationship->is_enforced() );
	}

	// Helpers.

	/**
	 * Test that get_query_class() returns the remote Query FQCN.
	 *
	 * @since 3.1.0
	 */
	public function test_get_query_class_returns_query() {
		$relationship = new Relationship(
			array( 'query' => 'EDD\\Database\\Queries\\Order' )
		);
		$this->assertSame( 'EDD\\Database\\Queries\\Order', $relationship->get_query_class() );
	}

	// DDL emission.

	/**
	 * Test that an unenforced relationship emits no DDL.
	 *
	 * @since 3.1.0
	 */
	public function test_create_string_is_empty_when_not_enforced() {
		$relationship = new Relationship(
			array(
				'columns'    => array( 'order_id' ),
				'references' => array( 'id' ),
			)
		);
		$this->assertSame( '', $relationship->get_create_string( 'wp_acme_orders' ) );
	}

	/**
	 * Test that a has_many relationship emits no DDL even when enforced.
	 *
	 * @since 3.1.0
	 */
	public function test_create_string_is_empty_for_has_many() {
		$relationship = new Relationship(
			array(
				'type'       => 'has_many',
				'enforce'    => true,
				'columns'    => array( 'id' ),
				'references' => array( 'order_id' ),
			)
		);
		$this->assertSame( '', $relationship->get_create_string( 'wp_acme_order_items' ) );
	}

	/**
	 * Test that DDL is empty without a resolved remote table name.
	 *
	 * @since 3.1.0
	 */
	public function test_create_string_is_empty_without_remote_table() {
		$relationship = new Relationship(
			array(
				'enforce'    => true,
				'columns'    => array( 'order_id' ),
				'references' => array( 'id' ),
			)
		);
		$this->assertSame( '', $relationship->get_create_string() );
	}

	/**
	 * Test that DDL is empty when the column and reference counts differ.
	 *
	 * @since 3.1.0
	 */
	public function test_create_string_is_empty_on_arity_mismatch() {
		$relationship = new Relationship(
			array(
				'enforce'    => true,
				'columns'    => array( 'order_id', 'tenant_id' ),
				'references' => array( 'id' ),
			)
		);
		$this->assertSame( '', $relationship->get_create_string( 'wp_acme_orders' ) );
	}

	/**
	 * Test that an enforced owning-side relationship emits a FOREIGN KEY clause.
	 *
	 * @since 3.1.0
	 */
	public function test_create_string_emits_foreign_key() {
		$relationship = new Relationship(
			array(
				'enforce'    => true,
				'columns'    => array( 'order_id' ),
				'references' => array( 'id' ),
			)
		);
		$this->assertSame(
			'FOREIGN KEY (`order_id`) REFERENCES `wp_acme_orders` (`id`)',
			$relationship->get_create_string( 'wp_acme_orders' )
		);
	}

	/**
	 * Test that a named, composite relationship emits a full constraint clause.
	 *
	 * @since 3.1.0
	 */
	public function test_create_string_emits_named_composite_with_actions() {
		$relationship = new Relationship(
			array(
				'constraint' => 'fk_order',
				'enforce'    => true,
				'columns'    => array( 'order_id', 'tenant_id' ),
				'references' => array( 'id', 'tenant_id' ),
				'on_delete'  => 'cascade',
				'on_update'  => 'restrict',
			)
		);
		$this->assertSame(
			'CONSTRAINT `fk_order` FOREIGN KEY (`order_id`, `tenant_id`) REFERENCES `wp_acme_orders` (`id`, `tenant_id`) ON DELETE CASCADE ON UPDATE RESTRICT',
			$relationship->get_create_string( 'wp_acme_orders' )
		);
	}

	// Magic-friendly property access (protected + __get).

	/**
	 * Test that a protected property is still readable externally via __get().
	 *
	 * @since 3.1.0
	 */
	public function test_protected_property_is_readable_via_magic_get() {
		$relationship = new Relationship( array( 'type' => 'has_many' ) );

		/*
		 * Reading the protected $type from outside the class routes through
		 * the Magic trait's __get() and returns the value unchanged.
		 */
		$this->assertSame( 'has_many', $relationship->type );
	}

	/**
	 * Test that a subclass can override how a property appears via a get_*()
	 * method - the override that protected properties make possible (#46).
	 *
	 * @since 3.1.0
	 */
	public function test_subclass_can_override_property_via_getter() {
		$relationship = new class( array( 'type' => 'belongs_to' ) ) extends Relationship {

			/**
			 * Present the type in upper case to external readers.
			 *
			 * @return string
			 */
			public function get_type() {
				return strtoupper( $this->type );
			}
		};

		/*
		 * External property access routes through __get(), which prefers the
		 * subclass getter over the stored value.
		 */
		$this->assertSame( 'BELONGS_TO', $relationship->type );
	}

	// Accessor name (#193).

	/**
	 * Test that the accessor name derives from the local column.
	 *
	 * @since 3.1.0
	 */
	public function test_name_derives_from_local_column() {
		$relationship = new Relationship(
			array(
				'type'       => 'belongs_to',
				'columns'    => array( 'customer_id' ),
				'references' => array( 'id' ),
				'query'      => 'EDD\\Database\\Queries\\Customer',
			)
		);
		$this->assertSame( 'customer', $relationship->name );
	}

	/**
	 * Test that derivation disambiguates two relationships to the same target.
	 *
	 * @since 3.1.0
	 */
	public function test_name_derivation_disambiguates_same_target() {
		$creator  = new Relationship(
			array(
				'columns'    => array( 'created_by_user_id' ),
				'references' => array( 'id' ),
				'query'      => 'EDD\\Database\\Queries\\User',
			)
		);
		$assignee = new Relationship(
			array(
				'columns'    => array( 'assigned_to_user_id' ),
				'references' => array( 'id' ),
				'query'      => 'EDD\\Database\\Queries\\User',
			)
		);
		$this->assertSame( 'created_by_user', $creator->name );
		$this->assertSame( 'assigned_to_user', $assignee->name );
	}

	/**
	 * Test that an explicit name overrides derivation.
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_name_overrides_derivation() {
		$relationship = new Relationship(
			array(
				'name'    => 'creator',
				'columns' => array( 'created_by_user_id' ),
				'query'   => 'EDD\\Database\\Queries\\User',
			)
		);
		$this->assertSame( 'creator', $relationship->name );
	}

	/**
	 * Test that the accessor name never appears in emitted DDL.
	 *
	 * @since 3.1.0
	 */
	public function test_name_is_not_emitted_in_ddl() {
		$relationship = new Relationship(
			array(
				'name'       => 'customer',
				'enforce'    => true,
				'columns'    => array( 'customer_id' ),
				'references' => array( 'id' ),
			)
		);

		/*
		 * No 'constraint' was set, so MySQL auto-names: no CONSTRAINT prefix,
		 * and the accessor name must not leak into the SQL.
		 */
		$this->assertSame(
			'FOREIGN KEY (`customer_id`) REFERENCES `wp_acme_customers` (`id`)',
			$relationship->get_create_string( 'wp_acme_customers' )
		);
	}

	/**
	 * Test that is_foreign_key() is true only for an enforced belongs_to.
	 *
	 * @since 3.1.0
	 */
	public function test_is_foreign_key() {
		$enforced_belongs_to = new Relationship(
			array(
				'type'    => 'belongs_to',
				'enforce' => true,
				'columns' => array( 'customer_id' ),
			)
		);
		$this->assertTrue( $enforced_belongs_to->is_foreign_key() );

		$unenforced = new Relationship(
			array(
				'type'    => 'belongs_to',
				'columns' => array( 'customer_id' ),
			)
		);
		$this->assertFalse( $unenforced->is_foreign_key() );

		$enforced_has_many = new Relationship(
			array(
				'type'    => 'has_many',
				'enforce' => true,
				'columns' => array( 'id' ),
			)
		);
		$this->assertFalse( $enforced_has_many->is_foreign_key() );
	}
}
