<?php
/**
 * OSN\Util::title(): a venue's name as plain text for JSON-LD and map queries, where the display title's HTML
 * entities ("Ivar&#8217;s") are printed or sent as they are.
 */

require __DIR__ . '/wp-stubs-osn.php';
osn_load();

use OSN\Util;

function tt_venue( $id, $raw, $display, $address = '' ) {
	$p = osn_post( $id, 'venue-' . $id, '' );
	$p->post_title = $raw;
	$GLOBALS['osn']['posts'][ $id ]          = $p;
	$GLOBALS['osn']['titles'][ $id ]         = $display; // What get_the_title() returns: texturized.
	$GLOBALS['osn']['meta'][ $id ]['osn_address'] = $address;
}

echo "--- the name ---\n";
osn_reset();
tt_venue( 1, "Ivar's Acres of Clams", 'Ivar&#8217;s Acres of Clams', "Ivar's Acres of Clams, 1001 Alaskan Wy, Seattle, WA 98104" );
check( 'the stored name, not the texturized one', "Ivar's Acres of Clams" === Util::title( 1 ), Util::title( 1 ) );
tt_venue( 2, 'Ben &amp; Jerry&#039;s <b>Patio</b> ', 'x' );
check( 'entities in the stored name are decoded and tags dropped', "Ben & Jerry's Patio" === Util::title( 2 ), Util::title( 2 ) );
tt_venue( 3, 'Café Flora', 'Café Flora' );
check( 'UTF-8 is left alone', 'Café Flora' === Util::title( 3 ) );
$GLOBALS['osn']['titles'][9] = 'Joe&#8217;s';
check( 'with no stored title it falls back to the display title, decoded', "Joe\u{2019}s" === Util::title( 9 ), Util::title( 9 ) );

echo "\n--- map links ---\n";
$u = Util::directions_url( 1, 'Ivar&#8217;s Acres of Clams' ); // Callers hold the display title.
check( 'no entity in the query', false === strpos( $u, '%26%23' ), $u );
check( 'the name is not repeated when the address starts with it', 1 === substr_count( $u, 'Acres%20of%20Clams' ), $u );
check( 'the apostrophe is sent as an apostrophe', false !== strpos( $u, "query=Ivar%27s%20Acres" ), $u );
$e = Util::embed_url( 1, 'Ivar&#8217;s Acres of Clams' );
check( 'the embed query is the same text', false !== strpos( $e, "q=Ivar%27s%20Acres" ) && false === strpos( $e, '%26%23' ), $e );
tt_venue( 4, "Mama's Fish House", 'Mama&#8217;s Fish House', '799 Poho Pl, Paia, HI 96779' );
$u = Util::directions_url( 4, 'Mama&#8217;s Fish House' );
check( 'an address without the name gets the name in front', false !== strpos( $u, "query=Mama%27s%20Fish%20House%20799%20Poho" ), $u );

finish();
