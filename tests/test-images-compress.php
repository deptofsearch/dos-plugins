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
function wp_parse_args( $args, $defaults = array() ) {
    return array_merge( $defaults, (array) $args );
}
$GLOBALS['meta']  = array();
$GLOBALS['mimes'] = array();
$GLOBALS['files'] = array();
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ (int) $id ][ $k ] = $v; return true; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][ (int) $id ][ $k ] ?? ''; }
function get_post_mime_type( $id ) { return $GLOBALS['mimes'][ (int) $id ] ?? 'image/jpeg'; }
function get_attached_file( $id ) { return $GLOBALS['files'][ (int) $id ] ?? ''; }
class WP_Error {
    public $code; public $message;
    public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_raise_memory_limit( $c = '' ) { return false; }
// No GD in the test image, so the editor is the thing that is missing. That
// is also the honest shape of the failure on a host without it.
function wp_get_image_editor( $p ) { return new WP_Error( 'dos_no_editor', 'No image editor is available.' ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function wp_delete_file( $f ) { $GLOBALS['deleted'][] = $f; if ( file_exists( $f ) ) { @unlink( $f ); } return true; }
$GLOBALS['deleted'] = array();
function wp_basename( $path ) { return basename( str_replace( '\\', '/', (string) $path ) ); }
function size_format( $bytes, $decimals = 0 ) { return round( $bytes / 1024, $decimals ) . ' KB'; }
function get_posts( $args ) {
    // Newest META_TIME first, which is what recent() asks for.
    $ids = array_keys( $GLOBALS['meta'] );
    usort( $ids, function ( $a, $b ) {
        return ( $GLOBALS['meta'][ $b ]['_dos_compressed_at'] ?? 0 ) <=> ( $GLOBALS['meta'][ $a ]['_dos_compressed_at'] ?? 0 );
    } );
    return array_slice( $ids, 0, (int) ( $args['posts_per_page'] ?? 10 ) );
}
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

echo "\n--- the record of what has actually been compressed ---\n";
DOS_Images_Compress::reset_stats();
$stats = DOS_Images_Compress::stats();
check( 'starts at nothing rather than at nothing-shaped garbage', 0 === $stats['count'] && 0 === $stats['saved'] );
check( 'and claims no first compression before there was one', 0 === $stats['first'] );

DOS_Images_Compress::record_totals( 1000, 600 );
$stats = DOS_Images_Compress::stats();
check( 'one compression counts once', 1 === $stats['count'] );
check( 'and records what it saved, not what it might have', 400 === $stats['saved'], json_encode( $stats ) );
check( 'before and after are both kept, so a percentage is possible later', 1000 === $stats['before'] && 600 === $stats['after'] );
$first = $stats['first'];
check( 'the first compression is stamped', $first > 0 );

DOS_Images_Compress::record_totals( 500, 450 );
$stats = DOS_Images_Compress::stats();
check( 'a second compression adds to the total', 2 === $stats['count'] && 450 === $stats['saved'], json_encode( $stats ) );
check( 'and does not move the first-ever stamp', $first === $stats['first'] );

echo "\n--- the per-image record is the guard against a second lossy pass ---\n";
check( 'an untouched image has no record', ! DOS_Images_Compress::is_compressed( 101 ) );
DOS_Images_Compress::mark_attachment( 101, array( 'before' => 1000, 'after' => 600, 'saved' => 400 ), 'library' );
check( 'a compressed one does', DOS_Images_Compress::is_compressed( 101 ) );
$record = DOS_Images_Compress::record_for( 101 );
check( 'the record says what it saved', 400 === $record['saved'] );
check( 'and at what quality, because that number changes over time', 78 === $record['quality'], json_encode( $record ) );
check( 'and where it came from', 'library' === $record['source'] );

echo "\n--- what the library job refuses to touch ---\n";
$GLOBALS['files'][101] = fake_png( 1200, 900 );
$result = DOS_Images_Compress::compress_attachment( 101, false );
check( 'an already-optimised image is skipped, not re-encoded', 'skipped' === $result['status'] && 'already optimised' === $result['note'], json_encode( $result ) );

$GLOBALS['mimes'][102] = 'image/gif';
$GLOBALS['files'][102] = '/tmp/animation.gif';
$result = DOS_Images_Compress::compress_attachment( 102, false );
check( 'a GIF is skipped', 'skipped' === $result['status'] && false !== strpos( $result['note'], 'JPEG or PNG' ), json_encode( $result ) );
check( 'and skipping does not count as work done', 2 === DOS_Images_Compress::stats()['count'] );

$GLOBALS['mimes'][103] = 'image/jpeg';
$GLOBALS['files'][103] = '/tmp/dos-definitely-absent-' . uniqid() . '.jpg';
$result = DOS_Images_Compress::compress_attachment( 103, false );
check( 'a missing file says so rather than failing obscurely', 'skipped' === $result['status'] && 'not on disk' === $result['note'], json_encode( $result ) );

echo "\n--- the report reads back newest first ---\n";
DOS_Images_Compress::mark_attachment( 104, array( 'before' => 800, 'after' => 500, 'saved' => 300 ), 'upload' );
$GLOBALS['meta'][104]['_dos_compressed_at'] = $GLOBALS['meta'][101]['_dos_compressed_at'] + 60;
$recent = DOS_Images_Compress::recent( 25 );
check( 'both compressions are listed', 2 === count( $recent ), json_encode( array_column( $recent, 'id' ) ) );
check( 'the most recent is first', 104 === $recent[0]['id'] );
check( 'each row carries its record', 300 === $recent[0]['record']['saved'] );
check( 'an upload is labelled as one', 'upload' === $recent[0]['record']['source'] );

echo "\n--- the size limit, read off the file rather than off the metadata ---\n";
// A PNG header is enough: getimagesize reads IHDR and never decodes pixels,
// which is exactly what the oversize check needs and all it needs.
function fake_png( $w, $h ) {
    $ihdr = pack( 'NN', $w, $h ) . chr( 8 ) . chr( 2 ) . chr( 0 ) . chr( 0 ) . chr( 0 );
    $png  = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) );
    $path = sys_get_temp_dir() . '/dos-' . $w . 'x' . $h . '-' . uniqid() . '.png';
    file_put_contents( $path, $png );
    return $path;
}

$big   = fake_png( 4000, 3000 );
$small = fake_png( 1200, 900 );
check( 'a 4000px image is over a 2560 limit', DOS_Images_Compress::is_oversized( $big, 2560 ) );
check( 'a 1200px image is not', ! DOS_Images_Compress::is_oversized( $small, 2560 ) );
check( 'a tall image counts on its longest edge', DOS_Images_Compress::is_oversized( fake_png( 800, 4000 ), 2560 ) );
check( 'a limit of zero means no limit rather than every image', ! DOS_Images_Compress::is_oversized( $big, 0 ) );
check( 'a file that is not there is not called oversized', ! DOS_Images_Compress::is_oversized( '/tmp/dos-absent-' . uniqid(), 2560 ) );

echo "\n--- generated sizes bigger than the image they came from ---\n";
$dir = sys_get_temp_dir() . '/dos-sizes-' . uniqid();
mkdir( $dir );
foreach ( array( 'thumb.jpg', 'medium.jpg', 'big.jpg' ) as $f ) { file_put_contents( $dir . '/' . $f, 'x' ); }
$meta = array(
    'width'  => 1920,
    'height' => 1440,
    'sizes'  => array(
        'thumbnail' => array( 'file' => 'thumb.jpg',  'width' => 150,  'height' => 150 ),
        'large'     => array( 'file' => 'medium.jpg', 'width' => 1024, 'height' => 768 ),
        '2048x2048' => array( 'file' => 'big.jpg',    'width' => 2048, 'height' => 1536 ),
    ),
);
$GLOBALS['deleted'] = array();
$removed = DOS_Images_Compress::prune_oversized_sizes( $meta, 1920, 1440, $dir );
check( 'the size wider than the image is dropped', array( '2048x2048' ) === $removed, json_encode( $removed ) );
check( 'and the ones inside it are kept', isset( $meta['sizes']['thumbnail'], $meta['sizes']['large'] ) );
check( 'the dropped entry is gone from the metadata', ! isset( $meta['sizes']['2048x2048'] ) );
check( 'and its file went with it, rather than orphaning on disk', ! file_exists( $dir . '/big.jpg' ) );
check( 'the kept files are untouched', file_exists( $dir . '/medium.jpg' ) );

$meta2 = array( 'width' => 1920, 'height' => 1440 );
check( 'an attachment with no generated sizes does not error', array() === DOS_Images_Compress::prune_oversized_sizes( $meta2, 1920, 1440, $dir ) );

echo "\n--- when a second lossy pass is allowed, and when it is not ---\n";
DOS_Settings::set( 'images_threshold', 2560 );
$GLOBALS['mimes'][201] = 'image/jpeg';
$GLOBALS['files'][201] = $small;
DOS_Images_Compress::mark_attachment( 201, array( 'before' => 900, 'after' => 700, 'saved' => 200, 'resized' => false, 'width' => 1200, 'height' => 900 ), 'library' );
$result = DOS_Images_Compress::compress_attachment( 201, false );
check( 'an optimised image inside the limit is left alone', 'skipped' === $result['status'] && 'already optimised' === $result['note'], json_encode( $result ) );

$GLOBALS['mimes'][202] = 'image/jpeg';
$GLOBALS['files'][202] = $big;
DOS_Images_Compress::mark_attachment( 202, array( 'before' => 900, 'after' => 700, 'saved' => 200, 'resized' => true, 'width' => 2560, 'height' => 1920 ), 'library' );
$result = DOS_Images_Compress::compress_attachment( 202, false );
check( 'an image already rescaled is never re-encoded again', 'skipped' === $result['status'] && 'already optimised' === $result['note'], json_encode( $result ) );

$GLOBALS['mimes'][203] = 'image/jpeg';
$GLOBALS['files'][203] = $big;
DOS_Images_Compress::mark_attachment( 203, array( 'before' => 900, 'after' => 700, 'saved' => 200, 'resized' => false, 'width' => 4000, 'height' => 3000 ), 'library' );
$result = DOS_Images_Compress::compress_attachment( 203, false );
check( 'but one compressed while still oversized gets its one rescale', 'skipped' !== $result['status'], json_encode( $result ) );

DOS_Settings::set( 'images_threshold', 0 );
$result = DOS_Images_Compress::compress_attachment( 203, false );
check( 'and with no limit set there is nothing left to do to it', 'skipped' === $result['status'], json_encode( $result ) );
DOS_Settings::set( 'images_threshold', 2560 );

echo "\n--- rescales are counted apart from re-encodes ---\n";
DOS_Images_Compress::reset_stats();
DOS_Images_Compress::record_totals( 1000, 600, true );
DOS_Images_Compress::record_totals( 1000, 900, false );
$stats = DOS_Images_Compress::stats();
check( 'both count as work done', 2 === $stats['count'] );
check( 'only one of them counts as a rescale', 1 === $stats['resized'], json_encode( $stats ) );
check( 'and the bytes are the sum of both', 500 === $stats['saved'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
