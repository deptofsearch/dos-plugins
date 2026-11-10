<?php
/**
 * GET /osn/v1/cards: the on-demand parts of a grid. Part N is exactly numbered page N, the parts together are the
 * view, bad requests are 404s with their own codes, and the response is public and cacheable.
 */

require __DIR__ . '/wp-stubs-osn.php';
osn_load();

use OSN\Cards;
use OSN\Paging;
use OSN\Rest;

function rc_get( $post, $part, $v = 'whatever' ) { return Cards::rest_part( new WP_REST_Request( array( 'post' => $post, 'part' => $part, 'v' => $v ) ) ); }
function rc_card_count( $html ) { return substr_count( $html, 'data-osn-card' ); }

echo "--- the route ---\n";
osn_world( 600 );
Rest::register_routes();
$route = $GLOBALS['osn']['routes']['osn/v1/cards'] ?? null;
check( '/osn/v1/cards is registered', null !== $route );
check( 'it is GET only', 'GET' === $route['methods'] );
check( 'it is public', '__return_true' === $route['permission_callback'] );
check( 'its callback is Cards::rest_part', array( Cards::class, 'rest_part' ) === $route['callback'] );
check( 'post is required and part defaults to 1', true === $route['args']['post']['required'] && 1 === $route['args']['part']['default'] );

echo "\n--- parts are the view ---\n";
$view = Cards::view( $GLOBALS['osn']['terms'][10] );
$all  = array();
$ok   = true;
for ( $p = 1; $p <= 3; $p++ ) {
	$r = rc_get( 500, $p );
	$ok = $ok && $r instanceof WP_REST_Response;
	$all = array_merge( $all, $r->data['ids'] );
	check( "part $p: html has one data-osn-card per id (" . count( $r->data['ids'] ) . ')', rc_card_count( $r->data['html'] ) === count( $r->data['ids'] ) );
}
check( 'the union of every part\'s ids is view()[ids], in order', $all === $view['ids'] && 600 === count( $all ) );
$r2 = rc_get( 500, 2 );
check( 'part 2 has per (240) cards in its html', 240 === rc_card_count( $r2->data['html'] ) && 240 === $r2->data['per'] );
check( 'part 3 has the remaining 120', 120 === rc_card_count( rc_get( 500, 3 )->data['html'] ) );
check( 'the response shape is part, parts, total, per, ids, html', array( 'part', 'parts', 'total', 'per', 'ids', 'html' ) === array_keys( $r2->data ) );
check( 'part, parts and total are right', 2 === $r2->data['part'] && 3 === $r2->data['parts'] && 600 === $r2->data['total'] );
check( 'part N is the same slice as numbered page N', $r2->data['ids'] === Paging::slice( $view['ids'], 2 ) );
$page2 = Cards::render( 'Testville, TX', 0, true, true, null, null, 2 );
$from  = strpos( $page2, '<li class="osn-card"' );
$grid  = substr( $page2, $from, strpos( $page2, '<p class="osn-more-wrap"' ) - $from );
$grid  = trim( substr( trim( $grid ), 0, -strlen( '</ul>' ) ) );
check( 'the part\'s cards are byte-for-byte the cards page 2 prints (same card(), same slice)', $grid === trim( $r2->data['html'] ), strlen( $grid ) . ' vs ' . strlen( $r2->data['html'] ) );
check( 'the Cache-Control header is public, max-age=300', 'public, max-age=300' === $r2->headers['Cache-Control'] );
check( 'v is ignored by the server: another value gives the same part', rc_get( 500, 2, 'zzzzzzzzzz' )->data === $r2->data );

