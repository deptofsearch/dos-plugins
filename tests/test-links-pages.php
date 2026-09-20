<?php
/**
 * The page-level link index: what counts as an internal link, where it points,
 * and which side of the relationship each page is on.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options'] = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : array() ); }
function current_time( $t = 'mysql' ) { return '2026-09-20 10:00:00'; }
function home_url( $p = '/' ) { return 'https://example.com' . ( '/' === $p ? '/' : $p ); }
function wp_parse_url( $u, $c = -1 ) {
    $p = parse_url( $u );
    if ( -1 === $c ) { return $p; }
    $map = array( PHP_URL_HOST => 'host', PHP_URL_PATH => 'path' );
    $k = $map[ $c ] ?? null;
    return $k && isset( $p[ $k ] ) ? $p[ $k ] : null;
}
function url_to_postid( $u ) {
    $map = array( '/about/' => 2, '/contact/' => 7, '/guide/' => 10 );
    $path = wp_parse_url( $u, PHP_URL_PATH );
    return $map[ $path ] ?? 0;
}
class FakeWpdb { public $prefix='wp_'; public function get_charset_collate(){return '';} public function query($q){return 1;} public function get_var($q){return 0;} public function get_row($q,$o=null){return array();} public function get_results($q,$o=null){return array();} public function prepare($q,...$a){return $q;} }
$GLOBALS['wpdb'] = new FakeWpdb();

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/modules/links/class-dos-links-pages.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

// rule 5 points at page 10, rule 6 points at page 2.
$targets = array( 5 => 10, 6 => 2 );

echo "--- what counts as an internal link ---\n";

$html = '<p>'
    . '<a href="/guide/" class="dos-ilink" data-dos-link="5">open houses</a> '     // ours -> 10
    . '<a href="/about/">about us</a> '                                            // by hand -> 2
    . '<a href="https://example.com/contact/">contact</a> '                        // by hand, full URL -> 7
    . '<a href="https://elsewhere.com/x">off site</a> '                            // external
    . '<a href="#section">jump</a> '                                               // anchor
    . '<a href="mailto:a@b.c">email</a> '                                          // mailto
    . '<a href="/nowhere/">missing</a>'                                            // resolves to nothing
    . '</p>';

$r = DOS_Links_Pages::analyse_links( $html, $targets );

check( 'counts this module\'s links', 1 === $r['out_module'], json_encode( $r ) );
check( 'counts hand-written internal links', 2 === $r['out_manual'], json_encode( $r ) );
check( '  ignoring anything off-site', 0 === ( $r['to'][0] ?? 0 ) && 2 === $r['out_manual'] );
check( '  ignoring in-page anchors', 1 === DOS_Links_Pages::analyse_links( '<a href="#x">y</a>', $targets )['out_manual'] ? false : true );
check( '  ignoring mailto and tel', 0 === DOS_Links_Pages::analyse_links( '<a href="mailto:a@b.c">e</a><a href="tel:123">t</a>', $targets )['out_manual'] );
check( '  and ignoring a URL that resolves to no page', 0 === DOS_Links_Pages::analyse_links( '<a href="/nowhere/">m</a>', $targets )['out_manual'] );

echo "\n--- where they point ---\n";
check( 'attributes its own link to the rule\'s destination', 1 === ( $r['to'][10]['module'] ?? 0 ), json_encode( $r['to'] ) );
check( 'attributes a hand-written link to the page it points at', 1 === ( $r['to'][2]['manual'] ?? 0 ), json_encode( $r['to'] ) );
check( '  including one written as a full URL', 1 === ( $r['to'][7]['manual'] ?? 0 ), json_encode( $r['to'] ) );
check( 'records nothing for pages that were not linked', ! isset( $r['to'][99] ) );

echo "\n--- edge cases ---\n";
$empty = DOS_Links_Pages::analyse_links( '<p>No links at all here.</p>', $targets );
check( 'a page with no links counts nothing', 0 === $empty['out_module'] && 0 === $empty['out_manual'] );
check( '  and points at nothing', array() === $empty['to'] );

$unknown = DOS_Links_Pages::analyse_links( '<a href="/x" data-dos-link="404">gone</a>', $targets );
check( 'a link from a deleted phrase still counts as a link out', 1 === $unknown['out_module'] );
check( '  but is not credited to any destination', array() === $unknown['to'], json_encode( $unknown['to'] ) );

$repeat = DOS_Links_Pages::analyse_links( '<a href="/about/">a</a><a href="/about/">b</a>', $targets );
check( 'two links to the same page count twice', 2 === ( $repeat['to'][2]['manual'] ?? 0 ) );

$messy = DOS_Links_Pages::analyse_links( "<a\nclass='dos-ilink'\ndata-dos-link=\"5\"\nhref='/guide/'>x</a>", $targets );
check( 'attributes are found whatever their order or quoting', 1 === $messy['out_module'], json_encode( $messy ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
