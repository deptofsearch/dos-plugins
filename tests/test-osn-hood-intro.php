<?php
/**
 * OSN\City_Intro on neighborhood and district pages (0.6.20): a page with no text of its own gets an opening
 * paragraph written from its listings; anything else is left alone. City pages are covered by test-osn-city-intro.php.
 */

require __DIR__ . '/wp-stubs-osn.php';
osn_load();

use OSN\City_Intro;

const HI_TAIL = 'Each place has its own page with the address and directions.';

/**
 * Testville with $n venues. Districts: Downtown (20) holds hoods Old Town (21, page 600) and Riverside (23, page 620);
 * Uptown (22, page 610) is a district with one hood, Hilltop (24, page 630). $spec(k) = array( 'hood' => term id,
 * 'cat' => name, 'am' => slugs ) for venue k (1-based).
 */
function hi_world( $n, $spec ) {
	osn_world( $n );
	$o = &$GLOBALS['osn'];
	$o['taxonomies'][] = 'osn_amenity';
	$o['taxonomies'][] = 'osn_category';
	osn_term( 22, 'Uptown', 'testville-tx-uptown', 'osn_hood', 0, array( 'osn_city' => 'Testville, TX', 'osn_page_id' => 610 ) );
	osn_term( 20, 'Downtown', 'testville-tx-downtown', 'osn_hood', 0, array( 'osn_city' => 'Testville, TX', 'osn_page_id' => 650 ) );
	osn_term( 23, 'Riverside', 'testville-tx-riverside', 'osn_hood', 20, array( 'osn_city' => 'Testville, TX', 'osn_page_id' => 620 ) );
	osn_term( 24, 'Hilltop', 'testville-tx-hilltop', 'osn_hood', 22, array( 'osn_city' => 'Testville, TX', 'osn_page_id' => 630 ) );
	osn_post( 650, 'downtown', '[table id=3 filter="Downtown, Testville"]', 500 );
	osn_post( 610, 'uptown', '[table id=4 filter="Uptown, Testville"]', 500 );
	osn_post( 620, 'riverside', '[table id=5 filter="Riverside, Testville"]', 500 );
	osn_post( 630, 'hilltop', '[table id=6 filter="Hilltop, Testville"]', 500 );
	$terms = array();
	$tid   = 300;
	for ( $k = 1; $k <= $n; $k++ ) {
		$id = 1000 + $k;
		unset( $o['obj_terms'][ $id ] );
		$s  = $spec( $k );
		$am = $s['am'] ?? array( 'outdoor-seating' );
		$o['obj_terms'][ $id ]['osn_amenity'] = array();
		foreach ( $am as $slug ) {
			if ( ! isset( $terms[ 'a' . $slug ] ) ) {
				$t = new WP_Term();
				$t->term_id = ++$tid; $t->slug = $slug; $t->name = ucwords( str_replace( '-', ' ', $slug ) ); $t->taxonomy = 'osn_amenity';
				$terms[ 'a' . $slug ] = $t;
			}
			$o['obj_terms'][ $id ]['osn_amenity'][] = $terms[ 'a' . $slug ];
		}
		if ( ! empty( $s['cat'] ) ) {
			if ( ! isset( $terms[ 'c' . $s['cat'] ] ) ) {
				$t = new WP_Term();
				$t->term_id = ++$tid; $t->slug = sanitize_title( $s['cat'] ); $t->name = $s['cat']; $t->taxonomy = 'osn_category';
				$terms[ 'c' . $s['cat'] ] = $t;
			}
			$o['obj_terms'][ $id ]['osn_category'] = array( $terms[ 'c' . $s['cat'] ] );
		}
		if ( ! empty( $s['hood'] ) ) {
			$o['obj_terms'][ $id ]['osn_hood'] = array( $o['terms'][ $s['hood'] ] );
		}
		$o['titles'][ $id ] = 'Place ' . $k;
	}
}

