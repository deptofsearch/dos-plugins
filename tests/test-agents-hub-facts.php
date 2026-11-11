<?php
/**
 * DoS - Agents: the facts paragraph and source line on state hubs (includes/hub-facts.php).
 *
 * Pins that agents are counted once per state, that every number comes from the stored city data, the sentence
 * forms, the cache and its clearing, and where the two blocks sit on the hub.
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

// 2026-11-11 noon UTC: the 12-month cutoff is 2025-11-11 in the site's timezone.
$NOW = 1794398400;

/** Stores a city whose published page holds the shortcode, so it is in the index. */
function hf_city( $city, $state, array $agents, $generated = '2026-11-04T15:00:00Z' ) {
	$slug = dos_agents_city_slug( $city, $state );
	update_option( dos_agents_option_name( $slug ), array( 'city' => $city, 'state' => $state, 'generated_at' => $generated, 'agents' => $agents ), false );
	agents_add_page( 'in-' . $slug, '[breanm_agents city="' . $slug . '"]' );
	dos_agents_index_touch( $slug );
}

function hf_seed() {
	agents_reset();
	$shared = agents_make( array( 'zuid' => 'SHARED', 'last_review' => '2026-10-01' ) );
	$team   = agents_make( array( 'zuid' => 'TEAM', 'is_team' => true, 'last_review' => '2025-11-11' ) );
	$old    = agents_make( array( 'zuid' => 'OLD', 'last_review' => '2025-11-10' ) );
	$none   = agents_make( array( 'zuid' => 'NONE', 'last_review' => '' ) );
	hf_city( 'Seattle', 'WA', array( $shared, $team, $old, agents_make( array( 'last_review' => '2026-01-02' ) ) ), '2026-11-04T15:00:00Z' );
	hf_city( 'Bellevue', 'WA', array( $shared, $none ), '2026-11-09T01:00:00Z' );
	hf_city( 'Everett', 'WA', array( $shared, $team ), 'not a date' );
	hf_city( 'Boise', 'ID', array( agents_make() ) );
}

echo "--- Numbers ---\n";
hf_seed();
$f = dos_agents_hub_facts( 'WA', $NOW );
check( 'three cities with cards', 3 === $f['cities'] );
check( 'agents counted once per state (8 listings, 5 distinct)', 5 === $f['agents'], (string) $f['agents'] );
check( 'recent uses the cards\' 12-month cutoff (2025-11-11 counts, 2025-11-10 does not)', 3 === $f['recent'], (string) $f['recent'] );
check( 'teams counted once', 1 === $f['teams'] );
check( 'last updated: newest generated_at as a site-local date (01:00 UTC on Nov 9 is Nov 8 in Pacific), a bad one ignored', '2026-11-08' === $f['updated'], $f['updated'] );
check( 'top cities by their own agent count, ties by name', array( array( 'Seattle', 4 ), array( 'Bellevue', 2 ), array( 'Everett', 2 ) ) === $f['top'], json_encode( $f['top'] ) );
check( 'another state is not mixed in', 1 === dos_agents_hub_facts( 'ID', $NOW )['agents'] );
check( 'a state with no cards: null', null === dos_agents_hub_facts( 'AL', $NOW ) );

