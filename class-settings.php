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

	const PAGE_SLUG     = 'chp_usage';
	const CHP_URL       = 'chp_usage_url';
	const CHP_TOKEN     = 'chp_usage_token';
	const CHP_USERS     = 'chp_usage_users';
    const CHP_XML_TEMPLATE     = 'chp_usage_xml_template';
	const SLACK_APP_URL = 'slack_app_url';

	/**
	 * Hooks constructor
	 */
	function __construct() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register_fields' ] );
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
        self::register_setting( self::CHP_XML_TEMPLATE, 'CHP XML TEMPLATE', '','field_textarea' );
		self::register_setting( self::SLACK_APP_URL, 'SLACK APP URL' );

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

}

new Settings();

