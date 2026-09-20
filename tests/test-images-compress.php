<?php
/**
 * Compression decisions. The encoding itself is GD's job; what is pinned here
 * is when the module refuses to try, and what it calls a problem.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'MB_IN_BYTES', 1048576 );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options'] = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function add_filter() {} function add_action() {}
function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
function wp_convert_hr_to_bytes( $v ) {
    $v = trim( (string) $v ); $last = strtolower( substr( $v, -1 ) ); $n = (int) $v;
    if ( 'g' === $last ) { return $n * 1073741824; }
    if ( 'm' === $last ) { return $n * 1048576; }
    if ( 'k' === $last ) { return $n * 1024; }
    return $n;
}

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/modules/images/class-dos-images-compress.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

echo "--- settings stay inside sane bounds ---\n";
check( 'defaults to WordPress\'s own 82', 82 === DOS_Images_Compress::quality() );
DOS_Settings::set( 'images_quality', 200 );
check( 'cannot be set above 100', 100 === DOS_Images_Compress::quality() );
DOS_Settings::set( 'images_quality', 5 );
check( 'cannot be set low enough to ruin an image', 40 === DOS_Images_Compress::quality() );
DOS_Settings::set( 'images_quality', 78 );
check( 'accepts a sensible value', 78 === DOS_Images_Compress::quality() );
check( 'and feeds it to WordPress\'s own filters', 78 === DOS_Images_Compress::filter_quality( 82, 'image/jpeg' ) );

DOS_Settings::set( 'images_threshold', 0 );
check( 'a threshold of zero leaves WordPress to decide', 2560 === DOS_Images_Compress::filter_threshold( 2560 ) );
DOS_Settings::set( 'images_threshold', 1920 );
check( 'otherwise ours wins', 1920 === DOS_Images_Compress::filter_threshold( 2560 ) );

echo "\n--- what it will try to re-encode ---\n";
check( 'JPEG yes', DOS_Images_Compress::compressible( 'image/jpeg' ) );
check( 'PNG yes', DOS_Images_Compress::compressible( 'image/png' ) );
check( 'GIF no — re-encoding an animation destroys it', ! DOS_Images_Compress::compressible( 'image/gif' ) );
check( 'SVG no — it is not a bitmap', ! DOS_Images_Compress::compressible( 'image/svg+xml' ) );
check( 'WebP no, for now', ! DOS_Images_Compress::compressible( 'image/webp' ) );

echo "\n--- memory, because this host has GD and not ImageMagick ---\n";
$small = DOS_Images_Compress::memory_needed( 800, 600 );
$large = DOS_Images_Compress::memory_needed( 6000, 4000 );
check( 'a small image needs a few megabytes', $small > 3 * MB_IN_BYTES && $small < 6 * MB_IN_BYTES, (string) $small );
check( 'a 24 megapixel photograph needs over 200MB', $large > 200 * MB_IN_BYTES, (string) round( $large / MB_IN_BYTES ) . 'MB' );
check( '  which is why it is estimated before opening the file', $large > $small * 40 );

echo "\n--- classifying where the weight is ---\n";
DOS_Settings::set( 'images_threshold', 2560 );

$c = DOS_Images_Compress::classify( 900000, 4000, 3000, 'image/jpeg' );
check( 'oversized dimensions outrank everything else', 'oversized' === $c['bucket'], json_encode( $c ) );
check( '  and the note says what and by how much', false !== strpos( $c['note'], '4000x3000' ), $c['note'] );

$c = DOS_Images_Compress::classify( 2000000, 1200, 900, 'image/png' );
check( 'a large PNG reads as a photograph in the wrong format', 'wrong_format' === $c['bucket'], json_encode( $c ) );

$c = DOS_Images_Compress::classify( 900000, 1600, 1200, 'image/jpeg' );
check( 'a JPEG heavy for its pixels reads as over-quality', 'heavy' === $c['bucket'], json_encode( $c ) );

$c = DOS_Images_Compress::classify( 180000, 1600, 1200, 'image/jpeg' );
check( 'a well-compressed photograph is left alone', 'fine' === $c['bucket'], json_encode( $c ) );

$c = DOS_Images_Compress::classify( 8000, 300, 200, 'image/png' );
check( 'a small PNG logo is not called a photograph', 'fine' === $c['bucket'], json_encode( $c ) );

$c = DOS_Images_Compress::classify( 5000, 0, 0, 'image/jpeg' );
check( 'an image with no recorded dimensions does not divide by zero', is_array( $c ) && isset( $c['bucket'] ) );

echo "\n--- reading a difference score ---\n";
check( 'under 1 is nothing anyone sees', 'no visible change' === DOS_Images_Compress::verdict( 0.4 ) );
check( 'around 1.5 is very slight', false !== strpos( DOS_Images_Compress::verdict( 1.5 ), 'very slight' ) );
check( 'around 3 may show on a gradient', false !== strpos( DOS_Images_Compress::verdict( 3.0 ), 'gradients' ) );
check( 'above 3.5 is too far', false !== strpos( DOS_Images_Compress::verdict( 6.0 ), 'too far' ) );
check( 'an unmeasurable image says so rather than guessing', 'not measured' === DOS_Images_Compress::verdict( null ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
