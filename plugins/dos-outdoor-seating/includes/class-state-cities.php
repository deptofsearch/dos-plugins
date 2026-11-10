<?php
/**
 * State landing pages: [osn_state_cities state="AZ"] and the opt-in takeover of a state page's HTML city tables.
 *
 * The takeover (setting osn_state_takeover) runs on the_content at priority 6: after Page_Title (5), before wpautop and
 * shortcodes (10, 11). It swaps the page's <table>s for the shortcode, so the markup it prints is never touched by wpautop.
 * The page itself is never edited.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class State_Cities {

	const OPTION     = 'osn_state_takeover';
	const FLUSH      = 'osn_state_flush_';   // + state code, or "all": when that state's data was last marked out of date.
	const TRANSIENT  = 'osn_state_';
	const CRON       = 'osn_rebuild_state';
	const TTL        = WEEK_IN_SECONDS;      // Long, so there is always a copy to serve while a rebuild runs.
	const REFRESH    = 12 * HOUR_IN_SECONDS; // Rebuild at this age even when nothing was flushed (the old lifetime).
	const MIN_AGE    = 900;                  // While venues keep changing, rebuild a state at most every 15 minutes.
	const OVERDUE    = 600;                  // A rebuild job this late means cron is not running: do it in the request.
	const MIN_LINKS  = 5;
	const POPULAR    = 8;

	public static function hooks() {
		add_shortcode( 'osn_state_cities', array( __CLASS__, 'shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'takeover' ), 6 );
		add_action( 'osn_city_venues_changed', array( __CLASS__, 'flush' ) ); // Passes the city term ID: that city's state only.
		add_action( self::CRON, array( __CLASS__, 'cron_rebuild' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'on_option_change' ), 10, 2 );
		add_action(
			'add_option_' . self::OPTION,
			function ( $option, $value ) {
				self::on_option_change( array(), $value );
			},
			10,
			2
		);
	}

	/* ---------------------------------------------------------------- settings */

	/** @return string[] State codes whose page the plugin takes over. */
	public static function enabled() {
		return self::clean_codes( get_option( self::OPTION, array() ) );
	}

	/** @param mixed $value Raw option value. @return string[] */
	public static function clean_codes( $value ) {
		$out = array();
		foreach ( (array) $value as $c ) {
			$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $c ) );
			if ( States::has( $c ) ) {
				$out[ $c ] = $c;
			}
		}
		return array_values( $out );
	}

	/** A state flipped on or off: drop the page's post cache and tell page caches. */
	public static function on_option_change( $old, $new ) {
		$o = self::clean_codes( $old );
		$n = self::clean_codes( $new );
		foreach ( array_merge( array_diff( $o, $n ), array_diff( $n, $o ) ) as $code ) {
			$page = self::page_for( $code );
			if ( $page ) {
				clean_post_cache( $page->ID );
				do_action( 'osn_state_page_changed', $code, $page->ID );
			}
		}
	}

	/** Slug => state name map. Filter: osn_state_pages (shared with Page_Title). */
	private static function pages() {
		return States::pages();
	}

	/** State code for a state landing page, '' otherwise. */
	public static function code_for_post( $post ) {
		if ( ! $post || 'page' !== $post->post_type ) {
			return '';
		}
		$map = self::pages();
		if ( ! isset( $map[ $post->post_name ] ) ) {
			return '';
		}
		$code = array_search( $map[ $post->post_name ], States::names(), true );
		return false === $code ? '' : (string) $code;
	}

	/** The state's landing page (any status), or null. */
	public static function page_for( $code ) {
		$name = States::name( $code );
		foreach ( self::pages() as $slug => $label ) {
			if ( $label === $name ) {
				$p = get_page_by_path( $slug );
				return $p ? $p : null;
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------- parsing */

	/** Path of a link on this site, lowercased with no slashes ("phoenix-az"), or null when the link is elsewhere. */
	public static function local_key( $href ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|javascript):/i', $href ) ) {
			return null;
		}
		$home      = wp_parse_url( home_url() );
		$host      = preg_replace( '/^www\./i', '', strtolower( $home['host'] ?? '' ) );
		$home_path = trim( (string) ( $home['path'] ?? '' ), '/' );
		$p         = wp_parse_url( $href );
		if ( ! $p ) {
			return null;
		}
		if ( isset( $p['host'] ) && preg_replace( '/^www\./i', '', strtolower( $p['host'] ) ) !== $host ) {
			return null;
		}
		$path = strtolower( trim( rawurldecode( (string) ( $p['path'] ?? '' ) ), '/' ) );
		if ( '' !== $home_path && 0 === strpos( $path . '/', $home_path . '/' ) ) {
			$path = trim( substr( $path, strlen( $home_path ) ), '/' );
		}
		return '' === $path ? null : $path;
	}

	/** True for a path like "phoenix-az" whose trailing state equals $code. */
	public static function is_city_key( $key, $code ) {
		return (bool) preg_match( '/^[a-z0-9-]+-([a-z]{2})$/', $key, $m ) && strtoupper( $m[1] ) === strtoupper( $code );
	}

	/**
	 * City links inside the content's <table> elements, same-site only, deduped by URL, in page order.
	 *
	 * Only city links count: the slug must look like "phoenix-az" and end in the page's own state code.
	 *
	 * @return array[] Each { name, key, url }.
	 */
	public static function parse_links( $content, $code ) {
		$out = array();
		if ( ! preg_match_all( '~<table\b.*?</table>~is', (string) $content, $tables ) ) {
			return $out;
		}
		foreach ( $tables[0] as $table ) {
			if ( ! preg_match_all( '~<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', $table, $links, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $links as $m ) {
				$key  = self::local_key( $m[2] );
				$name = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES, 'UTF-8' ) ) );
				$name = trim( preg_replace( '/\s*,\s*[A-Za-z]{2}$/', '', $name ) );
				if ( null === $key || '' === $name || isset( $out[ $key ] ) || ! self::is_city_key( $key, $code ) ) {
					continue;
				}
				$out[ $key ] = array(
					'name' => $name,
					'key'  => $key,
					'url'  => user_trailingslashit( home_url( $key ) ),
				);
			}
		}
		return array_values( $out );
	}

	/** The content with the tables swapped for the shortcode and the "Here is X so far:" and "Welcome!" lines dropped. */
	public static function rewrite( $content, $code ) {
		$content = preg_replace_callback(
			'~<(p|h[1-6])\b[^>]*>(.*?)</\1>~is',
			function ( $m ) {
				$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES, 'UTF-8' ) ) );
				$text = trim( str_replace( "\xC2\xA0", ' ', $text ) );
				if ( preg_match( '/^Here is .{1,40} so far:?$/iu', $text ) ) {
					return '';
				}
				// A lone "Welcome!" paragraph (not headings, not "Welcome to ...").
				return ( 'p' === strtolower( $m[1] ) && preg_match( '/^Welcome[\p{P}\s]*$/iu', $text ) ) ? '' : $m[0];
			},
			$content
		);
		$first   = true;
		$content = preg_replace_callback(
			'~<table\b.*?</table>~is',
			function ( $m ) use ( &$first, $code ) {
				if ( ! self::parse_links( $m[0], $code ) ) {
					return $m[0]; // Not a city table: keep it.
				}
				if ( ! $first ) {
					return '';
				}
				$first = false;
				return "\n\n" . '[osn_state_cities state="' . $code . '"]' . "\n\n";
			},
			$content
		);
		return $content;
	}

	public static function takeover( $content ) {
		if ( ! is_page() || ! in_the_loop() || ! is_main_query() || doing_filter( 'get_the_excerpt' ) ) {
			return $content;
		}
		$post = get_post();
		$code = self::code_for_post( $post );
		if ( '' === $code || ! in_array( $code, self::enabled(), true ) ) {
			return $content;
		}
		if ( count( self::parse_links( $content, $code ) ) < self::MIN_LINKS ) {
			return $content; // Safety: not the page we think it is.
		}
		return self::rewrite( $content, $code );
	}

	/* ---------------------------------------------------------------- data */

	/**
	 * Mark cached state data out of date (cheap: at most one option write per state per second).
	 *
	 * Until 0.6.13 any venue change anywhere invalidated every state, and the next visitor to each state page
	 * (and to the homepage, whose carousel reads several states) waited for two heavy queries per state. The
	 * enrichment pipeline changes venues for 30 to 100 minutes every two hours, so hub pages were slow for much
	 * of the day. Now a venue change marks its own state only, and data() serves the copy it has while a
	 * background job rebuilds it.
	 *
	 * Each state's flush time is its own option, so writing one never rewrites another. One shared map would be read at the start of a
	 * request and written back whole, so two overlapping requests (an upsert batch and a photo batch) would undo
	 * each other's flushes and leave a state out of date until the 12-hour refresh.
	 *
	 * @param int|string $what A city term ID or a state code: that state only. Empty, or a term whose state is
	 *                         not known: every state (a city or landing page was added, removed or relinked).
	 */
	public static function flush( $what = '' ) {
		$code = '*';
		if ( is_string( $what ) && States::has( strtoupper( $what ) ) ) {
			$code = strtoupper( $what );
		} elseif ( is_numeric( $what ) && (int) $what > 0 ) {
			$st   = self::state_of_term( (int) $what );
			$code = '' !== $st ? $st : '*';
		}
		$now = time();
		// The memo holds the second written, not a flag: a rebuild can land between two flushes of one long request,
		// and the second flush must then still count.
		if ( ( self::$flushed[ $code ] ?? 0 ) === $now || ( self::$flushed['*'] ?? 0 ) === $now ) {
			return;
		}
		self::$flushed[ $code ] = $now;
		update_option( self::flush_key( $code ), $now, false );
	}

	/** @var array<string,int> When each state (or '*') was last flushed by this request. */
	private static $flushed = array();

	public static function flush_memo() {
		self::$flushed = array();
	}

	/** @var callable|null Replaces build() in tests. */
	public static $builder = null;

	/** Option holding a state's last flush time ('*': every state). Not autoloaded: each is its own cache key. */
	private static function flush_key( $code ) {
		return self::FLUSH . ( '*' === $code ? 'all' : $code );
	}

	/** Two-letter state of a city term ("" when unknown): the osn_state term meta, else the ", ST" in its name. */
	private static function state_of_term( $term_id ) {
		$st = strtoupper( (string) get_term_meta( $term_id, 'osn_state', true ) );
		if ( '' === $st ) {
			$t  = get_term( $term_id, Data_Model::CITY );
			$p  = ( $t && ! is_wp_error( $t ) ) ? Util::parse_city( $t->name ) : null;
			$st = $p ? strtoupper( (string) $p[1] ) : '';
		}
		return States::has( $st ) ? $st : '';
	}

	/**
	 * Per-city venue data for a state, keyed by landing page path ("phoenix-az").
	 * Cached in a transient per state; one aggregated pair of queries per rebuild.
	 *
	 * A copy that is out of date is still returned: a visitor never waits for the rebuild. The rebuild is
	 * scheduled as a cron job, no sooner than MIN_AGE after the last one, so a long run of venue changes costs
	 * one rebuild per state every 15 minutes at most. Counts and photos on state pages and the homepage
	 * carousel can therefore be 15 minutes behind, plus however late cron runs and however long the page cache
	 * keeps the page. Three cases build in the request: there is no copy at all (the cache was evicted, or a
	 * state is read for the first time), the job could not be scheduled, or the scheduled job is OVERDUE, which
	 * means cron is not running; a lock keeps the last two to one request.
	 *
	 * @return array<string,array> { term_id, count, top: [ [label, n], ... ], photo: attachment id }
	 */
	public static function data( $code ) {
		$hit = get_transient( self::TRANSIENT . $code );
		if ( ! is_array( $hit ) || ! isset( $hit['d'] ) || ! is_array( $hit['d'] ) ) {
			return self::rebuild( $code );
		}
		$now     = time();
		$built   = (int) ( $hit['b'] ?? 0 ); // Copies cached before 0.6.13 have no build time and count as out of date.
		$flushed = max( (int) get_option( self::flush_key( '*' ), 0 ), (int) get_option( self::flush_key( $code ), 0 ) );
		// Strictly newer: a build and a flush in the same second cannot be told apart, so the copy is rebuilt.
		if ( $built > $flushed && ( $now - $built ) < self::REFRESH ) {
			return $hit['d'];
		}
		$args  = array( $code );
		$next  = wp_next_scheduled( self::CRON, $args );
		$tried = max( $built, (int) ( $hit['t'] ?? 0 ) );
		// Cron normally does the rebuild. When it cannot, the request does, but no more often than cron would have:
		// without the MIN_AGE test a site with broken cron and no shared object cache (so no lock) would rebuild on
		// every view during an import.
		$due = ( $now - $tried ) >= self::MIN_AGE;
		if ( ! $next ) {
			if ( false !== wp_schedule_single_event( max( $now, $tried + self::MIN_AGE ), self::CRON, $args ) || ! $due || ! self::lock( $code ) ) {
				return $hit['d'];
			}
			return self::rebuild( $code, $hit );
		}
		if ( ( $now - $next ) < self::OVERDUE ) {
			return $hit['d'];
		}
		if ( ! $due ) {
			wp_clear_scheduled_hook( self::CRON, $args ); // A job left over from before the last build: the next read schedules a new one.
			return $hit['d'];
		}
		if ( ! self::lock( $code ) ) {
			return $hit['d'];
		}
		wp_clear_scheduled_hook( self::CRON, $args );
		return self::rebuild( $code, $hit );
	}

	/**
	 * Build a state's data now and store it.
	 *
	 * The build time is taken before the queries run: a venue saved while they run is not in the result, and
	 * its flush must be newer than the copy. When a query fails (build() returns null) the copy we already have
	 * is kept, or an empty one stored with no build time, marked with the time of the attempt so the retry waits
	 * MIN_AGE like any rebuild; before 0.6.13 a failed build was cached as an empty state for 12 hours.
	 *
	 * @param array|null $old The cached copy being replaced, if any.
	 */
	private static function rebuild( $code, $old = null ) {
		$started = time();
		$d       = self::$builder ? call_user_func( self::$builder, $code ) : self::build( $code );
		if ( ! is_array( $d ) ) {
			$keep = ( is_array( $old ) && isset( $old['d'] ) && is_array( $old['d'] ) ) ? $old : array( 'b' => 0, 'd' => array() );
			set_transient( self::TRANSIENT . $code, array( 'b' => (int) ( $keep['b'] ?? 0 ), 't' => $started, 'd' => $keep['d'] ), self::TTL );
			return $keep['d'];
		}
		set_transient( self::TRANSIENT . $code, array( 'b' => $started, 'd' => $d ), self::TTL );
		return $d;
	}

	/**
	 * The cron job: rebuild one state in the background.
	 *
	 * WordPress removes the event before it runs this, so a visitor who arrives while the queries run sees no
	 * job and schedules another for now. Jobs are only ever scheduled MIN_AGE or more after the last attempt,
	 * so one that finds a younger copy is that duplicate and does nothing.
	 */
	public static function cron_rebuild( $code ) {
		$code = strtoupper( (string) $code );
		if ( ! States::has( $code ) ) {
			return;
		}
		$hit   = get_transient( self::TRANSIENT . $code );
		$hit   = ( is_array( $hit ) && isset( $hit['d'] ) && is_array( $hit['d'] ) ) ? $hit : null;
		$tried = $hit ? max( (int) ( $hit['b'] ?? 0 ), (int) ( $hit['t'] ?? 0 ) ) : 0;
		if ( ( time() - $tried ) >= self::MIN_AGE && self::lock( $code ) ) {
			self::rebuild( $code, $hit );
		}
	}

	/** One rebuild of a state at a time, across requests where there is a shared object cache (two minutes). */
	private static function lock( $code ) {
		return (bool) wp_cache_add( 'osn_state_lock_' . $code, 1, 'osn', 2 * MINUTE_IN_SECONDS );
	}

	private static function build( $code ) {
		global $wpdb;
		$terms = get_terms(
			array(
				'taxonomy'   => Data_Model::CITY,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}
		$mine = array();
		foreach ( $terms as $t ) {
			$st = strtoupper( (string) get_term_meta( $t->term_id, 'osn_state', true ) );
			if ( '' === $st ) {
				$p  = Util::parse_city( $t->name );
				$st = $p ? $p[1] : '';
			}
			if ( $st === $code ) {
				$mine[] = $t;
			}
		}
		Util::prime_landing_pages( $mine );
		$keys = array();
		foreach ( $mine as $t ) {
			$url = Util::city_url( $t );
			$key = '' !== $url ? self::local_key( $url ) : null;
			if ( null !== $key ) {
				$keys[ (int) $t->term_id ] = $key;
			}
		}
		if ( ! $keys ) {
			return array();
		}
		$in    = implode( ',', array_map( 'intval', array_keys( $keys ) ) );
		$rc    = Fields::key( 'rating_count' );
		$bs    = Fields::key( 'business_status' );
		$venue = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, tt.term_id AS term_id,
					MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS rc,
					MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS bs,
					MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END) AS th
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN (%s, %s, '_thumbnail_id')
				WHERE p.post_type = %s AND p.post_status = 'publish' AND tt.term_id IN ($in)
				GROUP BY p.ID, tt.term_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of ints.
				$rc,
				$bs,
				Data_Model::CITY,
				$rc,
				$bs,
				Data_Model::POST_TYPE
			)
		);
		$out = array();
		foreach ( (array) $venue as $r ) {
			if ( 'permanently_closed' === $r->bs ) {
				continue;
			}
			$tid = (int) $r->term_id;
			if ( ! isset( $out[ $tid ] ) ) {
				$out[ $tid ] = array( 'count' => 0, 'best' => -1, 'photo' => 0 );
			}
			++$out[ $tid ]['count'];
			if ( (int) $r->th > 0 && (int) $r->rc > $out[ $tid ]['best'] ) {
				$out[ $tid ]['best']  = (int) $r->rc;
				$out[ $tid ]['photo'] = (int) $r->th;
			}
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return null; // The venue query failed: not the same as a state with no venues.
		}
		if ( ! $out ) {
			return array();
		}

		$am = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ctt.term_id AS term_id, t.slug AS slug, t.name AS name, COUNT(DISTINCT p.ID) AS n
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} cr ON cr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} ctt ON ctt.term_taxonomy_id = cr.term_taxonomy_id AND ctt.taxonomy = %s
				INNER JOIN {$wpdb->term_relationships} ar ON ar.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} att ON att.term_taxonomy_id = ar.term_taxonomy_id AND att.taxonomy = %s
				INNER JOIN {$wpdb->terms} t ON t.term_id = att.term_id
				LEFT JOIN {$wpdb->postmeta} bs ON bs.post_id = p.ID AND bs.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = 'publish' AND ctt.term_id IN ($in)
					AND (bs.meta_value IS NULL OR bs.meta_value <> 'permanently_closed')
				GROUP BY ctt.term_id, t.slug, t.name", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of ints.
				Data_Model::CITY,
				Data_Model::AMENITY,
				$bs,
				Data_Model::POST_TYPE
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return null; // Without the amenity counts every city would lose its "top" line.
		}
		$by_city = array();
		foreach ( (array) $am as $r ) {
			if ( in_array( $r->slug, array( 'outdoor-seating', 'wheelchair-accessible' ), true ) ) {
				continue;
			}
			$by_city[ (int) $r->term_id ][ $r->slug ] = array( 'name' => Util::lower( html_entity_decode( $r->name, ENT_QUOTES, 'UTF-8' ) ), 'n' => (int) $r->n );
		}

		$result = array();
		foreach ( $out as $tid => $row ) {
			$top = array();
			if ( ! empty( $by_city[ $tid ] ) ) {
				foreach ( array_slice( Cards::sort_slugs( array_keys( $by_city[ $tid ] ) ), 0, 2 ) as $slug ) {
					$top[] = array( $by_city[ $tid ][ $slug ]['name'], $by_city[ $tid ][ $slug ]['n'] );
				}
			}
			$result[ $keys[ $tid ] ] = array(
				'term_id' => $tid,
				'count'   => $row['count'],
				'top'     => $top,
				'photo'   => $row['photo'],
			);
		}
		return $result;
	}

	/* ---------------------------------------------------------------- admin stats */

	/** @return array { page: WP_Post|null, links: int, cities: int } for Tools -> Outdoor Seating. */
	public static function admin_stats( $code ) {
		$page  = self::page_for( $code );
		$links = $page ? self::parse_links( $page->post_content, $code ) : array();
		$data  = self::data( $code );
		$with  = 0;
		foreach ( $links as $l ) {
			if ( isset( $data[ $l['key'] ] ) ) {
				++$with;
			}
		}
		return array(
			'page'   => $page,
			'links'  => count( $links ),
			'cities' => $with,
		);
	}

	/* ---------------------------------------------------------------- render */

	public static function shortcode( $atts ) {
		$a    = shortcode_atts( array( 'state' => '' ), $atts, 'osn_state_cities' );
		$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $a['state'] ) );
		$post = get_post();
		if ( '' === $code ) {
			$code = self::code_for_post( $post );
		}
		if ( ! States::has( $code ) ) {
			return '';
		}
		// Parse this page's own tables; used on any other page it falls back to every city that has a landing page.
		// Only a state's own page is parsed; anywhere else the list comes from the city index.
		$links = ( $post && self::code_for_post( $post ) === $code ) ? self::parse_links( $post->post_content, $code ) : array();
		if ( ! $links ) {
			foreach ( City_Index::get( $code ) as $c ) {
				$key = self::local_key( $c['u'] );
				if ( null !== $key ) {
					$links[] = array( 'name' => Util::city_label( $c['n'] ), 'key' => $key, 'url' => $c['u'] );
				}
			}
		}
		return $links ? self::render( $code, $links ) : '';
	}

	private static function letter( $name ) {
		$c = strtoupper( substr( remove_accents( ltrim( $name ) ), 0, 1 ) );
		return ( '' !== $c && ctype_alpha( $c ) ) ? $c : '#';
	}

	private static function patios( $n ) {
		/* translators: %s: number of venues */
		return sprintf( _n( '%s patio', '%s patios', $n, 'dos-outdoor-seating' ), number_format_i18n( $n ) );
	}

	public static function render( $code, array $links ) {
		static $n = 0;
		++$n;
		$state = States::name( $code );
		$data  = self::data( $code );
		usort(
			$links,
			function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		Assets::enqueue();

		$search = array();
		$groups = array();
		$pop    = array();
		foreach ( $links as $l ) {
			$d        = $data[ $l['key'] ] ?? null;
			$l['d']   = $d;
			$search[] = array(
				'n' => $l['name'] . ', ' . $code,
				'u' => City_Index::relative( $l['url'] ),
				's' => $code,
			);
			$groups[ self::letter( $l['name'] ) ][] = $l;
			if ( $d && $d['count'] > 0 ) {
				$pop[] = $l;
			}
		}
		usort(
			$pop,
			function ( $a, $b ) {
				return $b['d']['count'] <=> $a['d']['count'] ?: strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		$pop  = array_slice( $pop, 0, self::POPULAR );
		$tids = array();
		foreach ( $pop as $l ) {
			if ( $l['d']['photo'] ) {
				$tids[] = $l['d']['photo'];
			}
		}
		if ( $tids ) {
			_prime_post_caches( array_values( array_unique( $tids ) ), true, false );
		}
		ksort( $groups );
		if ( isset( $groups['#'] ) ) { // Non-letters go last.
			$h = $groups['#'];
			unset( $groups['#'] );
			$groups['#'] = $h;
		}
		$uid = 'osn-st-' . $n;

		ob_start();
		?>
<div class="osn osn-state" data-osn-state="<?php echo esc_attr( $code ); ?>">
		<?php
		echo City_Search::render( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
			array(
				/* translators: %s: state name */
				'heading'     => sprintf( __( 'Find a city in %s', 'dos-outdoor-seating' ), $state ),
				'intro'       => __( 'Start typing a city and pick it from the list.', 'dos-outdoor-seating' ),
				'placeholder' => __( 'Start typing a city', 'dos-outdoor-seating' ),
				'button'      => __( 'Search', 'dos-outdoor-seating' ),
			),
			$search,
			$code,
			true
		);
		?>
		<?php if ( $pop ) : ?>
<section class="osn-state__section osn-state__popular">
<h2><?php esc_html_e( 'Popular cities', 'dos-outdoor-seating' ); ?></h2>
<ul class="osn-citycards">
			<?php foreach ( $pop as $l ) : ?>
				<?php
				$d     = $l['d'];
				$img   = $d['photo'] ? wp_get_attachment_image(
					$d['photo'],
					'medium_large',
					false,
					array(
						'alt'      => '',
						'loading'  => 'lazy',
						'decoding' => 'async',
						'sizes'    => '(max-width: 600px) 100vw, 300px',
					)
				) : '';
				$bits  = array();
				foreach ( $d['top'] as $t ) {
					$bits[] = number_format_i18n( $t[1] ) . ' ' . $t[0];
				}
				?>
<li class="osn-citycard">
				<?php if ( '' !== $img ) : ?>
<div class="osn-citycard__media"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput -- core image markup. ?></div>
<?php else : ?>
<div class="osn-citycard__media osn-card__media--band" style="<?php echo esc_attr( Icons::neutral_style() ); ?>"><?php echo Icons::svg( 'utensils-crossed' ); // phpcs:ignore WordPress.Security.EscapeOutput -- trusted constants. ?></div>
<?php endif; ?>
<div class="osn-citycard__body">
<h3 class="osn-citycard__name"><a href="<?php echo esc_url( $l['url'] ); ?>"><?php echo esc_html( $l['name'] ); ?></a></h3>
<p class="osn-citycard__count"><?php echo esc_html( self::patios( $d['count'] ) ); ?></p>
				<?php if ( $bits ) : ?><p class="osn-citycard__amen"><?php echo esc_html( implode( ' · ', $bits ) ); ?></p><?php endif; ?>
</div>
</li>
			<?php endforeach; ?>
</ul>
</section>
		<?php endif; ?>
<section class="osn-state__section osn-state__all">
<h2>
		<?php
		/* translators: %s: state name */
		echo esc_html( sprintf( __( 'All %s cities', 'dos-outdoor-seating' ), $state ) );
		?>
</h2>
<nav class="osn-az" aria-label="<?php esc_attr_e( 'Jump to a letter', 'dos-outdoor-seating' ); ?>">
		<?php foreach ( array_keys( $groups ) as $L ) : ?>
<a href="#<?php echo esc_attr( $uid . '-' . ( '#' === $L ? 'other' : $L ) ); ?>"><?php echo esc_html( $L ); ?></a>
		<?php endforeach; ?>
</nav>
		<?php foreach ( $groups as $L => $rows ) : ?>
<div class="osn-az-group" id="<?php echo esc_attr( $uid . '-' . ( '#' === $L ? 'other' : $L ) ); ?>">
<h3 class="osn-az-letter"><?php echo esc_html( $L ); ?></h3>
<ul class="osn-tiles">
			<?php foreach ( $rows as $l ) : ?>
<li class="osn-tile"><a href="<?php echo esc_url( $l['url'] ); ?>"><span class="osn-tile__name"><?php echo esc_html( $l['name'] ); ?></span><span class="osn-tile__meta">
				<?php
				echo $l['d'] && $l['d']['count'] > 0
					? esc_html( self::patios( $l['d']['count'] ) )
					: esc_html__( 'View list →', 'dos-outdoor-seating' );
				?>
</span></a></li>
			<?php endforeach; ?>
</ul>
</div>
		<?php endforeach; ?>
</section>
		<?php
		if ( $pop ) {
			$items = array();
			foreach ( $pop as $i => $l ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $l['name'],
					'url'      => $l['url'],
				);
			}
			echo Util::json_ld( // phpcs:ignore WordPress.Security.EscapeOutput
				array(
					'@context'        => 'https://schema.org',
					'@type'           => 'ItemList',
					/* translators: %s: state name */
					'name'            => sprintf( 'Popular cities for outdoor seating in %s', $state ),
					'numberOfItems'   => count( $items ),
					'itemListElement' => $items,
				)
			);
		}
		?>
</div>
		<?php
		return ob_get_clean();
	}
}
