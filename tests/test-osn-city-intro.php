<?php
/**
 * OSN\City_Intro (0.6.18): stock city page intros become a summary written from the city's listing data.
 * Templates A, B and C, the dead and linked closings, the generated sentences, the gates, and the Cards::view memo.
 */

require __DIR__ . '/wp-stubs-osn.php';
osn_load();

use OSN\Cards;
use OSN\City_Intro;

const CI_TABLE = '[table id=1 filter="Testville, TX"]';
const CI_STOCK = 'Below is every place we have listed with a patio, deck, rooftop or sidewalk tables, with hours, ratings and directions.';
const CI_DEAD  = "If you're open to other parts of the area for finding outdoor seating on patio or deck, there are plenty of options.";
const CI_MORE  = '<p class="osn-intro osn-intro--more">More places nearby: <a href="https://example.com/texas/">every city in Texas with outdoor seating</a>.</p>';
const CI_TAIL  = 'Each place has its own page with the address and directions.';
const CI_ONE   = 'It has its own page with the address and directions.';

/**
 * A city of $n venues. $spec(k) returns array( 'cat' => name|null, 'am' => slugs, 'dist' => district term|null ) for
 * venue k (1-based); by default every venue has outdoor-seating only.
 */
function ci_world( $n, $spec = null ) {
	osn_world( $n );
	$o = &$GLOBALS['osn'];
	$o['taxonomies'][] = 'osn_amenity';
	$o['taxonomies'][] = 'osn_category';
	osn_term( 22, 'Uptown', 'testville-tx-uptown', 'osn_hood', 0, array( 'osn_city' => 'Testville, TX' ) );
	$terms = array();
	$tid   = 200;
	for ( $k = 1; $k <= $n; $k++ ) {
		$id = 1000 + $k;
		unset( $o['obj_terms'][ $id ] );
		$s = $spec ? $spec( $k ) : array();
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
		if ( ! empty( $s['dist'] ) ) {
			$o['obj_terms'][ $id ]['osn_hood'] = array( $o['terms'][ $s['dist'] ] );
		}
		$o['titles'][ $id ] = 'Place ' . $k;
	}
	return $terms;
}

/** Run the filter on the city page (500) with $content as its stored text. */
function ci_run( $content, $page = 500 ) {
	$GLOBALS['osn']['posts'][ $page ]->post_content = $content;
	OSN\Takeover::flush_memo();
	osn_request( $page );
	return City_Intro::filter( $content );
}

function ci_state_page( $status = 'publish' ) {
	$p = osn_post( 700, 'texas', 'Texas cities' );
	$p->post_status = $status;
}

function ci_a( $city = 'Testville', $attr = ' style="text-align: center;"' ) {
	return "<p$attr>$city has restaurants, bars, cafes and breweries with patios and outdoor tables. " . CI_STOCK . '</p>';
}

function ci_b( $city = 'Testville', $amp = '&amp;', $apos = "'" ) {
	return "<h3 style=\"text-align: center;\">List of Outdoor $amp Patio Seating in $city</h3>\n"
		. "<p style=\"text-align: center;\">The city of $city offers a great deal of sunshine and some great places to sit, eat, and drink while enjoying that sunshine.</p>\n"
		. "<p style=\"text-align: center;\">If you{$apos}re looking for a place to sit on a deck, patio, or just any outdoor seating you can find, here is your list:</p>\n";
}

function ci_gen() {
	$term = get_term_by( 'slug', 'testville-tx', 'osn_city' );
	return City_Intro::sentences( $term, Cards::view( $term ) );
}

$table = '<p style="text-align: center;">' . CI_TABLE . '</p>';
$dead  = "\n<h3 style=\"text-align: center;\">Outdoor Seating in Testville</h3>\n<p style=\"text-align: center;\">" . CI_DEAD . '</p>';

