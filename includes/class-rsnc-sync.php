<?php
defined( 'ABSPATH' ) || exit;

/**
 * Synchronisiert Bilder einer Nextcloud-Freigabe in einen Slider-Revolution-6-Slider.
 *
 * Prinzip: Für jedes Bild wird eine „Vorlagen-Slide“ des Sliders geklont und deren
 * Hintergrundbild ersetzt. So bleiben Übergänge, Ebenen, Kenburns usw. aus dem
 * Slider-Editor erhalten. Platzhalter in Text-Ebenen der Vorlage:
 *   {{nc_title}}     Dateiname ohne Endung, „_“ und „-“ als Leerzeichen
 *   {{nc_filename}}  Dateiname
 *   {{nc_date}}      Änderungsdatum der Datei (WordPress-Datumsformat)
 *   {{nc_caption}}   Bildbeschreibung aus XMP/IPTC/EXIF (Lightroom: „Bildunterschrift“)
 *   {{nc_headline}}  IPTC-Überschrift
 *   {{nc_meta_title}} IPTC/XMP-Titel
 *   {{nc_creator}}   Fotograf (Ersteller/Artist)
 *   {{nc_copyright}} Copyright-Vermerk
 *   {{nc_date_taken}} Aufnahmedatum
 *   {{nc_camera}}    Kameramodell
 * Fallback: {{nc_caption|nc_title}} oder {{nc_caption|Freier Text}} – wird verwendet, wenn das Feld leer ist.
 */
/** Interne Markierung: Etappe beendet, weitere folgen. */
class RSNC_Batch_Pause extends Exception {}

class RSNC_Sync {

	const LOCK = 'rsnc_lock_';

	/** Zuordnung, die gerade läuft, und Bild, das gerade verarbeitet wird (für Absturzmeldungen). */
	private static $running = null;
	private static $current = null;
	private static $shutdown_registered = false;
	private static $deadline = null;

	private static function slides_table() {
		global $wpdb;
		return $wpdb->prefix . 'revslider_slides';
	}

	public static function tables_ok() {
		global $wpdb;
		$t = self::slides_table();
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
	}

	/** Alle aktiven Zuordnungen (Cron). */
	public static function run_all() {
		foreach ( RSNC_Settings::get_mappings() as $id => $m ) {
			if ( ! empty( $m['active'] ) ) {
				self::continue_run( $id );
			}
		}
	}

	/** Cron: eine Etappe ausführen und bei Bedarf die nächste einplanen. */
	public static function continue_run( $mapping_id ) {
		$r = self::run( $mapping_id );
		if ( ! empty( $r['pending'] ) || ! empty( $r['busy'] ) ) {
			self::schedule_continue( $mapping_id, ! empty( $r['busy'] ) ? 120 : 15 );
		}
		return $r;
	}

	public static function schedule_continue( $mapping_id, $delay = 15 ) {
		if ( ! wp_next_scheduled( 'rsnc_cron_continue', array( $mapping_id ) ) ) {
			wp_schedule_single_event( time() + $delay, 'rsnc_cron_continue', array( $mapping_id ) );
		}
	}

	/** Übersprungene Bilder erneut versuchen (manueller Abgleich). */
	public static function reset_failed( $mapping_id ) {
		delete_option( 'rsnc_failed_' . $mapping_id );
	}

	/* =====================================================================
	 * Zeitbudget – der Abgleich läuft in Etappen, damit das Zeitlimit des
	 * Servers (oft 30–60 s, nicht erhöhbar) nie erreicht wird.
	 * ===================================================================== */

