<?php
/**
 * Post types (blnm_city, blnm_lender), meta registration, and lender-array sanitizing.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Data_Model {

	const CITY   = 'blnm_city';
	const LENDER = 'blnm_lender';

	const LENDER_TYPES = array( 'bank', 'credit_union', 'mortgage_company' );
	const LOAN_TYPES   = array( 'conventional', 'fha', 'va', 'usda', 'jumbo' );

	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'derive' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'derive' ), 10, 4 );
	}

	public static function register() {
		register_post_type(
			self::CITY,
			array(
				'labels'          => array(
					'name'          => __( 'BLNM Cities', 'dos-best-lenders' ),
					'singular_name' => __( 'BLNM City', 'dos-best-lenders' ),
					'all_items'     => __( 'All Cities', 'dos-best-lenders' ),
					'edit_item'     => __( 'Edit City', 'dos-best-lenders' ),
					'add_new_item'  => __( 'Add New City', 'dos-best-lenders' ),
					'search_items'  => __( 'Search Cities', 'dos-best-lenders' ),
					'not_found'     => __( 'No cities found.', 'dos-best-lenders' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_rest'    => true,
				'rest_base'       => 'blnm-cities',
				'menu_icon'       => 'dashicons-location-alt',
				'menu_position'   => 26,
				// Post name is "<city>-<st>", e.g. /mortgage-lenders/kennewick-wa/
				'rewrite'         => array( 'slug' => 'mortgage-lenders', 'with_front' => false ),
				'has_archive'     => false,
				'supports'        => array( 'title', 'editor', 'excerpt', 'custom-fields' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		register_post_type(
			self::LENDER,
			array(
				'labels'          => array(
					'name'          => __( 'BLNM Lenders', 'dos-best-lenders' ),
					'singular_name' => __( 'BLNM Lender', 'dos-best-lenders' ),
					'all_items'     => __( 'All Lenders', 'dos-best-lenders' ),
					'edit_item'     => __( 'Edit Lender', 'dos-best-lenders' ),
					'add_new_item'  => __( 'Add New Lender', 'dos-best-lenders' ),
					'search_items'  => __( 'Search Lenders', 'dos-best-lenders' ),
					'not_found'     => __( 'No lenders found.', 'dos-best-lenders' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_rest'    => true,
				'rest_base'       => 'blnm-lenders',
				'menu_icon'       => 'dashicons-bank',
				'menu_position'   => 27,
				// Post name is the lowercased LEI (or a slug when no LEI), e.g. /lenders/549300abc.../
				'rewrite'         => array( 'slug' => 'lenders', 'with_front' => false ),
				'has_archive'     => false,
				'supports'        => array( 'title', 'editor', 'custom-fields' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		$auth = static function () {
			return current_user_can( 'edit_posts' );
		};

		$city_meta = array(
			'blnm_city_name'    => array( 'string', 'City name, e.g. Kennewick.', 'sanitize_text_field' ),
			'blnm_state'        => array( 'string', 'Two-letter state code.', array( __CLASS__, 'sanitize_state' ) ),
			'blnm_county_fips'  => array( 'string', '5-digit county FIPS the HMDA pull was made for.', array( __CLASS__, 'sanitize_fips' ) ),
			'blnm_county_name'  => array( 'string', 'County name without the word County, e.g. Benton. Used in the page header.', array( __CLASS__, 'sanitize_county_name' ) ),
			'blnm_data_year'    => array( 'integer', 'HMDA data year, e.g. 2025.', 'absint' ),
			'blnm_updated_at'   => array( 'string', 'ISO 8601 time the lender data was last refreshed.', 'sanitize_text_field' ),
			'blnm_lenders_json' => array( 'string', 'JSON array of lender stats for this city. See Data_Model::sanitize_lenders().', array( __CLASS__, 'sanitize_lenders_json' ) ),
			// Derived on write from blnm_lenders_json; read-only in practice.
			'blnm_lender_count' => array( 'integer', 'Derived: number of lenders in blnm_lenders_json.', 'absint' ),
			'blnm_loan_total'   => array( 'integer', 'Derived: sum of loans_2025 across lenders.', 'absint' ),
			'blnm_reviewed_at'  => array( 'string', 'YYYY-MM-DD the Google review check last ran for this city\'s county. Empty = not checked yet.', array( __CLASS__, 'sanitize_date' ) ),
			'blnm_nearby_json'  => array( 'string', 'JSON: up to 3 nearest towns with listed lenders, shown when this city has none. See Data_Model::sanitize_nearby().', array( __CLASS__, 'sanitize_nearby_json' ) ),
		);
		foreach ( $city_meta as $key => $def ) {
			register_post_meta(
				self::CITY,
				$key,
				array(
					'type'              => $def[0],
					'description'       => $def[1],
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $def[2],
					'auth_callback'     => $auth,
				)
			);
		}

		$lender_meta = array(
			'blnm_lei'         => array( 'Legal Entity Identifier (20 chars).', array( __CLASS__, 'sanitize_lei' ) ),
			'blnm_lender_type' => array( 'bank | credit_union | mortgage_company.', array( __CLASS__, 'sanitize_lender_type' ) ),
			'blnm_hq'          => array( 'Headquarters, e.g. "Seattle, WA" (from GLEIF).', 'sanitize_text_field' ),
			'blnm_nmls_url'    => array( 'NMLS Consumer Access link for this lender.', 'esc_url_raw' ),
			'blnm_place_id'    => array( 'Google Places place_id, if known.', 'sanitize_text_field' ),
			'blnm_summary'     => array( 'Short plain-text paragraph about the lender, shown on the profile page.', array( __CLASS__, 'sanitize_summary' ) ),
		);
		foreach ( $lender_meta as $key => $def ) {
			register_post_meta(
				self::LENDER,
				$key,
				array(
					'type'              => 'string',
					'description'       => $def[0],
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $def[1],
					'auth_callback'     => $auth,
				)
			);
		}

		// Review fields on the lender profile (same names as the lender row keys, prefixed blnm_).
		// Stored as one JSON string so the shape stays identical to the per-city rows.
		register_post_meta(
			self::LENDER,
			'blnm_review_json',
			array(
				'type'              => 'string',
				'description'       => 'JSON object of review fields (google_rating, google_review_count, google_maps_url, rating_as_of, review_summary, review_highlights, review_pros, review_cons, is_builder_lender, branch_name, branch_address).',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $v ) {
					$a = is_array( $v ) ? $v : json_decode( (string) $v, true );
					return wp_json_encode( (object) self::sanitize_review( is_array( $a ) ? $a : array() ) );
				},
				'auth_callback'     => $auth,
			)
		);
	}

	/** Keep blnm_lender_count / blnm_loan_total in step with blnm_lenders_json, however it was written. */
	public static function derive( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( 'blnm_lenders_json' !== $meta_key ) {
			return;
		}
		$rows = is_string( $meta_value ) ? json_decode( $meta_value, true ) : null;
		$rows = is_array( $rows ) ? $rows : array();
		$sum  = 0;
		foreach ( $rows as $r ) {
			$sum += isset( $r['loans_2025'] ) ? (int) $r['loans_2025'] : 0;
		}
		update_post_meta( $post_id, 'blnm_lender_count', count( $rows ) );
		update_post_meta( $post_id, 'blnm_loan_total', $sum );
	}

	public static function sanitize_state( $v ) {
		return strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $v ), 0, 2 ) );
	}

	public static function sanitize_fips( $v ) {
		return substr( preg_replace( '/\D/', '', (string) $v ), 0, 5 );
	}

	/** County name stored without the trailing word "County" (the header adds it). */
	public static function sanitize_date( $v ) {
		$v = (string) $v;
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $v : '';
	}

	/**
	 * Nearest towns with listed lenders, for cities whose county has none. Up to 3 rows:
	 * slug (city post slug, required) · city · state · county (without "County") · distance_mi (0-500) ·
	 * lender_count · top_lenders (up to 3 display names). The URL is resolved from the slug at render time.
	 */
	public static function sanitize_nearby( $rows ) {
		if ( is_string( $rows ) ) {
			$rows = json_decode( $rows, true );
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$slug = sanitize_title( (string) ( $r['slug'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}
			$names = array();
			foreach ( array_slice( (array) ( $r['top_lenders'] ?? array() ), 0, 3 ) as $n ) {
				$n = self::plain_text( is_scalar( $n ) ? $n : '', 120 );
				if ( '' !== $n ) {
					$names[] = $n;
				}
			}
			$out[] = array(
				'slug'         => $slug,
				'city'         => self::plain_text( $r['city'] ?? '', 80 ),
				'state'        => self::sanitize_state( $r['state'] ?? '' ),
				'county'       => self::sanitize_county_name( $r['county'] ?? '' ),
				'distance_mi'  => isset( $r['distance_mi'] ) && is_numeric( $r['distance_mi'] ) ? (int) round( max( 0, min( 500, (float) $r['distance_mi'] ) ) ) : null,
				'lender_count' => (int) max( 0, (int) ( $r['lender_count'] ?? 0 ) ),
				'top_lenders'  => $names,
			);
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return $out;
	}

	public static function sanitize_nearby_json( $v ) {
		return wp_json_encode( self::sanitize_nearby( $v ) );
	}

	public static function city_nearby( $post_id ) {
		return self::sanitize_nearby( (string) get_post_meta( $post_id, 'blnm_nearby_json', true ) );
	}

	public static function sanitize_county_name( $v ) {
		return trim( preg_replace( '/\s+County$/i', '', sanitize_text_field( (string) $v ) ) );
	}

	public static function sanitize_summary( $v ) {
		return self::plain_text( $v, 900 );
	}

	/** Strip tags, collapse whitespace, cap length. */
	public static function plain_text( $v, $max ) {
		$t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $v ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $t, 0, $max ) : substr( $t, 0, $max );
	}

	public static function sanitize_lei( $v ) {
		return strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ), 0, 20 ) );
	}

	public static function sanitize_lender_type( $v ) {
		return in_array( $v, self::LENDER_TYPES, true ) ? $v : 'mortgage_company';
	}

	/** Meta sanitize callback: decode, clean, re-encode. Invalid JSON becomes "[]". */
	public static function sanitize_lenders_json( $value ) {
		$rows = is_array( $value ) ? $value : json_decode( (string) $value, true );
		return wp_json_encode( self::sanitize_lenders( is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * Whitelist and clamp lender rows. Units: approval_rate, median_rate and city_median_rate are
	 * percentages (87.4 means 87.4%, 6.375 means 6.375%). score is 0-100. loans_2025 is an integer.
	 * Rate fields may be null when HMDA has no rate for that lender.
	 */
	public static function sanitize_lenders( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || empty( $r['name'] ) ) {
				continue;
			}
			$num = static function ( $key, $min, $max ) use ( $r ) {
				if ( ! isset( $r[ $key ] ) || '' === $r[ $key ] || ! is_numeric( $r[ $key ] ) ) {
					return null;
				}
				return round( max( $min, min( $max, (float) $r[ $key ] ) ), 3 );
			};
			$types = array();
			foreach ( (array) ( $r['loan_types'] ?? array() ) as $t ) {
				$t = strtolower( (string) $t );
				if ( in_array( $t, self::LOAN_TYPES, true ) ) {
					$types[ $t ] = $t;
				}
			}
			$row = array(
				'lei'              => self::sanitize_lei( $r['lei'] ?? '' ),
				'name'             => sanitize_text_field( $r['name'] ),
				'type'             => self::sanitize_lender_type( $r['type'] ?? '' ),
				'loans_2025'       => (int) max( 0, (int) ( $r['loans_2025'] ?? 0 ) ),
				'approval_rate'    => $num( 'approval_rate', 0, 100 ),
				'median_rate'      => $num( 'median_rate', 0, 25 ),
				'city_median_rate' => $num( 'city_median_rate', 0, 25 ),
				'loan_types'       => array_values( $types ),
				'score'            => $num( 'score', 0, 100 ),
				'nmls_url'         => esc_url_raw( (string) ( $r['nmls_url'] ?? '' ), array( 'https' ) ),
			);
			$place = sanitize_text_field( (string) ( $r['place_id'] ?? '' ) );
			if ( '' !== $place ) {
				$row['place_id'] = $place;
			}
			$out[] = $row + self::sanitize_review( $r );
		}
		return $out;
	}

	/**
	 * Optional review/branch fields, whitelisted and clamped. Only keys that are present and valid are
	 * returned, so a lender without reviews stays free of empty keys. Used for city rows and lender profiles.
	 *
	 * google_rating 0-5 (1 decimal) · google_review_count int · google_maps_url https only ·
	 * rating_as_of YYYY-MM-DD · review_summary plain text <= 900 chars · review_pros <= 4 strings ·
	 * review_cons <= 3 strings · review_highlights <= 3 paraphrased client lines ("One client said ..."), 240 chars each ·
	 * is_builder_lender bool · branch_name / branch_address strings.
	 */
	public static function sanitize_review( array $r ) {
		$o = array();
		if ( isset( $r['google_rating'] ) && '' !== $r['google_rating'] && is_numeric( $r['google_rating'] ) ) {
			$o['google_rating'] = round( max( 0, min( 5, (float) $r['google_rating'] ) ), 1 );
		}
		if ( isset( $r['google_review_count'] ) && '' !== $r['google_review_count'] && is_numeric( $r['google_review_count'] ) ) {
			$o['google_review_count'] = (int) max( 0, (int) $r['google_review_count'] );
		}
		$url = esc_url_raw( (string) ( $r['google_maps_url'] ?? '' ), array( 'https' ) );
		if ( '' !== $url ) {
			$o['google_maps_url'] = $url;
		}
		$d = (string) ( $r['rating_as_of'] ?? '' );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			$o['rating_as_of'] = $d;
		}
		$sum = self::plain_text( $r['review_summary'] ?? '', 900 );
		if ( '' !== $sum ) {
			$o['review_summary'] = $sum;
		}
		foreach ( array( 'review_highlights' => 3, 'review_pros' => 4, 'review_cons' => 3 ) as $k => $cap ) {
			$list = array();
			foreach ( (array) ( $r[ $k ] ?? array() ) as $item ) {
				$item = self::plain_text( is_scalar( $item ) ? $item : '', 'review_highlights' === $k ? 240 : 120 );
				if ( '' !== $item ) {
					$list[] = $item;
				}
			}
			if ( $list ) {
				$o[ $k ] = array_slice( $list, 0, $cap );
			}
		}
		if ( ! empty( $r['is_builder_lender'] ) && ! in_array( $r['is_builder_lender'], array( 'false', '0', 'no' ), true ) ) {
			$o['is_builder_lender'] = true;
		}
		foreach ( array( 'branch_name' => 120, 'branch_address' => 200 ) as $k => $cap ) {
			$v = self::plain_text( $r[ $k ] ?? '', $cap );
			if ( '' !== $v ) {
				$o[ $k ] = $v;
			}
		}
		return $o;
	}

	/** Decoded, sanitized lender rows for a city post. */
	public static function city_lenders( $post_id ) {
		$rows = json_decode( (string) get_post_meta( $post_id, 'blnm_lenders_json', true ), true );
		return is_array( $rows ) ? $rows : array();
	}
}
