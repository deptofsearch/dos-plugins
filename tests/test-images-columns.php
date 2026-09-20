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
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function get_the_title( $id ) { return $GLOBALS['titles'][ $id ] ?? ''; }
function get_edit_post_link( $id ) { return '/wp-admin/post.php?post=' . (int) $id; }

class DOS_Images_Usage { const META_STATUS = '_dos_usage_status'; const META_USED_IN = '_dos_usage_used_in'; }
$GLOBALS['titles'] = array( 10 => 'About Us', 11 => 'Contact', 12 => 'Services' );

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

echo "\n--- usage status in the media library ---\n";
check( 'a used image says so', false !== strpos( DOS_Images_Columns::status_badge( 'used' ), 'Used' ) );
check( 'an unused one says so', false !== strpos( DOS_Images_Columns::status_badge( 'unused' ), 'Unused' ) );

// The distinction that keeps unexamined images off a deletion list.
check( 'an unscanned image says "Not scanned" rather than "Unused"', false !== strpos( DOS_Images_Columns::status_badge( '' ), 'Not scanned' ), DOS_Images_Columns::status_badge( '' ) );
check( '  and so does one with a status nobody recognises', false !== strpos( DOS_Images_Columns::status_badge( 'nonsense' ), 'Not scanned' ) );

echo "\n--- where an image is used ---\n";
check( 'nothing recorded shows a dash', '&mdash;' === DOS_Images_Columns::format_used_in( array() ) );
check( '  as does a value that is not a list', '&mdash;' === DOS_Images_Columns::format_used_in( 'broken' ) );

$used = array(
    array( 'post_id' => 10, 'type' => 'featured' ),
    array( 'post_id' => 11, 'type' => 'content' ),
    array( 'post_id' => 12, 'type' => 'content' ),
);

$all = DOS_Images_Columns::format_used_in( $used );
check( 'lists every page using it', 3 === substr_count( $all, '<a href' ), $all );
check( '  linking to the editor for each', false !== strpos( $all, 'post.php?post=10' ) );
check( '  and saying how it is used', false !== strpos( $all, 'featured' ) && false !== strpos( $all, 'in content' ), $all );

$short = DOS_Images_Columns::format_used_in( $used, 2 );
check( 'a limit shows that many', 2 === substr_count( $short, '<a href' ), $short );
check( '  and counts the rest rather than hiding them', false !== strpos( $short, '+1 more' ), $short );

$untitled = DOS_Images_Columns::format_used_in( array( array( 'post_id' => 99, 'type' => 'content' ) ) );
check( 'an untitled page is named by its ID rather than left blank', false !== strpos( $untitled, '#99' ), $untitled );

$broken = DOS_Images_Columns::format_used_in( array( array( 'type' => 'content' ), array( 'post_id' => 10 ) ) );
check( 'a record with no page is skipped, not rendered empty', 1 === substr_count( $broken, '<a href' ), $broken );

echo "\n--- the media column ---\n";
$cols = DOS_Images_Columns::add_usage_column( array( 'cb' => '', 'title' => 'File' ) );
check( 'is added to the media list', isset( $cols['dos_usage'] ) );
check( '  without disturbing the existing columns', 3 === count( $cols ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
