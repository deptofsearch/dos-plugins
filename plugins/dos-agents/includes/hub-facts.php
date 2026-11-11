<?php
/**
 * State hubs: a short paragraph of facts about the state's agent lists, and a line saying who compiles them.
 *
 * The SEO audit of 2026-11-10 (P5) judged the hubs thin: a list of cities and a short stock paragraph, with nothing
 * saying where the lists come from. Every number here is one the site itself holds, read from the stored city
 * data, so the paragraph is always true and follows the sweep by itself. Agents are counted once per state, by
 * their profile ID: the same agent is listed in several cities (Meridian, Boise and Kuna list 215, 169 and 130,
 * with heavy overlap), so adding up the city counts would claim agents the state does not have.
 *
 * The source line does not name the profile site, as Ryan asked for the city-page intros (2026-11-11); each city
 * page's own "How this list works" disclosure names it. Like the rest of hub.php nothing is written into the
 * pages, and `dos_agents_hub_facts` (false) turns both off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_AGENTS_HUB_FACTS_TRANSIENT', 'breanm_agents_hub_facts_v1_' );

function dos_agents_hub_facts_enabled() {
	return (bool) apply_filters( 'dos_agents_hub_facts', true );
}

/**
 * The facts for a state, cached for 12 hours: {cities, agents, recent, teams, updated, top}.
 *
 * `cities` is the number of cities with cards, `agents` the distinct agents across them, `recent` those whose
 * newest review is within the last 12 months (the same cutoff as the cards' filter), `teams` the team profiles,
 * `updated` the newest generated_at of the state's cities (YYYY-MM-DD, '' when none says), and `top` up to three
 * array( city, agents ) with the most agents. Null when the state has no city with cards.
 *
 * Reads each city's stored agents once per 12 hours. Cleared when any city of the state is touched in the index
 * (dos_agents_hub_facts_flush()), which is every push and every page save the sweep makes.
 */
