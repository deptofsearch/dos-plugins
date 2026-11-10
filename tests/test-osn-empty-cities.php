<?php
/**
 * OSN\Empty_Cities: which city pages have nothing to show (no published venue, or only permanently closed ones), the
 * one cached set, the flush on a city's first or last venue, and everything that reads it: robots, the sitemap,
 * the state page tiles and the homepage's city counts.
 */

require __DIR__ . '/wp-stubs-osn.php';
osn_load();

use OSN\Empty_Cities;
use OSN\Seo;
use OSN\State_Cities;
use OSN\Home;
use OSN\Util;
use OSN\Takeover;

/** A city term (landing page $landing, slug taken from the page) with $count published venues. */
function ec_city( $tid, $name, $landing, $count, $state = 'AZ' ) {
	$t        = osn_term( $tid, $name, sanitize_title( $name ), 'osn_city', 0, array( 'osn_state' => $state ) );
	$t->count = $count;
	if ( null !== $landing ) {
		$GLOBALS['osn']['term_meta'][ $tid ]['osn_landing_page_id'] = $landing;
	}
	return $t;
}
function ec_page( $id, $slug, $filter ) {
	osn_post( $id, $slug, '' === $filter ? '<p>About us</p>' : '[table id=1 filter="' . $filter . '"]' );
}
function ec_site() {
	osn_reset();
	$GLOBALS['osn']['options']['osn_takeover_cities'] = '*';
	ec_city( 1, 'Phoenix, AZ', 101, 12 );
	ec_page( 101, 'phoenix-az', 'Phoenix, AZ' );
	ec_city( 2, 'Tombstone, AZ', 102, 0 );
	ec_page( 102, 'tombstone-az', 'Tombstone, AZ' );
	ec_city( 3, 'Mesa, AZ', 0, 0 );              // No landing page: not a page to hide.
	ec_city( 4, 'Tempe, AZ', 104, 0 );           // Empty term A whose meta points at a page that shows term B.
	ec_city( 5, 'Tempe Town, AZ', 105, 3 );
	ec_page( 104, 'tempe-az', 'Tempe Town, AZ' );
	ec_city( 6, 'Ghost Gulch, AZ', 106, 2 );     // Two venues, both permanently closed.
	ec_page( 106, 'ghost-gulch-az', 'Ghost Gulch, AZ' );
	ec_city( 7, 'Half Closed, AZ', 107, 2 );     // Two venues, one permanently closed: still has a card.
	ec_page( 107, 'half-closed-az', 'Half Closed, AZ' );
	ec_city( 8, 'Statewide, AZ', 108, 0 );       // Landing meta points at a page with no [table filter] (a state page).
	ec_page( 108, 'arizona-landing', '' );
	ec_city( 9, 'Old Name, AZ', 109, 0 );        // The page's filter names no term at all.
	ec_page( 109, 'nowhere-az', 'Nowhere, AZ' );
	Empty_Cities::$closed = function () { $GLOBALS['ec_closed_calls'] = ( $GLOBALS['ec_closed_calls'] ?? 0 ) + 1; return array( 6 => 2, 7 => 1 ); };
	$GLOBALS['ec_closed_calls'] = 0;
}

