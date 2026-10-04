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
class RSNC_Sync {

	const LOCK = 'rsnc_sync_lock_';

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
				self::run( $id );
			}
		}
	}

	/**
	 * Eine Zuordnung synchronisieren.
	 *
	 * @return array Ergebnis (added, updated, removed, unchanged, errors[])
	 */
	public static function run( $mapping_id ) {
		$result = array(
			'added'     => 0,
			'updated'   => 0,
			'removed'   => 0,
			'unchanged' => 0,
			'errors'    => array(),
			'time'      => time(),
		);

		$m = RSNC_Settings::get_mapping( $mapping_id );
		if ( ! $m ) {
			$result['errors'][] = 'Zuordnung nicht gefunden.';
			return $result;
		}

		if ( get_transient( self::LOCK . $mapping_id ) ) {
			$result['errors'][] = __( 'Ein Abgleich läuft bereits.', 'rs-nextcloud' );
			return $result;
		}
		set_transient( self::LOCK . $mapping_id, 1, 15 * MINUTE_IN_SECONDS );

		try {
			if ( ! rsnc_revslider_active() || ! self::tables_ok() ) {
				throw new RuntimeException( __( 'Slider Revolution 6 ist nicht aktiv oder seine Tabellen fehlen.', 'rs-nextcloud' ) );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 600 ); // phpcs:ignore
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$client = new RSNC_Nextcloud_Client( $m['share_url'], $m['password'] );
			$files  = self::sort_files( $client->list_images( $m['subfolder'] ), $m['sort'] );
			if ( (int) $m['limit'] > 0 ) {
				$files = array_slice( $files, 0, (int) $m['limit'] );
			}

			$slider_id = (int) $m['slider_id'];
			$template  = self::get_template( $slider_id, (int) $m['template_slide_id'], $mapping_id );
			$state     = get_option( 'rsnc_slides_' . $mapping_id, array() );
			$state     = is_array( $state ) ? $state : array();
			$seen      = array();
			$order     = self::max_unmanaged_order( $slider_id, $state );

			// Vorlage oder Add-on-Version geändert → auch unveränderte Bilder neu aufbauen.
			$tpl_hash  = md5( RSNC_VERSION . '|' . wp_json_encode( $template['params'] ) . '|' . $template['layers'] . '|' . $template['settings'] );
			$rebuild   = get_option( 'rsnc_tplhash_' . $mapping_id ) !== $tpl_hash;

			foreach ( $files as $file ) {
				$key          = $file['path'];
				$seen[ $key ] = true;
				$order++;
				try {
					$entry = isset( $state[ $key ] ) ? $state[ $key ] : null;
					if ( $entry && self::slide_exists( $entry['slide_id'] ) && get_post( $entry['attachment_id'] ) ) {
						if ( $entry['etag'] === $file['etag'] && '' !== $file['etag'] ) {
							if ( $rebuild ) {
								self::update_slide( $entry['slide_id'], $template, $file, $entry['attachment_id'], $m, $order );
							} else {
								self::set_order( $entry['slide_id'], $order );
							}
							$result['unchanged']++;
							continue;
						}
						// Datei geändert → neues Attachment, Slide aktualisieren.
						$att = self::import( $client, $file, $mapping_id );
						self::update_slide( $entry['slide_id'], $template, $file, $att, $m, $order );
						wp_delete_attachment( $entry['attachment_id'], true );
						$state[ $key ] = array(
							'slide_id'      => $entry['slide_id'],
							'attachment_id' => $att,
							'etag'          => $file['etag'],
						);
						$result['updated']++;
						continue;
					}
					// Neu (oder Slide wurde im Editor gelöscht).
					if ( $entry && get_post( $entry['attachment_id'] ) ) {
						wp_delete_attachment( $entry['attachment_id'], true );
					}
					$att      = self::import( $client, $file, $mapping_id );
					$slide_id = self::create_slide( $slider_id, $template, $file, $att, $m, $order );
					$state[ $key ] = array(
						'slide_id'      => $slide_id,
						'attachment_id' => $att,
						'etag'          => $file['etag'],
					);
					$result['added']++;
				} catch ( Exception $e ) {
					$result['errors'][] = $file['name'] . ': ' . $e->getMessage();
				}
			}

			// Entfernte Bilder.
			foreach ( $state as $key => $entry ) {
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				if ( ! empty( $m['delete_removed'] ) ) {
					self::delete_slide( $entry['slide_id'] );
					if ( ! empty( $entry['attachment_id'] ) ) {
						wp_delete_attachment( $entry['attachment_id'], true );
					}
					unset( $state[ $key ] );
					$result['removed']++;
				}
			}

			update_option( 'rsnc_slides_' . $mapping_id, $state, false );
			if ( ! $result['errors'] ) {
				update_option( 'rsnc_tplhash_' . $mapping_id, $tpl_hash, false );
			}
			do_action( 'rsnc_after_sync', $mapping_id, $result );
		} catch ( Exception $e ) {
			$result['errors'][] = $e->getMessage();
		}

		delete_transient( self::LOCK . $mapping_id );
		RSNC_Settings::set_status( $mapping_id, $result );
		return $result;
	}

	/** Alle verwalteten Slides + Bilder einer Zuordnung entfernen. */
	public static function purge( $mapping_id ) {
		$state = get_option( 'rsnc_slides_' . $mapping_id, array() );
		foreach ( (array) $state as $entry ) {
			self::delete_slide( $entry['slide_id'] );
			if ( ! empty( $entry['attachment_id'] ) ) {
				wp_delete_attachment( $entry['attachment_id'], true );
			}
		}
		delete_option( 'rsnc_slides_' . $mapping_id );
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

	/** Bild herunterladen und in die Mediathek übernehmen. */
	private static function import( RSNC_Nextcloud_Client $client, array $file, $mapping_id ) {
		$tmp  = $client->download( $file );
		// Metadaten vor dem Import lesen – WP kann beim Skalieren Metadaten entfernen.
		$meta = RSNC_Image_Meta::read( $tmp );
		$att  = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( $file['name'] ),
				'tmp_name' => $tmp,
			),
			0,
			self::title_from_name( $file['name'] )
		);
		if ( is_wp_error( $att ) ) {
			@unlink( $tmp ); // phpcs:ignore
			throw new RuntimeException( $att->get_error_message() );
		}
		update_post_meta( $att, '_rsnc_mapping', $mapping_id );
		update_post_meta( $att, '_rsnc_source', $file['path'] );
		update_post_meta( $att, '_rsnc_etag', $file['etag'] );
		update_post_meta( $att, '_rsnc_meta', $meta );

		// Caption & Alt-Text auch in der Mediathek setzen.
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
		return (int) $att;
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
	private static function get_template( $slider_id, $template_id, $mapping_id ) {
		global $wpdb;
		$t = self::slides_table();

		if ( ! $slider_id ) {
			throw new RuntimeException( __( 'Kein Ziel-Slider ausgewählt.', 'rs-nextcloud' ) );
		}

		$row = null;
		if ( $template_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $template_id ), ARRAY_A ); // phpcs:ignore
		}
		if ( ! $row ) {
			$managed = wp_list_pluck( (array) get_option( 'rsnc_slides_' . $mapping_id, array() ), 'slide_id' );
			$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE slider_id = %d ORDER BY slide_order ASC", $slider_id ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $r ) {
				if ( ! in_array( (int) $r['id'], array_map( 'intval', $managed ), true ) ) {
					$row = $r;
					break;
				}
			}
		}

		if ( $row ) {
			return array(
				'params'   => json_decode( $row['params'], true ) ?: array(),
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

	private static function build_row( array $template, array $file, $att_id, array $m ) {
		$size = in_array( $m['image_size'], get_intermediate_image_sizes(), true ) ? $m['image_size'] : 'full';
		$src  = wp_get_attachment_image_src( $att_id, $size );
		$url  = $src ? $src[0] : wp_get_attachment_url( $att_id );
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
			$params['thumb']['customThumbSrc']   = $thumb[0];
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

	private static function create_slide( $slider_id, array $template, array $file, $att_id, array $m, $order ) {
		global $wpdb;
		$row                = self::build_row( $template, $file, $att_id, $m );
		$row['slider_id']   = $slider_id;
		$row['slide_order'] = $order;
		if ( false === $wpdb->insert( self::slides_table(), $row ) ) {
			throw new RuntimeException( __( 'Slide konnte nicht angelegt werden: ', 'rs-nextcloud' ) . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	private static function update_slide( $slide_id, array $template, array $file, $att_id, array $m, $order ) {
		global $wpdb;
		$row                = self::build_row( $template, $file, $att_id, $m );
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
		$ids = array_map( 'intval', wp_list_pluck( $state, 'slide_id' ) );
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
