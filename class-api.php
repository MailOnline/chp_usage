<?php

namespace MDT\CHP;

/**
 * Class API
 *
 * Adds a new wp-json endpoint for creating media items via the wp-json api using the
 * schema defined for uploading media via the WordPress.com public API.
 *
 * @package MDT
 */
class API {

	const API_NAMESPACE = 'media-chp';

	/**
	 * API constructor.
	 */
	function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_endpoint' ], 10, 2 );
	}

	/**
	 * Registers the new media upload endpoint /wp-json/chp-usage/media/
	 */
	public function register_endpoint() {
		register_rest_route(
			self::API_NAMESPACE,
			'/media',
			[
				'methods'  => \WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'callback' ],
			]
		);
	}

	/**
	 * Callback for the new media upload endpoint.
	 *
	 * All we're doing here is transforming the expected query schema for creating a new media item via
	 * the WordPress.com public API to the query schema needed for the default wp-json media route and
	 * then making an internal call to said route. The response is returned as is.
	 *
	 * Permission checks alongside validation and sanitization for the passed in parameters are handled
	 * by the core wp-json code when internally calling the media route.
	 *
	 * @see https://developer.wordpress.com/docs/api/1.1/post/sites/%24site/media/new/
	 * @see https://developer.wordpress.org/rest-api/reference/media/#create-a-media-item
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return array
	 */
	function callback( \WP_REST_Request $request ) {
		$file_params = $request->get_file_params();
		$file        = [
			'name'     => $file_params['media']['name'][0],
			'type'     => $file_params['media']['type'][0],
			'tmp_name' => $file_params['media']['tmp_name'][0],
			'error'    => $file_params['media']['error'][0],
			'size'     => $file_params['media']['size'][0],
		];

		$body_params = $request->get_body_params();
		$body        = [
			'title'       => $body_params['attrs'][0]['title'],
			'caption'     => $body_params['attrs'][0]['caption'],
			'description' => $body_params['attrs'][0]['description'],
			'alt_text'    => $body_params['attrs'][0]['alt'],
		];

		$new_request = new \WP_REST_Request( 'POST', '/wp/v2/media' );
		$new_request->set_file_params( [ 'file' => $file ] );
		$new_request->set_body_params( $body );
		$response = rest_do_request( $new_request );
		$server   = rest_get_server();

		return $server->response_to_data( $response, false );
	}
}

new API();

