<?php
/**
 * Base Custom Database Table Rest Class.
 *
 * @package     Database
 * @subpackage  Rest
 * @copyright   Copyright (c) 2021
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * A base database table rest class, which houses the creation of rest
 * endpoints that a table is made out of.
 *
 * This class is intended to be extended for each unique database table,
 * including global tables for multisite, and users tables.
 *
 * @since 3.0.0
 */
class Rest extends Base {

	/**
	 * String with the field key name for this column.
	 *
	 * @since 3.0.0
	 * @var   string
	 */
	protected $field = '';

	/**
	 * Array with options for REST initialization.
	 *
	 * @since 3.0.0
	 * @var   array
	 */
	protected $rest_options = array();

	/**
	 * Array with default parameters for BerlinDB.
	 *
	 * @since 3.0.0
	 * @var   array
	 */
	protected $berlindb_default = array(
		// BerlinDB parameters
		'fields' => '',
		'number' => 100,
		'offset' => 0,
		'no_found_rows' => true,
		'orderby' => '',
		'order' => 'DESC',
		'update_item_cache' => false,
		'update_meta_cache' => false
	);

	/**
	 * Array with default parameters for REST.
	 *
	 * @since 3.0.0
	 * @var   array
	*/
	protected $rest_default = array(
		'page' => array(
			'default' => 1,
			'type' => 'int'
		)
	);

	/**
	 * Array with Column parameters.
	 *
	 * @since 3.0.0
	 * @var   array
	 */
	protected $args = '';

	/**
	 * Table name, without the global table prefix.
	 *
	 * @since 3.0.0
	 * @var   string
	 */
	protected $table_name = '';

	/**
	 * Query class used for REST integration.
	 *
	 * @since 3.0.0
	 * @var   string
	 */
	public $query_class = '';

	/**
	 * Array with all the columns (used by some specific feature).
	 *
	 * @since 3.0.0
	 * @var   array
	 */
	protected $all_columns = array();

	/**
	 * Initiliaze a new REST endpoint if the column is enabled.
	 *
	 * @since 3.0.0
	 */
	public function __construct( $all_columns = array(), $global_action = array(), $table_name = '', $query_class = '' ) {
		if ( !empty( $all_columns ) ) {
			$this->all_columns = $all_columns;
		}

		$this->table_name = $table_name;
		$this->query_class = $query_class;

		if ( empty( $this->args ) ) {
			$this->rest_options = $global_action;

			\add_action( 'rest_api_init', array( $this, 'initialize_global' ) );
		}

		foreach( $this->all_columns as $key => $column ) {
			if ( !isset( $column[ 'rest' ] ) ) {
				return;
			}

			if ( isset( $column[ 'crud' ] ) && $column[ 'crud' ] ) {
				$this->all_columns[ $key ][ 'create' ] = true;
				$this->all_columns[ $key ][ 'read' ] = true;
				$this->all_columns[ $key ][ 'update' ] = true;
				$this->all_columns[ $key ][ 'delete' ] = true;
			}

			\add_action( 'rest_api_init', function() use ( $column ) {
				$this->initialize_column_value( $column );
			} );
		}
	}

