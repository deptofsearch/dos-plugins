<?php
/**
 * Venue meta field definitions and sanitizers.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Fields {

	const PREFIX = 'osn_';

	/** Per-pass payload hash meta key prefix: osn_hash_{pass}. Not registered, so never exposed over REST. */
	const HASH_PREFIX = 'osn_hash_';

	const PRICE_ENUM = array(
		'PRICE_LEVEL_FREE'           => 0,
		'PRICE_LEVEL_INEXPENSIVE'    => 1,
		'PRICE_LEVEL_MODERATE'       => 2,
		'PRICE_LEVEL_EXPENSIVE'      => 3,
		'PRICE_LEVEL_VERY_EXPENSIVE' => 4,
	);

	/**
	 * name (no prefix) => array( REST type, sanitizer kind, show_in_rest )
	 */
	public static function definitions() {
		return array(
			'source'          => array( 'string', 'text', false ),
			'source_id'       => array( 'string', 'text', false ),
			'phone'           => array( 'string', 'text' ),
			'address'         => array( 'string', 'text' ),
			'street'          => array( 'string', 'text' ),
			'city_name'       => array( 'string', 'text' ),
			'state'           => array( 'string', 'state' ),
			'zip'             => array( 'string', 'text' ),
			'website'         => array( 'string', 'url' ),
			'google_place_id' => array( 'string', 'text' ),
			'maps_cid'        => array( 'string', 'cid' ),
			'google_maps_uri' => array( 'string', 'url' ),
			'hours_json'      => array( 'string', 'hours' ),
			'details_json'    => array( 'string', 'details' ),
			'types_json'      => array( 'string', 'list' ),
			'data_sources'    => array( 'string', 'list' ),
			'photos_json'     => array( 'string', 'photos' ),
			'summary'         => array( 'string', 'textarea' ),
			'patio_notes'     => array( 'string', 'textarea' ),
			'timezone'        => array( 'string', 'tz' ),
			'featured_media_url' => array( 'string', 'url' ),
			'lat'             => array( 'number', 'lat' ),
			'lng'             => array( 'number', 'lng' ),
			'rating'          => array( 'number', 'rating' ),
			'rating_count'    => array( 'integer', 'count' ),
			'price_level'     => array( 'integer', 'price' ),
			'price_range'     => array( 'string', 'text' ),
			'outdoor_seating' => array( 'string', 'enum' ),
			'business_status' => array( 'string', 'status' ),
			'enriched_at'     => array( 'string', 'date' ),
		);
	}

	/** Names a payload may set. */
	public static function payload_names() {
		return array_keys( self::definitions() );
	}

	public static function key( $name ) {
		return self::PREFIX . $name;
	}

	/**
	 * Sanitize one value. Returns '' when the value is unusable.
	 *
	 * @param string $name Field name without prefix.
	 * @param mixed  $value Raw value.
	 * @return string|int|float
	 */
	public static function sanitize( $name, $value ) {
		$defs = self::definitions();
		$kind = isset( $defs[ $name ] ) ? $defs[ $name ][1] : 'text';

		if ( is_array( $value ) && ! in_array( $kind, array( 'hours', 'photos', 'details', 'list' ), true ) ) {
			return '';
		}

		switch ( $kind ) {
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'url':
				return esc_url_raw( (string) $value, array( 'http', 'https' ) );
			case 'state':
				$s = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
				return 2 === strlen( $s ) ? $s : '';
			case 'tz':
				$tz = sanitize_text_field( (string) $value );
				return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : '';
			case 'lat':
				return ( is_numeric( $value ) && abs( (float) $value ) <= 90 ) ? (float) $value : '';
			case 'lng':
				return ( is_numeric( $value ) && abs( (float) $value ) <= 180 ) ? (float) $value : '';
			case 'rating':
				return ( is_numeric( $value ) && $value >= 0 && $value <= 5 ) ? round( (float) $value, 2 ) : '';
			case 'count':
				return ( is_numeric( $value ) && $value >= 0 ) ? (int) $value : '';
			case 'price':
				if ( is_string( $value ) && isset( self::PRICE_ENUM[ $value ] ) ) {
					return self::PRICE_ENUM[ $value ];
				}
				if ( is_string( $value ) && preg_match( '/^\${1,4}$/', trim( $value ) ) ) {
					return strlen( trim( $value ) );
				}
				return ( is_numeric( $value ) && $value >= 0 && $value <= 4 ) ? (int) $value : '';
			case 'cid':
				$c = preg_replace( '/\D/', '', (string) $value );
				return strlen( $c ) <= 24 ? $c : '';
			case 'status':
				$v = strtolower( str_replace( array( ' ', '-' ), '_', trim( (string) $value ) ) );
				return in_array( $v, array( 'open', 'temporarily_closed', 'permanently_closed' ), true ) ? $v : '';
			case 'enum':
				$v = strtolower( trim( (string) $value ) );
				return in_array( $v, array( 'yes', 'no', 'unknown' ), true ) ? $v : '';
			case 'date':
				return preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $value ) ? substr( (string) $value, 0, 10 ) : '';
			case 'hours':
				return self::clean_hours( $value );
			case 'photos':
				return self::clean_photos( $value );
			case 'details':
				return self::clean_details( $value );
			case 'list':
				return self::clean_list( $value );
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	private static function decode( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		$d = json_decode( (string) $value, true );
		return is_array( $d ) ? $d : null;
	}

	public const DAY_NAMES = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	/** Maps Data working_hours: { "Monday": "11 AM-10 PM", ... }. Keeps only the seven day names; values are free text. */
	private static function clean_hours( $value ) {
		$d = self::decode( $value );
		if ( null === $d ) {
			return '';
		}
		$lc  = array_change_key_case( $d, CASE_LOWER );
		$out = array();
		foreach ( self::DAY_NAMES as $day ) {
			$v = $lc[ strtolower( $day ) ] ?? null;
			if ( is_array( $v ) ) {
				$v = implode( ', ', array_filter( $v, 'is_scalar' ) );
			}
			if ( is_scalar( $v ) ) {
				$v = trim( sanitize_text_field( (string) $v ) );
				if ( '' !== $v && strlen( $v ) <= 120 ) {
					$out[ $day ] = $v;
				}
			}
		}
		return $out ? wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) : '';
	}

	/** Maps Data place.details: { "Section": ["Value", ...] }. Deduped, bounded. */
	private static function clean_details( $value ) {
		$d = self::decode( $value );
		if ( null === $d ) {
			return '';
		}
		$out = array();
		foreach ( $d as $section => $values ) {
			$section = trim( sanitize_text_field( (string) $section ) );
			if ( '' === $section || strlen( $section ) > 60 || ! is_array( $values ) ) {
				continue;
			}
			$vals = array();
			foreach ( $values as $v ) {
				if ( is_scalar( $v ) ) {
					$v = trim( sanitize_text_field( (string) $v ) );
					if ( '' !== $v && strlen( $v ) <= 100 ) {
						$vals[ $v ] = true;
					}
				}
			}
			if ( $vals ) {
				$out[ $section ] = array_slice( array_keys( $vals ), 0, 60 );
			}
		}
		return $out ? wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) : '';
	}

	/** JSON list of short strings (data_sources, types). */
	private static function clean_list( $value ) {
		$d = self::decode( $value );
		if ( null === $d ) {
			return '';
		}
		$out = array();
		foreach ( $d as $v ) {
			if ( is_scalar( $v ) ) {
				$v = trim( sanitize_text_field( (string) $v ) );
				if ( '' !== $v && strlen( $v ) <= 60 ) {
					$out[ $v ] = true;
				}
			}
		}
		return $out ? wp_json_encode( array_slice( array_keys( $out ), 0, 30 ), JSON_UNESCAPED_UNICODE ) : '';
	}

	/** Array of { url, width, height, attribution_html }. */
	private static function clean_photos( $value ) {
		$d = self::decode( $value );
		if ( null === $d ) {
			return '';
		}
		$out = array();
		foreach ( $d as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$url = isset( $p['url'] ) ? esc_url_raw( (string) $p['url'], array( 'http', 'https' ) ) : '';
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'url'              => $url,
				'width'            => isset( $p['width'] ) ? (int) $p['width'] : 0,
				'height'           => isset( $p['height'] ) ? (int) $p['height'] : 0,
				'attribution_html' => wp_kses(
					isset( $p['attribution_html'] ) ? (string) $p['attribution_html'] : '',
					array( 'a' => array( 'href' => true ) )
				),
			);
		}
		return $out ? wp_json_encode( $out ) : '';
	}
}
