<?php
/**
 * Neighborhoods (sub-areas of a city): osn_hood taxonomy helpers, REST import/assign/pages/tree,
 * landing page redirects, and the markup for the district/neighborhood UI on city and hood views.
 *
 * Data model: osn_hood is hierarchical. Top-level terms are districts, their children are neighborhoods.
 * A venue carries ONE osn_hood term (its neighborhood); the district is derived through the parent.
 * Term slugs are prefixed with the city slug ("seattle-wa-ballard"); URLs and data attributes use the
 * short slug ("ballard"). Term meta: osn_city, osn_page_id, osn_neighbors, osn_redirect.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Hoods {

	const TAX          = 'osn_hood';
	const REDIRECT_KEY = '_osn_redirect';
	const MAX_ASSIGN   = 1000;

	/** @var array<int,int> Per-request memo: term ID => landing page ID usable for links. */
	private static $memo_page = array();
	/** @var array<int,\WP_Term|null> Per-request memo: page ID => linked term. */
	private static $memo_term = array();

	/** Forget per-request memos (after writes). */
	public static function flush_memo() {
		self::$memo_page = array();
		self::$memo_term = array();
	}

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
	}

	/* ---------------------------------------------------------------- term helpers */

	public static function city_prefix( $city_name ) {
		return sanitize_title( (string) $city_name ) . '-';
	}

	/** Slug without the city prefix: what URLs and data attributes use. */
	public static function short( $term ) {
		static $memo = array();
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		if ( ! isset( $memo[ $term->term_id ] ) ) {
			$prefix = self::city_prefix( (string) get_term_meta( $term->term_id, 'osn_city', true ) );
			$memo[ $term->term_id ] = ( '-' !== $prefix && 0 === strpos( $term->slug, $prefix ) ) ? substr( $term->slug, strlen( $prefix ) ) : $term->slug;
			if ( ! $term->parent && preg_match( '/^(.+)-district$/', $memo[ $term->term_id ], $m ) ) {
				$memo[ $term->term_id ] = $m[1]; // Collision suffix is internal; URLs read ?district=ballard.
			}
		}
		return $memo[ $term->term_id ];
	}

	/** All hood terms (districts and neighborhoods) of a city, by name. @return \WP_Term[] */
	public static function terms_for_city( $city_name ) {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => 'osn_city', 'value' => (string) $city_name ),
				),
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/** Resolve a full or short slug to a term. The city name (when known) disambiguates short slugs. */
	public static function resolve( $slug, $city_name = '' ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return null;
		}
		$t = get_term_by( 'slug', $slug, self::TAX );
		if ( $t instanceof \WP_Term ) {
			return $t;
		}
		if ( '' !== (string) $city_name ) {
			$t = get_term_by( 'slug', self::city_prefix( $city_name ) . $slug, self::TAX );
			return $t instanceof \WP_Term ? $t : null;
		}
		$found = array();
		$all   = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false ) );
		foreach ( is_wp_error( $all ) ? array() : $all as $term ) {
			if ( self::short( $term ) === $slug ) {
				$found[] = $term;
			}
		}
		return 1 === count( $found ) ? $found[0] : null;
	}

	/** Sibling neighborhood slugs (full) recorded for a term. @return string[] */
	public static function neighbors( $term_id ) {
		return array_values( array_filter( (array) get_term_meta( $term_id, 'osn_neighbors', true ), function ( $v ) { return is_string( $v ) && '' !== $v; } ) );
	}

	/** The osn_city term for a hood term. */
	public static function city_term( $term ) {
		return $term instanceof \WP_Term ? Util::find_city( (string) get_term_meta( $term->term_id, 'osn_city', true ) ) : null;
	}

	/** Enabled-cities check shared with the takeover. */
	public static function city_enabled( $city_term ) {
		if ( ! $city_term ) {
			return false;
		}
		$enabled = Takeover::enabled();
		return true === $enabled || in_array( $city_term->slug, $enabled, true );
	}

	/** A redirect URL recorded for the term or for its landing page, '' when none. */
	public static function redirect_for_term( $term ) {
		$r = (string) get_term_meta( $term->term_id, 'osn_redirect', true );
		if ( '' !== $r ) {
			return $r;
		}
		$pid = (int) get_term_meta( $term->term_id, 'osn_page_id', true );
		return $pid ? (string) get_post_meta( $pid, self::REDIRECT_KEY, true ) : '';
	}

	/** Landing page ID of a hood term when it exists, is published and is not redirected, else 0. */
	public static function page_id( $term ) {
		$memo = &self::$memo_page;
		if ( ! $term instanceof \WP_Term ) {
			return 0;
		}
		if ( ! isset( $memo[ $term->term_id ] ) ) {
			$pid = (int) get_term_meta( $term->term_id, 'osn_page_id', true );
			if ( $pid && 'publish' !== get_post_status( $pid ) ) {
				$pid = 0;
			}
			if ( $pid && '' !== self::redirect_for_term( $term ) ) {
				$pid = 0;
			}
			$memo[ $term->term_id ] = $pid;
		}
		return $memo[ $term->term_id ];
	}

	public static function page_url( $term ) {
		$pid = self::page_id( $term );
		return $pid ? (string) get_permalink( $pid ) : '';
	}

	/** The hood term a page is linked to (osn_page_id), or null. */
	public static function term_for_page( $page_id ) {
		$memo    = &self::$memo_term;
		$page_id = (int) $page_id;
		if ( ! $page_id ) {
			return null;
		}
		if ( ! array_key_exists( $page_id, $memo ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => self::TAX,
					'hide_empty' => false,
					'number'     => 1,
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => 'osn_page_id', 'value' => $page_id, 'type' => 'NUMERIC' ),
					),
				)
			);
			$memo[ $page_id ] = ( ! is_wp_error( $terms ) && $terms ) ? $terms[0] : null;
		}
		return $memo[ $page_id ];
	}

	/**
	 * The hood term for a legacy page: linked by osn_page_id, else the filter's "Hood, City" matched
	 * against the hoods of the city named by the page's parent page. Does not check enablement.
	 */
	public static function match_page( $post, $filter ) {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$t = self::term_for_page( $post->ID );
		if ( $t ) {
			return $t;
		}
		if ( ! $post->post_parent || ! preg_match( '/^(.+),\s*([^,]+)$/', (string) $filter, $m ) ) {
			return null;
		}
		$pf   = Page_Title::filter_from_content( (string) get_post_field( 'post_content', $post->post_parent ) );
		$city = '' !== $pf ? Util::find_city( $pf ) : null;
		if ( ! $city || Util::lower( Util::city_label( $city->name ) ) !== Util::lower( trim( $m[2] ) ) ) {
			return null;
		}
		$name = trim( $m[1] );
		$t    = get_term_by( 'slug', self::city_prefix( $city->name ) . sanitize_title( $name ), self::TAX );
		if ( ! $t instanceof \WP_Term ) {
			$t = null;
			foreach ( self::terms_for_city( $city->name ) as $term ) {
				if ( Util::lower( $term->name ) === Util::lower( $name ) ) {
					$t = $term;
					break;
				}
			}
		}
		if ( $t ) {
			// A hood that already has a different published landing page is not this page's hood.
			$pid = (int) get_term_meta( $t->term_id, 'osn_page_id', true );
			if ( $pid && $pid !== (int) $post->ID && 'publish' === get_post_status( $pid ) ) {
				return null;
			}
		}
		return $t;
	}

	/** The neighborhood name this page prints: the filter's part before the last comma, else the term name. */
	public static function page_hood_name( $filter, $term ) {
		$p = strrpos( (string) $filter, ',' );
		$n = false !== $p ? trim( substr( $filter, 0, $p ) ) : '';
		return '' !== $n ? $n : $term->name;
	}

	/** The city term a page belongs to (hood link, else the parent page's [table] filter), or null. */
	public static function page_city( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$t = self::term_for_page( $post->ID );
		if ( $t ) {
			return self::city_term( $t );
		}
		if ( $post->post_parent ) {
			$pf = Page_Title::filter_from_content( (string) get_post_field( 'post_content', $post->post_parent ) );
			return '' !== $pf ? Util::find_city( $pf ) : null;
		}
		return null;
	}

	/** { hood, district } terms for a venue (either may be null). */
	public static function venue_info( $id ) {
		$terms = get_the_terms( $id, self::TAX );
		$hood  = null;
		$dist  = null;
		foreach ( ( $terms && ! is_wp_error( $terms ) ) ? $terms : array() as $t ) {
			if ( $t->parent ) {
				$hood = $t;
				break;
			}
			$dist = $dist ? $dist : $t;
		}
		if ( $hood ) {
			$p    = get_term( $hood->parent, self::TAX );
			$dist = $p instanceof \WP_Term ? $p : null;
		}
		return array( 'hood' => $hood, 'district' => $dist );
	}

	/**
	 * Districts and neighborhoods of a city with visible-venue counts.
	 *
	 * @param \WP_Term $city_term City term.
	 * @param int[]    $ids       Visible venue IDs (term cache must be primed).
	 * @return array { districts: [ id => { term, count, hoods: [ id => { term, count } ] } ], by_venue: [ id => { hood, district } ] }
	 */
	public static function city_tree( $city_term, array $ids ) {
		$out = array( 'districts' => array(), 'by_venue' => array() );
		$terms = self::terms_for_city( $city_term->name );
		if ( ! $terms ) {
			return $out;
		}
		$byid = array();
		foreach ( $terms as $t ) {
			$byid[ $t->term_id ] = $t;
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
		$dcount = array();
		$hcount = array();
		foreach ( $ids as $id ) {
			$info = self::venue_info( $id );
			if ( ! $info['hood'] && ! $info['district'] ) {
				continue;
			}
			$out['by_venue'][ $id ] = $info;
			if ( $info['district'] ) {
				$dcount[ $info['district']->term_id ] = ( $dcount[ $info['district']->term_id ] ?? 0 ) + 1;
			}
			if ( $info['hood'] ) {
				$hcount[ $info['hood']->term_id ] = ( $hcount[ $info['hood']->term_id ] ?? 0 ) + 1;
			}
		}
		foreach ( $terms as $t ) {
			if ( $t->parent || empty( $dcount[ $t->term_id ] ) ) {
				continue;
			}
			$out['districts'][ $t->term_id ] = array( 'term' => $t, 'count' => $dcount[ $t->term_id ], 'hoods' => array() );
		}
		foreach ( $terms as $t ) {
			if ( $t->parent && ! empty( $hcount[ $t->term_id ] ) && isset( $out['districts'][ $t->parent ] ) ) {
				$out['districts'][ $t->parent ]['hoods'][ $t->term_id ] = array( 'term' => $t, 'count' => $hcount[ $t->term_id ] );
			}
		}
		return $out;
	}

	/** Does a venue's { hood, district } match the term (a neighborhood, or a district with all its children)? */
	public static function venue_in( $info, $term ) {
		if ( ! $info ) {
			return false;
		}
		return ( $info['hood'] && $info['hood']->term_id === $term->term_id ) || ( $info['district'] && $info['district']->term_id === $term->term_id );
	}

	/* ---------------------------------------------------------------- markup */

	/** Anchor target on the grid's count line; city pages carry one grid so -1 is stable. */
	public static function results_anchor( $n ) {
		return 'osn-results-' . (int) $n;
	}

	/** Link for a hood: its landing page, else the city page filtered via ?hood=. */
	private static function link_for( $term, $filter_base, $anchor ) {
		$url = self::page_url( $term );
		if ( '' !== $url ) {
			return array( $url, false );
		}
		return array( $filter_base . '?hood=' . rawurlencode( self::short( $term ) ) . '#' . $anchor, true );
	}

	/** District chip row + neighborhood chip row (rows are filled in client side; no-JS visitors see the browse section). */
	public static function chips_html( array $tree, $total ) {
		if ( ! $tree['districts'] ) {
			return '';
		}
		ob_start();
		?>
<div class="osn-filter-section osn-filter-section--area">
<p class="osn-filter-label"><?php esc_html_e( 'Neighborhoods', 'dos-outdoor-seating' ); ?></p>
<div class="osn-filters__chips osn-filters__chips--districts" role="group" aria-label="<?php esc_attr_e( 'Filter by district', 'dos-outdoor-seating' ); ?>" data-osn-districts>
<button type="button" class="osn-chip osn-chip--filter osn-chip--district" aria-pressed="true" data-osn-district=""><?php esc_html_e( 'All', 'dos-outdoor-seating' ); ?> <span class="osn-chip__count"><?php echo (int) $total; ?></span></button>
<?php foreach ( $tree['districts'] as $d ) : ?>
<button type="button" class="osn-chip osn-chip--filter osn-chip--district" aria-pressed="false" data-osn-district="<?php echo esc_attr( self::short( $d['term'] ) ); ?>"><?php echo esc_html( $d['term']->name ); ?> <span class="osn-chip__count"><?php echo (int) $d['count']; ?></span></button>
<?php endforeach; ?>
</div>
<div class="osn-area-sub">
<div class="osn-filters__chips osn-filters__chips--hoods" role="group" aria-label="<?php esc_attr_e( 'Filter by neighborhood', 'dos-outdoor-seating' ); ?>" data-osn-hoods hidden>
<?php foreach ( $tree['districts'] as $d ) : ?>
<?php foreach ( $d['hoods'] as $h ) : ?>
<button type="button" class="osn-chip osn-chip--filter osn-chip--hood" aria-pressed="false" data-osn-hood="<?php echo esc_attr( self::short( $h['term'] ) ); ?>" data-district="<?php echo esc_attr( self::short( $d['term'] ) ); ?>" hidden><?php echo esc_html( $h['term']->name ); ?> <span class="osn-chip__count"><?php echo (int) $h['count']; ?></span></button>
<?php endforeach; ?>
<?php endforeach; ?>
</div>
</div>
</div>
		<?php
		return ob_get_clean();
	}

	/** "Browse by neighborhood" section (id hoods), grouped by district. */
	public static function browse_html( array $tree, $anchor ) {
		if ( ! $tree['districts'] ) {
			return '';
		}
		ob_start();
		?>
<section class="osn-hoods" id="hoods" aria-labelledby="hoods-h-<?php echo esc_attr( $anchor ); ?>">
<h2 id="hoods-h-<?php echo esc_attr( $anchor ); ?>"><?php esc_html_e( 'Browse by neighborhood', 'dos-outdoor-seating' ); ?></h2>
<div class="osn-hoods__acc">
<?php foreach ( $tree['districts'] as $d ) : ?>
<details class="osn-hoods__group" data-osn-hoods-group="<?php echo esc_attr( self::short( $d['term'] ) ); ?>">
<summary class="osn-hoods__district"><span class="osn-hoods__name"><?php echo esc_html( $d['term']->name ); ?></span> <span class="osn-chip__count"><?php echo (int) $d['count']; ?></span><span class="osn-hoods__chev" aria-hidden="true"></span></summary>
<?php if ( $d['hoods'] ) : ?>
<ul class="osn-hoods__list">
<?php
foreach ( $d['hoods'] as $h ) :
	list( $url, $filter ) = self::link_for( $h['term'], '', $anchor );
	?>
<li data-osn-hood-link="<?php echo esc_attr( self::short( $h['term'] ) ); ?>"><a href="<?php echo esc_url( $url ); ?>"<?php echo $filter ? ' rel="nofollow"' : ''; ?>><?php echo esc_html( $h['term']->name ); ?></a> <span class="osn-chip__count"><?php echo (int) $h['count']; ?></span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</details>
<?php endforeach; ?>
</div>
</section>
		<?php
		return ob_get_clean();
	}

	/** Hood view extras: "Nearby neighborhoods" strip and the link back to the city page. */
	public static function nearby_html( array $tree, $hood, $city_term, $city_label ) {
		$counts = array();
		$terms  = array();
		foreach ( $tree['districts'] as $d ) {
			foreach ( $d['hoods'] as $h ) {
				$counts[ $h['term']->slug ] = $h['count'];
				$terms[ $h['term']->slug ]  = $h['term'];
			}
		}
		$slugs = array();
		foreach ( self::neighbors( $hood->term_id ) as $s ) {
			if ( isset( $counts[ $s ] ) && $s !== $hood->slug ) {
				$slugs[] = $s;
			}
		}
		if ( ! $slugs ) { // Fallback: the other neighborhoods in the same district.
			foreach ( $terms as $s => $t ) {
				if ( $t->parent === ( $hood->parent ? $hood->parent : $hood->term_id ) && $s !== $hood->slug ) {
					$slugs[] = $s;
				}
			}
		}
		$city_url = Util::city_url( $city_term );
		ob_start();
		if ( $slugs ) :
			?>
<section class="osn-hoods osn-hoods--nearby" aria-labelledby="hoods-near-h-<?php echo (int) $hood->term_id; ?>">
<h2 id="hoods-near-h-<?php echo (int) $hood->term_id; ?>"><?php esc_html_e( 'Nearby neighborhoods', 'dos-outdoor-seating' ); ?></h2>
<ul class="osn-hoods__list">
<?php
foreach ( $slugs as $s ) :
	list( $url, $filter ) = self::link_for( $terms[ $s ], $city_url, self::results_anchor( 1 ) );
	if ( $filter && '' === $city_url ) {
		continue;
	}
	?>
<li><a href="<?php echo esc_url( $url ); ?>"<?php echo $filter ? ' rel="nofollow"' : ''; ?>><?php echo esc_html( $terms[ $s ]->name ); ?></a> <span class="osn-chip__count"><?php echo (int) $counts[ $s ]; ?></span></li>
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

	/* ---------------------------------------------------------------- redirects + robots */

	/** Redirect target for the queried page, '' when none or when it points at the page itself. */
	private static function redirect_target( $post ) {
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return '';
		}
		$r = (string) get_post_meta( $post->ID, self::REDIRECT_KEY, true );
		if ( '' === $r ) {
			return '';
		}
		$self = untrailingslashit( (string) get_permalink( $post ) );
		$abs  = 0 === strpos( $r, '/' ) ? home_url( $r ) : $r;
		if ( untrailingslashit( $abs ) === $self || untrailingslashit( $r ) === $self ) {
			return '';
		}
		return $r;
	}

	public static function maybe_redirect() {
		if ( ! is_page() ) {
			return;
		}
		$r = self::redirect_target( get_queried_object() );
		if ( '' !== $r ) {
			wp_redirect( esc_url_raw( $r ), 301, 'DoS Outdoor Seating' ); // phpcs:ignore WordPress.Security.SafeRedirect -- admin-set target, may be another host.
			exit;
		}
	}

	/** A redirected page that somehow renders is noindex. */
	public static function robots( $robots ) {
		if ( is_page() && '' !== self::redirect_target( get_queried_object() ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
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
			'/hoods',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_tree' ),
				'permission_callback' => $can,
				'args'                => array( 'city' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
		register_rest_route(
			$ns,
			'/hoods/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_import' ),
				'permission_callback' => $can,
				'args'                => array(
					'city'      => array( 'type' => 'string', 'required' => true ),
					'districts' => array( 'type' => 'array', 'required' => true ),
					'dry_run'   => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/hoods/venues',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_venues' ),
				'permission_callback' => $can,
				'args'                => array( 'city' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
		register_rest_route(
			$ns,
			'/hoods/assign',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_assign' ),
				'permission_callback' => $can,
				'args'                => array(
					'assignments' => array( 'type' => 'array', 'required' => true ),
					'dry_run'     => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/hoods/pages',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_pages' ),
				'permission_callback' => $can,
				'args'                => array(
					'pages'   => array( 'type' => 'array', 'required' => true ),
					'dry_run' => array( 'type' => 'boolean', 'default' => false ),
					'city'    => array( 'description' => 'Optional "City, ST" to resolve short hood slugs.', 'type' => 'string' ),
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

	/** Distinct city term IDs -> purge sorted lists + landing page caches, and tell page caches the hoods changed. */
	private static function purge( array $city_names ) {
		self::flush_memo();
		$ids = array();
		foreach ( array_unique( $city_names ) as $name ) {
			$t = Util::find_city( $name );
			if ( $t ) {
				$ids[] = $t->term_id;
				do_action( 'osn_hoods_changed', $t->term_id );
			}
		}
		Repository::purge_cities( $ids );
	}

	/** Upsert one hood term. @return array { id, state: created|updated|unchanged } or WP_Error */
	private static function upsert_term( $name, $slug, $parent, $city, $page_id, $neighbors, $dry ) {
		$existing = get_term_by( 'slug', $slug, self::TAX );
		if ( ! $existing ) {
			if ( $dry ) {
				return array( 'id' => 0, 'state' => 'created' );
			}
			$r = wp_insert_term( $name, self::TAX, array( 'slug' => $slug, 'parent' => (int) $parent ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$id = (int) $r['term_id'];
			update_term_meta( $id, 'osn_city', $city );
			update_term_meta( $id, 'osn_page_id', (int) $page_id );
			if ( null !== $neighbors ) {
				update_term_meta( $id, 'osn_neighbors', $neighbors );
			}
			return array( 'id' => $id, 'state' => 'created' );
		}
		$id      = $existing->term_id;
		$changed = false;
		if ( $existing->name !== $name || (int) $existing->parent !== (int) $parent ) {
			$changed = true;
			if ( ! $dry ) {
				$r = wp_update_term( $id, self::TAX, array( 'name' => $name, 'parent' => (int) $parent ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}
		if ( (string) get_term_meta( $id, 'osn_city', true ) !== $city ) {
			$changed = true;
			$dry || update_term_meta( $id, 'osn_city', $city );
		}
		if ( null !== $page_id && (int) get_term_meta( $id, 'osn_page_id', true ) !== (int) $page_id ) {
			$changed = true;
			$dry || update_term_meta( $id, 'osn_page_id', (int) $page_id );
		}
		if ( null !== $neighbors && self::neighbors( $id ) !== $neighbors ) {
			$changed = true;
			$dry || update_term_meta( $id, 'osn_neighbors', $neighbors );
		}
		return array( 'id' => $id, 'state' => $changed ? 'updated' : 'unchanged' );
	}

	/** POST /osn/v1/hoods/import */
	public static function rest_import( \WP_REST_Request $req ) {
		$city = self::city_param( $req );
		if ( ! $city ) {
			return self::bad( 'bad_city', 'city must look like "Seattle, WA".' );
		}
		$dry       = (bool) $req->get_param( 'dry_run' );
		$districts = (array) $req->get_param( 'districts' );
		$prefix    = self::city_prefix( $city );
		$full      = function ( $s ) use ( $prefix ) {
			$s = sanitize_title( (string) $s );
			return 0 === strpos( $s, $prefix ) ? $s : $prefix . $s;
		};

		// Neighborhood slugs first: a district whose slug collides with one gets "-district".
		$hood_slugs = array();
		foreach ( $districts as $d ) {
			foreach ( (array) ( $d['neighborhoods'] ?? array() ) as $h ) {
				$hood_slugs[ $full( $h['slug'] ?? ( $h['name'] ?? '' ) ) ] = true;
			}
		}

		$counts   = array(
			'districts'     => array( 'created' => 0, 'updated' => 0, 'unchanged' => 0 ),
			'neighborhoods' => array( 'created' => 0, 'updated' => 0, 'unchanged' => 0 ),
		);
		$errors   = array();
		$warnings = array();
		$seen     = array();
		$pending  = array(); // hood full slug => neighbor slugs as given
		foreach ( $districts as $d ) {
			$dname = trim( sanitize_text_field( (string) ( $d['name'] ?? '' ) ) );
			$dslug = $full( $d['slug'] ?? $dname );
			if ( '' === $dname ) {
				$errors[] = 'District without a name.';
				continue;
			}
			if ( isset( $hood_slugs[ $dslug ] ) ) {
				$dslug .= '-district';
			}
			$r = self::upsert_term( $dname, $dslug, 0, $city, isset( $d['page_id'] ) ? absint( $d['page_id'] ) : null, null, $dry );
			if ( is_wp_error( $r ) ) {
				$errors[] = $dname . ': ' . $r->get_error_message();
				continue;
			}
			++$counts['districts'][ $r['state'] ];
			$seen[ $dslug ] = true;
			foreach ( (array) ( $d['neighborhoods'] ?? array() ) as $h ) {
				$hname = trim( sanitize_text_field( (string) ( $h['name'] ?? '' ) ) );
				$hslug = $full( $h['slug'] ?? $hname );
				if ( '' === $hname ) {
					$errors[] = $dname . ': neighborhood without a name.';
					continue;
				}
				$page = isset( $h['page_id'] ) ? absint( $h['page_id'] ) : null;
				$hr   = self::upsert_term( $hname, $hslug, $r['id'], $city, $page, null, $dry );
				if ( is_wp_error( $hr ) ) {
					$errors[] = $hname . ': ' . $hr->get_error_message();
					continue;
				}
				++$counts['neighborhoods'][ $hr['state'] ];
				$seen[ $hslug ] = true;
				if ( isset( $h['neighbors'] ) ) {
					$pending[ $hslug ] = (array) $h['neighbors'];
				}
			}
		}
		// Neighbors need every term in place first.
		$neighbor_updates = 0;
		foreach ( $pending as $hslug => $given ) {
			$list = array();
			foreach ( $given as $n ) {
				$n = $full( $n );
				if ( isset( $hood_slugs[ $n ] ) && $n !== $hslug ) {
					$list[] = $n;
				} else {
					$warnings[] = $hslug . ': unknown neighbor ' . $n;
				}
			}
			$list = array_values( array_unique( $list ) );
			$t    = get_term_by( 'slug', $hslug, self::TAX );
			if ( $t && self::neighbors( $t->term_id ) !== $list ) {
				++$neighbor_updates;
				$dry || update_term_meta( $t->term_id, 'osn_neighbors', $list );
			}
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
				'dry_run'          => $dry,
				'city'             => $city,
				'districts'        => $counts['districts'],
				'neighborhoods'    => $counts['neighborhoods'],
				'neighbor_updates' => $neighbor_updates,
				'unmanaged_terms'  => $unmanaged,
				'warnings'         => array_slice( $warnings, 0, 50 ),
				'errors'           => $errors,
			)
		);
	}

	/** GET /osn/v1/hoods/venues?city= */
	public static function rest_venues( \WP_REST_Request $req ) {
		$city = self::city_param( $req );
		$term = $city ? Util::find_city( $city ) : null;
		if ( ! $term ) {
			return self::bad( 'bad_city', 'Unknown city.' );
		}
		$ids = get_posts(
			array(
				'post_type'      => Data_Model::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 5000,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array( 'taxonomy' => Data_Model::CITY, 'field' => 'term_id', 'terms' => $term->term_id ),
				),
			)
		);
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, Data_Model::POST_TYPE );
		$out = array();
		foreach ( $ids as $id ) {
			$info  = self::venue_info( $id );
			$lat   = Util::meta( $id, 'lat' );
			$lng   = Util::meta( $id, 'lng' );
			$out[] = array(
				'id'        => (int) $id,
				'title'     => get_the_title( $id ),
				'status'    => get_post_status( $id ),
				'lat'       => '' === $lat ? null : (float) $lat,
				'lng'       => '' === $lng ? null : (float) $lng,
				'hood_slug' => $info['hood'] ? $info['hood']->slug : ( $info['district'] ? $info['district']->slug : null ),
				'link'      => get_permalink( $id ),
			);
		}
		return rest_ensure_response( array( 'city' => $term->name, 'count' => count( $out ), 'venues' => $out ) );
	}

	/** POST /osn/v1/hoods/assign */
	public static function rest_assign( \WP_REST_Request $req ) {
		$rows = (array) $req->get_param( 'assignments' );
		if ( count( $rows ) > self::MAX_ASSIGN ) {
			return self::bad( 'batch_too_large', sprintf( 'Send at most %d assignments per request.', self::MAX_ASSIGN ) );
		}
		$dry    = (bool) $req->get_param( 'dry_run' );
		$counts = array( 'assigned' => 0, 'cleared' => 0, 'unchanged' => 0, 'error' => 0 );
		$errors = array();
		$cities = array();
		foreach ( $rows as $row ) {
			$id   = isset( $row['venue_id'] ) ? (int) $row['venue_id'] : 0;
			$post = $id ? get_post( $id ) : null;
			if ( ! $post || Data_Model::POST_TYPE !== $post->post_type ) {
				++$counts['error'];
				$errors[] = array( 'venue_id' => $id, 'error' => 'not a venue' );
				continue;
			}
			$city = Util::first_term( $id, Data_Model::CITY );
			$cur  = self::venue_info( $id );
			$cur  = $cur['hood'] ? $cur['hood'] : $cur['district'];
			$slug = $row['hood_slug'] ?? null;
			if ( null === $slug || '' === $slug ) {
				if ( ! $cur ) {
					++$counts['unchanged'];
					continue;
				}
				$dry || wp_set_object_terms( $id, array(), self::TAX );
				++$counts['cleared'];
			} else {
				$t = self::resolve( $slug, $city ? $city->name : '' );
				if ( ! $t ) {
					++$counts['error'];
					$errors[] = array( 'venue_id' => $id, 'error' => 'unknown hood_slug ' . sanitize_title( (string) $slug ) );
					continue;
				}
				if ( $city && (string) get_term_meta( $t->term_id, 'osn_city', true ) !== $city->name ) {
					++$counts['error'];
					$errors[] = array( 'venue_id' => $id, 'error' => 'hood belongs to another city' );
					continue;
				}
				if ( $cur && $cur->term_id === $t->term_id ) {
					++$counts['unchanged'];
					continue;
				}
				$dry || wp_set_object_terms( $id, array( $t->term_id ), self::TAX );
				++$counts['assigned'];
			}
			if ( $city ) {
				$cities[] = $city->name;
			}
		}
		if ( ! $dry && $cities ) {
			self::purge( $cities );
		}
		return rest_ensure_response( array( 'dry_run' => $dry, 'counts' => $counts, 'errors' => array_slice( $errors, 0, 100 ) ) );
	}

	/** POST /osn/v1/hoods/pages */
	public static function rest_pages( \WP_REST_Request $req ) {
		$rows   = (array) $req->get_param( 'pages' );
		$dry    = (bool) $req->get_param( 'dry_run' );
		$cname  = trim( sanitize_text_field( (string) $req->get_param( 'city' ) ) );
		$counts = array( 'linked' => 0, 'unlinked' => 0, 'redirects_set' => 0, 'redirects_cleared' => 0, 'unchanged' => 0, 'error' => 0 );
		$errors = array();
		$cities = array();
		foreach ( $rows as $row ) {
			self::flush_memo(); // Earlier rows may have changed links.
			$pid  = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
			$post = $pid ? get_post( $pid ) : null;
			if ( ! $post || 'page' !== $post->post_type ) {
				++$counts['error'];
				$errors[] = array( 'page_id' => $pid, 'error' => 'not a page' );
				continue;
			}
			$touched  = false;
			$term     = null;
			$has_hood = is_array( $row ) && array_key_exists( 'hood_slug', $row );
			if ( $has_hood && null !== $row['hood_slug'] && '' !== $row['hood_slug'] ) {
				$term = self::resolve( $row['hood_slug'], $cname );
				if ( ! $term ) {
					++$counts['error'];
					$errors[] = array( 'page_id' => $pid, 'error' => 'unknown hood_slug ' . sanitize_title( (string) $row['hood_slug'] ) );
					continue;
				}
			}
			if ( $has_hood ) {
				$current = self::term_for_page( $pid );
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
			}
			if ( is_array( $row ) && array_key_exists( 'redirect', $row ) ) {
				$url = is_string( $row['redirect'] ) ? esc_url_raw( trim( $row['redirect'] ) ) : '';
				$cur = (string) get_post_meta( $pid, self::REDIRECT_KEY, true );
				if ( '' !== $url ) {
					$tid = url_to_postid( $url );
					if ( $tid === $pid || ( $tid && '' !== (string) get_post_meta( $tid, self::REDIRECT_KEY, true ) ) ) {
						++$counts['error'];
						$errors[] = array( 'page_id' => $pid, 'error' => 'redirect points at itself or at another redirected page' );
						continue;
					}
				}
				if ( '' !== $url && $url !== $cur ) {
					$dry || update_post_meta( $pid, self::REDIRECT_KEY, $url );
					++$counts['redirects_set'];
					$touched = true;
				} elseif ( '' === $url && '' !== $cur ) {
					$dry || delete_post_meta( $pid, self::REDIRECT_KEY );
					++$counts['redirects_cleared'];
					$touched = true;
				}
				$lt = $term ? $term : self::term_for_page( $pid );
				if ( $lt && ! $dry ) {
					if ( '' !== $url ) {
						update_term_meta( $lt->term_id, 'osn_redirect', $url );
					} else {
						delete_term_meta( $lt->term_id, 'osn_redirect' );
					}
				}
			}
			if ( ! $touched ) {
				++$counts['unchanged'];
				continue;
			}
			$c = self::page_city( $post );
			$t = $term ? $term : self::term_for_page( $pid );
			$c = $t ? self::city_term( $t ) : $c;
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

	/** GET /osn/v1/hoods?city= : the tree with counts, page ids/links and redirects. */
	public static function rest_tree( \WP_REST_Request $req ) {
		$city = self::city_param( $req );
		$term = $city ? Util::find_city( $city ) : null;
		if ( ! $city ) {
			return self::bad( 'bad_city', 'city must look like "Seattle, WA".' );
		}
		$all_ids = $term ? get_posts(
			array(
				'post_type'      => Data_Model::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 5000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array( 'taxonomy' => Data_Model::CITY, 'field' => 'term_id', 'terms' => $term->term_id ),
				),
			)
		) : array();
		$vis = $term ? Util::sorted_ids( $term->term_id ) : array();
		if ( $all_ids ) {
			update_object_term_cache( $all_ids, Data_Model::POST_TYPE );
		}
		$count_all = array();
		foreach ( $all_ids as $id ) {
			$i = self::venue_info( $id );
			$k = $i['hood'] ? $i['hood']->term_id : ( $i['district'] ? $i['district']->term_id : 0 );
			$count_all[ $k ] = ( $count_all[ $k ] ?? 0 ) + 1;
		}
		$count_vis = array();
		foreach ( $vis as $id ) {
			$i = self::venue_info( $id );
			$k = $i['hood'] ? $i['hood']->term_id : ( $i['district'] ? $i['district']->term_id : 0 );
			$count_vis[ $k ] = ( $count_vis[ $k ] ?? 0 ) + 1;
		}
		$node = function ( $t ) use ( $count_all, $count_vis ) {
			$pid = (int) get_term_meta( $t->term_id, 'osn_page_id', true );
			return array(
				'slug'          => $t->slug,
				'short'         => self::short( $t ),
				'name'          => $t->name,
				'venues'        => $count_vis[ $t->term_id ] ?? 0,
				'venues_all'    => $count_all[ $t->term_id ] ?? 0,
				'page_id'       => $pid,
				'page_status'   => $pid ? get_post_status( $pid ) : null,
				'link'          => $pid ? get_permalink( $pid ) : null,
				'redirect'      => self::redirect_for_term( $t ) ? self::redirect_for_term( $t ) : null,
				'neighbors'     => self::neighbors( $t->term_id ),
			);
		};
		$districts = array();
		$terms     = self::terms_for_city( $city );
		foreach ( $terms as $t ) {
			if ( ! $t->parent ) {
				$n = $node( $t );
				$n['hoods'] = array();
				$districts[ $t->term_id ] = $n;
			}
		}
		foreach ( $terms as $t ) {
			if ( $t->parent && isset( $districts[ $t->parent ] ) ) {
				$districts[ $t->parent ]['hoods'][] = $node( $t );
			}
		}
		foreach ( $districts as &$d ) {
			$d['venues_in_district'] = $d['venues'] + array_sum( wp_list_pluck( $d['hoods'], 'venues' ) );
		}
		unset( $d );
		return rest_ensure_response(
			array(
				'city'              => $city,
				'enabled'           => $term ? self::city_enabled( $term ) : false,
				'venues_visible'    => count( $vis ),
				'venues_all'        => count( $all_ids ),
				'venues_unassigned' => $count_all[0] ?? 0,
				'districts'         => array_values( $districts ),
			)
		);
	}
}
