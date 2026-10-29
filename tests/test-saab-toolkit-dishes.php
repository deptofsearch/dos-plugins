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
	16759 => array( '2026-03-08 08:20:24', 'Breakfast at Hill’s Restaurant — Pulled Pork Benedict – March 8, 2026', 16696 ),
	16658 => array( '2025-12-28 22:34:49', 'Pulled Pork Benedict at Hills Restaurant &amp; Lounge in Kennewick – December 28, 2025', 16655 ),
	16025 => array( '2025-09-23 10:38:26', 'Eggs Benedict at Hills Restaurant – September 23, 2025', 15934 ),
	15930 => array( '2025-09-23 09:24:49', 'French Dip at Hills Restaurant in Kennewick – September 23, 2025', 15936 ),
	12938 => array( '2021-03-28 12:39:17', 'Pulled Pork Eggs Benedict at Hills Restaurant and Lounge in Kennewick – March 28 2021', 12921 ),
	11999 => array( '2020-10-24 19:32:46', 'Pulled Pork Benedict at Hills Restaurant in Kennewick – October 24 2020', 11885 ),
	12205 => array( '2020-09-25 17:13:00', 'Lunch at Hills Restaurant in Kennewick – September 25 2020', 11439 ),
	12410 => array( '2019-03-09 12:00:35', 'Breakfast at Hills Restaurant in Kennewick – March 9 2019', 9820 ),
	12015 => array( '2019-01-05 19:57:08', 'Bacon Cheeseburger at Hills Restaurant in Kennewick – January 5 2019', 9470 ),
	4658  => array( '2015-10-17 08:41:42', 'Pulled Pork Eggs Benedict at Hills Restaurant – October 17 2015', 1832 ),
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

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
