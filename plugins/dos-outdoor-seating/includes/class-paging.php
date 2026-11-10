<?php
/**
 * Numbered pages for the venue grids: /city-st/page/2/, 240 cards a page.
 *
 * Googlebot reads only the first 2 MB of an HTML file, and a big city's grid is more than that, so the grid is cut
 * into real numbered pages and every venue is server-rendered on exactly one of them. With JavaScript the rest of
 * the view is fetched on demand (Cards::rest_part), so search, chips and "Show more" still cover the whole view.
 *
 * Mostly pure statics (page maths, URLs, nav markup, titles). The two hooks are the 404 for a page number past the
 * end and the "Page N of T" meta description prefix.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Paging {

	const OPTION  = 'osn_cards_per_page';
	const DEFAULT = 240;
	const MIN     = 120; // osn.js reveals 120 cards at a time: a smaller page would make every visit fetch the next part on load.

	public static function hooks() {
		add_action( 'wp', array( __CLASS__, 'maybe_404' ) );
		add_filter( 'get_pagenum_link', array( __CLASS__, 'pagenum_link' ), 10, 2 );
	}

	/** Cards per numbered page. 0 means everything on one page: no nav, no on-demand loading (the rollback switch). */
	public static function per_page() {
		$n = max( 0, (int) apply_filters( 'osn_cards_per_page', get_option( self::OPTION, self::DEFAULT ) ) );
		return ( $n > 0 && $n < self::MIN ) ? self::MIN : $n; // 1 to 119 is raised to MIN; 0 stays "off".
	}

	/** Number of numbered pages for a view of $total venues (always at least 1). */
	public static function pages( $total ) {
		$per = self::per_page();
		if ( $per < 1 || (int) $total <= $per ) {
			return 1;
		}
		return (int) ceil( (int) $total / $per );
	}

	/** The ids that belong on numbered page $page. With paging off, all of them. */
	public static function slice( array $ids, $page ) {
		$per = self::per_page();
		if ( $per < 1 ) {
			return array_values( $ids );
		}
		return array_slice( array_values( $ids ), ( max( 1, (int) $page ) - 1 ) * $per, $per );
	}

	/** Page 1 is the bare permalink; page N is /page/N/ under it, which is what core's page rewrite already maps. */
	public static function url( $permalink, $n ) {
		if ( (int) $n <= 1 ) {
			return $permalink;
		}
		return trailingslashit( $permalink ) . 'page/' . (int) $n . '/';
	}

	/** Cache-buster for the REST parts: changes when the plugin or the view's ranked ids change. */
	public static function version( array $ids ) {
		return substr( md5( VERSION . implode( ',', $ids ) ), 0, 10 );
	}

	/** " – Page 2": the suffix for titles and the H1. */
	public static function label( $n ) {
		/* translators: %d: page number */
		return sprintf( __( ' – Page %d', 'dos-outdoor-seating' ), (int) $n );
	}

	/** Title with the page number before the first " | " (the site name), or at the end when there is none. */
	public static function title( $title, $n ) {
		$title = (string) $title;
		if ( (int) $n < 2 || '' === trim( $title ) ) {
			return $title;
		}
		$at = strpos( $title, ' | ' );
		return false === $at ? $title . self::label( $n ) : substr_replace( $title, self::label( $n ), $at, 0 );
	}

	/**
	 * The page number asked for, 1 when none. Reads the main query's page only when the queried page is $post, so
	 * it never depends on loop state (Themify Builder runs the_content more than once) and a [table] shortcode
	 * printed on some other page is not paged by that page's URL.
	 */
	public static function requested( $post ) {
		if ( ! $post instanceof \WP_Post || ! is_page() || (int) get_queried_object_id() !== (int) $post->ID ) {
			return 1;
		}
		return max( 1, (int) get_query_var( 'paged' ) );
	}

	/**
	 * The numbered links. Plain anchors to every page so crawlers reach every venue; no rel prev/next (Google
	 * ignores it). The current page is a span, not a link. Empty when there is one page.
	 */
	public static function nav_html( $permalink, $page, $pages, $total ) {
		$page  = max( 1, (int) $page );
		$pages = (int) $pages;
		if ( $pages < 2 ) {
			return '';
		}
		$per   = self::per_page();
		$from  = ( $page - 1 ) * $per + 1;
		$to    = min( (int) $total, $page * $per );
		ob_start();
		?>
<nav class="osn-pages" data-osn-pages aria-label="<?php esc_attr_e( 'Pages', 'dos-outdoor-seating' ); ?>">
<p class="osn-pages__status"><?php echo esc_html( sprintf( /* translators: 1: current page, 2: number of pages */ __( 'Page %1$d of %2$d', 'dos-outdoor-seating' ), $page, $pages ) ); ?> <span class="osn-pages__range"><?php echo esc_html( sprintf( /* translators: 1: first place on this page, 2: last place, 3: total places */ __( '(places %1$s to %2$s of %3$s)', 'dos-outdoor-seating' ), number_format_i18n( $from ), number_format_i18n( $to ), number_format_i18n( (int) $total ) ) ); ?></span></p>
<ul class="osn-pages__list">
<?php if ( $page > 1 ) : ?>
<li><a class="osn-pages__link osn-pages__step" href="<?php echo esc_url( self::url( $permalink, $page - 1 ) ); ?>">&larr; <?php esc_html_e( 'Previous', 'dos-outdoor-seating' ); ?></a></li>
<?php endif; ?>
<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
<?php if ( $i === $page ) : ?>
<li><span class="osn-pages__link is-current" aria-current="page"><?php echo (int) $i; ?></span></li>
<?php else : ?>
<li><a class="osn-pages__link" href="<?php echo esc_url( self::url( $permalink, $i ) ); ?>"><?php echo (int) $i; ?></a></li>
<?php endif; ?>
<?php endfor; ?>
<?php if ( $page < $pages ) : ?>
<li><a class="osn-pages__link osn-pages__step" href="<?php echo esc_url( self::url( $permalink, $page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'dos-outdoor-seating' ); ?> &rarr;</a></li>
<?php endif; ?>
</ul>
</nav>
		<?php
		return ob_get_clean();
	}

	/** @var array<int,int> Page count per grid page, for this request. */
	private static $grid_pages = array();

	public static function flush_memo() {
		self::$grid_pages = array();
	}

	/** Number of numbered pages of the takeover grid on $post, or 0 when $post is not a takeover grid page. */
	public static function grid_pages( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return 0;
		}
		if ( ! isset( self::$grid_pages[ $post->ID ] ) ) {
			$ctx  = Takeover::context( $post );
			$view = $ctx ? Cards::view( $ctx['city'], $ctx['hood'], $ctx['spot'] ) : null;
			self::$grid_pages[ $post->ID ] = ( $view && $view['ids'] ) ? self::pages( count( $view['ids'] ) ) : 0;
		}
		return self::$grid_pages[ $post->ID ];
	}

	/**
	 * A page number past the end of a grid is a real 404 (it used to return page 1 again, a duplicate of the
	 * canonical page). That includes /page/2/ on a grid that fits on one page. Pages that are not takeover grids
	 * are left exactly as they were.
	 */
	public static function maybe_404() {
		global $wp_query;
		// Paging off is the rollback switch, and it has to switch this off too: /page/N/ then answers as it did
		// before 0.6.12 (page 1 again), so a problem in this path can be taken out of play from the settings screen.
		if ( is_admin() || ! is_page() || self::per_page() < 1 ) {
			return;
		}
		$post = get_queried_object();
		$n    = self::requested( $post );
		if ( $n < 2 ) {
			return;
		}
		$pages = self::grid_pages( $post );
		if ( $pages < 1 || $n <= $pages ) {
			return;
		}
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		// Core's redirect_canonical() answers a 404 by guessing a permalink from the slug, which set_404() leaves in
		// the query: /seattle-wa/page/99/ would 301 to /seattle-wa/, and /<city>/downtown/page/9/ to whichever
		// "downtown…" post matches first, possibly in another city. The answer here is the 404 itself.
		add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
	}

	/**
	 * The link to page N of a grid page, without the current request's query string. Core's get_pagenum_link()
	 * keeps the query string, and DoS Toolkit uses it for the canonical, so /city/page/2/?utm_source=x (or the
	 * ?district= that osn.js writes) would name itself canonical. Page 1's canonical is already clean.
	 */
	public static function pagenum_link( $link, $n = 0 ) {
		if ( is_admin() || ! is_page() || self::per_page() < 1 ) {
			return $link;
		}
		$post = get_queried_object();
		if ( self::grid_pages( $post ) < 1 ) {
			return $link;
		}
		$n = (int) $n > 0 ? (int) $n : self::requested( $post );
		return self::url( (string) get_permalink( $post->ID ), $n );
	}
}
