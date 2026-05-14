<?php
/**
 * Base Custom Database Table Index Class.
 *
 * @package     Database
 * @subpackage  Index
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */
namespace BerlinDB\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Base class used for each index for a custom table.
 *
 * Mirrors Column class, but for index registration & management.
 *
 * @since 1.0.0
 *
 * @param array|string $args {
 *     Optional. Array or query string of index parameters. Default empty.
 *
 *     @type string        $name        Name of the index
 *     @type string        $type        Index type: primary, unique, key, fulltext
 *     @type array         $columns     Array of column names included in this index
 *     @type bool          $unique      Is this index unique?
 *     @type string        $method      Index method: BTREE, HASH, etc
 *     @type string        $comment     Optional comment for the index
 *     @type string        $using       USING clause for index type (optional)
 * }
 */
class Index {

	use Traits\Base;
	use Traits\Boot;

	/** Attributes ************************************************************/

	/**
	 * Name for the database index.
	 * @var string
	 */
	public $name = '';

	/**
	 * Index type (primary, unique, key, fulltext)
	 * @var string
	 */
	public $type = 'key';

	/**
	 * Array of columns the index consists of.
	 * @var array
	 */
	public $columns = array();

	/**
	 * Is this index unique?
	 * @var bool
	 */
	public $unique = false;

	/**
	 * Index method (BTREE, HASH, etc.)
	 * @var string
	 */
	public $method = 'BTREE';

	/**
	 * Optional comment for the index.
	 * @var string
	 */
	public $comment = '';

	/**
	 * Optional USING clause for advanced index type specification.
	 * @var string
	 */
	public $using = '';

	/** Argument validation *************************************/
	protected function validate_args($args = array()) {
		$callbacks = array(
			'name'    => array($this, 'sanitize_index_name'),
			'type'    => 'strtolower',
			'unique'  => 'wp_validate_boolean',
			'method'  => 'strtoupper',
			'comment' => 'wp_kses_data',
			'using'   => 'strtoupper',
			'columns' => array($this, 'sanitize_columns'),
		);
		$r = array();
		foreach ($args as $key => $value) {
			if (isset($callbacks[$key]) && is_callable($callbacks[$key])) {
				$r[$key] = call_user_func($callbacks[$key], $value);
			} else {
				$r[$key] = $value;
			}
		}
		return $r;
	}

	/** Get CREATE clause for this index. */
	public function get_create_string() {
		if (empty($this->name) || empty($this->columns)) return '';
		$columns = array_map(function($col) { return "`$col`"; }, $this->columns);
		$type = strtoupper($this->type);
		$sql = '';
		if ($type === 'PRIMARY') {
			$sql = 'PRIMARY KEY (' . implode(', ', $columns) . ')';
		} elseif ($this->unique || $type === 'UNIQUE') {
			$sql = 'UNIQUE KEY `'.$this->name.'` (' . implode(', ', $columns) . ')';
		} elseif ($type === 'FULLTEXT') {
			$sql = 'FULLTEXT KEY `'.$this->name.'` (' . implode(', ', $columns) . ')';
		} else {
			$sql = 'KEY `'.$this->name.'` (' . implode(', ', $columns) . ')';
		}
		if (!empty($this->method)) {
			$sql .= ' USING ' . $this->method;
		}
		if (!empty($this->comment)) {
			$sql .= ' COMMENT ' . "'{$this->comment}'";
		}
		return $sql;
	}

	/** Sanitizers ****************************/
	private function sanitize_index_name($name = '') {
		return strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $name));
	}
	private function sanitize_columns($columns = array()) {
		return array_values(array_filter((array) $columns, 'is_string'));
	}
}