echo "--- the generated text: 4 or more places ---\n";
$spec = function ( $k ) {
	$cat = array( 1 => 'Bar', 2 => 'Bar', 3 => 'Bar', 4 => 'Bar', 5 => 'Bar', 6 => 'Cafe', 7 => 'Cafe', 8 => 'Cafe', 9 => 'Cafe', 10 => 'Mexican restaurant', 11 => 'Mexican restaurant', 12 => 'Brewery' );
	$am  = array( 'outdoor-seating' );
	if ( $k <= 6 ) { $am[] = 'happy-hour'; }
	if ( $k >= 9 ) { $am[] = 'dog-friendly'; }
	if ( 5 === $k || 6 === $k ) { $am[] = 'brunch'; }
	return array( 'cat' => $cat[ $k ], 'am' => $am, 'dist' => $k <= 7 ? 20 : ( $k <= 10 ? 22 : null ) );
};
ci_world( 12, $spec );
$g = ci_gen();
$want = 'Testville has 12 places with outdoor seating listed here. Most common: Bar (5), Cafe (4) and Mexican restaurant (2). 6 have a happy hour and 4 are dog friendly. By area, Downtown (7) and Uptown (3) have the most. ' . CI_TAIL;
check( 'all four sentences, category names keep their capitals', $want === $g, $g );
echo "  EXAMPLE N>=4: $g\n";

// Three amenities with counts: the two biggest win; ties go in list order; singular and plural phrases.
ci_world( 5, function ( $k ) {
	return array( 'cat' => 'Bar', 'am' => 1 === $k ? array( 'outdoor-seating', 'happy-hour', 'rooftop' ) : ( $k <= 3 ? array( 'outdoor-seating', 'brunch', 'live-music' ) : array() ) );
} );
$g = ci_gen();
check( 'only 3 of 5 confirm outdoor seating', 0 === strpos( $g, 'Testville has 5 places listed here, and 3 of them confirm outdoor seating. ' ), $g );
check( 'one category: no "Most common" sentence', false === strpos( $g, 'Most common' ), $g );
check( 'ties on 2 break by list order (brunch before live music); the lone happy hour and rooftop do not show', false !== strpos( $g, ' 2 serve brunch and 2 have live music. ' ), $g );
check( 'no districts: no neighborhood sentence', false === strpos( $g, 'neighborhood' ), $g );

ci_world( 4, function ( $k ) {
	return array( 'am' => array( 'outdoor-seating', 'happy-hour', 'waterfront' ) );
} );
$g = ci_gen();
check( 'plural phrases for 4 and 4', false !== strpos( $g, ' 4 have a happy hour and 4 are on the water. ' ), $g );
check( 'all 4 confirm', 0 === strpos( $g, 'Testville has 4 places with outdoor seating listed here. ' ), $g );

ci_world( 4, function ( $k ) {
	return array( 'am' => 1 === $k ? array( 'outdoor-seating', 'dog-friendly', 'fire-pit' ) : array( 'outdoor-seating' ) );
} );
$g = ci_gen();
check( 'singular phrases for a count of 1', false !== strpos( $g, ' 1 is dog friendly and 1 has a fire pit. ' ), $g );

ci_world( 4, function ( $k ) {
	return array( 'am' => 1 === $k ? array( 'outdoor-seating', 'rooftop' ) : array( 'brunch' ) );
} );
$g = ci_gen();
check( 'one confirming place of 4 reads "1 of them confirms"', 0 === strpos( $g, 'Testville has 4 places listed here, and 1 of them confirms outdoor seating. ' ), $g );
check( 'a lone amenity is a one-part sentence', false !== strpos( $g, ' 3 serve brunch and 1 has a rooftop. ' ), $g );

ci_world( 4, function ( $k ) {
	return array( 'am' => array() );
} );
$g = ci_gen();
check( 'none confirm outdoor seating', 0 === strpos( $g, 'Testville has 4 places listed here; none of them confirms outdoor seating yet. ' ), $g );
check( 'no amenities: no amenity sentence, the tail still closes', CI_TAIL === substr( $g, -strlen( CI_TAIL ) ) && 2 === substr_count( $g, '. ' ) + 1, $g );

