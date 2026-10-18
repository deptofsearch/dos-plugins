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

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
