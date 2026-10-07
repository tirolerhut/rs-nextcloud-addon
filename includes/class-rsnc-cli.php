<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: wp rsnc list | wp rsnc sync [<id>] [--all]
 */
class RSNC_CLI {

	/**
	 * Listet alle Zuordnungen.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array();
		foreach ( RSNC_Settings::get_mappings() as $id => $m ) {
			$rows[] = array(
				'id'     => $id,
				'name'   => $m['name'],
				'share'  => $m['share_url'],
				'slider' => $m['slider_id'],
				'active' => $m['active'] ? 'ja' : 'nein',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'share', 'slider', 'active' ) );
	}

	/**
	 * Gleicht eine oder alle Zuordnungen ab.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : ID der Zuordnung.
	 *
	 * [--all]
	 * : Alle aktiven Zuordnungen abgleichen.
	 */
	public function sync( $args, $assoc ) {
		$ids = ! empty( $assoc['all'] ) || empty( $args[0] )
			? array_keys( array_filter( RSNC_Settings::get_mappings(), function ( $m ) { return ! empty( $m['active'] ); } ) )
			: array( $args[0] );

		foreach ( $ids as $id ) {
			do {
				$r = RSNC_Sync::run( $id );
			} while ( ! empty( $r['pending'] ) && $r['pending'] > 0 && empty( $r['errors'] ) );
			WP_CLI::log( sprintf( '%s: +%d ~%d -%d =%d', $id, $r['added'], $r['updated'], $r['removed'], $r['unchanged'] ) );
			foreach ( $r['errors'] as $e ) {
				WP_CLI::warning( $e );
			}
		}
		WP_CLI::success( 'Fertig.' );
	}
}
