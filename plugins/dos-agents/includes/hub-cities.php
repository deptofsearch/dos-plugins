<?php
/**
 * State hubs: city pages the hub's "All Cities in {State}" table does not link to are added to it.
 *
 * Each hub's stored content holds a table of every city in the state, written when the hub was made. A city page
 * published later, or renamed, is not in it: the 343 `__trashed` pages renamed on 2026-11-10 (298 AL, 26 MA, 14 NH,
 * 5 RI) were linked from no hub at all, so only the sitemap and the old-slug redirects reached them. Like the rest
 * of hub.php, nothing is written into the pages: the table is rebuilt in `the_content`, only when a published city
 * page of the state is missing from it, and a hub with nothing missing is printed exactly as stored.
 *
 * The rebuilt table keeps the stored one's markup (its own <table>, <td> and <a> tags, copied from the first cell)
 * and its order: alphabetical, down three columns. The "All Cities in {State} (N)" count follows. Empty city pages
 * (includes/empty-cities.php) are not added, since they are noindex; the links the table already has are kept.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_AGENTS_HUB_CITIES_TRANSIENT', 'breanm_agents_hub_cities_v1_' );

function dos_agents_hub_cities_enabled() {
	return (bool) apply_filters( 'dos_agents_hub_add_missing', true );
}

/** The city name a page title gives ("in Mc Kee KY" or "in Boise, ID" -> "Mc Kee"), or '' when the title is not in that form. */
function dos_agents_hub_city_name( $title, $state ) {
	$title = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	if ( preg_match( '/^in (.+?),? ([A-Za-z]{2})$/iu', $title, $m ) && strtoupper( $m[2] ) === $state && '' !== trim( $m[1] ) ) {
		return trim( $m[1] );
	}
	return '';
}

/**
 * The published, top-level city pages of a state: array of array( id, slug, city ), cached for 12 hours.
 *
 * A page counts when its slug is "in-<city>-<st>" and its title is "in {City} {ST}", which is how every city page
 * is named; the title check keeps out a page whose slug merely ends in the state code. Cleared when a page of that
 * state is saved, renamed or deleted (dos_agents_hub_cities_on_save()).
 */
function dos_agents_hub_city_pages( $state ) {
	$state = strtoupper( (string) $state ); // No memo: a hub calls this once per view, and the transient is the cache.
	$pre = apply_filters( 'dos_agents_pre_hub_city_pages', null, $state );
	if ( is_array( $pre ) ) {
		return $pre;
	}
	$key = DOS_AGENTS_HUB_CITIES_TRANSIENT . strtolower( $state );
	$hit = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_name, post_title FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_parent = 0 AND post_name LIKE %s",
			'in-%-' . $wpdb->esc_like( strtolower( $state ) )
		)
	);
	if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
		return array(); // Add nothing rather than cache a failed scan.
	}
	$pages = array();
	foreach ( $rows as $row ) {
		$slug = (string) $row->post_name;
		if ( ! preg_match( '/^in-[a-z0-9-]+-' . preg_quote( strtolower( $state ), '/' ) . '$/', $slug ) ) {
			continue;
		}
		$city = dos_agents_hub_city_name( $row->post_title, $state );
		if ( '' !== $city ) {
			$pages[] = array( (int) $row->ID, $slug, $city );
		}
	}
	set_transient( $key, $pages, 12 * HOUR_IN_SECONDS );
	return $pages;
}

/** The state code a city page slug ends in ("in-boise-id" -> "ID"), or ''. */
function dos_agents_hub_slug_state( $slug ) {
	if ( preg_match( '/^in-[a-z0-9-]+-([a-z]{2})$/', (string) $slug, $m ) && isset( dos_agents_state_names()[ strtoupper( $m[1] ) ] ) ) {
		return strtoupper( $m[1] );
	}
	return '';
}

/** save_post and deleted_post: a city page changed, so its state's list is read again next time. */
function dos_agents_hub_cities_on_save( $post_id, $post = null ) {
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return;
	}
	$state = dos_agents_hub_slug_state( $post->post_name );
	if ( '' !== $state ) {
		delete_transient( DOS_AGENTS_HUB_CITIES_TRANSIENT . strtolower( $state ) );
	}
}

/** post_updated: a renamed page leaves its old state's list too. */
function dos_agents_hub_cities_on_update( $post_id, $after, $before ) {
	dos_agents_hub_cities_on_save( $post_id, $before );
	dos_agents_hub_cities_on_save( $post_id, $after );
}

/**
 * Adds the state's missing city pages to the hub's city table. Returns $content unchanged when the table is not
 * found, cannot be read, or already links every city page.
 */
