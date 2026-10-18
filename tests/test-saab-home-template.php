<?php
/**
 * DoS Shot and a Beer Home: when the front-page template is swapped. 0.1.0 filtered the_content of a static front
 * page and never showed on a latest-posts home; 0.1.1 swaps the template on any front page.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DOS_SAAB_HOME_FILE', dirname( __DIR__ ) . '/plugins/dos-saab-home/dos-saab-home.php' );

$GLOBALS['req'] = array();
function saab_req( array $r ) {
	$GLOBALS['req'] = array_merge(
		array( 'admin' => false, 'wp' => true, 'front' => true, 'paged' => false, 'feed' => false, 'on' => true, 'cap' => false ),
		$r
	);
	$_GET = $r['get'] ?? array();
}
function is_admin() { return $GLOBALS['req']['admin']; }
function did_action( $a ) { return 'wp' === $a && $GLOBALS['req']['wp'] ? 1 : 0; }
function is_front_page() { return $GLOBALS['req']['front']; }
function is_paged() { return $GLOBALS['req']['paged']; }
function is_feed() { return $GLOBALS['req']['feed']; }
function current_user_can( $c ) { return $GLOBALS['req']['cap']; }
function get_option( $k, $d = false ) { return 'saab_home' === $k ? array( 'on' => $GLOBALS['req']['on'] ) : $d; }

require dirname( __DIR__ ) . '/plugins/dos-saab-home/includes/class-home.php';

use DOS_SAAB\Home;

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

$theme = '/themes/magazine/index.php';
$ours  = dirname( DOS_SAAB_HOME_FILE ) . '/templates/front-page.php';

check( 'the template file ships with the plugin', is_readable( $ours ) );

saab_req( array() );
check( 'on, front page: our template', $ours === Home::template( $theme ) );

saab_req( array( 'front' => false ) );
check( 'on, any other page: the theme\'s template', $theme === Home::template( $theme ) );

saab_req( array( 'paged' => true ) );
check( 'on, page 2 of a latest-posts home: the theme\'s post list', $theme === Home::template( $theme ) );

saab_req( array( 'feed' => true ) );
check( 'on, the feed: untouched', $theme === Home::template( $theme ) );

saab_req( array( 'on' => false ) );
check( 'off: the theme\'s template', $theme === Home::template( $theme ) );

saab_req( array( 'on' => false, 'get' => array( 'saab_home_preview' => '1' ), 'cap' => true ) );
check( 'off, admin preview: our template', $ours === Home::template( $theme ) );

saab_req( array( 'on' => false, 'get' => array( 'saab_home_preview' => '1' ), 'cap' => false ) );
check( 'off, preview by a visitor: the theme\'s template', $theme === Home::template( $theme ) );

saab_req( array( 'admin' => true ) );
check( 'never in wp-admin', $theme === Home::template( $theme ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
