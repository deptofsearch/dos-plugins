<?php
/**
 * Best Lenders state hub maps: projection, tile drawing, brand colours, and the upsert's hub keys.
 * Runs without WordPress (the few WP functions the upsert touches are stubbed in memory).
 */

define( 'ABSPATH', '/tmp/' );
define( 'OBJECT', 'OBJECT' );
$plugin = dirname( __DIR__ ) . '/plugins/dos-best-lenders';

// ---- WordPress stubs, just enough for Rest::upsert_city ----
$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();
class WP_Error { public $code; function __construct( $c = '', $m = '' ) { $this->code = $c; } }
class WP_REST_Request { public $p; function __construct( $p ) { $this->p = $p; } function get_json_params() { return $this->p; } function get_params() { return $this->p; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^a-zA-Z0-9]+/', '-', $s ), '-' ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses_post( $s ) { return $s; }
function wp_slash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_url_raw( $u ) { return (string) $u; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_page_by_path( $slug ) { foreach ( $GLOBALS['posts'] as $id => $s ) { if ( $s === $slug ) { return (object) array( 'ID' => $id ); } } return null; }
function wp_insert_post( $a ) { $id = count( $GLOBALS['posts'] ) + 1; $GLOBALS['posts'][ $id ] = $a['post_name']; return $id; }
function wp_update_post( $a ) { return $a['ID']; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_permalink( $id ) { return "/p/$id/"; }
function get_post_status( $id ) { return 'publish'; }
function rest_ensure_response( $v ) { return $v; }
require $plugin . '/includes/class-data-model.php';
require $plugin . '/includes/class-rest.php';
require $plugin . '/includes/class-maps.php';

use BLNM\Maps;
use BLNM\Rest;
use BLNM\Data_Model;

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; }
}

// ---- Synthetic two-county state ----
$gj = array( 'features' => array(
	array( 'properties' => array( 'GEOID' => '99001', 'BASENAME' => 'West' ),
		'geometry' => array( 'type' => 'Polygon', 'coordinates' => array( array( array( -120, 40 ), array( -118, 40 ), array( -118, 44 ), array( -120, 44 ), array( -120, 40 ) ) ) ) ),
	array( 'properties' => array( 'GEOID' => '99002', 'BASENAME' => 'East' ),
		'geometry' => array( 'type' => 'MultiPolygon', 'coordinates' => array( array( array( array( -118, 40 ), array( -114, 40 ), array( -114, 44 ), array( -118, 44 ), array( -118, 40 ) ) ) ) ) ),
) );
$geo = Maps::project( $gj, 'ZZ' );
check( 'projects two counties', $geo && 2 === count( $geo['counties'] ) );
check( 'county name kept', 'East' === $geo['counties']['99002']['name'] );

$xs = $ys = array();
foreach ( $geo['counties'] as $c ) {
	preg_match_all( '/[ML](-?\d+) (-?\d+)/', $c['d'], $m );
	$xs = array_merge( $xs, $m[1] );
	$ys = array_merge( $ys, $m[2] );
}
check( 'x inside padded viewBox', min( $xs ) >= Maps::PAD && max( $xs ) <= Maps::W - Maps::PAD );
check( 'y inside padded viewBox', min( $ys ) >= Maps::PAD && max( $ys ) <= Maps::H - Maps::PAD );
check( 'fills one dimension to the padding', min( $xs ) <= Maps::PAD + 1 && max( $xs ) >= Maps::W - Maps::PAD - 1 || min( $ys ) <= Maps::PAD + 1 && max( $ys ) >= Maps::H - Maps::PAD - 1 );
check( 'coordinates are integers', 1 === preg_match( '/^(M-?\d+ -?\d+(L-?\d+ -?\d+)*Z)+$/', $geo['counties']['99001']['d'] ) );
check( 'west county is left of east county', Maps::xy( $geo['scale'], -119, 42 )[0] < Maps::xy( $geo['scale'], -116, 42 )[0] );
check( 'north is up', Maps::xy( $geo['scale'], -119, 43 )[1] < Maps::xy( $geo['scale'], -119, 41 )[1] );

// ---- Tile ----
$alt  = Maps::alt( 'Spokane', 'Spokane', 'Washington' );
check( 'alt text format', 'Map of Spokane in Spokane County, Washington' === $alt );
check( 'alt without county', 'Map of Spokane, Washington' === Maps::alt( 'Spokane', '', 'Washington' ) );
$svg = Maps::tile_svg( $geo, '99002', 42, -116, $alt, 'ZZ' );
check( 'tile is well-formed XML', false !== simplexml_load_string( $svg ) );
check( 'tile title is the alt text', false !== strpos( $svg, '<title>' . $alt . '</title>' ) );
check( 'tile has no text element', false === strpos( $svg, '<text' ) );
check( 'no county highlight colours', false === strpos( $svg, '#dceae3' ) && false === strpos( $svg, 'stroke-width="1.5"' ) );
check( 'background is paper-sunk', false !== strpos( $svg, '<rect width="300" height="200" fill="' . Maps::COLORS['paper-sunk'] . '"/>' ) );
check( 'state interior is paper white', false !== strpos( $svg, '<g fill="' . Maps::COLORS['paper'] . '"' ) );
check( 'dot is spruce r=6 with 2px white ring', 1 === preg_match( '/<circle [^>]*r="6" fill="' . Maps::COLORS['spruce'] . '" stroke="' . Maps::COLORS['paper'] . '" stroke-width="2"/', $svg ) );
check( 'default style A keeps faint county lines', 'A' === Maps::STYLE && false !== strpos( $svg, 'stroke-width="0.5"' ) );
$svgB = Maps::tile_svg( $geo, '99002', 42, -116, $alt, 'ZZ', 'B' );
check( 'style B has no interior lines', false === strpos( $svgB, 'stroke-width="0.5"' ) && false !== strpos( $svgB, 'stroke="none"' ) );
check( 'style B is well-formed XML', false !== simplexml_load_string( $svgB ) );
check( 'tile version is 2', 2 === Maps::TILE_VER );

preg_match( '/<circle cx="(\d+)" cy="(\d+)"/', $svg, $dot );
preg_match_all( '/[ML](-?\d+) (-?\d+)/', $geo['counties']['99002']['d'], $e );
check( 'dot inside its county bbox', $dot[1] >= min( $e[1] ) && $dot[1] <= max( $e[1] ) && $dot[2] >= min( $e[2] ) && $dot[2] <= max( $e[2] ) );
check( 'no lat/lng means no dot', false === strpos( Maps::tile_svg( $geo, '99002', null, null, $alt, 'ZZ' ), '<circle' ) );
check( 'alt text is escaped in the title', false !== strpos( Maps::tile_svg( $geo, '99002', null, null, 'A & "B"', 'ZZ' ), '<title>A &amp; &quot;B&quot;</title>' ) );

// ---- Alaska antimeridian ----
check( 'AK shifts eastern longitudes west', abs( Maps::fix_lng( 179, 'AK' ) - ( 179 - 360 ) ) < 1e-9 && 179 === Maps::fix_lng( 179, 'WA' ) );
$ak = Maps::project( array( 'features' => array(
	array( 'properties' => array( 'GEOID' => '02001', 'BASENAME' => 'Main' ), 'geometry' => array( 'type' => 'Polygon', 'coordinates' => array( array( array( -150, 60 ), array( -140, 60 ), array( -140, 68 ), array( -150, 68 ), array( -150, 60 ) ) ) ) ),
	array( 'properties' => array( 'GEOID' => '02002', 'BASENAME' => 'Aleut' ), 'geometry' => array( 'type' => 'Polygon', 'coordinates' => array( array( array( 172, 51 ), array( 173, 51 ), array( 173, 52 ), array( 172, 52 ), array( 172, 51 ) ) ) ) ),
) ), 'AK' );
check( 'AK bbox is one connected span', $ak['bbox'][0] < -180 && $ak['bbox'][2] === -140.0 );

// ---- Brand colours match assets/blnm-brand.css (light :root block) ----
$css = file_get_contents( $plugin . '/assets/blnm-brand.css' );
preg_match( '/:root\s*\{(.*?)\}/s', $css, $root );
$tok = function ( $name ) use ( $root ) { preg_match( '/--blnm-' . preg_quote( $name, '/' ) . ':\s*(#[0-9a-fA-F]{6})/', $root[1], $m ); return $m[1] ?? null; };
foreach ( Maps::COLORS as $name => $hex ) {
	check( "COLORS['$name'] equals --blnm-$name", strtolower( $hex ) === strtolower( (string) $tok( $name ) ) );
}

// ---- Upsert: hub keys ----
check( 'sanitize_lat clamps', 90.0 === Data_Model::sanitize_lat( 120 ) && -90.0 === Data_Model::sanitize_lat( -95 ) );
check( 'sanitize_lng clamps', 180.0 === Data_Model::sanitize_lng( 400 ) && 47.60621 === Data_Model::sanitize_lat( 47.606209999 ) );

$r = Rest::upsert_city( new WP_REST_Request( array(
	'slug' => 'spokane-wa', 'city_name' => 'Spokane', 'state' => 'WA', 'status' => 'publish', 'lenders' => array( array( 'name' => 'Acme Mortgage', 'loans_2025' => 4 ) ),
) ) );
$id = $r['id'];
$before = $GLOBALS['meta'][ $id ];
check( 'create stores lenders json', false !== strpos( $before['blnm_lenders_json'], 'Acme' ) );
check( 'create without hub keys sets none', ! isset( $before['blnm_lat'] ) && ! isset( $before['blnm_population'] ) );

$r2 = Rest::upsert_city( new WP_REST_Request( array( 'slug' => 'spokane-wa', 'lat' => 47.6588, 'lng' => -117.426, 'population' => 229071 ) ) );
$after = $GLOBALS['meta'][ $id ];
check( 'second call updates the same city', 'updated' === $r2['action'] );
check( 'lat, lng, population stored', 47.6588 === $after['blnm_lat'] && -117.426 === $after['blnm_lng'] && 229071 === $after['blnm_population'] );
$other = array_diff_key( $after, array( 'blnm_lat' => 1, 'blnm_lng' => 1, 'blnm_population' => 1 ) );
check( 'every other meta untouched (lenders, state, county, dates)', $other === $before );

$r3 = Rest::upsert_city( new WP_REST_Request( array( 'slug' => 'spokane-wa', 'lat' => 'north' ) ) );
check( 'non-numeric lat is ignored', 47.6588 === $GLOBALS['meta'][ $id ]['blnm_lat'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
