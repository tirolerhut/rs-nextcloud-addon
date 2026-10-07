<?php
/**
 * Plugin Name:       Slider Revolution – Nextcloud Add-on
 * Description:       Lädt Bilder direkt aus einer öffentlichen Nextcloud-Freigabe und erzeugt daraus automatisch Slides in Slider Revolution 6.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Alexander
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/
 * Text Domain:       rs-nextcloud
 */

defined( 'ABSPATH' ) || exit;

define( 'RSNC_VERSION', '1.3.0' );
define( 'RSNC_FILE', __FILE__ );
define( 'RSNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RSNC_CRON_HOOK', 'rsnc_cron_sync' );

require_once RSNC_DIR . 'includes/class-rsnc-settings.php';
require_once RSNC_DIR . 'includes/class-rsnc-nextcloud-client.php';
require_once RSNC_DIR . 'includes/class-rsnc-image-meta.php';
require_once RSNC_DIR . 'includes/class-rsnc-sync.php';
require_once RSNC_DIR . 'includes/class-rsnc-admin.php';
require_once RSNC_DIR . 'includes/class-rsnc-updater.php';

// Updater immer laden – WordPress prüft Updates auch per Cron (außerhalb des Admins).
RSNC_Updater::init();

/**
 * Ist Slider Revolution 6 aktiv?
 */
function rsnc_revslider_active() {
	return defined( 'RS_REVISION' ) || class_exists( 'RevSliderFront' );
}

register_activation_hook( __FILE__, array( 'RSNC_Settings', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'RSNC_Settings', 'unschedule' ) );

add_action( RSNC_CRON_HOOK, array( 'RSNC_Sync', 'run_all' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( is_admin() ) {
			RSNC_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once RSNC_DIR . 'includes/class-rsnc-cli.php';
			WP_CLI::add_command( 'rsnc', 'RSNC_CLI' );
		}
	}
);