function dos_agents_hub_facts( $state, $now = null ) {
	$state = dos_agents_state( $state );
	if ( '' === $state ) {
		return null;
	}
	$key = DOS_AGENTS_HUB_FACTS_TRANSIENT . strtolower( $state );
	$hit = get_transient( $key );
	if ( is_array( $hit ) && isset( $hit['cities'] ) ) {
		return $hit['cities'] > 0 ? $hit : null;
	}

	$cutoff  = dos_agents_recent_cutoff( null === $now ? time() : (int) $now );
	$seen    = array();
	$recent  = 0;
	$teams   = 0;
	$updated = '';
	$top     = array();
	$cities  = 0;
	foreach ( dos_agents_index_for_state( $state ) as $slug => $row ) {
		$stored = dos_agents_get( $slug );
		// Let the city's agents go again: up to 500 per city, and a state can have dozens of cities.
		wp_cache_delete( dos_agents_option_name( $slug ), 'options' );
		if ( null === $stored || ! $stored['agents'] ) {
			continue;
		}
		++$cities;
		// The index row's count, so the top cities read the same as the city boxes right below the paragraph.
		$top[] = array( $row['c'], (int) $row['n'] );
		// The site's local date, like the review cutoff, not the UTC day of the timestamp.
		$gen = strtotime( (string) $stored['generated_at'] );
		$day = $gen ? wp_date( 'Y-m-d', $gen ) : '';
		if ( '' !== $day && $day > $updated ) {
			$updated = $day;
		}
		foreach ( $stored['agents'] as $a ) {
			$id = '' !== $a['zuid'] ? 'z:' . $a['zuid'] : 'u:' . $a['profile_url'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$recent     += dos_agents_is_recent( $a, $cutoff ) ? 1 : 0;
			$teams      += $a['is_team'] ? 1 : 0;
		}
	}
	usort(
		$top,
		static function ( $x, $y ) {
			return array( -$x[1], $x[0] ) <=> array( -$y[1], $y[0] );
		}
	);
	$facts = array(
		'cities'  => $cities,
		'agents'  => count( $seen ),
		'recent'  => $recent,
		'teams'   => $teams,
		'updated' => $updated,
		'top'     => array_slice( $top, 0, 3 ),
	);
	set_transient( $key, $facts, 12 * HOUR_IN_SECONDS );
	return $cities > 0 ? $facts : null;
}

/** Clears every state's cached facts: the index rebuild adds and drops cities without touching them one by one. */
function dos_agents_hub_facts_flush_all() {
	foreach ( array_keys( dos_agents_state_names() ) as $st ) {
		delete_transient( DOS_AGENTS_HUB_FACTS_TRANSIENT . strtolower( $st ) );
	}
}

/** Clears the cached facts of the state a city slug belongs to ("boise-id" -> ID). */
function dos_agents_hub_facts_flush( $slug ) {
	if ( preg_match( '/-([a-z]{2})$/', (string) $slug, $m ) && isset( dos_agents_state_names()[ strtoupper( $m[1] ) ] ) ) {
		delete_transient( DOS_AGENTS_HUB_FACTS_TRANSIENT . $m[1] );
	}
}

/** "listing 261 different agents with recorded sales in those cities." (or "1 agent", or "there" for one city). */
function dos_agents_hub_facts_listing( $f ) {
	if ( 1 === $f['agents'] ) {
		return 1 === $f['cities'] ? __( 'with 1 agent who has recorded sales there.', 'dos-agents' ) : __( 'with 1 agent who has recorded sales in those cities.', 'dos-agents' );
	}
	return sprintf(
		/* translators: %s: number of distinct agents */
		1 === $f['cities'] ? __( 'with %s different agents who have recorded sales there.', 'dos-agents' ) : __( 'with %s different agents who have recorded sales in those cities.', 'dos-agents' ),
		number_format_i18n( $f['agents'] )
	);
}

/** "Seattle (48)", "Seattle (48) and Bellevue (18)", "Seattle (48), Bellevue (18) and Everett (18)". */
function dos_agents_hub_facts_top( $top ) {
	$parts = array();
	foreach ( $top as list( $city, $n ) ) {
		$parts[] = $city . ' (' . number_format_i18n( $n ) . ')';
	}
	if ( count( $parts ) < 2 ) {
		return (string) reset( $parts );
	}
	$last = array_pop( $parts );
	/* translators: 1: a list of cities, 2: the last city */
	return sprintf( __( '%1$s and %2$s', 'dos-agents' ), implode( ', ', $parts ), $last );
}

/**
 * The facts paragraph for a hub, or '' when the state has no city with cards (the hub block already says so).
 *
 * @param int|null $pages The number of city pages the hub lists (its "All Cities in {State} (N)" count), or null.
 */
function dos_agents_render_hub_facts( $state, $pages = null, $now = null ) {
	if ( ! dos_agents_hub_facts_enabled() ) {
		return '';
	}
	$f = dos_agents_hub_facts( $state, $now );
	if ( null === $f ) {
		return '';
	}
	$name      = dos_agents_state_name( $state );
	$sentences = array();
	if ( null !== $pages && $pages >= $f['cities'] ) {
		$sentences[] = sprintf(
			/* translators: 1: state name, 2: number of city pages */
			_n( '%1$s has %2$s city page on this site.', '%1$s has %2$s city pages on this site.', $pages, 'dos-agents' ),
			$name,
			number_format_i18n( $pages )
		);
		$sentences[] = sprintf(
			/* translators: %s: number of cities with cards */
			_n( '%s of them has an agent list so far,', '%s of them have agent lists so far,', $f['cities'], 'dos-agents' ),
			number_format_i18n( $f['cities'] )
		) . ' ' . dos_agents_hub_facts_listing( $f );
	} else {
		$sentences[] = sprintf(
			/* translators: 1: number of cities with cards, 2: state name */
			_n( '%1$s city in %2$s has an agent list so far,', '%1$s cities in %2$s have agent lists so far,', $f['cities'], 'dos-agents' ),
			number_format_i18n( $f['cities'] ),
			$name
		) . ' ' . dos_agents_hub_facts_listing( $f );
	}
	if ( count( $f['top'] ) >= 2 ) {
		/* translators: %s: up to three cities with their agent counts, e.g. "Seattle (48), Bellevue (18) and Everett (18)" */
		$sentences[] = sprintf( __( '%s list the most.', 'dos-agents' ), dos_agents_hub_facts_top( $f['top'] ) );
	}
	if ( $f['agents'] > 1 ) {
		$all = number_format_i18n( $f['agents'] );
		if ( 0 === $f['recent'] ) {
			/* translators: %s: number of agents */
			$sentences[] = sprintf( __( 'None of the %s has a review from the last 12 months.', 'dos-agents' ), $all );
		} elseif ( $f['recent'] === $f['agents'] ) {
			/* translators: %s: number of agents */
			$sentences[] = sprintf( __( 'All %s have a review from the last 12 months.', 'dos-agents' ), $all );
		} else {
			$sentences[] = sprintf(
				/* translators: 1: agents reviewed in the last 12 months, 2: all agents */
				_n( '%1$s of the %2$s has a review from the last 12 months.', '%1$s of the %2$s have a review from the last 12 months.', $f['recent'], 'dos-agents' ),
				number_format_i18n( $f['recent'] ),
				$all
			);
		}
		if ( $f['teams'] > 0 ) {
			$sentences[] = sprintf(
				/* translators: 1: number of team profiles, 2: all agents */
				_n( '%1$s of the %2$s is a team.', '%1$s of the %2$s are teams.', $f['teams'], 'dos-agents' ),
				number_format_i18n( $f['teams'] ),
				$all
			);
		}
	}
	$ts = '' !== $f['updated'] ? strtotime( $f['updated'] . ' 12:00:00 UTC' ) : false;
	if ( $ts ) {
		/* translators: 1: state name, 2: date, e.g. "November 4, 2026" */
		$sentences[] = sprintf( _n( 'The agent list for %1$s was last updated %2$s.', 'The agent lists for %1$s were most recently updated %2$s.', $f['cities'], 'dos-agents' ), $name, gmdate( 'F j, Y', $ts ) );
	}
	return '<p class="breanm-hub-facts">' . esc_html( implode( ' ', $sentences ) ) . '</p>';
}

/** The "How this list works" line for a hub. */
function dos_agents_render_hub_source() {
	if ( ! dos_agents_hub_facts_enabled() ) {
		return '';
	}
	$site = trim( html_entity_decode( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$text = sprintf(
		/* translators: %s: site name */
		__( 'Compiled by %s from public agent profiles and reviews. Information only, not a ranking or endorsement, and we are not a brokerage. Each city page explains how its list is built.', 'dos-agents' ),
		'' !== $site ? $site : __( 'this site', 'dos-agents' )
	);
	$text = (string) apply_filters( 'dos_agents_hub_source_text', $text );
	return '<p class="breanm-hub-source"><strong>' . esc_html__( 'How this list works.', 'dos-agents' ) . '</strong> ' . esc_html( $text ) . '</p>';
}
