<?php
/**
 * The shared media picker. Storage format must stay a bare attachment ID, and
 * the two states worth getting right are a dangling ID and an image too small
 * to use as a share image.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'DOS_TOOLKIT_VERSION', 'test' );
define( 'DOS_TOOLKIT_URL', 'https://example.com/plugin/' );

$GLOBALS['library'] = array();   // id => array(width, height)

function __( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : array() ); }
function wp_enqueue_media() {} function wp_enqueue_style() {} function wp_enqueue_script() {} function wp_localize_script() {}

function wp_get_attachment_image_src( $id, $size ) {
    if ( ! isset( $GLOBALS['library'][ $id ] ) ) { return false; }
    list( $w, $h ) = $GLOBALS['library'][ $id ];
    if ( 'medium' === $size ) { $w = min( $w, 300 ); $h = min( $h, 300 ); }
    return array( "https://example.com/uploads/{$id}.jpg", $w, $h );
}

require PLUGIN . '/includes/class-dos-media-field.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}
function render( $name, $value, $args = array() ) {
    ob_start();
    DOS_Media_Field::render( $name, $value, $args );
    return ob_get_clean();
}

$GLOBALS['library'] = array( 42 => array( 1600, 900 ), 7 => array( 400, 300 ) );

echo "--- empty state ---\n";
$out = render( 'fallback_image', 0 );
check( 'posts under the given field name', false !== strpos( $out, 'name="fallback_image"' ), $out );
check( 'stores an empty value, not a zero', false !== strpos( $out, 'value=""' ), $out );
check( 'offers Choose, not Change', false !== strpos( $out, 'Choose image' ) && false === strpos( $out, 'Change image' ) );
check( 'hides Remove', false !== strpos( $out, 'dos-media-remove" hidden' ) || false !== strpos( $out, "hidden" ) );
check( 'renders no preview image', false === strpos( $out, '<img' ) );

echo "\n--- populated ---\n";
$out = render( 'fallback_image', 42, array( 'min_width' => 1200, 'min_height' => 630 ) );
check( 'keeps the attachment ID as the stored value', false !== strpos( $out, 'value="42"' ), $out );
check( 'renders a preview', false !== strpos( $out, '<img src="https://example.com/uploads/42.jpg"' ), $out );
check( 'shows the full dimensions, not the preview size', false !== strpos( $out, '1600×900' ), $out );
check( 'offers Change', false !== strpos( $out, 'Change image' ) );
check( 'no size warning on a large enough image', false === strpos( $out, 'may crop or refuse' ) );

echo "\n--- too small for a share image ---\n";
$out = render( 'fallback_image', 7, array( 'min_width' => 1200, 'min_height' => 630 ) );
check( 'warns when below the minimum', false !== strpos( $out, 'may crop or refuse' ), $out );
check( '  names the minimum', false !== strpos( $out, '1200×630' ), $out );
check( '  still keeps the value', false !== strpos( $out, 'value="7"' ) );

echo "\n--- dangling ID ---\n";
$out = render( 'fallback_image', 999, array( 'min_width' => 1200, 'min_height' => 630 ) );
check( 'says the image is gone rather than rendering a broken preview', false !== strpos( $out, 'no longer in the media library' ), $out );
check( '  names the missing ID', false !== strpos( $out, '#999' ), $out );
check( '  renders no img tag', false === strpos( $out, '<img' ), $out );
check( '  keeps the value so it is not silently discarded', false !== strpos( $out, 'value="999"' ), $out );

echo "\n--- passthrough ---\n";
$out = render( 'dos_seo_image', 42, array( 'description' => 'Leave empty to use the featured image.' ) );
check( 'renders the description', false !== strpos( $out, 'Leave empty to use the featured image.' ) );
check( 'carries the min-width constraint as data', false !== strpos( $out, 'data-min-width="0"' ), $out );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
