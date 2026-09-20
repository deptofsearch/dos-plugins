<?php
/**
 * Finding pages to tag: plain search, regular expressions, statuses, paging.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options'] = array();
$GLOBALS['pages']   = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : array() ); }
function wp_list_pluck( $rows, $field ) { return array_map( function ( $r ) use ( $field ) { return is_array( $r ) ? $r[ $field ] : $r->$field; }, $rows ); }
function is_wp_error( $t ) { return false; }
function wp_get_object_terms( $ids, $tax, $args = array() ) { return array(); }

class FakeWpdb {
    public $posts = 'wp_posts';
    public function prepare( $q, ...$a ) {
        if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
        foreach ( $a as $v ) { $q = preg_replace( '/%[sd]/', is_int( $v ) ? (string) $v : "'" . $v . "'", $q, 1 ); }
        return $q;
    }
    public function get_results( $q, $o = null ) {
        preg_match_all( "/'([a-z]+)'/", $q, $m );
        $statuses = $m[1];
        $rows = array_values( array_filter( $GLOBALS['pages'], function ( $p ) use ( $statuses ) {
            return in_array( $p['post_status'], $statuses, true );
        } ) );
        usort( $rows, function ( $a, $b ) { return strcmp( $a['post_title'], $b['post_title'] ); } );
        return $rows;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/modules/utilities/class-dos-page-tags.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}
function titles( $result ) { return wp_list_pluck( $result['rows'], 'post_title' ); }

$GLOBALS['pages'] = array(
    array( 'ID' => 1, 'post_title' => 'Roof Repair Phoenix',    'post_status' => 'publish' ),
    array( 'ID' => 2, 'post_title' => 'Roof Replacement',       'post_status' => 'publish' ),
    array( 'ID' => 3, 'post_title' => 'Gutter Cleaning',        'post_status' => 'draft' ),
    array( 'ID' => 4, 'post_title' => 'About Us',               'post_status' => 'publish' ),
    array( 'ID' => 5, 'post_title' => 'Services in Scottsdale', 'post_status' => 'publish' ),
    array( 'ID' => 6, 'post_title' => 'Services in Mesa',       'post_status' => 'private' ),
);

echo "--- plain search ---\n";
$r = DOS_Page_Tags::search( array() );
check( 'no search returns everything', 6 === $r['total'], (string) $r['total'] );
check( '  in title order', 'About Us' === $r['rows'][0]['post_title'], json_encode( titles( $r ) ) );

$r = DOS_Page_Tags::search( array( 'search' => 'roof' ) );
check( 'matches anywhere in the title', 2 === $r['total'], json_encode( titles( $r ) ) );
check( '  and ignores case', in_array( 'Roof Repair Phoenix', titles( $r ), true ) );

check( 'no match returns nothing rather than everything', 0 === DOS_Page_Tags::search( array( 'search' => 'zzz' ) )['total'] );

echo "\n--- regular expressions ---\n";
$r = DOS_Page_Tags::search( array( 'search' => '^Services', 'regex' => true ) );
check( 'anchors work', 2 === $r['total'], json_encode( titles( $r ) ) );

$r = DOS_Page_Tags::search( array( 'search' => '(roof|gutter)', 'regex' => true ) );
check( 'alternation works', 3 === $r['total'], json_encode( titles( $r ) ) );

$r = DOS_Page_Tags::search( array( 'search' => 'Phoenix$', 'regex' => true ) );
check( 'end anchors work', 1 === $r['total'], json_encode( titles( $r ) ) );

$r = DOS_Page_Tags::search( array( 'search' => '^Services', 'regex' => false ) );
check( 'the same pattern as plain text matches nothing', 0 === $r['total'], json_encode( titles( $r ) ) );

$r = DOS_Page_Tags::search( array( 'search' => 'Services in (Scottsdale', 'regex' => true ) );
check( 'an invalid pattern is reported, not thrown', '' !== $r['error'], json_encode( $r ) );
check( '  and returns nothing rather than everything', 0 === $r['total'] );

$r = DOS_Page_Tags::search( array( 'search' => 'a/b', 'regex' => true ) );
check( 'a slash in the pattern does not break the delimiter', '' === $r['error'], json_encode( $r ) );

echo "\n--- statuses ---\n";
check( 'any includes drafts and private', 6 === DOS_Page_Tags::search( array( 'status' => 'any' ) )['total'] );
check( 'published only', 4 === DOS_Page_Tags::search( array( 'status' => 'publish' ) )['total'] );
check( 'drafts only', 1 === DOS_Page_Tags::search( array( 'status' => 'draft' ) )['total'] );
check( 'a nonsense status falls back to any rather than nothing', 6 === DOS_Page_Tags::search( array( 'status' => 'wat' ) )['total'] );

echo "\n--- paging and applying to everything ---\n";
$r = DOS_Page_Tags::search( array() );
check( 'every matching ID is returned for the apply-to-all case', 6 === count( $r['ids'] ), json_encode( $r['ids'] ) );
check( '  even though only one page of rows is shown', count( $r['rows'] ) <= DOS_Page_Tags::PER_PAGE );
check( 'page count is worked out from the matches', 1 === $r['pages'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
