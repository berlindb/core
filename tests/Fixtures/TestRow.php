<?php
/**
 * Test Row fixture for BerlinDB integration tests.
 *
 * @package     BerlinDB\Tests\Fixtures
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests\Fixtures;

use BerlinDB\Database\Row;

/**
 * Typed row wrapper for test_widgets table rows.
 *
 * @since 2.1.0
 */
class TestRow extends Row {

	/** @var array<string,string> */
	public $casts = array(
		'id'       => 'int',
		'priority' => 'int',
		'settings' => 'array',
	);

	/** @var array<string,mixed> */
	public $args = array();

	/** @var int */
	public $id = 0;

	/** @var string */
	public $name = '';

	/** @var string */
	public $status = 'active';

	/** @var int */
	public $priority = 0;

	/** @var array<string,mixed> */
	public $settings = array();

	/** @var string */
	public $date_created = '';

	/** @var string */
	public $date_modified = '';

	/** @var string */
	public $uuid = '';
}
