<?php
/**
 * Clause Builder.
 *
 * @package     Database
 * @subpackage  Clauses
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Clauses;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Assembles per-parser JOIN/WHERE fragments into the final clause lists.
 *
 * The reusable "fragments -> { join, where }" engine. A Query runs its parsers and
 * hands their per-parser fragments here; the builder assembles them - JOINs via
 * Clauses\Join, WHEREs via Clauses\Where applying the optional 'criteria' boolean
 * tree - and exposes the clause lists plus any misconfiguration warnings.
 *
 * It is deliberately INERT: assembling builds SQL but never executes a statement.
 * That is what lets a future write operation (delete_by_where / update_by_where)
 * build the same JOIN/WHERE without triggering a SELECT - it builds, then renders
 * its own verb. SELECT is just the first consumer.
 *
 * Running the parsers themselves stays on Query (it owns the schema, the parser
 * registry, and the container-var isolation); this builder is the construction
 * step AFTER the parsers have produced their fragments. See
 * skills/berlindb/references/architecture.md for the directive taxonomy.
 *
 * @since 3.1.0
 * @internal Query collaborator; built by Query (and, later, by Operations).
 */
class Builder {

	/**
	 * The 'criteria' boolean tree (or empty/null for the default cross-parser AND).
	 *
	 * @since 3.1.0
	 * @var mixed
	 */
	private $criteria = null;

	/**
	 * Per-parser JOIN fragments, keyed by parser name.
	 *
	 * @since 3.1.0
	 * @var array<string,string>
	 */
	private $join = array();

	/**
	 * Per-parser WHERE fragments, keyed by parser name.
	 *
	 * @since 3.1.0
	 * @var array<string,string>
	 */
	private $where = array();

	/**
	 * Valid parser names a criteria leaf may reference.
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private $parsers = array();

	/**
	 * Whether build() has run.
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	private $built = false;

	/**
	 * Assembled JOIN clause list (populated by build()).
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private $join_clauses = array();

	/**
	 * Assembled WHERE clause list (populated by build()).
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private $where_clauses = array();

	/**
	 * Misconfiguration warnings recorded while building (populated by build()).
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private $warnings = array();

	/**
	 * Build the assembler from a key-value argument array.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args See init().
	 */
	public function __construct( array $args = array() ) {
		if ( ! empty( $args ) ) {
			$this->init( $args );
		}
	}

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type mixed                $criteria The 'criteria' boolean tree (or empty for none).
	 *     @type array<string,string> $join       Per-parser JOIN fragments, keyed by parser name.
	 *     @type array<string,string> $where      Per-parser WHERE fragments, keyed by parser name.
	 *     @type list<string>         $parsers    Valid parser names a criteria leaf may reference.
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$this->criteria = $args[ 'criteria' ] ?? null;
		$this->join     = $this->string_map( $args[ 'join' ] ?? array() );
		$this->where    = $this->string_map( $args[ 'where' ] ?? array() );
		$this->parsers  = $this->string_list( $args[ 'parsers' ] ?? array() );
	}

	/**
	 * The assembled JOIN clause list.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	public function get_join_clauses(): array {
		$this->build();

		return $this->join_clauses;
	}

	/**
	 * The assembled WHERE clause list (the 'criteria' tree applied, fail-closed).
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	public function get_where_clauses(): array {
		$this->build();

		return $this->where_clauses;
	}

	/**
	 * Misconfiguration warnings recorded while building, for the caller to log.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	public function get_warnings(): array {
		$this->build();

		return $this->warnings;
	}

	/**
	 * Assemble the fragments once (inert: builds SQL, never executes).
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	private function build(): void {
		if ( $this->built ) {
			return;
		}

		$this->built = true;

		// JOINs flatten (no boolean tree).
		$this->join_clauses = ( new Join(
			array(
				'join' => $this->join,
			)
		) )->get_clauses();

		// WHEREs combine per the criteria tree, surfacing any misconfiguration.
		$where = new Where(
			array(
				'tree'    => $this->criteria,
				'join'    => $this->join,
				'where'   => $this->where,
				'parsers' => $this->parsers,
			)
		);

		$this->where_clauses = $where->get_clauses();
		$this->warnings      = $where->get_warnings();
	}

	/**
	 * Keep only string => string pairs from a mixed value.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value Candidate map.
	 * @return array<string,string>
	 */
	private function string_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$retval = array();

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && is_string( $item ) ) {
				$retval[ $key ] = $item;
			}
		}

		return $retval;
	}

	/**
	 * Keep only string values from a mixed value, as a list.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value Candidate list.
	 * @return list<string>
	 */
	private function string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$retval = array();

		foreach ( $value as $item ) {
			if ( is_string( $item ) ) {
				$retval[] = $item;
			}
		}

		return $retval;
	}
}
