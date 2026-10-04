<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates aus GitHub-Releases über das WordPress-Update-System.
 *
 * - Prüft https://api.github.com/repos/{owner}/{repo}/releases/latest
 * - Version = Tag ohne führendes „v“ (z. B. v1.2.0 → 1.2.0)
 * - Paket: Release-Asset „rs-nextcloud-addon.zip“ (bevorzugt) oder Quellcode-ZIP des Tags
 * - Private Repos: Personal Access Token (Lesezugriff auf „Contents“)
 *
 * Konfiguration in der Admin-Seite oder in wp-config.php:
 *   define( 'RSNC_GITHUB_REPO',  'owner/repo' );
 *   define( 'RSNC_GITHUB_TOKEN', 'github_pat_…' );
 */
class RSNC_Updater {

	const OPT        = 'rsnc_github';
	const CACHE      = 'rsnc_gh_release';
	const SLUG       = 'rs-nextcloud-addon';
	const ASSET_NAME = 'rs-nextcloud-addon.zip';
	const API        = 'https://api.github.com';

	public static function init() {
		// Greift dank „Update URI: https://github.com/…“ im Plugin-Header (WP 5.8+).
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	public static function basename() {
		return plugin_basename( RSNC_FILE );
	}

	/** @return array{repo:string,token:string,repo_locked:bool,token_locked:bool} */
	public static function config() {
		$opt  = get_option( self::OPT, array() );
		$opt  = is_array( $opt ) ? $opt : array();
		$repo = defined( 'RSNC_GITHUB_REPO' ) ? RSNC_GITHUB_REPO : ( $opt['repo'] ?? '' );
		$tok  = defined( 'RSNC_GITHUB_TOKEN' ) ? RSNC_GITHUB_TOKEN : ( $opt['token'] ?? '' );
		return array(
			'repo'         => self::normalize_repo( $repo ),
			'token'        => trim( (string) $tok ),
			'repo_locked'  => defined( 'RSNC_GITHUB_REPO' ),
			'token_locked' => defined( 'RSNC_GITHUB_TOKEN' ),
		);
	}

	/** Akzeptiert „owner/repo“ oder eine GitHub-URL. */
	public static function normalize_repo( $repo ) {
		$repo = trim( (string) $repo );
		$repo = preg_replace( '#^(https?://)?(www\.)?github\.com/#i', '', $repo );
		$repo = preg_replace( '#(\.git)?/*$#', '', $repo );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	public static function save_config( $repo, $token, $clear_token = false ) {
		$opt          = get_option( self::OPT, array() );
		$opt          = is_array( $opt ) ? $opt : array();
		$opt['repo']  = self::normalize_repo( $repo );
		if ( $clear_token ) {
			$opt['token'] = '';
		} elseif ( '' !== trim( $token ) ) {
			$opt['token'] = trim( $token );
		}
		update_option( self::OPT, $opt, false );
		self::flush();
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	private static function api_headers( $token ) {
		$h = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'rs-nextcloud-addon/' . RSNC_VERSION . '; ' . home_url(),
		);
		if ( $token ) {
			$h['Authorization'] = 'Bearer ' . $token;
		}
		return $h;
	}

	/**
	 * Neuestes Release (6 h gecacht, Fehler 1 h).
	 *
	 * @return array|WP_Error
	 */
	public static function release( $force = false ) {
		$cfg = self::config();
		if ( ! $cfg['repo'] ) {
			return new WP_Error( 'rsnc_no_repo', __( 'Kein GitHub-Repository eingetragen.', 'rs-nextcloud' ) );
		}

		$cached = $force ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) && ( $cached['repo'] ?? '' ) === $cfg['repo'] ) {
			return isset( $cached['error'] ) ? new WP_Error( 'rsnc_gh', $cached['error'] ) : $cached;
		}

		$res = wp_remote_get(
			self::API . '/repos/' . $cfg['repo'] . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => self::api_headers( $cfg['token'] ),
			)
		);

		$error = null;
		if ( is_wp_error( $res ) ) {
			$error = $res->get_error_message();
		} else {
			$code = wp_remote_retrieve_response_code( $res );
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( 200 !== $code || ! is_array( $data ) || empty( $data['tag_name'] ) ) {
				if ( 404 === $code ) {
					$error = $cfg['token']
						? __( 'Repository oder Release nicht gefunden (Name und Token-Rechte prüfen).', 'rs-nextcloud' )
						: __( 'Repository oder Release nicht gefunden. Bei privaten Repositories wird ein Token benötigt.', 'rs-nextcloud' );
				} elseif ( 401 === $code ) {
					$error = __( 'GitHub-Token ungültig oder abgelaufen.', 'rs-nextcloud' );
				} elseif ( 403 === $code || 429 === $code ) {
					$error = __( 'GitHub-API-Limit erreicht – später erneut versuchen oder einen Token hinterlegen.', 'rs-nextcloud' );
				} else {
					/* translators: %d: HTTP status */
					$error = sprintf( __( 'Unerwartete Antwort von GitHub (HTTP %d).', 'rs-nextcloud' ), $code );
				}
			}
		}

		if ( $error ) {
			set_site_transient(
				self::CACHE,
				array(
					'repo'  => $cfg['repo'],
					'error' => $error,
				),
				HOUR_IN_SECONDS
			);
			return new WP_Error( 'rsnc_gh', $error );
		}