echo "\n--- The paragraph ---\n";
$p = dos_agents_render_hub_facts( 'WA', 470, $NOW );
echo '  ' . wp_strip_all_tags( $p ) . "\n";
check( 'full paragraph', '<p class="breanm-hub-facts">Washington has 470 city pages on this site. 3 of them have agent lists so far, with 5 different agents who have recorded sales in those cities. Seattle (4), Bellevue (2) and Everett (2) list the most. 3 of the 5 have a review from the last 12 months. 1 of the 5 is a team. The agent lists for Washington were most recently updated November 8, 2026.</p>' === $p, $p );
check( 'no page count: the opening names the state instead', 0 === strpos( wp_strip_all_tags( dos_agents_render_hub_facts( 'WA', null, $NOW ) ), '3 cities in Washington have agent lists so far, with 5 different agents' ) );
check( 'a page count smaller than the cities is not trusted', 0 === strpos( wp_strip_all_tags( dos_agents_render_hub_facts( 'WA', 2, $NOW ) ), '3 cities in Washington have' ) );
$id = wp_strip_all_tags( dos_agents_render_hub_facts( 'ID', 269, $NOW ) );
check( 'one city: singular, no "list the most" sentence', 'Idaho has 269 city pages on this site. 1 of them has an agent list so far, with 1 agent who has recorded sales there. The agent list for Idaho was last updated November 4, 2026.' === $id, $id );
agents_reset();
hf_city( 'Austin', 'TX', array( agents_make( array( 'last_review' => '2020-01-01' ) ), agents_make( array( 'last_review' => '' ) ) ) );
$tx = wp_strip_all_tags( dos_agents_render_hub_facts( 'TX', 1450, $NOW ) );
check( 'none recent, no teams: "None of the 2", no team sentence', false !== strpos( $tx, 'with 2 different agents who have recorded sales there. None of the 2 has a review from the last 12 months. The agent list for' ) && false === strpos( $tx, 'team' ), $tx );
hf_seed();
agents_reset();
hf_city( 'Provo', 'UT', array( agents_make( array( 'last_review' => '2026-05-01' ) ), agents_make( array( 'last_review' => '2019-01-01', 'is_team' => true ) ), agents_make( array( 'last_review' => '2019-01-01', 'is_team' => true ) ) ) );
$ut = wp_strip_all_tags( dos_agents_render_hub_facts( 'UT', 272, $NOW ) );
check( 'one recent: "1 of the 3 has", two teams: "2 of the 3 are teams"', false !== strpos( $ut, '1 of the 3 has a review from the last 12 months. 2 of the 3 are teams.' ), $ut );
hf_city( 'Ogden', 'UT', array( agents_make( array( 'zuid' => '', 'profile_url' => 'https://www.zillow.com/profile/same', 'last_review' => '2026-05-01' ) ) ) );
hf_city( 'Orem', 'UT', array( agents_make( array( 'zuid' => '', 'profile_url' => 'https://www.zillow.com/profile/same', 'last_review' => '2026-05-01' ) ) ) );
check( 'agents without a profile ID are deduplicated by profile link', 4 === dos_agents_hub_facts( 'UT', $NOW )['agents'], (string) dos_agents_hub_facts( 'UT', $NOW )['agents'] );
agents_reset();
hf_city( 'Reno', 'NV', array( agents_make( array( 'last_review' => '2026-05-01' ) ), agents_make( array( 'last_review' => '2026-06-01' ) ) ), '' );
$nv = wp_strip_all_tags( dos_agents_render_hub_facts( 'NV', 98, $NOW ) );
check( 'all recent: "All 2 have"; no date: no updated sentence', false !== strpos( $nv, 'All 2 have a review from the last 12 months.' ) && false === strpos( $nv, 'updated' ), $nv );
hf_seed();
check( 'no cards: no paragraph (the hub block says so)', '' === dos_agents_render_hub_facts( 'AL', 573, $NOW ) );
check( 'no em dashes', false === strpos( $p, "\u{2014}" ) );

echo "\n--- Cache ---\n";
set_transient( 'breanm_agents_hub_facts_v1_wa', array( 'cities' => 9, 'agents' => 99, 'recent' => 1, 'teams' => 0, 'updated' => '', 'top' => array() ) );
check( 'read from the 12-hour cache', 99 === dos_agents_hub_facts( 'WA', $NOW )['agents'] );
dos_agents_index_touch( 'seattle-wa' );
check( 'touching a WA city clears WA', false === get_transient( 'breanm_agents_hub_facts_v1_wa' ) && 5 === dos_agents_hub_facts( 'WA', $NOW )['agents'] );
dos_agents_hub_facts( 'ID', $NOW );
dos_agents_index_touch( 'seattle-wa' );
check( 'and leaves ID cached', false !== get_transient( 'breanm_agents_hub_facts_v1_id' ) );
dos_agents_store( array( 'city' => 'Tacoma', 'state' => 'WA', 'agents' => array( agents_make() ) ) );
check( 'a push for a WA city clears WA', false === get_transient( 'breanm_agents_hub_facts_v1_wa' ) );
hf_seed();
dos_agents_hub_facts( 'WA', $NOW );
update_option( dos_agents_option_name( 'seattle-wa' ), array( 'city' => 'Seattle', 'state' => 'WA', 'agents' => array( agents_make(), agents_make(), agents_make(), agents_make() ) ), false );
dos_agents_index_touch( 'seattle-wa' );
check( 'a push that changes the agents but not the count still clears WA', false === get_transient( 'breanm_agents_hub_facts_v1_wa' ) );
dos_agents_hub_facts( 'WA', $NOW );
dos_agents_hub_facts( 'ID', $NOW );
dos_agents_index_rebuild();
check( 'an index rebuild clears every state', false === get_transient( 'breanm_agents_hub_facts_v1_wa' ) && false === get_transient( 'breanm_agents_hub_facts_v1_id' ) );
dos_agents_hub_facts( 'AL', $NOW );
check( 'an empty state is cached too, as zero', is_array( get_transient( 'breanm_agents_hub_facts_v1_al' ) ) && 0 === get_transient( 'breanm_agents_hub_facts_v1_al' )['cities'] );