ci_world( 5, function ( $k ) {
	return array( 'cat' => $k <= 3 ? 'Bar' : ( 4 === $k ? 'Cafe' : 'Diner' ), 'dist' => 20 );
} );
$g = ci_gen();
check( 'two categories need 2+ venues each: Bar (3) alone is not enough (Cafe and Diner have 1)', false === strpos( $g, 'Most common' ), $g );
check( 'one district: no neighborhood sentence', false === strpos( $g, 'By area' ), $g );

ci_world( 9, function ( $k ) {
	return array( 'cat' => $k <= 3 ? 'Bar' : ( $k <= 5 ? 'Cafe' : 'Diner' ), 'dist' => $k <= 4 ? 20 : 22 );
} );
$g = ci_gen();
check( 'two categories with 2+ venues give a two-item list; the 3-count Diner... ties by name', false !== strpos( $g, 'Most common: Diner (4), Bar (3) and Cafe (2). ' ), $g );
check( 'districts tie by name', false !== strpos( $g, 'By area, Uptown (5) and Downtown (4) have the most.' ), $g );

echo "\n--- the generated text: 1 to 3 places ---\n";
ci_world( 1 );
$g = ci_gen();
check( 'one place', 'Testville has one place listed here: Place 1. It confirms outdoor seating. ' . CI_ONE === $g, $g );
echo "  EXAMPLE N=1: $g\n";
ci_world( 3, function ( $k ) { return array( 'am' => 3 === $k ? array() : array( 'outdoor-seating' ) ); } );
$g = ci_gen();
check( 'three places, 2 of 3 confirm', 'Testville has 3 places listed here: Place 1, Place 2 and Place 3. 2 of them confirm outdoor seating. ' . CI_TAIL === $g, $g );
echo "  EXAMPLE N=3: $g\n";
ci_world( 2 );
check( 'two places, all confirm', 'Testville has 2 places listed here: Place 1 and Place 2. All of them confirm outdoor seating. ' . CI_TAIL === ci_gen() );
ci_world( 2, function ( $k ) { return array( 'am' => array() ); } );
check( 'two places, none confirm: no confirmation sentence', 'Testville has 2 places listed here: Place 1 and Place 2. ' . CI_TAIL === ci_gen() );
ci_world( 3, function ( $k ) { return array( 'am' => 1 === $k ? array( 'outdoor-seating' ) : array() ); } );
check( 'one of three confirms', false !== strpos( ci_gen(), ' 1 of them confirms outdoor seating. ' ), ci_gen() );

echo "\n--- escaping ---\n";
ci_world( 1, function ( $k ) { return array( 'am' => array( 'outdoor-seating' ) ); } );
$p = osn_post( 1001, 'v1', '' );
$p->post_title = 'Fish &lt;3 Chips &amp; Co';
check( 'a venue name with < and & is escaped once', 'Testville has one place listed here: Fish &lt;3 Chips &amp; Co. It confirms outdoor seating. ' . CI_ONE === ci_gen(), ci_gen() );
ci_world( 6, function ( $k ) { return array( 'cat' => $k <= 3 ? 'Bar & Grill' : 'Cafe <b>' ); } );
check( 'category names with markup characters are escaped', false !== strpos( ci_gen(), 'Bar &amp; Grill (3) and Cafe &lt;b&gt; (3)' ), ci_gen() );

