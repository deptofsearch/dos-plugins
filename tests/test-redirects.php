<?php
/**
 * Redirects: normalising, loop refusal, target sanitising, query carry-over
 * and the noise filter on the 404 log.
 *
 * These run on every front-end request and one of them can lock a site's
 * visitors out of it, so the rules are pinned rather than assumed.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['options'] = array();
$GLOBALS['cache']   = array();
$GLOBALS['rows']    = array();   // id => row
$GLOBALS['next_id'] = 1;

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_cache_get( $k, $g = '' ) { return $GLOBALS['cache'][ $g . $k ] ?? false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { $GLOBALS['cache'][ $g . $k ] = $v; return true; }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['cache'][ $g . $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function apply_filters( $t, $v ) { return $v; }
function add_action() {} function add_filter() {}
function current_time( $t = 'mysql' ) { return date( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 1; }
function home_url( $p = '/' ) { return 'https://example.com' . $p; }
function wp_parse_url( $u, $c = -1 ) {
    $p = parse_url( $u );
    if ( -1 === $c ) { return $p; }
    $map = array( PHP_URL_HOST => 'host', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query' );
    $k = $map[ $c ] ?? null;
    return $k && isset( $p[ $k ] ) ? $p[ $k ] : null;
}
function esc_url_raw( $u, $protocols = null ) {
    $u = trim( (string) $u );
    if ( preg_match( '#^(javascript|data|vbscript|file):#i', $u ) ) { return ''; }
    return $u;
}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }

class WP_Error {
    public $code; private $msg;
    public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
    public function get_error_message() { return $this->msg; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/** Minimal $wpdb over an in-memory row list. */
class FakeWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public function get_charset_collate() { return ''; }
    public function prepare( $q, ...$a ) {
        foreach ( $a as $v ) {
            $q = preg_replace( '/%[sd]/', is_int( $v ) ? (string) $v : "'" . $v . "'", $q, 1 );
        }
        return $q;
    }
    public function get_results( $q, $out = null ) {
        $rows = array_values( $GLOBALS['rows'] );
        if ( false !== strpos( $q, 'enabled = 1' ) ) {
            $rows = array_values( array_filter( $rows, function ( $r ) { return 1 === (int) $r['enabled']; } ) );
        }
        return $rows;
    }
    public function get_row( $q, $out = null ) {
        if ( preg_match( "/source = '([^']*)'/", $q, $m ) ) {
            foreach ( $GLOBALS['rows'] as $r ) { if ( $r['source'] === $m[1] ) { return $r; } }
        }
        return null;
    }
    public function get_var( $q ) { return count( $GLOBALS['rows'] ); }
    public function insert( $t, $data, $fmt = null ) {
        $id = $GLOBALS['next_id']++;
        $data['id'] = $id; $data['enabled'] = $data['enabled'] ?? 1; $data['hits'] = 0;
        $GLOBALS['rows'][ $id ] = $data;
        $this->insert_id = $id;
        return 1;
    }
    public function update( $t, $data, $where, $f = null, $wf = null ) {
        $id = (int) $where['id'];
        if ( isset( $GLOBALS['rows'][ $id ] ) ) { $GLOBALS['rows'][ $id ] = array_merge( $GLOBALS['rows'][ $id ], $data ); return 1; }
        return 0;
    }
    public function delete( $t, $where, $f = null ) { unset( $GLOBALS['rows'][ (int) $where['id'] ] ); return 1; }
    public function query( $q ) { return 0; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

class DOS_Log { public static function add() {} public static function prune() {} }

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/modules/redirects/class-dos-redirects-store.php';
require PLUGIN . '/modules/redirects/class-dos-redirects-router.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}
function reset_store() {
    $GLOBALS['rows'] = array(); $GLOBALS['next_id'] = 1;
    $GLOBALS['cache'] = array(); $GLOBALS['options'] = array();
}

echo "--- normalising ---\n";
$n = array( 'DOS_Redirects_Store', 'normalise' );
check( 'adds a leading slash', '/old' === call_user_func( $n, 'old' ) );
check( 'drops a trailing slash', '/old' === call_user_func( $n, '/old/' ) );
check( 'lowercases', '/old-page' === call_user_func( $n, '/Old-Page' ) );
check( 'drops the query string', '/old' === call_user_func( $n, '/old?utm_source=x' ) );
check( 'drops the fragment', '/old' === call_user_func( $n, '/old#section' ) );
check( 'accepts a full URL', '/old' === call_user_func( $n, 'https://example.com/old/' ) );
check( 'root stays root', '/' === call_user_func( $n, '/' ) );
check( 'empty stays empty', '' === call_user_func( $n, '' ) );

echo "\n--- targets ---\n";
check( 'keeps an internal path', '/new' === DOS_Redirects_Store::clean_target( '/new' ) );
check( 'adds a leading slash', '/new' === DOS_Redirects_Store::clean_target( 'new' ) );
check( 'keeps an external https URL', 'https://elsewhere.com/x' === DOS_Redirects_Store::clean_target( 'https://elsewhere.com/x' ) );
check( 'refuses javascript:', '' === DOS_Redirects_Store::clean_target( 'javascript:alert(1)' ), DOS_Redirects_Store::clean_target( 'javascript:alert(1)' ) );
check( 'refuses data:', '' === DOS_Redirects_Store::clean_target( 'data:text/html,x' ) );
check( 'refuses mailto:', '' === DOS_Redirects_Store::clean_target( 'mailto:a@b.c' ) );

// A protocol-relative URL starts with a slash, so anything that treats
// "starts with /" as "internal" hands out an open redirect.
check( 'refuses a protocol-relative URL', '' === DOS_Redirects_Store::clean_target( '//evil.example/x' ), DOS_Redirects_Store::clean_target( '//evil.example/x' ) );
check( '  and one dressed up with a path', '' === DOS_Redirects_Store::clean_target( '//evil.example/looks/like/a/path' ) );
check( 'refuses an empty target outright', '' === DOS_Redirects_Store::clean_target( '   ' ) );

echo "\n--- adding rules ---\n";
reset_store();
$id = DOS_Redirects_Store::add( '/old-page/', '/new-page' );
check( 'adds a rule', is_int( $id ) && $id > 0, json_encode( $id ) );
check( '  stored normalised', isset( DOS_Redirects_Store::map()['/old-page'] ), json_encode( array_keys( DOS_Redirects_Store::map() ) ) );

$again = DOS_Redirects_Store::add( '/OLD-PAGE', '/newer-page' );
check( 'the same path updates rather than duplicating', $again === $id, "first=$id second=" . json_encode( $again ) );
check( '  and the target changed', '/newer-page' === DOS_Redirects_Store::map()['/old-page']['target'] );

check( 'refuses an empty target', is_wp_error( DOS_Redirects_Store::add( '/a', '' ) ) );
check( 'refuses redirecting the site root', is_wp_error( DOS_Redirects_Store::add( '/', '/somewhere' ) ) );
check( 'refuses a path that is too long to index', is_wp_error( DOS_Redirects_Store::add( '/' . str_repeat( 'a', 200 ), '/x' ) ) );

echo "\n--- loops ---\n";
reset_store();
check( 'refuses a rule pointing at itself', is_wp_error( DOS_Redirects_Store::add( '/loop', '/loop/' ) ) );

DOS_Redirects_Store::add( '/a', '/b' );
DOS_Redirects_Store::add( '/b', '/c' );
$r = DOS_Redirects_Store::add( '/c', '/a' );
check( 'refuses a rule that closes a chain', is_wp_error( $r ), json_encode( $r ) );
check( '  a chain that does not close is allowed', is_int( DOS_Redirects_Store::add( '/d', '/e' ) ) );
check( '  an external target cannot loop back', is_int( DOS_Redirects_Store::add( '/f', 'https://elsewhere.com/a' ) ) );

echo "\n--- query strings survive the move ---\n";
$q = array( 'DOS_Redirects_Router', 'with_query' );
check( 'carries the incoming query across', '/new?utm_source=mail' === call_user_func( $q, '/new', '/old?utm_source=mail' ) );
check( 'leaves a target that has its own query alone', '/new?a=1' === call_user_func( $q, '/new?a=1', '/old?b=2' ) );
check( 'no query, no change', '/new' === call_user_func( $q, '/new', '/old' ) );

echo "\n--- the 404 log ignores probe traffic ---\n";
foreach ( array( '/wp-config.php', '/.env', '/vendor/phpunit/x', '/admin.asp', '/.git/config', '/backup.sql' ) as $junk ) {
    check( "filters $junk", DOS_Redirects_Router::is_noise( $junk ) );
}
foreach ( array( '/about-us', '/2024/09/old-post', '/images/photo.jpg', '/services/roof-repair' ) as $real ) {
    check( "keeps $real", ! DOS_Redirects_Router::is_noise( $real ) );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
