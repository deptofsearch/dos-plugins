<?php
/**
 * REST routes under blnm/v1.
 *
 * The standard wp/v2/blnm-cities and wp/v2/blnm-lenders routes also exist (show_in_rest, meta
 * registered), so n8n can write with plain wp/v2 too. These routes add an idempotent upsert by
 * slug and the public city list the search box reads.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NAMESPACE_V1 = 'blnm/v1';
	const TRANSIENT    = 'blnm_city_index_v1';

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_flush' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_on_meta' ), 10, 3 );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/cities',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => array(
					'state' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( Data_Model::class, 'sanitize_state' ),
					),
				),
				'callback'            => array( __CLASS__, 'list_cities' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cities/upsert',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_publish' ),
				'callback'            => array( __CLASS__, 'upsert_city' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/lenders/upsert',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'can_publish' ),
				'callback'            => array( __CLASS__, 'upsert_lender' ),
			)
		);
	}

	public static function can_publish() {
		return current_user_can( 'publish_posts' );
	}

	/** Public list: [ { n: "Kennewick, WA", u: url, s: "WA" } ], A to Z. */
	public static function index() {
		$index = get_transient( self::TRANSIENT );
		if ( false === $index ) {
			$ids   = get_posts(
				array(
					'post_type'      => Data_Model::CITY,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$index = array();
			foreach ( $ids as $id ) {
				$city  = get_post_meta( $id, 'blnm_city_name', true );
				$state = get_post_meta( $id, 'blnm_state', true );
				if ( '' === $city ) {
					continue;
				}
				$index[] = array(
					'n' => $city . ( $state ? ', ' . $state : '' ),
					'u' => get_permalink( $id ),
					's' => $state,
				);
			}
			usort(
				$index,
				static function ( $a, $b ) {
					return strnatcasecmp( $a['n'], $b['n'] );
				}
			);
			set_transient( self::TRANSIENT, $index, 12 * HOUR_IN_SECONDS );
		}
		return $index;
	}

	public static function list_cities( \WP_REST_Request $request ) {
		$rows  = self::index();
		$state = (string) $request->get_param( 'state' );
		if ( '' !== $state ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $state ) {
						return $r['s'] === $state;
					}
				)
			);
		}
		$res = rest_ensure_response( $rows );
		$res->header( 'Cache-Control', 'public, max-age=3600' );
		return $res;
	}

	public static function maybe_flush( $new, $old, $post ) {
		if ( Data_Model::CITY === $post->post_type && ( 'publish' === $new || 'publish' === $old ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	public static function flush_on_meta( $meta_id, $post_id, $meta_key ) {
		if ( in_array( $meta_key, array( 'blnm_city_name', 'blnm_state' ), true ) && Data_Model::CITY === get_post_type( $post_id ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * POST blnm/v1/cities/upsert
	 * Body: slug (required, "<city>-<st>"), city_name (required when creating), state, county_fips, county_name,
	 *       data_year, lenders (array or JSON string), title, content, excerpt, status (draft|publish|pending),
	 *       updated_at, reviewed_at (YYYY-MM-DD), nearby (up to 3 nearest towns with lenders; see
	 *       Data_Model::sanitize_nearby(), shown only when the city has no lenders).
	 *
	 * PARTIAL UPDATES (0.2.0): when the slug already exists, only the keys present in the body are changed.
	 * Omitting `lenders` leaves blnm_lenders_json untouched; same for title, content, excerpt, status, state,
	 * county_fips, county_name, data_year and city_name. Send `"lenders": []` to clear the list on purpose.
	 * On create, defaults apply: title "Mortgage Lenders in <City>, <ST>", status publish, data_year 2025.
	 * blnm_updated_at is set to now whenever `lenders` is sent (or on create) unless `updated_at` is given.
	 * Returns { id, action, url, status, lender_count }.
	 */
	public static function upsert_city( \WP_REST_Request $request ) {
		$p    = $request->get_json_params();
		$p    = is_array( $p ) ? $p : $request->get_params();
		$has  = static function ( $k ) use ( $p ) {
			return array_key_exists( $k, $p );
		};
		$slug = sanitize_title( (string) ( $p['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new \WP_Error( 'blnm_bad_request', 'slug is required.', array( 'status' => 400 ) );
		}
		$existing = get_page_by_path( $slug, OBJECT, Data_Model::CITY );
		$name     = sanitize_text_field( (string) ( $p['city_name'] ?? '' ) );
		if ( ! $existing && '' === $name ) {
			return new \WP_Error( 'blnm_bad_request', 'city_name is required when creating a city.', array( 'status' => 400 ) );
		}
		$state = Data_Model::sanitize_state( $p['state'] ?? '' );

		$args = array(
			'post_type' => Data_Model::CITY,
			'post_name' => $slug,
		);
		if ( $has( 'status' ) || ! $existing ) {
			$args['post_status'] = in_array( $p['status'] ?? 'publish', array( 'draft', 'publish', 'pending' ), true ) ? $p['status'] : 'publish';
		}
		if ( $has( 'title' ) && '' !== $p['title'] ) {
			$args['post_title'] = sanitize_text_field( $p['title'] );
		} elseif ( ! $existing ) {
			$args['post_title'] = sprintf( 'Mortgage Lenders in %s, %s', $name, $state );
		}
		if ( $has( 'content' ) ) {
			$args['post_content'] = wp_kses_post( $p['content'] );
		}
		if ( $has( 'excerpt' ) ) {
			$args['post_excerpt'] = sanitize_text_field( $p['excerpt'] );
		}
		if ( $existing ) {
			$args['ID'] = $existing->ID;
			$id         = wp_update_post( wp_slash( $args ), true );
		} else {
			$id = wp_insert_post( wp_slash( $args ), true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( '' !== $name ) {
			update_post_meta( $id, 'blnm_city_name', $name );
		}
		if ( $has( 'state' ) || ! $existing ) {
			update_post_meta( $id, 'blnm_state', $state );
		}
		if ( $has( 'county_fips' ) || ! $existing ) {
			update_post_meta( $id, 'blnm_county_fips', Data_Model::sanitize_fips( $p['county_fips'] ?? '' ) );
		}
		if ( $has( 'county_name' ) || ! $existing ) {
			update_post_meta( $id, 'blnm_county_name', Data_Model::sanitize_county_name( $p['county_name'] ?? '' ) );
		}
		if ( $has( 'data_year' ) || ! $existing ) {
			update_post_meta( $id, 'blnm_data_year', absint( $p['data_year'] ?? 2025 ) );
		}
		if ( $has( 'updated_at' ) || $has( 'lenders' ) || ! $existing ) {
			update_post_meta( $id, 'blnm_updated_at', sanitize_text_field( $p['updated_at'] ?? gmdate( 'c' ) ) );
		}
		if ( $has( 'lenders' ) || ! $existing ) {
			// update_post_meta unslashes its value, so slash the JSON to keep its backslashes.
			update_post_meta( $id, 'blnm_lenders_json', wp_slash( Data_Model::sanitize_lenders_json( $p['lenders'] ?? array() ) ) );
		}
		if ( $has( 'reviewed_at' ) ) {
			update_post_meta( $id, 'blnm_reviewed_at', Data_Model::sanitize_date( $p['reviewed_at'] ) );
		}
		if ( $has( 'nearby' ) ) {
			update_post_meta( $id, 'blnm_nearby_json', wp_slash( Data_Model::sanitize_nearby_json( $p['nearby'] ) ) );
		}

		return rest_ensure_response(
			array(
				'id'           => $id,
				'action'       => $existing ? 'updated' : 'created',
				'url'          => get_permalink( $id ),
				'status'       => get_post_status( $id ),
				'lender_count' => (int) get_post_meta( $id, 'blnm_lender_count', true ),
			)
		);
	}

	/**
	 * POST blnm/v1/lenders/upsert
	 * Body: lei (or slug), name, type, hq, nmls_url, place_id, summary, content, status, plus the optional review
	 * fields (google_rating, google_review_count, google_maps_url, rating_as_of, review_summary, review_pros,
	 * review_cons, is_builder_lender, branch_name, branch_address). Slug is the lowercased LEI.
	 * Review fields are replaced as a set when any of them is sent; omit them all to leave reviews untouched.
	 */
	public static function upsert_lender( \WP_REST_Request $request ) {
		$p    = $request->get_json_params();
		$p    = is_array( $p ) ? $p : $request->get_params();
		$lei  = Data_Model::sanitize_lei( $p['lei'] ?? '' );
		$slug = sanitize_title( '' !== $lei ? $lei : (string) ( $p['slug'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		if ( '' === $slug || '' === $name ) {
			return new \WP_Error( 'blnm_bad_request', 'name and lei (or slug) are required.', array( 'status' => 400 ) );
		}
		$status   = in_array( $p['status'] ?? 'publish', array( 'draft', 'publish', 'pending' ), true ) ? $p['status'] : 'publish';
		$existing = get_page_by_path( $slug, OBJECT, Data_Model::LENDER );
		$args     = array(
			'post_type'   => Data_Model::LENDER,
			'post_name'   => $slug,
			'post_title'  => $name,
			'post_status' => $status,
		);
		if ( isset( $p['content'] ) ) {
			$args['post_content'] = wp_kses_post( $p['content'] );
		}
		if ( $existing ) {
			$args['ID'] = $existing->ID;
			$id         = wp_update_post( wp_slash( $args ), true );
		} else {
			$id = wp_insert_post( wp_slash( $args ), true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, 'blnm_lei', $lei );
		update_post_meta( $id, 'blnm_lender_type', Data_Model::sanitize_lender_type( $p['type'] ?? '' ) );
		update_post_meta( $id, 'blnm_hq', sanitize_text_field( $p['hq'] ?? '' ) );
		update_post_meta( $id, 'blnm_nmls_url', esc_url_raw( $p['nmls_url'] ?? '', array( 'https' ) ) );
		update_post_meta( $id, 'blnm_place_id', sanitize_text_field( $p['place_id'] ?? '' ) );
		if ( array_key_exists( 'summary', $p ) ) {
			update_post_meta( $id, 'blnm_summary', Data_Model::sanitize_summary( $p['summary'] ) );
		}
		$review_keys = array( 'google_rating', 'google_review_count', 'google_maps_url', 'rating_as_of', 'review_summary', 'review_highlights', 'review_pros', 'review_cons', 'is_builder_lender', 'branch_name', 'branch_address' );
		if ( array_intersect( $review_keys, array_keys( $p ) ) ) {
			update_post_meta( $id, 'blnm_review_json', wp_slash( wp_json_encode( (object) Data_Model::sanitize_review( $p ) ) ) );
		}

		return rest_ensure_response(
			array(
				'id'     => $id,
				'action' => $existing ? 'updated' : 'created',
				'url'    => get_permalink( $id ),
			)
		);
	}
}
