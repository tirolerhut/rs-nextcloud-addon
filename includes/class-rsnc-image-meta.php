<?php
defined( 'ABSPATH' ) || exit;

/**
 * Liest Bildbeschreibung & Co. aus XMP, IPTC und EXIF.
 *
 * Priorität je Feld: XMP (immer UTF-8, z. B. Lightroom) → IPTC → EXIF.
 * Lightroom „Bildunterschrift“ landet in XMP dc:description, IPTC 2#120 und EXIF ImageDescription.
 */
class RSNC_Image_Meta {

	const FIELDS = array( 'caption', 'headline', 'title', 'creator', 'copyright', 'date_taken', 'camera' );

	/**
	 * @param string $path Lokaler Dateipfad.
	 * @return array<string,string>
	 */
	public static function read( $path ) {
		$out = array_fill_keys( self::FIELDS, '' );
		if ( ! $path || ! is_readable( $path ) ) {
			return $out;
		}

		$sources = array( self::read_xmp( $path ), self::read_iptc( $path ), self::read_exif( $path ) );
		foreach ( self::FIELDS as $f ) {
			foreach ( $sources as $src ) {
				if ( isset( $src[ $f ] ) && '' !== trim( $src[ $f ] ) ) {
					$out[ $f ] = self::clean( $src[ $f ] );
					break;
				}
			}
		}
		return apply_filters( 'rsnc_image_meta', $out, $path );
	}

	private static function clean( $s ) {
		$s = (string) $s;
		if ( ! self::is_utf8( $s ) ) {
			$s = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' ) : utf8_encode( $s );
		}
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s );
		return trim( $s );
	}

	private static function is_utf8( $s ) {
		return function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $s, 'UTF-8' ) : (bool) preg_match( '//u', $s );
	}

	/* ---------- XMP ---------- */

	private static function read_xmp( $path ) {
		$data = @file_get_contents( $path, false, null, 0, 4 * MB_IN_BYTES ); // phpcs:ignore
		if ( false === $data ) {
			return array();
		}
		$start = strpos( $data, '<x:xmpmeta' );
		$end   = false !== $start ? strpos( $data, '</x:xmpmeta>', $start ) : false;
		if ( false === $start || false === $end ) {
			return array();
		}
		$xml = substr( $data, $start, $end - $start + strlen( '</x:xmpmeta>' ) );

		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $prev );
		if ( false === $doc ) {
			return array();
		}
		$ns = array(
			'rdf'       => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
			'dc'        => 'http://purl.org/dc/elements/1.1/',
			'photoshop' => 'http://ns.adobe.com/photoshop/1.0/',
			'exif'      => 'http://ns.adobe.com/exif/1.0/',
			'xmp'       => 'http://ns.adobe.com/xap/1.0/',
		);
		foreach ( $ns as $p => $u ) {
			$doc->registerXPathNamespace( $p, $u );
		}

		return array(
			'caption'    => self::xmp_alt( $doc, 'dc:description' ),
			'title'      => self::xmp_alt( $doc, 'dc:title' ),
			'copyright'  => self::xmp_alt( $doc, 'dc:rights' ),
			'creator'    => self::xmp_first( $doc, array( '//dc:creator//rdf:li' ) ),
			'headline'   => self::xmp_first( $doc, array( '//rdf:Description/@photoshop:Headline', '//photoshop:Headline' ) ),
			'date_taken' => self::xmp_first( $doc, array( '//rdf:Description/@exif:DateTimeOriginal', '//exif:DateTimeOriginal', '//rdf:Description/@photoshop:DateCreated', '//photoshop:DateCreated' ) ),
		);
	}

	/** Sprachalternativen (rdf:Alt): x-default bevorzugen. */
	private static function xmp_alt( SimpleXMLElement $doc, $prop ) {
		$v = self::xmp_first( $doc, array( "//{$prop}//rdf:li[@xml:lang='x-default']", "//{$prop}//rdf:li" ) );
		if ( '' === $v ) {
			$v = self::xmp_first( $doc, array( "//rdf:Description/@{$prop}" ) );
		}
		return $v;
	}

	private static function xmp_first( SimpleXMLElement $doc, array $queries ) {
		foreach ( $queries as $q ) {
			$r = @$doc->xpath( $q ); // phpcs:ignore
			if ( $r ) {
				$v = trim( (string) $r[0] );
				if ( '' !== $v ) {
					return $v;
				}
			}
		}
		return '';
	}

	/* ---------- IPTC ---------- */

	private static function read_iptc( $path ) {
		if ( ! function_exists( 'iptcparse' ) ) {
			return array();
		}
		$info = array();
		@getimagesize( $path, $info ); // phpcs:ignore
		if ( empty( $info['APP13'] ) ) {
			return array();
		}
		$iptc = iptcparse( $info['APP13'] );
		if ( ! $iptc ) {
			return array();
		}
		$get = function ( $k ) use ( $iptc ) {
			return isset( $iptc[ $k ][0] ) ? $iptc[ $k ][0] : '';
		};
		$date = $get( '2#055' );
		if ( $date && preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $date, $m ) ) {
			$date = "{$m[1]}-{$m[2]}-{$m[3]}" . ( $get( '2#060' ) ? ' ' . substr( $get( '2#060' ), 0, 2 ) . ':' . substr( $get( '2#060' ), 2, 2 ) : '' );
		}
		return array(
			'caption'    => $get( '2#120' ),
			'headline'   => $get( '2#105' ),
			'title'      => $get( '2#005' ),
			'creator'    => $get( '2#080' ),
			'copyright'  => $get( '2#116' ),
			'date_taken' => $date,
		);
	}

	/* ---------- EXIF ---------- */

	private static function read_exif( $path ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return array();
		}
		$exif = @exif_read_data( $path ); // phpcs:ignore
		if ( ! $exif ) {
			return array();
		}
		$caption = isset( $exif['ImageDescription'] ) ? $exif['ImageDescription'] : '';
		// Manche Kameras schreiben Platzhalter wie „OLYMPUS DIGITAL CAMERA“.
		if ( preg_match( '/^(OLYMPUS DIGITAL CAMERA|SONY DSC|DCIM|Default|\s*)$/i', $caption ) ) {
			$caption = '';
		}
		$camera = trim( ( $exif['Make'] ?? '' ) . ' ' . ( $exif['Model'] ?? '' ) );
		if ( isset( $exif['Make'], $exif['Model'] ) && 0 === stripos( $exif['Model'], $exif['Make'] ) ) {
			$camera = $exif['Model'];
		}
		return array(
			'caption'    => $caption,
			'creator'    => $exif['Artist'] ?? '',
			'copyright'  => $exif['Copyright'] ?? '',
			'date_taken' => $exif['DateTimeOriginal'] ?? '',
			'camera'     => $camera,
		);
	}

	/** Aufnahmedatum im WordPress-Datumsformat. */
	public static function format_date( $raw ) {
		if ( ! $raw ) {
			return '';
		}
		$raw = preg_replace( '/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $raw ); // EXIF 2026:10:04 → 2026-10-04
		$ts  = strtotime( $raw );
		return $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
	}
}
