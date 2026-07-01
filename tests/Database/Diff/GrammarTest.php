<?php
/**
 * SQL grammar (schema-change rendering) tests.
 *
 * The Grammar is the single place ALTER syntax is built - Operations carry intent,
 * the Grammar renders it. Pure string rendering, no database.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Diff\Grammar;
use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Index;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Diff Grammar.
 *
 * @since 3.1.0
 */
class GrammarTest extends TestCase {

	/**
	 * The grammar under test.
	 *
	 * @since 3.1.0
	 * @var Grammar
	 */
	private $grammar;

	/**
	 * @since 3.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->grammar = new Grammar();
	}

	/**
	 * A column with a name and type.
	 *
	 * @since 3.1.0
	 *
	 * @return Column
	 */
	private function column(): Column {
		return new Column(
			array(
				'name'   => 'title',
				'type'   => 'varchar',
				'length' => '191',
			)
		);
	}

	/**
	 * add_column() wraps the column body in ADD COLUMN.
	 *
	 * @since 3.1.0
	 */
	public function test_add_column() {
		$sql = $this->grammar->add_column( 'wp_things', $this->column() );

		$this->assertStringStartsWith( 'ALTER TABLE wp_things ADD COLUMN ', $sql );
		$this->assertStringContainsString( '`title`', $sql );
	}

	/**
	 * modify_column() wraps the column body in MODIFY COLUMN.
	 *
	 * @since 3.1.0
	 */
	public function test_modify_column() {
		$sql = $this->grammar->modify_column( 'wp_things', $this->column() );

		$this->assertStringStartsWith( 'ALTER TABLE wp_things MODIFY COLUMN ', $sql );
		$this->assertStringContainsString( '`title`', $sql );
	}

	/**
	 * drop_column() back-ticks the name.
	 *
	 * @since 3.1.0
	 */
	public function test_drop_column() {
		$this->assertSame(
			'ALTER TABLE wp_things DROP COLUMN `title`',
			$this->grammar->drop_column( 'wp_things', 'title' )
		);
	}

	/**
	 * add_index() wraps the index body in ADD.
	 *
	 * @since 3.1.0
	 */
	public function test_add_index() {
		$index = new Index(
			array(
				'name'    => 'title',
				'columns' => array( 'title' ),
			)
		);

		$sql = $this->grammar->add_index( 'wp_things', $index );

		$this->assertStringStartsWith( 'ALTER TABLE wp_things ADD ', $sql );
		$this->assertStringContainsString( '`title`', $sql );
	}

	/**
	 * drop_index() back-ticks a normal index name.
	 *
	 * @since 3.1.0
	 */
	public function test_drop_index() {
		$this->assertSame(
			'ALTER TABLE wp_things DROP INDEX `status`',
			$this->grammar->drop_index( 'wp_things', 'status' )
		);
	}

	/**
	 * drop_index() renders DROP PRIMARY KEY for the primary key (any case).
	 *
	 * @since 3.1.0
	 */
	public function test_drop_index_primary() {
		$this->assertSame(
			'ALTER TABLE wp_things DROP PRIMARY KEY',
			$this->grammar->drop_index( 'wp_things', 'PRIMARY' )
		);
		$this->assertSame(
			'ALTER TABLE wp_things DROP PRIMARY KEY',
			$this->grammar->drop_index( 'wp_things', 'primary' )
		);
	}

	/**
	 * replace_index() renders one combined DROP-then-ADD for the primary key.
	 *
	 * @since 3.1.0
	 */
	public function test_replace_index_primary() {
		$from = new Index(
			array(
				'type'    => 'primary',
				'columns' => array( 'id' ),
			)
		);
		$to   = new Index(
			array(
				'type'    => 'primary',
				'columns' => array( 'id', 'status' ),
			)
		);

		$sql = $this->grammar->replace_index( 'wp_things', $from, $to );

		$this->assertStringStartsWith( 'ALTER TABLE wp_things DROP PRIMARY KEY, ADD PRIMARY KEY', $sql );
		$this->assertStringContainsString( '`status`', $sql );
	}

	/**
	 * replace_index() renders DROP INDEX then ADD for a non-primary index.
	 *
	 * @since 3.1.0
	 */
	public function test_replace_index_secondary() {
		$from = new Index(
			array(
				'name'    => 'sl',
				'columns' => array( 'slug' ),
			)
		);
		$to   = new Index(
			array(
				'name'    => 'sl',
				'columns' => array( 'slug', 'status' ),
			)
		);

		$sql = $this->grammar->replace_index( 'wp_things', $from, $to );

		$this->assertStringStartsWith( 'ALTER TABLE wp_things DROP INDEX `sl`, ADD ', $sql );
		$this->assertStringContainsString( '`status`', $sql );
	}

	/**
	 * add_foreign_key() wraps an already-rendered FK fragment in ALTER TABLE ADD.
	 *
	 * @since 3.1.0
	 */
	public function test_add_foreign_key() {
		$fragment = 'FOREIGN KEY (`widget_id`) REFERENCES `wp_widgets` (`id`)';

		$this->assertSame(
			"ALTER TABLE wp_things ADD {$fragment}",
			$this->grammar->add_foreign_key( 'wp_things', $fragment )
		);
	}

	/**
	 * Empty names / fragments render nothing (no malformed statement).
	 *
	 * @since 3.1.0
	 */
	public function test_empty_names_render_nothing() {
		$this->assertSame( '', $this->grammar->drop_column( 'wp_things', '' ) );
		$this->assertSame( '', $this->grammar->drop_index( 'wp_things', '' ) );
		$this->assertSame( '', $this->grammar->add_foreign_key( 'wp_things', '' ) );
	}
}
