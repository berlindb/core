<?php
/**
 * Query-tier (remote) relationship validation (#206).
 *
 * Query::get_relationship_errors() resolves each remote Query and checks what
 * only it can see: the class is a real sibling Query, and the referenced remote
 * columns exist. It is on demand by design - a plugin's tests or dev tooling call
 * it explicitly.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Remote target
// ---------------------------------------------------------------------------

/** Remote schema: a bigint primary key 'id' and a varchar 'name'. */
class QValRemoteSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
		array(
			'name' => 'name',
			'type' => 'varchar',
		),
	);
}

/** A real sibling Query, the target of the relationships below. */
class QValRemoteQuery extends Query {
	protected $prefix       = 'qv';
	protected $table_name   = 'remote';
	protected $table_schema = QValRemoteSchema::class;
	protected $cache_group  = 'remote';
}

/** A class that exists but is NOT a Query. */
class QValNotAQuery {}

// ---------------------------------------------------------------------------
// Local fixtures
// ---------------------------------------------------------------------------

/** Valid belongs_to: bigint remote_id -> remote bigint id. */
class QValGoodSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
		array(
			'name'          => 'remote_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'name'   => 'remote',
					'query'  => QValRemoteQuery::class,
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),
	);
}
class QValGoodQuery extends Query {
	protected $prefix       = 'qv';
	protected $table_name   = 'good';
	protected $table_schema = QValGoodSchema::class;
	protected $cache_group  = 'good';
}

/** References a remote column that does not exist. */
class QValBadColSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'remote_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'name'   => 'remote',
					'query'  => QValRemoteQuery::class,
					'column' => 'idd',
				),
			),
		),
	);
}
class QValBadColQuery extends Query {
	protected $prefix       = 'qv';
	protected $table_name   = 'badcol';
	protected $table_schema = QValBadColSchema::class;
	protected $cache_group  = 'badcol';
}

/** Remote class exists but is not a Query. */
class QValNotAQuerySchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'remote_id',
			'type'          => 'bigint',
			'relationships' => array(
				array(
					'name'   => 'remote',
					'query'  => QValNotAQuery::class,
					'column' => 'id',
				),
			),
		),
	);
}
class QValNotAQueryQuery extends Query {
	protected $prefix       = 'qv';
	protected $table_name   = 'notaquery';
	protected $table_schema = QValNotAQuerySchema::class;
	protected $cache_group  = 'notaquery';
}

/**
 * Tests for Query::get_relationship_errors().
 *
 * @since 3.1.0
 */
class QueryRelationshipValidationTest extends TestCase {

	/**
	 * A valid relationship to a real remote Query reports no errors.
	 *
	 * @since 3.1.0
	 */
	public function test_valid_relationship_has_no_errors() {
		$query = new QValGoodQuery();

		$this->assertSame( array(), $query->get_relationship_errors() );
	}

	/**
	 * A missing remote column is reported.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_remote_column_reported() {
		$query = new QValBadColQuery();

		$this->assertStringContainsString( 'unknown remote column idd', implode( ' ', $query->get_relationship_errors() ) );
	}

	/**
	 * A remote class that exists but is not a Query is reported here (not Schema).
	 *
	 * @since 3.1.0
	 */
	public function test_remote_not_a_query_reported() {
		$query = new QValNotAQueryQuery();

		$this->assertStringContainsString( 'is not a Query', implode( ' ', $query->get_relationship_errors() ) );
	}
}
