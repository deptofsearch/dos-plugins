<?php
/**
 * State hub map tiles: one small SVG per city (the state's counties, the city's county highlighted, a dot
 * for the city), drawn from Census TIGERweb county outlines. No text in the tile, no JS, no third-party
 * request at view time. Geometry is fetched once per state (admin REST), projected to a 300x200 viewBox
 * and stored in option blnm_geo_<ST>; tiles are static files written on first use.
 *
 * Projection, tile drawing and the file naming are pure (no WordPress calls) so tests/test-blnm-maps.php
 * runs them without WordPress.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Maps {

	/** Bump when the drawing changes: tiles live in uploads/blnm-maps/v<TILE_VER>/, so old ones are simply ignored. */
	const TILE_VER = 2;

	/** Tile style: 'A' keeps faint interior county lines, 'B' draws the state outline only. One-line switch. */
	const STYLE = 'A';

	const W   = 300;
	const H   = 200;
	const PAD = 8;

	/** Hex values of the brand tokens in assets/blnm-brand.css (light theme). An SVG used as <img> cannot read CSS variables. */
	const COLORS = array(
		'paper'      => '#ffffff', // --blnm-paper: the state's interior and the dot's ring
		'paper-sunk' => '#eceae2', // --blnm-paper-sunk: tile background, same shade as the card
		'rule'       => '#d3d0c6', // --blnm-rule: state outline and county lines
		'spruce'     => '#1d5446', // --blnm-spruce: the city dot
	);

	/** TIGERweb Generalized_ACS2023 State_County: 13 = Counties at 1:20M, 12 = Counties at 1:5M (finer, for tiny states). */
	const TIGER = 'https://tigerweb.geo.census.gov/arcgis/rest/services/Generalized_ACS2023/State_County/MapServer/';

	/** States whose bounding box is under about 4 degrees: they need the finer layer to look like themselves. */
	const SMALL = array( 'RI', 'DE', 'CT', 'NJ', 'DC', 'HI' );

	const FIPS = array(
		'AL' => '01', 'AK' => '02', 'AZ' => '04', 'AR' => '05', 'CA' => '06', 'CO' => '08', 'CT' => '09', 'DE' => '10',
		'DC' => '11', 'FL' => '12', 'GA' => '13', 'HI' => '15', 'ID' => '16', 'IL' => '17', 'IN' => '18', 'IA' => '19',
		'KS' => '20', 'KY' => '21', 'LA' => '22', 'ME' => '23', 'MD' => '24', 'MA' => '25', 'MI' => '26', 'MN' => '27',
		'MS' => '28', 'MO' => '29', 'MT' => '30', 'NE' => '31', 'NV' => '32', 'NH' => '33', 'NJ' => '34', 'NM' => '35',
		'NY' => '36', 'NC' => '37', 'ND' => '38', 'OH' => '39', 'OK' => '40', 'OR' => '41', 'PA' => '42', 'RI' => '44',
		'SC' => '45', 'SD' => '46', 'TN' => '47', 'TX' => '48', 'UT' => '49', 'VT' => '50', 'VA' => '51', 'WA' => '53',
		'WV' => '54', 'WI' => '55', 'WY' => '56',
	);

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/* ---------- Pure: projection and drawing ---------- */

	/** Every ring of a GeoJSON geometry as a list of [lng, lat] lists. */
	public static function rings( $geometry ) {
		$type   = $geometry['type'] ?? '';
		$coords = $geometry['coordinates'] ?? array();
		if ( 'Polygon' === $type ) {
			return (array) $coords;
		}
		$out = array();
		if ( 'MultiPolygon' === $type ) {
			foreach ( (array) $coords as $poly ) {
				foreach ( (array) $poly as $ring ) {
					$out[] = $ring;
				}
			}
		}
		return $out;
	}

	/** Alaska's Aleutians cross the antimeridian: shift eastern-hemisphere longitudes west so the state stays in one piece. */
	public static function fix_lng( $lng, $st ) {
		return ( 'AK' === $st && $lng > 0 ) ? $lng - 360 : $lng;
	}

	/**
	 * Equirectangular projection (x scaled by cos of the middle latitude) fitted into the viewBox with padding.
	 *
	 * @param array  $geojson FeatureCollection with GEOID and BASENAME properties.
	 * @param string $st      USPS code.
	 * @return array|null { ver, bbox: [minLng, minLat, maxLng, maxLat], scale: [lng0, lat1, kx, s, ox, oy], counties: { fips: { name, d } } }
	 */
	public static function project( $geojson, $st ) {
		$feats = (array) ( $geojson['features'] ?? array() );
		$min_x = $min_y = INF;
		$max_x = $max_y = -INF;
		foreach ( $feats as $f ) {
			foreach ( self::rings( $f['geometry'] ?? array() ) as $ring ) {
				foreach ( $ring as $pt ) {
					$x     = self::fix_lng( (float) $pt[0], $st );
					$y     = (float) $pt[1];
					$min_x = min( $min_x, $x );
					$max_x = max( $max_x, $x );
					$min_y = min( $min_y, $y );
					$max_y = max( $max_y, $y );
				}
			}
		}
		if ( ! is_finite( $min_x ) || $max_x <= $min_x || $max_y <= $min_y ) {
			return null;
		}
		$kx = cos( deg2rad( ( $min_y + $max_y ) / 2 ) );
		$w  = ( $max_x - $min_x ) * $kx;
		$h  = $max_y - $min_y;
		$s  = min( ( self::W - 2 * self::PAD ) / $w, ( self::H - 2 * self::PAD ) / $h );
		$ox = ( self::W - $w * $s ) / 2;
		$oy = ( self::H - $h * $s ) / 2;
		$sc = array( $min_x, $max_y, $kx, $s, $ox, $oy );

		$counties = array();
		foreach ( $feats as $f ) {
			$props = (array) ( $f['properties'] ?? array() );
			$fips  = preg_replace( '/\D/', '', (string) ( $props['GEOID'] ?? '' ) );
			if ( '' === $fips ) {
				continue;
			}
			$d = '';
			foreach ( self::rings( $f['geometry'] ?? array() ) as $ring ) {
				$prev = null;
				$seg  = '';
				$n    = 0;
				foreach ( $ring as $pt ) {
					$xy = self::xy( $sc, (float) $pt[0], (float) $pt[1], $st );
					if ( $prev === $xy ) {
						continue; // Integer rounding collapses neighbours; drop repeats.
					}
					$seg .= ( 0 === $n ? 'M' : 'L' ) . $xy[0] . ' ' . $xy[1];
					$prev = $xy;
					++$n;
				}
				if ( $n >= 3 ) {
					$d .= $seg . 'Z';
				}
			}
			$counties[ $fips ] = array(
				'name' => (string) ( $props['BASENAME'] ?? '' ),
				'd'    => $d,
			);
		}
		return array(
			'ver'      => self::TILE_VER,
			'bbox'     => array( $min_x, $min_y, $max_x, $max_y ),
			'scale'    => $sc,
			'counties' => $counties,
		);
	}

	/** Project one lng/lat to integer viewBox coordinates using stored scale params. */
	public static function xy( array $sc, $lng, $lat, $st = '' ) {
		$x = self::fix_lng( (float) $lng, $st );
		return array(
			(int) round( ( $x - $sc[0] ) * $sc[2] * $sc[3] + $sc[4] ),
			(int) round( ( $sc[1] - (float) $lat ) * $sc[3] + $sc[5] ),
		);
	}

	private static function x( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * The tile as an SVG string: paper-sunk background, the state in white with a 1px rule outline, and a spruce
	 * dot (r 6, 2px white ring) for the city. No county highlight. Style A also draws faint interior county lines;
	 * style B draws none. Both draw a 2px rule stroke under all counties first and cover it with white fills, so
	 * only the outer half of the stroke survives: a clean state outline with no union geometry needed.
	 * No lat/lng gives a tile without the dot.
	 */
	public static function tile_svg( array $geo, $fips, $lat, $lng, $alt, $st = '', $style = self::STYLE ) {
		$c    = self::COLORS;
		$ds   = array();
		foreach ( (array) ( $geo['counties'] ?? array() ) as $county ) {
			$ds[] = self::x( $county['d'] );
		}
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::W . ' ' . self::H . '" width="' . self::W . '" height="' . self::H . '" role="img">'
			. '<title>' . self::x( $alt ) . '</title>'
			. '<rect width="' . self::W . '" height="' . self::H . '" fill="' . $c['paper-sunk'] . '"/>'
			. '<g fill="none" stroke="' . $c['rule'] . '" stroke-width="2" stroke-linejoin="round">';
		foreach ( $ds as $d ) {
			$svg .= '<path d="' . $d . '"/>';
		}
		$svg .= '</g>';
		if ( 'B' === $style ) {
			$svg .= '<g fill="' . $c['paper'] . '" stroke="none">';
		} else {
			$svg .= '<g fill="' . $c['paper'] . '" stroke="' . $c['rule'] . '" stroke-width="0.5" stroke-opacity="0.6" stroke-linejoin="round">';
		}
		foreach ( $ds as $d ) {
			$svg .= '<path d="' . $d . '"/>';
		}
		$svg .= '</g>';
		if ( null !== $lat && null !== $lng && '' !== $lat && '' !== $lng && isset( $geo['scale'] ) ) {
			$p    = self::xy( $geo['scale'], $lng, $lat, $st );
			$p[0] = max( 8, min( self::W - 8, $p[0] ) );
			$p[1] = max( 8, min( self::H - 8, $p[1] ) );
			$svg .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="6" fill="' . $c['spruce'] . '" stroke="' . $c['paper'] . '" stroke-width="2"/>';
		}
		return $svg . '</svg>';
	}

	/** "Map of Spokane in Spokane County, Washington" (county part left out when unknown). */
	public static function alt( $city, $county, $state_name ) {
		$county = trim( (string) $county );
		return 'Map of ' . $city . ( '' !== $county ? ' in ' . $county . ' County' : '' ) . ', ' . $state_name;
	}

	/* ---------- WordPress: storage, files, REST ---------- */

	public static function geo( $st ) {
		$geo = get_option( 'blnm_geo_' . $st, null );
		return is_array( $geo ) && ! empty( $geo['counties'] ) ? $geo : null;
	}

	/** Fetch county outlines for a state from TIGERweb and return the GeoJSON array, or WP_Error. */
	public static function fetch_geometry( $st ) {
		if ( ! isset( self::FIPS[ $st ] ) ) {
			return new \WP_Error( 'blnm_bad_state', 'Unknown state.', array( 'status' => 400 ) );
		}
		$layers = in_array( $st, self::SMALL, true ) ? array( 12, 13 ) : array( 13, 12 );
		foreach ( $layers as $layer ) {
			$url = self::TIGER . $layer . '/query?' . http_build_query(
				array(
					'where'             => "STATE='" . self::FIPS[ $st ] . "'",
					'outFields'         => 'GEOID,BASENAME',
					'returnGeometry'    => 'true',
					'geometryPrecision' => 3,
					'outSR'             => 4326,
					'f'                 => 'geojson',
				)
			);
			$res = wp_remote_get( $url, array( 'timeout' => 60 ) );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $body ) && ! empty( $body['features'] ) ) {
				return $body;
			}
		}
		return new \WP_Error( 'blnm_geo_failed', 'TIGERweb returned no counties for ' . $st . '.', array( 'status' => 502 ) );
	}

	private static function dir( $st = '' ) {
		$up = wp_upload_dir();
		return array(
			'path' => trailingslashit( $up['basedir'] ) . 'blnm-maps/v' . self::TILE_VER . ( '' !== $st ? '/' . strtolower( $st ) : '' ),
			'url'  => trailingslashit( $up['baseurl'] ) . 'blnm-maps/v' . self::TILE_VER . ( '' !== $st ? '/' . strtolower( $st ) : '' ),
		);
	}

	/**
	 * URL of a city's tile, writing the file when it is missing. Falls back to a data: URI when the uploads
	 * folder is not writable. '' when the state has no stored geometry yet.
	 *
	 * @param array $row Hub row (slug, fips, lat, lng, n, county) plus 'st' and 'state_name'.
	 */
	public static function tile_url( array $row ) {
		$st  = $row['st'];
		$geo = self::geo( $st );
		if ( ! $geo || '' === (string) $row['slug'] ) {
			return '';
		}
		$d    = self::dir( $st );
		$file = $d['path'] . '/' . sanitize_file_name( $row['slug'] ) . '.svg';
		$url  = $d['url'] . '/' . sanitize_file_name( $row['slug'] ) . '.svg';
		if ( file_exists( $file ) ) {
			return $url;
		}
		$svg = self::tile_svg( $geo, $row['fips'], $row['lat'], $row['lng'], self::alt( $row['n'], $row['county'], $row['state_name'] ), $st );
		if ( wp_mkdir_p( $d['path'] ) && false !== @file_put_contents( $file, $svg ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $url;
		}
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public static function register_routes() {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		$state_arg = array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => array( Data_Model::class, 'sanitize_state' ),
		);
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/states/(?P<st>[A-Za-z]{2})/geometry',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $admin,
				'callback'            => array( __CLASS__, 'rest_geometry' ),
			)
		);
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/maps/build',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $admin,
				'args'                => array( 'state' => $state_arg ),
				'callback'            => array( __CLASS__, 'rest_build' ),
			)
		);
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/maps',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'permission_callback' => $admin,
				'args'                => array( 'state' => $state_arg ),
				'callback'            => array( __CLASS__, 'rest_delete' ),
			)
		);
	}

	/** POST blnm/v1/states/XX/geometry: fetch county outlines, project, store in option blnm_geo_XX. Rewrites no tiles. */
	public static function rest_geometry( \WP_REST_Request $request ) {
		$st = Data_Model::sanitize_state( $request['st'] );
		$gj = self::fetch_geometry( $st );
		if ( is_wp_error( $gj ) ) {
			return $gj;
		}
		$geo = self::project( $gj, $st );
		if ( ! $geo ) {
			return new \WP_Error( 'blnm_geo_empty', 'The outlines had no usable coordinates.', array( 'status' => 502 ) );
		}
		update_option( 'blnm_geo_' . $st, $geo, false );
		return rest_ensure_response(
			array(
				'state'    => $st,
				'counties' => count( $geo['counties'] ),
				'bytes'    => strlen( wp_json_encode( $geo ) ),
			)
		);
	}

	/** POST blnm/v1/maps/build?state=XX: (re)write the tile for every published and draft city of the state. */
	public static function rest_build( \WP_REST_Request $request ) {
		$st  = Data_Model::sanitize_state( $request['state'] );
		$geo = self::geo( $st );
		if ( ! $geo ) {
			return new \WP_Error( 'blnm_no_geo', 'POST /states/' . $st . '/geometry first.', array( 'status' => 409 ) );
		}
		$ids = get_posts(
			array(
				'post_type'      => Data_Model::CITY,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'blnm_state',
				'meta_value'     => $st,
			)
		);
		update_meta_cache( 'post', $ids );
		$d = self::dir( $st );
		wp_mkdir_p( $d['path'] );
		$out = array( 'state' => $st, 'cities' => count( $ids ), 'written' => 0, 'failed' => 0, 'no_coords' => 0, 'no_county_match' => 0 );
		foreach ( $ids as $id ) {
			$lat  = get_post_meta( $id, 'blnm_lat', true );
			$lng  = get_post_meta( $id, 'blnm_lng', true );
			$fips = (string) get_post_meta( $id, 'blnm_county_fips', true );
			$slug = (string) get_post_field( 'post_name', $id );
			if ( '' === $lat || '' === $lng ) {
				++$out['no_coords'];
				$lat = $lng = null;
			}
			if ( ! isset( $geo['counties'][ $fips ] ) ) {
				++$out['no_county_match'];
			}
			$alt = self::alt( (string) get_post_meta( $id, 'blnm_city_name', true ), (string) get_post_meta( $id, 'blnm_county_name', true ), Rest::STATE_NAMES[ $st ] ?? $st );
			$svg = self::tile_svg( $geo, $fips, $lat, $lng, $alt, $st );
			if ( false !== @file_put_contents( $d['path'] . '/' . sanitize_file_name( $slug ) . '.svg', $svg ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				++$out['written'];
			} else {
				++$out['failed'];
			}
		}
		return rest_ensure_response( $out );
	}

	/** DELETE blnm/v1/maps?state=XX: remove the state's tile files (every tile version); they are rewritten on next use. */
	public static function rest_delete( \WP_REST_Request $request ) {
		$st      = Data_Model::sanitize_state( $request['state'] );
		$up      = wp_upload_dir();
		$root    = trailingslashit( $up['basedir'] ) . 'blnm-maps';
		$deleted = 0;
		foreach ( (array) glob( $root . '/v*/' . strtolower( $st ), GLOB_ONLYDIR ) as $dir ) {
			foreach ( (array) glob( $dir . '/*.svg' ) as $f ) {
				if ( @unlink( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					++$deleted;
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return rest_ensure_response( array( 'state' => $st, 'deleted' => $deleted ) );
	}
}
