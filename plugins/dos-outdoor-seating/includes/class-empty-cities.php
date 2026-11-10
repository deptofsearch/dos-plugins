<?php
/**
 * City landing pages with no venue to show: noindex, out of the sitemap, no tile on state pages, not counted on the homepage.
 *
 * Robots decides live from the page. The sitemap, state tiles and homepage counts read a cached set.
 *
 * "Empty" means what the page itself shows: no card is printed. That is a city term with no published venue, or one whose
 * published venues are all permanently closed (Util::city_has_venues() skips those). The page's own text is not changed.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Empty_Cities {

	const TRANSIENT = 'osn_empty_cities_v1';
	const FLAG      = '_osn_empty'; // Page meta: '1' when the page's last view printed no listing.

	/** @var array|null Request memo of the cached set. */
	private static $memo = null;

	/** @var callable|null Replaces closed_counts() in tests. */
	public static $closed = null;

	public static function hooks() {
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
		// A first venue arriving or the last one leaving does not always pass through City_Index::flush().
		add_action( 'osn_city_venues_changed', array( __CLASS__, 'on_city_venues_changed' ), 10, 2 );
	}

	public static function flush_memo() {
		self::$memo   = null;
		self::$prints = array();
	}

	/** Called from City_Index::flush(), so the set follows the city index's invalidation. */
	public static function flush() {
		delete_transient( self::TRANSIENT );
		self::$memo = null;
	}

	/** @return int[] Landing page IDs of cities with nothing to show. */
	public static function ids() {
		return self::get()['ids'];
	}

	/** @return array<string,true> Landing paths in State_Cities::local_key() format ("phoenix-az"); use with isset(). */
	public static function paths() {
		return self::get()['paths'];
	}

	public static function is_empty_page( $post_id ) {
		return in_array( (int) $post_id, self::ids(), true );
	}

	/** @return array { ids: int[], paths: array<string,true>, terms: int[] } terms: the empty city terms that have a landing page. */
	private static function get() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$hit = get_transient( self::TRANSIENT );
		if ( ! is_array( $hit ) || ! isset( $hit['ids'], $hit['paths'], $hit['terms'] ) ) {
			$hit = self::build();
			// Built where no [table] shortcode could be rendered: pages were assumed not empty, so try again soon.
			set_transient( self::TRANSIENT, $hit, shortcode_exists( 'table' ) ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		}
		self::$memo = $hit;
		return $hit;
	}

	/**
	 * Build the set from what pages show, not from term meta alone. A landing ID in term meta can point at a state
	 * page, the front page or About, or at a page whose [table filter] names a different term. A candidate page
	 * is kept only when it is a published page whose own filter names a "City, ST" whose term is empty
	 * (or names no term at all, which prints no cards either).
	 *
	 * Cost of a rebuild, inside the request that needs it: one term query, one grouped closed-venues query and one
	 * post prime.
	 */
	private static function build() {
		$out   = array( 'ids' => array(), 'paths' => array(), 'terms' => array() );
		$terms = get_terms(
			array(
				'taxonomy'   => Data_Model::CITY,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return $out;
		}
		$closed  = (array) ( self::$closed ? call_user_func( self::$closed ) : self::closed_counts() );
		$by_slug = array();
		$by_name = array();
		$empty   = array(); // term_id => true: no card is printed for this city.
		foreach ( $terms as $term ) {
			$by_slug[ $term->slug ] = $term;
			$by_name[ strtolower( $term->name ) ] = $term; // get_term_by( 'name' ) matches without regard to case.
			// term->count is the published venues; closed ones are published but print no card.
			$empty[ (int) $term->term_id ] = ( (int) $term->count - (int) ( $closed[ $term->term_id ] ?? 0 ) ) <= 0;
		}
		$cand = array();
		foreach ( $terms as $term ) {
			$landing = (int) get_term_meta( $term->term_id, 'osn_landing_page_id', true );
			if ( $landing > 0 && $empty[ (int) $term->term_id ] ) {
				$cand[ $landing ]   = true;
				$out['terms'][]     = (int) $term->term_id;
			}
		}
		$cand = array_keys( $cand );
		sort( $cand );
		if ( $cand ) {
			_prime_post_caches( $cand, false, false );
		}
		foreach ( $cand as $id ) {
			$post   = get_post( $id );
			$filter = ( $post && 'page' === $post->post_type && 'publish' === $post->post_status ) ? self::city_filter( $post ) : '';
			if ( '' === $filter ) {
				continue;
			}
			// Mirror Util::find_city(): slug first, then name.
			$hit = $by_slug[ sanitize_title( $filter ) ] ?? ( $by_name[ strtolower( $filter ) ] ?? null );
			if ( ! $hit ) {
				$hit = Util::find_city( $filter ); // The maps are not core's exact name match (collation, "&"); rare, one query.
			}
			if ( self::shows_other_listings( $post, $filter ) ) {
				continue;
			}
			if ( $hit && ! $empty[ (int) $hit->term_id ] ) {
				continue; // The page shows another city that has venues.
			}
			$key = State_Cities::local_key( (string) get_permalink( $id ) );
			if ( null !== $key ) {
				$out['paths'][ $key ] = true;
			}
			$out['ids'][] = $id;
		}
		return $out;
	}

	/**
	 * The "City, ST" filter of a page, else ''. Whether the takeover is enabled for the city does not matter: a
	 * city it is not enabled for is left to TablePress, and an empty TablePress table is as empty as no cards.
	 * (0.6.15 required the city to be enabled, and on the live site none of the 79 empty pages that still print a
	 * TablePress table were treated as empty.)
	 */
	private static function city_filter( $post ) {
		$filter = Page_Title::filter_from_content( (string) $post->post_content );
		$parsed = '' !== $filter ? Util::parse_city( $filter ) : null;
		return ( $parsed && States::has( $parsed[1] ) ) ? $filter : ''; // Not: no filter, a neighborhood ("Ballard, Seattle"), a state page, the front page, About.
	}

	/**
	 * True when this page is a city page that prints no cards, decided from the page itself and live: nothing here
	 * reads or writes the cached set, so a stale set can never noindex a page that has venues.
	 */
	public static function page_shows_nothing( \WP_Post $post ) {
		if ( 'page' !== $post->post_type ) {
			return false;
		}
		$filter = self::city_filter( $post );
		// The resolver first: it is already computed for a city with venues, and then nothing is rendered here.
		return '' !== $filter && null === Takeover::context( $post ) && ! self::shows_other_listings( $post, $filter );
	}

	/**
	 * True when the page prints, or may print, a listing, in which case it is never treated as empty:
	 *  - a second [table] or an [osn_venues] in the content (only the first [table] is looked at);
	 *  - the first [table] shortcode, rendered, prints a card or a table row. Rendering it is the only test that
	 *    is right for every case: cards from the takeover, rows from TablePress for a city the takeover is not
	 *    enabled for or has no venue for (Columbia City, OR printed one such row, 2026-11), hidden TablePress
	 *    rows, a missing table. Reading TablePress's stored JSON for the filter text, as 0.6.15 did, is not: a
	 *    match there does not mean a row is printed.
	 *
	 * Cost: TablePress caches its rendered output, so this is normally one cached read. It runs only for pages the
	 * takeover shows nothing on. Rendering a table here makes TablePress number the page's own copy "-no-2".
	 */
	private static function shows_other_listings( $post, $filter ) {
		$id = (int) $post->ID;
		if ( isset( self::$prints[ $id ] ) ) {
			return self::$prints[ $id ];
		}
		$content = (string) $post->post_content;
		if ( preg_match_all( '/\[table\b/i', $content ) > 1 || false !== stripos( $content, '[osn_venues' ) ) {
			return self::$prints[ $id ] = true;
		}
		if ( ! shortcode_exists( 'table' ) ) {
			return self::$prints[ $id ] = true; // Nothing can render it in this request (TablePress loads its shortcode on the front end only): do not guess.
		}
		$html = preg_match( '/\[table\b[^\]]*\]/i', $content, $m ) ? (string) do_shortcode( $m[0] ) : '';
		return self::$prints[ $id ] = ( false !== strpos( $html, 'osn-card' ) || 1 === preg_match( '/<td[\s>]/i', $html ) );
	}

	/** @var array<int,bool> Request memo for shows_other_listings(): page ID => prints a listing. */
	private static $prints = array();

	/**
	 * Published venues per city term that are permanently closed. One query; the result is small.
	 *
	 * @return array<int,int> term_id => closed venues. On a query error, empty: only the term count is used then.
	 */
	private static function closed_counts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id AS term_id, COUNT(DISTINCT p.ID) AS n
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = 'permanently_closed'
				WHERE p.post_type = %s AND p.post_status = 'publish'
				GROUP BY tt.term_id",
				Data_Model::CITY,
				Fields::key( 'business_status' ),
				Data_Model::POST_TYPE
			)
		);
		$out = array();
		if ( '' !== (string) $wpdb->last_error ) {
			return $out;
		}
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->term_id ] = (int) $r->n;
		}
		return $out;
	}

	/** @param int $term_id City term ID. @param int $landing Its landing page ID (unused: read from term meta). */
	public static function on_city_venues_changed( $term_id, $landing = 0 ) {
		self::check_term( $term_id );
	}

	/**
	 * A city's venues changed through a path that may not reach City_Index::flush() (venue batches, wp-admin
	 * publish, trash or delete): flush when this city crossed between empty and not empty.
	 *
	 * Cheap on purpose, because Takeover::on_option_change purges every city. A city the set says is not empty
	 * and whose term count is above zero returns with no query; a city that went from not empty to only
	 * permanently closed venues is therefore caught at the next rebuild (12 hours at most), not here. The term
	 * count may lag a post status change by a moment; the same rebuild catches that.
	 *
	 * @param int $term_id City term ID.
	 */
	public static function check_term( $term_id ) {
		$term_id = (int) $term_id;
		$hit     = get_transient( self::TRANSIENT );
		if ( ! is_array( $hit ) || ! isset( $hit['terms'] ) || (int) get_term_meta( $term_id, 'osn_landing_page_id', true ) < 1 ) {
			return; // Nothing cached to be wrong, or the city has no landing page to hide.
		}
		$term = get_term( $term_id, Data_Model::CITY );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		if ( in_array( $term_id, array_map( 'intval', $hit['terms'] ), true ) ) {
			$cross = Util::city_has_venues( $term ); // Few cities are empty: one query each is fine.
		} else {
			$cross = 0 === (int) $term->count;
		}
		if ( $cross ) {
			self::flush();
			Home::flush(); // The homepage's per-state city counts skip empty cities.
		}
	}

	/** An empty city page is noindex,follow: Google reports an empty 200 page as a soft 404. */
	public static function robots( $robots ) {
		if ( is_page() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$empty = self::page_shows_nothing( $post );
				self::sync_flag( $post->ID, $empty );
				if ( $empty ) {
					unset( $robots['index'] );
					$robots['noindex'] = true;
					$robots['follow']  = true;
				}
			}
		}
		return $robots;
	}

	/**
	 * Remember on the page itself what its last view decided, so the sitemap can leave it out.
	 *
	 * The cached set only knows landing pages of city terms. On the live site 78 empty city pages (the TablePress
	 * era states) have no city term at all, because a term is made when a city gets its first venue: they were
	 * noindex and still in the sitemap. The view is the one place that knows what every page prints, so it sets
	 * this flag when a page turns out empty and removes it when it no longer is. A page is flagged at its first
	 * uncached view, which for a page in the sitemap is at the latest the next crawl. Writes only on a change.
	 */
	private static function sync_flag( $post_id, $empty ) {
		$has = '1' === (string) get_post_meta( $post_id, self::FLAG, true );
		if ( $empty && ! $has ) {
			update_post_meta( $post_id, self::FLAG, '1' );
		} elseif ( ! $empty && $has ) {
			delete_post_meta( $post_id, self::FLAG );
		}
	}

	/** @var callable|null Replaces the query in flagged_ids() in tests. */
	public static $flagged = null;

	/** @return int[] Pages whose last view found them empty. One indexed query; called for sitemap requests only. */
	public static function flagged_ids() {
		if ( self::$flagged ) {
			return array_map( 'intval', (array) call_user_func( self::$flagged ) );
		}
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::FLAG ) ) );
	}
}
