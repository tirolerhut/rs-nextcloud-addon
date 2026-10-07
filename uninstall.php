<?php
// Entfernt nur die Einstellungen. Erzeugte Slides und Bilder bleiben erhalten.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$maps = get_option( 'rsnc_mappings', array() );
foreach ( array_keys( (array) $maps ) as $id ) {
	delete_option( 'rsnc_slides_' . $id );
	delete_option( 'rsnc_tplhash_' . $id );
	delete_option( 'rsnc_failed_' . $id );
	wp_clear_scheduled_hook( 'rsnc_cron_continue', array( $id ) );
}
delete_option( 'rsnc_mappings' );
delete_option( 'rsnc_interval' );
delete_option( 'rsnc_status' );
wp_clear_scheduled_hook( 'rsnc_cron_sync' );
delete_option( 'rsnc_github' );
delete_site_transient( 'rsnc_gh_release' );
