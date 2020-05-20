<?php

/**
 * CHP commands for the WP-CLI framework
 *
 * @package wp-cli
 * @since 3.0
 * @see https://github.com/wp-cli/wp-cli
 */
class CHP_WP_CLI extends WPCOM_VIP_CLI_Command {

	/**
	 * Subcommand to send the usage to chp for a specific post id
	 *
	 * @since 3.0
	 *
	 * @subcommand send_chp_usage
	 * @synopsis [--post_id=<post_id>]
	 */
	public function send_chp_usage( $args, $assoc_args ) {
		global $chp_hooks;

		$defaults = [ 'post_id'        => '' ];
		$assoc_args = wp_parse_args( $assoc_args, $defaults );
		if ( $assoc_args['post_id'] ) {
			WP_CLI::line( "Send the chp usage for post_id: " . esc_html( $assoc_args['post_id'] ) );
			$chp_hooks->send_usage_to_chp( $assoc_args['post_id'] );
        } else {
			WP_CLI::line( "Please set a valid post_id" );
        }

	}

}

\WP_CLI::add_command( 'chp', 'CHP_WP_CLI' );

