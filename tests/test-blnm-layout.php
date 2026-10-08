<?php
/** Best Lenders Layout: hub pages answer Themify hide_image / hide_post_image and get a body class. Runs without WordPress. */
define( 'ABSPATH', '/tmp/' );
error_reporting( E_ALL );
set_error_handler( static function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );
$plugin = dirname( __DIR__ ) . '/plugins/dos-best-lenders';
$GLOBALS['posts'] = array(
	1 => (object) array( 'post_type' => 'page', 'post_content' => '[blnm_state_index state="WA"]' ),
	2 => (object) array( 'post_type' => 'page', 'post_content' => 'Plain page' ),
	3 => (object) array( 'post_type' => 'page', 'post_content' => '[blnm_state_index]' ),
);
$GLOBALS['admin'] = false;
$GLOBALS['queried'] = 1;
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_type( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_type : false; }
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }
function is_admin() { return $GLOBALS['admin']; }
function is_page() { return true; }
function is_singular() { return false; }
function get_queried_object_id() { return $GLOBALS['queried']; }
require $plugin . '/includes/class-data-model.php';
require $plugin . '/includes/class-layout.php';

$fail = 0;
function check( $label, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n"; $fail += $ok ? 0 : 1; }

foreach ( array( 'hide_image', 'hide_post_image' ) as $k ) {
	check( "hub answers $k yes", 'yes' === BLNM\Layout::themify_meta( null, 1, $k, true ) );
	check( "hub answers $k array", array( 'yes' ) === BLNM\Layout::themify_meta( null, 1, $k, false ) );
	check( "normal page leaves $k alone", null === BLNM\Layout::themify_meta( null, 2, $k, true ) );
	check( "no-state index page leaves $k alone", null === BLNM\Layout::themify_meta( null, 3, $k, true ) );
	check( "explicit value for $k respected", 'no' === BLNM\Layout::themify_meta( 'no', 1, $k, true ) );
}
$GLOBALS['admin'] = true;
check( 'admin untouched', null === BLNM\Layout::themify_meta( null, 1, 'hide_image', true ) );
$GLOBALS['admin'] = false;

check( 'hub gets blnm-hub-page', in_array( 'blnm-hub-page', BLNM\Layout::body_class( array( 'page' ) ), true ) );
$GLOBALS['queried'] = 2;
check( 'normal page no class', ! in_array( 'blnm-hub-page', BLNM\Layout::body_class( array( 'page' ) ), true ) );

exit( $fail ? 1 : 0 );