	private static function deadline() {
		if ( null === self::$deadline ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore -- wirkt nur, wenn der Hoster es erlaubt
			}
			$limit = (int) ini_get( 'max_execution_time' );
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				$budget = 0; // Kommandozeile: kein Limit
			} else {
				$budget = $limit > 0 ? min( 20, max( 5, $limit * 0.5 ) ) : 20;
			}
			$budget = (float) apply_filters( 'rsnc_time_budget', $budget, $limit );
			$start  = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
			self::$deadline = $budget > 0 ? $start + $budget : 0;
		}
		return self::$deadline;
	}

	/* =====================================================================
	 * Absturzschutz – bricht PHP ab (Zeitlimit, Arbeitsspeicher), wird die
	 * Sperre sofort freigegeben und die Ursache in der Admin-Seite angezeigt.
	 * ===================================================================== */

	public static function on_shutdown() {
		if ( null === self::$running ) {
			return;
		}
		$err = error_get_last();
		if ( ! $err || ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}
		$id   = self::$running;
		$cur  = self::$current;
		$name = $cur ? $cur['name'] : '';
		$msg  = $err['message'];

		if ( false !== stripos( $msg, 'Maximum execution time' ) ) {
			/* translators: 1: seconds 2: file */
			$text = sprintf( __( 'Zeitlimit des Servers (%1$s s) bei „%2$s“ überschritten – das Bild ist für den Server zu aufwendig. Bild in Nextcloud verkleinern (z. B. auf 3000 px Breite) oder beim Hoster max_execution_time erhöhen.', 'rs-nextcloud' ), ini_get( 'max_execution_time' ), $name ? $name : '?' );
		} elseif ( false !== stripos( $msg, 'Allowed memory size' ) ) {
			/* translators: 1: file 2: memory limit */
			$text = sprintf( __( 'Arbeitsspeicher des Servers (%2$s) reicht nicht für „%1$s“. Bild in Nextcloud verkleinern oder in wp-config.php WP_MAX_MEMORY_LIMIT erhöhen (z. B. 512M).', 'rs-nextcloud' ), $name, ini_get( 'memory_limit' ) );
		} else {
			$text = sprintf( 'PHP-Fehler%s: %s (%s:%d)', $name ? ' bei „' . $name . '“' : '', $msg, wp_basename( $err['file'] ), $err['line'] );
		}

		// Bild merken – nach dem zweiten Absturz am selben Bild wird es übersprungen.
		if ( $cur ) {
			$failed                  = get_option( 'rsnc_failed_' . $id, array() );
			$failed                  = is_array( $failed ) ? $failed : array();
			$prev                    = $failed[ $cur['path'] ] ?? array( 'n' => 0, 'fp' => '' );
			$failed[ $cur['path'] ]  = array(
				'n'   => $prev['fp'] === $cur['fp'] ? $prev['n'] + 1 : 1,
				'fp'  => $cur['fp'],
				'msg'  => $text,
				'time' => time(),
			);
			update_option( 'rsnc_failed_' . $id, $failed, false );
		}

		self::unlock( $id );
		$status = RSNC_Settings::get_status( $id );
		$status = is_array( $status ) ? $status : array();
		RSNC_Settings::set_status(
			$id,
			array_merge(
				array( 'added' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0, 'renamed' => 0, 'duplicates' => array(), 'cleaned' => 0 ),
				$status,
				array(
					'errors'      => array( $text ),
					'interrupted' => true,
					'pending'     => max( 1, (int) ( $status['pending'] ?? 1 ) ),
					'time'        => time(),
				)
			)
		);
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::schedule_continue( $id, 60 );
		}
		self::$running = null;
	}

	/* =====================================================================
	 * Sperre – atomar per INSERT IGNORE, damit nie zwei Läufe gleichzeitig
	 * dieselbe Zuordnung bearbeiten (z. B. WP-Cron + manueller Abgleich).
	 * ===================================================================== */

	private static function lock( $mapping_id ) {
		global $wpdb;
		$name = self::LOCK . $mapping_id;
		$sql  = "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')";
		if ( $wpdb->query( $wpdb->prepare( $sql, $name, (string) time() ) ) ) { // phpcs:ignore
			return true;
		}
		// Hängengebliebene Sperre (Abbruch/Timeout) nach 20 Minuten übernehmen.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $name, time() - 20 * MINUTE_IN_SECONDS ) ); // phpcs:ignore
		return (bool) $wpdb->query( $wpdb->prepare( $sql, $name, (string) time() ) ); // phpcs:ignore
	}

	private static function unlock( $mapping_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK . $mapping_id ) );
	}

	/* =====================================================================
	 * Zustand: Nextcloud-Pfad → Slide + Attachment (+ Fingerprint, Inhalts-Hash)
	 * ===================================================================== */

	private static function load_state( $mapping_id ) {
		$state = get_option( 'rsnc_slides_' . $mapping_id, array() );
		$state = is_array( $state ) ? $state : array();
		foreach ( $state as $k => $e ) {
			// Einträge älterer Versionen ergänzen.
			$state[ $k ] = wp_parse_args(
				(array) $e,
				array(
					'slide_id'      => 0,
					'attachment_id' => 0,
					'fp'            => ! empty( $e['etag'] ) ? 'e:' . $e['etag'] : '',
					'hash'          => '',
				)
			);
		}
		return $state;
	}

	private static function save_state( $mapping_id, array $state ) {
		update_option( 'rsnc_slides_' . $mapping_id, $state, false );
	}

	/** Fingerprint ohne Download: ETag, ersatzweise Größe + Änderungszeit. */
	private static function fingerprint( array $file ) {
		return '' !== $file['etag'] ? 'e:' . $file['etag'] : 's:' . $file['size'] . ':' . $file['mtime'];
	}

	private static function managed_slide_ids( array $state ) {
		$ids = array();
		foreach ( $state as $e ) {
			if ( ! empty( $e['slide_id'] ) ) {
				$ids[] = (int) $e['slide_id'];
			}
		}
		return $ids;
	}

	private static function attachment_ok( $att_id ) {
		return $att_id && 'attachment' === get_post_type( $att_id );
	}

	/** Inhalts-Hash (SHA-1) eines Eintrags – bei älteren Einträgen aus der lokalen Datei berechnen. */
	private static function ensure_hash( array &$entry ) {
		if ( '' !== $entry['hash'] || ! self::attachment_ok( $entry['attachment_id'] ) ) {
			return $entry['hash'];
		}
		$hash = get_post_meta( $entry['attachment_id'], '_rsnc_hash', true );
		if ( ! $hash ) {
			$path = self::original_path( $entry['attachment_id'] );
			$hash = ( $path && is_readable( $path ) ) ? sha1_file( $path ) : '';
			if ( $hash ) {
				update_post_meta( $entry['attachment_id'], '_rsnc_hash', $hash );
			}
		}
		$entry['hash'] = (string) $hash;
		return $entry['hash'];
	}

	private static function original_path( $att_id ) {
		$p = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $att_id ) : false;
		return $p ? $p : get_attached_file( $att_id );
	}

	/* =====================================================================
	 * Abgleich
	 * ===================================================================== */

	/**
	 * Eine Zuordnung synchronisieren.
	 *
	 * @return array Ergebnis
	 */
	public static function run( $mapping_id ) {
		$result = array(
			'pending'    => 0,
			'total'      => 0,
			'busy'       => false,
			'added'      => 0,
			'updated'    => 0,
			'removed'    => 0,
			'unchanged'  => 0,
			'renamed'    => 0,
			'duplicates' => array(),
			'cleaned'    => 0,
			'errors'     => array(),
			'time'       => time(),
		);

		$m = RSNC_Settings::get_mapping( $mapping_id );
		if ( ! $m ) {
			$result['errors'][] = 'Zuordnung nicht gefunden.';
			return $result;
		}
		$deadline = self::deadline();
		if ( $deadline && microtime( true ) > $deadline ) {
			// Zeitbudget dieses Aufrufs schon verbraucht (z. B. mehrere Zuordnungen im Cron).
			$result['pending'] = -1;
			return $result;
		}
		if ( ! self::lock( $mapping_id ) ) {
			$result['busy']     = true;
			$result['errors'][] = __( 'Ein Abgleich läuft bereits.', 'rs-nextcloud' );
			return $result;
		}
		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
			self::$shutdown_registered = true;
		}
		self::$running = $mapping_id;
		self::$current = null;

		try {
			if ( ! rsnc_revslider_active() || ! self::tables_ok() ) {
				throw new RuntimeException( __( 'Slider Revolution 6 ist nicht aktiv oder seine Tabellen fehlen.', 'rs-nextcloud' ) );
			}
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'image' );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$slider_id = (int) $m['slider_id'];
			if ( ! $slider_id ) {
				throw new RuntimeException( __( 'Kein Ziel-Slider ausgewählt.', 'rs-nextcloud' ) );
			}

			// Liste zuerst holen – schlägt sie fehl, wird nichts verändert.
			$client = new RSNC_Nextcloud_Client( $m['share_url'], $m['password'] );
			$files  = array();
			foreach ( self::sort_files( $client->list_images( $m['subfolder'] ), $m['sort'] ) as $f ) {
				$files[ $f['path'] ] = $f; // Pfad ist eindeutig
			}
			if ( (int) $m['limit'] > 0 ) {
				$files = array_slice( $files, 0, (int) $m['limit'], true );
			}

			// Zustand mit dem tatsächlichen Slider abgleichen (verlorene Zuordnungen,
			// doppelte Slides aus abgebrochenen Läufen).
			$state = self::reconcile( $slider_id, $mapping_id, $m, self::load_state( $mapping_id ), $result );
			self::save_state( $mapping_id, $state );

			$template = self::get_template( $slider_id, (int) $m['template_slide_id'], $state );
			$tpl_hash = md5( RSNC_VERSION . '|' . wp_json_encode( $template['params'] ) . '|' . $template['layers'] . '|' . $template['settings'] );
			$rebuild  = get_option( 'rsnc_tplhash_' . $mapping_id ) !== $tpl_hash;
			$order    = self::max_unmanaged_order( $slider_id, $state );

			// Hash-Index: Inhalt → Pfad (erkennt identische Fotos unter anderem Namen).
			$by_hash = array();
			foreach ( $state as $k => $e ) {
				if ( empty( $e['duplicate_of'] ) && self::ensure_hash( $state[ $k ] ) ) {
					$by_hash[ $state[ $k ]['hash'] ] = $k;
				}
			}
			self::save_state( $mapping_id, $state );

			$failed       = get_option( 'rsnc_failed_' . $mapping_id, array() );
			$failed       = is_array( $failed ) ? $failed : array();
			$failed_dirty = false;
			$slowest      = 0.0;
			$done         = 0;
			$result['total'] = count( $files );

			foreach ( $files as $key => $file ) {
				// Zeitbudget: aufhören, bevor das nächste (aufwendige) Bild das Limit sprengt.
				if ( $deadline && $done > 0 && microtime( true ) + max( 1.0, $slowest * 1.5 ) > $deadline ) {
					$result['pending'] = count( $files ) - $done;
					break;
				}
				$done++;

				// Bild, an dem PHP schon zweimal abgestürzt ist → überspringen, bis es sich ändert.
				$fp = self::fingerprint( $file );
				if ( isset( $failed[ $key ] ) ) {
					// Nach 6 Stunden gibt es einen neuen Versuch (z. B. nachdem der Hoster Limits erhöht hat).
					if ( $failed[ $key ]['fp'] === $fp && $failed[ $key ]['n'] >= 2 && time() - (int) ( $failed[ $key ]['time'] ?? 0 ) < 6 * HOUR_IN_SECONDS ) {
						/* translators: %s: reason */
						$result['errors'][] = sprintf( __( '„%1$s“ übersprungen (zweimal abgebrochen): %2$s', 'rs-nextcloud' ), $file['name'], $failed[ $key ]['msg'] );
						continue;
					}
				}

				self::$current = array(
					'path' => $key,
					'name' => $file['name'],
					'fp'   => $fp,
				);
				$t0 = microtime( true );
				try {
					$changed = self::process_file( $key, $file, $files, $state, $by_hash, $client, $template, $m, $mapping_id, $slider_id, $rebuild, $order, $result );
					if ( $changed ) {
						self::save_state( $mapping_id, $state ); // nach jedem Bild sichern
					}
					if ( isset( $failed[ $key ] ) ) {
						unset( $failed[ $key ] );
						$failed_dirty = true;
					}
				} catch ( Exception $e ) {
					$result['errors'][] = $file['name'] . ': ' . $e->getMessage();
				}
				self::$current = null;
				$slowest       = max( $slowest, microtime( true ) - $t0 );
			}
			if ( $failed_dirty ) {
				update_option( 'rsnc_failed_' . $mapping_id, $failed, false );
			}

			if ( $result['pending'] > 0 ) {
				// Etappe beendet – Aufräumen erst, wenn alle Bilder verarbeitet sind.
				self::save_state( $mapping_id, $state );
				throw new RSNC_Batch_Pause();
			}

			// Duplikate erneut prüfen, deren Original sich in diesem Lauf geändert hat.
			foreach ( $files as $key => $file ) {
				$e = $state[ $key ] ?? null;
				if ( $e && ! empty( $e['duplicate_of'] ) && isset( $state[ $e['duplicate_of'] ] )
					&& $state[ $e['duplicate_of'] ]['hash'] !== $e['hash'] ) {
					$result['duplicates'] = array_values( array_filter( $result['duplicates'], function ( $d ) use ( $file ) {
						return 0 !== strpos( $d, $file['name'] . ' = ' );
					} ) );
					try {
						self::process_file( $key, $file, $files, $state, $by_hash, $client, $template, $m, $mapping_id, $slider_id, $rebuild, $order, $result );
						self::save_state( $mapping_id, $state );
					} catch ( Exception $ex ) {
						$result['errors'][] = $file['name'] . ': ' . $ex->getMessage();
					}
				}
			}

			// Aus Nextcloud entfernte Bilder.
			foreach ( $state as $key => $entry ) {
				if ( isset( $files[ $key ] ) ) {
					continue;
				}
				if ( ! empty( $entry['duplicate_of'] ) ) {
					unset( $state[ $key ] );
					continue;
				}
				if ( ! empty( $m['delete_removed'] ) ) {
					self::delete_entry( $entry );
					unset( $state[ $key ] );
					$result['removed']++;
				}
			}
			self::save_state( $mapping_id, $state );

			// Verwaiste Bilder dieser Zuordnung (z. B. aus abgebrochenen Läufen) entfernen.
			$result['cleaned'] += self::cleanup_attachments( $mapping_id, $state );

			if ( ! $result['errors'] ) {
				update_option( 'rsnc_tplhash_' . $mapping_id, $tpl_hash, false );
			}
			do_action( 'rsnc_after_sync', $mapping_id, $result );
		} catch ( RSNC_Batch_Pause $e ) { // phpcs:ignore -- Etappe beendet, kein Fehler
		} catch ( Exception $e ) {
			$result['errors'][] = $e->getMessage();
		}

		self::$running = null;
		self::$current = null;
		self::unlock( $mapping_id );
		RSNC_Settings::set_status( $mapping_id, $result );
		return $result;
	}

	/**
	 * Ein Bild verarbeiten. Gibt true zurück, wenn sich der Zustand geändert hat.
	 */
	private static function process_file( $key, array $file, array $files, array &$state, array &$by_hash, $client, array $template, array $m, $mapping_id, $slider_id, $rebuild, &$order, array &$result ) {
		$fp         = self::fingerprint( $file );
		$entry      = isset( $state[ $key ] ) ? $state[ $key ] : null;
		$known_hash = '';

		// 1) Bekanntes Duplikat, das sich nicht geändert hat → nichts herunterladen.
		if ( $entry && ! empty( $entry['duplicate_of'] ) ) {
			$orig = $entry['duplicate_of'];
			if ( $entry['fp'] === $fp && isset( $files[ $orig ], $state[ $orig ] ) && empty( $state[ $orig ]['duplicate_of'] )
				&& ( '' === $entry['hash'] || $state[ $orig ]['hash'] === $entry['hash'] ) ) {
				$result['duplicates'][] = $file['name'] . ' = ' . $files[ $orig ]['name'];
				return false;
			}
			// Original weg oder geändert: Inhalt dieser Datei ist bekannt, solange sie unverändert ist.
			if ( $entry['fp'] === $fp ) {
				$known_hash = (string) $entry['hash'];
			}
			unset( $state[ $key ] );
			$entry = null;
		}

		$order++;

		// 2) Bekanntes Bild.
		if ( $entry && self::attachment_ok( $entry['attachment_id'] ) ) {
			$att       = (int) $entry['attachment_id'];
			$touched   = false;
			$slide_ok  = $entry['slide_id'] && self::slide_exists( $entry['slide_id'] );
			$is_update = false;

			if ( $entry['fp'] !== $fp ) {
				// Fingerprint geändert → laden und Inhalt vergleichen.
				$tmp  = $client->download( $file );
				$hash = sha1_file( $tmp );
				if ( $hash === self::ensure_hash( $entry ) ) {
					@unlink( $tmp ); // phpcs:ignore -- gleicher Inhalt, nur Metadaten in Nextcloud geändert
				} elseif ( isset( $by_hash[ $hash ] ) && $by_hash[ $hash ] !== $key && isset( $files[ $by_hash[ $hash ] ] ) ) {
					// Inhalt ist jetzt identisch mit einem anderen Foto → diese Slide entfernen.
					@unlink( $tmp ); // phpcs:ignore
					unset( $by_hash[ $entry['hash'] ] );
					self::delete_entry( $entry );
					$state[ $key ] = array( 'duplicate_of' => $by_hash[ $hash ], 'fp' => $fp, 'hash' => $hash, 'slide_id' => 0, 'attachment_id' => 0 );
					$result['duplicates'][] = $file['name'] . ' = ' . $files[ $by_hash[ $hash ] ]['name'];
					$order--;
					return true;
				} else {
					unset( $by_hash[ $entry['hash'] ] );
					self::replace_file( $att, $tmp, $file['name'], $mapping_id );
					$entry['hash']     = $hash;
					$by_hash[ $hash ]  = $key;
					$is_update         = true;
				}
				$entry['fp'] = $fp;
				self::tag_attachment( $att, $mapping_id, $file, $fp, $entry['hash'] );
				$touched = true;
			}

			// Ältere Importe auf den Originaldateinamen umstellen (lokal, ohne Download).
			if ( ! $is_update && ! self::has_clean_location( $att, $file['name'], $mapping_id ) ) {
				self::relocate( $att, $file['name'], $mapping_id );
				$result['renamed']++;
				$touched = true;
			}

			if ( ! $slide_ok ) {
				$entry['slide_id'] = self::create_slide( $slider_id, $template, $file, $att, $m, $order, $mapping_id, $key );
				$result['added']++;
			} elseif ( $is_update || $touched || $rebuild ) {
				self::update_slide( $entry['slide_id'], $template, $file, $att, $m, $order, $mapping_id, $key );
				$result[ $is_update ? 'updated' : 'unchanged' ]++;
			} else {
				self::set_order( $entry['slide_id'], $order );
				$result['unchanged']++;
			}
			$state[ $key ] = $entry;
			return $touched || ! $slide_ok;
		}

		// 3) Neues Bild (oder Attachment fehlt). Zuerst eine passende Mediendatei suchen,
		//    die schon importiert wurde (z. B. aus einem abgebrochenen Lauf) → kein Download.
		$old_slide = ( $entry && $entry['slide_id'] && self::slide_exists( $entry['slide_id'] ) ) ? (int) $entry['slide_id'] : 0;
		$att       = self::find_orphan_attachment( $mapping_id, $key, $fp, $state );
		$hash      = $att ? (string) get_post_meta( $att, '_rsnc_hash', true ) : '';
		$tmp       = null;

		if ( ! $att && $known_hash ) {
			$hash = $known_hash; // Download nur, falls wirklich neu importiert werden muss
		} elseif ( ! $att ) {
			$tmp  = $client->download( $file );
			$hash = sha1_file( $tmp );
		}

		if ( $hash && isset( $by_hash[ $hash ] ) && $by_hash[ $hash ] !== $key ) {
			$other = $by_hash[ $hash ];
			if ( isset( $files[ $other ] ) ) {
				// Gleiches Foto liegt schon unter anderem Namen im Slider → nicht doppelt laden.
				if ( $tmp ) {
					@unlink( $tmp ); // phpcs:ignore
				}
				if ( $old_slide ) {
					self::delete_slide( $old_slide );
				}
				$state[ $key ] = array( 'duplicate_of' => $other, 'fp' => $fp, 'hash' => $hash, 'slide_id' => 0, 'attachment_id' => 0 );
				$result['duplicates'][] = $file['name'] . ' = ' . $files[ $other ]['name'];
				$order--;
				return true;
			}
			// Datei wurde in Nextcloud umbenannt/verschoben → bestehende Slide übernehmen.
			$moved = $state[ $other ];
			unset( $state[ $other ] );
			if ( $tmp ) {
				self::replace_file( (int) $moved['attachment_id'], $tmp, $file['name'], $mapping_id );
			} else {
				if ( $att && $att !== (int) $moved['attachment_id'] ) {
					wp_delete_attachment( $att, true );
				}
				if ( ! self::has_clean_location( (int) $moved['attachment_id'], $file['name'], $mapping_id ) ) {
					self::relocate( (int) $moved['attachment_id'], $file['name'], $mapping_id );
				}
			}
			$att = (int) $moved['attachment_id'];
			self::tag_attachment( $att, $mapping_id, $file, $fp, $hash );
			if ( $old_slide && $old_slide !== (int) $moved['slide_id'] ) {
				self::delete_slide( $old_slide );
			}
			if ( $moved['slide_id'] && self::slide_exists( $moved['slide_id'] ) ) {
				self::update_slide( $moved['slide_id'], $template, $file, $att, $m, $order, $mapping_id, $key );
			} else {
				$moved['slide_id'] = self::create_slide( $slider_id, $template, $file, $att, $m, $order, $mapping_id, $key );
			}
			$state[ $key ]    = array_merge( $moved, array( 'fp' => $fp, 'hash' => $hash ) );
			$by_hash[ $hash ] = $key;
			$result['renamed']++;
			return true;
		}

		if ( ! $att ) {
			if ( ! $tmp ) {
				$tmp = $client->download( $file );
				$hash = sha1_file( $tmp );
			}
			$att = self::import_new( $tmp, $file['name'], $mapping_id );
		} elseif ( ! self::has_clean_location( $att, $file['name'], $mapping_id ) ) {
			self::relocate( $att, $file['name'], $mapping_id );
		}
		if ( ! $hash ) {
			$hash = sha1_file( self::original_path( $att ) );
		}
		self::tag_attachment( $att, $mapping_id, $file, $fp, $hash );

		if ( $old_slide ) {
			self::update_slide( $old_slide, $template, $file, $att, $m, $order, $mapping_id, $key );
			$slide_id = $old_slide;
			$result['updated']++;
		} else {
			$slide_id = self::create_slide( $slider_id, $template, $file, $att, $m, $order, $mapping_id, $key );
			$result['added']++;
		}
		$state[ $key ]    = array(
			'slide_id'      => $slide_id,
			'attachment_id' => $att,
			'fp'            => $fp,
			'hash'          => $hash,
		);
		$by_hash[ $hash ] = $key;
		return true;
	}

	/**
	 * Zustand mit den Slides im Slider abgleichen:
	 *  - Slides mit Kennung dieser Zuordnung, die im Zustand fehlen → übernehmen
	 *  - Mehrere Slides für dasselbe Foto → nur eine behalten
	 */
	private static function reconcile( $slider_id, $mapping_id, array $m, array $state, array &$result ) {
		global $wpdb;
		$template_id = (int) $m['template_slide_id'];
		$share       = self::share_key( $m );
		$existing    = RSNC_Settings::get_mappings();
		$t    = self::slides_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, params FROM $t WHERE slider_id = %d ORDER BY slide_order ASC, id ASC", $slider_id ), ARRAY_A ); // phpcs:ignore

		$by_source = array();
		foreach ( $rows as $r ) {
			$id = (int) $r['id'];
			if ( $id === $template_id ) {
				continue;
			}
			$p      = json_decode( $r['params'], true );
			$source = null;
			$att    = (int) ( $p['bg']['imageId'] ?? 0 );
			if ( isset( $p['rsnc']['mapping'] ) ) {
				// Slides einer gelöschten Zuordnung für dieselbe Freigabe werden übernommen
				// (z. B. nach Neuanlage der Zuordnung oder Neuinstallation).
				$owner   = (string) $p['rsnc']['mapping'];
				$is_ours = $owner === $mapping_id
					|| ( ! isset( $existing[ $owner ] ) && ( $p['rsnc']['share'] ?? '' ) === $share );
				// Im Editor kopierte Slides (andere ID): unveröffentlicht = Vorlage → nicht anfassen;
				// veröffentlicht = dasselbe Foto ein zweites Mal → als Duplikat behandeln.
				$published = 'published' === ( $p['publish']['state'] ?? 'published' );
				if ( $is_ours && ( (int) ( $p['rsnc']['slide'] ?? 0 ) === $id || $published ) ) {
					$source = (string) $p['rsnc']['source'];
				}
			} elseif ( $att && 'published' === ( $p['publish']['state'] ?? 'published' ) && get_post_meta( $att, '_rsnc_mapping', true ) === $mapping_id ) {
				$source = (string) get_post_meta( $att, '_rsnc_source', true ); // Slides aus Version < 1.3
			}
			if ( null !== $source && '' !== $source ) {
				$by_source[ $source ][] = array( 'slide' => $id, 'att' => $att );
			}
		}

		foreach ( $by_source as $source => $slides ) {
			$keep = null;
			if ( isset( $state[ $source ] ) ) {
				foreach ( $slides as $s ) {
					if ( $s['slide'] === (int) $state[ $source ]['slide_id'] ) {
						$keep = $s;
					}
				}
			}
			if ( ! $keep ) {
				$keep = $slides[0];
				if ( isset( $state[ $source ] ) && empty( $state[ $source ]['duplicate_of'] ) && ! self::slide_exists( $state[ $source ]['slide_id'] ) ) {
					$state[ $source ]['slide_id'] = $keep['slide'];
				} elseif ( ! isset( $state[ $source ] ) && self::attachment_ok( $keep['att'] ) ) {
					$etag             = (string) get_post_meta( $keep['att'], '_rsnc_etag', true );
					$fp               = (string) get_post_meta( $keep['att'], '_rsnc_fp', true );
					$state[ $source ] = array(
						'slide_id'      => $keep['slide'],
						'attachment_id' => $keep['att'],
						'fp'            => $fp ? $fp : ( $etag ? 'e:' . $etag : '' ),
						'hash'          => (string) get_post_meta( $keep['att'], '_rsnc_hash', true ),
					);
					update_post_meta( $keep['att'], '_rsnc_mapping', $mapping_id );
				} elseif ( ! isset( $state[ $source ] ) ) {
					// Slide ohne (gültige) Mediendatei → Slide behalten, Bild wird neu geladen.
					$state[ $source ] = array(
						'slide_id'      => $keep['slide'],
						'attachment_id' => 0,
						'fp'            => '',
						'hash'          => '',
					);
				} else {
					$keep = array( 'slide' => -1, 'att' => 0 ); // Zustand ist maßgeblich
				}
			}
			foreach ( $slides as $s ) {
				if ( $s['slide'] === $keep['slide'] ) {
					continue;
				}
				self::delete_slide( $s['slide'] );
				$result['cleaned']++;
			}
		}
		return $state;
	}

	/** Mediendateien dieser Zuordnung, die keiner Slide mehr zugeordnet sind, löschen. */
	private static function cleanup_attachments( $mapping_id, array $state ) {
		$used = array();
		foreach ( $state as $e ) {
			$used[ (int) $e['attachment_id'] ] = true;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => '_rsnc_mapping', // phpcs:ignore
				'meta_value'     => $mapping_id, // phpcs:ignore
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		$n = 0;
		foreach ( $ids as $id ) {
			if ( empty( $used[ (int) $id ] ) ) {
				wp_delete_attachment( $id, true );
				$n++;
			}
		}
		return $n;
	}

	/** Bereits importierte, aber nicht zugeordnete Mediendatei für diesen Pfad suchen. */
	private static function find_orphan_attachment( $mapping_id, $source, $fp, array $state ) {
		$used = array();
		foreach ( $state as $e ) {
			$used[ (int) $e['attachment_id'] ] = true;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore
					array(
						'key'   => '_rsnc_mapping',
						'value' => $mapping_id,
					),
					array(
						'key'   => '_rsnc_source',
						'value' => $source,
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			if ( ! empty( $used[ (int) $id ] ) ) {
				continue;
			}
			$a_fp = (string) get_post_meta( $id, '_rsnc_fp', true );
			$etag = (string) get_post_meta( $id, '_rsnc_etag', true );
			if ( $a_fp === $fp || ( ! $a_fp && $etag && 'e:' . $etag === $fp ) ) {
				if ( ! get_post_meta( $id, '_rsnc_hash', true ) ) {
					$p = self::original_path( $id );
					if ( $p && is_readable( $p ) ) {
						update_post_meta( $id, '_rsnc_hash', sha1_file( $p ) );
					}
				}
				return (int) $id;
			}
		}
		return 0;
	}

	private static function delete_entry( array $entry ) {
		if ( ! empty( $entry['slide_id'] ) ) {
			self::delete_slide( $entry['slide_id'] );
		}
		if ( ! empty( $entry['attachment_id'] ) && self::attachment_ok( $entry['attachment_id'] ) ) {
			wp_delete_attachment( $entry['attachment_id'], true );
		}
	}

	/** Alle verwalteten Slides + Bilder einer Zuordnung entfernen. */
	public static function purge( $mapping_id ) {
		foreach ( self::load_state( $mapping_id ) as $entry ) {
			self::delete_entry( $entry );
		}
		self::cleanup_attachments( $mapping_id, array() );
		delete_option( 'rsnc_slides_' . $mapping_id );
		delete_option( 'rsnc_tplhash_' . $mapping_id );
	}

	private static function sort_files( array $files, $sort ) {
		usort(
			$files,
			function ( $a, $b ) use ( $sort ) {
				switch ( $sort ) {
					case 'name_desc':
						return strnatcasecmp( $b['name'], $a['name'] );
					case 'date_asc':
						return $a['mtime'] <=> $b['mtime'];
					case 'date_desc':
						return $b['mtime'] <=> $a['mtime'];
					default:
						return strnatcasecmp( $a['name'], $b['name'] );
				}
			}
		);
		if ( 'random' === $sort ) {
			shuffle( $files );
		}
		return $files;
	}

	/* =====================================================================
	 * Mediathek – Originaldateinamen beibehalten
	 *
	 * Bilder landen in uploads/nextcloud/{Zuordnung}/ unter ihrem Nextcloud-Namen.
	 * Der eigene Ordner verhindert Namenskonflikte (kein „-1“), die WordPress-
	 * Umbenennung (Leerzeichen → „-“) und das Verkleinern zu „-scaled“ sind
	 * während des Imports abgeschaltet.
	 * ===================================================================== */

	/** Nur Zeichen entfernen, die in Dateisystemen oder URLs nicht funktionieren. */
	public static function clean_name( $name ) {
		$name = wp_basename( str_replace( '\\', '/', (string) $name ) );
		$name = preg_replace( '/[\x00-\x1F\x7F\/\\\\:*?"<>|#%]/u', '', $name );
		$name = trim( $name, " .\t" );
		if ( '' === pathinfo( $name, PATHINFO_FILENAME ) ) {
			$name = 'bild-' . substr( md5( (string) microtime( true ) ), 0, 8 ) . ( $name ? $name : '.jpg' );
		}
		return $name;
	}

	/** Kennung der Freigabe (Link + Unterordner), um Slides gelöschter Zuordnungen zuzuordnen. */
	private static function share_key( array $m ) {
		return substr( md5( untrailingslashit( trim( $m['share_url'] ) ) . '|' . trim( (string) $m['subfolder'], '/' ) ), 0, 12 );
	}

	private static function subdir( $mapping_id ) {
		return '/nextcloud/' . sanitize_key( $mapping_id );
	}

	private static function target_path( $name, $mapping_id ) {
		$u = wp_get_upload_dir();
		return $u['basedir'] . self::subdir( $mapping_id ) . '/' . self::clean_name( $name );
	}

	private static function has_clean_location( $att_id, $name, $mapping_id ) {
		return wp_normalize_path( (string) get_attached_file( $att_id ) ) === wp_normalize_path( self::target_path( $name, $mapping_id ) );
	}

	/** Führt $fn mit den Import-Filtern (Ordner, Dateiname, keine Skalierung) aus. */
	private static function with_import_filters( $mapping_id, callable $fn ) {
		$sub   = self::subdir( $mapping_id );
		$dir   = function ( $d ) use ( $sub ) {
			$d['subdir'] = $sub;
			$d['path']   = $d['basedir'] . $sub;
			$d['url']    = $d['baseurl'] . $sub;
			return $d;
		};
		$name  = function ( $filename, $raw = '' ) {
			return self::clean_name( '' !== (string) $raw ? $raw : $filename );
		};
		$scale = '__return_false';
		add_filter( 'upload_dir', $dir, 999 );
		add_filter( 'sanitize_file_name', $name, 999, 2 );
		add_filter( 'big_image_size_threshold', $scale, 999 );
		try {
			return $fn();
		} finally {
			remove_filter( 'upload_dir', $dir, 999 );
			remove_filter( 'sanitize_file_name', $name, 999 );
			remove_filter( 'big_image_size_threshold', $scale, 999 );
		}
	}

	/** Zieldatei frei machen, falls dort eine nicht mehr zugeordnete Datei liegt. */
	private static function free_target( $target, $mapping_id, $except_att = 0 ) {
		if ( ! file_exists( $target ) ) {
			return;
		}
		$u   = wp_get_upload_dir();
		$rel = ltrim( substr( wp_normalize_path( $target ), strlen( wp_normalize_path( $u['basedir'] ) ) ), '/' );
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'no_found_rows'  => true,
				'meta_key'       => '_wp_attached_file', // phpcs:ignore
				'meta_value'     => $rel, // phpcs:ignore
			)
		);
		foreach ( $ids as $id ) {
			if ( (int) $id === (int) $except_att ) {
				continue;
			}
			if ( get_post_meta( $id, '_rsnc_mapping', true ) !== $mapping_id ) {
				return; // gehört nicht uns → nicht anfassen
			}
			wp_delete_attachment( $id, true );
		}
		if ( file_exists( $target ) ) {
			wp_delete_file( $target );
		}
	}

	/** Neues Bild aus einer temporären Datei in die Mediathek übernehmen. */
	private static function import_new( $tmp, $name, $mapping_id ) {
		$meta  = RSNC_Image_Meta::read( $tmp );
		$clean = self::clean_name( $name );
		self::free_target( self::target_path( $clean, $mapping_id ), $mapping_id );

		$att = self::with_import_filters(
			$mapping_id,
			function () use ( $tmp, $clean ) {
				return media_handle_sideload(
					array(
						'name'     => $clean,
						'tmp_name' => $tmp,
					),
					0,
					pathinfo( $clean, PATHINFO_FILENAME )
				);
			}
		);
		if ( is_wp_error( $att ) ) {
			@unlink( $tmp ); // phpcs:ignore
			throw new RuntimeException( $att->get_error_message() );
		}
		update_post_meta( $att, '_rsnc_mapping', $mapping_id );
		self::apply_meta( $att, $meta );
		return (int) $att;
	}

	/**
	 * Datei eines bestehenden Attachments ersetzen (gleiche ID, neuer Inhalt und/oder Name).
	 * Alte Datei und alle Zwischengrößen werden vorher entfernt – so entsteht kein „-1“.
	 */
	private static function replace_file( $att, $tmp, $name, $mapping_id ) {
		$meta   = RSNC_Image_Meta::read( $tmp );
		$target = self::target_path( $name, $mapping_id );
		wp_mkdir_p( dirname( $target ) );

		$old_dir  = dirname( (string) get_attached_file( $att ) );
		$old_meta = wp_get_attachment_metadata( $att );
		$backup   = get_post_meta( $att, '_wp_attachment_backup_sizes', true );
		wp_delete_attachment_files( $att, is_array( $old_meta ) ? $old_meta : array(), is_array( $backup ) ? $backup : array(), get_attached_file( $att ) );
		delete_post_meta( $att, '_wp_attachment_backup_sizes' );
		self::free_target( $target, $mapping_id, $att );
		if ( file_exists( $target ) ) {
			@unlink( $tmp ); // phpcs:ignore
			/* translators: %s: file name */
			throw new RuntimeException( sprintf( __( '%s ist im Upload-Ordner bereits durch eine andere Mediendatei belegt.', 'rs-nextcloud' ), wp_basename( $target ) ) );
		}

		if ( ! @rename( $tmp, $target ) ) { // phpcs:ignore
			if ( ! @copy( $tmp, $target ) ) { // phpcs:ignore
				@unlink( $tmp ); // phpcs:ignore
				throw new RuntimeException( __( 'Datei konnte nicht in den Upload-Ordner geschrieben werden.', 'rs-nextcloud' ) );
			}
			@unlink( $tmp ); // phpcs:ignore
		}
		$stat = @stat( dirname( $target ) ); // phpcs:ignore
		if ( $stat ) {
			@chmod( $target, $stat['mode'] & 0000666 ); // phpcs:ignore
		}

		update_attached_file( $att, $target );
		// Leer gewordenen alten Nextcloud-Ordner entfernen.
		if ( $old_dir !== dirname( $target ) && false !== strpos( wp_normalize_path( $old_dir ), '/nextcloud/' ) && is_dir( $old_dir ) && 2 === count( (array) @scandir( $old_dir ) ) ) { // phpcs:ignore
			@rmdir( $old_dir ); // phpcs:ignore
		}
		$type = wp_check_filetype( $target );
		wp_update_post(
			array(
				'ID'             => $att,
				'post_mime_type' => $type['type'] ? $type['type'] : get_post_mime_type( $att ),
				'post_title'     => pathinfo( $target, PATHINFO_FILENAME ),
			)
		);
		self::with_import_filters(
			$mapping_id,
			function () use ( $att, $target ) {
				wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $target ) );
			}
		);
		self::apply_meta( $att, $meta );
	}

	/** Bestehende Mediendatei lokal auf den Originalnamen im Nextcloud-Ordner umziehen. */
	private static function relocate( $att, $name, $mapping_id ) {
		$src = self::original_path( $att );
		if ( ! $src || ! is_readable( $src ) ) {
			return;
		}
		$tmp = wp_tempnam( $name );
		if ( ! @copy( $src, $tmp ) ) { // phpcs:ignore
			return;
		}
		self::replace_file( $att, $tmp, $name, $mapping_id );
	}

	private static function tag_attachment( $att, $mapping_id, array $file, $fp, $hash ) {
		update_post_meta( $att, '_rsnc_mapping', $mapping_id );
		update_post_meta( $att, '_rsnc_source', $file['path'] );
		update_post_meta( $att, '_rsnc_etag', $file['etag'] );
		update_post_meta( $att, '_rsnc_fp', $fp );
		if ( $hash ) {
			update_post_meta( $att, '_rsnc_hash', $hash );
		}
	}

	/** Metadaten speichern; Caption als Beschriftung & Alt-Text in der Mediathek. */
	private static function apply_meta( $att, array $meta ) {
		update_post_meta( $att, '_rsnc_meta', $meta );
		if ( '' !== $meta['caption'] ) {
			wp_update_post(
				array(
					'ID'           => $att,
					'post_excerpt' => $meta['caption'],
				)
			);
			if ( ! get_post_meta( $att, '_wp_attachment_image_alt', true ) ) {
				update_post_meta( $att, '_wp_attachment_image_alt', wp_strip_all_tags( $meta['caption'] ) );
			}
		}
	}

	/** Metadaten eines Attachments (gecacht; bei älteren Importen aus dem Original nachlesen). */
	private static function get_meta( $att_id ) {
		$meta = get_post_meta( $att_id, '_rsnc_meta', true );
		if ( is_array( $meta ) ) {
			return wp_parse_args( $meta, array_fill_keys( RSNC_Image_Meta::FIELDS, '' ) );
		}
		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $att_id ) : get_attached_file( $att_id );
		$meta = RSNC_Image_Meta::read( $path );
		update_post_meta( $att_id, '_rsnc_meta', $meta );
		return $meta;
	}

	/** Platzhalter {{nc_xyz}} bzw. {{nc_xyz|Fallback}} in der Ebenen-JSON ersetzen. */
	private static function replace_placeholders( $layers, array $vars ) {
		return preg_replace_callback(
			'/\{\{\s*(nc_[a-z_]+)\s*(?:\|([^}]*))?\}\}/',
			function ( $mt ) use ( $vars ) {
				if ( ! array_key_exists( $mt[1], $vars ) ) {
					return $mt[0]; // unbekannt → unverändert lassen
				}
				$val = $vars[ $mt[1] ];
				if ( '' === $val && isset( $mt[2] ) ) {
					$fb  = trim( json_decode( '"' . $mt[2] . '"' ) ?? $mt[2] );
					$val = array_key_exists( $fb, $vars ) ? $vars[ $fb ] : $fb;
				}
				$html = nl2br( esc_html( $val ), false );
				return substr( wp_json_encode( $html ), 1, -1 ); // JSON-sicher innerhalb des Strings
			},
			$layers
		);
	}

	private static function title_from_name( $name ) {
		return trim( preg_replace( '/[_\-]+/', ' ', pathinfo( $name, PATHINFO_FILENAME ) ) );
	}

	/** Vorlagen-Slide laden (gewählt oder erste nicht verwaltete Slide des Sliders). */
	private static function get_template( $slider_id, $template_id, array $state ) {
		global $wpdb;
		$t = self::slides_table();

		$row = null;
		if ( $template_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $template_id ), ARRAY_A ); // phpcs:ignore
		}
		if ( ! $row ) {
			$managed = self::managed_slide_ids( $state );
			$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE slider_id = %d ORDER BY slide_order ASC", $slider_id ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $r ) {
				$p = json_decode( $r['params'], true );
				if ( ! in_array( (int) $r['id'], $managed, true ) && empty( $p['rsnc'] ) ) {
					$row = $r;
					break;
				}
			}
		}

		if ( $row ) {
			$params = json_decode( $row['params'], true ) ?: array();
			unset( $params['rsnc'] ); // Vorlage darf keine Kennung weitergeben
			return array(
				'params'   => $params,
				'layers'   => (string) $row['layers'],
				'settings' => (string) $row['settings'],
			);
		}

		// Fallback: minimale Slide ohne Ebenen.
		return array(
			'params'   => array(
				'bg'      => array(
					'type'     => 'image',
					'fit'      => 'cover',
					'position' => 'center center',
					'repeat'   => 'no-repeat',
				),
				'publish' => array( 'state' => 'published' ),
			),
			'layers'   => '{}',
			'settings' => '{}',
		);
	}

	private static function build_row( array $template, array $file, $att_id, array $m, $mapping_id, $source, $slide_id ) {
		$size = in_array( $m['image_size'], get_intermediate_image_sizes(), true ) ? $m['image_size'] : 'full';
		$src  = wp_get_attachment_image_src( $att_id, $size );
		$url  = self::encode_url( $src ? $src[0] : wp_get_attachment_url( $att_id ) );
		$meta = wp_get_attachment_metadata( $att_id );

		$params = $template['params'];
		$params['title'] = self::title_from_name( $file['name'] );

		if ( ! isset( $params['bg'] ) || ! is_array( $params['bg'] ) ) {
			$params['bg'] = array();
		}
		$params['bg']['type']      = 'image';
		$params['bg']['image']     = $url;
		$params['bg']['imageId']   = $att_id;
		$params['bg']['imageLib']  = 'medialibrary';
		$params['bg']['imageWidth']  = isset( $meta['width'] ) ? (int) $meta['width'] : '';
		$params['bg']['imageHeight'] = isset( $meta['height'] ) ? (int) $meta['height'] : '';

		// Kennung: welches Nextcloud-Bild gehört zu dieser Slide (verhindert Duplikate).
		$params['rsnc'] = array(
			'mapping' => $mapping_id,
			'source'  => $source,
			'slide'   => (int) $slide_id,
			'share'   => self::share_key( $m ),
		);

		if ( ! isset( $params['publish'] ) || ! is_array( $params['publish'] ) ) {
			$params['publish'] = array();
		}
		$params['publish']['state'] = 'published';

		// Thumbnail für Navigation.
		$thumb = wp_get_attachment_image_src( $att_id, 'medium' );
		if ( $thumb ) {
			if ( ! isset( $params['thumb'] ) || ! is_array( $params['thumb'] ) ) {
				$params['thumb'] = array();
			}
			$params['thumb']['customThumbSrc']   = self::encode_url( $thumb[0] );
			$params['thumb']['customThumbSrcId'] = $att_id;
		}

		$params = apply_filters( 'rsnc_slide_params', $params, $file, $att_id, $m );

		// Platzhalter in Ebenen ersetzen (JSON-sicher).
		$meta = self::get_meta( $att_id );
		$vars = array(
			'nc_title'      => self::title_from_name( $file['name'] ),
			'nc_filename'   => $file['name'],
			'nc_date'       => $file['mtime'] ? date_i18n( get_option( 'date_format' ), $file['mtime'] ) : '',
			'nc_caption'    => $meta['caption'],
			'nc_headline'   => $meta['headline'],
			'nc_meta_title' => $meta['title'],
			'nc_creator'    => $meta['creator'],
			'nc_copyright'  => $meta['copyright'],
			'nc_date_taken' => RSNC_Image_Meta::format_date( $meta['date_taken'] ),
			'nc_camera'     => $meta['camera'],
		);
		$vars   = apply_filters( 'rsnc_placeholders', $vars, $file, $att_id, $m );
		$layers = self::replace_placeholders( $template['layers'], $vars );

		return array(
			'params'   => wp_json_encode( $params ),
			'layers'   => $layers,
			'settings' => $template['settings'],
		);
	}

	/** Dateinamen mit Leerzeichen/Umlauten in URLs korrekt kodieren. */
	private static function encode_url( $url ) {
		$pos = strrpos( $url, '/' );
		if ( false === $pos ) {
			return $url;
		}
		return substr( $url, 0, $pos + 1 ) . rawurlencode( rawurldecode( substr( $url, $pos + 1 ) ) );
	}

	private static function create_slide( $slider_id, array $template, array $file, $att_id, array $m, $order, $mapping_id, $source ) {
		global $wpdb;
		$ok = $wpdb->insert(
			self::slides_table(),
			array(
				'slider_id'   => $slider_id,
				'slide_order' => $order,
				'params'      => '{}',
				'layers'      => '{}',
				'settings'    => '{}',
			)
		);
		if ( false === $ok ) {
			throw new RuntimeException( __( 'Slide konnte nicht angelegt werden: ', 'rs-nextcloud' ) . $wpdb->last_error );
		}
		$slide_id = (int) $wpdb->insert_id;
		self::update_slide( $slide_id, $template, $file, $att_id, $m, $order, $mapping_id, $source );
		return $slide_id;
	}

	private static function update_slide( $slide_id, array $template, array $file, $att_id, array $m, $order, $mapping_id, $source ) {
		global $wpdb;
		$row                = self::build_row( $template, $file, $att_id, $m, $mapping_id, $source, $slide_id );
		$row['slide_order'] = $order;
		$wpdb->update( self::slides_table(), $row, array( 'id' => (int) $slide_id ) );
	}

	private static function set_order( $slide_id, $order ) {
		global $wpdb;
		$wpdb->update( self::slides_table(), array( 'slide_order' => $order ), array( 'id' => (int) $slide_id ) );
	}

	private static function slide_exists( $slide_id ) {
		global $wpdb;
		$t = self::slides_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d", $slide_id ) ); // phpcs:ignore
	}

	private static function delete_slide( $slide_id ) {
		global $wpdb;
		$wpdb->delete( self::slides_table(), array( 'id' => (int) $slide_id ) );
	}

	/** Höchste slide_order der nicht verwalteten Slides – verwaltete kommen danach. */
	private static function max_unmanaged_order( $slider_id, array $state ) {
		global $wpdb;
		$t   = self::slides_table();
		$ids = self::managed_slide_ids( $state );
		$sql = $wpdb->prepare( "SELECT MAX(slide_order) FROM $t WHERE slider_id = %d", $slider_id ); // phpcs:ignore
		if ( $ids ) {
			$sql .= ' AND id NOT IN (' . implode( ',', $ids ) . ')';
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore
	}

	/** Für die Admin-Oberfläche: Slider & Slides von Slider Revolution. */
	public static function get_sliders() {
		global $wpdb;
		$t = $wpdb->prefix . 'revslider_sliders';
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
			return array();
		}
		return $wpdb->get_results( "SELECT id, title, alias FROM $t WHERE type != 'template' OR type IS NULL ORDER BY title", ARRAY_A ); // phpcs:ignore
	}

	public static function get_slides( $slider_id ) {
		global $wpdb;
		if ( ! self::tables_ok() ) {
			return array();
		}
		$t    = self::slides_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, slide_order, params FROM $t WHERE slider_id = %d ORDER BY slide_order", $slider_id ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			$p     = json_decode( $r['params'], true );
			$out[] = array(
				'id'    => (int) $r['id'],
				'title' => ! empty( $p['title'] ) ? $p['title'] : '#' . $r['id'],
			);
		}
		return $out;
	}
}
