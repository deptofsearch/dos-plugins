<?php
/**
 * DoS Toolkit: "Leave the home page out of search results".
 *
 * On electedorappointed.com the static home page (page 198) summarises the whole site, so a search for
 * "Tri-Cities" listed it with the restaurant chart from its content, and the card "linked to the homepage".
 * The option drops the front page (and posts page) from the front-end main search query only.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts']  = array();
$GLOBALS['admin'] = false;
$GLOBALS['hooks'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function is_admin() { return $GLOBALS['admin']; }
function add_action( $h, $cb ) { $GLOBALS['hooks'][] = $h; }
final class DOS_Settings { public static $v = array(); public static function get( $k, $d = null ) { return self::$v[ $k ] ?? $d; } }
class Q {
	public $vars = array(); public $main = true; public $search = true;
	function is_main_query() { return $this->main; } function is_search() { return $this->search; }
	function get( $k ) { return $this->vars[ $k ] ?? ''; } function set( $k, $v ) { $this->vars[ $k ] = $v; }
}
require dirname( __DIR__ ) . '/plugins/dos-toolkit/modules/utilities/class-dos-search-front.php';

$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; } }

DOS_Search_Front::init();
check( 'off by default: no hook', ! in_array( 'pre_get_posts', $GLOBALS['hooks'], true ) );
DOS_Settings::$v['utilities_search_hide_front'] = 1;
DOS_Search_Front::init();
check( 'on: hooks pre_get_posts', in_array( 'pre_get_posts', $GLOBALS['hooks'], true ) );

$GLOBALS['opts'] = array( 'show_on_front' => 'page', 'page_on_front' => '198', 'page_for_posts' => '0' );
$q = new Q(); DOS_Search_Front::exclude( $q );
check( 'front-end search leaves out the front page', array( 198 ) === $q->get( 'post__not_in' ) );

$q = new Q(); $q->set( 'post__not_in', array( 5, 198 ) ); DOS_Search_Front::exclude( $q );
check( 'keeps exclusions already set, without duplicates', array( 5, 198 ) === $q->get( 'post__not_in' ) );

$GLOBALS['opts']['page_for_posts'] = '40';
$q = new Q(); DOS_Search_Front::exclude( $q );
check( 'the posts page is left out too', array( 198, 40 ) === $q->get( 'post__not_in' ) );

$q = new Q(); $q->search = false; DOS_Search_Front::exclude( $q );
check( 'not a search: untouched', '' === $q->get( 'post__not_in' ) );
$q = new Q(); $q->main = false; DOS_Search_Front::exclude( $q );
check( 'secondary query: untouched', '' === $q->get( 'post__not_in' ) );
$GLOBALS['admin'] = true; $q = new Q(); DOS_Search_Front::exclude( $q ); $GLOBALS['admin'] = false;
check( 'admin search: untouched', '' === $q->get( 'post__not_in' ) );

$GLOBALS['opts'] = array( 'show_on_front' => 'posts', 'page_on_front' => '198' );
$q = new Q(); DOS_Search_Front::exclude( $q );
check( 'latest-posts home page: nothing to leave out', '' === $q->get( 'post__not_in' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
