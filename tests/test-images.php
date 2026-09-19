<?php
/**
 * Images module: markup rewriting, reference detection, and the paging and
 * protection rules that deletion depends on.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PHP_URL_PATH_STUB', 5 );

$GLOBALS['options']     = array();
$GLOBALS['transients']  = array();
$GLOBALS['cache']       = array();
$GLOBALS['deleted']     = array();
$GLOBALS['meta']        = array();   // [attachment_id][key] = value
$GLOBALS['attachments'] = array();   // id => relative upload path
$GLOBALS['unused']      = array();   // ids the scan concluded were unused
$GLOBALS['log']         = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function get_theme_mod( $k, $d = false ) { return $GLOBALS['options'][ 'theme_mod_' . $k ] ?? $d; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function wp_cache_get( $k, $g = '' ) { return $GLOBALS['cache'][ $g . $k ] ?? false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { $GLOBALS['cache'][ $g . $k ] = $v; return true; }
function __( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function current_user_can() { return true; }
function get_current_user_id() { return 1; }
function current_time() { return date( 'Y-m-d H:i:s' ); }
function home_url( $p = '/' ) { return 'https://example.com' . $p; }
function add_query_arg( $a = array(), $b = null ) { return '/'; }
function get_bloginfo( $w ) { return 'UTF-8'; }
function wp_basename( $p ) { return basename( (string) $p ); }
function wp_parse_url( $u, $c = -1 ) { $p = parse_url( $u ); return 5 === $c ? ( $p['path'] ?? '' ) : $p; }
function wp_get_upload_dir() { return array( 'baseurl' => 'https://example.com/wp-content/uploads' ); }
function is_admin() { return false; } function is_feed() { return false; }
function is_embed() { return false; } function is_preview() { return false; }
function wp_doing_ajax() { return false; }
function human_time_diff( $a, $b = 0 ) { return '1 minute'; }

function get_post_meta( $id, $k = '', $single = false ) {
    if ( '' === $k ) {
        // All-meta form: WordPress returns every value as an array.
        $out = array();
        foreach ( $GLOBALS['meta'][ $id ] ?? array() as $key => $val ) { $out[ $key ] = array( $val ); }
        return $out;
    }
    return $GLOBALS['meta'][ $id ][ $k ] ?? '';
}
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function wp_delete_attachment( $id, $force = false ) { $GLOBALS['deleted'][] = (int) $id; $GLOBALS['unused'] = array_values( array_diff( $GLOBALS['unused'], array( (int) $id ) ) ); return true; }

/**
 * get_posts stub for the unused-image query the delete job issues. It honours
 * the meta_query, because marking a protected image "used" is precisely how
 * the code stops it being handed back on every subsequent page.
 */
function get_posts( $args ) {
    $ids = $GLOBALS['unused'];

    if ( ! empty( $args['meta_query'] ) ) {
        $ids = array_values( array_filter( $ids, function ( $id ) {
            return 'used' !== ( $GLOBALS['meta'][ $id ]['_dos_usage_status'] ?? 'unused' );
        } ) );
    }

    $off = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
    $num = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;

    return array_slice( $ids, $off, $num );
}

function attachment_url_to_postid( $url ) {
    $path = ltrim( str_replace( 'https://example.com/wp-content/uploads/', '', $url ), '/' );
    foreach ( $GLOBALS['attachments'] as $id => $file ) { if ( $file === $path ) { return $id; } }
    return 0;
}
function wp_get_attachment_metadata( $id ) {
    return array( 'width' => 2560, 'height' => 1440, 'sizes' => array(
        'thumbnail' => array( 'width' => 150 ), 'medium' => array( 'width' => 300 ),
        'medium_large' => array( 'width' => 768 ), 'large' => array( 'width' => 1024 ),
    ) );
}
function wp_get_attachment_image_src( $id, $size ) {
    $w = array( 'thumbnail' => 150, 'medium' => 300, 'medium_large' => 768, 'large' => 1024, 'full' => 2560 );
    $width = $w[ $size ] ?? 2560;
    $file  = $GLOBALS['attachments'][ $id ] ?? 'hero.jpg';
    $url   = 'full' === $size
        ? 'https://example.com/wp-content/uploads/' . $file
        : 'https://example.com/wp-content/uploads/' . preg_replace( '/\.(\w+)$/', "-{$width}x{$width}.$1", $file );
    return array( $url, $width, $width );
}
function wp_get_attachment_image_srcset( $id, $size ) { return 'a-300x300.jpg 300w, a-768x768.jpg 768w'; }
function wp_get_attachment_image_sizes( $id, $size ) { return '(max-width: 768px) 100vw, 768px'; }

