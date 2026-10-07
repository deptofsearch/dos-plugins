<?php
/**
 * SEO glue for DoS Toolkit's SEO module (and core sitemaps).
 *
 *  1. Seeds the Toolkit's per-post title/description meta (_dos_seo_title, _dos_seo_description) on the front page,
 *     the state pages and the city/neighborhood pages: POST /osn/v1/seo/seed. Only empty values, or values this class
 *     wrote and nobody has touched since (_osn_seo_auto + a per-field md5 in _osn_seo_auto_hash), are ever written.
 *  2. Titles single venues "{Venue} – Outdoor Seating in {City, ST}" through pre_get_document_title (priority 5, ahead
 *     of the Toolkit's 10), only while the Toolkit SEO module is active and the venue has no _dos_seo_title of its own.
 *     The Toolkit's og:title, twitter and schema name read wp_get_document_title(), so they follow.
 *  3. Core sitemaps: no users sitemap, no noindexed thin venues (flag _osn_thin), no plugin taxonomies.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Seo {

	const TITLE_KEY = '_dos_seo_title';
	const DESC_KEY  = '_dos_seo_description';
	const AUTO_KEY  = '_osn_seo_auto';
	const HASH_KEY  = '_osn_seo_auto_hash';
	const THIN_KEY  = '_osn_thin';
	const MAX_DESC  = 155;

	/** Used to name cities in state descriptions before a state has venue data. */
	const MAJOR_CITIES = array(
		'WA' => array( 'Seattle', 'Spokane', 'Tacoma' ),
		'OR' => array( 'Portland', 'Eugene', 'Bend' ),
		'CA' => array( 'Los Angeles', 'San Diego', 'San Francisco' ),
		'ID' => array( 'Boise', 'Coeur d\'Alene', 'Idaho Falls' ),
		'AZ' => array( 'Phoenix', 'Tucson', 'Scottsdale' ),
		'NV' => array( 'Las Vegas', 'Reno', 'Henderson' ),
		'NM' => array( 'Albuquerque', 'Santa Fe', 'Las Cruces' ),
		'CO' => array( 'Denver', 'Boulder', 'Colorado Springs' ),
		'UT' => array( 'Salt Lake City', 'Provo', 'Park City' ),
		'TX' => array( 'Austin', 'Dallas', 'Houston' ),
		'IL' => array( 'Chicago', 'Naperville', 'Springfield' ),
		'FL' => array( 'Miami', 'Orlando', 'Tampa' ),
		'NY' => array( 'New York', 'Buffalo', 'Albany' ),
		'GA' => array( 'Atlanta', 'Savannah', 'Athens' ),
	); // DoS Toolkit truncates meta descriptions at 155.
	const MAX_ITEMS = 200;

	/** Toolkit setting (DOS_Settings key) the front page description is read from; it ignores _dos_seo_description there. */
	const HOME_SETTING = 'seo_home_description';
	const HOME_HASH    = 'osn_seo_home_hash';

	const HOME_TITLE = 'Outdoor Seating Near Me | Restaurants with Patios & Rooftops';

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );

		add_filter( 'pre_get_document_title', array( __CLASS__, 'venue_title' ), 5 );
		add_filter( 'wp_title', array( __CLASS__, 'venue_title' ), 5 );

		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sitemap_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'sitemap_taxonomies' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_venue_args' ), 10, 2 );
		add_action( 'save_post_' . Data_Model::POST_TYPE, array( __CLASS__, 'on_save_venue' ), 20 );
	}

	/** True when DoS Toolkit's SEO module is enabled, present, and not standing down for another SEO plugin. */
	public static function toolkit_active() {
		return class_exists( '\\DOS_Toolkit' ) && class_exists( '\\DOS_Module_SEO' )
			&& \DOS_Toolkit::is_active( 'seo' ) && '' === (string) \DOS_Module_SEO::conflicting_plugin();
	}

	/* ---------------------------------------------------------------- venue titles */

	public static function venue_title( $title ) {
		if ( ! self::toolkit_active() || ! is_singular( Data_Model::POST_TYPE ) ) {
			return $title;
		}
		$id = (int) get_queried_object_id();
		if ( ! $id || '' !== trim( (string) get_post_meta( $id, self::TITLE_KEY, true ) ) ) {
			return $title; // The Toolkit's own filter (priority 10) uses the hand-written one.
		}
		$term  = Util::first_term( $id, Data_Model::CITY );
		$label = $term ? (string) $term->name : trim( Util::meta( $id, 'city_name' ) . ', ' . Util::meta( $id, 'state' ), ' ,' );
		$name  = trim( html_entity_decode( wp_strip_all_tags( (string) get_post_field( 'post_title', $id ) ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $label || '' === $name ) {
			return $title;
		}
		/* translators: 1: venue name, 2: "City, ST" */
		$text = (string) apply_filters( 'osn_venue_seo_title', sprintf( '%1$s – Outdoor Seating in %2$s', $name, $label ), $id );
		// Returning from pre_get_document_title skips core's escaping, same as the Toolkit does.
		return esc_html( $text );
	}

	/* ---------------------------------------------------------------- sitemaps */

	public static function sitemap_provider( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	public static function sitemap_taxonomies( $taxonomies ) {
		foreach ( array( Data_Model::CITY, Data_Model::AMENITY, Data_Model::CATEGORY, Data_Model::HOOD, Data_Model::LANDMARK ) as $tax ) {
			unset( $taxonomies[ $tax ] );
		}
		return $taxonomies;
	}

	public static function sitemap_venue_args( $args, $post_type ) {
		if ( 'page' === $post_type ) { // Redirected neighborhood pages (meta _osn_redirect) stay out of the sitemap.
			$clause = array( 'key' => Hoods::REDIRECT_KEY, 'compare' => 'NOT EXISTS' );
			$have   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$args['meta_query'] = $have ? array( 'relation' => 'AND', $have, $clause ) : array( $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			return $args;
		}
		if ( Data_Model::POST_TYPE !== $post_type ) {
			return $args;
		}
		$clause = array( 'key' => self::THIN_KEY, 'compare' => 'NOT EXISTS' );
		$have   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$args['meta_query'] = $have ? array( 'relation' => 'AND', $have, $clause ) : array( $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}

	/** Keep _osn_thin in step with Venue_Page::is_thin(). '1' when thin, deleted otherwise. @return bool Changed. */
	public static function sync_thin( $post_id ) {
		$post_id = (int) $post_id;
		$has     = metadata_exists( 'post', $post_id, self::THIN_KEY );
		if ( Venue_Page::is_thin( $post_id ) ) {
			if ( $has && '1' === (string) get_post_meta( $post_id, self::THIN_KEY, true ) ) {
				return false;
			}
			update_post_meta( $post_id, self::THIN_KEY, '1' );
			return true;
		}
		if ( $has ) {
			delete_post_meta( $post_id, self::THIN_KEY );
			return true;
		}
		return false;
	}

	public static function on_save_venue( $post_id ) {
		if ( ! wp_is_post_revision( $post_id ) ) {
			self::sync_thin( $post_id );
		}
	}

	/* ---------------------------------------------------------------- seeding */

	public static function register_routes() {
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/seo/seed',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_seed' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'scope'   => array(
						'type'    => 'string',
						'enum'    => array( 'home', 'states', 'cities', 'all' ),
						'default' => 'all',
					),
					'dry_run' => array( 'type' => 'boolean', 'default' => false ),
					'offset'  => array( 'type' => 'integer', 'default' => 0 ),
					'limit'   => array( 'type' => 'integer', 'default' => 50 ),
				),
			)
		);
	}

	public static function rest_seed( \WP_REST_Request $req ) {
		$scope   = (string) $req['scope'];
		$dry     = (bool) $req['dry_run'];
		$offset  = max( 0, (int) $req['offset'] );
		$limit   = max( 1, min( self::MAX_ITEMS, (int) $req['limit'] ) );
		$targets = self::targets( $scope );
		$page    = array_slice( $targets, $offset, $limit );
		$counts  = array_fill_keys( array( 'set', 'kept', 'skipped' ), 0 );
		$results = array();
		foreach ( $page as $t ) {
			$row = self::seed_page( $t['id'], $t['kind'], $dry );
			++$counts[ $row['action'] ];
			$results[] = $row;
		}
		$next = $offset + count( $page );
		return rest_ensure_response(
			array(
				'dry_run'     => $dry,
				'scope'       => $scope,
				'total'       => count( $targets ),
				'counts'      => $counts,
				'next_offset' => $next < count( $targets ) ? $next : null,
				'results'     => $results,
			)
		);
	}

	/** @return array[] Each { id, kind }: front page, state pages, then city pages, in that order. */
	public static function targets( $scope ) {
		global $wpdb;
		$out  = array();
		$seen = array();
		if ( 'home' === $scope || 'all' === $scope ) {
			$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			if ( $front && 'publish' === get_post_status( $front ) ) {
				$out[]          = array( 'id' => $front, 'kind' => 'home' );
				$seen[ $front ] = true;
			}
		}
		if ( 'states' === $scope || 'all' === $scope ) {
			foreach ( array_keys( States::pages() ) as $slug ) {
				$p = get_page_by_path( $slug, OBJECT, 'page' );
				if ( $p && 'publish' === $p->post_status && State_Cities::code_for_post( $p ) && ! isset( $seen[ $p->ID ] ) ) {
					$out[]          = array( 'id' => (int) $p->ID, 'kind' => 'state' );
					$seen[ $p->ID ] = true;
				}
			}
		}
		if ( 'cities' === $scope || 'all' === $scope ) {
			$sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC",
				'%' . $wpdb->esc_like( '[table' ) . '%'
			);
			foreach ( (array) $wpdb->get_col( $sql ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$id = (int) $id;
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				if ( '' !== Page_Title::filter_from_content( get_post_field( 'post_content', $id ) ) ) {
					$out[] = array( 'id' => $id, 'kind' => 'city' );
				}
			}
		}
		return $out;
	}

	/** Compute (and, unless $dry, write) the title/description for one page. @return array Result row. */
	public static function seed_page( $id, $kind, $dry ) {
		$post = get_post( $id );
		$row  = array(
			'id'          => (int) $id,
			'slug'        => $post ? $post->post_name : '',
			'kind'        => $kind,
			'title'       => '',
			'description' => '',
			'action'      => 'skipped',
		);
		if ( ! $post ) {
			return $row;
		}
		$text = self::text_for( $post, $kind );
		$row['title']       = $text['title'];
		$row['description'] = $text['description'];

		$hash  = get_post_meta( $id, self::HASH_KEY, true );
		$hash  = is_array( $hash ) ? $hash : array();
		$wrote = false;
		$acts  = array(
			'title'       => self::seed_field( $id, self::TITLE_KEY, 'title', $text['title'], $dry, $hash, $wrote ),
			'description' => self::seed_field( $id, self::DESC_KEY, 'description', $text['description'], $dry, $hash, $wrote ),
		);
		if ( $wrote ) {
			update_post_meta( $id, self::AUTO_KEY, '1' );
			update_post_meta( $id, self::HASH_KEY, $hash );
		}
		if ( 'home' === $kind ) {
			// The Toolkit's front page description comes from its "Homepage description" setting, not the post meta.
			$acts['home_setting'] = self::seed_home_setting( $text['description'], $dry );
		}
		$row['fields'] = $acts;
		if ( in_array( 'set', $acts, true ) ) {
			$row['action'] = 'set';
		} elseif ( in_array( 'kept', $acts, true ) ) {
			$row['action'] = 'kept';
		}
		return $row;
	}

	/**
	 * Write one meta field when it is empty, or when it still holds exactly what we last wrote.
	 *
	 * @return string set|kept|skipped
	 */
	private static function seed_field( $id, $key, $field, $text, $dry, array &$hash, &$wrote ) {
		if ( '' === $text ) {
			return 'skipped';
		}
		$cur = (string) get_post_meta( $id, $key, true );
		if ( $cur === $text ) {
			return 'kept';
		}
		if ( '' !== trim( $cur ) ) {
			$ours = '1' === (string) get_post_meta( $id, self::AUTO_KEY, true ) && isset( $hash[ $field ] ) && hash_equals( (string) $hash[ $field ], md5( $cur ) );
			if ( ! $ours ) {
				return 'kept'; // Hand-edited (or written by something else): never overwrite.
			}
		}
		if ( ! $dry ) {
			update_post_meta( $id, $key, wp_slash( $text ) );
			$hash[ $field ] = md5( $text );
			$wrote          = true;
		}
		return 'set';
	}

	/** Same rules as seed_field(), for the Toolkit's Homepage description setting. @return string set|kept|skipped */
	private static function seed_home_setting( $text, $dry ) {
		if ( '' === $text || ! class_exists( 'DOS_Settings' ) ) {
			return 'skipped';
		}
		$cur = (string) \DOS_Settings::get( self::HOME_SETTING, '' );
		if ( $cur === $text ) {
			return 'kept';
		}
		if ( '' !== trim( $cur ) && ! hash_equals( (string) get_option( self::HOME_HASH, '' ), md5( $cur ) ) ) {
			return 'kept';
		}
		if ( ! $dry ) {
			\DOS_Settings::set( self::HOME_SETTING, $text );
			update_option( self::HOME_HASH, md5( $text ), false );
		}
		return 'set';
	}

	/** @return array { title, description } for a front, state or city page. Filter: osn_seo_text. */
	public static function text_for( $post, $kind ) {
		$out = array( 'title' => '', 'description' => '' );
		if ( 'home' === $kind ) {
			$out['title']       = self::HOME_TITLE;
			$out['description'] = self::trim_words( wp_strip_all_tags( Home::intro() ) );
		} elseif ( 'state' === $kind ) {
			$out = self::state_text( $post );
		} elseif ( 'city' === $kind && Landmarks::active_for_page( $post ) ) {
			$out = self::landmark_text( Landmarks::active_for_page( $post ) );
		} elseif ( 'city' === $kind ) {
			$out = self::city_text( Page_Title::filter_from_content( $post->post_content ) );
		}
		return (array) apply_filters( 'osn_seo_text', $out, $post, $kind );
	}

	private static function state_text( $post ) {
		$code  = State_Cities::code_for_post( $post );
		$state = $code ? States::name( $code ) : '';
		if ( '' === $state ) {
			return array( 'title' => '', 'description' => '' );
		}
		$links = State_Cities::parse_links( $post->post_content, $code );
		if ( ! $links ) { // A state page built around [osn_state_cities]: count the cities that have a landing page.
			foreach ( City_Index::get( $code ) as $c ) {
				$k = State_Cities::local_key( $c['u'] );
				if ( null !== $k ) {
					$links[] = array( 'name' => Util::city_label( $c['n'] ), 'key' => $k, 'url' => $c['u'] );
				}
			}
		}
		$data  = State_Cities::data( $code );
		$rank  = array();
		foreach ( $links as $i => $l ) {
			$rank[] = array( isset( $data[ $l['key'] ] ) ? (int) $data[ $l['key'] ]['count'] : 0, $i, $l['name'] );
		}
		usort(
			$rank,
			function ( $a, $b ) {
				return $b[0] <=> $a[0] ?: $a[1] <=> $b[1];
			}
		);
		$top = array();
		foreach ( $rank as $r ) {
			if ( $r[0] > 0 && count( $top ) < 3 ) {
				$top[] = $r[2];
			}
		}
		// Fewer than three cities have venues: pad with the state's best-known cities, then the parsed links.
		$major = (array) apply_filters( 'osn_state_major_cities', self::MAJOR_CITIES );
		$link_names = array_map( 'strtolower', wp_list_pluck( $links, 'name' ) );
		foreach ( $major[ $code ] ?? array() as $name ) {
			// Only name a fallback city the state page actually lists, so a description never promises a city we don't have.
			if ( count( $top ) < 3 && ! in_array( $name, $top, true ) && in_array( strtolower( $name ), $link_names, true ) ) {
				$top[] = $name;
			}
		}
		if ( ! isset( $major[ $code ] ) ) { // No hardcoded list for this state: use its busiest cities.
			foreach ( self::busiest_cities( $code ) as $name ) {
				if ( count( $top ) < 3 && ! in_array( $name, $top, true ) ) {
					$top[] = $name;
				}
			}
		}
		foreach ( $links as $l ) {
			if ( count( $top ) < 3 && ! in_array( $l['name'], $top, true ) ) {
				$top[] = $l['name'];
			}
		}
		$n    = count( $links );
		$lead = 'Find restaurants, bars, and cafés with patios and outdoor seating in ' . ( $n ? $n . ' ' . $state . ( 1 === $n ? ' city' : ' cities' ) : $state );
		if ( $top ) {
			$last  = array_pop( $top );
			$lead .= ', including ' . ( $top ? implode( ', ', $top ) . ( count( $top ) > 1 ? ',' : '' ) . ' and ' : '' ) . $last;
		}
		$lead .= '.';
		$full  = $lead . ' Search your city, then filter for happy hour, dog-friendly patios, and more.';
		$max   = self::max_desc();
		return array(
			'title'       => sprintf( 'Outdoor Seating in %s | Restaurants with Patios', $state ),
			'description' => mb_strlen( $full ) <= $max ? $full : self::trim_words( $lead ), // Drop the last sentence if needed.
		);
	}

	/** Up to 3 city names for a state, ordered by visible venue count (osn_city terms). */
	private static function busiest_cities( $code ) {
		$terms = get_terms(
			array(
				'taxonomy'   => Data_Model::CITY,
				'hide_empty' => true,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}
		$rows = array();
		foreach ( $terms as $t ) {
			$st = strtoupper( (string) get_term_meta( $t->term_id, 'osn_state', true ) );
			if ( '' === $st ) {
				$p  = Util::parse_city( $t->name );
				$st = $p ? $p[1] : '';
			}
			if ( $st === $code ) {
				$rows[] = array( (int) $t->count, $t );
			}
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);
		$out = array();
		foreach ( array_slice( $rows, 0, 8 ) as $r ) { // Raw counts include closed venues; confirm with the visible list.
			$n = count( Util::sorted_ids( $r[1]->term_id ) );
			if ( $n > 0 ) {
				$out[] = array( $n, Util::city_label( $r[1]->name ) );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);
		return array_slice( array_column( $out, 1 ), 0, 3 );
	}

	/** Title and description for a landmark landing page. Title stem is the H1 (osn_h1 or the default). */
	private static function landmark_text( $spot ) {
		$city = Landmarks::city_term( $spot );
		if ( ! $city ) {
			return array( 'title' => '', 'description' => '' );
		}
		$label = Util::city_label( $city->name );
		$h1    = Landmarks::custom_h1( $spot );
		$stem  = '' !== $h1 ? $h1 : sprintf( '%s: Outdoor Seating in %s', $spot->name, $label );
		$ids   = Util::sorted_ids( $city->term_id );
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, Data_Model::POST_TYPE );
		$data  = Landmarks::city_data( $city, $ids );
		$row   = $data['landmarks'][ $spot->term_id ] ?? null;
		$host  = $row && $row['casinos'] ? ', including spots inside casinos and resorts' : '';
		$desc  = sprintf( 'Restaurants and bars with patios and outdoor seating: %1$s, %2$s%3$s. See hours and ratings, and filter for happy hour, dogs, and brunch.', $spot->name, $label, $host );
		return array(
			'title'       => $stem . ' | Patios & Restaurants',
			'description' => $desc,
		);
	}

	private static function city_text( $filter ) {
		$p     = Util::parse_city( $filter );
		$label = $p ? $p[0] . ', ' . $p[1] : $filter; // "Roxbury, Seattle" has no state code and is used as written.
		$term  = Util::find_city( $label );
		if ( $term && Util::city_has_venues( $term ) ) {
			$desc = sprintf( 'Restaurants and bars with outdoor seating in %s. See patios, hours, and ratings, and filter for happy hour, dogs, and brunch.', $label );
		} else {
			$desc = sprintf( 'Restaurants, bars, and cafés with patios and outdoor seating in %s, with addresses, phone numbers, and websites.', $label );
		}
		return array(
			'title'       => sprintf( 'Outdoor Seating in %s | Patios & Restaurants', $label ),
			'description' => $desc,
		);
	}

	private static function max_desc() {
		return max( 50, (int) apply_filters( 'osn_seo_description_max', self::MAX_DESC ) );
	}

	/** Collapse whitespace and cut at a word boundary within the limit. No ellipsis. */
	public static function trim_words( $text, $max = 0 ) {
		$max  = $max ? $max : self::max_desc();
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );
		if ( ' ' !== mb_substr( $text, $max, 1 ) ) {
			$sp  = mb_strrpos( $cut, ' ' );
			$cut = false !== $sp ? mb_substr( $cut, 0, $sp ) : $cut;
		}
		// Don't end on a dangling connector ("... Arizona, and").
		$cut = rtrim( $cut, ' ,;:' );
		while ( preg_match( '/\s(?:and|or|in|of|the|to|with|for|across|a|an)$/iu', $cut, $m ) ) {
			$cut = rtrim( mb_substr( $cut, 0, -mb_strlen( $m[0] ) ), ' ,;:' );
		}
		return $cut;
	}

	/* ---------------------------------------------------------------- admin */

	public static function admin_assets( $hook ) {
		if ( 'tools_page_dos-outdoor-seating' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'dos-outdoor-seating-admin', plugins_url( 'assets/osn-admin.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_localize_script(
			'dos-outdoor-seating-admin',
			'osnSeo',
			array(
				'url'   => esc_url_raw( rest_url( Rest::NAMESPACE_V1 . '/seo/seed' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/** The Tools > Outdoor Seating "SEO" block (outside the settings form). */
	public static function admin_box() {
		$on = self::toolkit_active();
		?>
<h2><?php esc_html_e( 'SEO titles and descriptions', 'dos-outdoor-seating' ); ?></h2>
<div id="osn-seo-seed">
<p><?php echo $on ? esc_html__( 'DoS Toolkit SEO module: active.', 'dos-outdoor-seating' ) : esc_html__( 'DoS Toolkit SEO module: not active. The values are still saved and start working when it is.', 'dos-outdoor-seating' ); ?></p>
<p class="description" style="max-width:720px"><?php esc_html_e( 'Writes the Toolkit title and meta description for the homepage, the state pages and every city page. Only empty values, or values this button wrote earlier and nobody has edited since, are written. Hand-edited values are never overwritten.', 'dos-outdoor-seating' ); ?></p>
<p>
<select data-osn-seo-scope>
	<option value="all"><?php esc_html_e( 'All pages', 'dos-outdoor-seating' ); ?></option>
	<option value="home"><?php esc_html_e( 'Homepage only', 'dos-outdoor-seating' ); ?></option>
	<option value="states"><?php esc_html_e( 'State pages only', 'dos-outdoor-seating' ); ?></option>
	<option value="cities"><?php esc_html_e( 'City pages only', 'dos-outdoor-seating' ); ?></option>
</select>
<label><input type="checkbox" data-osn-seo-dry checked> <?php esc_html_e( 'Dry run (report only)', 'dos-outdoor-seating' ); ?></label>
<button type="button" class="button button-primary" data-osn-seo-run><?php esc_html_e( 'Seed SEO titles/descriptions', 'dos-outdoor-seating' ); ?></button>
</p>
<pre data-osn-seo-out style="max-width:900px;max-height:320px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px" hidden></pre>
</div>
		<?php
	}
}
