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

		$legacy_operator_classes = array(
			'BerlinDB\\Database\\Operators\\Base'               => 'BerlinDB\\Database\\Operators\\Comparisons\\Base',
			'BerlinDB\\Database\\Operators\\Between'            => 'BerlinDB\\Database\\Operators\\Comparisons\\Between',
			'BerlinDB\\Database\\Operators\\Equal'              => 'BerlinDB\\Database\\Operators\\Comparisons\\Equal',
			'BerlinDB\\Database\\Operators\\Exists'             => 'BerlinDB\\Database\\Operators\\Comparisons\\Exists',
			'BerlinDB\\Database\\Operators\\GreaterThan'        => 'BerlinDB\\Database\\Operators\\Comparisons\\GreaterThan',
			'BerlinDB\\Database\\Operators\\GreaterThanOrEqual' => 'BerlinDB\\Database\\Operators\\Comparisons\\GreaterThanOrEqual',
			'BerlinDB\\Database\\Operators\\In'                 => 'BerlinDB\\Database\\Operators\\Comparisons\\In',
			'BerlinDB\\Database\\Operators\\LessThan'           => 'BerlinDB\\Database\\Operators\\Comparisons\\LessThan',
			'BerlinDB\\Database\\Operators\\LessThanOrEqual'    => 'BerlinDB\\Database\\Operators\\Comparisons\\LessThanOrEqual',
			'BerlinDB\\Database\\Operators\\Like'               => 'BerlinDB\\Database\\Operators\\Comparisons\\Like',
			'BerlinDB\\Database\\Operators\\NotBetween'         => 'BerlinDB\\Database\\Operators\\Comparisons\\NotBetween',
			'BerlinDB\\Database\\Operators\\NotEqual'           => 'BerlinDB\\Database\\Operators\\Comparisons\\NotEqual',
			'BerlinDB\\Database\\Operators\\NotExists'          => 'BerlinDB\\Database\\Operators\\Comparisons\\NotExists',
			'BerlinDB\\Database\\Operators\\NotIn'              => 'BerlinDB\\Database\\Operators\\Comparisons\\NotIn',
			'BerlinDB\\Database\\Operators\\NotLike'            => 'BerlinDB\\Database\\Operators\\Comparisons\\NotLike',
			'BerlinDB\\Database\\Operators\\NotRegexp'          => 'BerlinDB\\Database\\Operators\\Comparisons\\NotRegexp',
			'BerlinDB\\Database\\Operators\\Regexp'             => 'BerlinDB\\Database\\Operators\\Comparisons\\Regexp',
			'BerlinDB\\Database\\Operators\\Rlike'              => 'BerlinDB\\Database\\Operators\\Comparisons\\Rlike',
		);

		$legacy_classes = array_merge( $legacy_kern_classes, $legacy_operator_classes );

		if ( isset( $legacy_classes[ $class_name ] ) ) {
			$target = $legacy_classes[ $class_name ];
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
		$strip = str_replace( $root_namespace, '', $class_name );
		$name  = str_replace( '\\', DIRECTORY_SEPARATOR, $strip );

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

		/*
		 * Eagerly register this class's legacy alias, if it has one. PHP's
		 * instanceof operator does not trigger autoloading, so an `instanceof
		 * \BerlinDB\Database\Index` check would resolve to false until the alias
		 * name was loaded some other way. Creating the alias as soon as the Kern
		 * class loads keeps legacy type checks (instanceof / is_a) correct.
		 */
		$legacy_alias = array_search( $class_name, $legacy_classes, true );

		if ( ( false !== $legacy_alias ) && ! class_exists( $legacy_alias, false ) ) {
			class_alias( $class_name, $legacy_alias );
		}
	},
	true,
	true
);