echo "\n--- template A ---\n";
ci_world( 12, $spec );
ci_state_page();
$gen = ci_gen();
$out = ci_run( ci_a() . "\n" . $table . $dead );
check( 'template A becomes one generated paragraph with no inline style', 0 === strpos( $out, '<p class="osn-intro">' . $gen . '</p>' ), $out );
check( 'A: the table paragraph is untouched', false !== strpos( $out, $table ), $out );
check( 'A: the dead closing and its heading are replaced by the state line', substr( $out, -strlen( CI_MORE ) ) === CI_MORE && false === strpos( $out, 'plenty of options' ) && false === strpos( $out, 'Outdoor Seating in Testville' ), $out );
check( 'A: the stock wording is gone, including "ratings"', false === strpos( $out, 'ratings' ) && false === strpos( $out, 'has restaurants, bars' ), $out );
$out = ci_run( ci_a( 'Port Townsend &amp; Co', '' ) . "\n" . $table );
check( 'A: no style attribute and any city name still match', 0 === strpos( $out, '<p class="osn-intro">' ), $out );
$out = ci_run( "<p style=\"text-align:center\">\n  Testville has restaurants,  bars, cafes and breweries with patios and outdoor tables.\n\n" . CI_STOCK . "\n</p>\n" . $table );
check( 'A: extra whitespace and newlines still match', 0 === strpos( $out, '<p class="osn-intro">' ), $out );
$out = ci_run( ci_a() . ci_a() . $table );
check( 'A: only the first copy is rewritten', 1 === substr_count( $out, 'class="osn-intro"' ) && 1 === substr_count( $out, 'has restaurants, bars' ), $out );

echo "\n--- template B ---\n";
foreach ( array( array( '&amp;', "'" ), array( '&#038;', "\u{2019}" ), array( '&', '&#8217;' ) ) as $v ) {
	$out = ci_run( ci_b( 'Testville', $v[0], $v[1] ) . $table . $dead );
	$ok  = '<p class="osn-intro">' . $gen . '</p>' . "\n" . $table . "\n" . CI_MORE === $out || ( 0 === strpos( $out, '<p class="osn-intro">' . $gen . '</p>' ) );
	check( "B ({$v[0]} / apostrophe form): heading and list line gone, lead replaced", $ok && false === strpos( $out, 'List of Outdoor' ) && false === strpos( $out, 'here is your list' ) && false === strpos( $out, 'great deal of sunshine' ), $out );
}
$out = ci_run( ci_b() . $table . $dead );
check( 'B: exact result', '<p class="osn-intro">' . $gen . '</p>' . "\n" . $table . "\n" . CI_MORE === $out, $out );

echo "\n--- template C ---\n";
$hand = 'Testville is a suburb south of Salt Lake City, with restaurants and cafes that have patios and outdoor tables.';
$out  = ci_run( '<p style="text-align: center;">' . $hand . ' ' . CI_STOCK . '</p>' . "\n" . $table );
check( 'C: the hand-written sentence stays, the stock sentence becomes the generated text, the paragraph keeps its style', '<p style="text-align: center;">' . $hand . ' ' . $gen . '</p>' . "\n" . $table . "\n" . CI_MORE === $out, $out );
check( 'C: no second paragraph', false === strpos( $out, 'class="osn-intro">' ), $out );
$out = ci_run( '<p>' . $hand . ' <a href="/x/">Salt Lake</a> ' . CI_STOCK . ' Call ahead.</p>' . $table );
check( 'C: markup before the sentence and text after it survive', 0 === strpos( $out, '<p>' . $hand . ' <a href="/x/">Salt Lake</a> ' . $gen . ' Call ahead.</p>' ), $out );
$out = ci_run( "<p>First paragraph.</p>\n<p>" . $hand . "\n" . CI_STOCK . '</p>' . $table );
check( 'C: another paragraph before it is not swallowed', 0 === strpos( $out, "<p>First paragraph.</p>\n<p>" . $hand . "\n" . $gen . '</p>' ), $out );

