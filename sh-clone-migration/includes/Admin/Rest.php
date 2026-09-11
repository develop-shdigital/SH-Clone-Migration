<?php
/**
 * REST API.
 *
 * @package SHCM
 */

namespace SHCM\Admin;

use SHCM\Core\Plugin;
use SHCM\Security\Request;

defined( 'ABSPATH' ) || exit;

/**
 * A REST mirror of the migration actions.
 *
 * Useful for deployment tooling and for driving a migration from outside the
 * admin screens; the browser UI itself uses admin-ajax, which is available even
 * when the REST API is blocked by a security plugin.
 */
class Rest {

	const NAMESPACE_V1 = 'shcm/v1';

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Controller.
	 *
	 * @var Controller
	 */
	protected $controller;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin     = $plugin;
		$this->controller = new Controller( $plugin );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Declare the routes.
	 *
	 * @return void
	 */
	public function routes() {
		$permission = array( Request::class, 'restPermission' );

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $permission,
					'callback'            => function ( $request ) {
						return $this->respond(
							function () use ( $request ) {
								$type = $request->get_param( 'type' );
								return $this->controller->jobs( $type ? sanitize_key( $type ) : null );
							}
						);
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $permission,
					'args'                => array(
						'type' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'export', 'import', 'search_replace' ),
						),
					),
					'callback'            => function ( $request ) {
						return $this->respond(
							function () use ( $request ) {
								$params = (array) $request->get_json_params();
								if ( empty( $params ) ) {
									$params = (array) $request->get_body_params();
								}
								switch ( $request->get_param( 'type' ) ) {
									case 'import':
										return $this->controller->startImport( $params );
									case 'search_replace':
										return $this->controller->startReplace( $params );
								}
								return $this->controller->startExport( $params );
							}
						);
					},
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>[A-Za-z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => function ( $request ) {
					return $this->respond(
						function () use ( $request ) {
							return $this->controller->status( $request->get_param( 'id' ) );
						}
					);
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>[A-Za-z0-9\-]+)/tick',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function ( $request ) {
					return $this->respond(
						function () use ( $request ) {
							return $this->controller->tick(
								$request->get_param( 'id' ),
								(string) $request->get_param( 'password' )
							);
						}
					);
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>[A-Za-z0-9\-]+)/cancel',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function ( $request ) {
					return $this->respond(
						function () use ( $request ) {
							return $this->controller->cancel( $request->get_param( 'id' ) );
						}
					);
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/archives',
			array(
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => function () {
					return $this->respond(
						function () {
							return $this->controller->archives();
						}
					);
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => function () {
					return $this->respond(
						function () {
							return $this->controller->systemStatus();
						}
					);
				},
			)
		);
	}

	/**
	 * Run a callback and turn exceptions into REST errors.
	 *
	 * @param callable $callback Callback.
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function respond( callable $callback ) {
		try {
			return rest_ensure_response( call_user_func( $callback ) );
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error( 'REST request failed: ' . $e->getMessage() );
			return new \WP_Error( 'shcm_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
