<?php
/**
 * Editor-only stubs for the WordPress test-case class chain.
 *
 * This file exists solely so Intelephense (and other static editors) can
 * resolve the inheritance used by the BerlinDB integration tests:
 *
 *   Yoast\WPTestUtils\WPIntegration\TestCase
 *     → WP_UnitTestCase
 *       → WP_UnitTestCase_Base
 *         → PHPUnit\Framework\TestCase
 *
 * Without it, every inherited assert*()/expect*() call in the ~40 integration
 * test files is flagged as an "undefined method", because the real
 * WP_UnitTestCase classes only exist inside the WordPress test suite that is
 * downloaded into Docker at run time (see tests/bootstrap.php and
 * bin/install-wp-tests.sh) — they are never present in the repository.
 *
 * This file is NOT autoloaded, NOT executed by PHPUnit, and NOT analyzed by
 * PHPStan (paths: src/) or PHPCS (file: src/, tests/). It is excluded from the
 * distributed package via .gitattributes. The class_exists() guards are a
 * belt-and-suspenders safeguard in case it is ever loaded alongside the real
 * WordPress test classes.
 *
 * @package BerlinDB\Tests
 * @since   3.1.0
 */

if ( ! class_exists( 'WP_UnitTestCase_Base' ) ) {

	/**
	 * Stub mirroring WordPress core's WP_UnitTestCase_Base.
	 */
	abstract class WP_UnitTestCase_Base extends \PHPUnit\Framework\TestCase {}
}

if ( ! class_exists( 'WP_UnitTestCase' ) ) {

	/**
	 * Stub mirroring WordPress core's WP_UnitTestCase.
	 */
	abstract class WP_UnitTestCase extends WP_UnitTestCase_Base {}
}
