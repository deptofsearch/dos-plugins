<?php
/**
 * Small shared helpers: meta access, formatting, city term lookups, sorted venue queries.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Util {

	public static function meta( $post_id, $name ) {
		return get_post_meta( $post_id, Fields::key( $name ), true );
	}

	/** "Phoenix, AZ" => array( 'Phoenix', 'AZ' ) or null. */
	public static function parse_city( $city ) {
		if ( preg_match( '/^\s*(.+?)\s*,\s*([A-Za-z]{2})\s*$/', (string) $city, $m ) ) {
			return array( trim( $m[1] ), strtoupper( $m[2] ) );
		}
		return null;
	}

	public static function city_label( $term_name ) {
		$p = self::parse_city( $term_name );
		return $p ? $p[0] : (string) $term_name;
	}

	public static function format_phone( $raw ) {
		$digits = preg_replace( '/\D/', '', (string) $raw );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		if ( 10 === strlen( $digits ) ) {
			return array( sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6 ) ), '+1' . $digits );
		}
		return array( (string) $raw, preg_replace( '/[^\d+]/', '', (string) $raw ) );
	}

	public static function price_string( $level ) {
		$level = (int) $level;
		return ( $level >= 1 && $level <= 4 ) ? str_repeat( '$', $level ) : '';
	}

	/** "$$" from price_level, else Google's range text like "$10–60". */
	public static function price_label( $post_id ) {
		$s = self::price_string( self::meta( $post_id, 'price_level' ) );
		return '' !== $s ? $s : (string) self::meta( $post_id, 'price_range' );
	}

	public static function timezone( $post_id ) {
		$tz = self::meta( $post_id, 'timezone' );
		if ( $tz ) {
			return $tz;
		}
		$map = apply_filters( 'osn_state_timezones', States::tz_map() );
		$st  = strtoupper( (string) self::meta( $post_id, 'state' ) );
		return isset( $map[ $st ] ) ? $map[ $st ] : '';
	}

	public static function first_term( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		return ( $terms && ! is_wp_error( $terms ) ) ? reset( $terms ) : null;
	}

	/** Lowercase that survives accents when mbstring is present. */
	public static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}

	/** First character of a venue name, uppercased, for the card placeholder. */
	public static function initial( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s ) {
			return '';
		}
		// Skip a leading article so "The Vig" gets V, not T.
		if ( preg_match( '/^(?:the|an?|el|la|le|los|las)\s+(\S.*)$/iu', $s, $m ) ) {
			$s = $m[1];
		}
		$c = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 1, 'UTF-8' ) : substr( $s, 0, 1 );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $c, 'UTF-8' ) : strtoupper( $c );
	}

	/** Attachment ID of the venue's featured image, 0 when none (or when the attachment is gone). */
	public static function thumb_id( $post_id ) {
		$tid = (int) get_post_meta( $post_id, '_thumbnail_id', true );
		return ( $tid > 0 && 'attachment' === get_post_type( $tid ) ) ? $tid : 0;
	}

	/** @return array[] Decoded photo rows. */
	public static function photos( $post_id ) {
		$d = json_decode( (string) self::meta( $post_id, 'photos_json' ), true );
		return is_array( $d ) ? $d : array();
	}

	/** @return array<string,string> Day name => hours text, in Monday-first order. Empty when unset or not the Maps Data shape. */
	public static function hours( $post_id ) {
		$d = json_decode( (string) self::meta( $post_id, 'hours_json' ), true );
		if ( ! is_array( $d ) ) {
			return array();
		}
		$out = array();
		foreach ( Fields::DAY_NAMES as $day ) {
			if ( isset( $d[ $day ] ) && is_string( $d[ $day ] ) && '' !== $d[ $day ] ) {
				$out[ $day ] = $d[ $day ];
			}
		}
		return $out;
	}

	/** @return array<string,string[]> Section => values. */
	public static function details( $post_id ) {
		$d = json_decode( (string) self::meta( $post_id, 'details_json' ), true );
		return is_array( $d ) ? $d : array();
	}

	/** @return string[] */
	public static function list_meta( $post_id, $name ) {
		$d = json_decode( (string) self::meta( $post_id, $name ), true );
		return is_array( $d ) ? array_values( array_filter( $d, 'is_string' ) ) : array();
	}

	/**
	 * One day's hours text to minute ranges from midnight. A range that closes after midnight
	 * has close > 1440. Mirrored by parseDay() in assets/osn.js.
	 *
	 * @return array[]|null  list of array( open, close ); empty list = closed; null = unparseable.
	 */
	public static function parse_day_ranges( $text ) {
		$s = trim( str_replace( array( "\u{202F}", "\u{00A0}", "\u{2009}" ), ' ', (string) $text ) );
		if ( '' === $s ) {
			return null;
		}
		if ( preg_match( '/^closed$/i', $s ) ) {
			return array();
		}
		if ( preg_match( '/^open\s+24\s+hours?$/i', $s ) ) {
			return array( array( 0, 1440 ) );
		}
		$out = array();
		foreach ( preg_split( '/\s*,\s*/', $s ) as $part ) {
			if ( ! preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*([AaPp])\.?[Mm]?\.?\s*[\x{2013}\x{2014}-]\s*(\d{1,2})(?::(\d{2}))?\s*([AaPp])\.?[Mm]?\.?$/u', $part, $m ) && ! preg_match( '/^(\d{1,2})(?::(\d{2}))?()\s*[\x{2013}\x{2014}-]\s*(\d{1,2})(?::(\d{2}))?\s*([AaPp])\.?[Mm]?\.?$/u', $part, $m ) ) {
				return null;
			}
			$h1 = (int) $m[1];
			$m1 = '' !== $m[2] ? (int) $m[2] : 0;
			$h2 = (int) $m[4];
			$m2 = isset( $m[5] ) && '' !== $m[5] ? (int) $m[5] : 0;
			if ( $h1 < 1 || $h1 > 12 || $h2 < 1 || $h2 > 12 || $m1 > 59 || $m2 > 59 ) {
				return null;
			}
			$pm2 = 'p' === strtolower( $m[6] );
			$has = '' !== $m[3];
			$pm1 = $has ? 'p' === strtolower( $m[3] ) : $pm2;
			$c   = ( $h2 % 12 + ( $pm2 ? 12 : 0 ) ) * 60 + $m2;
			$o   = ( $h1 % 12 + ( $pm1 ? 12 : 0 ) ) * 60 + $m1;
			if ( ! $has && $o > $c ) {
				$o = ( $h1 % 12 + ( $pm1 ? 0 : 12 ) ) * 60 + $m1; // "11-2 PM": the open time is AM.
			}
			if ( $c <= $o ) {
				$c += 1440;
			}
			$out[] = array( $o, $c );
		}
		return $out;
	}

	/** @return array<string,array[]> Day name => ranges, only for days that parsed. */
	public static function parsed_hours( array $hours ) {
		$out = array();
		foreach ( $hours as $day => $text ) {
			$r = self::parse_day_ranges( $text );
			if ( null !== $r ) {
				$out[ $day ] = $r;
			}
		}
		return $out;
	}

	public static function is_permanently_closed( $post_id ) {
		return 'permanently_closed' === self::meta( $post_id, 'business_status' );
	}

	/** "Name address" for map queries; the address often already starts with the name (Maps full_address). */
	private static function name_address( $post_id, $title ) {
		$addr = trim( (string) self::meta( $post_id, 'address' ) );
		return ( '' !== $addr && 0 === stripos( $addr, $title ) ) ? $addr : trim( $title . ' ' . $addr );
	}

	/** Google Maps embed URL: lat/lng when known, else name + address. */
	public static function embed_url( $post_id, $title ) {
		$lat = self::meta( $post_id, 'lat' );
		$lng = self::meta( $post_id, 'lng' );
		if ( '' !== $lat && '' !== $lng ) {
			return 'https://maps.google.com/maps?q=' . (float) $lat . ',' . (float) $lng . '&z=16&output=embed';
		}
		return 'https://maps.google.com/maps?q=' . rawurlencode( self::name_address( $post_id, $title ) ) . '&output=embed';
	}

	/** "Get directions" link, built at render time. */
	public static function directions_url( $post_id, $title ) {
		$url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( self::name_address( $post_id, $title ) );
		$pid = (string) self::meta( $post_id, 'google_place_id' );
		if ( '' !== $pid ) {
			$url .= '&query_place_id=' . rawurlencode( $pid );
		}
		return $url;
	}

	/** Prime post caches for the landing pages of these terms in one query. */
	public static function prime_landing_pages( array $terms ) {
		$ids = array();
		foreach ( $terms as $t ) {
			$id = (int) get_term_meta( $t->term_id, 'osn_landing_page_id', true );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		if ( $ids ) {
			_prime_post_caches( array_values( array_unique( $ids ) ), false, false );
		}
	}

	/** Published landing page URL for a city term, or the term's own link, or ''. */
	public static function city_url( $term ) {
		if ( ! $term ) {
			return '';
		}
		$page_id = (int) get_term_meta( $term->term_id, 'osn_landing_page_id', true );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}
		return '';
	}

	/** Find a city term from "City, ST" (slug first, then name). */
	public static function find_city( $city ) {
		$city = trim( (string) $city );
		if ( '' === $city ) {
			return null;
		}
		$t = get_term_by( 'slug', sanitize_title( $city ), Data_Model::CITY );
		if ( ! $t ) {
			$t = get_term_by( 'name', $city, Data_Model::CITY );
		}
		return $t instanceof \WP_Term ? $t : null;
	}

	const SORTED_TRANSIENT = 'osn_sorted_';

	/** Drop the cached sorted venue ID list for a city term. */
	public static function purge_sorted( $term_id ) {
		delete_transient( self::SORTED_TRANSIENT . (int) $term_id );
		self::$has_cache = array();
		State_Cities::flush(); // State pages show per-city counts and photos from the same venues.
	}

	/** @var array<int,bool> */
	private static $has_cache = array();

	/**
	 * Published, not permanently closed venue IDs for a city (or all when $term_id is 0), sorted:
	 * outdoor seating yes, then rating count desc, then has a photo, then has summary, then title. The city's full
	 * list is cached in a transient keyed by term ID; the upsert purges it.
	 *
	 * @return int[]
	 */
	public static function sorted_ids( $term_id = 0, $limit = 0 ) {
		$term_id = (int) $term_id;
		$out     = $term_id ? get_transient( self::SORTED_TRANSIENT . $term_id ) : false;
		if ( ! is_array( $out ) ) {
			$out = self::build_sorted( $term_id );
			if ( $term_id ) {
				set_transient( self::SORTED_TRANSIENT . $term_id, $out, 12 * HOUR_IN_SECONDS );
			}
		}
		return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
	}

	private static function build_sorted( $term_id ) {
		$cap  = (int) apply_filters( 'osn_max_cards', 1500 );
		$args = array(
			'post_type'              => Data_Model::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => $cap,
			'fields'                 => 'ids',
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);
		if ( $term_id ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => Data_Model::CITY,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			);
		}
		$ids = ( new \WP_Query( $args ) )->posts;
		if ( ! $ids ) {
			return array();
		}
		update_meta_cache( 'post', $ids );

		$rank = array( 'yes' => 0, 'unknown' => 1, 'no' => 2 );
		$rows = array();
		foreach ( $ids as $i => $id ) {
			if ( self::is_permanently_closed( $id ) ) {
				continue;
			}
			$os     = (string) self::meta( $id, 'outdoor_seating' );
			$rows[] = array(
				(int) $id,
				isset( $rank[ $os ] ) ? $rank[ $os ] : 1,
				(int) self::meta( $id, 'rating_count' ),
				(int) get_post_meta( $id, '_thumbnail_id', true ) > 0 ? 0 : 1, // Meta only: no per-venue attachment lookups.
				'' !== trim( (string) self::meta( $id, 'summary' ) ) ? 0 : 1,
				$i,
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				foreach ( array( 1 => 1, 2 => -1, 3 => 1, 4 => 1, 5 => 1 ) as $k => $dir ) {
					if ( $a[ $k ] !== $b[ $k ] ) {
						return $dir * ( $a[ $k ] - $b[ $k ] );
					}
				}
				return 0; // Index 5 is the title order from the query.
			}
		);
		return array_map( 'intval', wp_list_pluck( $rows, 0 ) );
	}

	/** True when the city has at least one published venue that is not permanently closed. */
	public static function city_has_venues( $term ) {
		if ( ! $term ) {
			return false;
		}
		if ( ! isset( self::$has_cache[ $term->term_id ] ) ) {
			$q = new \WP_Query(
				array(
					'post_type'      => Data_Model::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => Data_Model::CITY,
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						),
					),
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array( 'key' => Fields::key( 'business_status' ), 'compare' => 'NOT EXISTS' ),
						array( 'key' => Fields::key( 'business_status' ), 'value' => 'permanently_closed', 'compare' => '!=' ),
					),
				)
			);
			self::$has_cache[ $term->term_id ] = ! empty( $q->posts );
		}
		return self::$has_cache[ $term->term_id ];
	}

	public static function json_ld( $data ) {
		// DoS Toolkit's SEO module owns the Organization / WebSite / WebPage graph; never print a second copy.
		if ( isset( $data['@type'] ) && in_array( $data['@type'], array( 'Organization', 'WebSite', 'WebPage' ), true ) && Seo::toolkit_active() ) {
			return '';
		}
		return '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	}
}