	/**
	 * Create a REST Endpoint
	 *
	 * @since 3.0.0
	 *
	 */
	public function initialize_global() {
		if ( isset( $this->rest_options[ 'create' ] ) && $this->rest_options[ 'create' ] ) {
			\register_rest_route(
				$this->table_name,
				'create',
				array(
					'methods' => \WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'create' ),
					'args' => array(
						$this->table_name => array(
							'description' => 'Object',
							'type' => 'array'
						)
					)
				)
			);
		}
		if ( isset( $this->rest_options[ 'shows_all' ] ) && $this->rest_options[ 'shows_all' ] ) {
			\register_rest_route(
				$this->table_name,
				'all',
				array(
					'methods' => \WP_REST_Server::READABLE,
					'callback' => array( $this, 'read_all' ),
					'args' => $this->generate_rest_args()
				)
			);
		}
		if ( isset( $this->rest_options[ 'enable_search' ] ) && $this->rest_options[ 'enable_search' ] ) {
			\register_rest_route(
				$this->table_name,
				'/search/',
					array(
					'methods' => \WP_REST_Server::READABLE,
					'permission_callback' => function () {
						return \apply_filters( 'berlindb_rest_' . $this->table_name . '_search', true, $this );
					},
					'callback' => array( $this, 'search' ),
					'args' => \wp_parse_args( $this->generate_rest_args(), array(
						's' => array(
							'description' => 'Search that string in that key',
							'type' => 'string'
						),
						'columns' => array(
							'description' => 'Search on those columns',
							'type' => 'array'
						)
					) )
				)
			);
		}
	}

	public function initialize_column_value( $column ) {
		if ( isset( $column[ 'rest' ][ 'read' ] ) && $column[ 'rest' ][ 'read' ] ) {
			\register_rest_route(
				$this->table_name,
				'/(?P<' . $column[ 'name' ] .'>\d+)',
				array(
					'methods' => \WP_REST_Server::READABLE,
					'callback' => function( \WP_REST_Request $request ) use ( $column ) {
						$this->read( $request, $column );
					},
					'args' => \wp_parse_args( $this->generate_rest_args(), array(
						$column[ 'name' ] => array(
							'type' => $column[ 'name' ]
						)
					) )
				)
			);
		}
		if ( isset( $column[ 'rest' ][ 'update' ] ) && $column[ 'rest' ][ 'update' ] ) {
			\register_rest_route(
				$this->table_name,
				'/(?P<' . $column[ 'name' ] .'>\d+)',
					array(
					'methods' => \WP_REST_Server::EDITABLE,
					'permission_callback' => function () use ( $column ) {
						return \apply_filters( 'berlindb_rest_' . $this->table_name . '_update', true, $column, $this );
					},
					'callback' => function( \WP_REST_Request $request ) use ( $column ) {
						$this->update( $request, $column );
					},
					'args' => \wp_parse_args( $this->generate_rest_args(), array(
						'meta' => array(
							'description' => 'Object',
							'type' => 'array'
						)
					) )
				)
			);
		}
		if ( isset( $column[ 'rest' ][ 'delete' ] ) && $column[ 'rest' ][ 'delete' ] ) {
			\register_rest_route(
				$this->table_name,
				'/(?P<' . $column[ 'name' ] .'>\d+)',
					array(
					'methods' => 'DELETE',
					'permission_callback' => function () use ( $column ) {
						return \apply_filters( 'berlindb_rest_' . $this->table_name . '_delete', true, $column, $this );
					},
					'callback' => function( \WP_REST_Request $request ) use ( $column ) {
						$this->delete( $request, $column );
					},
					'args' => array(
						$column[ 'name' ] => array(
							'description' => $column[ 'name' ] . ' key',
						)
					)
				)
			);
		}
	}

	public function parse_args( \WP_REST_Request $request ) {
		$args = \wp_parse_args( $request->get_params(), $this->berlindb_default );

		// Add support for defacto search WordPress parameter
		if ( isset( $request[ 's' ] ) ) {
			$args[ 'search' ] = $request[ 's' ];
			unset( $args[ 's' ] );
		}

		$args[ 'search_columns' ] = $request[ 'search_columns' ];
		if ( !empty( $args[ 'search_columns' ] ) ) {
			foreach ( $args[ 'search_columns' ] as $key => $name ) {
				if ( !in_array( $name, $this->all_columns ) ) {
					unset( $args[ 'search_columns' ][ $key ] );
				}
			}
		}

		if ( empty( $args[ 'search_columns' ] ) ) {
			$columns_supported = array();
			foreach ( $this->all_columns as $name => $column ) {
				if ( isset( $column[ 'rest' ][ 'search' ] ) && $column[ 'rest' ][ 'search' ] ) {
					$columns_supported[ $column[ 'name' ] ] = true;
				}
			}
			$args[ 'search_columns' ] = $columns_supported;
		}

		if ( isset( $request[ 'page' ], $request[ 'offset' ] ) && $args[ 'offset' ] === 0 ) {
			$args[ 'offset' ] = $request[ 'offset' ] * $request[ 'page' ];
		}

		return $args;
	}

	public function generate_rest_args() {
		$args = $this->rest_default;
		foreach( $this->berlindb_default as $key => $value ) {
			$args[ $key ] = array(
				'default' => $value,
			);
		}

		return $args;
	}

	public function read( \WP_REST_Request $request, $column ) {
		$args = $this->parse_args( $request );
		if ( isset( $column[ 'name' ] ) ) {
			$args[ $column[ 'name' ] ] = $request[ $column[ 'name' ] ];
		}

		$query = new $this->query_class( $args );
		// TODO Seems that otherwise doesn't work
		if ( isset( $query->items[0] ) ) {
			echo \wp_json_encode( $query->items[0] );
		}
		return '';
	}

	public function read_all( \WP_REST_Request $request ) {
		$args = $this->parse_args( $request );

		$query = new $this->query_class( $args );
		return \rest_ensure_response( $query->items );
	}

	public function create( \WP_REST_Request $request ) {
		$value = \apply_filters( 'berlindb_rest_' . $this->table_name . '_create', $request[ 'value' ] );
		$response = \rest_ensure_response();
		if ( isset( $request[ 'value' ] ) && !\is_wp_error( $value ) ) {
			$query = new $this->query_class();
			$result = $query->add_item( apply_filters( 'berlindb_rest_' . $this->table_name . '_create', $value ) );
			if ( $result ) {
				return $response;
			}
		}

		$response->set_status( 500 );

		return $response;
	}

	public function delete( \WP_REST_Request $request, $column ) {
		$query = new $this->query_class();
		$response = \rest_ensure_response();
		$result = $query->delete_item( $request[ $column[ 'name' ] ] );
		if ( $result ) {
			return $response;
		}

		$response->set_status( 500 );

		return $response;
	}

	public function update( \WP_REST_Request $request, $column ) {
		$item_meta = \apply_filters( 'berlindb_rest_' . $this->table_name . '_update_value', $request[ 'value' ], $request, $this );
		$response = \rest_ensure_response();
		if ( !\is_wp_error( $item_meta ) ) {
			$query = new $this->query_class();
			$query->update_item( $request[ $column[ 'name' ] ], $item_meta );
			if ( $result ) {
				return $response;
			}
		}

		$response->set_status( 500 );

		return $response;
	}

	public function search( \WP_REST_Request $request ) {
		$value = \apply_filters( 'berlindb_rest_' . $this->table_name . '_search_value', $request[ 's' ], $request, $this );
		if ( !empty( $value ) && !\is_wp_error( $value ) ) {
			$args = $this->parse_args( $request );
			$query = new $this->query_class( $args );
			if ( !empty( $query->items ) ) {
				return \rest_ensure_response( $query->items );
			}
		}

		$response = \rest_ensure_response();
		$response->set_status( 500 );

		return $response;
	}

}
