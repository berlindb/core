<?php
/**
 * Documentation drift guards.
 *
 * These tests turn a recurring class of contract-vs-code drift into a red test
 * instead of silent rot: the test-suite size cited in CLAUDE.md, and the Boot
 * construction-lifecycle order wherever it is summarized in docs/source. When a
 * test is added or the lifecycle is reshaped, the corresponding doc must move
 * with it or CI fails (the failure message says exactly what to write).
 *
 * Pure filesystem reads — no database, no WordPress.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Guards against documentation drifting away from the code it describes.
 *
 * @since 3.1.0
 */
class DocDriftTest extends TestCase {

	/**
	 * Repository root (this file lives at <root>/tests/Database/DocDriftTest.php).
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * CLAUDE.md must cite the current test-method count ("<N> test methods").
	 *
	 * The "688" cited before this guard had drifted to ~900 unnoticed. This counts
	 * `function test*()` DECLARATIONS — deterministic and runner-free — which is
	 * deliberately NOT PHPUnit's reported total (data providers expand methods into
	 * more cases, so the runner reports a higher number). "test methods" is the
	 * honest phrasing for what this asserts.
	 *
	 * @since 3.1.0
	 */
	public function test_claude_md_cites_current_test_count() {
		$count    = $this->count_test_methods( $this->root() . '/tests' );
		$claudemd = (string) file_get_contents( $this->root() . '/CLAUDE.md' );

		$this->assertStringContainsString(
			"{$count} test methods",
			$claudemd,
			"CLAUDE.md cites a stale test-method count. Update the '<N> test methods' phrase to: {$count} test methods"
		);
	}

	/**
	 * Every doc/source line that summarizes the Boot lifecycle must list the hooks
	 * in canonical order and must not mention the removed setup() hook.
	 *
	 * Canonical order (Boot): sunrise → configure → init → consume_args → sunset.
	 *
	 * @since 3.1.0
	 */
	public function test_lifecycle_order_is_consistent_across_docs() {
		$canonical = array( 'sunrise', 'configure', 'init', 'consume_args', 'sunset' );

		$files = array(
			'CLAUDE.md',
			'src/Database/Traits/Boot.php',
			'src/Database/Traits/Lifecycle.php',
			'skills/berlindb/references/extending.md',
		);

		$inspected = 0;

		foreach ( $files as $rel ) {
			$path  = $this->root() . '/' . $rel;
			$lines = explode( "\n", (string) file_get_contents( $path ) );

			foreach ( $lines as $index => $line ) {

				// A lifecycle summary line bookends with both sunrise and sunset.
				if ( ( false === strpos( $line, 'sunrise' ) ) || ( false === strpos( $line, 'sunset' ) ) ) {
					continue;
				}

				// Pull the hook tokens in the order they appear on the line.
				preg_match_all( '/\b(sunrise|configure|setup|init|consume_args|sunset)\b/', $line, $matches );
				$sequence = $matches[1];

				// Fewer than four hook tokens is prose, not a pipeline summary; skip it.
				if ( count( $sequence ) < 4 ) {
					continue;
				}

				$where = "{$rel}:" . ( $index + 1 );

				// The setup() hook was collapsed into init() and must not reappear.
				$this->assertNotContains( 'setup', $sequence, "{$where} references the removed setup() lifecycle hook: {$line}" );

				// The remaining tokens must match the canonical order exactly.
				$this->assertSame( $canonical, $sequence, "{$where} lists the lifecycle out of canonical order: {$line}" );

				++$inspected;
			}
		}

		// Guard the guard: if the summaries move or get reworded away, fail loudly
		// rather than passing vacuously.
		$this->assertGreaterThanOrEqual( count( $files ), $inspected, 'Expected at least one lifecycle summary per documented file; the guard may be looking in the wrong place.' );
	}

	/**
	 * Count `function test*()` method declarations under a directory tree.
	 *
	 * @param string $dir Directory to scan.
	 * @return int
	 */
	private function count_test_methods( string $dir ): int {
		$count    = 0;
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {

			// Only PHP files.
			if ( ! $file->isFile() || ( 'php' !== strtolower( $file->getExtension() ) ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );
			$count   += preg_match_all( '/function\s+test\w*\s*\(/', $contents );
		}

		return $count;
	}
}
