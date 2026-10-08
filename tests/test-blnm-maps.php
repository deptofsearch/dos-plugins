<?php
/**
 * Best Lenders state hub maps: projection, tile drawing, brand colours, and the upsert's hub keys.
 * Runs without WordPress (the few WP functions the upsert touches are stubbed in memory).
 */

define( 'ABSPATH', '/tmp/' );
// Any PHP warning or notice is a failure: the upsert once read an undefined array key without anyone noticing.
error_reporting( E_ALL );
set_error_handler( static function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );
define( 'OBJECT', 'OBJECT' );
$plugin = dirname( __DIR__ ) . '/plugins/dos-best-lenders';

// ---- WordPress stubs, just enough for Rest::upsert_city ----
$GLOBALS['posts'] = array();
$GLOBALS['inserted'] = array();
$GLOBALS['meta']  = array();
class WP_Error { public $code; public $msg; public $data; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_message() { return $this->msg; } function get_error_data() { return $this->data; } }
class WP_REST_Request implements ArrayAccess { public $p; function __construct( $p ) { $this->p = $p; } function get_json_params() { return $this->p; } function get_params() { return $this->p; } function offsetExists( $k ): bool { return isset( $this->p[ $k ] ); } function offsetGet( $k ): mixed { return $this->p[ $k ] ?? null; } function offsetSet( $k, $v ): void { $this->p[ $k ] = $v; } function offsetUnset( $k ): void { unset( $this->p[ $k ] ); } }
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
function wp_insert_post( $a ) { $GLOBALS['inserted'][] = $a; $id = count( $GLOBALS['posts'] ) + 1; $GLOBALS['posts'][ $id ] = $a['post_name']; return $id; }
function wp_update_post( $a ) { return $a['ID']; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_permalink( $id ) { return "/p/$id/"; }
function get_post_status( $id ) { return 'publish'; }
function wp_unslash( $s ) { return $s; }
function rest_ensure_response( $v ) { return $v; }
$GLOBALS['options'] = array();
$GLOBALS['http_calls'] = array();
$GLOBALS['http_next'] = null;
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['http_calls'][] = array( $url, $args ); return $GLOBALS['http_next']; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
require $plugin . '/includes/class-data-model.php';
require $plugin . '/includes/class-rest.php';
require $plugin . '/includes/class-maps.php';
require $plugin . '/includes/class-render.php';
require $plugin . '/includes/class-search.php';
require $plugin . '/includes/class-shortcodes.php';
function sanitize_file_name( $s ) { return $s; }

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
check( 'sanitize_lng clamps', 180.0 === Data_Model::sanitize_lng( 400 ) && -180.0 === Data_Model::sanitize_lng( -400 ) );
check( 'sanitize_lat rounds to 5 places', 47.60621 === Data_Model::sanitize_lat( 47.606209999 ) );

$r = Rest::upsert_city( new WP_REST_Request( array(
	'slug' => 'spokane-wa', 'city_name' => 'Spokane', 'state' => 'WA', 'status' => 'publish', 'lenders' => array( array( 'name' => 'Acme Mortgage', 'loans_2025' => 4 ) ),
) ) );
$id = $r['id'];
check( 'create without status is published (no undefined-key read)', 'publish' === $GLOBALS['inserted'][0]['post_status'] );
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

$n_before = count( $GLOBALS['posts'] );
$r4 = Rest::upsert_city( new WP_REST_Request( array( 'slug' => 'nowhere-xx', 'lat' => 1, 'lng' => 2 ) ) );
check( 'unknown slug with only lat/lng is an error, not a post', $r4 instanceof WP_Error && count( $GLOBALS['posts'] ) === $n_before );
$r5 = Rest::upsert_city( new WP_REST_Request( array( 'slug' => 'nowhere-xx', 'city_name' => 'Nowhere', 'create' => false ) ) );
check( 'create:false on unknown slug is 404 blnm_not_found', $r5 instanceof WP_Error && 'blnm_not_found' === $r5->code && count( $GLOBALS['posts'] ) === $n_before );
$r6 = Rest::upsert_city( new WP_REST_Request( array( 'slug' => 'spokane-wa', 'create' => false, 'population' => 230000 ) ) );
check( 'create:false on an existing slug still updates', is_array( $r6 ) && 230000 === $GLOBALS['meta'][ $id ]['blnm_population'] );
$r7 = Rest::upsert_lender( new WP_REST_Request( array( 'lei' => 'ABC123', 'name' => 'X', 'create' => 'false' ) ) );
check( 'lender create:"false" is 404 too', $r7 instanceof WP_Error && 'blnm_not_found' === $r7->code );
Rest::upsert_lender( new WP_REST_Request( array( 'lei' => 'ABC123', 'name' => 'X' ) ) );
check( 'lender create without status is published', 'publish' === end( $GLOBALS['inserted'] )['post_status'] );

// ---- Hub request parsing ----
use BLNM\Shortcodes;
use BLNM\Render;
$d = Shortcodes::hub_request( array() );
check( 'hub_request defaults', array( 'q' => '', 'has' => false ) === $d );
$a = Shortcodes::hub_request( array( 'q' => array( 'x' ), 'has' => array( '1' ) ) );
check( 'hub_request ignores array values', '' === $a['q'] && true === $a['has'] );
check( 'hub_request has=0 is off', false === Shortcodes::hub_request( array( 'has' => '0' ) )['has'] );
check( 'hub_request has="" is off', false === Shortcodes::hub_request( array( 'has' => '' ) )['has'] );
check( 'hub_request has=1 is on', true === Shortcodes::hub_request( array( 'has' => '1' ) )['has'] );
check( 'hub_request ignores old county and sort', array( 'q' => '', 'has' => false ) === Shortcodes::hub_request( array( 'county' => 'Spokane', 'sort' => 'az' ) ) );
check( 'hub_request strips tags from q', 'x' === Shortcodes::hub_request( array( 'q' => '<b>x</b>' ) )['q'] );

// ---- Lender display names (hub cards) ----
$dn = array(
	array( 'CMG MORTGAGE, INC.', '', 'CMG Mortgage' ),
	array( 'MOVEMENT MORTGAGE, LLC', '', 'Movement Mortgage' ),
	array( 'Broker Solutions, Inc.', 'New American Funding - Kirkland, WA', 'New American Funding' ),
	array( 'WELLS FARGO BANK, N.A.', '', 'Wells Fargo Bank' ),
	array( 'UNITED WHOLESALE MORTGAGE, LLC', '', 'United Wholesale Mortgage' ),
	array( 'LOANDEPOT.COM, LLC', '', 'loanDepot.com' ),
	array( 'JPMORGAN CHASE BANK, NATIONAL ASSOCIATION', '', 'JPMorgan Chase Bank, National Association' ),
	array( 'BANK OF AMERICA, N.A.', '', 'Bank of America' ),
	array( 'U.S. BANK NATIONAL ASSOCIATION', '', 'U.S. Bank National Association' ),
	array( 'GUILD MORTGAGE COMPANY LLC', '', 'Guild Mortgage Company' ),
	array( 'USAA FEDERAL SAVINGS BANK', '', 'USAA Federal Savings Bank' ),
	array( 'Rocket Mortgage, LLC', '', 'Rocket Mortgage' ),
	array( 'CMG MORTGAGE, INC.', 'CMG Home Loans', 'CMG Home Loans' ),
	array( 'X', '', 'X' ),
	array( '', '', '' ),
);
foreach ( $dn as $t ) {
	$got = Render::display_name( $t[0], $t[1] );
	check( 'display_name ' . $t[0] . ( $t[1] ? ' + ' . $t[1] : '' ) . ' -> ' . $t[2], $t[2] === $got );
	if ( $t[2] !== $got ) { echo "        got: $got\n"; }
}

// ---- Tile file naming ----
$f1 = Maps::tile_file( 'spokane-wa', '53063', 47.6588, -117.426, 'Map of Spokane' );
check( 'tile file is slug-hash.svg', 1 === preg_match( '/^spokane-wa-[0-9a-f]{8}\.svg$/', $f1 ) );
check( 'same inputs, same file', $f1 === Maps::tile_file( 'spokane-wa', '53063', '47.6588', '-117.426', 'Map of Spokane' ) );
check( 'changed lat, new file', $f1 !== Maps::tile_file( 'spokane-wa', '53063', 47.66, -117.426, 'Map of Spokane' ) );
check( 'changed county, new file', $f1 !== Maps::tile_file( 'spokane-wa', '53033', 47.6588, -117.426, 'Map of Spokane' ) );
check( 'changed alt, new file', $f1 !== Maps::tile_file( 'spokane-wa', '53063', 47.6588, -117.426, 'Map of Spokane in X' ) );
check( 'valid_state accepts WA, rejects ZZ', Maps::valid_state( 'wa' ) && ! Maps::valid_state( 'ZZ' ) );

// ---- Geometry route: posted body and fetch diagnostics ----
$sq = static function ( $geoid, $type = 'Polygon', $x = -120.0 ) {
	$ring = array( array( $x, 47.0 ), array( $x + 1, 47.0 ), array( $x + 1, 48.0 ), array( $x, 48.0 ), array( $x, 47.0 ) );
	return array( 'type' => 'Feature', 'properties' => array( 'GEOID' => $geoid, 'BASENAME' => 'County ' . $geoid ), 'geometry' => array( 'type' => $type, 'coordinates' => 'Polygon' === $type ? array( $ring ) : array( array( $ring ) ) ) );
};
$fc = array( 'type' => 'FeatureCollection', 'features' => array( $sq( '53063' ), $sq( '53033', 'MultiPolygon', -122.0 ) ) );
$r = Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA', 'geojson' => $fc ) ) );
check( 'valid body stores counties, source body', is_array( $r ) && 2 === $r['counties'] && 'body' === $r['source'] && isset( $GLOBALS['options']['blnm_geo_WA'] ) && ! $GLOBALS['http_calls'], json_encode( $r ) );
$bad = $fc; $bad['features'][0] = $sq( '41063' );
$r = Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA', 'geojson' => $bad ) ) );
check( 'GEOID from another state is 400', $r instanceof WP_Error && 400 === $r->data['status'] );
$bad = $fc; $bad['features'][0]['geometry']['type'] = 'Point'; $bad['features'][0]['geometry']['coordinates'] = array( -120, 47 );
$r = Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA', 'geojson' => $bad ) ) );
check( 'Point geometry is 400', $r instanceof WP_Error && 400 === $r->data['status'] );
$bad = $fc; $bad['features'][0]['geometry']['coordinates'][0][1][0] = 500;
check( 'out-of-range longitude is 400', Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA', 'geojson' => $bad ) ) ) instanceof WP_Error );
$bad = $fc; $bad['features'][0]['geometry']['coordinates'][0][1][0] = '-120';
check( 'string coordinate is 400', Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA', 'geojson' => $bad ) ) ) instanceof WP_Error );
check( 'non-collection and empty features are 400', Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA', 'geojson' => 'x' ) ) ) instanceof WP_Error && Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA', 'geojson' => array( 'type' => 'FeatureCollection', 'features' => array() ) ) ) ) instanceof WP_Error );
$bad = $fc; $bad['features'][0]['properties']['BASENAME'] = str_repeat( 'a', 81 );
check( 'BASENAME over 80 chars is 400', Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA', 'geojson' => $bad ) ) ) instanceof WP_Error );
$big = $fc; $big['features'][0]['geometry']['coordinates'][0] = array_fill( 0, 200001, array( -120.5, 47.5 ) );
check( 'over 200k coordinate pairs is 400', Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA', 'geojson' => $big ) ) ) instanceof WP_Error );

$GLOBALS['http_next'] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
$r = Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA' ) ) );
$att = $r instanceof WP_Error ? ( $r->data['attempts'] ?? array() ) : array();
check( 'fetch failure is 502 with a diagnostic per layer', 502 === $r->data['status'] && 2 === count( $att ) && false !== strpos( $att[0]['error'], 'timed out' ), json_encode( $att ) );
check( 'fetch sends a plain user-agent', false !== strpos( $GLOBALS['http_calls'][0][1]['user-agent'], 'BestLendersNearMe' ) && false !== strpos( $GLOBALS['http_calls'][0][0], 'where=STATE%3D%2753%27' ) );
$GLOBALS['http_next'] = array( 'code' => 403, 'body' => '<html>Forbidden ' . str_repeat( 'x', 500 ) );
$r = Maps::rest_geometry( new WP_REST_Request( array( 'st' => 'WA' ) ) );
check( 'non-JSON reply shows HTTP code and 200-char body', 403 === $r->data['attempts'][0]['http'] && 200 === strlen( $r->data['attempts'][0]['body'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
