<?php
/**
 * DoS - Agents: the head meta for the older brokerage-summary city pages (kind "brokerages").
 *
 * Uses 12 real rendered samples (tests/fixtures-legacy/*.html, from the REST API's content.rendered).
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

function leg_page( $slug, $content, $title = 'in Somewhere XX – Best Real Estate Agents Near Me' ) {
	$p             = agents_add_page( $slug, $content );
	$p->post_title = $title;
	return $p;
}

function leg_view( $post ) {
	$GLOBALS['wp']['singular'] = true;
	$GLOBALS['wp']['post']     = $post;
	$GLOBALS['wp']['page']     = true;
	$GLOBALS['wp']['queried']  = $post->ID;
}

function leg_out() {
	ob_start();
	dos_agents_head_meta();
	$raw  = ob_get_clean();
	$tags = array( 'raw' => $raw );
	if ( preg_match_all( '/<meta (name|property)="([^"]+)" content="([^"]*)">/', $raw, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $t ) {
			$tags[ $t[2] ][] = html_entity_decode( $t[3], ENT_QUOTES, 'UTF-8' );
		}
	}
	return $tags;
}

function lv( $out, $k ) {
	return $out[ $k ][0] ?? null;
}

/** A minimal page with this heading text and $n brokerage entries. */
function leg_content( $heading, $n ) {
	$li = '';
	for ( $i = 1; $i <= $n; $i++ ) {
		$li .= '<li><strong>Broker ' . $i . ' (Sales: ' . ( 10 * $i ) . '; Rating: 4.5; Reviews: 12)</strong> – Clients highlight service.</li>' . "\n";
	}
	return '<h3 style="margin: 0;">' . $heading . '</h3>' . "\n<p>Intro.</p>\n<ul>\n" . $li . "</ul>\n";
}

$expect = array(
	'in-bancroft-wi'     => array( 'Bancroft', 'WI' ),
	'in-colwell-ia'      => array( 'Colwell', 'IA' ),
	'in-culloden-ga'     => array( 'Culloden', 'GA' ),
	'in-delray-beach-fl' => array( 'Delray Beach', 'FL' ),
	'in-falun-ks'        => array( 'Falun', 'KS' ),
	'in-gibsonia-pa'     => array( 'Gibsonia', 'PA' ),
	'in-holbrook-ny'     => array( 'Holbrook', 'NY' ),
	'in-magnolia-nc'     => array( 'Magnolia', 'NC' ),
	'in-paragonah-ut'    => array( 'Paragonah', 'UT' ),
	'in-pledger-tx'      => array( 'Pledger', 'TX' ),
	'in-tunnelton-wv'    => array( 'Tunnelton', 'WV' ),
	'in-walhalla-mi'     => array( 'Walhalla', 'MI' ),
);

echo "--- Real fixtures ---\n";
$real = array();
foreach ( $expect as $slug => list( $city, $st ) ) {
	agents_reset();
	$html = (string) file_get_contents( __DIR__ . '/fixtures-legacy/' . $slug . '.html' );
	$n    = substr_count( $html, '(Sales:' );
	$p    = leg_page( $slug, $html );
	leg_view( $p );
	$view = dos_agents_head_meta_view();
	$out  = leg_out();
	$d    = (string) lv( $out, 'description' );
	$b    = dos_agents_head_meta_brokerages( $p );
	check( "$slug: kind brokerages", is_array( $view ) && 'brokerages' === $view[0] );
	check( "$slug: city, state and count parsed ($city, $st, $n)", array( $city, $st, $n ) === $b, json_encode( $b ) );
	check( "$slug: description within 155 and names the city", dos_agents_head_len( $d ) <= 155 && false !== strpos( $d, "$city, $st" ), $d );
	if ( $n >= 2 ) {
		$want = "Compare $n real estate brokerages in $city, $st by sales, rating and what clients say about them.";
	} else {
		$want = "A real estate brokerage serving $city, $st: its sales, rating and what clients say about it.";
	}
	check( "$slug: description is the template for $n", $want === $d, $d );
	check( "$slug: og:title and twitter:title", "Real Estate Brokerages in $city, $st" === lv( $out, 'og:title' ) && lv( $out, 'og:title' ) === lv( $out, 'twitter:title' ) );
	check( "$slug: og:description matches, og:url is the page's own", $d === lv( $out, 'og:description' ) && "https://example.com/$slug/" === lv( $out, 'og:url' ) );
	$real[ $slug ] = array( $d, lv( $out, 'og:title' ) );
}
check( 'the single-entry page (tunnelton) has 2 li but 1 "(Sales:"', 1 === substr_count( (string) file_get_contents( __DIR__ . '/fixtures-legacy/in-tunnelton-wv.html' ), '(Sales:' ) && substr_count( (string) file_get_contents( __DIR__ . '/fixtures-legacy/in-tunnelton-wv.html' ), '<li' ) >= 2 );
echo '  tunnelton: ' . $real['in-tunnelton-wv'][0] . ' | ' . $real['in-tunnelton-wv'][1] . "\n";
echo '  delray: ' . $real['in-delray-beach-fl'][0] . ' | ' . $real['in-delray-beach-fl'][1] . "\n";