echo "--- which landing pages are empty ---\n";
ec_site();
$ids = Empty_Cities::ids();
sort( $ids );
check( 'a city with no venue is empty', in_array( 102, $ids, true ) );
check( 'a city with venues is not', ! in_array( 101, $ids, true ) );
check( 'a term with no landing page adds nothing (0 is never listed)', ! in_array( 0, $ids, true ) );
check( 'a page whose filter names a city with venues is not empty, though an empty term\'s meta points at it', ! in_array( 104, $ids, true ) );
check( 'a landing ID that is not a city page (no [table filter]) never enters the set', ! in_array( 108, $ids, true ) );
check( 'a filter that resolves to no term is empty', in_array( 109, $ids, true ) );
check( 'a city whose venues are all permanently closed is empty', in_array( 106, $ids, true ) );
check( 'a city with one open venue left is not', ! in_array( 107, $ids, true ) );
check( 'ids() is exactly [102, 106, 109]', array( 102, 106, 109 ) === $ids, json_encode( $ids ) );
check( 'is_empty_page() agrees', Empty_Cities::is_empty_page( 102 ) && ! Empty_Cities::is_empty_page( 101 ) && ! Empty_Cities::is_empty_page( 999 ) );
ec_site();
$GLOBALS['osn']['terms'][5]->count = 0;
$GLOBALS['osn']['posts'][104]->post_content = '[table id=1 filter="Tempe, AZ"]';
check( 'a page whose own filter names an empty term is empty', in_array( 104, Empty_Cities::ids(), true ) );
ec_site();
$GLOBALS['osn']['posts'][108]->post_status = 'draft';
$GLOBALS['osn']['posts'][108]->post_content = '[table id=1 filter="Statewide, AZ"]';
check( 'an unpublished page is never in the set', ! in_array( 108, Empty_Cities::ids(), true ) );
$GLOBALS['osn']['posts'][108]->post_status = 'publish';
$GLOBALS['osn']['options']['osn_takeover_cities'] = 'phoenix-az';
Empty_Cities::flush();
check( 'a city the takeover is not enabled for is in the set when its page prints nothing (TablePress shows an empty table)', in_array( 102, Empty_Cities::ids(), true ) );
$GLOBALS['osn']['shortcodes']['[table id=1 filter="Tombstone, AZ"]'] = '<table><tbody><tr><td>Bird Cage</td></tr></tbody></table>';
Empty_Cities::flush();
Empty_Cities::flush_memo();
check( '... and is not when TablePress prints a row for it', ! in_array( 102, Empty_Cities::ids(), true ) );

echo "\n--- paths() ---\n";
ec_site();
$paths = Empty_Cities::paths();
check( 'keys are State_Cities::local_key() of the page URL', isset( $paths['tombstone-az'] ) && isset( $paths['ghost-gulch-az'] ) && isset( $paths['nowhere-az'] ) && 3 === count( $paths ), json_encode( $paths ) );
check( 'the key is what local_key gives for the permalink', State_Cities::local_key( get_permalink( 102 ) ) === 'tombstone-az' );
check( 'a non-empty city is not in it', ! isset( $paths['phoenix-az'] ) );

echo "\n--- one cached set ---\n";
ec_site();
Empty_Cities::ids();
check( 'the set is stored in one transient', isset( $GLOBALS['osn']['transients']['osn_empty_cities_v1'] ) );
$GLOBALS['osn']['terms'][2]->count = 5; // The site changes but nothing flushes.
Empty_Cities::flush_memo();
check( 'a later request reads the cached set', in_array( 102, Empty_Cities::ids(), true ) && 1 === $GLOBALS['ec_closed_calls'] );
check( 'ids() and paths() share one build', 1 === $GLOBALS['ec_closed_calls'] && isset( Empty_Cities::paths()['tombstone-az'] ) );
Empty_Cities::flush();
check( 'flush() deletes the transient', ! isset( $GLOBALS['osn']['transients']['osn_empty_cities_v1'] ) );
check( 'and the next read rebuilds', ! in_array( 102, Empty_Cities::ids(), true ) && 2 === $GLOBALS['ec_closed_calls'] );
$GLOBALS['osn']['transients']['osn_city_index_v2'] = array( 'x' );
Empty_Cities::ids();
OSN\City_Index::flush( false );
check( 'City_Index::flush( false ) (a venue batch) keeps the set but clears the index', isset( $GLOBALS['osn']['transients']['osn_empty_cities_v1'] ) && ! isset( $GLOBALS['osn']['transients']['osn_city_index_v2'] ) );
OSN\City_Index::flush();
check( 'City_Index::flush() clears it', ! isset( $GLOBALS['osn']['transients']['osn_empty_cities_v1'] ) );

