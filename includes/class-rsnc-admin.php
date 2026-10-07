<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-Oberfläche unter „Slider Revolution → Nextcloud“.
 */
class RSNC_Admin {

	const SLUG = 'rsnc-nextcloud';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'admin_post_rsnc_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_rsnc_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_rsnc_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_rsnc_interval', array( __CLASS__, 'handle_interval' ) );
		add_action( 'admin_post_rsnc_github', array( __CLASS__, 'handle_github' ) );
		add_action( 'wp_ajax_rsnc_slides', array( __CLASS__, 'ajax_slides' ) );
		add_action( 'wp_ajax_rsnc_test', array( __CLASS__, 'ajax_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RSNC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Einstellungen', 'rs-nextcloud' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['revslider'] ) ? 'revslider' : 'options-general.php';
		add_submenu_page( $parent, 'Nextcloud-Bilder', 'Nextcloud', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function check( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'rs-nextcloud' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function notice( $type, $msg ) {
		set_transient( 'rsnc_notice_' . get_current_user_id(), array( $type, $msg ), 60 );
	}

	private static function format_result( array $r ) {
		/* translators: 1: added 2: updated 3: removed 4: unchanged */
		$msg = sprintf( __( 'Abgleich: %1$d neu, %2$d aktualisiert, %3$d entfernt, %4$d unverändert.', 'rs-nextcloud' ), $r['added'], $r['updated'], $r['removed'], $r['unchanged'] );
		if ( ! empty( $r['renamed'] ) ) {
			/* translators: %d: count */
			$msg .= ' ' . sprintf( __( '%d Datei(en) umbenannt/auf Originalnamen umgestellt.', 'rs-nextcloud' ), $r['renamed'] );
		}
		if ( ! empty( $r['cleaned'] ) ) {
			/* translators: %d: count */
			$msg .= ' ' . sprintf( __( '%d doppelte Slide(s)/verwaiste Bilder entfernt.', 'rs-nextcloud' ), $r['cleaned'] );
		}
		if ( ! empty( $r['duplicates'] ) ) {
			$msg .= ' ' . __( 'Übersprungen (gleiches Foto mehrfach in Nextcloud):', 'rs-nextcloud' ) . ' ' . implode( ', ', $r['duplicates'] ) . '.';
		}
		if ( $r['errors'] ) {
			$msg .= ' ' . __( 'Fehler:', 'rs-nextcloud' ) . ' ' . implode( ' | ', $r['errors'] );
		}
		return $msg;
	}

	/* ---------- Handler ---------- */

	public static function handle_save() {
		self::check( 'rsnc_save' );
		$in  = wp_unslash( $_POST );
		$id  = sanitize_key( $in['id'] ?? '' );
		$old = $id ? RSNC_Settings::get_mapping( $id ) : null;

		$share = esc_url_raw( trim( $in['share_url'] ?? '' ) );
		try {
			new RSNC_Nextcloud_Client( $share );
		} catch ( Exception $e ) {
			self::notice( 'error', $e->getMessage() );
			wp_safe_redirect( self::page_url( array( 'edit' => $id ? $id : 'new' ) ) );
			exit;
		}

		$password = (string) ( $in['password'] ?? '' );
		if ( '' === $password && $old && empty( $in['clear_password'] ) ) {
			$password = $old['password'];
		}

		$mapping = array(
			'id'                => $id,
			'name'              => sanitize_text_field( $in['name'] ?? '' ),
			'share_url'         => $share,
			'password'          => $password,
			'subfolder'         => trim( sanitize_text_field( $in['subfolder'] ?? '' ), '/' ),
			'slider_id'         => absint( $in['slider_id'] ?? 0 ),
			'template_slide_id' => absint( $in['template_slide_id'] ?? 0 ),
			'sort'              => in_array( $in['sort'] ?? '', array( 'name_asc', 'name_desc', 'date_asc', 'date_desc', 'random' ), true ) ? $in['sort'] : 'name_asc',
			'limit'             => absint( $in['limit'] ?? 0 ),
			'image_size'        => sanitize_key( $in['image_size'] ?? 'full' ),
			'delete_removed'    => empty( $in['delete_removed'] ) ? 0 : 1,
			'active'            => empty( $in['active'] ) ? 0 : 1,
		);
		$id = RSNC_Settings::save_mapping( $mapping );

		if ( ! empty( $in['sync_now'] ) ) {
			self::notice( 'info', self::format_result( RSNC_Sync::run( $id ) ) );
		} else {
			self::notice( 'success', __( 'Gespeichert.', 'rs-nextcloud' ) );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_delete() {
		self::check( 'rsnc_delete' );
		$id = sanitize_key( $_GET['id'] ?? '' );
		if ( ! empty( $_GET['purge'] ) ) {
			RSNC_Sync::purge( $id );
		}
		RSNC_Settings::delete_mapping( $id );
		self::notice( 'success', __( 'Zuordnung gelöscht.', 'rs-nextcloud' ) );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_sync() {
		self::check( 'rsnc_sync' );
		$id = sanitize_key( $_GET['id'] ?? '' );
		$r  = RSNC_Sync::run( $id );
		self::notice( $r['errors'] ? 'warning' : 'success', self::format_result( $r ) );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_interval() {
		self::check( 'rsnc_interval' );
		RSNC_Settings::set_interval( sanitize_key( $_POST['interval'] ?? 'hourly' ) );
		self::notice( 'success', __( 'Zeitplan gespeichert.', 'rs-nextcloud' ) );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_github() {
		self::check( 'rsnc_github' );
		$in = wp_unslash( $_POST );
		$cfg = RSNC_Updater::config();
		if ( ! $cfg['repo_locked'] || ! $cfg['token_locked'] ) {
			$repo_in = (string) ( $in['repo'] ?? '' );
			if ( ! $cfg['repo_locked'] && '' !== trim( $repo_in ) && '' === RSNC_Updater::normalize_repo( $repo_in ) ) {
				self::notice( 'error', __( 'Ungültiges Repository. Erwartet: owner/repo oder https://github.com/owner/repo', 'rs-nextcloud' ) );
				wp_safe_redirect( self::page_url() );
				exit;
			}
			RSNC_Updater::save_config(
				$cfg['repo_locked'] ? $cfg['repo'] : $repo_in,
				$cfg['token_locked'] ? '' : (string) ( $in['token'] ?? '' ),
				! $cfg['token_locked'] && ! empty( $in['clear_token'] )
			);
		}

		$r = RSNC_Updater::check_now();
		if ( is_wp_error( $r ) ) {
			self::notice( 'error', __( 'Update-Prüfung:', 'rs-nextcloud' ) . ' ' . $r->get_error_message() );
		} elseif ( version_compare( $r['version'], RSNC_VERSION, '>' ) ) {
			/* translators: %s: version */
			self::notice( 'warning', sprintf( __( 'Version %s ist verfügbar – unter „Plugins“ oder „Dashboard → Aktualisierungen“ installieren.', 'rs-nextcloud' ), $r['version'] ) );
		} else {
			self::notice( 'success', __( 'Das Add-on ist aktuell.', 'rs-nextcloud' ) );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function ajax_slides() {
		check_ajax_referer( 'rsnc_ajax' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error();
		}
		wp_send_json_success( RSNC_Sync::get_slides( absint( $_GET['slider'] ?? 0 ) ) );
	}

	public static function ajax_test() {
		check_ajax_referer( 'rsnc_ajax' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( 'Keine Berechtigung.' );
		}
		$in       = wp_unslash( $_POST );
		$password = (string) ( $in['password'] ?? '' );
		$id       = sanitize_key( $in['id'] ?? '' );
		if ( '' === $password && $id && ( $old = RSNC_Settings::get_mapping( $id ) ) ) {
			$password = $old['password'];
		}
		try {
			$client = new RSNC_Nextcloud_Client( esc_url_raw( $in['share_url'] ?? '' ), $password );
			$files  = $client->list_images( sanitize_text_field( $in['subfolder'] ?? '' ) );
			wp_send_json_success(
				array(
					'count'  => count( $files ),
					'sample' => array_slice( wp_list_pluck( $files, 'name' ), 0, 5 ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/* ---------- Ausgabe ---------- */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Nextcloud-Bilder für Slider Revolution', 'rs-nextcloud' ) . '</h1>';

		$n = get_transient( 'rsnc_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'rsnc_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[0] ), esc_html( $n[1] ) );
		}

		if ( ! rsnc_revslider_active() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Slider Revolution 6 ist nicht aktiv. Das Add-on benötigt das Plugin.', 'rs-nextcloud' ) . '</p></div>';
		}

		if ( isset( $_GET['edit'] ) ) {
			self::render_form( sanitize_key( $_GET['edit'] ) );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list() {
		$sliders = wp_list_pluck( RSNC_Sync::get_sliders(), 'title', 'id' );
		$maps    = RSNC_Settings::get_mappings();

		echo '<p><a class="button button-primary" href="' . esc_url( self::page_url( array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Neue Zuordnung', 'rs-nextcloud' ) . '</a></p>';

		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Freigabe</th><th>Slider</th><th>Letzter Abgleich</th><th>Aktionen</th></tr></thead><tbody>';
		if ( ! $maps ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Noch keine Zuordnung angelegt.', 'rs-nextcloud' ) . '</td></tr>';
		}
		foreach ( $maps as $id => $m ) {
			$m      = wp_parse_args( $m, RSNC_Settings::defaults() );
			$status = RSNC_Settings::get_status( $id );
			$st     = '–';
			if ( $status ) {
				$st = esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $status['time'] + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) )
					. '<br><small>+' . (int) $status['added'] . ' / ~' . (int) $status['updated'] . ' / −' . (int) $status['removed']
					. ( ! empty( $status['duplicates'] ) ? ' · ' . count( $status['duplicates'] ) . ' Duplikat(e) übersprungen' : '' ) . '</small>';
				if ( $status['errors'] ) {
					$st .= '<br><span style="color:#b32d2e">' . esc_html( implode( ' | ', $status['errors'] ) ) . '</span>';
				}
			}
			$sync  = wp_nonce_url( admin_url( 'admin-post.php?action=rsnc_sync&id=' . $id ), 'rsnc_sync' );
			$del   = wp_nonce_url( admin_url( 'admin-post.php?action=rsnc_delete&id=' . $id ), 'rsnc_delete' );
			$purge = wp_nonce_url( admin_url( 'admin-post.php?action=rsnc_delete&purge=1&id=' . $id ), 'rsnc_delete' );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $m['name'] ? $m['name'] : $id ) . '</strong>' . ( $m['active'] ? '' : ' <em>(inaktiv)</em>' ) . '</td>';
			echo '<td><code>' . esc_html( $m['share_url'] ) . ( $m['subfolder'] ? ' → /' . esc_html( $m['subfolder'] ) : '' ) . '</code></td>';
			echo '<td>' . esc_html( $sliders[ $m['slider_id'] ] ?? '#' . $m['slider_id'] ) . '</td>';
			echo '<td>' . $st . '</td>'; // phpcs:ignore -- bereits escaped
			echo '<td><a class="button" href="' . esc_url( $sync ) . '">Jetzt abgleichen</a> ';
			echo '<a class="button" href="' . esc_url( self::page_url( array( 'edit' => $id ) ) ) . '">Bearbeiten</a> ';
			echo '<a class="button-link-delete" href="' . esc_url( $del ) . '" onclick="return confirm(\'Zuordnung löschen? Slides bleiben erhalten.\')">Löschen</a> | ';
			echo '<a class="button-link-delete" href="' . esc_url( $purge ) . '" onclick="return confirm(\'Zuordnung UND alle erzeugten Slides und Bilder löschen?\')">inkl. Slides</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$interval = RSNC_Settings::get_interval();
		$next     = wp_next_scheduled( RSNC_CRON_HOOK );
		echo '<h2>' . esc_html__( 'Automatischer Abgleich', 'rs-nextcloud' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'rsnc_interval' );
		echo '<input type="hidden" name="action" value="rsnc_interval"><select name="interval">';
		foreach ( array(
			'hourly'     => 'Stündlich',
			'twicedaily' => 'Zweimal täglich',
			'daily'      => 'Täglich',
			'manual'     => 'Nur manuell',
		) as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $interval, $k, false ), esc_html( $l ) );
		}
		echo '</select> ';
		submit_button( 'Speichern', 'secondary', 'submit', false );
		if ( $next ) {
			echo ' <span class="description">Nächster Lauf: ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), get_option( 'date_format' ) . ' H:i' ) ) . '</span>';
		}
		echo '</form>';

		self::render_updates();

		echo '<h2>So funktioniert’s</h2><ol>'
			. '<li>In Slider Revolution einen Slider mit einer <strong>Vorlagen-Slide</strong> anlegen (Übergang, Ebenen, Ken Burns …) und diese Slide auf „Unveröffentlicht“ setzen.</li>'
			. '<li>Platzhalter für Text-Ebenen der Vorlage:<br><code>{{nc_caption}}</code> Bildbeschreibung aus den Metadaten (Lightroom: „Bildunterschrift“) · <code>{{nc_headline}}</code> Überschrift · <code>{{nc_meta_title}}</code> Titel · <code>{{nc_creator}}</code> Fotograf · <code>{{nc_copyright}}</code> Copyright · <code>{{nc_date_taken}}</code> Aufnahmedatum · <code>{{nc_camera}}</code> Kamera · <code>{{nc_title}}</code> Dateiname als Titel · <code>{{nc_filename}}</code> · <code>{{nc_date}}</code> Dateidatum<br>Fallback, wenn ein Feld leer ist: <code>{{nc_caption|nc_title}}</code> oder <code>{{nc_caption|Eigener Text}}</code>.</li>'
			. '<li>Hier eine Zuordnung anlegen: öffentlicher Nextcloud-Link (<code>https://cloud…/s/TOKEN</code>), Ziel-Slider, Vorlage.</li>'
			. '<li>Für jedes Bild entsteht eine eigene Slide. Neue, geänderte und gelöschte Bilder werden beim Abgleich übernommen. Änderungen an der Vorlage werden beim nächsten Abgleich auf alle Slides angewendet.</li>'
			. '</ol>';
	}

	private static function render_updates() {
		$cfg     = RSNC_Updater::config();
		$release = $cfg['repo'] ? get_site_transient( RSNC_Updater::CACHE ) : null;
		echo '<h2>' . esc_html__( 'Updates über GitHub', 'rs-nextcloud' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'rsnc_github' );
		echo '<input type="hidden" name="action" value="rsnc_github"><table class="form-table" role="presentation">';

		echo '<tr><th><label for="rsnc-repo">Repository</label></th><td>';
		if ( $cfg['repo_locked'] ) {
			echo '<code>' . esc_html( $cfg['repo'] ) . '</code> <span class="description">(festgelegt in wp-config.php)</span>';
		} else {
			echo '<input type="text" class="regular-text" id="rsnc-repo" name="repo" value="' . esc_attr( $cfg['repo'] ) . '" placeholder="owner/repo">';
			echo '<p class="description">Updates kommen aus dem neuesten <strong>Release</strong> dieses Repositorys (Tag z. B. <code>v1.3.0</code>).</p>';
		}
		echo '</td></tr>';

		echo '<tr><th><label for="rsnc-token">Zugriffstoken</label></th><td>';
		if ( $cfg['token_locked'] ) {
			echo '<span class="description">Festgelegt in wp-config.php</span>';
		} else {
			echo '<input type="password" class="regular-text" id="rsnc-token" name="token" value="" autocomplete="new-password" placeholder="' . ( $cfg['token'] ? '•••••• (unverändert)' : 'nur für private Repositories' ) . '">';
			if ( $cfg['token'] ) {
				echo ' <label><input type="checkbox" name="clear_token" value="1"> Token entfernen</label>';
			}
			echo '<p class="description">Fine-grained Token mit Leserecht auf „Contents“ für dieses Repository.</p>';
		}
		echo '</td></tr>';

		echo '<tr><th>Status</th><td>Installiert: <strong>' . esc_html( RSNC_VERSION ) . '</strong>';
		if ( is_array( $release ) && isset( $release['version'] ) ) {
			$newer = version_compare( $release['version'], RSNC_VERSION, '>' );
			echo ' · Neuestes Release: <a href="' . esc_url( $release['url'] ) . '" target="_blank" rel="noopener"><strong>' . esc_html( $release['version'] ) . '</strong></a>';
			if ( $newer ) {
				echo ' · <a href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Jetzt aktualisieren', 'rs-nextcloud' ) . '</a>';
			}
		} elseif ( is_array( $release ) && isset( $release['error'] ) ) {
			echo '<br><span style="color:#b32d2e">' . esc_html( $release['error'] ) . '</span>';
		}
		echo '</td></tr></table>';
		submit_button( __( 'Speichern & nach Updates suchen', 'rs-nextcloud' ), 'secondary' );
		echo '</form>';
	}

	private static function render_form( $id ) {
		$m       = ( 'new' !== $id && $id ) ? RSNC_Settings::get_mapping( $id ) : null;
		$m       = $m ? $m : RSNC_Settings::defaults();
		$sliders = RSNC_Sync::get_sliders();
		$slides  = $m['slider_id'] ? RSNC_Sync::get_slides( $m['slider_id'] ) : array();
		$sizes   = array_merge( array( 'full' ), get_intermediate_image_sizes() );
		$nonce   = wp_create_nonce( 'rsnc_ajax' );
		?>
		<h2><?php echo $m['id'] ? 'Zuordnung bearbeiten' : 'Neue Zuordnung'; ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rsnc-form">
			<?php wp_nonce_field( 'rsnc_save' ); ?>
			<input type="hidden" name="action" value="rsnc_save">
			<input type="hidden" name="id" value="<?php echo esc_attr( $m['id'] ); ?>">
			<table class="form-table" role="presentation">
				<tr><th><label for="rsnc-name">Name</label></th>
					<td><input type="text" class="regular-text" id="rsnc-name" name="name" value="<?php echo esc_attr( $m['name'] ); ?>" placeholder="z. B. Startseite Spotting"></td></tr>
				<tr><th><label for="rsnc-url">Nextcloud-Freigabelink</label></th>
					<td><input type="url" class="large-text" id="rsnc-url" name="share_url" required value="<?php echo esc_attr( $m['share_url'] ); ?>" placeholder="https://cloud.example.org/s/AbCdEf12345">
					<p class="description">Öffentlicher Link auf einen <strong>Ordner</strong> (Freigabe „Nur lesen“ genügt).</p></td></tr>
				<tr><th><label for="rsnc-pw">Passwort der Freigabe</label></th>
					<td><input type="password" class="regular-text" id="rsnc-pw" name="password" value="" autocomplete="new-password" placeholder="<?php echo $m['password'] ? '•••••• (unverändert)' : 'optional'; ?>">
					<?php if ( $m['password'] ) : ?><label><input type="checkbox" name="clear_password" value="1"> Passwort entfernen</label><?php endif; ?></td></tr>
				<tr><th><label for="rsnc-sub">Unterordner</label></th>
					<td><input type="text" class="regular-text" id="rsnc-sub" name="subfolder" value="<?php echo esc_attr( $m['subfolder'] ); ?>" placeholder="optional, z. B. 2026/Slider">
					<button type="button" class="button" id="rsnc-test">Verbindung testen</button> <span id="rsnc-test-out"></span></td></tr>
				<tr><th><label for="rsnc-slider">Ziel-Slider</label></th>
					<td><select id="rsnc-slider" name="slider_id" required>
						<option value="">– wählen –</option>
						<?php foreach ( $sliders as $s ) : ?>
							<option value="<?php echo (int) $s['id']; ?>" <?php selected( (int) $m['slider_id'], (int) $s['id'] ); ?>><?php echo esc_html( $s['title'] . ' (' . $s['alias'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th><label for="rsnc-tpl">Vorlagen-Slide</label></th>
					<td><select id="rsnc-tpl" name="template_slide_id">
						<option value="0">Automatisch (erste eigene Slide des Sliders)</option>
						<?php foreach ( $slides as $s ) : ?>
							<option value="<?php echo (int) $s['id']; ?>" <?php selected( (int) $m['template_slide_id'], $s['id'] ); ?>><?php echo esc_html( $s['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Diese Slide wird für jedes Bild geklont; nur das Hintergrundbild wird ersetzt.</p></td></tr>
				<tr><th><label for="rsnc-sort">Reihenfolge</label></th>
					<td><select id="rsnc-sort" name="sort">
						<?php
						foreach ( array(
							'name_asc'  => 'Dateiname A–Z',
							'name_desc' => 'Dateiname Z–A',
							'date_desc' => 'Neueste zuerst',
							'date_asc'  => 'Älteste zuerst',
							'random'    => 'Zufällig (bei jedem Abgleich)',
						) as $k => $l ) {
							printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $m['sort'], $k, false ), esc_html( $l ) );
						}
						?>
					</select>
					<label style="margin-left:1em">Max. Bilder <input type="number" min="0" class="small-text" name="limit" value="<?php echo (int) $m['limit']; ?>"></label> <span class="description">0 = alle</span></td></tr>
				<tr><th><label for="rsnc-size">Bildgröße</label></th>
					<td><select id="rsnc-size" name="image_size">
						<?php foreach ( $sizes as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $m['image_size'], $s ); ?>><?php echo esc_html( $s ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th>Optionen</th>
					<td><label><input type="checkbox" name="delete_removed" value="1" <?php checked( $m['delete_removed'] ); ?>> Slides löschen, wenn das Bild aus Nextcloud entfernt wird</label><br>
					<label><input type="checkbox" name="active" value="1" <?php checked( $m['active'] ); ?>> Automatisch abgleichen</label><br>
					<label><input type="checkbox" name="sync_now" value="1" checked> Nach dem Speichern sofort abgleichen</label></td></tr>
			</table>
			<?php submit_button( 'Speichern' ); ?>
			<a href="<?php echo esc_url( self::page_url() ); ?>">Zurück</a>
		</form>
		<script>
		(function(){
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var slider = document.getElementById('rsnc-slider'), tpl = document.getElementById('rsnc-tpl');
			slider.addEventListener('change', function(){
				tpl.length = 1;
				if (!slider.value) return;
				fetch(ajax + '?action=rsnc_slides&_ajax_nonce=' + nonce + '&slider=' + slider.value, {credentials:'same-origin'})
					.then(function(r){return r.json();}).then(function(r){
						(r.data || []).forEach(function(s){ tpl.add(new Option(s.title, s.id)); });
					});
			});
			document.getElementById('rsnc-test').addEventListener('click', function(){
				var out = document.getElementById('rsnc-test-out'), f = document.getElementById('rsnc-form');
				out.textContent = 'Prüfe …';
				var fd = new FormData();
				fd.append('action','rsnc_test'); fd.append('_ajax_nonce', nonce);
				['id','share_url','password','subfolder'].forEach(function(n){ fd.append(n, f.elements[n].value); });
				fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){
					out.style.color = r.success ? '#008a20' : '#b32d2e';
					out.textContent = r.success
						? r.data.count + ' Bilder gefunden' + (r.data.sample.length ? ' (z. B. ' + r.data.sample.join(', ') + ')' : '')
						: (r.data || 'Fehler');
				}).catch(function(){ out.textContent = 'Fehler'; });
			});
		})();
		</script>
		<?php
	}
}
