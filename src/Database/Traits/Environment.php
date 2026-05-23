<?php
/**
 * Database Environment Trait.
 *
 * @package     Database
 * @subpackage  Environment
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for resolving the current application environment.
 *
 * @since 3.0.0
 */
trait Environment {

	/**
	 * The name of the PHP global that contains the primary database interface.
	 *
	 * For example, WordPress uses 'wpdb', but other applications will use
	 * something else, or you may be doing something really cool that
	 * requires a custom interface.
	 *
	 * A future version of BerlinDB will abstract this to a new class, so
	 * custom calls to the get_db() method in your own code should be avoided.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $db_global = 'wpdb';

	/**
	 * Return the global database interface.
	 *
	 * @since 3.0.0
	 *
	 * @return \wpdb|false Database interface, or False if not set.
	 */
	protected function get_db() {
		global ${$this->db_global};

		// Default return value.
		$retval = false;

		// Look for the global database interface.
		if ( ! is_null( ${$this->db_global} ) ) {
			$retval = ${$this->db_global};
		}

		/*
		 * Note: If you are here because this method is returning false for you,
		 * that means a database Table or Query are being invoked too early in
		 * the lifecycle of the application.
		 *
		 * In WordPress, that means before require_wp_db() creates the $wpdb
		 * global (inside of the wp-settings.php file) and you may want to
		 * hook your custom code into 'admin_init' or 'plugins_loaded' instead.
		 *
		 * The decision to return false here is likely to change in the future.
		 */

		// Return the database interface.
		return $retval;
	}

	/**
	 * Check if the current request is from some kind of test.
	 *
	 * This is primarily used to skip 'admin_init' and force-install tables.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	protected function is_testing() {
		return (bool) (

			// Tests constant is being used.
			( defined( 'WP_TESTS_DIR' ) && WP_TESTS_DIR )

			||

			// Scaffolded (https://make.wordpress.org/cli/handbook/plugin-unit-tests/).
			function_exists( '_manually_load_plugin' )
		);
	}
}
