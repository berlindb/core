<?php
/**
 * Index::safe_prefix_chars() tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Index;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Index::safe_prefix_chars() - the safe index prefix length calc (#222).
 *
 * Turns a storage-engine byte ceiling into a character-count prefix for a column's
 * charset: floor(engine_bytes / bytes_per_char). Named profiles (INNODB_LEGACY 767,
 * INNODB_MODERN 3072, MYISAM 1000) and charset widths (utf8mb4 4, utf8 3, latin1 1)
 * are looked up; unknowns fall back to the conservative legacy floor / 4 bytes, so
 * the default is WordPress's utf8mb4-on-legacy-InnoDB 191.
 *
 * @since 3.1.0
 */
class IndexPrefixLengthTest extends TestCase {

	/**
	 * The default (legacy InnoDB + utf8mb4) is WordPress's conservative 191.
	 *
	 * @since 3.1.0
	 */
	public function test_default_is_conservative_191() {
		$this->assertSame( 191, Index::safe_prefix_chars() );
	}

	/**
	 * Each engine profile applies its byte ceiling for utf8mb4 (4 bytes/char).
	 *
	 * @since 3.1.0
	 */
	public function test_engine_profiles_for_utf8mb4() {
		$this->assertSame( 191, Index::safe_prefix_chars( 'INNODB_LEGACY', 'utf8mb4' ) );
		$this->assertSame( 768, Index::safe_prefix_chars( 'INNODB_MODERN', 'utf8mb4' ) );
		$this->assertSame( 250, Index::safe_prefix_chars( 'MYISAM', 'utf8mb4' ) );
	}

	/**
	 * Each charset width divides the byte ceiling (shown on legacy InnoDB, 767 bytes).
	 *
	 * @since 3.1.0
	 */
	public function test_charset_widths_on_legacy_innodb() {
		$this->assertSame( 191, Index::safe_prefix_chars( 'INNODB_LEGACY', 'utf8mb4' ) );
		$this->assertSame( 255, Index::safe_prefix_chars( 'INNODB_LEGACY', 'utf8' ) );
		$this->assertSame( 255, Index::safe_prefix_chars( 'INNODB_LEGACY', 'utf8mb3' ) );
		$this->assertSame( 767, Index::safe_prefix_chars( 'INNODB_LEGACY', 'latin1' ) );
		$this->assertSame( 767, Index::safe_prefix_chars( 'INNODB_LEGACY', 'ascii' ) );
	}

	/**
	 * The result floors (integer division) rather than rounding.
	 *
	 * @since 3.1.0
	 */
	public function test_result_floors() {
		// 1000 / 3 = 333.33 -> 333.
		$this->assertSame( 333, Index::safe_prefix_chars( 'MYISAM', 'utf8' ) );
		// 767 / 3 = 255.66 -> 255.
		$this->assertSame( 255, Index::safe_prefix_chars( 'INNODB_LEGACY', 'utf8' ) );
	}

	/**
	 * An unknown engine profile falls back to the legacy 767-byte floor.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_profile_falls_back_to_legacy() {
		$this->assertSame(
			Index::safe_prefix_chars( 'INNODB_LEGACY', 'utf8mb4' ),
			Index::safe_prefix_chars( 'NOPE', 'utf8mb4' )
		);
	}

	/**
	 * An unknown charset assumes 4 bytes/char (the utf8mb4 worst case).
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_charset_assumes_four_bytes() {
		$this->assertSame(
			Index::safe_prefix_chars( 'INNODB_LEGACY', 'utf8mb4' ),
			Index::safe_prefix_chars( 'INNODB_LEGACY', 'made_up' )
		);
	}

	/**
	 * Profile and charset lookups are case-insensitive.
	 *
	 * @since 3.1.0
	 */
	public function test_lookups_are_case_insensitive() {
		$this->assertSame( 768, Index::safe_prefix_chars( 'innodb_modern', 'UTF8MB4' ) );
	}
}
