<?php
/**
 * Builds plugins/dos-best-lenders/assets/state-outlines.json: one pre-projected outline path per state (all 50
 * plus DC), each fitted to the 300x200 tile viewBox with the same projection as includes/class-maps.php
 * (equirectangular, x scaled by cos of the middle latitude, Alaska's Aleutians shifted west of the antimeridian).
 *
 * Source: US Census Bureau TIGERweb Generalized_ACS2023 State_County, layer 9 "States 20M" (the 1:20M cartographic
 * boundary files; US federal data, public domain). The carousel on the Best Lenders homepage draws these, so
 * states with no city data yet still get a picture, and no per-state geometry option is needed.
 *
 * Usage:   php tools/blnm-state-outlines/build.php [states.geojson]
 * With no argument it downloads the layer (needs network); with a path it reads that GeoJSON FeatureCollection
 * (properties STUSAB or GEOID). Output is committed; rerun only when the drawing style or tolerance changes.
 * Rings are simplified (Ramer-Douglas-Peucker, EPS px) after projection to integer coordinates and specks
 * under MIN_AREA px^2 are dropped (the largest ring of a state is always kept).
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/plugins/dos-best-lenders/includes/class-maps.php';

use BLNM\Maps;

const EPS      = 0.7;
const MIN_AREA = 2.0;
const URL      = 'https://tigerweb.geo.census.gov/arcgis/rest/services/Generalized_ACS2023/State_County/MapServer/9/query?where=1%3D1&outFields=GEOID,STUSAB&returnGeometry=true&geometryPrecision=3&outSR=4326&f=geojson';

$src = $argv[1] ?? '';
$raw = '' !== $src ? file_get_contents( $src ) : file_get_contents( URL, false, stream_context_create( array( 'http' => array( 'header' => "User-Agent: Mozilla/5.0\r\n" ) ) ) );
$gj  = json_decode( (string) $raw, true );
if ( ! is_array( $gj ) || empty( $gj['features'] ) ) {
	fwrite( STDERR, "No features.\n" );
	exit( 1 );
}

$by_fips = array_flip( Maps::FIPS );

function dp( array $pts, $eps ) {
	$n = count( $pts );
	if ( $n < 3 ) {
		return $pts;
	}
	$keep = array_fill( 0, $n, false );
	$keep[0] = $keep[ $n - 1 ] = true;
	$stack = array( array( 0, $n - 1 ) );
	while ( $stack ) {
		list( $a, $b ) = array_pop( $stack );
		$max = 0;
		$idx = -1;
		$dx  = $pts[ $b ][0] - $pts[ $a ][0];
		$dy  = $pts[ $b ][1] - $pts[ $a ][1];
		$len = sqrt( $dx * $dx + $dy * $dy );
		for ( $i = $a + 1; $i < $b; $i++ ) {
			$px = $pts[ $i ][0] - $pts[ $a ][0];
			$py = $pts[ $i ][1] - $pts[ $a ][1];
			$d  = $len > 0 ? abs( $dx * $py - $dy * $px ) / $len : sqrt( $px * $px + $py * $py );
			if ( $d > $max ) {
				$max = $d;
				$idx = $i;
			}
		}
		if ( $max > $eps ) {
			$keep[ $idx ] = true;
			$stack[]      = array( $a, $idx );
			$stack[]      = array( $idx, $b );
		}
	}
	$out = array();
	foreach ( $pts as $i => $p ) {
		if ( $keep[ $i ] ) {
			$out[] = $p;
		}
	}
	return $out;
}

function area( array $pts ) {
	$s = 0;
	$n = count( $pts );
	for ( $i = 0; $i < $n; $i++ ) {
		$j  = ( $i + 1 ) % $n;
		$s += $pts[ $i ][0] * $pts[ $j ][1] - $pts[ $j ][0] * $pts[ $i ][1];
	}
	return abs( $s ) / 2;
}

$out = array();
foreach ( $gj['features'] as $f ) {
	$p    = $f['properties'] ?? array();
	$st   = strtoupper( (string) ( $p['STUSAB'] ?? '' ) );
	if ( '' === $st || ! isset( Maps::FIPS[ $st ] ) ) {
		$st = $by_fips[ str_pad( (string) ( $p['GEOID'] ?? '' ), 2, '0', STR_PAD_LEFT ) ] ?? '';
	}
	if ( '' === $st ) {
		continue; // Puerto Rico and other territories.
	}
	$one = array( 'features' => array( array( 'properties' => array( 'GEOID' => Maps::FIPS[ $st ] ), 'geometry' => $f['geometry'] ) ) );
	$geo = Maps::project( $one, $st );
	if ( ! $geo ) {
		fwrite( STDERR, "project failed: $st\n" );
		exit( 1 );
	}
	$sc    = $geo['scale'];
	$rings = array();
	foreach ( Maps::rings( $f['geometry'] ) as $ring ) {
		$pts  = array();
		$prev = null;
		foreach ( $ring as $pt ) {
			$xy = Maps::xy( $sc, $pt[0], $pt[1], $st );
			if ( $xy !== $prev ) {
				$pts[] = $xy;
				$prev  = $xy;
			}
		}
		if ( count( $pts ) > 1 && $pts[0] === end( $pts ) ) {
			array_pop( $pts );
		}
		$pts = dp( $pts, EPS );
		if ( count( $pts ) >= 3 ) {
			$rings[] = array( area( $pts ), $pts );
		}
	}
	usort( $rings, static function ( $a, $b ) { return $b[0] <=> $a[0]; } );
	$d = '';
	foreach ( $rings as $i => $r ) {
		if ( $i > 0 && $r[0] < MIN_AREA ) {
			continue;
		}
		foreach ( $r[1] as $k => $xy ) {
			$d .= ( 0 === $k ? 'M' : 'L' ) . $xy[0] . ' ' . $xy[1];
		}
		$d .= 'Z';
	}
	$out[ $st ] = $d;
}
ksort( $out );
if ( count( $out ) !== 51 ) {
	fwrite( STDERR, 'Expected 51 states, got ' . count( $out ) . "\n" );
	exit( 1 );
}
$file = dirname( __DIR__, 2 ) . '/plugins/dos-best-lenders/assets/state-outlines.json';
file_put_contents(
	$file,
	json_encode( array( 'viewBox' => array( Maps::W, Maps::H ), 'source' => 'US Census Bureau TIGERweb Generalized_ACS2023 States 20M (public domain)', 'paths' => $out ), JSON_UNESCAPED_SLASHES ) . "\n"
);
echo 'Wrote ' . $file . ' (' . filesize( $file ) . " bytes)\n";