echo "\n--- Raw content without wpautop ---\n";
agents_reset();
$html = (string) file_get_contents( __DIR__ . '/fixtures-legacy/in-bancroft-wi.html' );
$raw  = str_replace( array( '</p>', '</li>', '</ul>' ), '', $html );
$raw  = preg_replace( '/\n+/', ' ', $raw );
$p    = leg_page( 'in-bancroft-wi', $raw );
leg_view( $p );
check( 'still brokerages, same city and count', array( 'Bancroft', 'WI', 5 ) === dos_agents_head_meta_brokerages( $p ) && 'brokerages' === dos_agents_head_meta_view()[0] );
agents_reset();
$p = leg_page( 'in-x-wi', "<h3>Top-Rated   Real Estate Brokerages in\n Bancroft ,  wi :  Client Reviews</h3><p>x" );
check( 'extra whitespace, newlines and lowercase state', array( 'Bancroft', 'WI', 0 ) === dos_agents_head_meta_brokerages( $p ) );
agents_reset();
$p = leg_page( 'in-x-wi', '<h3 class="a"><span>Top-Rated Real Estate Brokerages in <em>Bancroft</em>, WI:</span> Reviews</h3>' );
check( 'tags inside the h3', array( 'Bancroft', 'WI', 0 ) === dos_agents_head_meta_brokerages( $p ) );

echo "\n--- Broken slug ---\n";
agents_reset();
$p = leg_page( '__trashed-83', leg_content( 'Top-Rated Real Estate Brokerages in Coaling, AL: Client Reviews and Performance Summary', 3 ), 'in Coaling AL – Best Real Estate Agents Near Me' );
leg_view( $p );
$out = leg_out();
check( '__trashed-83 is detected from the heading', 'brokerages' === dos_agents_head_meta_view()[0] );
check( 'Coaling, AL description, og:title and its current URL', 'Compare 3 real estate brokerages in Coaling, AL by sales, rating and what clients say about them.' === lv( $out, 'description' ) && 'Real Estate Brokerages in Coaling, AL' === lv( $out, 'og:title' ) && 'https://example.com/__trashed-83/' === lv( $out, 'og:url' ), $out['raw'] );

echo "\n--- Counts ---\n";
agents_reset();
$p = leg_page( 'in-zero-ok', leg_content( 'Top-Rated Real Estate Brokerages in Zero, OK: Client Reviews and Performance Summary', 0 ) );
leg_view( $p );
check( 'heading with no entries', 'Real estate brokerages in Zero, OK: sales, ratings and what clients say.' === lv( leg_out(), 'description' ) );
$p = leg_page( 'in-one-ok', leg_content( 'Top-Rated Real Estate Brokerages in One, OK: Client Reviews', 1 ) );
leg_view( $p );
check( 'one entry', 'A real estate brokerage serving One, OK: its sales, rating and what clients say about it.' === lv( leg_out(), 'description' ) );

echo "\n--- Stays city, stays quiet ---\n";
agents_reset();
$p = leg_page( 'in-both-tx', '[breanm_agents city="both-tx"]' . leg_content( 'Top-Rated Real Estate Brokerages in Both, TX: Client Reviews', 3 ) );
leg_view( $p );
$out = leg_out();
check( 'a page with the heading AND [breanm_agents] is not brokerages', 'brokerages' !== dos_agents_head_meta_view()[0] && null === dos_agents_head_meta_brokerages( $p ) );
check( 'and gets no brokerage description', false === strpos( (string) lv( $out, 'description' ), 'brokerages' ), (string) lv( $out, 'description' ) );
agents_reset();
$p = leg_page( 'about', '<h3>About us</h3><p>We are an agency. Top-Rated service (Sales: many).</p>' );
leg_view( $p );
$out = leg_out();
check( 'an ordinary page: kind page, no description', 'page' === dos_agents_head_meta_view()[0] && ! isset( $out['description'] ) && ! isset( $out['og:description'] ), $out['raw'] );
$p = leg_page( 'tr', '<h3>Top-Rated Real Estate Brokerages in nowhere</h3>' );
check( 'a similar heading without "City, ST:" is not matched', null === dos_agents_head_meta_brokerages( $p ) );
$p = leg_page( 'tr2', '<p>Top-Rated Real Estate Brokerages in Boise, ID: not a heading</p>' );
check( 'the phrase outside an h3 is not matched', null === dos_agents_head_meta_brokerages( $p ) );

