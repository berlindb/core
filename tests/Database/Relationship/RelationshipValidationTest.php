<?php
/**
 * Relationship self-shape validation tests (#206).
 *
 * Relationship::get_validation_errors() reports only what the value object can
 * see in isolation — no owning Schema, no remote resolution. Local-column and
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
	 * A bound relationship is exempt from the missing-remote-query-class check.
	 *
	 * @since 3.1.0
	 */
	public function test_bound_relationship_exempt_from_query_check() {
		$relationship = new Relationship(
			array(
				'name'       => 'meta',
				'type'       => 'has_many',
				'columns'    => array( 'id' ),
				'references' => array( 'object_id' ),
				'bound'      => true,
			)
		);

		$this->assertTrue( $relationship->is_bound() );
		$this->assertStringNotContainsString(
			'missing a remote query class',
			implode( ' ', $relationship->get_validation_errors() )
		);
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
}