echo "\n--- Source line ---\n";
$GLOBALS['wp']['sitename'] = 'Best Real Estate Agents Near Me';
$s = dos_agents_render_hub_source();
check( 'source line', '<p class="breanm-hub-source"><strong>How this list works.</strong> Compiled by Best Real Estate Agents Near Me from public agent profiles and reviews. Information only, not a ranking or endorsement, and we are not a brokerage. Each city page explains how its list is built.</p>' === $s, $s );
check( 'does not name Zillow', false === stripos( $s, 'zillow' ) );
$GLOBALS['wp']['sitename'] = 'A &amp; B <i>Agents</i>';
check( 'site name decoded, tags gone, escaped once', false !== strpos( dos_agents_render_hub_source(), 'Compiled by A &amp; B Agents from' ) );
$GLOBALS['wp']['sitename'] = 'Best Real Estate Agents Near Me';
$GLOBALS['filters']['dos_agents_hub_facts'][] = '__return_false';
check( 'dos_agents_hub_facts false turns both off', '' === dos_agents_render_hub_source() && '' === dos_agents_render_hub_facts( 'WA', 470, $NOW ) );
unset( $GLOBALS['filters']['dos_agents_hub_facts'] );

echo "\n--- On the hub ---\n";
hf_seed();
$GLOBALS['filters']['dos_agents_pre_empty_city_ids'][] = static function () { return array(); };
$GLOBALS['filters']['dos_agents_pre_hub_city_pages'][] = static function () { return array(); };
$stored = '<h3>All Cities in Washington (470)</h3><table class="breanm-city-table"><tr><td><a href="https://example.com/in-seattle-wa/">Seattle</a></td></tr></table><p>Old copy.</p>';
$hub    = agents_add_page( 'in-washington', $stored );
$GLOBALS['wp']['page']     = true;
$GLOBALS['wp']['singular'] = true;
$GLOBALS['wp']['queried']  = $hub->ID;
$GLOBALS['wp']['post']     = $hub;
$o  = dos_agents_hub_the_content( $stored );
$at = array_map( static function ( $needle ) use ( $o ) { return strpos( $o, $needle ); }, array( 'breanm-hub-title', 'breanm-hub-facts', 'breanm-home__hub', 'breanm-hub-source', 'All Cities in Washington' ) );
check( 'order: heading, facts, city cards, source line, stored content', ! in_array( false, $at, true ) && $at === array_values( array_unique( $at ) ) && $at == array_values( ( function ( $a ) { sort( $a ); return $a; } )( $at ) ), json_encode( $at ) );
check( 'the facts use the hub\'s own city-page count', false !== strpos( $o, 'Washington has 470 city pages on this site.' ) );
// A second page: the content filter runs once per page per request.
$big  = str_replace( '(470)', '(1,450)', $stored );
$hub2 = agents_add_page( 'in-washington', $big, 'publish', 9999 );
$GLOBALS['wp']['queried'] = $hub2->ID;
$GLOBALS['wp']['post']    = $hub2;
check( 'a count with a comma is read', false !== strpos( dos_agents_hub_the_content( $big ), 'Washington has 1,450 city pages on this site.' ) );
check( 'the stored content follows unchanged', '<p>Old copy.</p>' === substr( $o, -strlen( '<p>Old copy.</p>' ) ), json_encode( substr( $o, -60 ) ) );

finish();
