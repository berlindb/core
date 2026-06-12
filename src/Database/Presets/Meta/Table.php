<?php
/**
 * Meta preset: base Table for a key/value meta sibling table.
 *
 * @package     Database
 * @subpackage  Presets
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Presets\Meta;

use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table as KernTable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base Table for a WordPress-style key/value meta sibling table (#204 Phase A).
 *
 * Extend it with a one-line stub that names its meta Query stub:
 *
 *     class Order_Meta_Table extends \BerlinDB\Database\Presets\Meta\Table {
 *         protected $query = Order_Meta::class;
 *     }
 *
 * The base derives its name ({object}_meta), plugin prefix, and schema from that
 * meta Query — the exact same generated Schema instance the query runs against —
 * then behaves like any other Table: it registers itself with the database
 * interface and installs/upgrades through the normal Table lifecycle. A plugin
 * instantiates it alongside its other tables.
 *
 * @since 3.1.0
 */
class Table extends KernTable {

	/**
	 * FQCN of this table's meta Query class (a Presets\Meta\Query stub).
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	protected $query = '';

	/**
	 * Table version.
	 *
	 * Versions the preset's generated meta schema, not the plugin. Override in a
	 * stub when customizing build_schema(), so upgrades can be detected.
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	protected $version = '1.0.0';

	/**
	 * Derive identity and schema from the meta Query before normal setup.
	 *
	 * @since 3.1.0
	 */
	protected function init(): void {
		$this->configure_from_query();

		parent::init();
	}

	/**
	 * Configure this table's identity and schema from its meta Query.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	private function configure_from_query(): void {

		// Bail (loudly) unless a meta Query class is named and exists.
		if ( ( '' === $this->query ) || ! class_exists( $this->query ) ) {
			$this->log(
				'warning',
				'meta_table_query_missing',
				'Meta table names no usable meta Query class; not configured.',
				array( 'query' => $this->query )
			);

			return;
		}

		// Bail (loudly) unless the class is a meta Query.
		$meta = new $this->query();
		if ( ! ( $meta instanceof Query ) ) {
			$this->log(
				'warning',
				'meta_table_not_meta_query',
				'Meta table query class is not a Presets\Meta\Query; not configured.',
				array( 'query' => $this->query )
			);

			return;
		}

		// Bail (loudly) when the meta Query itself failed to configure.
		if ( ! $meta->is_configured_from_primary() ) {
			$this->log(
				'warning',
				'meta_table_query_misconfigured',
				'Meta table query did not configure from its primary; not configured.',
				array( 'query' => $this->query )
			);

			return;
		}

		// Bail (loudly) without the generated Schema instance the query uses.
		$schema = $meta->get_schema();
		if ( ! ( $schema instanceof Schema ) ) {
			$this->log(
				'warning',
				'meta_table_schema_missing',
				'Meta table query carries no generated schema; not configured.',
				array( 'query' => $this->query )
			);

			return;
		}

		// Same identity and the same generated Schema instance the query uses.
		$this->name   = $meta->get_item_name();
		$this->prefix = $meta->get_prefix();
		$this->schema = $schema;
	}
}
