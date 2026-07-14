<?php
/**
 * Test Schema fixture for BerlinDB integration tests.
 *
 * @package     BerlinDB\Tests\Fixtures
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests\Fixtures;

use BerlinDB\Database\Kern\Schema;

/**
 * Minimal Schema fixture covering all common column flags.
 *
 * @since 2.1.0
 */
class TestSchema extends Schema {

	/**
	 * Column definitions.
	 *
	 * Declared public to allow direct access in tests.
	 *
	 * @since 2.1.0
	 * @var array
	 */
	public $columns = array(

		// Primary key.
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'default'   => false,
			'cache_key' => true,
			'sortable'  => true,
		),

		// Searchable, sortable varchar.
		array(
			'name'       => 'name',
			'type'       => 'varchar',
			'length'     => '200',
			'default'    => '',
			'searchable' => true,
			'sortable'   => true,
		),

		// Status with transition and dedicated cache key.
		array(
			'name'       => 'status',
			'type'       => 'varchar',
			'length'     => '20',
			'default'    => 'active',
			'cache_key'  => true,
			'transition' => true,
			'sortable'   => true,
			'in'         => true,
			'not_in'     => true,
		),

		// Integer with in/not_in and comparison support.
		array(
			'name'     => 'priority',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => '0',
			'sortable' => true,
			'in'       => true,
			'not_in'   => true,
			'compare'  => true,
		),

		// Auto-set creation timestamp.
		array(
			'name'       => 'date_created',
			'type'       => 'datetime',
			'default'    => '',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
		),

		// Auto-updated modification timestamp.
		array(
			'name'       => 'date_modified',
			'type'       => 'datetime',
			'default'    => '',
			'modified'   => true,
			'date_query' => true,
			'sortable'   => true,
		),

		// UUID column - exercises special_args() UUID branch.
		array(
			'uuid' => true,
		),
	);

	/**
	 * Index definitions.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		array(
			'name'    => 'status',
			'type'    => 'key',
			'columns' => array( 'status' ),
		),
	);
}
