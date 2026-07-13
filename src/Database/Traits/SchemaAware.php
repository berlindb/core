<?php
/**
 * Schema-resolution trait.
 *
 * @package     BerlinDB\Database\Traits
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

use BerlinDB\Database\Kern\Schema;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Resolving a declared schema (a class-string or a Schema instance) into the single
 * Schema object a relation or query holds.
 *
 * Composed by the three schema consumers - Table, Query, and View - which each
 * declare a schema source (Table/View: $schema; Query: $table_schema) and, during
 * init(), call resolve_schema() to instantiate and validate it once. The three used
 * to carry near-identical copies of this logic; the differences are exactly the
 * parameters: which source, whether the consumer requires the schema to expose a
 * given method (Table needs get_create_table_string(), Query needs get_columns(), a
 * View's reading schema needs only to be a Schema), and how loudly an unusable
 * schema logs (a View is still creatable without a reading schema, so it warns
 * rather than errors).
 *
 * Each consumer holds its OWN resolved instance - there is no shared/registry Schema
 * object, by design. Everything a Schema exposes is a static class-level
 * declaration, so separate instances of the same declared class always agree; there
 * is no runtime-mutable schema state to single-source. The one relation-level fact
 * that does want a single source of truth (read-only / is-a-view) is homed on the
 * Connection, alongside relation registration, not a global Schema holder (#238,
 * #239).
 *
 * @since 3.1.0
 */
trait SchemaAware {

	/**
	 * The resolved Schema object, populated by resolve_schema() during boot.
	 *
	 * Private so it is not touched directly until access can be vetted and opened up.
	 *
	 * @since 3.1.0
	 * @var   Schema|null|object
	 */
	private $schema_object = null;

	/**
	 * Resolve a declared schema source into $this->schema_object, logging on failure.
	 *
	 * A Schema instance is taken as-is; a non-empty class-string is instantiated. The
	 * result is usable when it exposes $required_method (Table/Query) or, when none is
	 * required, when it is a Schema (View). An unusable or unresolvable schema is
	 * logged at $severity under $log_code, with the offending source in the context.
	 *
	 * @since 3.1.0
	 *
	 * @param Schema|string|mixed $source          A Schema instance or a class-string.
	 * @param string|null         $required_method A method the schema must expose to be
	 *                                             usable by this consumer, or null to
	 *                                             require only that it is a Schema.
	 * @param string              $severity        Log level for an unresolved schema.
	 * @param string              $log_code        Log code for an unresolved schema.
	 * @param string              $message         Log message for an unresolved schema.
	 */
	protected function resolve_schema( $source, ?string $required_method, string $severity, string $log_code, string $message ): void {

		// Accept a Schema object passed directly via constructor or property assignment.
		if ( $source instanceof Schema ) {
			$this->schema_object = $source;
			return;
		}

		// Default log context (the source, whether a class-string or empty).
		$log_error = true;
		$context   = array(
			'schema' => is_string( $source )
				? $source
				: '',
		);

		// Maybe instantiate a schema class name (instances were returned above).
		if ( is_string( $source ) && ! empty( $source ) ) {
			try {
				$this->schema_object = $this->instantiate_class( $source );
				$log_error           = ( null === $this->schema_object );
			} catch ( \Throwable $exception ) {
				$context['exception']         = get_class( $exception );
				$context['exception_message'] = $exception->getMessage();
			}
		}

		/*
		 * Validate usability: by the method this consumer needs (Table/Query), or -
		 * when none is required - by being a Schema at all (View).
		 */
		if ( ( false === $log_error ) && ( null !== $required_method ) && ! is_callable( array( $this->schema_object, $required_method ) ) ) {
			$log_error         = true;
			$context['method'] = $required_method;
		} elseif ( ( false === $log_error ) && ( null === $required_method ) && ! ( $this->schema_object instanceof Schema ) ) {
			$log_error = true;
		}

		// Maybe log the schema setup failure.
		if ( true === $log_error ) {
			$this->log( $severity, $log_code, $message, $context );
		}
	}
}