echo "\n--- unknown text and closings ---\n";
$other = '<p>Testville has plenty of patios, and we love them.</p>';
$out   = ci_run( $other . $table );
check( 'text that matches no pattern is unchanged (the state line is still appended)', $other . $table . "\n" . CI_MORE === $out, $out );
$out = ci_run( '<p>Testville has restaurants, bars, cafes and breweries with patios and outdoor tables. Plus our own thoughts.</p>' . $table . $dead );
check( 'a near miss on template A is left alone', false !== strpos( $out, 'Plus our own thoughts' ) && false === strpos( $out, 'class="osn-intro">' ), $out );

$linked1 = "<h3 style=\"text-align: center;\">Outdoor Seating in Testville</h3>\n<p style=\"text-align: center;\">If you're open to other parts of the area for finding outdoor seating on patio or deck, there are plenty of options like <a href=\"/clarkston-wa/\">Clarkston</a>, <a href=\"/lewiston-id/\">Lewiston</a>.</p>";
$out     = ci_run( ci_a() . $table . "\n" . $linked1 );
check( 'the linked closing is kept unchanged and no state line is added', false !== strpos( $out, $linked1 ) && false === strpos( $out, 'osn-intro--more' ), $out );
$linked2 = '<h3>Outdoor Seating in Testville</h3><p>If you\'re looking for other towns near here, you can search for outdoor seating in <a href="/fruitland-id/">Fruitland</a>.</p>';
$out     = ci_run( ci_a() . $table . $linked2 );
check( 'the other linked closing is kept too', false !== strpos( $out, $linked2 ) && false === strpos( $out, 'osn-intro--more' ), $out );

$out = ci_run( ci_a() . "\n" . $table );
check( 'no closing section: the state line is appended at the end', substr( $out, -strlen( CI_MORE ) ) === CI_MORE && 1 === substr_count( $out, 'osn-intro--more' ), $out );
$out = ci_run( $table );
check( 'a page with no intro at all gets no intro, only the state line', $table . "\n" . CI_MORE === $out, $out );

$dead_curly = "<p>If you\u{2019}re open to other parts of the area for finding outdoor seating on patio or deck, there are plenty of options.</p>";
$out        = ci_run( ci_a() . $table . '<h3>Outdoor Seating in Testville</h3>' . $dead_curly );
check( 'dead closing with a curly apostrophe is replaced', substr( $out, -strlen( CI_MORE ) ) === CI_MORE && false === strpos( $out, 'plenty of options' ), $out );
$out = ci_run( ci_a() . $table . $dead_curly );
check( 'dead closing without its heading is replaced too', substr( $out, -strlen( CI_MORE ) ) === CI_MORE && false === strpos( $out, 'plenty of options' ), $out );

echo "\n--- no state page ---\n";
unset( $GLOBALS['osn']['posts'][700] );
$out = ci_run( ci_a() . $table . $dead );
check( 'no state page: the dead closing is removed and no line is written', false === strpos( $out, 'plenty of options' ) && false === strpos( $out, 'osn-intro--more' ) && false === strpos( $out, 'Outdoor Seating in Testville' ), $out );
$out = ci_run( ci_a() . $table );
check( 'no state page and no closing: nothing is appended', false === strpos( $out, 'osn-intro--more' ), $out );
ci_state_page( 'draft' );
$out = ci_run( ci_a() . $table );
check( 'a draft state page is not linked', false === strpos( $out, 'osn-intro--more' ), $out );
ci_state_page();

echo "\n--- where it applies ---\n";
$in = ci_a() . $table . $dead;
check( 'baseline: it rewrites', ci_run( $in ) !== $in );
check( 'its own output is never rewritten again', ci_run( ci_run( $in ) ) === ci_run( $in ) );
$GLOBALS['osn']['options']['osn_city_intro'] = '0';
check( 'setting off: unchanged', ci_run( $in ) === $in );
$GLOBALS['osn']['options']['osn_city_intro'] = '1';
check( 'setting on explicitly: rewrites', ci_run( $in ) !== $in );
unset( $GLOBALS['osn']['options']['osn_city_intro'] );
check( 'setting absent: on by default', ci_run( $in ) !== $in );
add_filter( 'osn_city_intro_enabled', '__return_false' );
check( 'osn_city_intro_enabled false: unchanged', ci_run( $in ) === $in );
$GLOBALS['filters'] = array();
check( 'sanitize keeps 0 and 1 and defaults to 1', '0' === City_Intro::sanitize( '0' ) && '1' === City_Intro::sanitize( '1' ) && '1' === City_Intro::sanitize( '' ) );

