<?php
/**
 * The featured-image column and its filter.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options']  = array();
$GLOBALS['supports'] = array( 'post' => true, 'page' => true, 'landing' => false );

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function get_post_types( $a = array(), $b = 'names' ) { return array( 'post', 'page', 'landing', 'attachment' ); }
function post_type_supports( $type, $feature ) { return ! empty( $GLOBALS['supports'][ $type ] ); }

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/modules/images/class-dos-images-columns.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

echo "--- which lists get the column ---\n";
$types = DOS_Images_Columns::post_types();
check( 'posts', in_array( 'post', $types, true ) );
check( 'pages', in_array( 'page', $types, true ) );
check( 'not attachments', ! in_array( 'attachment', $types, true ) );
check( 'not a type that cannot have a featured image', ! in_array( 'landing', $types, true ), implode( ',', $types ) );

echo "\n--- where the column sits ---\n";
$cols = DOS_Images_Columns::add_column( array( 'cb' => '', 'title' => 'Title', 'author' => 'Author', 'date' => 'Date' ) );
$keys = array_keys( $cols );
check( 'immediately after the title', array_search( 'dos_featured', $keys, true ) === array_search( 'title', $keys, true ) + 1, implode( ',', $keys ) );
check( 'keeps every original column', 5 === count( $cols ) );

$cols = DOS_Images_Columns::add_column( array( 'cb' => '' ) );
check( 'appends when there is no title column', isset( $cols['dos_featured'] ) );

echo "\n--- filtering ---\n";
$has = DOS_Images_Columns::meta_query( 'has' );
check( 'has: asks for the key to exist', 'EXISTS' === $has[0]['compare'] && '_thumbnail_id' === $has[0]['key'], json_encode( $has ) );

$missing = DOS_Images_Columns::meta_query( 'missing' );
check( 'missing: matches either way a thumbnail can be absent', 'OR' === $missing[0]['relation'] && 2 === count( array_filter( $missing[0], 'is_array' ) ), json_encode( $missing ) );
check( '  no row at all', 'NOT EXISTS' === $missing[0][0]['compare'] );
check( '  or a row left empty when one was removed', '' === $missing[0][1]['value'] );

$existing = DOS_Images_Columns::meta_query( 'has', array( array( 'key' => 'something_else' ) ) );
check( 'adds to an existing meta query rather than replacing it', 2 === count( $existing ), json_encode( $existing ) );

echo "\n--- the setting ---\n";
check( 'on by default, since it only reads', DOS_Images_Columns::is_enabled() );
DOS_Settings::set( 'images_featured_column', 0 );
check( 'can be switched off', ! DOS_Images_Columns::is_enabled() );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
