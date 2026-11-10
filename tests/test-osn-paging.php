<?php
/**
 * OSN\Paging: the page maths, the slices, the URLs, the numbered nav, the title and the requested page number.
 */

require __DIR__ . '/wp-stubs-osn.php';
osn_load();

use OSN\Paging;

function pg_set( $per ) { $GLOBALS['osn']['options']['osn_cards_per_page'] = $per; $GLOBALS['filters'] = array(); }
function pg_ids( $n ) { return $n ? range( 1001, 1000 + $n ) : array(); }

echo "--- per_page ---\n";
osn_reset();
check( 'unset option means 240', 240 === Paging::per_page() );
pg_set( 0 );
check( 'option 0 stays 0 (the rollback switch)', 0 === Paging::per_page() );
pg_set( -5 );
check( 'a negative option is clamped to 0', 0 === Paging::per_page() );
pg_set( '300' );
check( 'a string option is read as an integer', 300 === Paging::per_page() );
pg_set( 60 );
check( 'below the script\'s 120-card step is raised to 120 (a smaller page would fetch on every visit)', 120 === Paging::per_page() );
pg_set( 1 );
check( '1 is raised to 120, not treated as off', 120 === Paging::per_page() );
pg_set( 120 );
check( '120 itself is allowed', 120 === Paging::per_page() );
pg_set( 240 );
add_filter( 'osn_cards_per_page', function () { return 360; } );
check( 'the osn_cards_per_page filter wins over the option', 360 === Paging::per_page() );
$GLOBALS['filters'] = array();
add_filter( 'osn_cards_per_page', function () { return 50; } );
check( 'a filter cannot go below the floor either', 120 === Paging::per_page() );
$GLOBALS['filters'] = array();

echo "\n--- pages and slices ---\n";
pg_set( 240 );
foreach ( array( 0 => 1, 1 => 1, 240 => 1, 241 => 2, 480 => 2, 481 => 3, 2087 => 9 ) as $total => $want ) {
	check( "$total venues make $want page(s)", $want === Paging::pages( $total ), (string) Paging::pages( $total ) );
}
foreach ( array( 0, 1, 240, 241, 2087 ) as $total ) {
	$ids   = pg_ids( $total );
	$pages = Paging::pages( $total );
	$union = array();
	$sizes = array();
	for ( $p = 1; $p <= $pages; $p++ ) {
		$s       = Paging::slice( $ids, $p );
		$sizes[] = count( $s );
		$union   = array_merge( $union, $s );
	}
	check( "$total venues: the slices together are the input, in order, with no duplicates", $union === $ids && count( $union ) === count( array_unique( $union ) ), implode( ',', $sizes ) );
	check( "$total venues: every slice but the last is exactly 240", array_slice( $sizes, 0, -1 ) === array_fill( 0, max( 0, $pages - 1 ), 240 ), implode( ',', $sizes ) );
}
check( 'the last slice of 2,087 is 167 ids', 167 === count( Paging::slice( pg_ids( 2087 ), 9 ) ) );
check( 'a page past the end is an empty slice', array() === Paging::slice( pg_ids( 241 ), 3 ) );
check( 'page 0 or less reads as page 1', Paging::slice( pg_ids( 300 ), 0 ) === Paging::slice( pg_ids( 300 ), 1 ) );

pg_set( 0 );
check( 'per_page 0: one page, even for 2,087 venues', 1 === Paging::pages( 2087 ) );
check( 'per_page 0: the slice is everything', pg_ids( 2087 ) === Paging::slice( pg_ids( 2087 ), 1 ) );
pg_set( 240 );

echo "\n--- urls ---\n";
check( 'page 1 is the bare permalink', 'https://example.com/seattle-wa/' === Paging::url( 'https://example.com/seattle-wa/', 1 ) );
check( 'page 2 is /page/2/ under it', 'https://example.com/seattle-wa/page/2/' === Paging::url( 'https://example.com/seattle-wa/', 2 ) );
check( 'a permalink with no trailing slash still gets one', 'https://example.com/seattle-wa/page/9/' === Paging::url( 'https://example.com/seattle-wa', 9 ) );
check( 'a hierarchical page path keeps its parent', 'https://example.com/seattle-wa/ballard/page/3/' === Paging::url( 'https://example.com/seattle-wa/ballard/', 3 ) );

echo "\n--- version ---\n";
$v1 = Paging::version( array( 1, 2, 3 ) );
check( 'ten hex characters', 1 === preg_match( '/^[0-9a-f]{10}$/', $v1 ), $v1 );
check( 'same ids, same version', $v1 === Paging::version( array( 1, 2, 3 ) ) );
check( 'a different order is a different version (a re-rank busts the cache)', $v1 !== Paging::version( array( 1, 3, 2 ) ) );
check( 'a different list is a different version', $v1 !== Paging::version( array( 1, 2 ) ) );