function dos_agents_hub_merge_cities( $content, $state ) {
	if ( ! dos_agents_hub_cities_enabled() ) {
		return $content;
	}
	// Found with strpos, not one regex over the page: the largest hubs hold over 500 KB, where a lazy match up to
	// </table> nears PCRE's backtrack limit. The class name also appears in the hub's own <style> block, so the
	// search is for a <table> tag that carries it.
	$content = (string) $content;
	$start   = false;
	for ( $at = stripos( $content, '<table' ); false !== $at; $at = stripos( $content, '<table', $at + 6 ) ) {
		$close = strpos( $content, '>', $at );
		if ( false !== $close && preg_match( '/\bbreanm-city-table\b/i', substr( $content, $at, $close - $at ) ) ) {
			$start = $at;
			break;
		}
	}
	$end = false === $start ? false : stripos( $content, '</table>', $start );
	if ( false === $end ) {
		return $content;
	}
	$table = substr( $content, $start, $end + 8 - $start );
	if ( ! preg_match( '/^<table\b[^>]*>/i', $table, $open ) || ! preg_match( '/<td\b[^>]*>/i', $table, $td ) || ! preg_match( '/<a\b[^>]*\bhref=(["\'])[^"\']*\1[^>]*>/i', $table, $a ) ) {
		return $content;
	}
	if ( ! preg_match_all( '/<a\b[^>]*\bhref=(["\'])([^"\']*)\1[^>]*>(.*?)<\/a>/is', $table, $links, PREG_SET_ORDER ) ) {
		return $content;
	}

	$cells  = array();
	$linked = array();
	foreach ( $links as $l ) {
		// A stored href is printed back as stored: it is already escaped markup, and esc_url() would escape it again.
		$cells[] = array( $l[2], trim( html_entity_decode( wp_strip_all_tags( $l[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), true );
		if ( preg_match( '#(?:^|/)(in-[a-z0-9-]+-[a-z]{2})/?(?:[?\#].*)?$#i', $l[2], $m ) ) {
			$linked[ strtolower( $m[1] ) ] = true;
		}
	}
	$missing = array();
	foreach ( dos_agents_hub_city_pages( $state ) as list( $id, $slug, $city ) ) {
		if ( ! isset( $linked[ $slug ] ) ) {
			$missing[ $slug ] = array( $id, $city );
		}
	}
	if ( ! $missing ) {
		return $content; // Most hubs: nothing missing, and the empty-page scan below is never needed.
	}
	$empty = array_flip( dos_agents_empty_city_ids() );
	$added = 0;
	foreach ( $missing as $slug => list( $id, $city ) ) {
		if ( ! isset( $empty[ $id ] ) ) {
			$cells[] = array( home_url( '/' . $slug . '/' ), $city, false );
			$added++;
		}
	}
	if ( 0 === $added ) {
		return $content;
	}

	usort(
		$cells,
		static function ( $x, $y ) {
			return array( strtolower( $x[1] ), $x[0] ) <=> array( strtolower( $y[1] ), $y[0] );
		}
	);

	// Down three columns, as stored: column k holds cells k*rows ... k*rows+rows-1, and a short last column leaves
	// the bottom rows with two cells.
	$n    = count( $cells );
	$cols = 3;
	$rows = (int) ceil( $n / $cols );
	$html = $open[0] . "\n<tbody>\n";
	for ( $r = 0; $r < $rows; $r++ ) {
		$html .= "<tr>\n";
		for ( $k = 0; $k < $cols; $k++ ) {
			$i = $k * $rows + $r;
			if ( $i >= $n ) {
				continue;
			}
			$href  = $cells[ $i ][2] ? $cells[ $i ][0] : esc_url( $cells[ $i ][0] );
			$tag   = preg_replace_callback(
				'/\bhref=(["\'])[^"\']*\1/i',
				static function () use ( $href ) {
					return 'href="' . $href . '"';
				},
				$a[0],
				1
			);
			$html .= $td[0] . $tag . esc_html( $cells[ $i ][1] ) . "</a></td>\n";
		}
		$html .= "</tr>\n";
	}
	$html .= "</tbody>\n</table>";

	$content = substr_replace( $content, $html, $start, strlen( $table ) );
	// The heading above the table counts the links; it follows the table. Only the first count is touched.
	$name    = preg_quote( dos_agents_state_name( $state ), '/' );
	// No /u: state names are ASCII, and with /u one invalid UTF-8 byte anywhere in the page makes preg_replace()
	// return null. Null keeps the merged table with the old count rather than blanking the hub.
	$counted = preg_replace( '/(All Cities in ' . $name . '\s*\()[\d,]+(\))/', '${1}' . $n . '${2}', $content, 1 );
	return null === $counted ? $content : $counted;
}