echo "\n--- osn_city_venues_changed ---\n";
ec_site();
Empty_Cities::hooks();
$fire = function ( $tid, $landing ) { foreach ( $GLOBALS['actions']['osn_city_venues_changed'][10] as $cb ) { call_user_func( $cb, $tid, $landing ); } };
$cached = function () { return isset( $GLOBALS['osn']['transients']['osn_empty_cities_v1'] ); };
check( 'the handler is hooked', ! empty( $GLOBALS['actions']['osn_city_venues_changed'] ) );
Empty_Cities::ids();
// City 1 has venues and the query agrees: no change.
$wpq0 = $GLOBALS['osn']['wpq'] ?? 0;
$GLOBALS['osn']['query_posts'] = array( 5001 );
Util::purge_sorted( 1 );
$fire( 1, 101 );
check( 'a venue change in a city that stays non-empty does not flush', $cached() );
check( 'a cached-not-empty city with a count above zero runs no has-venues query', 0 === ( $GLOBALS['osn']['wpq'] ?? 0 ) - $wpq0 );
// City 2 (empty) still has no venue.
$GLOBALS['osn']['query_posts'] = array();
Util::purge_sorted( 2 );
$fire( 2, 102 );
check( 'a change in a city that stays empty does not flush', $cached() );
// City 2 gains its first venue.
$GLOBALS['osn']['query_posts'] = array( 5002 );
Util::purge_sorted( 2 );
$fire( 2, 102 );
check( 'a city gaining its first venue flushes', ! $cached() );
// City 1 loses its last venue.
Empty_Cities::ids();
$GLOBALS['osn']['query_posts'] = array();
$GLOBALS['osn']['terms'][1]->count = 0;
Util::purge_sorted( 1 );
$fire( 1, 101 );
check( 'a city losing its last venue flushes', ! $cached() );
$GLOBALS['osn']['terms'][1]->count = 12;
Empty_Cities::ids();
$fire( 3, 0 );
check( 'a city with no landing page is ignored', $cached() );
$GLOBALS['osn']['transients'] = array();
$fire( 2, 102 );
check( 'with nothing cached there is nothing to flush or build', ! $cached() );

echo "\n--- robots (decided live from the page) ---\n";
ec_site();
Empty_Cities::hooks();
$in = array( 'max-image-preview' => 'large', 'index' => true, 'follow' => true );
$GLOBALS['osn']['query_posts'] = array();
osn_request( 102 );
$out = apply_filters( 'wp_robots', $in );
check( 'registered at priority 99', array( 99 ) === array_keys( $GLOBALS['filters']['wp_robots'] ) );
check( 'an empty city page with NOTHING cached: noindex, follow, index removed', ! isset( $out['index'] ) && true === $out['noindex'] && true === $out['follow'] && 'large' === $out['max-image-preview'], json_encode( $out ) );
check( 'robots wrote no cached set', ! isset( $GLOBALS['osn']['transients']['osn_empty_cities_v1'] ) );
$GLOBALS['osn']['query_posts'] = array( 5001 );
Util::purge_sorted( 1 );
$GLOBALS['osn']['transients']['osn_empty_cities_v1'] = array( 'ids' => array( 101 ), 'paths' => array( 'phoenix-az' => true ), 'terms' => array( 1 ) );
osn_request( 101 );
check( 'a city page with venues that is (wrongly) in the cached set stays indexable', $in === apply_filters( 'wp_robots', $in ) );
check( 'and the cached set was left as it was', array( 101 ) === $GLOBALS['osn']['transients']['osn_empty_cities_v1']['ids'] );
$GLOBALS['osn']['transients']['osn_empty_cities_v1']['ids'] = array( 108, 900, 901 );
ec_page( 900, 'ballard', 'Ballard, Seattle' );
osn_request( 108 );
check( 'a page with no [table filter] is never noindexed', $in === apply_filters( 'wp_robots', $in ) );
osn_request( 900 );
check( 'a neighborhood filter is never noindexed', $in === apply_filters( 'wp_robots', $in ) );
$GLOBALS['osn']['query_posts'] = array();
Util::purge_sorted( 2 );
$GLOBALS['osn']['options']['osn_takeover_cities'] = 'phoenix-az';
Takeover::flush_memo();
osn_request( 102 );
$GLOBALS['osn']['shortcodes']['[table id=1 filter="Tombstone, AZ"]'] = '<table><thead><tr><th>Name</th></tr></thead><tbody></tbody></table>';
Empty_Cities::flush_memo();
$r = apply_filters( 'wp_robots', $in );
check( 'a city the takeover is not enabled for, whose TablePress table prints no row, is noindexed', ! empty( $r['noindex'] ) && ! isset( $r['index'] ) );
$GLOBALS['osn']['shortcodes']['[table id=1 filter="Tombstone, AZ"]'] = '<table><tbody><tr><td class="column-1">Bird Cage</td></tr></tbody></table>';
Empty_Cities::flush_memo();
check( '... and is left alone when it prints a row', $in === apply_filters( 'wp_robots', $in ) );
unset( $GLOBALS['osn']['shortcodes'] );
Empty_Cities::flush_memo();
$GLOBALS['osn']['options']['osn_takeover_cities'] = '*';
Takeover::flush_memo();
osn_request( 102 );
$GLOBALS['osn']['is_page'] = false;
check( 'something that is not a page is left alone', $in === apply_filters( 'wp_robots', $in ) );
check( 'page_shows_nothing() is the same test, public', Empty_Cities::page_shows_nothing( $GLOBALS['osn']['posts'][102] ) && ! Empty_Cities::page_shows_nothing( $GLOBALS['osn']['posts'][108] ) );

