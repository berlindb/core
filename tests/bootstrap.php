<?php
/**
 * PHPUnit bootstrap for BerlinDB Core integration tests.
 *
 * @package BerlinDB\Tests
 * @copyright   Copyright (c) 2026, JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests;

use Yoast\WPTestUtils\WPIntegration;

$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['SERVER_NAME']     = '';
$PHP_SELF                   = $GLOBALS['PHP_SELF'] = $_SERVER['PHP_SELF'] = '/index.php';

define( 'WP_USE_THEMES', false );

$library_dir = dirname( __DIR__ );

// Load Composer autoloader (BerlinDB classes + yoast packages).
require_once $library_dir . '/vendor/autoload.php';

// Load yoast WP test utils bootstrap helpers.
require_once $library_dir . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

// Resolve WP_TESTS_DIR (env var → constant → /tmp/wordpress-tests-lib).
$_tests_dir = WPIntegration\get_path_to_wp_test_dir();

if ( empty( $_tests_dir ) ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! defined( 'WP_TESTS_DIR' ) ) {
	define( 'WP_TESTS_DIR', $_tests_dir );
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find ' . $_tests_dir . '/includes/functions.php' . PHP_EOL;
	echo 'Set WP_TESTS_DIR to the WordPress test suite path.' . PHP_EOL;
	exit( 1 );
}

// Give access to tests_add_filter().
require_once $_tests_dir . '/includes/functions.php';

// BerlinDB is a library loaded via Composer — no plugin to activate.
// bootstrap_it() defines ABSPATH, which every src/ file guards against.
WPIntegration\bootstrap_it();

// Load test fixture classes after ABSPATH is defined.
require_once __DIR__ . '/Fixtures/TestSchema.php';
require_once __DIR__ . '/Fixtures/TestRow.php';
require_once __DIR__ . '/Fixtures/TestTable.php';
require_once __DIR__ . '/Fixtures/TestQuery.php';
