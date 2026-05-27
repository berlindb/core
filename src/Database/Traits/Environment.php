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
	 * Return the global database interface without requiring an instance.
	 *
	 * Used by static factory methods (e.g. Schema::from_table()) that need
	 * the database handle before an instance exists.
	 *
	 * Note: If this returns false, the database global is not yet available.
	 * In WordPress that means the call is too early (before require_wp_db()
	 * runs in wp-settings.php). Hook into 'plugins_loaded' or 'admin_init'.
	 *
	 * @since 3.0.0
	 *
	 * @param string $db_global Optional. Global variable name. Default 'wpdb'.
	 * @return \wpdb|false Database interface, or false if not set.
	 */
	protected static function get_db_global( string $db_global = 'wpdb' ): \wpdb|false {
		global ${$db_global};

		return is_null( ${$db_global} )
			? false
			: ${$db_global};
	}

	/**
	 * Return the global database interface.
	 *
	 * @since 3.0.0
	 *
	 * @return \wpdb|false Database interface, or False if not set.
	 */
	protected function get_db(): \wpdb|false {
		return static::get_db_global( $this->db_global );
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