echo "\n--- nav ---\n";
$nav = Paging::nav_html( 'https://example.com/seattle-wa/', 3, 9, 2087 );
check( 'the nav is a <nav class="osn-pages" data-osn-pages aria-label="Pages">', false !== strpos( $nav, '<nav class="osn-pages" data-osn-pages aria-label="Pages">' ) );
check( 'it says Page 3 of 9', false !== strpos( $nav, 'Page 3 of 9' ) );
check( 'it says which places the page holds (481 to 720 of 2,087)', false !== strpos( $nav, 'places 481 to 720 of 2,087' ), $nav );
preg_match_all( '/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/', $nav, $m );
$nums = array();
foreach ( $m[2] as $i => $text ) { if ( ctype_digit( $text ) ) { $nums[ (int) $text ] = $m[1][ $i ]; } }
check( 'every page number but the current one is a link', array( 1, 2, 4, 5, 6, 7, 8, 9 ) === array_keys( $nums ), implode( ',', array_keys( $nums ) ) );
check( 'page 1 links to the bare permalink', 'https://example.com/seattle-wa/' === $nums[1] );
check( 'page 5 links to /page/5/', 'https://example.com/seattle-wa/page/5/' === $nums[5] );
check( 'exactly one aria-current, and it is a span, not a link', 1 === substr_count( $nav, 'aria-current' ) && false !== strpos( $nav, '<span class="osn-pages__link is-current" aria-current="page">3</span>' ) );
check( 'no link points at the current page', ! in_array( 'https://example.com/seattle-wa/page/3/', $m[1], true ) );
check( 'Previous goes to page 2 and Next to page 4', in_array( 'https://example.com/seattle-wa/page/2/', $m[1], true ) && false !== strpos( $nav, 'Previous</a>' ) && false !== strpos( $nav, 'Next &rarr;</a>' ) );
check( 'there is no rel prev/next', false === strpos( $nav, 'rel=' ) );
$nav1 = Paging::nav_html( 'https://example.com/seattle-wa/', 1, 9, 2087 );
check( 'page 1 has no Previous', false === strpos( $nav1, 'Previous' ) && false !== strpos( $nav1, 'Next' ) );
$navN = Paging::nav_html( 'https://example.com/seattle-wa/', 9, 9, 2087 );
check( 'the last page has no Next, and Previous goes to page 8', false === strpos( $navN, 'Next' ) && false !== strpos( $navN, 'page/8/' ) );
check( 'one page means no nav at all', '' === Paging::nav_html( 'https://example.com/x/', 1, 1, 50 ) );

echo "\n--- title ---\n";
check( 'page number goes before the first " | "', 'Outdoor Seating in Seattle – Page 2 | Outdoor Seating Near Me' === Paging::title( 'Outdoor Seating in Seattle | Outdoor Seating Near Me', 2 ) );
check( 'only the first " | " is used', 'A – Page 3 | B | C' === Paging::title( 'A | B | C', 3 ) );
check( 'no " | ": it is appended', 'Outdoor Seating in Seattle – Page 4' === Paging::title( 'Outdoor Seating in Seattle', 4 ) );
check( 'page 1 is left alone', 'A | B' === Paging::title( 'A | B', 1 ) );
check( 'an empty title stays empty', '' === Paging::title( '', 2 ) );

echo "\n--- requested ---\n";
osn_world( 10 );
$city_page = $GLOBALS['osn']['posts'][500];
$other     = $GLOBALS['osn']['posts'][600];
osn_request( 500, 3 );
check( 'the paged number when the queried page is this post', 3 === Paging::requested( $city_page ) );
check( 'page 1 when the queried page is some other post (a [table] on another page is not paged by this URL)', 1 === Paging::requested( $other ) );
$GLOBALS['osn']['is_page'] = false;
check( 'page 1 when the request is not a page', 1 === Paging::requested( $city_page ) );
$GLOBALS['osn']['is_page'] = true;
$GLOBALS['osn']['paged']   = 0;
check( 'page 1 when the URL has no page number', 1 === Paging::requested( $city_page ) );
$GLOBALS['osn']['current'] = $other; // The loop is on a different post: the answer must not follow it.
$GLOBALS['osn']['paged']   = 2;
check( 'it does not depend on the loop: still page 2 for the queried post', 2 === Paging::requested( $city_page ) );
check( 'null (no post in the loop) is page 1', 1 === Paging::requested( null ) );

finish();
