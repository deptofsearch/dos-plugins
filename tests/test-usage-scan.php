<?php
/**
 * The usage scan's two phases share one offset space, and the batch runner
 * advances that offset by a fixed stride whatever a step actually processed.
 *
 * This drives the real runner against the real scan and asserts that every
 * post is visited. The bug it pins skipped the first few posts whenever the
 * attachment count was not an exact multiple of the batch size, marked their
 * images unused, and offered them for deletion.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['options']   = array();
$GLOBALS['meta']      = array();
$GLOBALS['visited']   = array();   // post IDs the content phase actually saw
$GLOBALS['reset']     = array();   // attachment IDs the reset phase touched

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function get_post_types( $a = array(), $b = 'names' ) { return array( 'post', 'page' ); }
function get_bloginfo( $w ) { return 'UTF-8'; }
function wp_get_upload_dir() { return array( 'baseurl' => 'https://example.com/wp-content/uploads' ); }
function wp_parse_url( $u, $c = -1 ) { $p = parse_url( $u ); return 5 === $c ? ( $p['path'] ?? '' ) : $p; }
function wp_basename( $p ) { return basename( (string) $p ); }
function get_post_type( $id ) { return 'attachment'; }
function get_post_mime_type( $id ) { return 'image/jpeg'; }
function get_post_thumbnail_id( $id ) { return 900 + $id; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function get_post_meta( $id, $k = '', $s = false ) {
    if ( '' === $k ) { $o = array(); foreach ( $GLOBALS['meta'][ $id ] ?? array() as $kk => $vv ) { $o[ $kk ] = array( $vv ); } return $o; }
    return $GLOBALS['meta'][ $id ][ $k ] ?? '';
}

class FakeWpdb { public $postmeta='wp_postmeta';
    public function prepare( $q, ...$a ) { return $q; }
    public function esc_like( $s ) { return $s; }
    public function get_col( $q ) { return array(); } }
$GLOBALS['wpdb'] = new FakeWpdb();

/** Fixture: $ATTACHMENTS images and $POSTS posts, paged honestly. */
function get_posts( $args ) {
    $type = $args['post_type'] ?? 'post';
    $off  = (int) ( $args['offset'] ?? 0 );
    $num  = (int) ( $args['posts_per_page'] ?? 10 );

    if ( 'attachment' === $type ) {
        $all = range( 1, $GLOBALS['ATTACHMENTS'] );
        $page = array_slice( $all, $off, $num );
        foreach ( $page as $id ) { $GLOBALS['reset'][] = $id; }
        return $page;
    }

    $all = array();
    for ( $i = 1; $i <= $GLOBALS['POSTS']; $i++ ) {
        $all[] = (object) array( 'ID' => 100 + $i, 'post_content' => '' );
    }
    $page = array_slice( $all, $off, $num );
    foreach ( $page as $p ) { $GLOBALS['visited'][] = $p->ID; }
    return $page;
}

class WP_Query {
    public $found_posts = 0;
    public function __construct( $args ) {
        $this->found_posts = ( 'attachment' === ( $args['post_type'] ?? '' ) )
            ? $GLOBALS['ATTACHMENTS'] : $GLOBALS['POSTS'];
        if ( ! empty( $args['meta_query'] ) ) { $this->found_posts = 0; }
    }
}

class DOS_Log { public static function add() {} public static function prune() {} }
class DOS_Toolkit { public static function modules() { return array(); } public static function is_active( $k ) { return false; } }

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/modules/images/class-dos-images-usage.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

/** Drive the scan exactly as DOS_Batch does: fixed stride, stop on 0 or total. */
function run_scan( $attachments, $posts ) {
    $GLOBALS['ATTACHMENTS'] = $attachments;
    $GLOBALS['POSTS']       = $posts;
    $GLOBALS['visited']     = array();
    $GLOBALS['reset']       = array();

    $size   = DOS_Images_Usage::SCAN_BATCH;
    $total  = DOS_Images_Usage::scan_total();
    $offset = 0;
    $guard  = 0;

    do {
        $r       = DOS_Images_Usage::scan_step( $offset, $size, false );
        $offset += $size;
        $guard++;
    } while ( 0 !== (int) $r['processed'] && $offset < $total && $guard < 500 );

    return $total;
}

echo "--- every post is scanned, whatever the attachment count ---\n";

foreach ( array(
    array( 33, 40 ),   // attachments not a multiple of the batch size
    array( 40, 40 ),   // exact multiple
    array( 1, 61 ),    // one attachment
    array( 0, 12 ),    // none at all
    array( 19, 5 ),    // just under a batch
    array( 21, 5 ),    // just over a batch
    array( 137, 61 ),  // the shape of a real site
) as $case ) {
    list( $a, $p ) = $case;
    run_scan( $a, $p );

    $expected = array();
    for ( $i = 1; $i <= $p; $i++ ) { $expected[] = 100 + $i; }
    $missed = array_diff( $expected, $GLOBALS['visited'] );

    check(
        sprintf( '%d attachments, %d posts — all posts scanned', $a, $p ),
        array() === $missed,
        'missed ' . count( $missed ) . ': ' . implode( ',', array_slice( $missed, 0, 8 ) )
    );
}

echo "\n--- the reset phase still covers every attachment ---\n";
run_scan( 33, 40 );
$missed_att = array_diff( range( 1, 33 ), $GLOBALS['reset'] );
check( 'every attachment was reset', array() === $missed_att, 'missed ' . implode( ',', $missed_att ) );

echo "\n--- the padded boundary is reported in the total ---\n";
$GLOBALS['ATTACHMENTS'] = 33; $GLOBALS['POSTS'] = 40;
check( 'total covers the padding, so the run is not cut short', 40 + 40 === DOS_Images_Usage::scan_total(), (string) DOS_Images_Usage::scan_total() );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
