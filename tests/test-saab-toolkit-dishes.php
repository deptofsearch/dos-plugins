<?php
/**
 * DoS SAAB Toolkit: the dish board on a restaurant page. Which dish names are the same dish, which dish a review
 * title names, and what get_ryan_dishes() makes of Hill's Restaurant & Lounge as it stood on 2026-10-19:
 * "Pulled Pork Benedict" and "Pulled Pork Eggs Benedict" were two cards with no photo, and the March 2026 visit was
 * on neither.
 */

define( 'ABSPATH', __DIR__ . '/' );

// Hill's: page 18039, its saab_ryan_dishes and linked posts exactly as the live REST API returned them.
$GLOBALS['meta'] = array(
	18039 => array(
		'saab_ryan_dishes'  => '[{"name":"Pulled Pork Benedict","post_id":16658,"posts":2,"photo_id":0},{"name":"Pulled Pork Eggs Benedict","post_id":12938,"posts":2,"photo_id":0},{"name":"Eggs Benedict","post_id":16025,"posts":1,"photo_id":15934},{"name":"French Dip","post_id":15930,"posts":1,"photo_id":15936},{"name":"Bacon Cheeseburger","post_id":12015,"posts":1,"photo_id":9470}]',
		'saab_linked_posts' => '[16759,16658,16025,15930,12938,11999,12205,12410,12015,4658]',
	),
);
$GLOBALS['posts'] = array(
	// post_title as WordPress stores it (context=edit, 2026-10-30): plain hyphens, which it only shows as "–".
	16759 => array( '2026-03-08 08:20:24', "Breakfast at Hill's Restaurant — Pulled Pork Benedict - March 8, 2026", 16696 ),
	16658 => array( '2025-12-28 22:34:49', 'Pulled Pork Benedict at Hills Restaurant & Lounge in Kennewick - December 28, 2025', 16655 ),
	16025 => array( '2025-09-23 10:38:26', 'Eggs Benedict at Hills Restaurant - September 23, 2025', 15934 ),
	15930 => array( '2025-09-23 09:24:49', 'French Dip at Hills Restaurant in Kennewick - September 23, 2025', 15936 ),
	12938 => array( '2021-03-28 12:39:17', 'Pulled Pork Eggs Benedict at Hills Restaurant and Lounge in Kennewick - March 28 2021', 12921 ),
	11999 => array( '2020-10-24 19:32:46', 'Pulled Pork Benedict at Hills Restaurant in Kennewick - October 24 2020', 11885 ),
	12205 => array( '2020-09-25 17:13:00', 'Lunch at Hills Restaurant in Kennewick - September 25 2020', 11439 ),
	12410 => array( '2019-03-09 12:00:35', 'Breakfast at Hills Restaurant in Kennewick - March 9 2019', 9820 ),
	12015 => array( '2019-01-05 19:57:08', 'Bacon Cheeseburger at Hills Restaurant in Kennewick - January 5 2019', 9470 ),
	4658  => array( '2015-10-17 08:41:42', 'Pulled Pork Eggs Benedict at Hills Restaurant - October 17 2015', 1832 ),
	// A two-dish post whose only photo is the pho: its photo must not land on the spring rolls.
	500   => array( '2024-01-01 12:00:00', 'Spring Rolls and Beef Pho at Pho Place – January 1, 2024', 501 ),
);