		// Paket wählen. Öffentliche Repos: Release-Asset, sonst Quellcode-ZIP.
		// Private Repos: immer Quellcode-ZIP über die API (Asset-Downloads leiten auf
		// einen Speicher um, der den Token-Header ablehnt).
		$package = $data['zipball_url'];
		if ( ! $cfg['token'] ) {
			foreach ( (array) ( $data['assets'] ?? array() ) as $a ) {
				if ( self::ASSET_NAME === ( $a['name'] ?? '' ) && ! empty( $a['browser_download_url'] ) ) {
					$package = $a['browser_download_url'];
					break;
				}
			}
		}

		$release = array(
			'repo'      => $cfg['repo'],
			'version'   => ltrim( $data['tag_name'], 'vV' ),
			'tag'       => $data['tag_name'],
			'name'      => $data['name'] ? $data['name'] : $data['tag_name'],
			'body'      => (string) ( $data['body'] ?? '' ),
			'url'       => $data['html_url'],
			'package'   => $package,
			'published' => $data['published_at'] ?? '',
		);
		set_site_transient( self::CACHE, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/** Filter update_plugins_github.com – WordPress vergleicht die Versionen selbst. */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$r = self::release();
		if ( is_wp_error( $r ) ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . $r['repo'],
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $r['version'],
			'url'          => $r['url'],
			'package'      => $r['package'],
			'requires_php' => $plugin_data['RequiresPHP'] ?? '',
			'requires'     => $plugin_data['RequiresWP'] ?? '',
		);
	}

	/** Inhalt des „Details ansehen“-Fensters. */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$r = self::release();
		if ( is_wp_error( $r ) ) {
			return $result;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$p = get_plugin_data( RSNC_FILE, false, false );

		$readme = '';
		$file   = RSNC_DIR . 'readme.txt';
		if ( is_readable( $file ) ) {
			$readme = '<pre style="white-space:pre-wrap">' . esc_html( file_get_contents( $file ) ) . '</pre>'; // phpcs:ignore
		}

		return (object) array(
			'name'          => $p['Name'],
			'slug'          => self::SLUG,
			'version'       => $r['version'],
			'author'        => $p['Author'],
			'homepage'      => 'https://github.com/' . $r['repo'],
			'requires'      => $p['RequiresWP'],
			'requires_php'  => $p['RequiresPHP'],
			'last_updated'  => $r['published'],
			'download_link' => $r['package'],
			'sections'      => array(
				'changelog'   => '<h4>' . esc_html( $r['name'] ) . '</h4>' . self::markdown( $r['body'] ),
				'description' => $readme ? $readme : esc_html( $p['Description'] ),
			),
		);
	}

	/** Sehr einfacher Markdown-Konverter für Release-Notes. */
	public static function markdown( $md ) {
		if ( '' === trim( $md ) ) {
			return '<p>' . esc_html__( 'Keine Release-Notes.', 'rs-nextcloud' ) . '</p>';
		}
		$html   = '';
		$in_ul  = false;
		$inline = function ( $s ) {
			$s = esc_html( $s );
			$s = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s );
			$s = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
			$s = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $s );
			return $s;
		};
		foreach ( preg_split( '/\r\n|\r|\n/', $md ) as $line ) {
			if ( preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m ) ) {
				if ( ! $in_ul ) {
					$html .= '<ul>';
					$in_ul = true;
				}
				$html .= '<li>' . $inline( $m[1] ) . '</li>';
				continue;
			}
			if ( $in_ul ) {
				$html .= '</ul>';
				$in_ul = false;
			}
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$html .= '<h4>' . $inline( $m[2] ) . '</h4>';
			} elseif ( '' !== trim( $line ) ) {
				$html .= '<p>' . $inline( $line ) . '</p>';
			}
		}
		return $html . ( $in_ul ? '</ul>' : '' );
	}

	/**
	 * GitHub-ZIPs entpacken in „owner-repo-sha/“ – auf den Plugin-Ordnernamen umbenennen,
	 * sonst würde WordPress das Plugin in einen neuen Ordner installieren und deaktivieren.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $target ) ) {
			return $source;
		}
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . basename( RSNC_FILE ) ) ) {
			return new WP_Error( 'rsnc_bad_package', __( 'Das Update-Paket enthält das Plugin nicht (Hauptdatei fehlt).', 'rs-nextcloud' ) );
		}
		if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ), true ) ) {
			return new WP_Error( 'rsnc_rename', __( 'Update-Ordner konnte nicht umbenannt werden.', 'rs-nextcloud' ) );
		}
		return $target;
	}

	/** Token für Downloads aus privaten Repos mitsenden – nur an GitHub-API/Codeload für dieses Repo. */
	public static function auth( $args, $url ) {
		$cfg = self::config();
		if ( ! $cfg['token'] || ! $cfg['repo'] ) {
			return $args;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ok   = ( 'api.github.com' === $host && 0 === stripos( $path, '/repos/' . $cfg['repo'] . '/' ) )
			|| ( 'codeload.github.com' === $host && 0 === stripos( $path, '/' . $cfg['repo'] . '/' ) );
		if ( $ok ) {
			$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . $cfg['token'];
			$args['headers']['User-Agent']    = 'rs-nextcloud-addon/' . RSNC_VERSION;
		}
		return $args;
	}

	/** Für die Admin-Seite: sofort prüfen und WP-Update-Cache erneuern. */
	public static function check_now() {
		self::flush();
		$r = self::release( true );
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		return $r;
	}
}
