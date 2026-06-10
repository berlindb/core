<?php
/**
 * Schema type/supports surface (#204 Phase A).
 *
 * A Schema declares its role (`$type`, default 'primary') and the features it opts
 * into (`$supports`, WP post-type-supports idiom). These are declaration-only; the
 * owning Query and the relevant Preset act on them where they have full context.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Schema;
use PHPUnit\Framework\TestCase;

/** A plain primary schema (defaults). */
class SupportsDefaultSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);
}

/** A schema opting into meta. */
class SupportsMetaSchema extends Schema {
	protected $supports = array( 'meta' );

	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);
}

/**
 * Tests for Schema::get_type()/get_supports()/supports().
 *
 * @since 3.1.0
 */
class SchemaSupportsTest extends TestCase {

	/**
	 * Type defaults to 'primary' and supports defaults to empty.
	 *
	 * @since 3.1.0
	 */
	public function test_defaults() {
		$schema = new SupportsDefaultSchema();

		$this->assertSame( 'primary', $schema->get_type() );
		$this->assertSame( array(), $schema->get_supports() );
		$this->assertFalse( $schema->supports( 'meta' ) );
	}

	/**
	 * A declared support is reported.
	 *
	 * @since 3.1.0
	 */
	public function test_declared_support() {
		$schema = new SupportsMetaSchema();

		$this->assertTrue( $schema->supports( 'meta' ) );
		$this->assertSame( array( 'meta' ), $schema->get_supports() );
		$this->assertFalse( $schema->supports( 'nope' ) );
	}

	/**
	 * type/supports are configurable via constructor args and sanitized.
	 *
	 * @since 3.1.0
	 */
	public function test_configured_and_sanitized() {
		$schema = new Schema(
			array(
				'type'     => 'meta',
				'supports' => array( 'meta', 'Bad Key!', 42 ),
			)
		);

		$this->assertSame( 'meta', $schema->get_type() );

		// 'Bad Key!' is sanitized to 'badkey'; the non-string 42 is dropped.
		$this->assertSame( array( 'meta', 'badkey' ), $schema->get_supports() );
	}
}
