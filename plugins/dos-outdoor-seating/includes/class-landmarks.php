<?php
/**
 * Landmark zones: osn_landmark taxonomy helpers, REST import/assign/pages/tree, and the markup for the
 * "Landmarks" chip row, the casino sub-row and the landmark landing view on city pages.
 *
 * A landmark zone is a named area of a city that is not a neighborhood (a Strip, a casino district, a boardwalk,
 * an entertainment street). Nothing here knows which cities use it: labels come from the term data.
 *
 * Data model:
 *  - osn_landmark is flat (non-hierarchical). A venue carries ONE landmark term.
 *  - Term slugs are prefixed with the city slug ("las-vegas-nv-on-strip"); URLs and data attributes use the short
 *    slug ("on-strip"), exactly like osn_hood. Term name is the chip label, term description is optional copy.
 *  - Term meta: osn_city (city term name), osn_order (chip order), osn_page_id (landing page).
 *  - The optional casino (or any "venue within a venue" host) is post meta on the venue: _osn_casino (display name)
 *    and _osn_casino_slug (URL/data slug). It is meta, not a taxonomy, because casino names are free-form,
 *    mostly one-per-venue groups, and never need a term screen, landing page or sort order of their own.
 *
 * Filtering is client side (data-spot / data-casino on every card, ?spot= / ?casino= read by osn.js), so the
 * server HTML is identical for every query string and page caches stay valid, same as ?hood=.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Landmarks {

	const TAX             = 'osn_landmark';
	const CASINO_KEY      = '_osn_casino';
	const CASINO_SLUG_KEY = '_osn_casino_slug';
	const MAX_ASSIGN      = 1000;

	/** @var array<int,\WP_Term|null> Per-request memo: page ID => linked term. */
	private static $memo_term = array();
	/** @var array<int,int> Per-request memo: term ID => landing page ID usable for links. */
	private static $memo_page = array();

	public static function flush_memo() {
		self::$memo_term = array();
		self::$memo_page = array();
	}

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/* ---------------------------------------------------------------- term helpers */

	/** Slug without the city prefix: what URLs and data attributes use. */
	public static function short( $term ) {
		static $memo = array();
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		if ( ! isset( $memo[ $term->term_id ] ) ) {
			$prefix                 = Hoods::city_prefix( (string) get_term_meta( $term->term_id, 'osn_city', true ) );
			$memo[ $term->term_id ] = ( '-' !== $prefix && 0 === strpos( $term->slug, $prefix ) ) ? substr( $term->slug, strlen( $prefix ) ) : $term->slug;
		}
		return $memo[ $term->term_id ];
	}

	/** Landmark terms of a city in chip order (osn_order, then name). @return \WP_Term[] */
	public static function terms_for_city( $city_name ) {
		if ( ! taxonomy_exists( self::TAX ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => 'osn_city', 'value' => (string) $city_name ),
				),
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}
		$order = array();
		foreach ( $terms as $t ) {
			$o                     = get_term_meta( $t->term_id, 'osn_order', true );
			$order[ $t->term_id ] = '' === $o ? 9999 : (int) $o;
		}
		usort(
			$terms,
			function ( $a, $b ) use ( $order ) {
				return ( $order[ $a->term_id ] <=> $order[ $b->term_id ] ) ?: strcasecmp( $a->name, $b->name );
			}
		);
		return $terms;
	}

	/** Resolve a full or short slug to a term. The city name (when known) disambiguates short slugs. */
	public static function resolve( $slug, $city_name = '' ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug || ! taxonomy_exists( self::TAX ) ) {
			return null;
		}
		$t = get_term_by( 'slug', $slug, self::TAX );
		if ( $t instanceof \WP_Term ) {
			return $t;
		}
		if ( '' !== (string) $city_name ) {
			$t = get_term_by( 'slug', Hoods::city_prefix( $city_name ) . $slug, self::TAX );
			return $t instanceof \WP_Term ? $t : null;
		}
		return null;
	}

	/** The osn_city term for a landmark term. */
	public static function city_term( $term ) {
		return $term instanceof \WP_Term ? Util::find_city( (string) get_term_meta( $term->term_id, 'osn_city', true ) ) : null;
	}

	/** Landing page ID of a landmark term when it exists and is published, else 0. */
	public static function page_id( $term ) {
		if ( ! $term instanceof \WP_Term ) {
			return 0;
		}
		if ( ! isset( self::$memo_page[ $term->term_id ] ) ) {
			$pid = (int) get_term_meta( $term->term_id, 'osn_page_id', true );
			if ( $pid && 'publish' !== get_post_status( $pid ) ) {
				$pid = 0;
			}
			self::$memo_page[ $term->term_id ] = $pid;
		}
		return self::$memo_page[ $term->term_id ];
	}

	public static function page_url( $term ) {
		$pid = self::page_id( $term );
		return $pid ? (string) get_permalink( $pid ) : '';
	}

	/** The landmark term a page is linked to (osn_page_id), or null. */
	public static function term_for_page( $page_id ) {
		$page_id = (int) $page_id;
		if ( ! $page_id || ! taxonomy_exists( self::TAX ) ) {
			return null;
		}
		if ( ! array_key_exists( $page_id, self::$memo_term ) ) {
			$terms                        = get_terms(
				array(
					'taxonomy'   => self::TAX,
					'hide_empty' => false,
					'number'     => 1,
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => 'osn_page_id', 'value' => $page_id, 'type' => 'NUMERIC' ),
					),
				)
			);
			self::$memo_term[ $page_id ] = ( ! is_wp_error( $terms ) && $terms ) ? $terms[0] : null;
		}
		return self::$memo_term[ $page_id ];
	}

	/** Canonical city term name for a "City, ST" string (case/spacing variants resolve), or the input when unknown. */
	private static function canon_city( $city ) {
		$t = Util::find_city( $city );
		return $t ? $t->name : $city;
	}

	/**
	 * The landmark term when this page is linked to one, its city is enabled and the landmark has a visible venue.
	 * Otherwise null: the page then renders as the plain city grid. Shared by Takeover, Page_Title and Seo.
	 */
	public static function active_for_page( $post ) {
		static $memo = array();
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return null;
		}
		if ( array_key_exists( $post->ID, $memo ) ) {
			return $memo[ $post->ID ];
		}
		$memo[ $post->ID ] = null;
		$spot              = self::term_for_page( $post->ID );
		$city              = $spot ? self::city_term( $spot ) : null;
		if ( $spot && $city && Hoods::city_enabled( $city ) ) {
			$ids = Util::sorted_ids( $city->term_id );
			update_object_term_cache( $ids, Data_Model::POST_TYPE );
			foreach ( $ids as $id ) {
				$t = self::venue_term( $id );
				if ( $t && $t->term_id === $spot->term_id ) {
					$memo[ $post->ID ] = $spot;
					break;
				}
			}
		}
		return $memo[ $post->ID ];
	}

	/** Custom H1 (term meta osn_h1) or '' . */
	public static function custom_h1( $term ) {
		return trim( (string) get_term_meta( $term->term_id, 'osn_h1', true ) );
	}

	/** Page H1: osn_h1 when set, else "Landmark: Outdoor Seating in City" (never "in On the Strip"). */
	public static function h1( $term, $city_term ) {
		$h1 = self::custom_h1( $term );
		if ( '' !== $h1 ) {
			return $h1;
		}
		/* translators: 1: landmark name, 2: city */
		return sprintf( __( '%1$s: Outdoor Seating in %2$s', 'dos-outdoor-seating' ), $term->name, Util::city_label( $city_term->name ) );
	}

	/** A venue's landmark term (term cache should be primed), or null. */
	public static function venue_term( $id ) {
		if ( ! taxonomy_exists( self::TAX ) ) {
			return null;
		}
		return Util::first_term( $id, self::TAX );
	}

	/** { name, slug } of a venue's casino ('' when none). Meta cache should be primed. */
	public static function venue_casino( $id ) {
		$name = trim( (string) get_post_meta( $id, self::CASINO_KEY, true ) );
		if ( '' === $name ) {
			return array( 'name' => '', 'slug' => '' );
		}
		$slug = sanitize_title( (string) get_post_meta( $id, self::CASINO_SLUG_KEY, true ) );
		return array( 'name' => $name, 'slug' => '' !== $slug ? $slug : sanitize_title( $name ) );
	}

	/**
	 * Landmarks of a city with visible-venue counts and the casinos inside each.
	 *
	 * @param \WP_Term $city_term City term.
	 * @param int[]    $ids       Visible venue IDs (term and meta caches primed).
	 * @return array { landmarks: [ id => { term, count, casinos: [ slug => { name, slug, count } ] } ], by_venue: [ id => term_id ] }
	 */
	public static function city_data( $city_term, array $ids ) {
		$out   = array( 'landmarks' => array(), 'by_venue' => array() );
		$terms = self::terms_for_city( $city_term->name );
		if ( ! $terms ) {
			return $out;
		}
		$byid = array();
		foreach ( $terms as $t ) {
			$byid[ $t->term_id ] = array( 'term' => $t, 'count' => 0, 'casinos' => array() );
		}
		$pages = array();
		foreach ( $terms as $t ) {
			$p = (int) get_term_meta( $t->term_id, 'osn_page_id', true );
			if ( $p ) {
				$pages[] = $p;
			}
		}
		if ( $pages ) {
			_prime_post_caches( $pages, false, true );
		}
		foreach ( $ids as $id ) {
			$t = self::venue_term( $id );
			if ( ! $t || ! isset( $byid[ $t->term_id ] ) ) {
				continue;
			}
			$out['by_venue'][ $id ] = $t->term_id;
			++$byid[ $t->term_id ]['count'];
			$c = self::venue_casino( $id );
			if ( '' !== $c['name'] ) {
				if ( ! isset( $byid[ $t->term_id ]['casinos'][ $c['slug'] ] ) ) {
					$byid[ $t->term_id ]['casinos'][ $c['slug'] ] = array( 'name' => $c['name'], 'slug' => $c['slug'], 'count' => 0 );
				}
				++$byid[ $t->term_id ]['casinos'][ $c['slug'] ]['count'];
			}
		}
		foreach ( $byid as $tid => $row ) {
			if ( $row['count'] < 1 ) {
				continue;
			}
			uasort(
				$row['casinos'],
				function ( $a, $b ) {
					return ( $b['count'] <=> $a['count'] ) ?: strcasecmp( $a['name'], $b['name'] );
				}
			);
			$out['landmarks'][ $tid ] = $row;
		}
		return $out;
	}

	/* ---------------------------------------------------------------- markup */

	/**
	 * Landmark chip row + casino chip row. Rows are revealed by osn.js (the grid's filter bar is hidden without JS).
	 *
	 * @param array         $data  city_data().
	 * @param int           $total Visible venues in the grid.
	 * @param \WP_Term|null $fixed Landing view: grid is already limited to this landmark, so only its casino row shows.
	 */
	public static function chips_html( array $data, $total, $fixed = null ) {
		if ( ! $data['landmarks'] ) {
			return '';
		}
		$heading = (string) apply_filters( 'osn_landmark_heading', __( 'Landmarks', 'dos-outdoor-seating' ) );
		$sub     = (string) apply_filters( 'osn_casino_heading', __( 'Casinos', 'dos-outdoor-seating' ) );
		$has_sub = false;
		foreach ( $data['landmarks'] as $row ) {
			if ( $row['casinos'] && ( ! $fixed || $fixed->term_id === $row['term']->term_id ) ) {
				$has_sub = true;
			}
		}
		if ( $fixed && ! $has_sub ) {
			return '';
		}
		ob_start();
		?>
<div class="osn-filter-section osn-filter-section--spot">
<?php if ( ! $fixed ) : ?>
<p class="osn-filter-label"><?php echo esc_html( $heading ); ?></p>
<div class="osn-filters__chips osn-filters__chips--spots" role="group" aria-label="<?php echo esc_attr( $heading ); ?>" data-osn-spots>
<button type="button" class="osn-chip osn-chip--filter osn-chip--spot" aria-pressed="true" data-osn-spot=""><?php esc_html_e( 'All', 'dos-outdoor-seating' ); ?> <span class="osn-chip__count"><?php echo (int) $total; ?></span></button>
<?php foreach ( $data['landmarks'] as $row ) : ?>
<button type="button" class="osn-chip osn-chip--filter osn-chip--spot" aria-pressed="false" data-osn-spot="<?php echo esc_attr( self::short( $row['term'] ) ); ?>"><?php echo esc_html( $row['term']->name ); ?> <span class="osn-chip__count"><?php echo (int) $row['count']; ?></span></button>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ( $has_sub ) : ?>
<div class="<?php echo $fixed ? 'osn-spot-sub osn-spot-sub--fixed' : 'osn-area-sub osn-spot-sub'; ?>"<?php echo $fixed ? '' : ' hidden'; ?>>
<?php if ( $fixed ) : ?>
<p class="osn-filter-label"><?php echo esc_html( $sub ); ?></p>
<?php endif; ?>
<div class="osn-filters__chips osn-filters__chips--casinos" role="group" aria-label="<?php echo esc_attr( $sub ); ?>" data-osn-casinos>
<?php foreach ( $data['landmarks'] as $row ) : ?>
<?php
if ( $fixed && $fixed->term_id !== $row['term']->term_id ) {
	continue;
}
foreach ( $row['casinos'] as $c ) :
	?>
<button type="button" class="osn-chip osn-chip--filter osn-chip--casino" aria-pressed="false" data-osn-casino="<?php echo esc_attr( $c['slug'] ); ?>" data-spot="<?php echo esc_attr( self::short( $row['term'] ) ); ?>"<?php echo $fixed ? '' : ' hidden'; ?>><?php echo esc_html( $c['name'] ); ?> <span class="osn-chip__count"><?php echo (int) $c['count']; ?></span></button>
<?php endforeach; ?>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
</div>
		<?php
		return ob_get_clean();
	}

	/** Link for a landmark: its landing page, else the city page filtered via ?spot=. @return array { url, is_filter } */
	private static function link_for( $term, $city_url, $anchor ) {
		$url = self::page_url( $term );
		if ( '' !== $url ) {
			return array( $url, false );
		}
		return array( $city_url . '?spot=' . rawurlencode( self::short( $term ) ) . '#' . $anchor, true );
	}

	/** Crawlable "Browse by landmark" list (visible without JS). */
	public static function browse_html( array $data, $city_url, $anchor ) {
		if ( ! $data['landmarks'] ) {
			return '';
		}
		$heading = (string) apply_filters( 'osn_landmark_browse_heading', __( 'Browse by landmark', 'dos-outdoor-seating' ) );
		ob_start();
		?>
<section class="osn-hoods osn-hoods--spots" id="landmarks" aria-labelledby="landmarks-h-<?php echo esc_attr( $anchor ); ?>">
<h2 id="landmarks-h-<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $heading ); ?></h2>
<ul class="osn-hoods__list">
<?php
foreach ( $data['landmarks'] as $row ) :
	list( $url, $filter ) = self::link_for( $row['term'], $city_url, $anchor );
	?>
<li><a href="<?php echo esc_url( $url ); ?>"<?php echo $filter ? ' rel="nofollow"' : ''; ?>><?php echo esc_html( $row['term']->name ); ?></a> <span class="osn-chip__count"><?php echo (int) $row['count']; ?></span></li>
<?php endforeach; ?>
</ul>
</section>
		<?php
		return ob_get_clean();
	}

	/** Landing view extras: other landmarks of the city and the link back to the city page. */
	public static function nearby_html( array $data, $term, $city_term, $city_label ) {
		$city_url = Util::city_url( $city_term );
		$anchor   = Hoods::results_anchor( 1 );
		ob_start();
		$others = array();
		foreach ( $data['landmarks'] as $row ) {
			if ( $row['term']->term_id !== $term->term_id ) {
				list( $url, $filter ) = self::link_for( $row['term'], $city_url, $anchor );
				if ( $filter && '' === $city_url ) {
					continue;
				}
				$others[] = array( $row, $url, $filter );
			}
		}
		if ( $others ) :
			$heading = (string) apply_filters( 'osn_landmark_more_heading', __( 'More landmarks', 'dos-outdoor-seating' ) );
			?>
<section class="osn-hoods osn-hoods--nearby osn-hoods--spots" aria-labelledby="landmarks-more-h-<?php echo (int) $term->term_id; ?>">
<h2 id="landmarks-more-h-<?php echo (int) $term->term_id; ?>"><?php echo esc_html( $heading ); ?></h2>
<ul class="osn-hoods__list">
<?php foreach ( $others as $o ) : ?>
<li><a href="<?php echo esc_url( $o[1] ); ?>"<?php echo $o[2] ? ' rel="nofollow"' : ''; ?>><?php echo esc_html( $o[0]['term']->name ); ?></a> <span class="osn-chip__count"><?php echo (int) $o[0]['count']; ?></span></li>
<?php endforeach; ?>
</ul>
</section>
<?php endif; ?>
<?php if ( '' !== $city_url ) : ?>
<p class="osn-hoods__back"><a href="<?php echo esc_url( $city_url ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: %s: city */ __( 'All outdoor seating in %s', 'dos-outdoor-seating' ), $city_label ) ); ?></a></p>
<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------- REST */

	public static function rest_can() {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes() {
		$ns  = Rest::NAMESPACE_V1;
		$can = array( __CLASS__, 'rest_can' );
		register_rest_route(
			$ns,
			'/landmarks',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_tree' ),
				'permission_callback' => $can,
				'args'                => array( 'city' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
		register_rest_route(
			$ns,
			'/landmarks/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_import' ),
				'permission_callback' => $can,
				'args'                => array(
					'city'      => array( 'type' => 'string', 'required' => true ),
					'landmarks' => array( 'type' => 'array', 'required' => true ),
					'dry_run'   => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/landmarks/assign',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_assign' ),
				'permission_callback' => $can,
				'args'                => array(
					'city'        => array( 'type' => 'string', 'required' => true ),
					'assignments' => array( 'type' => 'array', 'required' => true ),
					'dry_run'     => array( 'type' => 'boolean', 'default' => false ),
					'replace'     => array( 'description' => 'Also clear landmark + casino on every venue of the city that is not in assignments.', 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/landmarks/pages',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_pages' ),
				'permission_callback' => $can,
				'args'                => array(
					'pages'   => array( 'type' => 'array', 'required' => true ),
					'dry_run' => array( 'type' => 'boolean', 'default' => false ),
					'city'    => array( 'description' => 'Optional "City, ST" to resolve short landmark slugs.', 'type' => 'string' ),
				),
			)
		);
	}

	private static function city_param( \WP_REST_Request $req ) {
		$city = trim( sanitize_text_field( (string) $req->get_param( 'city' ) ) );
		return Util::parse_city( $city ) ? $city : null;
	}

	private static function bad( $code, $msg ) {
		return new \WP_Error( 'osn_' . $code, $msg, array( 'status' => 400 ) );
	}

	/** Purge the cities' sorted lists + landing page caches, and tell page caches the landmarks changed. */
	private static function purge( array $city_names ) {
		self::flush_memo();
		$ids = array();
		foreach ( array_unique( $city_names ) as $name ) {
			$t = Util::find_city( $name );
			if ( $t ) {
				$ids[] = $t->term_id;
				foreach ( self::terms_for_city( $name ) as $lt ) {
					$pid = (int) get_term_meta( $lt->term_id, 'osn_page_id', true );
					if ( $pid ) {
						clean_post_cache( $pid );
					}
				}
				do_action( 'osn_landmarks_changed', $t->term_id );
			}
		}
		Repository::purge_cities( $ids );
	}

	/** All venue IDs of a city, any status, in ID order. @return int[] */
	private static function city_venue_ids( $city_term ) {
		return get_posts(
			array(
				'post_type'      => Data_Model::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 5000,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array( 'taxonomy' => Data_Model::CITY, 'field' => 'term_id', 'terms' => $city_term->term_id ),
				),
			)
		);
	}

	/** Upsert one landmark term. @return array { id, state: created|updated|unchanged } or WP_Error */
	private static function upsert_term( $name, $slug, $description, $order, $city, $page_id, $h1, $dry ) {
		$existing = get_term_by( 'slug', $slug, self::TAX );
		if ( ! $existing ) {
			if ( $dry ) {
				return array( 'id' => 0, 'state' => 'created' );
			}
			$r = wp_insert_term( $name, self::TAX, array( 'slug' => $slug, 'description' => (string) $description ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$id = (int) $r['term_id'];
			update_term_meta( $id, 'osn_city', $city );
			update_term_meta( $id, 'osn_order', (int) $order );
			update_term_meta( $id, 'osn_page_id', (int) $page_id );
			if ( null !== $h1 && '' !== $h1 ) {
				update_term_meta( $id, 'osn_h1', $h1 );
			}
			return array( 'id' => $id, 'state' => 'created' );
		}
		$id      = $existing->term_id;
		$changed = false;
		$args    = array();
		if ( $existing->name !== $name ) {
			$args['name'] = $name;
		}
		if ( null !== $description && $existing->description !== $description ) {
			$args['description'] = $description;
		}
		if ( $args ) {
			$changed = true;
			if ( ! $dry ) {
				$r = wp_update_term( $id, self::TAX, $args );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}
		if ( (string) get_term_meta( $id, 'osn_city', true ) !== $city ) {
			$changed = true;
			$dry || update_term_meta( $id, 'osn_city', $city );
		}
		if ( (string) get_term_meta( $id, 'osn_order', true ) !== (string) (int) $order ) {
			$changed = true;
			$dry || update_term_meta( $id, 'osn_order', (int) $order );
		}
		if ( null !== $page_id && (int) get_term_meta( $id, 'osn_page_id', true ) !== (int) $page_id ) {
			$changed = true;
			$dry || update_term_meta( $id, 'osn_page_id', (int) $page_id );
		}
		if ( null !== $h1 && self::custom_h1( $existing ) !== $h1 ) {
			$changed = true;
			if ( ! $dry ) {
				'' === $h1 ? delete_term_meta( $id, 'osn_h1' ) : update_term_meta( $id, 'osn_h1', $h1 );
			}
		}
		return array( 'id' => $id, 'state' => $changed ? 'updated' : 'unchanged' );
	}

	/** POST /osn/v1/landmarks/import */
	public static function rest_import( \WP_REST_Request $req ) {
		$city = self::city_param( $req );
		if ( ! $city ) {
			return self::bad( 'bad_city', 'city must look like "Las Vegas, NV".' );
		}
		$city   = self::canon_city( $city );
		$dry    = (bool) $req->get_param( 'dry_run' );
		$rows   = (array) $req->get_param( 'landmarks' );
		$prefix = Hoods::city_prefix( $city );
		$counts = array( 'created' => 0, 'updated' => 0, 'unchanged' => 0 );
		$errors = array();
		$seen   = array();
		$n      = 0;
		foreach ( $rows as $row ) {
			++$n;
			if ( ! is_array( $row ) ) {
				$errors[] = 'Landmark #' . $n . ' is not an object.';
				continue;
			}
			$name = trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );
			if ( '' === $name ) {
				$errors[] = 'Landmark #' . $n . ' has no name.';
				continue;
			}
			$slug = sanitize_title( (string) ( $row['slug'] ?? $name ) );
			$slug = 0 === strpos( $slug, $prefix ) ? $slug : $prefix . $slug;
			if ( isset( $seen[ $slug ] ) ) {
				$errors[] = $name . ': duplicate slug ' . $slug;
				continue;
			}
			$order = isset( $row['order'] ) && is_numeric( $row['order'] ) ? (int) $row['order'] : $n;
			$desc  = array_key_exists( 'description', $row ) ? wp_kses_post( (string) $row['description'] ) : null;
			$h1    = array_key_exists( 'h1', $row ) ? trim( sanitize_text_field( (string) $row['h1'] ) ) : null;
			$page  = null;
			if ( isset( $row['page_id'] ) && absint( $row['page_id'] ) ) {
				$page = absint( $row['page_id'] );
				$why  = self::page_link_error( $page, get_term_by( 'slug', $slug, self::TAX ) );
				if ( $why ) {
					$errors[] = $name . ': page_id ' . $page . ' ' . $why;
					continue;
				}
			}
			$r     = self::upsert_term( $name, $slug, $desc, $order, $city, $page, $h1, $dry );
			if ( is_wp_error( $r ) ) {
				$errors[] = $name . ': ' . $r->get_error_message();
				continue;
			}
			++$counts[ $r['state'] ];
			$seen[ $slug ] = true;
		}
		$unmanaged = 0;
		foreach ( self::terms_for_city( $city ) as $t ) {
			if ( ! isset( $seen[ $t->slug ] ) ) {
				++$unmanaged;
			}
		}
		if ( ! $dry ) {
			self::purge( array( $city ) );
		}
		return rest_ensure_response(
			array(
				'dry_run'         => $dry,
				'city'            => $city,
				'landmarks'       => $counts,
				'unmanaged_terms' => $unmanaged,
				'errors'          => $errors,
			)
		);
	}

	/** Casino fields of an assignment row, normalized. @return array|\WP_Error { set: bool, clear: bool, name, slug } */
	private static function casino_from_row( array $row, $current ) {
		$has_name = array_key_exists( 'casino_name', $row );
		$has_slug = array_key_exists( 'casino_slug', $row );
		if ( ! $has_name && ! $has_slug ) {
			return array( 'touch' => false );
		}
		if ( ! $has_name && '' === $current['name'] && '' !== sanitize_title( (string) ( $row['casino_slug'] ?? '' ) ) ) {
			return new \WP_Error( 'osn_bad_casino', 'casino_slug without casino_name on a venue that has none' );
		}
		$name = $has_name ? trim( sanitize_text_field( (string) ( $row['casino_name'] ?? '' ) ) ) : $current['name'];
		if ( '' === $name ) { // null or empty name clears the casino (and its slug).
			return array( 'touch' => true, 'name' => '', 'slug' => '' );
		}
		$slug = $has_slug ? sanitize_title( (string) ( $row['casino_slug'] ?? '' ) ) : '';
		if ( '' === $slug ) {
			$slug = $has_name || '' === $current['slug'] ? sanitize_title( $name ) : $current['slug'];
		}
		if ( '' === $slug ) {
			return new \WP_Error( 'osn_bad_casino', 'casino_name has no usable slug' );
		}
		return array( 'touch' => true, 'name' => $name, 'slug' => $slug );
	}

	/** POST /osn/v1/landmarks/assign */
	public static function rest_assign( \WP_REST_Request $req ) {
		$city = self::city_param( $req );
		$cterm = $city ? Util::find_city( $city ) : null;
		if ( ! $city ) {
			return self::bad( 'bad_city', 'city must look like "Las Vegas, NV".' );
		}
		if ( ! $cterm ) {
			return self::bad( 'bad_city', 'Unknown city.' );
		}
		$city = $cterm->name;
		$rows = (array) $req->get_param( 'assignments' );
		if ( count( $rows ) > self::MAX_ASSIGN ) {
			return self::bad( 'batch_too_large', sprintf( 'Send at most %d assignments per request.', self::MAX_ASSIGN ) );
		}
		$dry     = (bool) $req->get_param( 'dry_run' );
		$replace = (bool) $req->get_param( 'replace' );
		$counts  = array( 'assigned' => 0, 'cleared' => 0, 'unchanged' => 0, 'error' => 0, 'casinos_set' => 0, 'casinos_cleared' => 0 );
		$errors  = array();
		$listed  = array();
		$changed = false;

		$pre = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['venue_id'] ) && (int) $row['venue_id'] > 0 ) {
				$pre[] = (int) $row['venue_id'];
			}
		}
		if ( $pre ) {
			update_meta_cache( 'post', $pre );
			update_object_term_cache( $pre, Data_Model::POST_TYPE );
		}

		foreach ( $rows as $row ) {
			$id   = is_array( $row ) && isset( $row['venue_id'] ) ? (int) $row['venue_id'] : 0;
			$post = $id ? get_post( $id ) : null;
			if ( ! is_array( $row ) || ! $post || Data_Model::POST_TYPE !== $post->post_type ) {
				++$counts['error'];
				$errors[] = array( 'venue_id' => $id, 'error' => 'not a venue' );
				continue;
			}
			if ( ! has_term( $cterm->term_id, Data_Model::CITY, $id ) ) {
				++$counts['error'];
				$errors[] = array( 'venue_id' => $id, 'error' => 'venue is not in ' . $city );
				continue;
			}
			$listed[ $id ] = true;
			$cur_term      = self::venue_term( $id );
			$cur_casino    = self::venue_casino( $id );
			$did_set       = false;
			$did_clear     = false;

			// Landmark: key absent leaves it alone, null or '' clears it, a slug sets it.
			$new_term = false; // false = untouched.
			if ( array_key_exists( 'landmark_slug', $row ) ) {
				$slug = $row['landmark_slug'];
				if ( null === $slug || '' === $slug ) {
					$new_term = null;
				} else {
					$t = self::resolve( $slug, $city );
					if ( ! $t ) {
						++$counts['error'];
						$errors[] = array( 'venue_id' => $id, 'error' => 'unknown landmark_slug ' . sanitize_title( (string) $slug ) );
						continue;
					}
					if ( (string) get_term_meta( $t->term_id, 'osn_city', true ) !== $cterm->name ) {
						++$counts['error'];
						$errors[] = array( 'venue_id' => $id, 'error' => 'landmark belongs to another city' );
						continue;
					}
					$new_term = $t;
				}
			}
			$casino = self::casino_from_row( $row, $cur_casino );
			if ( is_wp_error( $casino ) ) {
				++$counts['error'];
				$errors[] = array( 'venue_id' => $id, 'error' => $casino->get_error_message() );
				continue;
			}

			if ( false !== $new_term ) {
				if ( null === $new_term && $cur_term ) {
					$dry || wp_set_object_terms( $id, array(), self::TAX );
					$did_clear = true;
				} elseif ( $new_term && ( ! $cur_term || $cur_term->term_id !== $new_term->term_id ) ) {
					$dry || wp_set_object_terms( $id, array( $new_term->term_id ), self::TAX );
					$did_set = true;
				}
			}
			if ( $casino['touch'] ) {
				if ( '' === $casino['name'] ) {
					if ( '' !== $cur_casino['name'] || '' !== (string) get_post_meta( $id, self::CASINO_SLUG_KEY, true ) ) {
						if ( ! $dry ) {
							delete_post_meta( $id, self::CASINO_KEY );
							delete_post_meta( $id, self::CASINO_SLUG_KEY );
						}
						++$counts['casinos_cleared'];
						$did_clear = true;
					}
				} elseif ( $casino['name'] !== $cur_casino['name'] || $casino['slug'] !== $cur_casino['slug'] ) {
					if ( ! $dry ) {
						update_post_meta( $id, self::CASINO_KEY, $casino['name'] );
						update_post_meta( $id, self::CASINO_SLUG_KEY, $casino['slug'] );
					}
					++$counts['casinos_set'];
					$did_set = true;
				}
			}
			if ( $did_set ) {
				++$counts['assigned'];
			} elseif ( $did_clear ) {
				++$counts['cleared'];
			} else {
				++$counts['unchanged'];
			}
			$changed = $changed || $did_set || $did_clear;
		}

		// replace: venues of the city that the payload did not mention lose their landmark and casino.
		if ( $replace ) {
			$all = self::city_venue_ids( $cterm );
			if ( $all ) {
				update_meta_cache( 'post', $all );
				update_object_term_cache( $all, Data_Model::POST_TYPE );
			}
			foreach ( $all as $id ) {
				if ( isset( $listed[ $id ] ) ) {
					continue;
				}
				$had_term   = (bool) self::venue_term( $id );
				$had_casino = '' !== trim( (string) get_post_meta( $id, self::CASINO_KEY, true ) ) || '' !== (string) get_post_meta( $id, self::CASINO_SLUG_KEY, true );
				if ( ! $had_term && ! $had_casino ) {
					continue;
				}
				if ( ! $dry ) {
					if ( $had_term ) {
						wp_set_object_terms( $id, array(), self::TAX );
					}
					delete_post_meta( $id, self::CASINO_KEY );
					delete_post_meta( $id, self::CASINO_SLUG_KEY );
				}
				if ( $had_casino ) {
					++$counts['casinos_cleared'];
				}
				++$counts['cleared'];
				$changed = true;
			}
		}
		if ( ! $dry && $changed ) {
			self::purge( array( $cterm->name ) );
		}
		return rest_ensure_response(
			array(
				'dry_run' => $dry,
				'city'    => $city,
				'replace' => $replace,
				'counts'  => $counts,
				'errors'  => array_slice( $errors, 0, 100 ),
			)
		);
	}

	/** Why a page can't be linked to $term ('' when it can): must be a page, not a hood page, not another landmark's page. */
	private static function page_link_error( $pid, $term ) {
		$post = $pid ? get_post( $pid ) : null;
		if ( ! $post || 'page' !== $post->post_type ) {
			return 'is not a page';
		}
		if ( Hoods::term_for_page( $pid ) ) {
			return 'is already linked to a neighborhood';
		}
		$other = self::term_for_page( $pid );
		if ( $other && ( ! $term || $other->term_id !== $term->term_id ) ) {
			return 'is already linked to another landmark';
		}
		return '';
	}

	/** POST /osn/v1/landmarks/pages: link or unlink landing pages. Rows: { page_id, landmark_slug|null }. */
	public static function rest_pages( \WP_REST_Request $req ) {
		$rows   = (array) $req->get_param( 'pages' );
		$dry    = (bool) $req->get_param( 'dry_run' );
		$cname  = trim( sanitize_text_field( (string) $req->get_param( 'city' ) ) );
		$cname  = '' !== $cname ? self::canon_city( $cname ) : '';
		$counts = array( 'linked' => 0, 'unlinked' => 0, 'unchanged' => 0, 'error' => 0 );
		$errors = array();
		$cities = array();
		foreach ( $rows as $row ) {
			self::flush_memo(); // Earlier rows may have changed links.
			$pid  = is_array( $row ) && isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
			$post = $pid ? get_post( $pid ) : null;
			if ( ! $post || 'page' !== $post->post_type ) {
				++$counts['error'];
				$errors[] = array( 'page_id' => $pid, 'error' => 'not a page' );
				continue;
			}
			$term = null;
			if ( array_key_exists( 'landmark_slug', $row ) && null !== $row['landmark_slug'] && '' !== $row['landmark_slug'] ) {
				$term = self::resolve( $row['landmark_slug'], $cname );
				if ( ! $term ) {
					++$counts['error'];
					$errors[] = array( 'page_id' => $pid, 'error' => 'unknown landmark_slug ' . sanitize_title( (string) $row['landmark_slug'] ) );
					continue;
				}
				$why = self::page_link_error( $pid, $term );
				if ( '' !== $why ) {
					++$counts['error'];
					$errors[] = array( 'page_id' => $pid, 'error' => 'page ' . $why );
					continue;
				}
			} elseif ( ! array_key_exists( 'landmark_slug', $row ) ) {
				++$counts['error'];
				$errors[] = array( 'page_id' => $pid, 'error' => 'landmark_slug is required (use null to unlink)' );
				continue;
			}
			$current = self::term_for_page( $pid );
			$touched = false;
			if ( $term ) {
				if ( ! $current || $current->term_id !== $term->term_id || (int) get_term_meta( $term->term_id, 'osn_page_id', true ) !== $pid ) {
					if ( ! $dry ) {
						if ( $current ) {
							update_term_meta( $current->term_id, 'osn_page_id', 0 );
						}
						update_term_meta( $term->term_id, 'osn_page_id', $pid );
					}
					++$counts['linked'];
					$touched = true;
				}
			} elseif ( $current ) {
				$dry || update_term_meta( $current->term_id, 'osn_page_id', 0 );
				++$counts['unlinked'];
				$touched = true;
			}
			if ( ! $touched ) {
				++$counts['unchanged'];
				continue;
			}
			$c = self::city_term( $term ? $term : $current );
			if ( $c ) {
				$cities[] = $c->name;
			}
			$dry || clean_post_cache( $pid );
		}
		if ( ! $dry && $cities ) {
			self::purge( $cities );
		}
		return rest_ensure_response( array( 'dry_run' => $dry, 'counts' => $counts, 'errors' => array_slice( $errors, 0, 100 ) ) );
	}

	/** GET /osn/v1/landmarks?city= : the landmarks with counts, casinos, page ids/links. */
	public static function rest_tree( \WP_REST_Request $req ) {
		$city = self::city_param( $req );
		if ( ! $city ) {
			return self::bad( 'bad_city', 'city must look like "Las Vegas, NV".' );
		}
		$cterm = Util::find_city( $city );
		$ids   = $cterm ? self::city_venue_ids( $cterm ) : array();
		$vis   = $cterm ? Util::sorted_ids( $cterm->term_id ) : array();
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
			update_object_term_cache( $ids, Data_Model::POST_TYPE );
		}
		$all     = array();
		$visible = array();
		$casinos = array();
		$none    = 0;
		foreach ( $ids as $id ) {
			$t = self::venue_term( $id );
			$k = $t ? $t->term_id : 0;
			if ( ! $k ) {
				++$none;
			}
			$all[ $k ] = ( $all[ $k ] ?? 0 ) + 1;
			$c         = self::venue_casino( $id );
			if ( $k && '' !== $c['name'] ) {
				$casinos[ $k ][ $c['slug'] ] = array( 'name' => $c['name'], 'slug' => $c['slug'], 'venues' => ( $casinos[ $k ][ $c['slug'] ]['venues'] ?? 0 ) + 1 );
			}
		}
		foreach ( $vis as $id ) {
			$t = self::venue_term( $id );
			$k = $t ? $t->term_id : 0;
			$visible[ $k ] = ( $visible[ $k ] ?? 0 ) + 1;
		}
		$out = array();
		foreach ( self::terms_for_city( $city ) as $t ) {
			$pid   = (int) get_term_meta( $t->term_id, 'osn_page_id', true );
			$out[] = array(
				'slug'        => $t->slug,
				'short'       => self::short( $t ),
				'name'        => $t->name,
				'description' => $t->description,
				'order'       => (int) get_term_meta( $t->term_id, 'osn_order', true ),
				'h1'          => self::custom_h1( $t ),
				'venues'      => $visible[ $t->term_id ] ?? 0,
				'venues_all'  => $all[ $t->term_id ] ?? 0,
				'casinos'     => array_values( $casinos[ $t->term_id ] ?? array() ),
				'page_id'     => $pid,
				'page_status' => $pid ? get_post_status( $pid ) : null,
				'link'        => $pid ? get_permalink( $pid ) : null,
			);
		}
		return rest_ensure_response(
			array(
				'city'                => $city,
				'enabled'             => $cterm ? Hoods::city_enabled( $cterm ) : false,
				'venues_visible'      => count( $vis ),
				'venues_all'          => count( $ids ),
				'venues_no_landmark'  => $none,
				'landmarks'           => $out,
			)
		);
	}
}