class FakeWpdb {
    public $postmeta = 'wp_postmeta';
    public function prepare( $q, ...$a ) { return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $q ), $a ); }
    public function esc_like( $s ) { return $s; }
    public function get_col( $q ) {
        if ( preg_match( "/meta_value = '([^']+)'/", $q, $m ) ) {
            foreach ( $GLOBALS['attachments'] as $id => $file ) { if ( $file === $m[1] ) { return array( $id ); } }
        }
        return array();
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

class DOS_Log { public static function add( $m, $a, $msg = '', $o = 0, $d = false ) { $GLOBALS['log'][] = "$a:$msg"; } public static function prune() {} }

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/includes/class-dos-module.php';
require PLUGIN . '/modules/images/class-dos-images-markup.php';
require PLUGIN . '/modules/images/class-dos-images-usage.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

/* ===================================================================== */
echo "--- markup rewriting ---\n";

$GLOBALS['attachments'] = array( 11 => 'hero.jpg' );
DOS_Settings::update( array( 'images_markup_enabled' => 1, 'images_markup_dry_run' => 0, 'images_slot_width' => 350 ) );

$html = '<html><body><img src="https://example.com/wp-content/uploads/hero.jpg" width="348" alt="x"></body></html>';
$out  = DOS_Images_Markup::rewrite( $html );

check( 'adds srcset', false !== strpos( $out, 'srcset="' ), $out );
check( 'adds sizes', false !== strpos( $out, 'sizes="' ) );
check( 'adds decoding="async"', false !== strpos( $out, 'decoding="async"' ) );
check( 'swaps a 2560px original for a smaller size', false === strpos( $out, 'uploads/hero.jpg"' ), $out );
check( '  picks the size covering 348px at 2x, not the full file', false !== strpos( $out, '768x768' ), $out );

$already = '<html><body><img src="https://example.com/wp-content/uploads/hero.jpg" srcset="x 1w"></body></html>';
check( 'leaves an already-responsive img alone', DOS_Images_Markup::rewrite( $already ) === $already );

$offsite = '<html><body><img src="https://cdn.other.com/a.jpg"></body></html>';
check( 'leaves an off-site img alone', DOS_Images_Markup::rewrite( $offsite ) === $offsite );

$data = '<html><body><img src="data:image/gif;base64,R0lGOD"></body></html>';
check( 'leaves a data: placeholder alone', DOS_Images_Markup::rewrite( $data ) === $data );

check( 'ignores a fragment that is not a document', DOS_Images_Markup::rewrite( '<img src="x.jpg">' ) === '<img src="x.jpg">' );

DOS_Settings::set( 'images_markup_dry_run', 1 );
$dry = DOS_Images_Markup::rewrite( $html );
check( 'dry run serves the original markup', $dry === $html );
check( '  but still records what it would change', false !== DOS_Images_Markup::last_page_log() );

/* ===================================================================== */
echo "\n--- reference detection ---\n";

$GLOBALS['attachments'] = array( 11 => 'hero.jpg', 12 => '2026/01/beach.jpg', 13 => 'logo.png' );

$cases = array(
    'editor class'       => array( '<img class="wp-image-11" src="x">', 11 ),
    'gallery shortcode'  => array( '[gallery ids="12,13"]', 12 ),
    'block JSON id'      => array( '{"id":13,"url":"x"}', 13 ),
    'builder mediaId'    => array( '{"mediaId": 11}', 11 ),
    'plain URL'          => array( '<img src="https://example.com/wp-content/uploads/2026/01/beach.jpg">', 12 ),
    'sized URL variant'  => array( '<img src="https://example.com/wp-content/uploads/2026/01/beach-300x200.jpg">', 12 ),
    'protocol-relative'  => array( '"//example.com/wp-content/uploads/hero.jpg"', 11 ),
    'escaped JSON slash' => array( '"https:\\/\\/example.com\\/wp-content\\/uploads\\/logo.png"', 13 ),
);

foreach ( $cases as $label => $case ) {
    list( $content, $expect ) = $case;
    $found = DOS_Images_Usage::attachment_ids_in_content( $content );
    check( "finds by $label", in_array( $expect, $found, true ), 'got ' . json_encode( $found ) );
}

check( 'finds nothing in empty content', array() === DOS_Images_Usage::attachment_ids_in_content( '' ) );

/* ===================================================================== */
echo "\n--- page-builder layouts live in post meta, not post_content ---\n";

$GLOBALS['attachments'] = array( 11 => 'hero.jpg', 12 => '2026/01/beach.jpg', 13 => 'logo.png' );
$GLOBALS['meta'] = array();

// A Themify-style builder post: empty content, the whole layout in meta.
$GLOBALS['meta'][50] = array(
    '_themify_builder_settings_json' => '{"modules":[{"mod_name":"image","img_url":"https:\\/\\/example.com\\/wp-content\\/uploads\\/2026\\/01\\/beach.jpg"}]}',
    '_edit_lock' => '1700000000:1',
);
$builder_post = (object) array( 'ID' => 50, 'post_content' => '' );

$text  = DOS_Images_Usage::scannable_text( $builder_post );
$found = DOS_Images_Usage::attachment_ids_in_content( $text );

check( 'finds an image referenced only in builder meta', in_array( 12, $found, true ), 'got ' . json_encode( $found ) );
check( '  which post_content alone would have missed', array() === DOS_Images_Usage::attachment_ids_in_content( $builder_post->post_content ) );

// An ACF-style field holding a bare attachment ID.
$GLOBALS['meta'][51] = array( 'hero_image' => '{"id":13}' );
$found = DOS_Images_Usage::attachment_ids_in_content( DOS_Images_Usage::scannable_text( (object) array( 'ID' => 51, 'post_content' => '' ) ) );
check( 'finds an image referenced by ID in a custom field', in_array( 13, $found, true ), json_encode( $found ) );

// Our own bookkeeping must not make everything look used on a second pass.
$GLOBALS['meta'][52] = array(
    '_dos_usage_used_in' => 'a:1:{i:0;a:2:{s:7:"post_id";i:11;s:4:"type";s:7:"content";}}',
);
$text = DOS_Images_Usage::scannable_text( (object) array( 'ID' => 52, 'post_content' => '' ) );
check( 'skips the scan\'s own meta keys', false === strpos( $text, 'post_id' ), $text );

$GLOBALS['meta'] = array();

echo "\n--- deletion: paging and protection ---\n";

$GLOBALS['attachments'] = array();
foreach ( range( 1, 10 ) as $i ) { $GLOBALS['meta'][ $i ]['_wp_attached_file'] = "img{$i}.jpg"; }

// 5 is the site logo, 7 is the SEO share image.
$GLOBALS['options']['theme_mod_custom_logo'] = 5;
DOS_Settings::set( 'seo_fallback_image', 7 );

$GLOBALS['unused']  = range( 1, 10 );
$GLOBALS['deleted'] = array();

$r = DOS_Images_Usage::delete_step( 0, 4, true );
check( 'dry run deletes nothing', array() === $GLOBALS['deleted'] );
check( '  reports what it would delete', 4 === count( $r['notes'] ), json_encode( $r['notes'] ) );
check( '  counts all four on a page with nothing protected', 4 === $r['changed'], 'changed=' . $r['changed'] );

// Page two holds ids 5-8, which includes the logo (5) and the share image (7).
$r2 = DOS_Images_Usage::delete_step( 4, 4, true );
check( '  a dry run advances by offset', false === strpos( json_encode( $r2['notes'] ), 'img1.jpg' ), json_encode( $r2['notes'] ) );
check( '  protected images are reported as kept, not as deletions', 2 === $r2['changed'], 'changed=' . $r2['changed'] );
check( '    and named in the report', 2 === count( preg_grep( '/^Kept /', $r2['notes'] ) ), json_encode( $r2['notes'] ) );
check( '  a dry run does not mark protected images used', 'used' !== ( $GLOBALS['meta'][5]['_dos_usage_status'] ?? 'unused' ) );

// Live: always takes the first page, because deleting shrinks the set.
$GLOBALS['unused'] = range( 1, 10 );
$guard = 0;
do {
    $r = DOS_Images_Usage::delete_step( $guard * 4, 4, false );
    $guard++;
} while ( $r['processed'] > 0 && $guard < 10 );

check( 'live run drains the whole set', 8 === count( $GLOBALS['deleted'] ), 'deleted ' . count( $GLOBALS['deleted'] ) . ': ' . json_encode( $GLOBALS['deleted'] ) );
check( '  the site logo survived', ! in_array( 5, $GLOBALS['deleted'], true ) );
check( '  the SEO share image survived', ! in_array( 7, $GLOBALS['deleted'], true ) );
check( '  every deletion was logged', 8 === count( array_filter( $GLOBALS['log'], function ( $l ) { return 0 === strpos( $l, 'attachment_deleted' ); } ) ) );
check( '  the run terminated rather than looping on protected images', $guard < 10, 'iterations=' . $guard );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
