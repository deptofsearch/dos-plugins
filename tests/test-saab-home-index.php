<?php
/**
 * DoS Shot and a Beer Home: building the search index on a site with restaurant place pages (shotandabeer.com).
 *
 * The restaurant tag and the place post type share the name saab_restaurant, so the tag's archive is a 404 there.
 * Restaurants must come from the place pages (with their reviews' tag names as aliases), restaurant tags without a
 * place must link to their newest review, and no entry may link to /restaurant/<slug>/.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DOS_SAAB_HOME_VERSION', '9.9.9' );
$GLOBALS['set'] = array();

class WP_Term {
	public $term_id; public $name; public $slug; public $count; public $taxonomy;
	function __construct( $id, $tax, $name, $count ) { $this->term_id = $id; $this->taxonomy = $tax; $this->name = $name; $this->slug = strtolower( preg_replace( '/\W+/', '-', $name ) ); $this->count = $count; }
}
class WP_Post {
	public $ID; public $post_title; public $post_type;
	function __construct( $id, $type, $title ) { $this->ID = $id; $this->post_type = $type; $this->post_title = $title; }
}

// Site data. Places 1001 and 1002; reviews 1-6. Review 6 (STK) belongs to no place.
$GLOBALS['places'] = array(
	new WP_Post( 1001, 'saab_restaurant', 'Bangkok Thai Cuisine' ),
	new WP_Post( 1002, 'saab_restaurant', 'Rocco&#8217;s Pizza' ),
);
$GLOBALS['meta'] = array(
	1001 => array( 'saab_linked_posts' => '[1,2,3]', 'saab_locations' => '[{"city":"Kennewick"}]' ),
	1002 => array( 'saab_linked_posts' => '[4,5]', 'saab_locations' => '[{"city":"Kennewick"}]' ),
);
$GLOBALS['restaurant_terms'] = array(
	new WP_Term( 10, 'saab_restaurant', 'Bangkok Thai Restaurant', 2 ),
	new WP_Term( 11, 'saab_restaurant', 'Bangkok Thai', 1 ),
	new WP_Term( 12, 'saab_restaurant', 'Rocco&#8217;s Pizza', 2 ),
	new WP_Term( 13, 'saab_restaurant', 'STK', 1 ),
);
$GLOBALS['term_posts'] = array( 10 => array( 2, 1 ), 11 => array( 3 ), 12 => array( 5, 4 ), 13 => array( 6 ) ); // Newest first.
$GLOBALS['post_restaurant'] = array( 1 => 'Bangkok Thai Restaurant', 2 => 'Bangkok Thai Restaurant', 3 => 'Bangkok Thai', 4 => 'Rocco&#8217;s Pizza', 5 => 'Rocco&#8217;s Pizza', 6 => 'STK' );
$GLOBALS['other_terms'] = array( 'saab_cuisine' => array( new WP_Term( 20, 'saab_cuisine', 'Thai', 3 ) ) );

function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { $GLOBALS['set'][] = $k; return true; }
function taxonomy_exists( $t ) { return in_array( $t, array( 'saab_restaurant', 'saab_cuisine' ), true ); }
function post_type_exists( $t ) { return 'saab_restaurant' === $t; }
function is_wp_error( $x ) { return false; }
function _n( $s, $p, $n ) { return 1 === $n ? $s : $p; }
function get_the_title( $p ) { return $p->post_title; }
function get_permalink( $p ) { $id = is_object( $p ) ? $p->ID : $p; return 'https://example.com/' . ( $id > 1000 ? 'place-' : 'review-' ) . $id . '/'; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function get_term_link( $t ) { return 'https://example.com/' . str_replace( 'saab_', '', $t->taxonomy ) . '/' . $t->slug . '/'; }
function wp_get_object_terms( $ids, $tax, $args ) { $out = array(); foreach ( $ids as $id ) { $out[] = $GLOBALS['post_restaurant'][ $id ]; } return $out; }
function get_terms( $args ) {
	return 'saab_restaurant' === $args['taxonomy'] ? $GLOBALS['restaurant_terms'] : ( $GLOBALS['other_terms'][ $args['taxonomy'] ] ?? array() );
}
function get_posts( $args ) {
	if ( 'saab_restaurant' === $args['post_type'] ) {
		return $GLOBALS['places'];
	}
	if ( isset( $args['tax_query'][0] ) ) {
		$ids = $GLOBALS['term_posts'][ $args['tax_query'][0]['terms'] ] ?? array();
		return isset( $args['posts_per_page'] ) && 1 === $args['posts_per_page'] ? array_slice( $ids, 0, 1 ) : $ids;
	}
	return array();
}

require dirname( __DIR__ ) . '/plugins/dos-saab-home/includes/class-search.php';

use DOS_SAAB\Search;

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

$index = Search::index();
$by    = array();
foreach ( $index as $e ) {
	$by[ $e['g'] . ':' . $e['n'] ] = $e;
}

$bk = $by['r:Bangkok Thai Cuisine'] ?? null;
check( 'a place is a Restaurants entry, named after the place', null !== $bk, json_encode( array_keys( $by ) ) );
check( 'it links to the place page', $bk && 'https://example.com/place-1001/' === $bk['u'] );
check( 'its grey line is the city and the review count', $bk && 'Kennewick · 3 reviews' === $bk['s'], json_encode( $bk['s'] ?? null ) );
check( 'its reviews\' tag names ride along as aliases', $bk && array( 'Bangkok Thai Restaurant', 'Bangkok Thai' ) === $bk['a'], json_encode( $bk['a'] ?? null ) );
check( 'the internal linked-post list is not in the index', $bk && ! isset( $bk['p'] ) );

$rc = $by['r:Rocco’s Pizza'] ?? null;
check( 'a tag name equal to the place name is not repeated as an alias', $rc && array() === $rc['a'], json_encode( $rc ) );

check( 'restaurant tags covered by a place get no entry of their own', ! isset( $by['r:Bangkok Thai Restaurant'] ) && ! isset( $by['r:Bangkok Thai'] ) );
$stk = $by['r:STK'] ?? null;
check( 'a restaurant tag with no place page still gets an entry', null !== $stk );
check( '... linking to its newest review, not the tag archive', $stk && 'https://example.com/review-6/' === $stk['u'], json_encode( $stk ) );

$bad = array_filter( $index, function ( $e ) { return false !== strpos( $e['u'], '/restaurant/' ); } );
check( 'no entry links to /restaurant/<slug>/', ! $bad, json_encode( array_values( $bad ) ) );
check( 'other kinds still link to their archive', isset( $by['c:Thai'] ) && 'https://example.com/cuisine/thai/' === $by['c:Thai']['u'] );

check( '"bangkok thai restaurant" finds the place', array( 'Bangkok Thai Cuisine' ) === array_column( Search::match( $index, 'bangkok thai restaurant' )[0]['items'] ?? array(), 'n' ) );

check( 'the cache key carries the plugin version, so an update never serves the previous build', in_array( 'saab_home_search_v1_9.9.9', $GLOBALS['set'], true ), json_encode( $GLOBALS['set'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
