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
 * Pure filesystem reads - no database, no WordPress.
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
	 * `function test*()` DECLARATIONS - deterministic and runner-free - which is
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
	 * Canonical order (Boot): sunrise -> configure -> init -> consume_args -> sunset.
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

		/*
		 * Guard the guard: if the summaries move or get reworded away, fail loudly
		 * rather than passing vacuously.
		 */
		$this->assertGreaterThanOrEqual( count( $files ), $inspected, 'Expected at least one lifecycle summary per documented file; the guard may be looking in the wrong place.' );
	}

	/**
	 * Multi-line inline comments must use block syntax, not stacked `//`.
	 *
	 * Non-Negotiable #1 (CLAUDE.md): block comments for multi-line, `//` for
	 * single lines only (WordPress standard). Documentation alone kept failing to
	 * hold the line, so this turns the convention into a red test - two or more
	 * consecutive full-line `//` comments are flagged, with the offending
	 * locations named. Exempt: `phpcs:` directive lines, and divider banners
	 * (`// ----` / `// ====`), which break a run so section headers are not
	 * mistaken for prose. Covers src/ and tests/.
	 *
	 * @since 3.1.0
	 */
	public function test_multiline_comments_use_block_syntax() {
		$offenders = array();

		foreach ( $this->source_php_files() as $relative => $path ) {
			$lines = explode( "\n", (string) file_get_contents( $path ) );
			$run   = 0;

			foreach ( $lines as $index => $line ) {

				/*
				 * A full-line // comment, excluding phpcs: directives and divider
				 * banners (which reset the run rather than extend it).
				 */
				$is_prose = ( 1 === preg_match( '#^\s*//#', $line ) )
					&& ( false === strpos( $line, 'phpcs:' ) )
					&& ( 1 !== preg_match( '#^\s*//\s*[-=*]{3,}#', $line ) );

				if ( ! $is_prose ) {
					$run = 0;
					continue;
				}

				++$run;

				// A second consecutive // line is a multi-line comment in disguise.
				if ( 2 === $run ) {
					$offenders[] = "{$relative}:" . ( $index + 1 );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Multi-line inline comments must use /* ... */ block syntax, not stacked //.\nOffending comment starts:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * Multi-line double-star comments in src/ must be docblocks, not inline.
	 *
	 * Non-Negotiable #1: docblock syntax (slash-star-star) is for declarations;
	 * an inline multi-line comment uses slash-star. A double-star block NOT
	 * attached to a declaration is a misused docblock. Uses the PHP tokenizer so
	 * single-line double-star label markers (a deliberate convention) are exempt.
	 * Blocks carrying phpDoc @-tags are also exempt - those are docblocks,
	 * including WordPress hook docs that sit before apply_filters()/do_action()
	 * (phpstan-wordpress reads them). Only tag-less multi-line double-star blocks
	 * whose next significant token is not a declaration are flagged. Covers
	 * src/ and tests/.
	 *
	 * @since 3.1.0
	 */
	public function test_inline_block_comments_are_not_docblock_style() {

		// Tokens that may legitimately follow a docblock (a declaration).
		$declarations = array(
			\T_FUNCTION,
			\T_CLASS,
			\T_INTERFACE,
			\T_TRAIT,
			\T_ABSTRACT,
			\T_FINAL,
			\T_PUBLIC,
			\T_PROTECTED,
			\T_PRIVATE,
			\T_STATIC,
			\T_CONST,
			\T_VAR,
			\T_USE,
			\T_NAMESPACE,
			\T_DECLARE,
		);

		// Version-dependent tokens.
		foreach ( array( 'T_READONLY', 'T_ENUM', 'T_ATTRIBUTE' ) as $name ) {
			if ( defined( $name ) ) {
				$declarations[] = constant( $name );
			}
		}

		$declarations = array_flip( $declarations );
		$skip         = array( \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT );
		$offenders    = array();

		foreach ( $this->source_php_files() as $relative => $path ) {
			$tokens = token_get_all( (string) file_get_contents( $path ) );
			$count  = count( $tokens );

			foreach ( $tokens as $i => $token ) {

				// Only multi-line double-star blocks; single-line markers are allowed.
				if ( ! is_array( $token ) || ( \T_DOC_COMMENT !== $token[0] ) || ( false === strpos( $token[1], "\n" ) ) ) {
					continue;
				}

				// A phpDoc @-tag line means it is a docblock (incl. hook docs): exempt.
				if ( 1 === preg_match( '/\n\s*\*\s*@\w/', $token[1] ) ) {
					continue;
				}

				// Find the next significant token.
				$next = null;
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$candidate = $tokens[ $j ];
					if ( is_array( $candidate ) && in_array( $candidate[0], $skip, true ) ) {
						continue;
					}
					$next = $candidate;
					break;
				}

				// A docblock must precede a declaration (or a PHP 8 attribute).
				$is_docblock = ( is_array( $next ) && isset( $declarations[ $next[0] ] ) )
					|| ( is_string( $next ) && ( '#[' === $next ) );

				if ( ! $is_docblock ) {
					$offenders[] = "{$relative}:{$token[2]}";
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Multi-line `/**` comments that are not docblocks must use `/*` block syntax.\nOffending blocks:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * Docblocks and comments stay ASCII-only.
	 *
	 * Non-ASCII punctuation (em/en dashes, the rightwards arrow, the ellipsis
	 * glyph) had crept into comments across the tree; this keeps it from coming
	 * back. Use ASCII equivalents: `-` for a dash, `->` for "maps to", `...` for
	 * an ellipsis. The lone exception is BaseSanitizationTest, whose accented
	 * fixtures are the intentional input that proves sanitization strips them.
	 *
	 * @since 3.1.0
	 */
	public function test_source_and_tests_are_ascii_only() {

		// Files whose non-ASCII bytes are intentional test data, not prose.
		$allowed = array(
			'tests/Database/Traits/BaseSanitizationTest.php',
		);

		$offenders = array();

		foreach ( $this->source_php_files() as $rel => $path ) {

			// Skip files with intentional non-ASCII fixtures.
			if ( in_array( $rel, $allowed, true ) ) {
				continue;
			}

			$lines = explode( "\n", (string) file_get_contents( $path ) );

			foreach ( $lines as $index => $line ) {
				if ( preg_match( '/[^\x00-\x7F]/', $line ) ) {
					$offenders[] = "{$rel}:" . ( $index + 1 );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Non-ASCII characters in comments/docblocks; use ASCII (`-`, `->`, `...`).\nOffending lines:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * Every PHP file under src/ and tests/, keyed by repo-relative path.
	 *
	 * @return array<string,string> Repo-relative path => absolute path.
	 */
	private function source_php_files(): array {
		$root  = $this->root();
		$files = array();

		foreach ( array( 'src', 'tests' ) as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && ( 'php' === strtolower( $file->getExtension() ) ) ) {
					$files[ str_replace( $root . '/', '', $file->getPathname() ) ] = $file->getPathname();
				}
			}
		}

		return $files;
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
