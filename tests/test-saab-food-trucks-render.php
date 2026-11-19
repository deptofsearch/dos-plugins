<?php
/**
 * DoS SAAB Food Trucks: the rendered block.
 *
 * Pins that every park in the data file is in the server HTML (table row and section), that the
 * filter only appears with the script, that pins come from the data file first and the geocode cache
 * second, that a failed lookup waits a day, that hostile text and links are escaped or dropped, and
 * that no visitor's page load ever calls the geocoder.
 */

define( 'ABSPATH', '/tmp/' );
define( 'DOS_SAAB_FT_DIR', dirname( __DIR__ ) . '/plugins/dos-saab-food-trucks' );
define( 'DOS_SAAB_FT_VERSION', 'test' );

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
function esc_url( $u ) { return preg_match( '#^https?://#', (string) $u ) ? esc_attr( $u ) : ''; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function apply_filters( $h, $v ) { return $v; }
function shortcode_atts( $defaults, $atts, $tag = '' ) { return array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) ); }
$GLOBALS['opts']      = array();
$GLOBALS['enqueued']  = array();
$GLOBALS['scheduled'] = array();
$GLOBALS['http']      = 0;
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_enqueue_style( $h ) { $GLOBALS['enqueued'][] = "style:$h"; }
function wp_enqueue_script( $h ) { $GLOBALS['enqueued'][] = "script:$h"; }
function wp_next_scheduled( $h ) { return $GLOBALS['scheduled'][ $h ] ?? false; }
function wp_schedule_single_event( $t, $h ) { $GLOBALS['scheduled'][ $h ] = $t; return true; }
$GLOBALS['urls'] = array();
function wp_remote_get( $u, $a = array() ) {
	$GLOBALS['http']++;
	$GLOBALS['urls'][] = $u;
	if ( isset( $GLOBALS['http_by_query'] ) ) {
		foreach ( $GLOBALS['http_by_query'] as $q => $reply ) {
			if ( str_ends_with( $u, '&q=' . rawurlencode( $q ) ) ) { return $reply; }
		}
	}
	return $GLOBALS['http_reply'] ?? array( 'body' => '[]' );
}
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function is_wp_error( $r ) { return false; }
function home_url( $p = '' ) { return 'https://example.test/'; }

require DOS_SAAB_FT_DIR . '/includes/render.php';

echo "--- data ---\n";
$parks = dos_saab_ft_parks();
check( 'the data file loads all eight entries', 8 === count( $parks ), (string) count( $parks ) );
check( 'ids are unique', count( $parks ) === count( array_unique( array_column( $parks, 'id' ) ) ) );
check( 'the data file has a valid checked date', '' !== dos_saab_ft_updated() );
check( 'an unknown city is dropped', null === dos_saab_ft_clean_park( array( 'name' => 'X', 'city' => 'spokane' ) ) );
check( 'a park without a name is dropped', null === dos_saab_ft_clean_park( array( 'city' => 'pasco' ) ) );
check( 'out-of-range coordinates are ignored', null === dos_saab_ft_clean_park( array( 'name' => 'X', 'city' => 'pasco', 'lat' => 200, 'lng' => 1 ) )['lat'] );

echo "\n--- render ---\n";
$all  = array( 'filter', 'map', 'table', 'parks' );
$html = dos_saab_ft_render( $parks, $all, '2026-11-19' );
check( 'every park has a table row', count( $parks ) === substr_count( $html, '<tr data-city-item=' ) );
check( 'every park has a section', count( $parks ) === substr_count( $html, '<section class="saab-ft__park' ) );
check( 'the filter stays hidden until the script runs', false !== strpos( $html, 'data-saab-ft-filter hidden' ) );
check( 'the filter shows a chip for each city that has parks', 4 === substr_count( $html, 'class="saab-ft__chip"' ) );
check( 'no pins yet means no map element', false === strpos( $html, 'data-saab-ft-map' ) );
check( 'directions links are built from the address', false !== strpos( $html, 'https://www.google.com/maps/search/?api=1&amp;query=6120%20Burden%20Blvd.' ) );
check( 'empty hours and pick add nothing', false === strpos( $html, 'Hours:' ) && false === strpos( $html, 'Our pick' ) );
check( 'the checked date is shown', false !== strpos( $html, 'Last checked November 19, 2026.' ) );
check( 'schema lists the seven places with an address', 7 === substr_count( $html, '"@type":"ListItem"' ) );
$h = dos_saab_ft_render( $parks, array( 'table' ), '' );
check( 'show="table" renders only the table, with plain names', false === strpos( $h, 'saab-ft__park' ) && false === strpos( $h, 'href="#park-' ) && false === strpos( $h, 'saab-ft__chip' ) );

