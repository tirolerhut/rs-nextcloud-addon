<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minimaler WebDAV-Client für öffentliche Nextcloud-Freigaben.
 *
 * Unterstützt beide Endpunkte:
 *  - neu (NC 29+):  /public.php/dav/files/{token}/
 *  - alt:           /public.php/webdav/  (Basic-Auth: token:passwort)
 */
class RSNC_Nextcloud_Client {

	const IMAGE_MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' );

	private $base;
	private $token;
	private $password;
	private $endpoint = null;

	/**
	 * @param string $share_url z. B. https://cloud.example.org/s/AbCdEf12345
	 * @param string $password  optionales Freigabe-Passwort
	 */
	public function __construct( $share_url, $password = '' ) {
		$share_url = trim( $share_url );
		if ( ! preg_match( '#^(https?://.+?)(?:/index\.php)?/s/([A-Za-z0-9]+)#', $share_url, $m ) ) {
			throw new InvalidArgumentException( __( 'Ungültiger Nextcloud-Freigabelink. Erwartet: https://host/s/TOKEN', 'rs-nextcloud' ) );
		}
		$this->base     = rtrim( $m[1], '/' );
		$this->token    = $m[2];
		$this->password = (string) $password;
	}

	public function get_token() {
		return $this->token;
	}

	private function auth_header() {
		return 'Basic ' . base64_encode( $this->token . ':' . $this->password );
	}

	private function endpoint_root( $type ) {
		return 'new' === $type
			? $this->base . '/public.php/dav/files/' . rawurlencode( $this->token )
			: $this->base . '/public.php/webdav';
	}

	private function request( $method, $url, $args = array() ) {
		$headers = array(
			'Authorization'    => $this->auth_header(),
			'X-Requested-With' => 'XMLHttpRequest',
		);
		if ( isset( $args['headers'] ) ) {
			$headers = array_merge( $headers, $args['headers'] );
		}
		$args = array_merge(
			array(
				'timeout'     => 30,
				'redirection' => 3,
			),
			$args,
			array(
				'method'  => $method,
				'headers' => $headers,
			)
		);
		return wp_remote_request( $url, $args );
	}

	/**
	 * Listet Bilder im (Unter-)Ordner der Freigabe auf.
	 *
	 * @return array[] Liste mit name, path, url, etag, mime, size, mtime
	 */
	public function list_images( $subfolder = '' ) {
		$subfolder = trim( (string) $subfolder, '/' );
		$encoded   = '' === $subfolder ? '' : '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $subfolder ) ) );

		$body = '<?xml version="1.0"?>'
			. '<d:propfind xmlns:d="DAV:"><d:prop>'
			. '<d:getetag/><d:getcontenttype/><d:getcontentlength/><d:getlastmodified/><d:resourcetype/>'
			. '</d:prop></d:propfind>';

		$types    = null === $this->endpoint ? array( 'new', 'old' ) : array( $this->endpoint );
		$last_err = null;

		foreach ( $types as $type ) {
			$root = $this->endpoint_root( $type );
			$res  = $this->request(
				'PROPFIND',
				$root . $encoded . '/',
				array(
					'headers' => array(
						'Depth'        => '1',
						'Content-Type' => 'application/xml; charset=utf-8',
					),
					'body'    => $body,
				)
			);
			if ( is_wp_error( $res ) ) {
				$last_err = $res->get_error_message();
				continue;
			}
			$code = wp_remote_retrieve_response_code( $res );
			if ( 207 === $code ) {
				$this->endpoint = $type;
				return $this->parse_multistatus( wp_remote_retrieve_body( $res ), $root );
			}
			if ( 401 === $code ) {
				$last_err = __( 'Zugriff verweigert – Passwort der Freigabe prüfen.', 'rs-nextcloud' );
			} elseif ( 404 === $code ) {
				$last_err = __( 'Freigabe oder Unterordner nicht gefunden.', 'rs-nextcloud' );
			} else {
				/* translators: %d: HTTP status */
				$last_err = sprintf( __( 'Unerwartete Antwort von Nextcloud (HTTP %d).', 'rs-nextcloud' ), $code );
			}
		}
		throw new RuntimeException( $last_err ? $last_err : __( 'Nextcloud nicht erreichbar.', 'rs-nextcloud' ) );
	}

	private function parse_multistatus( $xml, $root ) {
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $prev );
		// Achtung: SimpleXML-Elemente mit nur namespaced Kindern sind „falsy“ – daher strikt prüfen.
		if ( false === $doc ) {
			throw new RuntimeException( __( 'Antwort von Nextcloud konnte nicht gelesen werden.', 'rs-nextcloud' ) );
		}
		$doc->registerXPathNamespace( 'd', 'DAV:' );

		$root_path = wp_parse_url( $root, PHP_URL_PATH );
		$origin    = preg_replace( '#^(https?://[^/]+).*$#', '$1', $root );
		$files     = array();

		foreach ( $doc->xpath( '//d:response' ) as $resp ) {
			$resp->registerXPathNamespace( 'd', 'DAV:' );
			$href = (string) ( $resp->xpath( 'd:href' )[0] ?? '' );
			if ( '' === $href ) {
				continue;
			}
			if ( $resp->xpath( './/d:resourcetype/d:collection' ) ) {
				continue; // Ordner überspringen.
			}
			$mime = strtolower( trim( (string) ( $resp->xpath( './/d:getcontenttype' )[0] ?? '' ) ) );
			$mime = trim( explode( ';', $mime )[0] );
			$path = rawurldecode( $href );
			$name = basename( $path );
			if ( ! in_array( $mime, self::IMAGE_MIMES, true ) ) {
				$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ), true ) ) {
					continue;
				}
			}
			$rel = $path;
			if ( $root_path && 0 === strpos( $path, $root_path ) ) {
				$rel = substr( $path, strlen( $root_path ) );
			}
			$files[] = array(
				'name'  => $name,
				'path'  => '/' . ltrim( $rel, '/' ),
				'url'   => preg_match( '#^https?://#', $href ) ? $href : $origin . $href,
				'etag'  => trim( (string) ( $resp->xpath( './/d:getetag' )[0] ?? '' ), '"' ),
				'mime'  => $mime,
				'size'  => (int) ( $resp->xpath( './/d:getcontentlength' )[0] ?? 0 ),
				'mtime' => strtotime( (string) ( $resp->xpath( './/d:getlastmodified' )[0] ?? '' ) ) ?: 0,
			);
		}
		return $files;
	}

	/**
	 * Lädt eine Datei in eine temporäre Datei herunter.
	 *
	 * @return string Pfad der Temp-Datei
	 */
	public function download( array $file ) {
		$tmp = wp_tempnam( $file['name'] );
		$res = $this->request(
			'GET',
			$file['url'],
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $tmp,
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			@unlink( $tmp ); // phpcs:ignore
			/* translators: %s: file name */
			throw new RuntimeException( sprintf( __( 'Download fehlgeschlagen: %s', 'rs-nextcloud' ), $file['name'] ) );
		}
		return $tmp;
	}
}
