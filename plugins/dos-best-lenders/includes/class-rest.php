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
	const TRANSIENT    = 'blnm_city_index_v2';
	const STATES_TRANS = 'blnm_state_list_v1';
	const HUB_TRANS    = 'blnm_hub_';
	const HUB_STATES   = 'blnm_hub_states';

	/** USPS code => state name (50 states + DC). */
	const STATE_NAMES = array(
		'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
		'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia',
		'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
		'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
		'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
		'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
		'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
		'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon',
		'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
		'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
		'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
	);

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_flush' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'flush_on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'flush_on_deleted_meta' ), 10, 4 );
		add_action( 'before_delete_post', array( __CLASS__, 'flush_on_delete' ) );
		add_action( 'save_post_page', array( __CLASS__, 'flush_states' ) );
	}

	/**
	 * States that have at least one published city AND a published state page (slug = slugified state name).
	 * [ { c: "WA", name: "Washington", n: 91, u: url } ], A to Z by name. Cached in a transient flushed with the index.
	 */
	public static function states() {
		$list = get_transient( self::STATES_TRANS );
		if ( false === $list ) {
			$counts = array();
			foreach ( self::index() as $r ) {
				if ( '' !== $r['s'] ) {
					$counts[ $r['s'] ] = ( $counts[ $r['s'] ] ?? 0 ) + 1;
				}
			}
			$list = array();
			foreach ( $counts as $code => $n ) {
				if ( ! isset( self::STATE_NAMES[ $code ] ) ) {
					continue;
				}
				$name = self::STATE_NAMES[ $code ];
				$page = get_page_by_path( sanitize_title( $name ), OBJECT, 'page' );
				if ( ! $page || 'publish' !== $page->post_status ) {
					continue;
				}
				$list[] = array(
					'c'    => $code,
					'name' => $name,
					'n'    => $n,
					'u'    => get_permalink( $page ),
				);
			}
			usort(
				$list,
				static function ( $a, $b ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
			);
			set_transient( self::STATES_TRANS, $list, DAY_IN_SECONDS );
		}
		return $list;
	}

	/** Pages changing (new state page published, slug edited, trashed) can change the state list. */
	public static function flush_states() {
		delete_transient( self::STATES_TRANS );
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

	/** JSON false, "false", "0", "no" and 0 are false; everything else true. */
	private static function truthy( $v ) {
		return ! ( false === $v || 0 === $v || in_array( $v, array( 'false', '0', 'no', '' ), true ) );
	}

	public static function can_publish() {
		return current_user_can( 'publish_posts' );
	}

	/** Public list of published cities: [ { n: "Kennewick", s: "WA", u: url } ], A to Z. Cached in a transient. */
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
					'n' => $city,
					's' => $state,
					'u' => get_permalink( $id ),
				);
			}
			usort(
				$index,
				static function ( $a, $b ) {
					return strnatcasecmp( $a['n'] . ', ' . $a['s'], $b['n'] . ', ' . $b['s'] );
				}
			);
			set_transient( self::TRANSIENT, $index, DAY_IN_SECONDS );
		}
		return $index;
	}

	/**
	 * Rows for the state hub, A to Z by city, cached per state in transient blnm_hub_<ST>:
	 * { n: name, slug, u: url, county (no "County"), fips, lenders: count, top: up to 3 names by score, pop, lat, lng, yr }.
	 * One query for the IDs and one meta-cache priming, so 90 cities cost two queries, not 90.
	 */
	public static function hub_index( $state ) {
		$state = Data_Model::sanitize_state( $state );
		if ( '' === $state ) {
			return array();
		}
		$rows = get_transient( self::HUB_TRANS . $state );
		if ( false !== $rows ) {
			return $rows;
		}
		$ids = get_posts(
			array(
				'post_type'      => Data_Model::CITY,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'blnm_state',
				'meta_value'     => $state,
			)
		);
		update_meta_cache( 'post', $ids );
		$rows = array();
		foreach ( $ids as $id ) {
			$name = (string) get_post_meta( $id, 'blnm_city_name', true );
			if ( '' === $name ) {
				continue;
			}
			$lenders = Data_Model::city_lenders( $id );
			usort(
				$lenders,
				static function ( $a, $b ) {
					return ( $b['score'] ?? -1 ) <=> ( $a['score'] ?? -1 ) ?: ( $b['loans_2025'] ?? 0 ) <=> ( $a['loans_2025'] ?? 0 );
				}
			);
			$top = array();
			foreach ( array_slice( $lenders, 0, 3 ) as $l ) {
				$top[] = (string) $l['name'];
			}
			$lat    = get_post_meta( $id, 'blnm_lat', true );
			$lng    = get_post_meta( $id, 'blnm_lng', true );
			$rows[] = array(
				'n'       => $name,
				'slug'    => (string) get_post_field( 'post_name', $id ),
				'u'       => get_permalink( $id ),
				'county'  => (string) get_post_meta( $id, 'blnm_county_name', true ),
				'fips'    => (string) get_post_meta( $id, 'blnm_county_fips', true ),
				'lenders' => count( $lenders ),
				'top'     => $top,
				'pop'     => (int) get_post_meta( $id, 'blnm_population', true ),
				'lat'     => '' === $lat ? null : (float) $lat,
				'lng'     => '' === $lng ? null : (float) $lng,
				'yr'      => (int) get_post_meta( $id, 'blnm_data_year', true ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['n'], $b['n'] );
			}
		);
		set_transient( self::HUB_TRANS . $state, $rows, DAY_IN_SECONDS );
		$known = (array) get_option( self::HUB_STATES, array() );
		if ( ! in_array( $state, $known, true ) ) {
			$known[] = $state;
			update_option( self::HUB_STATES, $known, false );
		}
		return $rows;
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
		$res->header( 'Cache-Control', 'public, max-age=300, s-maxage=300' );
		return $res;
	}

	/** Drop the cached index and bump the version the shortcode appends to the API URL, so browsers/CDNs refetch. */
	public static function flush_index() {
		delete_transient( self::TRANSIENT );
		delete_transient( self::STATES_TRANS );
		self::flush_hubs();
		update_option( 'blnm_city_index_ver', time(), false );
	}

	/** Drop every cached state hub (transient blnm_hub_<ST>). */
	public static function flush_hubs() {
		foreach ( (array) get_option( self::HUB_STATES, array() ) as $st ) {
			delete_transient( self::HUB_TRANS . $st );
		}
	}

	public static function index_ver() {
		$v = (int) get_option( 'blnm_city_index_ver', 0 );
		if ( ! $v ) {
			$v = time();
			update_option( 'blnm_city_index_ver', $v, false );
		}
		return $v;
	}

	public static function maybe_flush( $new, $old, $post ) {
		if ( 'page' === $post->post_type && ( 'publish' === $new || 'publish' === $old ) ) {
			self::flush_states();
		}
		if ( Data_Model::CITY === $post->post_type && ( 'publish' === $new || 'publish' === $old ) ) {
			self::flush_index();
		}
	}

	public static function flush_on_deleted_meta( $meta_ids, $post_id, $meta_key, $value ) {
		self::flush_on_meta( 0, $post_id, $meta_key );
	}

	public static function flush_on_delete( $post_id ) {
		if ( 'page' === get_post_type( $post_id ) ) {
			self::flush_states();
		}
		if ( Data_Model::CITY === get_post_type( $post_id ) ) {
			self::flush_index();
		}
	}

	public static function flush_on_meta( $meta_id, $post_id, $meta_key ) {
		if ( in_array( $meta_key, array( 'blnm_city_name', 'blnm_state' ), true ) && Data_Model::CITY === get_post_type( $post_id ) ) {
			self::flush_index();
			return;
		}
		// Hub-only inputs (not in the /cities payload): refresh the per-state hub rows without bumping the city-search version.
		if ( in_array( $meta_key, array( 'blnm_county_name', 'blnm_county_fips', 'blnm_lenders_json', 'blnm_data_year', 'blnm_lat', 'blnm_lng', 'blnm_population' ), true ) && Data_Model::CITY === get_post_type( $post_id ) ) {
			self::flush_hubs();
		}
	}

	/**
	 * POST blnm/v1/cities/upsert
	 * Body: slug (required, "<city>-<st>"), city_name (required when creating), state, county_fips, county_name,
	 *       data_year, lenders (array or JSON string), title, content, excerpt, status (draft|publish|pending), create (false = 404 instead of creating when the slug is unknown),
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
		if ( ! $existing && array_key_exists( 'create', $p ) && ! self::truthy( $p['create'] ) ) {
			return new \WP_Error( 'blnm_not_found', 'No city with that slug, and create is false.', array( 'status' => 404 ) );
		}
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
			$status              = $p['status'] ?? 'publish';
			$args['post_status'] = in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ? $status : 'publish';
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
		// Hub map and sort inputs. Each is written only when sent, so {slug, lat, lng, population} alone touches nothing else.
		if ( $has( 'lat' ) && is_numeric( $p['lat'] ) ) {
			update_post_meta( $id, 'blnm_lat', Data_Model::sanitize_lat( $p['lat'] ) );
		}
		if ( $has( 'lng' ) && is_numeric( $p['lng'] ) ) {
			update_post_meta( $id, 'blnm_lng', Data_Model::sanitize_lng( $p['lng'] ) );
		}
		if ( $has( 'population' ) && is_numeric( $p['population'] ) ) {
			update_post_meta( $id, 'blnm_population', absint( $p['population'] ) );
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
		$status   = $p['status'] ?? 'publish';
		$status   = in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ? $status : 'publish';
		$existing = get_page_by_path( $slug, OBJECT, Data_Model::LENDER );
		if ( ! $existing && array_key_exists( 'create', $p ) && ! self::truthy( $p['create'] ) ) {
			return new \WP_Error( 'blnm_not_found', 'No lender with that slug, and create is false.', array( 'status' => 404 ) );
		}
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
