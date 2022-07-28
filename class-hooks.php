<?php

namespace MDT\CHP;

/**
 * Hooks for handling the CHP image usage returning from WordPress
 *
 * Class Hooks
 *
 * @package MDT
 */
class Hooks {

	const CHP_CRON = 'retry_chp_call';
	const CHP_DAILY_CRON = 'daily_retry_chp_calls';
	const MAX_RETRIES = 4;

	private $chp_endpoint, $auth_token, $slack_url, $slack_channel, $enabled_post_types, $mustache;

	/**
	 * Hooks constructor.
	 */
	function __construct() {
		$this->chp_endpoint = get_option( Settings::CHP_URL );
		$this->auth_token   = base64_encode( get_option( Settings::CHP_TOKEN ) . ':' );
		$this->enabled_post_types = get_option( Settings::ENABLED_POST_TYPES );
		$this->slack_url    = get_option( Settings::SLACK_APP_URL );
		$this->slack_channel = get_option( Settings::SLACK_CHANNEL );

		// Get the mustache template set in settings
		$template_xml = get_option( Settings::CHP_XML_TEMPLATE );
		if ( ! $template_xml ) {
			return;
		}

		$this->mustache = new \Mustache_Engine(
			[
				'partials' => [
					'template_xml' => $template_xml
				]
			]
		);

		if ( array_key_exists( 'wpcom_vip_passthrough_cron_to_jobs', $GLOBALS['wp_filter'] ) ) {
			add_filter( 'wpcom_vip_passthrough_cron_to_jobs', [ $this, 'passthrough_to_jobs' ] );
		}

		// We keep it out from the conditional above to clean up the cron queque when a chp endpoint previously set is removed from settings.
		add_action( self::CHP_CRON, [ $this, 'send_usage_to_chp' ], 10, 1 );

		if ( $this->chp_endpoint ) {
			add_action( 'transition_post_status', [ $this, 'save_post_action' ], 200, 3 );

			// set a daily cronjob to search for posts with failed chp calls and retry
			add_action( 'admin_init', [ $this, 'activate_chp_daily_cron' ] );
			add_action( self::CHP_DAILY_CRON, [ $this, 'retry_chp_calls_daily' ], 10, 1 );
		} else {
			// unschedule the daily cron if a proper CHP endpoint is not set
			$timestamp = wp_next_scheduled( self::CHP_DAILY_CRON );
			wp_unschedule_event( $timestamp, self::CHP_DAILY_CRON );
		}

	}

	/**
	 * Save post hook
	 *
	 * @param $new_status
	 * @param $old_status
	 * @param $post
	 */
	function save_post_action( $new_status, $old_status, $post ) {

		if ( !is_array( $this->enabled_post_types ) ) {
			return;
		}
		if ( is_array( $this->enabled_post_types ) && !in_array( get_post_type( $post->ID ), $this->enabled_post_types, true ) ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) ) {
			return;
		}
		if ( 'auto-draft' === $new_status ) {
			return;
		}

		if ( $new_status === $old_status && 'publish' !== $new_status ) {
			return;
		}

