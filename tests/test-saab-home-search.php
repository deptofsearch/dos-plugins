<?php
/**
 * DoS Shot and a Beer Home: the search match. Which entries a query finds, in what order, how they are grouped and
 * capped, and what the scope buttons leave out. assets/saab-home.js runs the same rules in the browser.
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/plugins/dos-saab-home/includes/class-search.php';

use DOS_SAAB\Search;

$pass = 0;
$fail = 0;

function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

function e( $n, $g, $k = 1 ) {
	return array( 'n' => $n, 'g' => $g, 'u' => 'https://example.com/' . strtolower( $g . '-' . $n ), 's' => '', 'k' => $k );
}

function names( array $groups, $key ) {
	foreach ( $groups as $g ) {
		if ( $g['key'] === $key ) {
			return array_column( $g['items'], 'n' );
		}
	}
	return array();
}

$index = array(
	e( 'Picante Mexican Taqueria', 'r', 2 ),
	e( 'Tomatillo Authentic Mexican Flavors', 'r', 1 ),
	e( 'KC’s Biscuit & BBQ', 'r', 1 ),
	e( 'Capones', 'r', 3 ),
	e( 'Mexican', 'c', 9 ),
	e( 'BBQ', 'c', 4 ),
	e( 'Birria Beef Ramen', 'd', 1 ),
	e( 'Giant Chicken Tenders', 'd', 1 ),
	e( 'Crispy Chicken Sandwich', 'd', 2 ),
	e( 'Chicken Fried Steak', 'd', 1 ),
	e( 'Kennewick', 'x', 20 ),
);

echo "--- basics ---\n";
check( 'one character matches nothing', array() === Search::match( $index, 'm' ) );
check( 'blank matches nothing', array() === Search::match( $index, '   ' ) );
check( 'no match gives no groups', array() === Search::match( $index, 'sushi' ) );

echo "--- grouping and order ---\n";
$g = Search::match( $index, 'mex' );
check( 'groups come in restaurant, cuisine, dish, city order', array( 'r', 'c' ) === array_column( $g, 'key' ), json_encode( array_column( $g, 'key' ) ) );
check( 'groups carry their labels', 'Restaurants' === $g[0]['label'] && 'Cuisines' === $g[1]['label'] );
check( 'the cuisine "Mexican" is found', array( 'Mexican' ) === names( $g, 'c' ) );
check( 'restaurants: word-start matches tie, more reviews first', array( 'Picante Mexican Taqueria', 'Tomatillo Authentic Mexican Flavors' ) === names( $g, 'r' ), json_encode( names( $g, 'r' ) ) );

$g = Search::match( $index, 'chicken' );
check( 'a name starting with the query ranks above one containing it', 'Chicken Fried Steak' === names( $g, 'd' )[0], json_encode( names( $g, 'd' ) ) );
check( 'then more reviews first', array( 'Chicken Fried Steak', 'Crispy Chicken Sandwich', 'Giant Chicken Tenders' ) === names( $g, 'd' ), json_encode( names( $g, 'd' ) ) );

$g = Search::match( $index, 'ken' );
check( 'a match inside a word still counts', array( 'Kennewick' ) === names( $g, 'x' ) && 3 === count( names( $g, 'd' ) ) );
check( 'in-word matches tie, so more reviews first', 'Crispy Chicken Sandwich' === names( $g, 'd' )[0], json_encode( names( $g, 'd' ) ) );

echo "--- folding ---\n";
check( 'case does not matter', names( Search::match( $index, 'BIRRIA' ), 'd' ) === array( 'Birria Beef Ramen' ) );
check( 'a straight apostrophe finds a curly one', names( Search::match( $index, "kc's" ), 'r' ) === array( 'KC’s Biscuit & BBQ' ) );
check( 'extra spaces are squeezed', names( Search::match( $index, '  beef   ramen ' ), 'd' ) === array( 'Birria Beef Ramen' ) );

echo "--- scope and cap ---\n";
$g = Search::match( $index, 'bbq', 'c' );
check( 'a scope keeps only that group', array( 'c' ) === array_column( $g, 'key' ) && array( 'BBQ' ) === names( $g, 'c' ) );
$g = Search::match( $index, 'chicken', '', 2 );
check( 'each group is capped', 2 === count( names( $g, 'd' ) ) );
$g = Search::match( $index, 'chicken', '', 0 );
check( 'a cap under 1 still shows one', 1 === count( names( $g, 'd' ) ) );

echo "--- totals ---\n";
$g = Search::match( $index, 'chicken', '', 1 );
check( 'total counts every match, items stop at the cap', 3 === $g[0]['total'] && 1 === count( $g[0]['items'] ), json_encode( $g[0] ) );
$g = Search::match( $index, 'chicken' );
check( 'the default cap is 5', Search::PER_GROUP === 5 && 3 === count( names( $g, 'd' ) ) );

echo "--- dishes from review titles ---\n";
$cases = array(
	'The Meaty Pizza at Greek Islands in Richland – September 11, 2026'                          => array( 'The Meaty Pizza', 'Greek Islands in Richland' ),
	'Personal Pizza Pepperoni & Sausage at Rocco’s Pizza – September 30, 2026'                   => array( 'Personal Pizza Pepperoni & Sausage', 'Rocco’s Pizza' ),
	'Mashed Potatoes & Turkey Gravy – August 27, 2026'                                           => array( 'Mashed Potatoes & Turkey Gravy', '' ),
	'Breakfast at Hill’s Restaurant — Pulled Pork Benedict – March 8, 2026'                      => array( 'Pulled Pork Benedict', 'Hill’s Restaurant' ),
	'Lunch at Tomatillo Authentic Mexican Flavors in Kennewick - January 8 2023'                 => array( 'Lunch', 'Tomatillo Authentic Mexican Flavors in Kennewick' ),
	'Saumon à la Crème de Fenouil at The Bradley in Richland – October 3, 2026'                  => array( 'Saumon à la Crème de Fenouil', 'The Bradley in Richland' ),
	'Chicken Teriyaki and Loco Moco at Huli Huli ‘Ohana BBQ in Downtown Kennewick – August 6, 2026' => array( 'Chicken Teriyaki and Loco Moco', 'Huli Huli ‘Ohana BBQ in Downtown Kennewick' ),
	'12oz Ribeye and Garlic Truffle Fries at STK - Nov 4, 2025'                                  => array( '12oz Ribeye and Garlic Truffle Fries', 'STK' ),
	'A title with no date or place'                                                              => array( 'A title with no date or place', '' ),
);
foreach ( $cases as $title => $want ) {
	$d = Search::dish_part( $title );
	$p = Search::place_part( $title );
	check( 'dish of "' . $title . '"', $want[0] === $d, json_encode( $d ) );
	check( 'place of "' . $title . '"', $want[1] === $p, json_encode( $p ) );
}
check( '"Pizza" finds the meaty pizza review dish', in_array( 'The Meaty Pizza', names( Search::match( array( e( 'The Meaty Pizza', 'd', 0 ), e( 'Pizza Speedway', 'r', 2 ) ), 'pizza', 'd' ), 'd' ), true ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
