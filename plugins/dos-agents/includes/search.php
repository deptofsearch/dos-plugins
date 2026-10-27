<?php
/**
 * City-name matching for the homepage search box.
 *
 * The rules live here and are mirrored in assets/breanm-home.js, because the box has to behave the
 * same whether the typeahead is running or the form was submitted with JavaScript off. Keep the two
 * in step: they normalise the same way, read the same state names and rank the same way. The rules
 * are the ones DoS - Best Lenders arrived at, so a visitor who uses both sites types the same things.
 *
 * A query may be "Boise", "boise id", "Boise, ID", "Boise, Idaho", "Saint Helens", "St. Helens", or a
 * prefix of any of those.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lowercase, accents and punctuation dropped, single spaces, and Saint/Mount/Fort folded to St/Mt/Ft
 * so "Saint Helens" and "St. Helens" are one thing however either side spells it.
 */
function dos_agents_search_norm( $s ) {
	$s = remove_accents( (string) $s );
	$s = strtolower( $s );
	$s = preg_replace( "/['\xE2\x80\x99.]/u", '', $s );
	$s = trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
	return preg_replace( array( '/\bsaint\b/', '/\bmount\b/', '/\bfort\b/' ), array( 'st', 'mt', 'ft' ), $s );
}

/**
 * The state codes a normalised string can mean: an exact code, an exact name, or a name prefix of at
 * least $min_prefix letters. "wa" is Washington; "new" is neither New York nor New Mexico on its own,
 * which is why the caller asks for three letters when the state was not set off by a comma.
 */
function dos_agents_search_states( $s, $min_prefix ) {
	if ( '' === $s ) {
		return array();
	}
	$names = dos_agents_state_names();
	$code  = strtoupper( $s );
	if ( isset( $names[ $code ] ) ) {
		return array( $code );
	}
	$out = array();
	foreach ( $names as $abbr => $name ) {
		$name = strtolower( $name );
		if ( $s === $name ) {
			return array( $abbr );
		}
		if ( strlen( $s ) >= $min_prefix && 0 === strpos( $name, $s ) ) {
			$out[] = $abbr;
		}
	}
	return $out;
}

/**
 * The ways one query can be read: a list of [ normalised city, state codes or null ]. "kansas city"
 * is both a city called Kansas City and a city called Kansas in a state starting "city" (no such
 * state, so that reading is dropped); "springfield il" is Springfield in Illinois and also a city
 * literally called "springfield il" (no such city, so that one finds nothing).
 */
function dos_agents_search_readings( $raw ) {
	$raw = (string) $raw;
	$out = array();
	$pos = strpos( $raw, ',' );

	// With a comma the visitor has said which part is the state, so one letter is enough to resolve it.
	if ( false !== $pos ) {
		$city  = dos_agents_search_norm( substr( $raw, 0, $pos ) );
		$state = dos_agents_search_norm( substr( $raw, $pos + 1 ) );
		if ( '' === $city ) {
			return $out;
		}
		if ( '' === $state ) {
			$out[] = array( $city, null );
			return $out;
		}
		$codes = dos_agents_search_states( $state, 1 );
		if ( $codes ) {
			$out[] = array( $city, $codes );
		}
		return $out;
	}

	$norm = dos_agents_search_norm( $raw );
	if ( '' === $norm ) {
		return $out;
	}
	$out[]  = array( $norm, null );
	$tokens = explode( ' ', $norm );
	for ( $i = 1, $n = count( $tokens ); $i < $n; $i++ ) {
		$codes = dos_agents_search_states( implode( ' ', array_slice( $tokens, $i ) ), 3 );
		if ( $codes ) {
			$out[] = array( implode( ' ', array_slice( $tokens, 0, $i ) ), $codes );
		}
	}
	return $out;
}

/**
 * Ranks index rows against a query. Each hit is the row plus 'rank': 0/1 the whole city name, 2/3 a
 * prefix of it, 4/5 the start of a later word ("helens" finding St Helens). The even number of each
 * pair is the one where the query also named the state, so "boise id" beats a bare "boise".
 *
 * @param array $rows dos_agents_index() rows, keyed by city slug.
 */
function dos_agents_search_match( array $rows, $raw ) {
	$readings = dos_agents_search_readings( $raw );
	$hits     = array();
	if ( ! $readings ) {
		return $hits;
	}
	foreach ( $rows as $slug => $r ) {
		$k    = dos_agents_search_norm( $r['c'] );
		$best = null;
		foreach ( $readings as $rd ) {
			list( $city, $states ) = $rd;
			if ( null !== $states && ! in_array( $r['s'], $states, true ) ) {
				continue;
			}
			if ( $k === $city ) {
				$tier = 0;
			} elseif ( 0 === strpos( $k, $city ) ) {
				$tier = 2;
			} elseif ( false !== strpos( $k, ' ' . $city ) ) {
				$tier = 4;
			} else {
				continue;
			}
			$rank = $tier + ( null === $states ? 1 : 0 );
			if ( null === $best || $rank < $best ) {
				$best = $rank;
			}
		}
		if ( null !== $best ) {
			$r['rank'] = $best;
			$r['k']    = $k;
			$r['slug'] = $slug;
			$hits[]    = $r;
		}
	}
	usort(
		$hits,
		static function ( $a, $b ) {
			return $a['rank'] <=> $b['rank'] ?: strcmp( $a['k'], $b['k'] ) ?: strcmp( $a['s'], $b['s'] );
		}
	);
	return $hits;
}

/**
 * The one city a query clearly means, or null. "Boise" is clear; "Springfield" is not, and the
 * visitor is shown the list instead of being sent to whichever one sorted first.
 */
function dos_agents_search_resolve( array $rows, $raw ) {
	$hits  = dos_agents_search_match( $rows, $raw );
	$exact = array_values(
		array_filter(
			$hits,
			static function ( $h ) {
				return $h['rank'] <= 1;
			}
		)
	);
	if ( 1 === count( $exact ) ) {
		return $exact[0];
	}
	if ( ! $exact && 1 === count( $hits ) ) {
		return $hits[0];
	}
	return null;
}

/**
 * template_redirect: the search form's no-JavaScript path. It submits ?s=<city>&breanm_city=1 to the
 * site root, and when exactly one city matches we go straight there. Anything else falls through to
 * the theme's own search results, which is a reasonable place to land.
 */
function dos_agents_search_fallback() {
	if ( ! is_search() ) {
		return;
	}
	// A marker on our own form, not a post type: city pages are ordinary Pages, shared with the rest of the site.
	if ( empty( $_GET['breanm_city'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public search form, no state change.
		return;
	}
	$q = trim( (string) get_query_var( 's' ) );
	if ( '' === $q ) {
		return;
	}
	$hit = dos_agents_search_resolve( dos_agents_index(), $q );
	if ( $hit && ! empty( $hit['u'] ) ) {
		wp_safe_redirect( $hit['u'], 302 );
		exit;
	}
}