$GLOBALS['osn']['not_loop'] = true;
OSN\Takeover::flush_memo();
check( 'not in the loop: unchanged', City_Intro::filter( $in ) === $in );
unset( $GLOBALS['osn']['not_loop'] );
$GLOBALS['osn']['not_main'] = true;
check( 'not the main query: unchanged', City_Intro::filter( $in ) === $in );
unset( $GLOBALS['osn']['not_main'] );
check( 'and rewrites again when both are true', City_Intro::filter( $in ) !== $in );
$GLOBALS['osn']['is_page'] = false;
check( 'not a page: unchanged', City_Intro::filter( $in ) === $in );
$GLOBALS['osn']['is_page'] = true;
$GLOBALS['osn']['current'] = $GLOBALS['osn']['posts'][700]; // The loop is on another post (a widget, a related-posts block).
check( 'the current post is not the queried page: unchanged', City_Intro::filter( $in ) === $in );
$GLOBALS['osn']['current'] = $GLOBALS['osn']['posts'][500];

// A neighborhood page keeps its text.
$GLOBALS['osn']['obj_terms'][1004]['osn_hood'] = array( $GLOBALS['osn']['terms'][21] );
$GLOBALS['osn']['posts'][600]->post_content = ci_a() . '[table id=2 filter="Old Town, Testville"]' . $dead;
OSN\Takeover::flush_memo();
osn_request( 600 );
$ctx = OSN\Takeover::context( $GLOBALS['osn']['posts'][600] );
check( 'setup: page 600 is a hood view', $ctx && $ctx['hood'] );
check( 'a neighborhood page is unchanged', City_Intro::filter( $GLOBALS['osn']['posts'][600]->post_content ) === $GLOBALS['osn']['posts'][600]->post_content );

// A landmark page keeps its text.
$GLOBALS['osn']['taxonomies'][] = 'osn_landmark';
osn_term( 30, 'On the Strip', 'testville-tx-on-strip', 'osn_landmark', 0, array( 'osn_city' => 'Testville, TX', 'osn_page_id' => 800 ) );
$GLOBALS['osn']['obj_terms'][1001]['osn_landmark'] = array( $GLOBALS['osn']['terms'][30] );
osn_post( 800, 'on-the-strip', ci_a() . CI_TABLE . $dead );
OSN\Takeover::flush_memo();
osn_request( 800 );
$ctx = OSN\Takeover::context( $GLOBALS['osn']['posts'][800] );
check( 'setup: page 800 is a landmark view', $ctx && $ctx['spot'] );
check( 'a landmark page is unchanged', City_Intro::filter( $GLOBALS['osn']['posts'][800]->post_content ) === $GLOBALS['osn']['posts'][800]->post_content );

// An empty city (no venues) keeps its text.
osn_world( 0 );
ci_state_page();
check( 'a city with no cards is unchanged', ci_run( $in ) === $in );
// A page that is not a takeover grid at all.
osn_world( 3 );
osn_post( 900, 'about', ci_a() . '<p>No table here.</p>' );
check( 'a page with no [table filter] is unchanged', ci_run( ci_a(), 900 ) === ci_a() );

