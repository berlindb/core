<?php
/**
 * Autoloader for BerlinDB.
 *
 * @link https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader-examples.md#class-example
 */

spl_autoload_register(
	/**
	 * Closure of the autoloader.
	 *
	 * @param class-string $class_name The fully-qualified class name.
	 * @return void
	 */
	static function ( $class_name ) {
		$project_namespace = 'BerlinDB\\Database\\';
		$length            = strlen( $project_namespace );

		// Class is not in our namespace.
		if ( 0 !== strncmp( $project_namespace, $class_name, $length ) ) {
			return;
		}

		$file = sprintf(
			'%1$s/src/%2$s.php',
			__DIR__,
			str_replace( '\\', '/', substr( $class_name, $length ) )
		);

		if ( ! is_file( $file ) ) {
			return;
		}

		require $file;
	}
);
