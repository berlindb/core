<?php
/**
 * Schema-tier relationship validation (#206).
 *
 * Schema::get_validation_errors() validates the local, context-free side of each
 * relationship: own-shape errors (merged from Relationship), accessor-name
 * uniqueness, local-column existence, a named-but-missing remote query class, and
 * unsupported composite declarations. Remote resolution lives in Query.
 *
 * The composite and missing-local-column checks are defensive: the Column
 * shorthand always yields a single local column (the declaring one), so those
 * cases are produced here by overriding get_relationships() with hand-built
 * Relationship objects.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Relationship;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** An existing remote schema + query, so class_exists() passes. */
class SchemaValRemoteSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);
}

/** Existing remote query (target of the valid relationships below). */
class SchemaValRemoteQuery extends Query {
	protected $prefix       = 'sv';
	protected $table_name   = 'remote';
	protected $table_schema = SchemaValRemoteSchema::class;
	protected $cache_group  = 'remote';
}

/** A clean schema with one valid belongs_to relationship. */
class SchemaValGoodSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
		array(
			'name'          => 'remote_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'name'   => 'remote',
					'query'  => SchemaValRemoteQuery::class,
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),
	);
}

/** A column whose relationship shorthand names an unknown type; Column drops it. */
class SchemaValDroppedTypeSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
		array(
			'name'          => 'remote_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'query'  => SchemaValRemoteQuery::class,
					'column' => 'id',
					'type'   => 'nonsense',
				),
			),
		),
	);
}

/** A column whose relationship shorthand omits the required 'query'; Column drops it. */
class SchemaValDroppedMissingKeySchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
		array(
			'name'          => 'remote_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'column' => 'id',
				),
			),
		),
	);
}

/** Two relationships sharing the same accessor name. */
class SchemaValDuplicateSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'a_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'name'   => 'dup',
					'query'  => SchemaValRemoteQuery::class,
					'column' => 'id',
				),
			),
		),
		array(
			'name'          => 'b_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'name'   => 'dup',
					'query'  => SchemaValRemoteQuery::class,
					'column' => 'id',
				),
			),
		),
	);
}

/** A relationship pointing at a class that does not exist. */
class SchemaValMissingClassSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'remote_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'name'   => 'remote',
					'query'  => 'BerlinDB\\Tests\\NoSuchRemoteClassXYZ',
					'column' => 'id',
				),
			),
		),
	);
}

/** Defensive: a composite (multi-local-column) relationship, injected directly. */
class SchemaValCompositeSchema extends Schema {
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
					'columns'    => array( 'a', 'b' ),
					'query'      => SchemaValRemoteQuery::class,
					'references' => array( 'x', 'y' ),
				)
			),
		);
	}
}

/** Defensive: a relationship naming a local column not in the schema. */
class SchemaValMissingLocalSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'       => 'ghosted',
					'columns'    => array( 'ghost' ),
					'query'      => SchemaValRemoteQuery::class,
					'references' => array( 'id' ),
				)
			),
		);
	}
}

/** Defensive: a relationship whose own shape is invalid (no remote query). */
class SchemaValSelfShapeSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'       => 'broken',
					'columns'    => array( 'id' ),
					'references' => array( 'id' ),
				)
			),
		);
	}
}

/**
 * Tests for the relationship checks in Schema::get_validation_errors().
 *
 * @since 3.1.0
 */
class SchemaRelationshipValidationTest extends TestCase {

	/**
	 * A schema with a valid relationship reports no errors and is valid.
	 *
	 * @since 3.1.0
	 */
	public function test_valid_schema_has_no_errors() {
		$schema = new SchemaValGoodSchema();

		$this->assertSame( array(), $schema->get_validation_errors() );
		$this->assertTrue( $schema->is_valid() );
	}

	/**
	 * Duplicate accessor names are reported.
	 *
	 * @since 3.1.0
	 */
	public function test_duplicate_accessor_names_reported() {
		$schema = new SchemaValDuplicateSchema();

		$this->assertStringContainsString( 'Duplicate relationship name found: dup', implode( ' ', $schema->get_validation_errors() ) );
		$this->assertFalse( $schema->is_valid() );
	}

	/**
	 * A named-but-missing remote query class is reported.
	 *
	 * @since 3.1.0
	 */
	public function test_missing_remote_class_reported() {
		$schema = new SchemaValMissingClassSchema();

		$this->assertStringContainsString( 'missing remote query class', implode( ' ', $schema->get_validation_errors() ) );
	}

	/**
	 * Composite (multi-local-column) relationships are supported: an otherwise-valid
	 * composite declaration produces no validation error.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_relationship_is_supported() {
		$schema = new SchemaValCompositeSchema();

		$errors = implode( ' ', $schema->get_validation_errors() );

		$this->assertStringNotContainsString( 'is composite', $errors );
		$this->assertStringNotContainsString( 'mismatched', $errors );
	}

	/**
	 * A local column not present in the schema is reported.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_local_column_reported() {
		$schema = new SchemaValMissingLocalSchema();

		$this->assertStringContainsString( 'unknown local column ghost', implode( ' ', $schema->get_validation_errors() ) );
	}

	/**
	 * The relationship's own shape errors are merged into the schema's errors.
	 *
	 * @since 3.1.0
	 */
	public function test_self_shape_errors_merged() {
		$schema = new SchemaValSelfShapeSchema();

		$this->assertStringContainsString( 'missing a remote query class', implode( ' ', $schema->get_validation_errors() ) );
	}

	/**
	 * A relationship shorthand the Column sanitizer dropped for an unknown type is
	 * surfaced in the schema's validation errors, not silently swallowed (#206).
	 *
	 * @since 3.1.0
	 */
	public function test_dropped_invalid_type_declaration_is_surfaced() {
		$schema = new SchemaValDroppedTypeSchema();
		$errors = implode( ' ', $schema->get_validation_errors() );

		$this->assertStringContainsString( 'Column remote_id', $errors );
		$this->assertStringContainsString( 'unknown "type"', $errors );
		$this->assertFalse( $schema->is_valid() );
	}

	/**
	 * A relationship shorthand dropped for a missing required key is surfaced too.
	 *
	 * @since 3.1.0
	 */
	public function test_dropped_missing_key_declaration_is_surfaced() {
		$schema = new SchemaValDroppedMissingKeySchema();
		$errors = implode( ' ', $schema->get_validation_errors() );

		$this->assertStringContainsString( 'Column remote_id', $errors );
		$this->assertStringContainsString( 'missing a required', $errors );
		$this->assertFalse( $schema->is_valid() );
	}
}