echo "\n--- pins ---\n";
$with = $parks;
$with[0]['lat'] = 46.2;
$with[0]['lng'] = -119.2;
$GLOBALS['opts'][ DOS_SAAB_FT_GEO_OPTION ] = array( dos_saab_ft_geo_key( $parks[1] ) => array( 'lat' => 46.27, 'lng' => -119.17 ) );
$h = dos_saab_ft_render( $with, $all, '' );
check( 'the map appears once some park has a pin', false !== strpos( $h, 'data-saab-ft-map' ) );
preg_match( '#<script type="application/json" data-saab-ft-points>(.*?)</script>#s', $h, $m );
$pts = json_decode( $m[1] ?? '', true );
check( 'pins come from the file and from the cache', is_array( $pts ) && 2 === count( $pts ) && '46.200000' === $pts[0]['lat'] && '46.270000' === $pts[1]['lat'] );
check( 'coordinates are printed with six decimals', 1 === preg_match( '/"lat":"46\.200000","lng":"-119\.200000"/', $m[1] ) );
check( 'parks with pins get geo in the schema', 2 === substr_count( $h, '"GeoCoordinates"' ) );

echo "\n--- geocoding ---\n";
$GLOBALS['opts'] = array();
dos_saab_ft_shortcode( array() );
check( 'rendering never calls the geocoder', 0 === $GLOBALS['http'] );
check( 'rendering schedules one background lookup', isset( $GLOBALS['scheduled'][ DOS_SAAB_FT_GEOCODE_HOOK ] ) );
check( 'the map and script are enqueued', in_array( 'script:dos-saab-ft-leaflet', $GLOBALS['enqueued'], true ) && in_array( 'script:dos-saab-food-trucks', $GLOBALS['enqueued'], true ) );
check( 'seven addresses need a lookup', 7 === count( dos_saab_ft_needs_lookup( $parks ) ) );
$cg = $parks[5];
check( 'Columbia Gardens has a fallback search', 'columbia-gardens' === $cg['id'] && '' !== $cg['geocode'] && array( $cg['address'], $cg['geocode'] ) === dos_saab_ft_geo_queries( $cg ) );
check( 'a park without a fallback searches its address only', array( $parks[0]['address'] ) === dos_saab_ft_geo_queries( $parks[0] ) );
check( 'the fallback is part of the cache key, so adding one retries at once', dos_saab_ft_geo_key( $cg ) !== dos_saab_ft_geo_key( array_merge( $cg, array( 'geocode' => '' ) ) ) );
$GLOBALS['http_reply'] = array( 'body' => '[{"lat":"46.2","lon":"-119.2"}]' );
dos_saab_ft_geocode_missing();
check( 'the cron run looks up each address once when the first search works', 7 === $GLOBALS['http'], (string) $GLOBALS['http'] );
check( 'answers are cached and nothing is left to look up', 0 === count( dos_saab_ft_needs_lookup( $parks ) ) && null !== dos_saab_ft_coords( $parks[0] ) );
$GLOBALS['opts']       = array();
$GLOBALS['http']       = 0;
$GLOBALS['http_reply'] = array( 'body' => '<html>rate limited</html>' );
dos_saab_ft_geocode_missing();
check( 'a failed lookup is remembered and not retried for a day', 0 === count( dos_saab_ft_needs_lookup( $parks ) ) && null === dos_saab_ft_coords( $parks[0] ) );
check( 'both searches are tried for a park with a fallback', 9 === $GLOBALS['http'], (string) $GLOBALS['http'] );
$GLOBALS['opts']          = array();
$GLOBALS['http']          = 0;
$GLOBALS['urls']          = array();
$GLOBALS['http_reply']    = array( 'body' => '[]' );
$GLOBALS['http_by_query'] = array( $cg['geocode'] => array( 'body' => '[{"lat":"46.21","lon":"-119.11"}]' ) );
dos_saab_ft_geocode_missing();
$c = dos_saab_ft_coords( $cg );
check( 'the fallback search places a park whose address fails', is_array( $c ) && 46.21 === $c[0] && -119.11 === $c[1] );
$qs = array_map( function ( $u ) { return rawurldecode( substr( $u, strpos( $u, '&q=' ) + 3 ) ); }, $GLOBALS['urls'] );
$ia = array_search( $cg['address'], $qs, true );
$ig = array_search( $cg['geocode'], $qs, true );
check( 'the fallback is only searched after the address fails', false !== $ia && false !== $ig && $ig === $ia + 1 );
unset( $GLOBALS['http_by_query'] );

echo "\n--- escaping ---\n";
$evil = dos_saab_ft_clean_park(
	array(
		'id'         => '"><script>',
		'name'       => '<script>alert(1)</script>',
		'city'       => 'pasco',
		'address'    => '"><img src=x>',
		'body'       => '<b>x</b>',
		'pick'       => 'Tacos',
		'review_url' => 'javascript:alert(1)',
		'image'      => 'javascript:alert(2)',
		'n'          => '<i>',
	)
);
$h = dos_saab_ft_park( $evil );
check( 'hostile text is escaped', false === strpos( $h, '<script>' ) && false === strpos( $h, '<img src=x>' ) && false === strpos( $h, '<b>x</b>' ) && false === strpos( $h, '<i>' ) );
check( 'the id is reduced to a safe slug', 1 === preg_match( '/id="park-[a-z0-9-]+"/', $h ) );
check( 'non-http review and image links are dropped', false === strpos( $h, 'javascript:' ) && false === strpos( $h, '<img' ) && false === strpos( $h, 'Read our review' ) );
$h = dos_saab_ft_render( array( $evil ), $all, '' );
check( 'the schema cannot close its script tag', 1 === substr_count( $h, '</script>' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
