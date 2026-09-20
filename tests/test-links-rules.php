<?php
/**
 * Link rules: what a rule is allowed to be, and how the reporting adds up.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options'] = array();
$GLOBALS['rows']    = array();
$GLOBALS['next_id'] = 1;
$GLOBALS['posts']   = array( 99 => 'publish', 98 => 'publish', 50 => 'draft' );

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function current_time( $t = 'mysql' ) { return '2026-09-20 10:00:00'; }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : array() ); }
function get_post( $id ) { return isset( $GLOBALS['posts'][ (int) $id ] ) ? (object) array( 'ID' => (int) $id ) : null; }
function get_post_status( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? false; }
function url_to_postid( $u ) { return false !== strpos( $u, '/agents' ) ? 98 : 0; }
function home_url( $p = '/' ) { return 'https://example.com' . $p; }
function absint( $v ) { return abs( (int) $v ); }
function get_page_by_title( $t, $o = null, $types = array() ) { return 'Agents Page' === $t ? (object) array( 'ID' => 98 ) : null; }
define( 'OBJECT', 'OBJECT' );

class WP_Error {
    public $code; private $msg;
    public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
    public function get_error_message() { return $this->msg; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class FakeWpdb {
    public $prefix = 'wp_'; public $insert_id = 0;
    public function get_charset_collate() { return ''; }
    public function prepare( $q, ...$a ) {
        foreach ( $a as $v ) { $q = preg_replace( '/%[sd]/', is_int( $v ) ? (string) $v : "'" . $v . "'", $q, 1 ); }
        return $q;
    }
    public function get_results( $q, $o = null ) { return array_values( $GLOBALS['rows'] ); }
    public function get_row( $q, $o = null ) {
        if ( preg_match( "/phrase = '([^']*)'/", $q, $m ) ) {
            foreach ( $GLOBALS['rows'] as $r ) { if ( $r['phrase'] === $m[1] ) { return $r; } }
        }
        if ( preg_match( '/id = (\d+)/', $q, $m ) ) { return $GLOBALS['rows'][ (int) $m[1] ] ?? null; }
        return null;
    }
    public function insert( $t, $d, $f = null ) {
        $id = $GLOBALS['next_id']++;
        $d['id'] = $id; $d['enabled'] = 1; $d['links_made'] = 0; $d['opportunities'] = 0; $d['pages_linked'] = 0;
        $GLOBALS['rows'][ $id ] = $d; $this->insert_id = $id; return 1;
    }
    public function update( $t, $d, $w, $f = null, $wf = null ) {
        $id = (int) $w['id'];
        if ( isset( $GLOBALS['rows'][ $id ] ) ) { $GLOBALS['rows'][ $id ] = array_merge( $GLOBALS['rows'][ $id ], $d ); return 1; }
        return 0;
    }
    public function delete( $t, $w, $f = null ) { unset( $GLOBALS['rows'][ (int) $w['id'] ] ); return 1; }
    public function query( $q ) { return 0; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/modules/links/class-dos-links-rules.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

echo "--- what a phrase may be ---\n";
check( 'refuses a single word', is_wp_error( DOS_Links_Rules::add( 'roofing', 99 ) ) );
check( '  and says why', 'dos_links_one_word' === DOS_Links_Rules::add( 'roofing', 99 )->code );
check( 'refuses an empty phrase', is_wp_error( DOS_Links_Rules::add( '   ', 99 ) ) );
check( 'accepts two words', is_int( DOS_Links_Rules::add( 'roof repair', 99 ) ) );
check( 'accepts more than two', is_int( DOS_Links_Rules::add( 'emergency roof repair phoenix', 99 ) ) );

$id = DOS_Links_Rules::add( '  roof   repair  ', 99 );
check( 'collapses stray whitespace to match the existing rule', $id === DOS_Links_Rules::by_phrase( 'roof repair' )['id'], json_encode( $id ) );

echo "\n--- what a target may be ---\n";
check( 'refuses a missing target', is_wp_error( DOS_Links_Rules::add( 'gutter cleaning', 0 ) ) );
check( 'refuses a target that does not exist', is_wp_error( DOS_Links_Rules::add( 'gutter cleaning', 12345 ) ) );
check( 'refuses an unpublished target', is_wp_error( DOS_Links_Rules::add( 'gutter cleaning', 50 ) ) );
check( '  and says why', 'dos_links_unpublished' === DOS_Links_Rules::add( 'gutter cleaning', 50 )->code );

echo "\n--- limits are clamped ---\n";
$id = DOS_Links_Rules::add( 'tile roofing', 99, array( 'max_per_page' => 0, 'throttle' => 500, 'first_instance' => 'nonsense' ) );
$row = DOS_Links_Rules::get( $id );
check( 'links per page cannot be zero', 1 === (int) $row['max_per_page'], json_encode( $row['max_per_page'] ) );
check( 'throttle cannot exceed 100', 100 === (int) $row['throttle'] );
check( 'an unknown first-occurrence value falls back to linking it', 'link' === $row['first_instance'] );

$id = DOS_Links_Rules::add( 'flat roofing', 99, array( 'throttle' => -20 ) );
check( 'throttle cannot go below zero', 0 === (int) DOS_Links_Rules::get( $id )['throttle'] );

echo "\n--- share of the linking profile ---\n";
$all = array(
    array( 'id' => 1, 'links_made' => 60 ),
    array( 'id' => 2, 'links_made' => 30 ),
    array( 'id' => 3, 'links_made' => 10 ),
);
check( 'the dominant phrase reports 60 per cent', 60.0 === DOS_Links_Rules::share( $all[0], $all ) );
check( 'the smallest reports 10 per cent', 10.0 === DOS_Links_Rules::share( $all[2], $all ) );
check( 'no links anywhere reports zero rather than dividing by zero', 0.0 === DOS_Links_Rules::share( array( 'id' => 1, 'links_made' => 0 ), array( array( 'id' => 1, 'links_made' => 0 ) ) ) );

echo "\n--- importing many at once ---\n";
$GLOBALS['rows'] = array(); $GLOBALS['next_id'] = 1;

$text = "# a comment line\n"
      . "open houses in phoenix | 99\n"
      . "phoenix real estate agent | /agents/ | 2 | skip | 60\n"
      . "listing agent | Agents Page\n"
      . "\n"
      . "roofing | 99\n"
      . "broken phrase | nowhere-at-all\n";

$res = DOS_Links_Rules::add_many( $text );

check( 'skips comments and blank lines', 5 === count( $res ), count( $res ) . ' results' );
check( 'adds a plain phrase with a numeric destination', $res[0]['ok'] );
check( 'accepts a URL as the destination', $res[1]['ok'], $res[1]['message'] );
check( '  and carries the extra fields through', 2 === (int) DOS_Links_Rules::by_phrase( 'phoenix real estate agent' )['max_per_page'] );
check( '  including the first-occurrence choice', 'skip' === DOS_Links_Rules::by_phrase( 'phoenix real estate agent' )['first_instance'] );
check( '  and the percentage', 60 === (int) DOS_Links_Rules::by_phrase( 'phoenix real estate agent' )['throttle'] );
check( 'accepts an exact title as the destination', $res[2]['ok'], $res[2]['message'] );
check( 'still refuses a single word', ! $res[3]['ok'] );
check( 'refuses a destination that matches nothing', ! $res[4]['ok'] );
check( '  and says what to use instead', false !== strpos( $res[4]['message'], 'exact title' ), $res[4]['message'] );

echo "\n--- destination resolution ---\n";
check( 'a numeric ID', 99 === DOS_Links_Rules::resolve_target( '99' ) );
check( 'a full URL', 98 === DOS_Links_Rules::resolve_target( 'https://example.com/agents/' ) );
check( 'a site-relative path', 98 === DOS_Links_Rules::resolve_target( '/agents/' ) );
check( 'an exact title', 98 === DOS_Links_Rules::resolve_target( 'Agents Page' ) );
check( 'nothing at all', 0 === DOS_Links_Rules::resolve_target( '' ) );
check( 'something unmatchable', 0 === DOS_Links_Rules::resolve_target( 'no such page anywhere' ) );

echo "\n--- bulk actions ---\n";
$ids = array_keys( $GLOBALS['rows'] );
check( 'switching several off', 2 === DOS_Links_Rules::bulk( 'disable', array_slice( $ids, 0, 2 ) ) );
check( '  actually switched them off', 0 === (int) $GLOBALS['rows'][ $ids[0] ]['enabled'] );
check( 'switching them back on', 2 === DOS_Links_Rules::bulk( 'enable', array_slice( $ids, 0, 2 ) ) );
check( 'deleting several', 1 === DOS_Links_Rules::bulk( 'delete', array( $ids[0] ) ) );
check( '  actually removed the row', ! isset( $GLOBALS['rows'][ $ids[0] ] ) );
check( 'an empty selection does nothing', 0 === DOS_Links_Rules::bulk( 'delete', array() ) );
check( 'a nonsense action changes nothing', 1 === DOS_Links_Rules::bulk( 'explode', array( $ids[1] ) ) && isset( $GLOBALS['rows'][ $ids[1] ] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
