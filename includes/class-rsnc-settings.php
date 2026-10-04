<?php
defined( 'ABSPATH' ) || exit;

/**
 * Speichert die Zuordnungen „Nextcloud-Freigabe → Slider“ und den Sync-Zeitplan.
 */
class RSNC_Settings {

	const OPT_MAPPINGS = 'rsnc_mappings';
	const OPT_INTERVAL = 'rsnc_interval';
	const OPT_STATUS   = 'rsnc_status';
	const INTERVALS    = array( 'hourly', 'twicedaily', 'daily', 'manual' );

	public static function defaults() {
		return array(
			'id'                => '',
			'name'              => '',
			'share_url'         => '',
			'password'          => '',
			'subfolder'         => '',
			'slider_id'         => 0,
			'template_slide_id' => 0,
			'sort'              => 'name_asc',
			'limit'             => 0,
			'image_size'        => 'full',
			'delete_removed'    => 1,
			'active'            => 1,
		);
	}

	public static function get_mappings() {
		$all = get_option( self::OPT_MAPPINGS, array() );
		return is_array( $all ) ? $all : array();
	}

	public static function get_mapping( $id ) {
		$all = self::get_mappings();
		return isset( $all[ $id ] ) ? wp_parse_args( $all[ $id ], self::defaults() ) : null;
	}

	public static function save_mapping( array $mapping ) {
		$mapping = wp_parse_args( $mapping, self::defaults() );
		if ( empty( $mapping['id'] ) ) {
			$mapping['id'] = strtolower( wp_generate_password( 8, false ) );
		}
		$all                   = self::get_mappings();
		$all[ $mapping['id'] ] = $mapping;
		update_option( self::OPT_MAPPINGS, $all, false );
		return $mapping['id'];
	}

	public static function delete_mapping( $id ) {
		$all = self::get_mappings();
		unset( $all[ $id ] );
		update_option( self::OPT_MAPPINGS, $all, false );
		delete_option( 'rsnc_slides_' . $id );
		delete_option( 'rsnc_tplhash_' . $id );
		$status = get_option( self::OPT_STATUS, array() );
		unset( $status[ $id ] );
		update_option( self::OPT_STATUS, $status, false );
	}

	public static function get_interval() {
		$i = get_option( self::OPT_INTERVAL, 'hourly' );
		return in_array( $i, self::INTERVALS, true ) ? $i : 'hourly';
	}

	public static function set_interval( $interval ) {
		if ( ! in_array( $interval, self::INTERVALS, true ) ) {
			return;
		}
		update_option( self::OPT_INTERVAL, $interval, false );
		self::unschedule();
		self::schedule();
	}

	public static function schedule() {
		$interval = self::get_interval();
		if ( 'manual' !== $interval && ! wp_next_scheduled( RSNC_CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, $interval, RSNC_CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( RSNC_CRON_HOOK );
	}

	public static function get_status( $id ) {
		$status = get_option( self::OPT_STATUS, array() );
		return isset( $status[ $id ] ) ? $status[ $id ] : null;
	}

	public static function set_status( $id, array $data ) {
		$status        = get_option( self::OPT_STATUS, array() );
		$status        = is_array( $status ) ? $status : array();
		$status[ $id ] = $data;
		update_option( self::OPT_STATUS, $status, false );
	}
}