		if ( 'publish' === $new_status ) {

			// Reset the number of retries on save. The author may have added additional images
			self::reset_chp_retries( $post->ID );

			// Schedule a single cron task performing the chp call after 60 seconds
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CHP_CRON, array( $post->ID ) );
		}
	}

	/**
	 * Handle the call to the CHP endpoint
	 *
	 * @param $post_id
	 * @param bool $daily
	 *
	 * @return array|mixed|void
	 */
	public function send_usage_to_chp( $post_id, $daily = false ) {
		// Skip the chp call if the chp endpoint is not set
		if ( ! $this->chp_endpoint ) {
			return;
		}

		$post = get_post( $post_id );

		$chp_errors = 0;

		if ( ! is_object( $post ) ) {
			return;
		}

		$chp_images     = self::get_chp_images( $post );
		$chp_images_ids = $this->get_images_meta( $post->ID );

		if ( ! $daily ) { // we don't want to skip calls for daily cron tasks
			$chp_retries = isset( $chp_images_ids['chp_retries'] ) ? $chp_images_ids['chp_retries'] : self::MAX_RETRIES;
			if ( $chp_retries <= 0 ) { // skip the CHP call if we've reached the max amount of allowed retries
				return;
			}
		}

		foreach ( $chp_images as $chp_image ) {

			$cph_error_log = false;
			$response      = '';

			// Skip the chp call if the image usage for the image has been already sent successfully
			if ( array_key_exists( $chp_image->ID, $chp_images_ids ) && isset( $chp_images_ids [ $chp_image->ID ]['status'] ) && 201 === $chp_images_ids [ $chp_image->ID ]['status'] ) {
				continue;
			}

			// Perform the first request to get the CHP asset ID
			$chp_images_ids = self::get_chp_asset_id( $chp_image->ID, $chp_images_ids );

			// Perform a second request sending the XURN instead of the global id (sometimes there are encoding issues)
			if ( !isset( $chp_images_ids[ $chp_image->ID ]['asset_id'] ) ) {
				$chp_images_ids = self::get_chp_asset_id( $chp_image->ID, $chp_images_ids, true );
			}

			if ( isset( $chp_images_ids[ $chp_image->ID ]['asset_id'] ) ) {

				$safe_data = self::format_data( (string) $chp_images_ids[ $chp_image->ID ]['asset_id'], $post ); // Structure data in a mustache friendly format

				$xml_string = $this->mustache->render( '{{> template_xml }}', $safe_data );

				// Perform a second request to post the image usage
				$response = wp_safe_remote_post(
					$this->chp_endpoint . 'children',
					array(
						'headers' => array(
							'Content-Type'  => 'text/plain',
							'Authorization' => 'Basic ' . $this->auth_token,
						),
						'body'    => $xml_string,
						'blocker' => false,
					)
				);

				if ( is_wp_error( $response ) ) {
					$cph_error_log = 'Wp error';
				} elseif ( 201 !== wp_remote_retrieve_response_code( $response ) ) {
					$cph_error_log = sprintf( 'Chp response status: %d', wp_remote_retrieve_response_code( $response ) );
				}
			} else {
				$cph_error_log = 'No CHP asset_id';
			}

			// if something goes wrong with the CHP call, store a few logs and send a slack notification.
			if ( $cph_error_log ) {
				$chp_errors ++;
				$chp_images_ids[ $chp_image->ID ]['error'] = $cph_error_log;
				$this->send_slack_notification( $post, $cph_error_log, $chp_image->ID, $response );
				continue;
			} elseif ( isset( $chp_images_ids[ $chp_image->ID ]['error'] ) ) {
				unset( $chp_images_ids[ $chp_image->ID ]['error'] );
			}

			$chp_images_ids[ $chp_image->ID ]['status'] = wp_remote_retrieve_response_code( $response );
		}

		if ( $chp_errors ) { // If something went wrong with one of the CHP calls retry in 30 minutes
			$chp_images_ids['errors'] = $chp_errors;

			if ( ! $daily ) { // We don't do this for daily cron tasks

				$chp_images_ids['chp_retries'] = $chp_retries - 1; // Update the remaining retries
				wp_schedule_single_event( time() + ( 30 * MINUTE_IN_SECONDS ), self::CHP_CRON, array( $post->ID ) );

			}
			update_post_meta( $post->ID, 'chp_errors', true );
		} else {
			delete_post_meta( $post->ID, 'chp_errors' );
		}

		update_post_meta( $post->ID, 'chp_images_ids', $chp_images_ids );

		return $chp_images_ids;
	}

	/**
	 * Retrieve the CHP external asset ID
	 *
	 * @param $image_id
	 * @param $chp_images_ids
	 * @param bool $search_by_xurn
	 *
	 * @return mixed
	 */
	public function get_chp_asset_id( $image_id, $chp_images_ids, $search_by_xurn = false ) {

		if ( ! isset( $chp_images_ids[ $image_id ]['asset_id'] ) ) {

			/*
			 * Get the chp global id meta
			 */
			$chp_global_id = get_post_meta( $image_id, 'chp_global_id', true );

			if ( $chp_global_id || $search_by_xurn ) {
				/*
			 * CHP image names follow some rules (e.g. PRI_69815710.jpg, SEI_66230596-bbcf.jpg or SEC_66232132.jpg )
			 * A few manipulations are needed in order to get the CHP ID
			 */
				$filename_exp_extension = explode( '.', basename( get_attached_file( $image_id ) ) );
				$filename_exp_hyphen    = explode( '-', $filename_exp_extension[0] );

				// the XURN_ID we send to CHP must have a specific format (e.g. PRI*69815710, SEI*66230596 )
				$xurn_id = str_replace( '_', '*', $filename_exp_hyphen[0] );

				/*
				 * We need to check if the image is a simple picture or a compound.
				 * Generally we get this info from the 3rd letter of the image name.
				 * if it's a "c" the image is a compound
				 * SEC_66232132.jpg is a compound
				 * PRI_69815710.jpg is a normal picture
				 * There is a race-condition in which some images don't have PRI/SEI or PRC/SEC in the filename.
				 * The filename will look like this: DMGTCHPDCOMP000001299583 or DMGTCHPDPICT000509793198
				 * 'DMGTCHPDCOMP' for compounds or 'DMGTCHPDPICT' for simple pictures
				 */

				$from = 'c' === strtolower( $xurn_id[2] ) || 'c' === strtolower( $xurn_id[8] ) ? 'Compound' : 'Picture';

				if ( $search_by_xurn ) {
					// Use the XURN instead
					$chp_img_id =  $xurn_id;
					$get_param = 'otex__DMG_INFO__XURN';
				} else {
					// Encode the GID to comply with the CHP requirements
					$chp_img_id = str_replace('%5C', "%27", \urlencode(\addslashes($chp_global_id)));
					$get_param = 'otex__DMG_INFO__GID';
				}

				$query = 'query?q=SELECT%20cmis:objectId%20FROM%20' . $from . '%20WHERE%20' . $get_param . '=%27' . $chp_img_id . '%27&includeRelationships=source';

				$chp_query = $this->chp_endpoint . $query;

				$response = wp_safe_remote_get(
					$chp_query,
					array(
						'headers' => array(
							'Authorization' => 'Basic ' . $this->auth_token,
						),
						'blocker' => false,
					)
				);

				if ( is_wp_error( $response ) ) {
					$chp_images_ids[ $image_id ]['wp_error'] = $response;
				}

				$chp_images_ids[ $image_id ]['xurn_id'][ $xurn_id ]['status'] = wp_remote_retrieve_response_code( $response );

				if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
					$chp_response = wp_remote_retrieve_body( $response );
					libxml_use_internal_errors( true );
					$chp_xml = simplexml_load_string( $chp_response );
					if ( false !== $chp_xml && isset( $chp_xml->xpath( 'atom:entry/cmisra:object/cmis:properties/cmis:propertyId/cmis:value' )[0] ) ) {
						$asset_id                                = $chp_xml->xpath( 'atom:entry/cmisra:object/cmis:properties/cmis:propertyId/cmis:value' )[0];
						$chp_images_ids[ $image_id ]['asset_id'] = (string) $asset_id;
					}
				}
			} else {
				$chp_images_ids[ $image_id ]['error_gid'] = 'No CHP GID set';
			}
		}

		return $chp_images_ids;
	}

	/**
	 * Reset the number of retries
	 *
	 * @param $post_id
	 */
	public function reset_chp_retries( $post_id ) {
		$chp_images_ids                = $this->get_images_meta( $post_id );
		$chp_images_ids['chp_retries'] = self::MAX_RETRIES;
		update_post_meta( $post_id, 'chp_images_ids', $chp_images_ids );
	}

	/**
	 * Retrieve the chp_images_ids array
	 *
	 * @param $post_id
	 *
	 * @return array
	 */
	public function get_images_meta( $post_id ) {
		return get_post_meta( $post_id, 'chp_images_ids', true ) ? get_post_meta( $post_id, 'chp_images_ids', true ) : array();
	}


	/**
	 * Send the slack notification
	 *
	 * @param $post
	 * @param $cph_error_log
	 * @param $image_id
	 * @param $response
	 */
	public function send_slack_notification( $post, $cph_error_log, $image_id, $response ) {

		if ( !$this->slack_url || !$this->slack_channel ) {
			return;
		}

		$post_url = get_permalink( $post->ID );

		$message = sprintf( 'CHP error: <%s|%s> - %s - IMG ID: %d ', trim( $post_url ), trim( $post->post_title ), $cph_error_log, $image_id );
		$message .= wp_json_encode( $response );

		$payload = array(
			'channel'    => $this->slack_channel,
			'icon_emoji' => ':-1:',
			'username'   => 'chpbot',
			'text'       => $message,
		);

		wp_safe_remote_post(
			$this->slack_url,
			array(
				'body'    => wp_json_encode( $payload ),
				'blocker' => false,
			)
		);
	}

	/**
	 * Return the data in a mustache friendly format
	 *
	 * @param $asset_id
	 * @param $post
	 *
	 * @return array
	 */
	public function format_data( $asset_id, $post ) {

		$categories    = get_the_category( $post->ID );
		$category_list = [];

		foreach ( $categories as $category ) {
			$category_list[] = $category->name;
		}

		$channels = implode( ',', $category_list );

		$authors = self::get_authors( $post->ID );

		$authors_names = [];
		foreach ( $authors as $author ) {

			if ( isset( $author->display_name ) ) {
				$authors_names[] = $author->display_name;
			}

		}

		return [
			'asset_id'      => strtoupper( $asset_id ),
			'post_id'       => $post->ID,
			'post_url'      => get_permalink( $post->ID ),
			'post_title'    => $post->post_title,
			'post_status'   => self::format_post_status( $post->post_status ),
			'post_publish'  => self::format_post_date( $post->post_date_gmt ),
			'post_modified' => self::format_post_date( $post->post_modified_gmt ),
			'post_category' => $channels,
			'post_author'   => implode( ',', $authors_names ),
		];
	}

	/**
	 * Return the date in Z(ulu) timezone (required by CHP)
	 *
	 * @param $post_date
	 *
	 * @return false|string
	 */
	function format_post_date( $post_date ) {
		return date( 'Y-m-d\TH:i:s.000\Z', strtotime( $post_date ) );
	}

	/**
	 * Convert wp post_status to the CHP standard (e.g publish = published)
	 *
	 * @param $post_status
	 *
	 * @return mixed
	 */
	function format_post_status( $post_status ) {
		switch ($post_status):
			case 'publish':
				return 'published';
				break;
			default:
				return $post_status;
		endswitch;
	}

	/**
	 * Returns post authors.
	 *
	 * @param $post_id
	 *
	 * @return array
	 */
	function get_authors( $post_id ) {

		$authors = [];

		if ( function_exists( 'get_coauthors' ) ) {
			$coauthors = get_coauthors( $post_id );
			foreach ( $coauthors as $author ) {
				if ( is_array( $author ) && array_key_exists( 'data', $author ) ) {
					$data                = new \stdClass();
					$data->ID            = $author->data->ID;
					$data->display_name  = $author->data->display_name;
					$data->user_nicename = $author->data->user_nicename;
					$data->type          = $author->data->type;
					$authors[]           = $data;
				} else {
					$authors[] = $author;
				}
			}
		} else {
			$author_id = get_post_field( 'post_author', $post_id );
			$author = get_userdata($author_id);
			$authors[] = $author;
		}

		return $authors;
	}

	/**
	 * Perform a regexp to get image IDs and then a WP_Query to retrieve only images uploaded via CHP
	 *
	 * @param $post
	 *
	 * @return array
	 */
	public function get_chp_images( $post ) {

		$img_ids    = [];
		$chp_images = [];

		$chp_users = explode( ',', get_option( Settings::CHP_USERS ) );

		// Single images IDs
		preg_match_all( '/wp-image-(\d+)/m', $post->post_content, $imgs_matches );

		if ( is_array( $imgs_matches[1] ) && ! empty( $imgs_matches[1] ) ) {
			$img_ids = $imgs_matches[1];
		}

		// Galleries images IDs
		preg_match_all( '/ids="(.*)"/m', $post->post_content, $gal_matches );

		if ( is_array( $gal_matches[1] ) && ! empty( $gal_matches[1] ) ) {
			foreach ( $gal_matches[1] as $gal_match ) {
				$gal_ids = explode( ',', $gal_match );
				$img_ids = array_merge( $gal_ids, $img_ids );
			}
		}

		// get thumbnail iD
		$thumb_img = get_post_thumbnail_id( $post->ID );
		if ( $thumb_img ) {
			$img_ids[] = $thumb_img;
		}

		// Array of meta keys used to store relevant infos about images set outside the post content
		// e.g. social images,leading images etc etc
		$post_meta = [
			'social-img-id',
			'leading-image-id',
			'_yoast_wpseo_opengraph-image-id',
			'_yoast_wpseo_twitter-image-id'
		];

		foreach ( $post_meta as $meta_key ) {
			$meta_value = get_post_meta( $post->ID, $meta_key, true );
			if ( ! empty( $meta_value ) ) {
				$img_ids[] = $meta_value;
			}
		}

		// For each image found check if it's a composition created through the Image Compositions tool
		foreach ( $img_ids as $img_id ) {

			$mdt_image_composition_source_id = get_post_meta ( $img_id, 'mdt_image_composition_source_id', false );
			if ( is_array( $mdt_image_composition_source_id ) ) {
				$img_ids = array_merge( $mdt_image_composition_source_id, $img_ids);
			}

			// Legacy code for old metro image compositions
			$metro_image_comp_source_id = get_post_meta ( $img_id, 'metro_image_comp_source_id', false );
			if ( is_array( $metro_image_comp_source_id ) ) {
				$img_ids = array_merge( $metro_image_comp_source_id, $img_ids);
			}
		}

		if ( ! empty( $img_ids ) ) {
			$args = array(
				'post__in'            => $img_ids,
				'post_type'           => 'attachment',
				'post_status'         => 'any',
				'author__in'          => $chp_users,
				// Retrieve only images uploaded by specific users via CHP (e.g. chpwpprod)
				'ignore_sticky_posts' => 1
			);

			$query = new \WP_Query( $args );

			if ( $query->have_posts() ) {
				$chp_images = $query->posts;
			}
		}

		return $chp_images;
	}

	/**
	 * Schedule a daily cron job
	 */
	function activate_chp_daily_cron() {
		if ( ! wp_next_scheduled( self::CHP_DAILY_CRON ) ) {
			wp_schedule_event( time(), 'daily', self::CHP_DAILY_CRON );
		}
	}

	/**
	 * Callback for the daily cron job
	 */
	function retry_chp_calls_daily() {

		if ( !is_array ( $this->enabled_post_types ) ) {
			return;
		}

		$paged = 1;

		do {

			$query = new \WP_Query(
				array(
					'post_type'      => $this->enabled_post_types,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'paged'          => $paged,
					'meta_query'     => [
						[
							'key'   => 'chp_errors',
							'value' => '1',
						],
					],
					'date_query'     => [
						[
							'after' => '3 days ago', // Retrieves stories from the last 3 days.
						],
					],
				)
			);

			foreach ( $query->posts as $post ) {
				self::send_usage_to_chp( $post->ID, true );
				sleep( 2 );
			}

			$paged ++;

		} while ( count( $query->posts ) );

	}

	/**
	 * Pass crons off to WP.com jobs system
	 *
	 * @param array $whitelist Crons running on jobs system
	 *
	 * @return array Filtered crons on jobs system
	 */
	public function passthrough_to_jobs( $whitelist ) {

		// Add cron jobs to whitelist
		$whitelist[] = self::CHP_CRON;
		$whitelist[] = self::CHP_DAILY_CRON;

		return $whitelist;
	}

}

global $chp_hooks;
$chp_hooks = new Hooks();
