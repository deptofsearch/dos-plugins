<?php
/**
 * OSN\Cards::render() with numbered pages, the paged 404, and the tail of the view: counts and chips cover the
 * whole view on every page, the cards are one slice, the browse lists and ItemList are page 1 only, and with
 * paging off the grid is the one-page grid it always was.
 */

require __DIR__ . '/wp-stubs-osn.php';
osn_load();

use OSN\Cards;
use OSN\Paging;
use OSN\Takeover;

function cp_cards( $html ) { return preg_match_all( '/<li class="osn-card" data-osn-card data-id="(\d+)"/', $html, $m ) ? array_map( 'intval', $m[1] ) : array(); }
function cp_chips( $html ) {
	preg_match_all( '/data-osn-chip="([^"]+)"[^>]*>[^<]*<span class="osn-chip__count">(\d+)<\/span>/', $html, $m );
	return array_combine( $m[1], array_map( 'intval', $m[2] ) );
}
function cp_ld( $html ) { return preg_match( '/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m ) ? json_decode( $m[1], true ) : null; }
function cp_page( $n, $paged ) { osn_request( 500, $paged ); return Cards::render( 'Testville, TX', 0, true, true, null, null, $paged ); }
/** The grid id and the results anchor carry a per-request counter; strip it so two renders compare. */
function cp_norm( $html ) { return preg_replace( '/osn-(grid|results)-\d+/', 'osn-$1-N', $html ); }

echo "--- 600 venues, 240 a page: three pages ---\n";
$ids = osn_world( 600 );
$p1  = cp_page( 500, 1 );
$p2  = cp_page( 500, 2 );
$p3  = cp_page( 500, 3 );

check( 'page 1 has 240 cards', 240 === count( cp_cards( $p1 ) ) );
check( 'page 2 has 240 cards', 240 === count( cp_cards( $p2 ) ) );
check( 'page 3 has the remaining 120', 120 === count( cp_cards( $p3 ) ) );
check( 'the cards of the three pages are the ranked list, in order, once each', array_merge( cp_cards( $p1 ), cp_cards( $p2 ), cp_cards( $p3 ) ) === $ids );
check( 'every card carries data-id (the de-duplication key for loaded parts)', 0 === substr_count( $p1, 'data-osn-card ' ) - count( cp_cards( $p1 ) ) );
$chips = cp_chips( $p1 );
check( 'chip counts are over the whole view, not the page (600 / 200 / 120)', array( 'outdoor-seating' => 600, 'dog-friendly' => 200, 'brunch' => 120 ) === $chips, json_encode( $chips ) );
check( 'chip counts are identical on every page', $chips === cp_chips( $p2 ) && $chips === cp_chips( $p3 ) );
check( 'the count line is the whole-view total on every page', 3 === count( array_filter( array( $p1, $p2, $p3 ), function ( $h ) { return false !== strpos( $h, 'role="status" aria-live="polite">600 places</p>' ); } ) ) );
check( 'the neighborhood chip row says All (600) on every page', 3 === count( array_filter( array( $p1, $p2, $p3 ), function ( $h ) { return false !== strpos( $h, 'data-osn-district="">All <span class="osn-chip__count">600</span>' ); } ) ) );

echo "\n--- what is on page 1 only ---\n";
check( 'the ItemList is on page 1', null !== cp_ld( $p1 ) && 'ItemList' === cp_ld( $p1 )['@type'] );
check( 'the ItemList is not on page 2 or 3', null === cp_ld( $p2 ) && null === cp_ld( $p3 ) );
check( 'the ItemList has the top 100 (osn_itemlist_max)', 100 === count( cp_ld( $p1 )['itemListElement'] ) && 1 === cp_ld( $p1 )['itemListElement'][0]['position'] );
add_filter( 'osn_itemlist_max', function () { return 500; } );
check( 'an osn_itemlist_max above the page size is clamped to the slice (240)', 240 === count( cp_ld( cp_page( 500, 1 ) )['itemListElement'] ) );
$GLOBALS['filters'] = array();
check( 'Browse by neighborhood is on page 1', false !== strpos( $p1, 'id="hoods"' ) );
check( 'Browse by neighborhood is not on page 2 or 3', false === strpos( $p2, 'id="hoods"' ) && false === strpos( $p3, 'id="hoods"' ) );

echo "\n--- root attributes and the nav ---\n";
check( 'page 2 root carries data-total, data-page, data-pages and data-per', false !== strpos( $p2, ' data-osn-grid data-total="600" data-page="2" data-pages="3" data-per="240" data-src="' ), substr( $p2, 0, 260 ) );
preg_match( '/data-src="([^"]+)"/', $p2, $m );
$src = html_entity_decode( $m[1] );
check( 'data-src is the REST route with the page id and the version, ready for &part=N', 'https://example.com/wp-json/osn/v1/cards?post=500&v=' . Paging::version( $ids ) === $src, $src );
check( 'the nav is on every page, with the right current page', false !== strpos( $p1, '<span class="osn-pages__link is-current" aria-current="page">1</span>' ) && false !== strpos( $p3, '<span class="osn-pages__link is-current" aria-current="page">3</span>' ) );
check( 'the nav links page 2 as /testville-tx/page/2/ and page 1 as the bare permalink', false !== strpos( $p3, 'href="https://example.com/testville-tx/page/2/"' ) && false !== strpos( $p3, 'href="https://example.com/testville-tx/"' ) );

echo "\n--- one page: nothing paged ---\n";
osn_world( 100 );
$one = cp_page( 500, 1 );
check( '100 venues: all 100 cards', 100 === count( cp_cards( $one ) ) );
check( '100 venues: no data-src, data-total, data-pages or nav', false === strpos( $one, 'data-src' ) && false === strpos( $one, 'data-total' ) && false === strpos( $one, 'data-pages' ) && false === strpos( $one, 'osn-pages' ) );
check( '100 venues: the ItemList and browse list are there', null !== cp_ld( $one ) && false !== strpos( $one, 'id="hoods"' ) );

echo "\n--- paging off (osn_cards_per_page 0) ---\n";
osn_world( 600 );
$GLOBALS['osn']['options']['osn_cards_per_page'] = 0;
$off = cp_page( 500, 1 );
check( 'all 600 cards on the one page', 600 === count( cp_cards( $off ) ) );
check( 'no nav, no data-src, no data-total', false === strpos( $off, 'osn-pages' ) && false === strpos( $off, 'data-src' ) && false === strpos( $off, 'data-total' ) );
check( 'the ItemList is still the top 100', 100 === count( cp_ld( $off )['itemListElement'] ) );
check( 'the page-0 call (what [osn_venues] makes) is byte-identical to page 1 with paging off', cp_norm( $off ) === cp_norm( Cards::render( 'Testville, TX' ) ) );
check( 'the root div is exactly <div class="osn osn-venues" id="..." data-osn-grid>', 1 === preg_match( '/<div class="osn osn-venues" id="osn-grid-\d+" data-osn-grid>/', $off ) );

echo "\n--- [osn_venues] is not paged ---\n";
osn_world( 600 );
osn_request( 500, 2 ); // Even on /page/2/ of a page that carries the shortcode.
$sc = Cards::shortcode( array( 'city' => 'Testville, TX' ) );
check( 'the shortcode renders every card', 600 === count( cp_cards( $sc ) ) );
check( 'the shortcode has no nav and no data-src', false === strpos( $sc, 'osn-pages' ) && false === strpos( $sc, 'data-src' ) );

echo "\n--- a past-the-end page number still renders safely ---\n";
osn_world( 300 );
$past = cp_page( 500, 9 );
check( 'page 9 of a 2-page grid renders the last page rather than an empty grid', 60 === count( cp_cards( $past ) ) );

echo "\n--- priming: posts and meta for the slice, terms for the view ---\n";
osn_world( 600 );
osn_request( 500, 2 );
Cards::render( 'Testville, TX', 0, true, true, null, null, 2 );
$pc = $GLOBALS['osn']['calls']['post_cache'] ?? array();
check( 'posts and meta are primed for the 240 of the page', in_array( array( 240, true ), $pc, true ), json_encode( $pc ) );
check( 'posts are never primed for all 600', ! in_array( 600, array_column( $pc, 0 ), true ), json_encode( $pc ) );
check( 'the term cache is loaded for all 600 ids', in_array( 600, $GLOBALS['osn']['calls']['term_cache'] ?? array(), true ) );
check( 'no landmarks: meta is not primed for all ids', ! isset( $GLOBALS['osn']['calls']['meta_cache'] ) );
$GLOBALS['osn']['taxonomies'][] = 'osn_landmark';
osn_term( 30, 'On the Strip', 'testville-tx-on-strip', 'osn_landmark', 0, array( 'osn_city' => 'Testville, TX' ) );
Cards::flush_memo(); // The landmark taxonomy appears mid-script: a new request in real life.
osn_request( 500, 2 );
Cards::render( 'Testville, TX', 0, true, true, null, null, 2 );
check( 'a city with landmarks primes meta for every id (the casino lookups read it)', in_array( 600, $GLOBALS['osn']['calls']['meta_cache'] ?? array(), true ), json_encode( $GLOBALS['osn']['calls'] ) );

echo "\n--- a neighborhood view pages too, and keeps its nearby / back links on every page ---\n";
osn_world( 1200 ); // The hood holds every 4th venue: 300 of them, so two pages.
$GLOBALS['osn']['term_meta'][10]['osn_landing_page_id'] = 500;
$hood = $GLOBALS['osn']['terms'][21];
osn_request( 600, 1 );
$h1 = Cards::render( 'Testville, TX', 0, true, true, $hood, null, 1 );
osn_request( 600, 2 );
$h2 = Cards::render( 'Testville, TX', 0, true, true, $hood, null, 2 );
check( 'hood page 1 has 240 cards, page 2 the other 60', 240 === count( cp_cards( $h1 ) ) && 60 === count( cp_cards( $h2 ) ) );
check( 'every card in the hood view is a hood venue (id divisible by 4)', 0 === count( array_filter( array_merge( cp_cards( $h1 ), cp_cards( $h2 ) ), function ( $id ) { return 0 !== ( $id - 1000 ) % 4; } ) ) );
check( 'the hood count line is 300 on both pages', false !== strpos( $h1, '>300 places</p>' ) && false !== strpos( $h2, '>300 places</p>' ) );
check( 'the back-to-city link is on page 2 as well as page 1', false !== strpos( $h1, 'osn-hoods__back' ) && false !== strpos( $h2, 'osn-hoods__back' ) );
check( 'the hood view has no ItemList on page 2', null !== cp_ld( $h1 ) && null === cp_ld( $h2 ) );
check( 'the hood view root says 300 total, 2 pages', false !== strpos( $h2, 'data-total="300" data-page="2" data-pages="2"' ) );

echo "\n--- the paged 404 ---\n";
osn_world( 600 );
osn_request( 500, 3 );
Paging::maybe_404();
check( 'page 3 of 3 is not a 404', ! $GLOBALS['wp_query']->is_404 && null === $GLOBALS['osn']['status'] );
osn_request( 500, 4 );
Paging::maybe_404();
check( 'page 4 of 3 sets the 404 on the main query', $GLOBALS['wp_query']->is_404 );
check( '... sends a 404 status and no-cache headers', 404 === $GLOBALS['osn']['status'] && $GLOBALS['osn']['nocache'] );
osn_request( 500, 0 );
Paging::maybe_404();
check( 'no page number in the URL is never a 404', ! $GLOBALS['wp_query']->is_404 );
osn_world( 100 );
osn_request( 500, 2 );
Paging::maybe_404();
check( 'page 2 of a grid that fits on one page is a 404', $GLOBALS['wp_query']->is_404 && 404 === $GLOBALS['osn']['status'] );
check( 'the 404 stops core guessing a permalink from the slug (it would 301 to a similar page)', false === apply_filters( 'do_redirect_guess_404_permalink', true ) );
$GLOBALS['filters'] = array();
osn_world( 600 );
osn_request( 500, 3 );
Paging::maybe_404();
check( 'a page that exists leaves core\'s permalink guessing alone', true === apply_filters( 'do_redirect_guess_404_permalink', true ) );

echo "\n--- the rollback switch turns the 404 off too ---\n";
osn_world( 600 );
$GLOBALS['osn']['options']['osn_cards_per_page'] = 0;
osn_request( 500, 9 );
Paging::maybe_404();
check( 'paging off: /page/9/ is not a 404 (it answers as it did before 0.6.12)', ! $GLOBALS['wp_query']->is_404 && null === $GLOBALS['osn']['status'] );
check( 'paging off: core\'s permalink guessing is untouched', true === apply_filters( 'do_redirect_guess_404_permalink', true ) );

echo "\n--- the canonical link for a numbered page drops the query string ---\n";
osn_world( 600 );
osn_request( 500, 2 );
$perma = get_permalink( 500 );
check( 'page 2 of a grid: clean /page/2/ whatever core built', Paging::url( $perma, 2 ) === Paging::pagenum_link( $perma . 'page/2/?utm_source=x&district=downtown', 2 ) );
check( 'no page number passed: the requested page is used', Paging::url( $perma, 2 ) === Paging::pagenum_link( $perma . 'page/2/?x=1' ) );
check( 'page 1 is the bare permalink', $perma === Paging::pagenum_link( $perma . '?x=1', 1 ) );
$GLOBALS['osn']['options']['osn_cards_per_page'] = 0;
check( 'paging off: the link is left as core built it', 'https://example.com/a/?x=1' === Paging::pagenum_link( 'https://example.com/a/?x=1', 2 ) );
osn_world( 600 );
osn_post( 700, 'about', '<p>No grid here.</p>' );
osn_request( 700, 2 );
check( 'a page with no grid: the link is left as core built it', 'https://example.com/about/page/2/?x=1' === Paging::pagenum_link( 'https://example.com/about/page/2/?x=1', 2 ) );

echo "\n--- end to end: the takeover prints the page the URL asked for ---\n";
osn_world( 600 );
osn_request( 500, 2 );
$via = \OSN\Takeover::maybe_take_over( false, 'table', array( 'id' => '1', 'filter' => 'Testville, TX' ), array() );
$ids2 = array_slice( $GLOBALS['osn']['transients']['osn_sorted2_10'], 240, 240 );
check( '/page/2/ through the shortcode takeover prints 240 cards', is_string( $via ) && 240 === count( cp_cards( $via ) ) );
check( '... and they are the second 240 of the ranked list, not page 1 again', is_string( $via ) && false !== strpos( $via, 'data-id="' . $ids2[0] . '"' ) && false === strpos( $via, 'data-id="' . $GLOBALS['osn']['transients']['osn_sorted2_10'][0] . '"' ) );
osn_request( 500, 0 );
$via1 = \OSN\Takeover::maybe_take_over( false, 'table', array( 'id' => '1', 'filter' => 'Testville, TX' ), array() );
check( 'no page number: the takeover prints page 1', is_string( $via1 ) && false !== strpos( $via1, 'data-id="' . $GLOBALS['osn']['transients']['osn_sorted2_10'][0] . '"' ) && false !== strpos( $via1, 'data-page="1"' ) );
osn_world( 1200 );
osn_request( 600, 3 );
Paging::maybe_404();
check( 'a hood grid pages by its own count: page 3 of 2 is a 404', $GLOBALS['wp_query']->is_404 );
osn_request( 600, 2 );
Paging::maybe_404();
check( '... and page 2 of 2 is not', ! $GLOBALS['wp_query']->is_404 );
osn_world( 100 );
osn_post( 700, 'about', 'Just some words, no table.' );
osn_request( 700, 5 );
Paging::maybe_404();
check( 'a page that is not a takeover grid is left untouched (no 404)', ! $GLOBALS['wp_query']->is_404 && null === $GLOBALS['osn']['status'] );
osn_post( 701, 'other-table', '[table id=9 filter="Nowhere, ZZ"]' );
osn_request( 701, 5 );
Paging::maybe_404();
check( 'a [table] for a city the takeover does not know is left untouched too', ! $GLOBALS['wp_query']->is_404 );
osn_world( 100 );
$GLOBALS['osn']['options']['osn_takeover_cities'] = 'some-other-city';
osn_request( 500, 5 );
Paging::maybe_404();
check( 'a city page whose takeover is off is left untouched (TablePress still renders it)', ! $GLOBALS['wp_query']->is_404 );

echo "\n--- Takeover::context ---\n";
osn_world( 100 );
$ctx = Takeover::context( $GLOBALS['osn']['posts'][500] );
check( 'a city page resolves to { city, hood null, spot null }', $ctx && 'Testville, TX' === $ctx['city']->name && null === $ctx['hood'] && null === $ctx['spot'] );
$ctx = Takeover::context( $GLOBALS['osn']['posts'][600] );
check( 'a hood page resolves to the hood term and its city', $ctx && 'Old Town' === $ctx['hood']->name && 'Testville, TX' === $ctx['city']->name );
check( 'a page with no [table] resolves to null', null === Takeover::context( osn_post( 702, 'plain', 'hello' ) ) );

finish();