/** Run the filter on page $page with $content stored as its text. */
function hi_run( $content, $page = 600 ) {
	$GLOBALS['osn']['posts'][ $page ]->post_content = $content;
	OSN\Takeover::flush_memo();
	osn_request( $page );
	return City_Intro::filter( $content );
}

$tbl = '[table id=2 filter="Old Town, Testville"]';

// 12 venues: Old Town 1-5, Riverside 6-8 (both in Downtown), Hilltop 9-10 (in Uptown), 11-12 in no hood.
function hi_spec_big( $k ) {
	$hood = $k <= 5 ? 21 : ( $k <= 8 ? 23 : ( $k <= 10 ? 24 : 0 ) );
	$cat  = array( 1 => 'Bar', 2 => 'Bar', 3 => 'Bar', 4 => 'Cafe', 5 => 'Cafe', 6 => 'Bar', 7 => 'Cafe', 8 => 'Brewery' );
	$am   = array( 'outdoor-seating' );
	if ( $k <= 4 ) { $am[] = 'happy-hour'; }
	if ( in_array( $k, array( 2, 3, 5 ), true ) ) { $am[] = 'dog-friendly'; }
	return array( 'hood' => $hood, 'cat' => $cat[ $k ] ?? 'Diner', 'am' => $am );
}

echo "--- neighborhood page ---\n";
hi_world( 12, 'hi_spec_big' );
$out  = hi_run( $tbl );
$want = '<p class="osn-intro">Old Town has 5 places with outdoor seating listed here. Most common: Bar (3) and Cafe (2). 4 have a happy hour and 3 are dog friendly. ' . HI_TAIL . '</p>' . "\n" . $tbl;
check( 'a page with only the table gets the intro prepended, stored content after it', $want === $out, $out );
echo "  EXAMPLE neighborhood: " . strstr( $out, "\n", true ) . "\n";
check( 'no area sentence on a neighborhood page', false === strpos( $out, 'By area' ) && false === strpos( $out, 'By neighborhood' ), $out );
check( 'no state or closing line', false === strpos( $out, 'osn-intro--more' ) && false === strpos( $out, 'More places nearby' ), $out );
check( 'whitespace, entities and an empty paragraph around the table still count as no text', 0 === strpos( hi_run( "\n<p>&nbsp;</p>\n<p style=\"text-align: center;\">" . $tbl . "</p>\r\n" ), '<p class="osn-intro">' ), '' );
check( 'an HTML comment alone is no text', 0 === strpos( hi_run( '<!-- wp:shortcode -->' . $tbl . '<!-- /wp:shortcode -->' ), '<p class="osn-intro">' ), '' );
check( 'applied once: our own output is not prefixed again', hi_run( hi_run( $tbl ) ) === hi_run( $tbl ) );

echo "\n--- the name comes from the page's filter ---\n";
$out = hi_run( '[table id=2 filter="Olde Towne, Testville"]' );
check( 'the label is the name in the filter (what the H1 prints)', 0 === strpos( $out, '<p class="osn-intro">Olde Towne has 5 places' ), $out );
$out = hi_run( '[table id=2 filter="Old Town &amp; Co, Testville"]' );
check( 'an entity in the filter is decoded then escaped once', 0 === strpos( $out, '<p class="osn-intro">Old Town &amp; Co has 5 places' ), $out );

echo "\n--- district page ---\n";
$out = hi_run( '[table id=3 filter="Downtown, Testville"]', 650 );
check( 'district page: hoods ranked, no city-style "By area"', false !== strpos( $out, ' By neighborhood, Old Town (5) and Riverside (3) have the most. ' ) && false === strpos( $out, 'By area' ), $out );
check( 'district text covers all 8 of its venues', 0 === strpos( $out, '<p class="osn-intro">Downtown has 8 places with outdoor seating listed here.' ), $out );
echo "  EXAMPLE district: " . strstr( $out, "\n", true ) . "\n";
$out = hi_run( '[table id=4 filter="Uptown, Testville"]', 610 );
check( 'a district with one counted neighborhood gets no area sentence', 0 === strpos( $out, '<p class="osn-intro">Uptown has 2 places' ) && false === strpos( $out, 'By neighborhood' ), $out );

