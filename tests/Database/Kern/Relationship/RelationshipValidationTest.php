<?php
/**
 * Relationship self-shape validation tests (#206).
 *
 * Relationship::get_validation_errors() reports only what the value object can
 * see in isolation - no owning Schema, no remote resolution. Local-column and
 * remote checks live in Schema/Query and are tested there.
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
 * Tests for Relationship::get_validation_errors().
 *
 * @since 3.1.0
 */
class RelationshipValidationTest extends TestCase {

	/**
	 * A fully-formed relationship reports no shape errors.
	 *
	 * @since 3.1.0
	 */
	public function test_valid_relationship_has_no_errors() {
		$relationship = new Relationship(
			array(
				'type'       => 'belongs_to',
				'columns'    => array( 'order_id' ),
				'query'      => 'BerlinDB\\Tests\\SomeRemoteQuery',
				'references' => array( 'id' ),
			)
		);

		$this->assertSame( array(), $relationship->get_validation_errors() );
	}

	/**
	 * A relationship with no local columns is flagged.
	 *
	 * @since 3.1.0
	 */
	public function test_missing_local_columns_is_flagged() {
		$relationship = new Relationship(
			array(
				'query'      => 'BerlinDB\\Tests\\SomeRemoteQuery',
				'references' => array( 'id' ),
			)
		);

		$errors = $relationship->get_validation_errors();

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'declares no local columns', implode( ' ', $errors ) );
	}

	/**
	 * A relationship missing its remote query class is flagged.
	 *
	 * @since 3.1.0
	 */
	public function test_missing_remote_query_class_is_flagged() {
		$relationship = new Relationship(
			array(
				'columns'    => array( 'order_id' ),
				'references' => array( 'id' ),
			)
		);

		$errors = $relationship->get_validation_errors();

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'missing a remote query class', implode( ' ', $errors ) );
	}

	/**
	 * A relationship with no remote columns (references) is flagged.
	 *
	 * @since 3.1.0
	 */
	public function test_missing_remote_columns_is_flagged() {
		$relationship = new Relationship(
			array(
				'columns' => array( 'order_id' ),
				'query'   => 'BerlinDB\\Tests\\SomeRemoteQuery',
			)
		);

		$errors = $relationship->get_validation_errors();

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'declares no remote columns', implode( ' ', $errors ) );
	}

	/**
	 * Mismatched local/remote column counts (composite pairing) are flagged.
	 *
	 * @since 3.1.0
	 */
	public function test_column_count_mismatch_is_flagged() {
		$relationship = new Relationship(
			array(
				'columns'    => array( 'a', 'b' ),
				'query'      => 'BerlinDB\\Tests\\SomeRemoteQuery',
				'references' => array( 'id' ),
			)
		);

		$errors = $relationship->get_validation_errors();

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'mismatched local and remote column counts', implode( ' ', $errors ) );
	}

	/**
	 * A fully-formed many_to_many reports no shape errors.
	 *
	 * @since 3.1.0
	 */
	public function test_valid_many_to_many_has_no_errors() {
		$relationship = new Relationship(
			array(
				'columns'            => array( 'id' ),
				'query'              => 'BerlinDB\\Tests\\TargetQuery',
				'references'         => array( 'id' ),
				'through'            => 'BerlinDB\\Tests\\PivotQuery',
				'through_columns'    => array( 'post_id' ),
				'through_references' => array( 'tag_id' ),
			)
		);

		$this->assertSame( 'many_to_many', $relationship->type );
		$this->assertSame( array(), $relationship->get_validation_errors() );
	}

	/**
	 * A many_to_many with asymmetric hop arities is valid: columns pair with
	 * through_columns and through_references pair with references, so a composite
	 * local key to a single-column target (columns != references count) is fine.
	 *
	 * @since 3.1.0
	 */
	public function test_many_to_many_asymmetric_hop_arities_are_valid() {
		$relationship = new Relationship(
			array(
				'columns'            => array( 'region_id', 'account_id' ), // composite local
				'query'              => 'BerlinDB\\Tests\\TargetQuery',
				'references'         => array( 'id' ),                       // single-column target
				'through'            => 'BerlinDB\\Tests\\PivotQuery',
				'through_columns'    => array( 'region_id', 'account_id' ),  // pairs with columns
				'through_references' => array( 'tag_id' ),                   // pairs with references
			)
		);

		$this->assertSame( array(), $relationship->get_validation_errors() );
	}

	/**
	 * A many_to_many missing its pivot (through) query class is flagged.
	 *
	 * @since 3.1.0
	 */
	public function test_many_to_many_missing_through_is_flagged() {
		$relationship = new Relationship(
			array(
				'type'               => 'many_to_many',
				'columns'            => array( 'id' ),
				'query'              => 'BerlinDB\\Tests\\TargetQuery',
				'references'         => array( 'id' ),
				'through_columns'    => array( 'post_id' ),
				'through_references' => array( 'tag_id' ),
			)
		);

		$errors = $relationship->get_validation_errors();

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'missing a pivot (through) query class', implode( ' ', $errors ) );
	}

	/**
	 * A many_to_many missing its through_columns / through_references is flagged.
	 *
	 * @since 3.1.0
	 */
	public function test_many_to_many_missing_pivot_columns_is_flagged() {
		$relationship = new Relationship(
			array(
				'columns'    => array( 'id' ),
				'query'      => 'BerlinDB\\Tests\\TargetQuery',
				'references' => array( 'id' ),
				'through'    => 'BerlinDB\\Tests\\PivotQuery',
			)
		);

		$errors = implode( ' ', $relationship->get_validation_errors() );

		$this->assertStringContainsString( 'declares no through_columns', $errors );
		$this->assertStringContainsString( 'declares no through_references', $errors );
	}

	/**
	 * A many_to_many hop-1 arity mismatch (columns vs through_columns) is flagged.
	 *
	 * @since 3.1.0
	 */
	public function test_many_to_many_hop1_arity_mismatch_is_flagged() {
		$relationship = new Relationship(
			array(
				'columns'            => array( 'a', 'b' ),
				'query'              => 'BerlinDB\\Tests\\TargetQuery',
				'references'         => array( 'a', 'b' ),
				'through'            => 'BerlinDB\\Tests\\PivotQuery',
				'through_columns'    => array( 'x' ),
				'through_references' => array( 'x', 'y' ),
			)
		);

		$this->assertStringContainsString(
			'mismatched local and through_columns counts',
			implode( ' ', $relationship->get_validation_errors() )
		);
	}

	/**
	 * A many_to_many hop-2 arity mismatch (through_references vs references) is flagged.
	 *
	 * @since 3.1.0
	 */
	public function test_many_to_many_hop2_arity_mismatch_is_flagged() {
		$relationship = new Relationship(
			array(
				'columns'            => array( 'id' ),
				'query'              => 'BerlinDB\\Tests\\TargetQuery',
				'references'         => array( 'id' ),
				'through'            => 'BerlinDB\\Tests\\PivotQuery',
				'through_columns'    => array( 'post_id' ),
				'through_references' => array( 'a', 'b' ),
			)
		);

		$this->assertStringContainsString(
			'mismatched through_references and remote column counts',
			implode( ' ', $relationship->get_validation_errors() )
		);
	}
}
