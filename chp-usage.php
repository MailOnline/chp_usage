<?php
/**
 * Plugin Name: CHP usage
 * Plugin URI:  https://github.com/MailOnline/chp-usage/
 * Description: Connect to CHP and push the usage stats back to their systems
 * Version:     1.0.0
 * Author:      Metro.co.uk
 * Author URI:  https://github.com/MailOnline/chp-usage/graphs/contributors
 * Text Domain: chp-usage
 */

namespace MDT\CHP;

if ( ! class_exists( 'MDT\CHP\Chp_Usage' ) ) :

	/**
	 * Class Chp_Usage
	 */
	class Chp_Usage {

		/**
		 * Initial load.
		 */
		public static function load() {
			if ( !class_exists('Mustache_Autoloader' ) ) {
				require_once __DIR__ . '/lib/mustache/src/Mustache/Autoloader.php';
				Mustache_Autoloader::register();
			}
			require_once plugin_dir_path( __FILE__ ) . 'class-settings.php';
			require_once plugin_dir_path( __FILE__ ) . 'class-hooks.php';
			require_once plugin_dir_path( __FILE__ ) . 'class-api.php';
		}

	}

	Chp_Usage::load();

endif;

