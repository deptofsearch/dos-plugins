<?php
/**
 * Importing from the Redirection plugin. Only an enabled, exact-URL rule that
 * redirects means the same thing here; everything else is skipped and named.
 * A path that already has a redirect here is never overwritten, and a dry run
 * writes nothing.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['options'] = array();
$GLOBALS['cache']   = array();
$GLOBALS['rows']    = array();   // DoS redirects: id => row
$GLOBALS['next_id'] = 1;
$GLOBALS['log']     = array();

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
function wp_list_pluck( $list, $field ) { return array_column( $list, $field ); }
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

// Redirection's side of the database.
$GLOBALS['tables']           = array( 'wp_dos_redirects', 'wp_dos_404', 'wp_redirection_items', 'wp_redirection_groups' );
$GLOBALS['redirection']      = array();   // item rows
$GLOBALS['redirection_groups'] = array( 1 => 'enabled', 2 => 'disabled' );

$GLOBALS['queries']     = array();   // every read query received, after prepare()
$GLOBALS['bad_prepare'] = array();   // prepare() calls whose placeholders and args disagree

class FakeWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public function get_charset_collate() { return ''; }
    public function prepare( $q, ...$a ) {
        if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
        if ( preg_match_all( '/%[sd]/', $q ) !== count( $a ) ) { $GLOBALS['bad_prepare'][] = $q; }
        return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$a ) {
            $v = array_shift( $a );
            return '%d' === $m[0] ? (string) (int) $v : "'" . $v . "'";
        }, $q );
    }
    public function get_results( $q, $out = null ) {
        $GLOBALS['queries'][] = $q;
        if ( false !== strpos( $q, 'FROM wp_redirection_items' ) ) {
            preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $q, $m );
            $items = $GLOBALS['redirection'];
            usort( $items, function ( $a, $b ) { return $a['id'] - $b['id']; } );
            return array_slice( $items, (int) $m[2], (int) $m[1] );
        }
        if ( false !== strpos( $q, 'FROM wp_redirection_groups' ) ) {
            $rows = array();
            foreach ( $GLOBALS['redirection_groups'] as $id => $status ) { $rows[] = array( 'id' => $id, 'status' => $status ); }
            return $rows;
        }
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
    public function esc_like( $v ) { return addcslashes( (string) $v, '_%\\' ); }
    public function get_var( $q ) {
        $GLOBALS['queries'][] = $q;
        if ( preg_match( "/SHOW TABLES LIKE '([^']*)'/", $q, $m ) ) {
            $name = stripslashes( $m[1] );
            return in_array( $name, $GLOBALS['tables'], true ) ? $name : null;
        }
        if ( false !== strpos( $q, 'FROM wp_redirection_items' ) ) { return count( $GLOBALS['redirection'] ); }
        return count( $GLOBALS['rows'] );
    }
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

class DOS_Log { public static function add() { $GLOBALS['log'][] = func_get_args(); } public static function prune() {} }

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/includes/class-dos-module.php';
require PLUGIN . '/modules/redirects/class-dos-redirects.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

function item( $id, $url, $target, array $over = array() ) {
    return array_merge( array(
        'id' => $id, 'url' => $url, 'regex' => 0, 'status' => 'enabled', 'action_type' => 'url',
        'action_code' => 301, 'action_data' => $target, 'match_type' => 'url', 'group_id' => 1,
    ), $over );
}
function reset_site() {
    $GLOBALS['rows'] = array(); $GLOBALS['next_id'] = 1; $GLOBALS['cache'] = array();
    $GLOBALS['options'] = array(); $GLOBALS['log'] = array(); $GLOBALS['redirection'] = array();
    $GLOBALS['queries'] = array(); $GLOBALS['bad_prepare'] = array();
    $GLOBALS['tables'] = array( 'wp_dos_redirects', 'wp_dos_404', 'wp_redirection_items', 'wp_redirection_groups' );
}
function notes( array $r ) { return implode( "\n", $r['notes'] ); }
function dos_sources() { return array_column( $GLOBALS['rows'], 'source' ); }
function dos_row( $source ) { return DOS_Redirects_Store::find( $source ); }

echo "--- a plain rule is imported ---\n";
reset_site();
$GLOBALS['redirection'] = array( item( 1, '/old-page/', '/new-page' ) );
$r = DOS_Redirects_Import::run( 0, 100, false );
check( 'an enabled url-to-url rule is imported', 1 === $r['changed'] && 1 === $r['processed'], json_encode( $r ) );
$row = dos_row( '/old-page' );
check( '  stored through the store, so normalised', null !== $row && '/old-page' === $row['source'] && '/new-page' === $row['target'] && 301 === (int) $row['code'], json_encode( $row ) );
check( '  marked as imported from Redirection', 'redirection' === ( $row['origin'] ?? '' ) );
check( '  and it is in the live map', isset( DOS_Redirects_Store::map()['/old-page'] ) );

echo "\n--- what is skipped, and why ---\n";
reset_site();
$GLOBALS['redirection'] = array(
    item( 2, '^/blog/(.*)$', '/news/$1', array( 'regex' => 1 ) ),
    item( 3, '/switched-off', '/x', array( 'status' => 'disabled' ) ),
    item( 4, '/members', '/dash', array( 'match_type' => 'login' ) ),
    item( 5, '/by-ref', '/x', array( 'match_type' => 'referrer' ) ),
    item( 6, '/by-agent', '/x', array( 'match_type' => 'agent' ) ),
    item( 7, '/gone', '', array( 'action_type' => 'error', 'action_code' => 410, 'action_data' => '' ) ),
    item( 8, '/passthrough', '', array( 'action_type' => 'pass' ) ),
    item( 9, '/random', '', array( 'action_type' => 'random' ) ),
    item( 10, '/nothing', '', array( 'action_type' => 'nothing' ) ),
    item( 11, '/tagged?id=5', '/x' ),
    item( 12, '/in-a-dead-group', '/x', array( 'group_id' => 2 ) ),
    item( 13, '/a', '/b' ),
);
// A loop: /b already goes to /a.
DOS_Redirects_Store::add( '/b', '/a' );
$before = count( $GLOBALS['rows'] );

$dry = DOS_Redirects_Import::run( 0, 100, true );
$text = notes( $dry );
check( 'dry run: examines every row', 12 === $dry['processed'], (string) $dry['processed'] );
check( '  writes nothing', $before === count( $GLOBALS['rows'] ), (string) count( $GLOBALS['rows'] ) );
check( '  imports none of these', 0 === $dry['changed'], (string) $dry['changed'] );
check( 'regex: named, with pattern and target', (bool) preg_match( '/item 2: regex rule.*\^\/blog\/\(\.\*\)\$.*\/news\/\$1/', $text ), $text );
check( 'disabled', (bool) preg_match( '/item 3 .*disabled/', $text ), $text );
check( 'login match type', (bool) preg_match( '/item 4 .*"login" match type/', $text ), $text );
check( 'referrer match type', (bool) preg_match( '/item 5 .*"referrer" match type/', $text ), $text );
check( 'agent match type', (bool) preg_match( '/item 6 .*"agent" match type/', $text ), $text );
check( 'error action', (bool) preg_match( '/item 7 .*action type "error"/', $text ), $text );
check( 'pass action', (bool) preg_match( '/item 8 .*action type "pass"/', $text ), $text );
check( 'random action', (bool) preg_match( '/item 9 .*action type "random"/', $text ), $text );
check( 'nothing action', (bool) preg_match( '/item 10 .*action type "nothing"/', $text ), $text );
check( 'a source with a query string would redirect the whole page, so it is skipped', (bool) preg_match( '/item 11 .*query string/', $text ), $text );
check( 'a rule in a disabled Redirection group never ran, so it is not switched on', (bool) preg_match( '/item 12 .*group is disabled/', $text ), $text );
check( 'a rule that would close a loop is refused by the store\'s own check', (bool) preg_match( '/item 13 .*refused.*loop/', $text ), $text );

$live = DOS_Redirects_Import::run( 0, 100, false );
check( 'live: the same rows are skipped, nothing new is stored', $before === count( $GLOBALS['rows'] ) && 0 === $live['changed'], json_encode( dos_sources() ) );

echo "\n--- an existing source is never overwritten ---\n";
reset_site();
DOS_Redirects_Store::add( '/exists', '/dos-target', 302 );
$GLOBALS['redirection'] = array( item( 20, '/EXISTS/', '/redirection-target' ) );
$r = DOS_Redirects_Import::run( 0, 100, false );
$row = dos_row( '/exists' );
check( 'skipped, with a note naming the item', 0 === $r['changed'] && (bool) preg_match( '/item 20 .*already exists here, left alone/', notes( $r ) ), notes( $r ) );
check( '  the existing target is untouched', '/dos-target' === $row['target'] );
check( '  and so is its status code', 302 === (int) $row['code'] );
check( '  and no second row appeared', 1 === count( $GLOBALS['rows'] ) );

// A disabled DoS rule still counts as existing: the person turned it off.
DOS_Redirects_Store::set_enabled( (int) $row['id'], false );
$r = DOS_Redirects_Import::run( 0, 100, false );
check( 'a disabled rule here still blocks the import', 0 === $r['changed'] && '/dos-target' === dos_row( '/exists' )['target'] );

echo "\n--- status codes ---\n";
reset_site();
$GLOBALS['redirection'] = array(
    item( 30, '/temp', '/t', array( 'action_code' => 302 ) ),
    item( 31, '/keep-method', '/k', array( 'action_code' => 307 ) ),
    item( 32, '/perm', '/p', array( 'action_code' => 308 ) ),
    item( 33, '/see-other', '/s', array( 'action_code' => 303 ) ),
);
$r = DOS_Redirects_Import::run( 0, 100, false );
check( 'supported codes are kept', 302 === (int) dos_row( '/temp' )['code'] && 307 === (int) dos_row( '/keep-method' )['code'] );
check( 'an unsupported code becomes 301', 301 === (int) dos_row( '/perm' )['code'] && 301 === (int) dos_row( '/see-other' )['code'] );
check( '  with a note saying so', (bool) preg_match( '/item 32: status 308 is not supported here, imported as 301/', notes( $r ) ) && (bool) preg_match( '/item 33: status 303/', notes( $r ) ), notes( $r ) );
check( '  and they were still imported', 4 === $r['changed'] );

echo "\n--- targets are held to the store's rules ---\n";
reset_site();
$GLOBALS['redirection'] = array(
    item( 40, '/open', '//evil.example/x' ),
    item( 41, '/script', 'javascript:alert(1)' ),
    item( 42, '/', '/home' ),
    item( 43, '/elsewhere', 'https://other.example/page' ),
    item( 44, '/self', '/self/' ),
);
$r = DOS_Redirects_Import::run( 0, 100, false );
check( 'a protocol-relative target is refused', null === dos_row( '/open' ) && (bool) preg_match( '/item 40 .*refused/', notes( $r ) ), notes( $r ) );
check( 'a javascript: target is refused', null === dos_row( '/script' ) );
check( 'the site root cannot be redirected', 0 === count( array_filter( dos_sources(), function ( $s ) { return '/' === $s; } ) ) && (bool) preg_match( '/item 42 .*refused/', notes( $r ) ), notes( $r ) );
check( 'a path redirected to itself is refused', null === dos_row( '/self' ) && (bool) preg_match( '/item 44 .*refused/', notes( $r ) ), notes( $r ) );
check( 'an external https target is fine', 'https://other.example/page' === ( dos_row( '/elsewhere' )['target'] ?? '' ) );
check( 'exactly the one good rule was imported', 1 === $r['changed'], (string) $r['changed'] );

echo "\n--- dry run against a mixed table ---\n";
reset_site();
$GLOBALS['redirection'] = array(
    item( 50, '/good-one', '/g1' ),
    item( 51, '/good-two', '/g2', array( 'action_code' => 302 ) ),
    item( 52, '^/x', '/y', array( 'regex' => 1 ) ),
    item( 53, '/off', '/z', array( 'status' => 'disabled' ) ),
);
$dry  = DOS_Redirects_Import::run( 0, 100, true );
check( 'dry run writes nothing', array() === $GLOBALS['rows'] );
check( '  and says what would be imported', 2 === $dry['changed'] && false !== strpos( notes( $dry ), 'Would import 2; 2 skipped' ), notes( $dry ) );
check( '  dry-run list is the rules it would write', array( '/good-one -> /g1 (301)', '/good-two -> /g2 (302)' ) === $dry['imported'], json_encode( $dry['imported'] ) );
$live = DOS_Redirects_Import::run( 0, 100, false );
check( 'the live run does exactly what the dry run said', $dry['changed'] === $live['changed'] && 2 === count( $GLOBALS['rows'] ), json_encode( dos_sources() ) );
$again = DOS_Redirects_Import::run( 0, 100, false );
check( 'running it again imports nothing and overwrites nothing', 0 === $again['changed'] && 2 === count( $GLOBALS['rows'] ) );

echo "\n--- paging ---\n";
reset_site();
for ( $i = 1; $i <= 7; $i++ ) { $GLOBALS['redirection'][] = item( $i, "/page-$i", "/to-$i" ); }
check( 'count covers every row, importable or not', 7 === DOS_Redirects_Import::count() );
$seen = 0;
foreach ( array( 0, 3, 6 ) as $offset ) { $seen += DOS_Redirects_Import::run( $offset, 3, false )['processed']; }
check( 'pages of three cover seven rows exactly once', 7 === $seen && 7 === count( $GLOBALS['rows'] ), "$seen / " . count( $GLOBALS['rows'] ) );

echo "\n--- Redirection is not installed ---\n";
reset_site();
$GLOBALS['tables'] = array( 'wp_dos_redirects', 'wp_dos_404' );
check( 'nothing to count', 0 === DOS_Redirects_Import::count() );
$r = DOS_Redirects_Import::run( 0, 100, false );
check( 'a clear note naming the table, nothing written', 0 === $r['processed'] && array() === $GLOBALS['rows'] && false !== strpos( notes( $r ), 'wp_redirection_items does not exist' ), notes( $r ) );

echo "\n--- Redirection without a groups table ---\n";
reset_site();
$GLOBALS['tables'] = array( 'wp_dos_redirects', 'wp_dos_404', 'wp_redirection_items' );
$GLOBALS['redirection'] = array( item( 60, '/no-groups', '/ok', array( 'group_id' => 2 ) ) );
$r = DOS_Redirects_Import::run( 0, 100, false );
check( 'treated as enabled rather than failing', 1 === $r['changed'] );

echo "\n--- the SQL the importer sends ---\n";
// The fake filters rows in PHP, so a typo in a table name would pass
// everything above. These pin the text.
reset_site();
$GLOBALS['redirection'] = array( item( 1, '/a', '/b' ), item( 2, '/c', '/d', array( 'group_id' => 2 ) ) );
$find = function ( $needle ) { foreach ( $GLOBALS['queries'] as $x ) { if ( false !== strpos( $x, $needle ) ) { return $x; } } return ''; };
DOS_Redirects_Import::count();
DOS_Redirects_Import::run( 40, 100, true );
check( 'probes for wp_redirection_items with a LIKE', false !== strpos( $find( "SHOW TABLES LIKE 'wp\\_redirection\\_items'" ), 'SHOW TABLES' ), json_encode( $GLOBALS['queries'] ) );
check( 'count: every row of wp_redirection_items', 'SELECT COUNT(*) FROM wp_redirection_items' === $find( 'COUNT(*)' ), $find( 'COUNT(*)' ) );
check( 'page: ordered by id, LIMIT then OFFSET', 'SELECT * FROM wp_redirection_items ORDER BY id ASC LIMIT 100 OFFSET 40' === $find( 'FROM wp_redirection_items ORDER' ), $find( 'FROM wp_redirection_items ORDER' ) );
DOS_Redirects_Import::run( 0, 100, true );
check( 'groups: id and status from wp_redirection_groups for the groups the page uses', 'SELECT id, status FROM wp_redirection_groups WHERE id IN ( 1, 2 )' === $find( 'wp_redirection_groups WHERE' ), $find( 'wp_redirection_groups WHERE' ) );
check( 'every prepare() had as many placeholders as arguments', array() === $GLOBALS['bad_prepare'], json_encode( $GLOBALS['bad_prepare'] ) );
$cols = array_keys( item( 1, '/a', '/b' ) );
check( 'the fixture rows carry the column names Redirection really uses', array( 'id', 'url', 'regex', 'status', 'action_type', 'action_code', 'action_data', 'match_type', 'group_id' ) === $cols );

echo "\n--- the job ---\n";
reset_site();
$jobs = DOS_Module_Redirects::jobs();
check( 'registered under its key', isset( $jobs['redirects_import_redirection'] ) );
$job = $jobs['redirects_import_redirection'];
check( '  callable, and not destructive: the source is never changed', is_callable( $job['count'] ) && is_callable( $job['step'] ) && empty( $job['destructive'] ) );
$GLOBALS['redirection'] = array( item( 70, '/via-job', '/ok' ), item( 71, '/via-job-2', '/ok2' ) );
$r = call_user_func( $job['step'], 0, 100, true );
check( 'the step returns what the runner expects', array( 'processed', 'changed', 'notes' ) === array_keys( $r ), json_encode( array_keys( $r ) ) );
check( '  a dry run is logged as one and writes nothing', array() === $GLOBALS['rows'] && true === ( $GLOBALS['log'][0][4] ?? null ) && 'redirects_imported' === ( $GLOBALS['log'][0][1] ?? '' ), json_encode( $GLOBALS['log'] ) );
$r = call_user_func( $job['step'], 0, 100, false );
check( '  a live run writes and logs', 2 === $r['changed'] && 2 === count( $GLOBALS['rows'] ) && false === ( $GLOBALS['log'][1][4] ?? null ), json_encode( $GLOBALS['log'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