echo "\n--- a page that may show listings the first filter does not account for ---\n";
ec_site();
$tomb = $GLOBALS['osn']['posts'][102];
check( 'baseline: the empty city page shows nothing', Empty_Cities::page_shows_nothing( $tomb ) );
$tomb->post_content = '[table id=1 filter="Tombstone, AZ"] [table id=1 filter="Phoenix, AZ"]';
Empty_Cities::flush_memo();
check( 'a second [table] on the page: never treated as empty', ! Empty_Cities::page_shows_nothing( $tomb ) );
$tomb->post_content = '[table id=1 filter="Tombstone, AZ"] [osn_venues city="Phoenix, AZ"]';
Empty_Cities::flush_memo();
check( 'an [osn_venues] on the page: never treated as empty', ! Empty_Cities::page_shows_nothing( $tomb ) );
$tomb->post_content = '[table id=1 filter="Tombstone, AZ"]';
$GLOBALS['osn']['shortcodes'][ $tomb->post_content ] = '<table class="tablepress"><thead><tr><th>Name</th></tr></thead><tbody><tr class="row-2"><td class="column-1">Bird Cage</td></tr></tbody></table>';
Empty_Cities::flush_memo();
check( 'the shortcode prints a TablePress row: the page shows it, so it is not empty', ! Empty_Cities::page_shows_nothing( $tomb ) );
Empty_Cities::flush();
check( 'and it is not in the cached set either', ! in_array( 102, Empty_Cities::ids(), true ) && in_array( 106, Empty_Cities::ids(), true ) );
$GLOBALS['osn']['shortcodes'][ $tomb->post_content ] = '<table class="tablepress"><thead><tr><th>Name</th></tr></thead><tbody></tbody></table>';
Empty_Cities::flush_memo();
check( 'a table that prints a header and no row is empty', Empty_Cities::page_shows_nothing( $tomb ) );
$GLOBALS['osn']['shortcodes'][ $tomb->post_content ] = '<ul><li class="osn-card">x</li></ul>';
Empty_Cities::flush_memo();
check( 'a shortcode that prints a card is not empty, whatever the resolver said', ! Empty_Cities::page_shows_nothing( $tomb ) );
$GLOBALS['osn']['shortcodes'][ $tomb->post_content ] = '<p class="osn osn-empty-note">No patios listed here yet.</p>';
Empty_Cities::flush_memo();
check( 'the "No patios listed here yet" note is empty', Empty_Cities::page_shows_nothing( $tomb ) );
$GLOBALS['osn']['no_shortcodes'] = true; // A REST or admin request: TablePress has not registered [table].
Empty_Cities::flush_memo();
check( 'when nothing can render the shortcode, the page is not guessed empty', ! Empty_Cities::page_shows_nothing( $tomb ) );
$GLOBALS['osn']['no_shortcodes'] = false;
Empty_Cities::flush_memo();
Empty_Cities::page_shows_nothing( $tomb );
$n = count( $GLOBALS['osn']['calls']['do_shortcode'] );
Empty_Cities::page_shows_nothing( $tomb );
check( 'the shortcode is rendered once per request for a page', $n === count( $GLOBALS['osn']['calls']['do_shortcode'] ) );