echo "\n--- 3 or fewer places ---\n";
$out = hi_run( '[table id=5 filter="Riverside, Testville"]', 620 );
check( 'three places: the names form, 3 of 3 confirm', '<p class="osn-intro">Riverside has 3 places listed here: Place 6, Place 7 and Place 8. All of them confirm outdoor seating. ' . HI_TAIL . '</p>' . "\n" . '[table id=5 filter="Riverside, Testville"]' === $out, $out );
$out = hi_run( '[table id=6 filter="Hilltop, Testville"]', 630 );
check( 'two places', 0 === strpos( $out, '<p class="osn-intro">Hilltop has 2 places listed here: Place 9 and Place 10. ' ), $out );
check( 'two places: the plural closing', false !== strpos( $out, ' ' . HI_TAIL . '</p>' ), $out );
hi_world( 12, function ( $k ) { return array( 'hood' => 9 === $k ? 24 : 0 ); } );
$out = hi_run( '[table id=6 filter="Hilltop, Testville"]', 630 );
check( 'one place: names it and closes with "It has its own page"', 0 === strpos( $out, '<p class="osn-intro">Hilltop has one place listed here: Place 9. ' ) && false !== strpos( $out, ' It has its own page with the address and directions.</p>' ) && false === strpos( $out, HI_TAIL ), $out );
hi_world( 12, 'hi_spec_big' );

echo "\n--- escaping ---\n";
$p = osn_post( 1008, 'v8', '' );
$p->post_title = 'Fish &lt;3 Chips &amp; Co';
$GLOBALS['osn']['terms'][23]->name = 'Rivers <i>ide</i> & Co';
$out = hi_run( '[table id=5 filter="Riverside, Testville"]', 620 );
check( 'a venue name with markup characters is escaped once', false !== strpos( $out, 'Place 7 and Fish &lt;3 Chips &amp; Co.' ), $out );
$out = hi_run( '[table id=5 filter=", Testville"]', 620 ); // No name in the filter: the term name.
check( 'a hood name with HTML is escaped (term name fallback)', 0 === strpos( $out, '<p class="osn-intro">Rivers &lt;i&gt;ide&lt;/i&gt; &amp; Co has 3 places' ), $out );

echo "\n--- left alone ---\n";
hi_world( 12, 'hi_spec_big' );
$own = '<p>Old Town is the oldest part of Testville.</p>' . "\n" . $tbl;
check( 'a page with its own text is unchanged', $own === hi_run( $own ), hi_run( $own ) );
check( 'own text after the table counts too', $tbl . '<p>Hello</p>' === hi_run( $tbl . '<p>Hello</p>' ) );
check( 'a heading counts as text', '<h2>Old Town</h2>' . $tbl === hi_run( '<h2>Old Town</h2>' . $tbl ) );
$in = '<p class="osn-intro">Mine.</p>' . $tbl;
check( 'content that already holds osn-intro is unchanged', $in === hi_run( $in ) );

// The real pipeline: Page_Title (priority 5) puts the H1 on top first, and the intro must land under it.
hi_run( $tbl );
$h1  = OSN\Page_Title::prepend( $tbl );
$out = City_Intro::filter( $h1 );
$ph  = strpos( $out, '<h1 class="osn-page-title">' );
$pi  = strpos( $out, '<p class="osn-intro">' );
$pt  = strpos( $out, '[table' );
check( 'after Page_Title: H1, then intro, then the table', 0 === strpos( $h1, '<h1 class="osn-page-title">' ) && 0 === $ph && false !== $pi && $ph < $pi && $pi < $pt, $out );
check( 'after Page_Title: a second pass adds nothing', City_Intro::filter( $out ) === $out, $out );
check( 'a [/table] closing tag is not text', 0 === strpos( hi_run( $tbl . '[/table]' ), '<p class="osn-intro">' ) );
check( 'a zero-width space is not text', 0 === strpos( hi_run( '&#8203;' . $tbl ), '<p class="osn-intro">' ) );

