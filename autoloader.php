<?php
/**
 * Autoloader.
 *
 * @package     Database
 * @copyright   Copyright (c) 2021
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Register a closure to autoload BerlinDB.
spl_autoload_register(

	/**
	 * Closure of the autoloader.
	 *
	 * @param string $class_name The fully-qualified class name.
	 * @return void
	 */
	static function ( $class_name = '' ) {

		// Project namespace & length.
		$project_namespace = 'BerlinDB\\Database\\';
		$length            = strlen( $project_namespace );

		// Bail if class is not in this namespace.
		if ( 0 !== strncmp( $project_namespace, $class_name, $length ) ) {
			return;
		}

		// Setup file parts.
		$format = '%1$s/src/%2$s.php';
		$path   = __DIR__;
		$name   = str_replace( '\\', '/', substr( $class_name, $length ) );

		// Parse class and namespace to file.
		$file   = sprintf( $format, $path, $name );

		// Bail if file does not exist.
		if ( ! is_file( $file ) ) {
			return;
		}

		// Require the file.
		require_once $file;
	}
);
