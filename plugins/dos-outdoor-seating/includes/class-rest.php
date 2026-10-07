<?php
/**
 * REST routes under osn/v1.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NAMESPACE_V1 = 'osn/v1';
	const MAX_BATCH    = 50;

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		Venue_Image::register();
		Takeover::register_routes();
		register_rest_route(
			self::NAMESPACE_V1,
			'/venues/upsert',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'upsert' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'venues'  => array(
						'description' => 'Array of venue objects (max ' . self::MAX_BATCH . '). Items are validated one by one.',
						'type'        => 'array',
						'required'    => true,
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'pass'    => array(
						'description' => 'Default pass name for venues that do not set their own. The hash is stored per pass (osn_hash_{pass}); a venue without a pass uses its source.',
						'type'        => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/venues/excerpts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'backfill_excerpts' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'offset' => array( 'type' => 'integer', 'default' => 0 ),
					'limit'  => array( 'type' => 'integer', 'default' => 200 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cities/register',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'register_cities' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'cities'  => array(
						'description' => 'Array of "City, ST" strings or { city, landing_slug?, landing_page_id? } objects. The landing page defaults to the page whose slug is the city slug (denver-co).',
						'type'        => 'array',
						'required'    => true,
					),
					'dry_run' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cities',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'cities' ),
				'permission_callback' => '__return_true', // Public: city names and landing page URLs only.
				'args'                => array(
					'state' => array(
						'description' => 'Two-letter state code to limit the list.',
						'type'        => 'string',
					),
					'scope' => array(
						'description' => '"all" lists every city linked from the state pages, not only cities with a landing page record.',
						'type'        => 'string',
					),
				),
			)
		);
	}

	public static function upsert( \WP_REST_Request $request ) {
		$venues = $request->get_param( 'venues' );
		if ( count( $venues ) > self::MAX_BATCH ) {
			return new \WP_Error(
				'osn_batch_too_large',
				sprintf( 'Send at most %d venues per request.', self::MAX_BATCH ),
				array( 'status' => 400 )
			);
		}
		$dry         = (bool) $request->get_param( 'dry_run' );
		$can_publish = current_user_can( 'publish_posts' );
		$default_pass = $request->get_param( 'pass' );

		$counts  = array_fill_keys( array( 'created', 'updated', 'skipped', 'error' ), 0 );
		$results = array();
		$terms   = array();
		$dirty   = false;
		foreach ( $venues as $raw ) {
			if ( is_array( $raw ) && ! isset( $raw['pass'] ) && is_scalar( $default_pass ) && '' !== $default_pass ) {
				$raw['pass'] = $default_pass;
			}
			$row = Repository::upsert( $raw, $dry, $can_publish );
			if ( ! empty( $row['_terms'] ) ) {
				$terms = array_merge( $terms, $row['_terms'] );
			}
			$dirty = $dirty || ! empty( $row['_dirty'] );
			unset( $row['_terms'], $row['_dirty'] );
			$results[] = $row;
			++$counts[ $row['action'] ];
		}
		if ( ! $dry && ( $counts['created'] || $counts['updated'] ) ) {
			Repository::purge_cities( $terms );
			if ( $dirty ) {
				City_Index::flush();
			}
		}
		return rest_ensure_response(
			array(
				'dry_run' => $dry,
				'counts'  => $counts,
				'results' => $results,
			)
		);
	}

	/**
	 * POST /osn/v1/cities/register: create each osn_city term (before it has venues) and link its landing page, so
	 * a new city shows on its state page, in the city search and on the homepage. Idempotent.
	 */
	public static function register_cities( \WP_REST_Request $request ) {
		$dry  = (bool) $request->get_param( 'dry_run' );
		$rows = array();
		$ids  = array();
		foreach ( (array) $request->get_param( 'cities' ) as $raw ) {
			$city  = is_array( $raw ) ? (string) ( $raw['city'] ?? '' ) : (string) $raw;
			$city  = trim( sanitize_text_field( $city ) );
			$parts = Util::parse_city( $city );
			if ( ! $parts || ! States::has( $parts[1] ) ) {
				$rows[] = array( 'city' => $city, 'action' => 'error', 'message' => 'Expected "City, ST" with a known state code.' );
				continue;
			}
			$slug = is_array( $raw ) && ! empty( $raw['landing_slug'] ) ? sanitize_title( (string) $raw['landing_slug'] ) : sanitize_title( $city );
			$pid  = is_array( $raw ) && ! empty( $raw['landing_page_id'] ) ? (int) $raw['landing_page_id'] : 0;
			$page = $pid ? get_post( $pid ) : get_page_by_path( $slug, OBJECT, 'page' );
			$term = Util::find_city( $city );
			$row  = array(
				'city'      => $city,
				'action'    => $term ? 'exists' : 'created',
				'page_id'   => $page ? (int) $page->ID : 0,
				'page_status' => $page ? $page->post_status : null,
				'message'   => $page ? '' : 'landing page not found yet (/' . $slug . '/); create it, then run this again',
			);
			if ( ! $dry ) {
				$warnings = array();
				$t        = Repository::ensure_city( $city, $parts[1], $pid, $pid ? '' : $slug, $warnings );
				if ( $t ) {
					$ids[] = $t->term_id;
				}
				if ( $warnings ) {
					$row['warnings'] = $warnings;
				}
			}
			$rows[] = $row;
		}
		if ( $ids ) {
			Repository::purge_cities( $ids );
			City_Index::flush();
			Home::flush();
		}
		return rest_ensure_response( array( 'dry_run' => $dry, 'cities' => $rows ) );
	}

	public static function cities( \WP_REST_Request $request ) {
		$state = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $request->get_param( 'state' ) ) );
		$list  = 'all' === $request->get_param( 'scope' ) ? Home::all_cities( $state ) : City_Index::get( $state );
		$out   = array();
		foreach ( $list as $c ) {
			$c['p'] = City_Index::relative( $c['u'] );
			$out[]  = $c;
		}
		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/** One-time/backfill: fill venue excerpts from summaries (Repository::sync_excerpt) and the _osn_thin sitemap flag (Seo::sync_thin). Page with offset/limit. */
	public static function backfill_excerpts( \WP_REST_Request $req ) {
		$limit = max( 1, min( 500, (int) $req['limit'] ) );
		$ids   = get_posts(
			array(
				'post_type'      => Data_Model::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => $limit,
				'offset'         => max( 0, (int) $req['offset'] ),
				'no_found_rows'  => true,
			)
		);
		$changed = 0;
		$flags   = 0;
		$thin    = 0;
		foreach ( $ids as $id ) {
			if ( Repository::sync_excerpt( $id ) ) {
				++$changed;
			}
			if ( Seo::sync_thin( $id ) ) {
				++$flags;
			}
			if ( '1' === (string) get_post_meta( $id, Seo::THIN_KEY, true ) ) {
				++$thin;
			}
		}
		return rest_ensure_response( array( 'scanned' => count( $ids ), 'changed' => $changed, 'flags_changed' => $flags, 'thin' => $thin, 'next_offset' => count( $ids ) < $limit ? null : (int) $req['offset'] + $limit ) );
	}
}