echo "\n--- the venue-change routes ---\n";
ec_site();
Empty_Cities::ids();
$venue = osn_post( 5001, 'v', '' );
$venue->post_type = 'osn_venue';
$GLOBALS['osn']['obj_terms'][5001]['osn_city'] = array( $GLOBALS['osn']['terms'][2] );
OSN\City_Index::hooks();
$GLOBALS['osn']['query_posts'] = array( 5001 );
foreach ( $GLOBALS['actions']['transition_post_status'][10] as $cb ) { call_user_func( $cb, 'publish', 'draft', $venue ); }
check( 'publishing a venue in an empty city (wp-admin route) flushes the set', ! isset( $GLOBALS['osn']['transients']['osn_empty_cities_v1'] ) );
Empty_Cities::ids();
$GLOBALS['osn']['obj_terms'][5001]['osn_city'] = array( $GLOBALS['osn']['terms'][1] );
foreach ( $GLOBALS['actions']['transition_post_status'][10] as $cb ) { call_user_func( $cb, 'publish', 'draft', $venue ); }
check( 'a venue in a city that stays non-empty does not', isset( $GLOBALS['osn']['transients']['osn_empty_cities_v1'] ) );

echo "\n--- sitemap ---\n";
ec_site();
$a = Seo::sitemap_venue_args( array( 'post_type' => 'page' ), 'page' );
check( 'the empty IDs are excluded', array( 102, 106, 109 ) === $a['post__not_in'] );
check( 'the redirect clause is kept', 'NOT EXISTS' === $a['meta_query'][0]['compare'] && OSN\Hoods::REDIRECT_KEY === $a['meta_query'][0]['key'] );
$a = Seo::sitemap_venue_args( array( 'post__not_in' => array( 7, 102 ), 'meta_query' => array( array( 'key' => 'x' ) ) ), 'page' );
check( 'existing post__not_in values are kept, merged without duplicates', array( 7, 102, 106, 109 ) === $a['post__not_in'], json_encode( $a['post__not_in'] ) );
check( 'an existing meta_query is kept beside the redirect clause', 'AND' === $a['meta_query']['relation'] && 'x' === $a['meta_query'][0][0]['key'] );
ec_site();
Empty_Cities::$closed = function () { return array( 6 => 2, 2 => 0 ); };
$GLOBALS['osn']['terms'][2]->count = 3;
$GLOBALS['osn']['terms'][6]->count = 2;
$GLOBALS['osn']['terms'][9]->count = 1;
$a = Seo::sitemap_venue_args( array(), 'page' );
check( 'only what is still empty is excluded', array( 106 ) === $a['post__not_in'] );
$GLOBALS['osn']['terms'][6]->count = 5;
Empty_Cities::flush();
$a = Seo::sitemap_venue_args( array(), 'page' );
check( 'and none at all when nothing is empty', ! isset( $a['post__not_in'] ) );
$a = Seo::sitemap_venue_args( array( 'x' => 1 ), 'post' );
check( 'other post types are untouched', array( 'x' => 1 ) === $a );