class WP_Post {
	public $ID; public $post_date; public $post_title;
	function __construct( $id ) { $p = $GLOBALS['posts'][ $id ]; $this->ID = $id; $this->post_date = $p[0]; $this->post_title = $p[1]; }
}
function add_action() {}
function add_filter() {}
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function get_post( $id ) { $id = is_object( $id ) ? $id->ID : (int) $id; return isset( $GLOBALS['posts'][ $id ] ) ? new WP_Post( $id ) : null; }
function get_post_field( $field, $id ) { $p = get_post( $id ); return $p ? $p->$field : ''; }
function get_post_thumbnail_id( $p ) { $id = is_object( $p ) ? $p->ID : (int) $p; return $GLOBALS['posts'][ $id ][2] ?? 0; }
function esc_url( $u ) { return (string) $u; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_list_pluck( $list, $field ) { return array_map( function ( $o ) use ( $field ) { return $o->$field; }, $list ); }

require dirname( __DIR__ ) . '/plugins/dos-saab-toolkit/includes/class-saab-restaurants.php';

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

echo "--- same dish? ---\n";
$same = array(
	array( 'Pulled Pork Benedict', 'Pulled Pork Eggs Benedict' ),
	array( 'Biscuits and Gravy', 'Biscuits & Gravy' ),
	array( 'Biscuit and Gravy', 'Biscuits and Gravy' ),
	array( 'Reuben', 'Classic Reuben' ),
	array( 'KC’s Memphis Sandwich', "KC's Memphis Sandwich" ),
);
foreach ( $same as $p ) {
	check( '"' . $p[0] . '" = "' . $p[1] . '"', SAAB_Dishes::same( $p[0], $p[1] ) );
}
$different = array(
	array( 'Eggs Benedict', 'Pulled Pork Eggs Benedict' ),
	array( 'Pho', 'Chicken Pho' ),
	array( 'Chicken Pho', 'Beef Pho' ),
	array( 'Cheeseburger', 'Bacon Cheeseburger' ),
	array( 'Spring Rolls', 'Spring Rolls and Beef Pho' ),
);
foreach ( $different as $p ) {
	check( '"' . $p[0] . '" ≠ "' . $p[1] . '"', ! SAAB_Dishes::same( $p[0], $p[1] ) );
}

echo "--- the dish in a title ---\n";
$titles = array(
	'Pulled Pork Benedict at Hills Restaurant & Lounge in Kennewick – December 28, 2025' => 'Pulled Pork Benedict',
	'Breakfast at Hill’s Restaurant — Pulled Pork Benedict – March 8, 2026'              => 'Pulled Pork Benedict',
	'Pulled Pork Eggs Benedict at Hills Restaurant – October 17 2015'                    => 'Pulled Pork Eggs Benedict',
	'Takeaway Lunch from Bangkok Thai in Kennewick – October 27 2020'                    => 'Takeaway Lunch',
	'Mashed Potatoes &amp; Turkey Gravy – August 27, 2026'                               => 'Mashed Potatoes & Turkey Gravy',
	// As stored: plain hyphens.
	"Breakfast at Hill's Restaurant — Pulled Pork Benedict - March 8, 2026"              => 'Pulled Pork Benedict',
	'Pulled Pork Benedict at Hills Restaurant & Lounge in Kennewick - December 28, 2025' => 'Pulled Pork Benedict',
	'Lunch at Hills Restaurant in Kennewick - September 25 2020'                         => 'Lunch',
	'Breakfast at Hills Restaurant - Pulled Pork Benedict - March 8, 2026'               => 'Pulled Pork Benedict',
);
foreach ( $titles as $t => $want ) {
	$got = SAAB_Dishes::from_title( $t );
	check( 'dish of "' . $t . '"', $want === $got, json_encode( $got ) );
}

echo "--- Hill's dish board ---\n";
$cards = SAAB_Restaurants::get_ryan_dishes( 18039 );
$by    = array_column( $cards, null, 'name' );
check( 'one Pulled Pork Benedict card, not two', isset( $by['Pulled Pork Benedict'] ) && ! isset( $by['Pulled Pork Eggs Benedict'] ), json_encode( array_column( $cards, 'name' ) ) );
$ppb = $by['Pulled Pork Benedict'] ?? array();
check( 'it counts all five visits (2015, 2020, 2021, Dec 2025, Mar 2026)', 5 === ( $ppb['posts'] ?? 0 ), json_encode( $ppb ) );
check( '"Last time" is the March 2026 visit', 16759 === ( $ppb['post_id'] ?? 0 ), json_encode( $ppb ) );
check( 'it has a photo: the March 2026 visit\'s featured image', 16696 === ( $ppb['photo_id'] ?? 0 ), json_encode( $ppb ) );
check( 'Eggs Benedict stays its own card, with its verified photo', isset( $by['Eggs Benedict'] ) && 15934 === $by['Eggs Benedict']['photo_id'] && 1 === $by['Eggs Benedict']['posts'] );
check( 'French Dip and Bacon Cheeseburger are unchanged', 15936 === ( $by['French Dip']['photo_id'] ?? 0 ) && 9470 === ( $by['Bacon Cheeseburger']['photo_id'] ?? 0 ) );
check( 'the most-visited dish comes first', 'Pulled Pork Benedict' === $cards[0]['name'] );
check( 'four cards: the two Pulled Pork Benedicts are one', 4 === count( $cards ), json_encode( array_column( $cards, 'name' ) ) );

echo "--- a verified \"no photo of this dish\" still holds on a two-dish post ---\n";
$GLOBALS['meta'][77] = array(
	'saab_ryan_dishes'  => '[{"name":"Spring Rolls","post_id":500,"posts":1,"photo_id":0},{"name":"Beef Pho","post_id":500,"posts":1,"photo_id":501}]',
	'saab_linked_posts' => '[500]',
);
$by = array_column( SAAB_Restaurants::get_ryan_dishes( 77 ), null, 'name' );
check( 'Spring Rolls gets no photo from "Spring Rolls and Beef Pho"', 0 === ( $by['Spring Rolls']['photo_id'] ?? -1 ), json_encode( $by ) );
check( 'Beef Pho keeps its verified photo', 501 === ( $by['Beef Pho']['photo_id'] ?? 0 ) );
check( 'the two dishes of one post stay two cards', 2 === count( $by ) );

echo "--- the hit list on a page Ryan hasn't eaten at ---\n";
$popular = array(
	array( 'name' => 'Pad Thai', 'mentions' => 4, 'source' => 'reviews' ),
	array( 'name' => 'Green Curry', 'mentions' => 9, 'source' => 'reviews' ),
	array( 'name' => 'Spring Rolls', 'mentions' => 2, 'source' => 'reviews' ),
	array( 'name' => 'Pad Thai', 'mentions' => null, 'source' => 'google_tag' ),
	array( 'name' => 'Mango Sticky Rice', 'mentions' => null, 'source' => 'google_tag' ),
	array( 'name' => 'Tom Yum', 'mentions' => null, 'source' => 'google_tag' ),
	array( 'name' => 'Larb', 'mentions' => null, 'source' => 'google_tag' ),
	array( 'name' => '', 'mentions' => 7, 'source' => 'reviews' ),
);
$names = array_column( SAAB_Dishes::hit_list( $popular ), 'name' );
check( 'review dishes first, most mentions first', array( 'Green Curry', 'Pad Thai', 'Spring Rolls' ) === array_slice( $names, 0, 3 ), json_encode( $names ) );
check( 'Google photo tags fill it to five, minus the Pad Thai already on it', array( 'Mango Sticky Rice', 'Tom Yum' ) === array_slice( $names, 3 ), json_encode( $names ) );
check( 'five at most', 5 === count( $names ) );
check( 'an empty list stays empty', array() === SAAB_Dishes::hit_list( array() ) );

echo "--- generic review dishes ---\n";
foreach ( array( 'Eggs', 'Bacon', 'Coffee', 'Indian Food', 'Mexican Cuisine', 'Potatoes', 'Drinks' ) as $g ) {
	check( "\"$g\" is generic", SAAB_Dishes::is_generic( $g ) );
}
foreach ( array( 'Biscuits And Gravy', 'Hash Browns', 'Chicken Fried Steak', 'Steak', 'Pho', 'Seafood Chowder', 'Fish and Chips' ) as $g ) {
	check( "\"$g\" is a dish", ! SAAB_Dishes::is_generic( $g ) );
}
// Hill's saab_popular_dishes as synced 2026-09-27.
$hills = json_decode( '[{"name":"Coffee","mentions":7,"source":"reviews"},{"name":"Biscuits And Gravy","mentions":6,"source":"reviews"},{"name":"Eggs","mentions":6,"source":"reviews"},{"name":"Hash Browns","mentions":4,"source":"reviews"},{"name":"Chicken Fried Steak","mentions":4,"source":"reviews"},{"name":"Bacon","mentions":3,"source":"reviews"}]', true );
check( 'the hit list skips Coffee, Eggs and Bacon', array( 'Biscuits And Gravy', 'Hash Browns', 'Chicken Fried Steak' ) === array_column( SAAB_Dishes::hit_list( $hills ), 'name' ), json_encode( array_column( SAAB_Dishes::hit_list( $hills ), 'name' ) ) );

echo "--- Ryan's Take on a page he hasn't eaten at ---\n";
$take = new ReflectionMethod( 'SAAB_Restaurants', 'render_take' );
$take->setAccessible( true );
// Ann's Best as built 2026-10-30: no take, no visits, a hit list from Google reviews.
$GLOBALS['meta'][18565] = array( 'saab_popular_dishes' => '[{"name":"Fried Catfish","mentions":2,"source":"reviews"},{"name":"Shrimp","mentions":2,"source":"reviews"}]' );
check( 'no "I haven\'t eaten here yet" box above the hit list', '' === $take->invoke( null, 18565, '' ) );
$GLOBALS['meta'][18567] = array( 'saab_popular_dishes' => '[]' );
check( 'with no hit list the box still says it', false !== strpos( $take->invoke( null, 18567, '' ), "I haven't eaten here yet." ) );
check( 'a written take always shows', false !== strpos( $take->invoke( null, 18565, '<p>Great catfish.</p>' ), 'Great catfish.' ) );
check( 'Hill\'s (visits, no take) still shows no box', '' === $take->invoke( null, 18039, '' ) );

echo "--- photos from the restaurant's own website ---\n";
require_once dirname( __DIR__ ) . '/plugins/dos-saab-toolkit/includes/class-saab-restaurant-image.php';
check( 'a 1200x630 jpeg share image passes', '' === SAAB_Restaurant_Image::check_dimensions( 1200, 630, 'image/jpeg' ) );
check( 'a 500x500 logo-sized image is too small', false !== strpos( SAAB_Restaurant_Image::check_dimensions( 500, 500, 'image/png' ), 'Too small' ) );
check( 'a tall phone shot is rejected (strip crops wide)', false !== strpos( SAAB_Restaurant_Image::check_dimensions( 900, 1600, 'image/jpeg' ), 'Aspect ratio' ) );
check( 'a 3000x600 banner is rejected', false !== strpos( SAAB_Restaurant_Image::check_dimensions( 3000, 600, 'image/webp' ), 'Aspect ratio' ) );
check( 'gif and svg are not accepted', false !== strpos( SAAB_Restaurant_Image::check_dimensions( 1200, 800, 'image/gif' ), 'Unsupported' ) );
$credit = new ReflectionMethod( 'SAAB_Restaurants', 'render_photo_credit' );
$credit->setAccessible( true );
$GLOBALS['posts'][18565] = array( '2026-10-30 00:20:43', "Ann's Best", 9001 );
$GLOBALS['meta'][18565]['saab_photo_credit_text'] = "Photo: Ann's Best's website";
$GLOBALS['meta'][18565]['saab_photo_credit_url']  = 'https://annsbest.example/';
$html = $credit->invoke( null, 18565, 9001 );
check( 'the credit shows under a website photo, linked to the site', false !== strpos( $html, 'annsbest.example' ) && false !== strpos( $html, 'Photo: Ann&#039;s Best&#039;s website' ), $html );
check( 'no credit once another photo leads the strip', '' === $credit->invoke( null, 18565, 1234 ) );
check( 'no credit on a page without one (Hill\'s)', '' === $credit->invoke( null, 18039, 16696 ) );

// A chain's website photo is one attachment (9100) shared by two locations; its stored alt names the first.
function get_the_terms( $id, $tax ) { return $GLOBALS['terms'][ $id ] ?? false; }
function is_wp_error( $x ) { return false; }
function get_ancestors( $id, $tax, $type ) { return $GLOBALS['ancestors'][ $id ] ?? array(); }
$GLOBALS['terms'] = array( 19407 => array( (object) array( 'term_id' => 31, 'name' => 'Pasco' ) ), 19320 => array( (object) array( 'term_id' => 32, 'name' => 'Kennewick' ) ) );
$GLOBALS['ancestors'] = array( 31 => array( 2, 1 ), 32 => array( 2, 1 ) );
$GLOBALS['posts'][19407] = array( '2026-11-01 00:00:00', 'Spudnut Shop', 9100 );
$GLOBALS['posts'][19320] = array( '2026-11-01 00:00:00', 'Spudnut Shop', 9100 );
$GLOBALS['meta'][9100] = array( '_saab_source_image_url' => 'https://spudnutsnw.com/x.jpg', '_wp_attachment_image_alt' => 'Spudnut Shop in Pasco' );
$GLOBALS['meta'][9200] = array( '_wp_attachment_image_alt' => 'Ryan\'s burger' );
check( 'a shared website photo names this page\'s city (Kennewick)', 'Spudnut Shop in Kennewick' === SAAB_Restaurants::photo_alt( 9100, 19320, 'x' ), SAAB_Restaurants::photo_alt( 9100, 19320, 'x' ) );
check( 'and the first location still reads Pasco', 'Spudnut Shop in Pasco' === SAAB_Restaurants::photo_alt( 9100, 19407, 'x' ) );
check( 'Ryan\'s own photos keep their stored alt', 'Ryan\'s burger' === SAAB_Restaurants::photo_alt( 9200, 19320, 'x' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