echo "\n--- Entities and escaping ---\n";
agents_reset();
$p = leg_page( 'in-ofallon-mo', leg_content( 'Top-Rated Real Estate Brokerages in O&#8217;Fallon, MO: Client Reviews', 2 ) );
leg_view( $p );
$out = leg_out();
check( 'curly apostrophe entity decodes once', "Compare 2 real estate brokerages in O\u{2019}Fallon, MO by sales, rating and what clients say about them." === lv( $out, 'description' ) && "Real Estate Brokerages in O\u{2019}Fallon, MO" === lv( $out, 'og:title' ), (string) lv( $out, 'description' ) );
$p = leg_page( 'in-ofallon2-mo', leg_content( "Top-Rated Real Estate Brokerages in O'Fallon, MO: Client Reviews", 2 ) );
leg_view( $p );
$out = leg_out();
check( 'a straight apostrophe is escaped in the markup and reads back as one', false !== strpos( $out['raw'], 'O&#039;Fallon, MO' ) && false === strpos( $out['raw'], '&amp;#039;' ) && 0 === strpos( (string) lv( $out, 'og:title' ), "Real Estate Brokerages in O'Fallon" ), $out['raw'] );
$p = leg_page( 'in-amp-xx', leg_content( 'Top-Rated Real Estate Brokerages in Bar &amp; Grill, TX: Client Reviews', 2 ) );
leg_view( $p );
$out = leg_out();
check( '&amp; in a city name decodes once and is escaped once', 'Real Estate Brokerages in Bar & Grill, TX' === lv( $out, 'og:title' ) && false !== strpos( $out['raw'], 'Bar &amp; Grill, TX' ) && false === strpos( $out['raw'], '&amp;amp;' ), $out['raw'] );
$p = leg_page( 'in-amp2-xx', leg_content( 'Top-Rated Real Estate Brokerages in Bar & Grill, TX: Client Reviews', 2 ) );
check( 'a bare & in the heading works too', array( 'Bar & Grill', 'TX', 2 ) === dos_agents_head_meta_brokerages( $p ) );

echo "\n--- Long city names and filters ---\n";
agents_reset();
$long = str_repeat( 'Very Long Place ', 4 ) . 'Springs';
$p    = leg_page( 'in-long-tx', leg_content( "Top-Rated Real Estate Brokerages in $long, TX: Client Reviews", 4 ) );
leg_view( $p );
$d = (string) lv( leg_out(), 'description' );
check( 'a long city falls back to the short sentence, within 155, not cut', dos_agents_head_len( $d ) <= 155 && "Compare 4 real estate brokerages in $long, TX." === $d, $d );
$GLOBALS['filters']['dos_agents_og_title'] = array( function ( $t ) { return 'brokerages:' . $t; } );
check( 'the og_title filter applies to this kind', 'brokerages:Real Estate Brokerages in ' . $long . ', TX' === lv( leg_out(), 'og:title' ) );
$GLOBALS['filters'] = array();

echo "\n--- Title: only the title part ---\n";
// Since 0.3.5 the title part of a hub or city page is its heading (tests/test-agents-title.php). The whole title is
// still never replaced, so WordPress keeps the separator and the site name.
check( 'head-meta never replaces the whole document title', false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/includes/head-meta.php' ), 'pre_get_document_title' ) );
agents_reset();
$p = leg_page( 'in-bancroft-wi', (string) file_get_contents( __DIR__ . '/fixtures-legacy/in-bancroft-wi.html' ) );
leg_view( $p );
$parts = dos_agents_head_document_title_parts( array( 'title' => 'in Bancroft WI', 'site' => 'Best Real Estate Agents Near Me' ) );
check( 'a brokerage page is titled by its heading, site name kept', array( 'title' => 'Real Estate Brokerages in Bancroft, WI', 'site' => 'Best Real Estate Agents Near Me' ) === $parts, json_encode( $parts ) );

finish();