echo "\n--- state page tiles ---\n";
ec_site();
State_Cities::$builder = function ( $code ) {
	return array(
		'phoenix-az' => array( 'term_id' => 1, 'count' => 12, 'top' => array(), 'photo' => 0 ),
		'tombstone-az' => array( 'term_id' => 2, 'count' => 0, 'top' => array(), 'photo' => 0 ),
	);
};
$GLOBALS['osn']['db_var'] = '2026-10-01';
$links = array(
	array( 'name' => 'Phoenix', 'key' => 'phoenix-az', 'url' => 'https://example.com/phoenix-az/' ),
	array( 'name' => 'Tombstone', 'key' => 'tombstone-az', 'url' => 'https://example.com/tombstone-az/' ),
	array( 'name' => 'Pine', 'key' => 'pine-az', 'url' => 'https://example.com/pine-az/' ),
	array( 'name' => 'Ghost Gulch', 'key' => 'ghost-gulch-az', 'url' => 'https://example.com/ghost-gulch-az/' ),
);
State_Cities::flush_memo();
$html = State_Cities::render( 'AZ', $links );
check( 'a city with venues has a tile', false !== strpos( $html, '<span class="osn-tile__name">Phoenix</span>' ) );
check( 'a city with no data row (no venue, or no page at all: a dead link) has no tile', false === strpos( $html, 'osn-tile__name">Pine<' ) );
check( 'an empty city has no tile', false === strpos( $html, 'osn-tile__name">Tombstone<' ) && false === strpos( $html, 'osn-tile__name">Ghost Gulch<' ) );
check( 'a letter group left with no tiles loses its heading', false === strpos( $html, '<h3 class="osn-az-letter">T</h3>' ) && false === strpos( $html, '<h3 class="osn-az-letter">G</h3>' ) );
check( '... and its jump link', false === strpos( $html, '-T">T</a>' ) && false === strpos( $html, '-G">G</a>' ) && false !== strpos( $html, '-P">P</a>' ) );
State_Cities::$builder = function ( $code ) { return array(); }; // The state build failed and there was no copy: no counts at all.
$GLOBALS['osn']['transients'] = array_filter( $GLOBALS['osn']['transients'], function ( $k ) { return 0 !== strpos( $k, 'osn_state_' ); }, ARRAY_FILTER_USE_KEY );
State_Cities::flush_memo();
$bare = State_Cities::render( 'AZ', $links );
check( 'with no state data at all, tiles are not all dropped: only the cached empty pages are', false !== strpos( $bare, 'osn-tile__name">Phoenix<' ) && false !== strpos( $bare, 'osn-tile__name">Pine<' ) && false === strpos( $bare, 'osn-tile__name">Tombstone<' ) );
check( 'the city search list still has the empty cities', false !== strpos( $html, 'Tombstone' ) && false !== strpos( $html, 'tombstone-az' ) );
check( 'the state page ends with the source note, once', 1 === substr_count( $html, 'osn-source-note' ) && false !== strpos( $html, 'Directory last updated October 1, 2026.' ) );

echo "\n--- homepage counts ---\n";
ec_site();
$tbl = '';
foreach ( array( 'phoenix-az' => 'Phoenix', 'tombstone-az' => 'Tombstone', 'ghost-gulch-az' => 'Ghost Gulch', 'pine-az' => 'Pine' ) as $k => $n ) {
	$tbl .= '<tr><td><a href="https://example.com/' . $k . '/">' . $n . '</a></td></tr>';
}
osn_post( 900, 'arizona', '<table>' . $tbl . '</table>' );
Home::flush();
$d = Home::data();
check( 'the state counts skip empty cities (4 links, 2 empty)', 2 === $d['counts']['AZ'], json_encode( $d['counts'] ) );
check( 'the city search list on the homepage is unchanged (all four)', 4 <= count( $d['cities'] ) );

finish();
