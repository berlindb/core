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
use BerlinDB\Database\Kern\Relationship;
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

/** A pivot Query with post_id / tag_id columns. */
class QValPivotSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
		array(
			'name' => 'post_id',
			'type' => 'bigint',
		),
		array(
			'name' => 'tag_id',
			'type' => 'bigint',
		),
	);
}
class QValPivotQuery extends Query {
	protected $prefix       = 'qv';
	protected $table_name   = 'pivot';
	protected $table_schema = QValPivotSchema::class;
	protected $cache_group  = 'pivot';
}

/** Valid many_to_many through the pivot (declared at the Schema level). */
class QValM2MGoodSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'               => 'remotes',
					'columns'            => array( 'id' ),
					'query'              => QValRemoteQuery::class,
					'references'         => array( 'id' ),
					'through'            => QValPivotQuery::class,
					'through_columns'    => array( 'post_id' ),
					'through_references' => array( 'tag_id' ),
				)
			),
		);
	}
}
class QValM2MGoodQuery extends Query {
	protected $prefix       = 'qv';
	protected $table_name   = 'm2mgood';
	protected $table_schema = QValM2MGoodSchema::class;
	protected $cache_group  = 'm2mgood';
}

/** many_to_many naming a pivot column that does not exist. */
class QValM2MBadPivotColSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'               => 'remotes',
					'columns'            => array( 'id' ),
					'query'              => QValRemoteQuery::class,
					'references'         => array( 'id' ),
					'through'            => QValPivotQuery::class,
					'through_columns'    => array( 'nope' ),
					'through_references' => array( 'tag_id' ),
				)
			),
		);
	}
}
class QValM2MBadPivotColQuery extends Query {
	protected $prefix       = 'qv';
	protected $table_name   = 'm2mbadcol';
	protected $table_schema = QValM2MBadPivotColSchema::class;
	protected $cache_group  = 'm2mbadcol';
}

/** many_to_many where BOTH the pivot and the target are not Queries. */
class QValM2MBothBadSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'               => 'remotes',
					'columns'            => array( 'id' ),
					'query'              => QValNotAQuery::class,
					'references'         => array( 'id' ),
					'through'            => QValNotAQuery::class,
					'through_columns'    => array( 'post_id' ),
					'through_references' => array( 'tag_id' ),
				)
			),
		);
	}
}
class QValM2MBothBadQuery extends Query {
	protected $prefix       = 'qv';
	protected $table_name   = 'm2mbothbad';
	protected $table_schema = QValM2MBothBadSchema::class;
	protected $cache_group  = 'm2mbothbad';
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

	/**
	 * A valid many_to_many (resolvable pivot + real pivot columns) reports no errors.
	 *
	 * @since 3.1.0
	 */
	public function test_valid_many_to_many_has_no_errors() {
		$query = new QValM2MGoodQuery();

		$this->assertSame( array(), $query->get_relationship_errors() );
	}

	/**
	 * A many_to_many naming a pivot column that does not exist is reported.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_pivot_column_reported() {
		$query = new QValM2MBadPivotColQuery();

		$this->assertStringContainsString(
			'unknown pivot column nope',
			implode( ' ', $query->get_relationship_errors() )
		);
	}

	/**
	 * When BOTH the pivot and the target are not Queries, BOTH are reported - the
	 * pivot check is not skipped by the target's early continue.
	 *
	 * @since 3.1.0
	 */
	public function test_pivot_and_target_both_not_a_query_reported() {
		$query  = new QValM2MBothBadQuery();
		$errors = implode( ' ', $query->get_relationship_errors() );

		$this->assertStringContainsString( 'pivot class', $errors );
		$this->assertStringContainsString( 'remote class', $errors );
	}
}
