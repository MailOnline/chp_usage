<?php

namespace MDT\CHP;

/**
 * Actions for handling the CHP image usage settings
 *
 * Class Settings
 *
 * @package MDT
 */
class Settings {

	const PAGE_SLUG = 'chp_usage';
	const CHP_URL = 'chp_usage_url';
	const CHP_TOKEN = 'chp_usage_token';
	const CHP_USERS = 'chp_usage_users';
	const CHP_XML_TEMPLATE = 'chp_usage_xml_template';
	const FE_SITE_URL = 'fe_site_url';
	const ENABLED_POST_TYPES = 'enabled_post_types';
	const SLACK_APP_URL = 'slack_app_url';
	const SLACK_CHANNEL = 'slack_channel';

	/**
	 * Hooks constructor
	 */
	function __construct() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register_fields' ] );
		add_action( 'init', [ $this, 'chp_global_id_meta_init' ] );
		add_action( 'rest_after_insert_attachment', [ __CLASS__, 'after_rest_insert' ], 10, 2 );
	}

	/**
	 * Add the page under Settings
	 */
	public function add_page() {

		// Get the page name when we add the submenu page
		add_submenu_page(
			'options-general.php',
			'CHP usage settings',
			'CHP usage settings',
			'administrator',
			self::PAGE_SLUG,
			[ $this, 'page_callback' ]
		);
	}


	/**
	 * The settings page callback
	 */
	public function page_callback() {
		?>
        <div class="wrap">
            <h2>CHP usage settings</h2>
            <form method="post" id="chp-settings" action="options.php">
				<?php
				settings_fields( 'chp_settings' );
				do_settings_sections( 'chp-settings' );
				submit_button( 'Save Changes', 'primary', 'submit', true, [ 'id' => 'submit' ] );
				?>
            </form>
        </div>
		<?php
	}

	/**
	 * Register the fields to display in the settings page
	 */
	public static function register_fields() {

		add_settings_section( 'chp-settings-general', '', '__return_false', 'chp-settings' );

		self::register_setting( self::CHP_URL, 'CHP URL' );
		self::register_setting( self::CHP_TOKEN, 'CHP TOKEN' );
		self::register_setting( self::CHP_USERS, 'CHP users', 'IDs Comma separated used to detect CHP images.' );
		self::register_setting( self::CHP_XML_TEMPLATE, 'CHP XML TEMPLATE', '', 'field_textarea' );
		self::register_setting( self::ENABLED_POST_TYPES, 'Enable Post Types', '', 'field_checkbox' );
		self::register_setting( self::FE_SITE_URL, 'SITE URL', 'Replace the default WordPress URL with a different one on URL sent to CHP.' );
		self::register_setting( self::SLACK_APP_URL, 'SLACK APP URL' );
		self::register_setting( self::SLACK_CHANNEL, 'SLACK CHANNEL' );
		self::register_setting( 'ip_address', 'IP address', '', 'display_the_ip' );

	}

	/**
	 * Add the settings field
	 *
	 * @param $id
	 * @param $label
	 * @param string $note
	 * @param string $form_field
	 */
	public static function register_setting( $id, $label, $note = '', $form_field = 'field_input' ) {

		add_settings_field(
			$id,
			'<label for="' . esc_attr( $id ) . '">' . esc_attr( $label ) . '</label>',
			[ __CLASS__, $form_field ],
			'chp-settings',
			'chp-settings-general',
			[ $id, $note ]
		);
		register_setting( 'chp_settings', $id );

	}

	/**
	 * Generate the input text field
	 *
	 * @param $args
	 */
	public static function field_input( $args ) {
		$value = get_option( $args[0] );
		printf( '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />', esc_attr( $args[0] ), esc_attr( $value ) );
		if ( $args[1] ) {
			printf( '<small class="admin-note">%s</small>', esc_html( $args[1] ) );
		}
	}

	/**
	 * Generate the textarea field
	 *
	 * @param $args
	 */
	public static function field_textarea( $args ) {
		$value = get_option( $args[0] );
		printf( '<textarea id="%1$s" name="%1$s" rows="10" cols="50" class="large-text code">%2$s</textarea>', esc_attr( $args[0] ), esc_attr( $value ) );
		if ( $args[1] ) {
			printf( '<small class="admin-note">%s</small>', esc_html( $args[1] ) );
		}
	}

	/**
	 * Generate the checkbox field
	 *
	 * @param $args
	 */
	public static function field_checkbox( $args ) {
		$option_value = get_option( $args[0] );
		$post_types   = array_keys( get_post_types() );

		foreach ( $post_types as $key ) {

			$checked = ( is_array( $option_value ) && in_array( $key, $option_value, true ) ) ? "checked=\"checked\"" : "";

			printf( '<div><input type="checkbox" name="%s[]" id="%s[%s]" value="%s" %s><label for="%s[%s]">%s</label></div>',
				esc_attr( $args[0] ), esc_attr( $args[0] ), esc_attr( $key ), esc_attr( $key ), $checked, esc_attr( $args[0] ), esc_attr( $key ), esc_html( $key ) );

		}
	}

	public static function chp_global_id_meta_init() {
		register_meta(
			'post',
			'chp_global_id',
			[
				'type'         => 'string',
				'show_in_rest' => true,
				'single'       => true,
			]
		);
	}

	/**
	 * Display the IP address
	 */
	public static function display_the_ip() {
		$curl     = new \WP_Http_Curl();
		$response = @$curl->request( 'https://ifconfig.me/' );
		if ( ! is_wp_error( $response ) && isset ( $response["body"] ) ) {
			echo esc_html( $response["body"] );
		}
	}

	/**
	 * Fires after an attachment is created or updated via the REST API.
	 * Workaround to store the chp_global_id custom meta without doing a second call
	 *
	 * @param object $post Inserted Post object (not a WP_Post object).
	 * @param WP_REST_Request $request Request object.
	 */
	public static function after_rest_insert( $post, $request ) {

		if ( isset( $request['chp_global_id'] ) ) {
			add_post_meta( $post->ID, 'chp_global_id', $request['chp_global_id'], true );
		}
	}

}

new Settings();
