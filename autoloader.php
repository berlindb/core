<?php
/**
 * BerlinDB Autoloader.
 *
 * @package     Database
 * @subpackage  Autoloader
 * @copyright   2021-2022 - JJJ and all BerlinDB Contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.0.0
 */

/**
 * Register a closure to autoload BerlinDB classes.
 */
spl_autoload_register(

	/**
	 * Closure for the autoloader.
	 *
	 * @since 2.0.0
	 * @param string $class_name A fully-qualified class name.
	 * @return void
	 */
	static function ( $class_name = '' ) {

		$legacy_kern_classes = array(
			'BerlinDB\\Database\\Column' => 'BerlinDB\\Database\\Kern\\Column',
			'BerlinDB\\Database\\Index'  => 'BerlinDB\\Database\\Kern\\Index',
			'BerlinDB\\Database\\Query'  => 'BerlinDB\\Database\\Kern\\Query',
			'BerlinDB\\Database\\Row'    => 'BerlinDB\\Database\\Kern\\Row',
			'BerlinDB\\Database\\Schema' => 'BerlinDB\\Database\\Kern\\Schema',
			'BerlinDB\\Database\\Table'  => 'BerlinDB\\Database\\Kern\\Table',
		);

		if ( isset( $legacy_kern_classes[ $class_name ] ) ) {
			$target = $legacy_kern_classes[ $class_name ];
			$strip  = str_replace( 'BerlinDB\\', '', $target );
			$name   = str_replace( '\\', DIRECTORY_SEPARATOR, $strip );
			$file   = sprintf( '%1$s/src/%2$s.php', __DIR__, $name );

			if ( is_file( $file ) ) {
				require_once $file;

				if ( class_exists( $target, false ) && ! class_exists( $class_name, false ) ) {
					class_alias( $target, $class_name );
				}
			}

			return;
		}

		// Project namespace & length.
		$root_namespace    = 'BerlinDB\\';
		$project_namespace = $root_namespace . 'Database\\';
		$length            = strlen( $project_namespace );

		// Bail if class is not in this namespace.
		if ( 0 !== strncmp( $project_namespace, $class_name, $length ) ) {
			return;
		}

		// Setup file parts.
		$strip  = str_replace( $root_namespace, '', $class_name );
		$name   = str_replace( '\\', DIRECTORY_SEPARATOR, $strip );

		// Parse class and namespace to file.
		$format = '%1$s/src/%2$s.php';
		$path   = __DIR__;
		$file   = sprintf( $format, $path, $name );

		// Bail if file does not exist.
		if ( ! is_file( $file ) ) {
			return;
		}

		// Require the file.
		require_once $file;
	}
);