// Landmark page.
$GLOBALS['osn']['taxonomies'][] = 'osn_landmark';
osn_term( 30, 'On the Strip', 'testville-tx-on-strip', 'osn_landmark', 0, array( 'osn_city' => 'Testville, TX', 'osn_page_id' => 800 ) );
$GLOBALS['osn']['obj_terms'][1001]['osn_landmark'] = array( $GLOBALS['osn']['terms'][30] );
osn_post( 800, 'on-the-strip', '[table id=9 filter="Testville, TX"]' );
OSN\Takeover::flush_memo();
osn_request( 800 );
$ctx = OSN\Takeover::context( $GLOBALS['osn']['posts'][800] );
check( 'setup: page 800 is a landmark view', $ctx && $ctx['spot'] );
check( 'a landmark page with no text is unchanged', '[table id=9 filter="Testville, TX"]' === City_Intro::filter( '[table id=9 filter="Testville, TX"]' ) );

// Hood with no venues in view: hood is linked but Cards::view finds nothing (no venue is in it).
hi_world( 12, function ( $k ) { return array( 'hood' => $k <= 5 ? 21 : 0 ); } );
OSN\Takeover::flush_memo();
$ctx = OSN\Takeover::context( $GLOBALS['osn']['posts'][630] );
check( 'setup: the empty hood has no takeover context', ! $ctx || empty( $ctx['hood'] ) );
check( 'so a page for it is unchanged', '[table id=6 filter="Hilltop, Testville"]' === hi_run( '[table id=6 filter="Hilltop, Testville"]', 630 ) );
// The takeover context was resolved while venues existed, but the hood view comes back with no ids.
hi_world( 12, 'hi_spec_big' );
$GLOBALS['osn']['posts'][600]->post_content = $tbl;
OSN\Takeover::flush_memo();
osn_request( 600 );
$ctx = OSN\Takeover::context( $GLOBALS['osn']['posts'][600] );
check( 'setup: page 600 is a hood view', $ctx && $ctx['hood'] );
OSN\Cards::flush_memo();
$GLOBALS['osn']['transients']['osn_sorted2_10'] = array();
check( 'a hood view with no ids is unchanged', $tbl === City_Intro::filter( $tbl ) );

echo "\n--- where it applies ---\n";
hi_world( 12, 'hi_spec_big' );
check( 'baseline: it prepends', hi_run( $tbl ) !== $tbl );
$GLOBALS['osn']['options']['osn_city_intro'] = '0';
check( 'setting off: unchanged', $tbl === hi_run( $tbl ) );
$GLOBALS['osn']['options']['osn_city_intro'] = '1';
check( 'setting on: prepends', $tbl !== hi_run( $tbl ) );
unset( $GLOBALS['osn']['options']['osn_city_intro'] );
check( 'setting absent: on by default', $tbl !== hi_run( $tbl ) );
add_filter( 'osn_city_intro_enabled', '__return_false' );
check( 'osn_city_intro_enabled false: unchanged', $tbl === hi_run( $tbl ) );
$GLOBALS['filters'] = array();
$GLOBALS['osn']['not_loop'] = true;
check( 'not in the loop: unchanged', $tbl === hi_run( $tbl ) );
unset( $GLOBALS['osn']['not_loop'] );
$GLOBALS['osn']['not_main'] = true;
check( 'not the main query: unchanged', $tbl === hi_run( $tbl ) );
unset( $GLOBALS['osn']['not_main'] );
$GLOBALS['wp_current_filter'] = array( 'the_content', 'the_content' );
check( 'nested the_content: unchanged', $tbl === hi_run( $tbl ) );
$GLOBALS['wp_current_filter'] = array( 'get_the_excerpt', 'the_content' );
check( 'excerpt: unchanged', $tbl === hi_run( $tbl ) );
$GLOBALS['wp_current_filter'] = array( 'the_content' );
check( 'the page body itself: prepends', $tbl !== hi_run( $tbl ) );
$GLOBALS['wp_current_filter'] = array();

finish();
