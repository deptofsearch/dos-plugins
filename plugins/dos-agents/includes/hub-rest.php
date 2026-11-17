<?php
/**
 * State hubs: the old all-cities table becomes a plain list of the cities that have no agent list.
 *
 * Each hub's stored content holds a 3-column table linking every city page in the state (Utah: 272), under an
 * "All Cities in {State} (N)" heading. The city boxes above it (includes/home.php) already link the cities that have
 * an agent list, so those cities were on the page twice. Ryan chose (2026-11-17) a plain list of only the rest over
 * boxes alone, so a city without an agent list (about 235 of Utah's 272, and the same in every state) keeps its one
 * link from the hub. Nothing is written into the pages: the table is swapped in `the_content`, and
 * `dos_agents_hub_rest_list` (false) puts the full table back exactly as stored.
 *
 * Runs after the missing-city merge (hub-cities.php), so a page published later is in the list too.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dos_agents_hub_rest_enabled() {
	return (bool) apply_filters( 'dos_agents_hub_rest_list', true );
}

/** The city page slug an href ends in ("https://x.com/in-boise-id/" -> "in-boise-id"), lower-cased, or ''. */
function dos_agents_hub_href_slug( $href ) {
	return preg_match( '#(?:^|/)(in-[a-z0-9-]+-[a-z]{2})/?(?:[?\#].*)?$#i', (string) $href, $m ) ? strtolower( $m[1] ) : '';
}

/**
 * Replaces the hub's city table with a list of the cities that have no agent list, and its heading with
 * "More cities in {State} ({n})". $remaining receives n, or null when the table was left alone, which is when
 * it is not found, has no links, or the filter is off. With no city left, the table and its heading are removed.
 */
function dos_agents_hub_rest_list( $content, $state, &$remaining = null ) {
	$remaining = null;
	$content   = (string) $content;
	if ( ! dos_agents_hub_rest_enabled() ) {
		return $content;
	}
	$found = dos_agents_hub_find_table( $content );
	if ( null === $found ) {
		return $content;
	}
	list( $start, $end ) = $found;
	$table = substr( $content, $start, $end - $start );
	if ( ! preg_match_all( '/<a\b[^>]*\bhref=(["\'])([^"\']*)\1[^>]*>(.*?)<\/a>/is', $table, $links, PREG_SET_ORDER ) ) {
		return $content;
	}

	$carded = array();
	foreach ( dos_agents_index_for_state( $state ) as $row ) {
		$slug = dos_agents_hub_href_slug( isset( $row['u'] ) ? $row['u'] : '' );
		if ( '' !== $slug ) {
			$carded[ $slug ] = true;
		}
	}
	$rest = array();
	foreach ( $links as $i => $l ) {
		if ( isset( $carded[ dos_agents_hub_href_slug( $l[2] ) ] ) ) {
			continue;
		}
		$rest[] = array( trim( html_entity_decode( wp_strip_all_tags( $l[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), $l[2], $i );
	}
	usort(
		$rest,
		static function ( $x, $y ) {
			return array( strtolower( $x[0] ), $x[2] ) <=> array( strtolower( $y[0] ), $y[2] );
		}
	);

	$n    = count( $rest );
	$html = '';
	if ( $n ) {
		$html = "<ul id=\"all-cities\" class=\"breanm-hub-rest\">\n";
		foreach ( $rest as $r ) {
			// A stored href is printed back as stored: it is already escaped markup, and esc_url() would escape it again.
			$html .= '<li><a href="' . $r[1] . '">' . esc_html( $r[0] ) . "</a></li>\n";
		}
		$html .= '</ul>';
	}
	$content = substr_replace( $content, $html, $start, $end - $start );

	// The heading above the table. Only the first one is touched. No /u: state names are ASCII, and with /u one
	// invalid UTF-8 byte anywhere in the page makes preg_replace() return null; null keeps the old heading.
	$name = preg_quote( dos_agents_state_name( $state ), '/' );
	$re   = '/All Cities in ' . $name . '\s*\([\d,]+\)/';
	if ( $n ) {
		$out = preg_replace( $re, str_replace( '$', '\$', sprintf( 'More cities in %s (%d)', dos_agents_state_name( $state ), $n ) ), $content, 1 );
	} elseif ( preg_match( $re, $content, $m, PREG_OFFSET_CAPTURE ) ) {
		// Nothing to list: the heading goes with the table. If the text is not inside a heading tag, only the text goes.
		$at     = $m[0][1];
		$before = substr( $content, max( 0, $at - 800 ), min( $at, 800 ) );
		$out    = null;
		if ( preg_match_all( '/<h([1-6])\b[^>]*>/i', $before, $hs, PREG_OFFSET_CAPTURE ) ) {
			$h    = end( $hs[0] );
			$tag  = end( $hs[1] );
			$open = $at - strlen( $before ) + $h[1];
			$stop = stripos( $content, '</h' . $tag[0] . '>', $at );
			if ( false !== $stop && false === stripos( substr( $content, $open, $at - $open ), '</h' . $tag[0] . '>' ) ) {
				$out = substr_replace( $content, '', $open, $stop + 5 - $open );
			}
		}
		if ( null === $out ) {
			$out = preg_replace( $re, '', $content, 1 );
		}
	} else {
		$out = $content;
	}
	$remaining = $n;
	return null === $out ? $content : $out;
}
