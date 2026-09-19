<?php
/**
 * Last-updated dates. The two rules worth pinning: a post is only "updated"
 * when it was genuinely edited after publishing, and a machine-readable date
 * is never decorated — doing so would put prose inside a timestamp that
 * something else is about to parse.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'DATE_W3C', 'Y-m-d\TH:i:sP' );
define( 'DATE_ATOM', 'Y-m-d\TH:i:sP' );
define( 'DATE_RFC3339', 'Y-m-d\TH:i:sP' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options']  = array();
$GLOBALS['posts']    = array();
$GLOBALS['singular'] = true;

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function is_admin() { return false; }
function is_feed() { return false; }
function is_singular( $t = '' ) { return $GLOBALS['singular']; }
function get_post_types( $a = array(), $b = 'names' ) { return array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment', 'product' => 'product' ); }
function get_post( $p = null ) {
    if ( is_object( $p ) ) { return $p; }
    if ( null === $p ) { $p = $GLOBALS['current'] ?? 0; }
    return $GLOBALS['posts'][ (int) $p ] ?? null;
}
function get_post_modified_time( $format, $gmt = false, $post = null ) {
    $post = get_post( $post );
    return $post ? date( $format, strtotime( $post->post_modified_gmt ) ) : '';
}

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/modules/utilities/class-dos-last-updated.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}
function make_post( $id, $published, $modified ) {
    $GLOBALS['posts'][ $id ] = (object) array(
        'ID' => $id,
        'post_date' => $published, 'post_date_gmt' => $published,
        'post_modified' => $modified, 'post_modified_gmt' => $modified,
    );
    return $id;
}

echo "--- was it really updated ---\n";
make_post( 1, '2026-01-01 10:00:00', '2026-01-01 10:00:03' );
check( 'three seconds after publishing is not an edit', ! DOS_Last_Updated::was_updated( 1 ) );

make_post( 2, '2026-01-01 10:00:00', '2026-01-01 10:00:59' );
check( 'under the threshold is not an edit', ! DOS_Last_Updated::was_updated( 2 ) );

make_post( 3, '2026-01-01 10:00:00', '2026-01-01 10:01:30' );
check( 'ninety seconds later is', DOS_Last_Updated::was_updated( 3 ) );

make_post( 4, '2026-01-01 10:00:00', '2026-06-01 09:00:00' );
check( 'months later certainly is', DOS_Last_Updated::was_updated( 4 ) );

make_post( 5, '2026-01-01 10:00:00', '2025-12-01 10:00:00' );
check( 'a modified time before publication is not', ! DOS_Last_Updated::was_updated( 5 ) );

check( 'a missing post is not', ! DOS_Last_Updated::was_updated( 999 ) );

echo "\n--- machine-readable formats are left alone ---\n";
foreach ( array( DATE_W3C, 'c', 'U', 'Y-m-d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP' ) as $f ) {
    check( "treats '$f' as machine-readable", DOS_Last_Updated::is_machine_format( $f ) );
}
foreach ( array( 'F j, Y', 'j M Y', 'd/m/Y', 'M j, Y \a\t g:i a', '' ) as $f ) {
    check( "treats '$f' as human-readable", ! DOS_Last_Updated::is_machine_format( $f ) );
}

echo "\n--- decorating ---\n";
$GLOBALS['current'] = 4;
$out = DOS_Last_Updated::filter_get_the_date( 'January 1, 2026', 'F j, Y', 4 );
check( 'prefixes an updated post', false !== strpos( $out, 'Updated:' ), $out );
check( '  and keeps the original date', false !== strpos( $out, 'January 1, 2026' ), $out );

$out = DOS_Last_Updated::filter_get_the_date( 'January 1, 2026', 'F j, Y', 1 );
check( 'leaves a post that was never edited alone', 'January 1, 2026' === $out, $out );

// The SEO module reads dates through get_post_time(), which this filter never
// sees — but anything else asking get_the_date() for an ISO timestamp must
// still get a timestamp back.
$iso = '2026-01-01T10:00:00+00:00';
$out = DOS_Last_Updated::filter_get_the_date( $iso, DATE_W3C, 4 );
check( 'never touches an ISO timestamp, even on an edited post', $iso === $out, $out );

$GLOBALS['singular'] = false;
$out = DOS_Last_Updated::filter_get_the_date( 'January 1, 2026', 'F j, Y', 4 );
check( 'does nothing on an archive', 'January 1, 2026' === $out, $out );
$GLOBALS['singular'] = true;

echo "\n--- the admin column ---\n";
$cols = DOS_Last_Updated::add_column( array( 'cb' => '', 'title' => 'Title', 'author' => 'Author', 'date' => 'Date' ) );
$keys = array_keys( $cols );
check( 'sits immediately left of the published date', array_search( 'dos_last_updated', $keys, true ) === array_search( 'date', $keys, true ) - 1, implode( ',', $keys ) );
check( '  and keeps every original column', 5 === count( $cols ), implode( ',', $keys ) );

$cols = DOS_Last_Updated::add_column( array( 'cb' => '', 'title' => 'Title' ) );
check( 'appends when there is no date column at all', isset( $cols['dos_last_updated'] ), implode( ',', array_keys( $cols ) ) );

check( 'covers public post types', in_array( 'product', DOS_Last_Updated::post_types(), true ) );
check( '  but not attachments', ! in_array( 'attachment', DOS_Last_Updated::post_types(), true ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