echo "\n--- Cards::view is computed once ---\n";
ci_world( 12, $spec );
osn_request( 500 );
OSN\Takeover::flush_memo();
$GLOBALS['osn']['calls'] = array();
City_Intro::filter( ci_a() . $table );
$after_intro = count( $GLOBALS['osn']['calls']['term_cache'] ?? array() );
$html = Cards::render( 'Testville, TX', 0, true, true, null, null, 1 );
check( 'the intro primed the term cache once', 1 === $after_intro, (string) $after_intro );
check( 'the grid that follows reuses the same view: still one term-cache call', 1 === count( $GLOBALS['osn']['calls']['term_cache'] ), json_encode( $GLOBALS['osn']['calls']['term_cache'] ) );
check( 'and still renders its cards', false !== strpos( $html, 'data-osn-card' ) );
Cards::flush_memo();
Cards::render( 'Testville, TX', 0, true, true, null, null, 1 );
check( 'flush_memo makes the next view recompute', 2 === count( $GLOBALS['osn']['calls']['term_cache'] ) );
$a = Cards::view( get_term_by( 'slug', 'testville-tx', 'osn_city' ), null, null, 5 );
$b = Cards::view( get_term_by( 'slug', 'testville-tx', 'osn_city' ) );
check( 'the memo is keyed by limit', 5 === count( $a['ids'] ) && 12 === count( $b['ids'] ) );

echo "\n--- the dead closing as it is really stored ---\n";
ci_world( 12 );
ci_state_page();
$raw = "<h3 style=\"text-align: center;\">List of Outdoor & Patio Seating in Testville</h3>\r\n<p style=\"text-align: center;\">The city of Testville offers a great deal of sunshine and some great places to sit, eat, and drink while enjoying that sunshine.</p>\r\n<p style=\"text-align: center;\">If you're looking for a place to sit on a deck, patio, or just any outdoor seating you can find, here is your list:</p>\r\n<p style=\"text-align: center;\">" . CI_TABLE . "</p>\r\n<h3 style=\"text-align: center;\">Outdoor Seating in Testville</h3>\r\n<p style=\"text-align: center;\">If you're open to other parts of the area for finding outdoor seating on patio or deck, there are plenty of options.\r\n";
$out = ci_run( $raw );
check( 'an unclosed dead closing at the end (the stored form on the live site) is replaced', false === strpos( $out, 'plenty of options' ) && false === strpos( $out, '>Outdoor Seating in Testville<' ) && false !== strpos( $out, CI_MORE ), $out );
$out = ci_run( str_replace( "plenty of options.\r\n", "plenty of options.\r\n<p>Something else.</p>", $raw ) );
check( 'an unclosed dead closing followed by another paragraph is replaced and the paragraph kept', false === strpos( $out, 'plenty of options' ) && false !== strpos( $out, '<p>Something else.</p>' ) && false !== strpos( $out, CI_MORE ), $out );
$out = ci_run( str_replace( 'plenty of options.', 'plenty of options like <a href="https://example.com/x/">X</a>.', $raw ) );
check( 'an unclosed closing with links is kept', false !== strpos( $out, 'plenty of options like <a' ) && false === strpos( $out, 'osn-intro--more' ), $out );

echo "\n--- only the page body ---\n";
ci_world( 12 );
ci_state_page();
$GLOBALS['wp_current_filter'] = array( 'the_content', 'the_content' );
check( 'a nested the_content call (a module filtering other text) is left alone', '<p>A text module.</p>' === ci_run( '<p>A text module.</p>' ) );
$GLOBALS['wp_current_filter'] = array( 'get_the_excerpt', 'the_content' );
check( 'an excerpt built from the page is left alone', ci_a() . CI_TABLE === ci_run( ci_a() . CI_TABLE ) );
$GLOBALS['wp_current_filter'] = array( 'the_content' );
check( 'the page body itself is still rewritten', false !== strpos( ci_run( ci_a() . CI_TABLE ), 'osn-intro' ) );
$GLOBALS['wp_current_filter'] = array();
ci_run( ci_a() . CI_TABLE );
osn_request( 500, 2 );
check( 'a numbered page (/page/2/) is rewritten too', false !== strpos( OSN\City_Intro::filter( ci_a() . CI_TABLE ), 'osn-intro' ) );

finish();