echo "\n--- bad requests ---\n";
$e = rc_get( 999, 1 );
check( 'an unknown post is a 404 osn_no_grid', $e instanceof WP_Error && 'osn_no_grid' === $e->code && 404 === $e->data['status'] );
$e = rc_get( 0, 1 );
check( 'post 0 is a 404 (it must not fall back to the global post)', $e instanceof WP_Error && 'osn_no_grid' === $e->code );
osn_post( 700, 'about', 'No table here.' );
$e = rc_get( 700, 1 );
check( 'a page that is not a grid is a 404 osn_no_grid', $e instanceof WP_Error && 'osn_no_grid' === $e->code );
$GLOBALS['osn']['posts'][500]->post_status = 'draft';
$e = rc_get( 500, 1 );
check( 'an unpublished page is a 404 osn_no_grid', $e instanceof WP_Error && 'osn_no_grid' === $e->code );
$GLOBALS['osn']['posts'][500]->post_status = 'publish';
$GLOBALS['osn']['posts'][500]->post_type = 'osn_venue';
check( 'a post that is not a page is a 404 osn_no_grid', rc_get( 500, 1 ) instanceof WP_Error );
$GLOBALS['osn']['posts'][500]->post_type = 'page';
foreach ( array( 0, 4, -1, 99 ) as $bad ) {
	$e = rc_get( 500, $bad );
	check( "part $bad of 3 is a 404 osn_no_part", $e instanceof WP_Error && 'osn_no_part' === $e->code && 404 === $e->data['status'] );
}
$GLOBALS['osn']['options']['osn_takeover_cities'] = 'some-other-city';
\OSN\Takeover::flush_memo();
check( 'a city whose takeover is off is not a grid', rc_get( 500, 1 ) instanceof WP_Error );
$GLOBALS['osn']['options']['osn_takeover_cities'] = '*';
\OSN\Takeover::flush_memo();

echo "\n--- a grid that fits on one page ---\n";
osn_world( 100 );
$r = rc_get( 500, 1 );
check( 'part 1 of a one-page grid is all 100', 1 === $r->data['parts'] && 100 === rc_card_count( $r->data['html'] ) );
check( 'part 2 of a one-page grid is a 404 osn_no_part', 'osn_no_part' === rc_get( 500, 2 )->code );

echo "\n--- paging off ---\n";
osn_world( 600 );
$GLOBALS['osn']['options']['osn_cards_per_page'] = 0;
$r = rc_get( 500, 1 );
check( 'paging off: no parts to hand out, a 404 osn_no_grid (not the whole city in one response)', $r instanceof WP_Error && 'osn_no_grid' === $r->code );

echo "\n--- a password-protected page ---\n";
osn_world( 600 );
$GLOBALS['osn']['posts'][500]->post_password = 'secret';
$r = rc_get( 500, 1 );
check( 'a protected page is a 404 osn_no_grid', $r instanceof WP_Error && 'osn_no_grid' === $r->code );

echo "\n--- a neighborhood grid ---\n";
osn_world( 1200 );
$GLOBALS['osn']['term_meta'][10]['osn_landing_page_id'] = 500;
$hood_view = Cards::view( $GLOBALS['osn']['terms'][10], $GLOBALS['osn']['terms'][21] );
$hood_page = Cards::render( 'Testville, TX', 0, true, true, $GLOBALS['osn']['terms'][21], null, 1 );
$r1 = rc_get( 600, 1 );
$r2 = rc_get( 600, 2 );
check( 'the hood grid has 300 venues in 2 parts', 300 === $r1->data['total'] && 2 === $r1->data['parts'] && 240 === count( $r1->data['ids'] ) && 60 === count( $r2->data['ids'] ) );
check( 'its parts are the hood view\'s ids, in order', array_merge( $r1->data['ids'], $r2->data['ids'] ) === $hood_view['ids'] );
check( 'the hood view cards are printed without the hood name (show_hood false), same as the page', false === strpos( $r1->data['html'], 'osn-card__hood' ) );
$city_part = rc_get( 500, 1 );
check( 'the city view\'s cards do show it (show_hood true)', false !== strpos( $city_part->data['html'], 'osn-card__hood' ) );

finish();
