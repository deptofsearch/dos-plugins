<?php
/**
 * DoS SAAB Food Trucks: data, map coordinates and server-side rendering.
 *
 * Everything a reader (or a search engine) needs is in the HTML the server sends: the table and a
 * section per park. The script only adds the city filter and draws the map.
 *
 * Data: data/parks.json. A park's lat/lng in the file always wins. When they are empty, WP-Cron
 * looks the address up once on OpenStreetMap's Nominatim and keeps the answer in an option, so no
 * visitor's page load ever waits on a geocoder. Until then that park is listed but has no pin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DOS_SAAB_FT_GEO_OPTION  = 'dos_saab_ft_geo';
const DOS_SAAB_FT_GEOCODE_HOOK = 'dos_saab_ft_geocode';
/** A failed lookup is not retried for a day. */
const DOS_SAAB_FT_RETRY_AFTER = 86400;

/** The city filter's keys and labels, in display order. A park with any other city is dropped. */
function dos_saab_ft_cities() {
	return array(
		'kennewick' => __( 'Kennewick', 'dos-saab-food-trucks' ),
		'pasco'     => __( 'Pasco', 'dos-saab-food-trucks' ),
		'richland'  => __( 'Richland area', 'dos-saab-food-trucks' ),
	);
}

/** One park from the data file, with only known fields, as strings. Null when it can't be shown. */
function dos_saab_ft_clean_park( $p ) {
	if ( ! is_array( $p ) ) {
		return null;
	}
	$s = function ( $k ) use ( $p ) {
		return isset( $p[ $k ] ) && is_scalar( $p[ $k ] ) ? trim( (string) $p[ $k ] ) : '';
	};
	$city = strtolower( $s( 'city' ) );
	$name = $s( 'name' );
	if ( '' === $name || ! array_key_exists( $city, dos_saab_ft_cities() ) ) {
		return null;
	}
	$id = preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $s( 'id' ) ) );
	$id = trim( (string) $id, '-' );
	if ( '' === $id ) {
		$id = trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $name ) ), '-' );
	}
	$kind = $s( 'kind' );
	$out  = array(
		'id'         => $id,
		'n'          => substr( $s( 'n' ), 0, 3 ),
		'kind'       => in_array( $kind, array( 'event', 'note' ), true ) ? $kind : 'park',
		'city'       => $city,
		'area'       => $s( 'area' ),
		'name'       => $name,
		'address'    => $s( 'address' ),
		'geocode'    => $s( 'geocode' ),
		'setup'      => $s( 'setup' ),
		'body'       => $s( 'body' ),
		'hours'      => $s( 'hours' ),
		'pick'       => $s( 'pick' ),
		'review_url' => $s( 'review_url' ),
		'image'      => $s( 'image' ),
		'lat'        => null,
		'lng'        => null,
	);
	if ( isset( $p['lat'], $p['lng'] ) && is_numeric( $p['lat'] ) && is_numeric( $p['lng'] ) ) {
		$lat = (float) $p['lat'];
		$lng = (float) $p['lng'];
		if ( abs( $lat ) <= 90 && abs( $lng ) <= 180 ) {
			$out['lat'] = $lat;
			$out['lng'] = $lng;
		}
	}
	return $out;
}

/** Every park that can be shown, in file order, with ids made unique. */
function dos_saab_ft_parks() {
	static $parks = null;
	if ( null !== $parks ) {
		return $parks;
	}
	$file = DOS_SAAB_FT_DIR . '/data/parks.json';
	$raw  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	$list = ( is_array( $raw ) && isset( $raw['parks'] ) && is_array( $raw['parks'] ) ) ? $raw['parks'] : array();
	$list = apply_filters( 'dos_saab_ft_parks', $list );
	$parks = array();
	$seen  = array();
	foreach ( (array) $list as $p ) {
		$p = dos_saab_ft_clean_park( $p );
		if ( null === $p ) {
			continue;
		}
		$base = $p['id'];
		$i    = 2;
		while ( isset( $seen[ $p['id'] ] ) ) {
			$p['id'] = $base . '-' . $i++;
		}
		$seen[ $p['id'] ] = true;
		$parks[]          = $p;
	}
	return $parks;
}

/** The date the data file says it was last checked (YYYY-MM-DD), or ''. */
function dos_saab_ft_updated() {
	$file = DOS_SAAB_FT_DIR . '/data/parks.json';
	$raw  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	$d    = is_array( $raw ) && isset( $raw['updated'] ) ? (string) $raw['updated'] : '';
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
}

/**
 * Cache key for a park's lookup: its address plus its fallback search text, so changing either
 * (say, adding a `geocode` fallback after a failed lookup) asks again at once instead of waiting
 * out the failure.
 */
function dos_saab_ft_geo_key( $p ) {
	$geocode = isset( $p['geocode'] ) ? (string) $p['geocode'] : '';
	return md5( strtolower( trim( (string) $p['address'] ) ) . '|' . strtolower( trim( $geocode ) ) );
}

/** What to search for, in order: the address, then the park's `geocode` fallback when it has one. */
function dos_saab_ft_geo_queries( $p ) {
	$q = array( $p['address'] );
	if ( isset( $p['geocode'] ) && '' !== $p['geocode'] && $p['geocode'] !== $p['address'] ) {
		$q[] = $p['geocode'];
	}
	return $q;
}

/** A park's coordinates: the data file's, else a cached lookup, else null. */
function dos_saab_ft_coords( $p ) {
	if ( null !== $p['lat'] && null !== $p['lng'] ) {
		return array( $p['lat'], $p['lng'] );
	}
	if ( '' === $p['address'] ) {
		return null;
	}
	$cache = get_option( DOS_SAAB_FT_GEO_OPTION, array() );
	$hit   = is_array( $cache ) ? ( $cache[ dos_saab_ft_geo_key( $p ) ] ?? null ) : null;
	if ( is_array( $hit ) && isset( $hit['lat'], $hit['lng'] ) && is_numeric( $hit['lat'] ) && is_numeric( $hit['lng'] ) ) {
		return array( (float) $hit['lat'], (float) $hit['lng'] );
	}
	return null;
}

/** Parks whose address still needs a lookup (no coordinates, not failed in the last day). */
function dos_saab_ft_needs_lookup( $parks ) {
	$cache = get_option( DOS_SAAB_FT_GEO_OPTION, array() );
	$cache = is_array( $cache ) ? $cache : array();
	$out   = array();
	foreach ( $parks as $p ) {
		if ( '' === $p['address'] || null !== dos_saab_ft_coords( $p ) ) {
			continue;
		}
		$key = dos_saab_ft_geo_key( $p );
		$hit = $cache[ $key ] ?? null;
		if ( is_array( $hit ) && ! empty( $hit['failed'] ) && time() - (int) $hit['failed'] < DOS_SAAB_FT_RETRY_AFTER ) {
			continue;
		}
		$out[ $key ] = $p;
	}
	return array_values( $out );
}

/** Schedule one background lookup run when some park still has no pin. */
function dos_saab_ft_maybe_schedule_geocode( $parks ) {
	if ( dos_saab_ft_needs_lookup( $parks ) && ! wp_next_scheduled( DOS_SAAB_FT_GEOCODE_HOOK ) ) {
		wp_schedule_single_event( time() + 30, DOS_SAAB_FT_GEOCODE_HOOK );
	}
}

/**
 * WP-Cron: look up every address that still needs it, one request a second (Nominatim's limit),
 * and keep each answer. Never runs during a visitor's page load.
 */
function dos_saab_ft_geocode_missing() {
	$todo = dos_saab_ft_needs_lookup( dos_saab_ft_parks() );
	if ( ! $todo ) {
		return;
	}
	$cache = get_option( DOS_SAAB_FT_GEO_OPTION, array() );
	$cache = is_array( $cache ) ? $cache : array();
	$sent = 0;
	foreach ( array_slice( $todo, 0, 20 ) as $p ) {
		$key  = dos_saab_ft_geo_key( $p );
		$used = '';
		foreach ( dos_saab_ft_geo_queries( $p ) as $query ) {
			if ( $sent++ > 0 ) {
				sleep( 1 );
			}
			$hit = dos_saab_ft_nominatim( $query );
			if ( $hit ) {
				$used          = $query;
				$cache[ $key ] = array( 'lat' => $hit[0], 'lng' => $hit[1], 'address' => $p['address'], 'query' => $query );
				break;
			}
		}
		if ( '' === $used ) {
			$cache[ $key ] = array( 'failed' => time(), 'address' => $p['address'] );
		}
	}
	update_option( DOS_SAAB_FT_GEO_OPTION, $cache, false );
}

/** One Nominatim search. Returns array( lat, lng ) or null. */
function dos_saab_ft_nominatim( $query ) {
	$url  = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=us&q=' . rawurlencode( $query );
	$resp = wp_remote_get(
		$url,
		array(
			'timeout'    => 10,
			'user-agent' => 'DoS SAAB Food Trucks/' . DOS_SAAB_FT_VERSION . ' (+' . home_url( '/' ) . ')',
		)
	);
	$body = is_wp_error( $resp ) ? null : json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( is_array( $body ) && isset( $body[0]['lat'], $body[0]['lon'] ) && is_numeric( $body[0]['lat'] ) && is_numeric( $body[0]['lon'] ) ) {
		return array( (float) $body[0]['lat'], (float) $body[0]['lon'] );
	}
	return null;
}

/**
 * A coordinate as a 6-decimal string. A float would be printed with the host's serialize_precision,
 * which on shotandabeer.com gives ~40 digits per number.
 */
function dos_saab_ft_coord( $v ) {
	return number_format( (float) $v, 6, '.', '' );
}

/** Google Maps directions search for an address. */
function dos_saab_ft_directions_url( $address ) {
	return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
}

/** The [saab_food_truck_parks] shortcode. show="filter,map,table,parks" (any subset, in that order). */
function dos_saab_ft_shortcode( $atts ) {
	$atts  = shortcode_atts( array( 'show' => 'filter,map,table,parks' ), $atts, 'saab_food_truck_parks' );
	$show  = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $atts['show'] ) ) ) );
	$parks = dos_saab_ft_parks();
	if ( ! $parks ) {
		return '';
	}
	dos_saab_ft_maybe_schedule_geocode( $parks );
	wp_enqueue_style( 'dos-saab-food-trucks' );
	if ( in_array( 'map', $show, true ) ) {
		wp_enqueue_style( 'dos-saab-ft-leaflet' );
		wp_enqueue_script( 'dos-saab-ft-leaflet' );
	}
	if ( in_array( 'map', $show, true ) || in_array( 'filter', $show, true ) ) {
		wp_enqueue_script( 'dos-saab-food-trucks' );
	}
	return dos_saab_ft_render( $parks, $show, dos_saab_ft_updated() );
}

/** The number badge for a park ('' for an entry without a number). */
function dos_saab_ft_badge( $p ) {
	if ( '' === $p['n'] ) {
		return '';
	}
	$cls = 'event' === $p['kind'] ? 'saab-ft__badge saab-ft__badge--event' : 'saab-ft__badge';
	return '<span class="' . $cls . '" aria-hidden="true">' . esc_html( $p['n'] ) . '</span>';
}

/** The whole block. */
function dos_saab_ft_render( $parks, $show, $updated = '' ) {
	$cities = dos_saab_ft_cities();
	$counts = array_fill_keys( array_keys( $cities ), 0 );
	$points = array();
	foreach ( $parks as $p ) {
		$counts[ $p['city'] ]++;
		$c = dos_saab_ft_coords( $p );
		if ( $c ) {
			$points[] = array(
				'id'      => $p['id'],
				'n'       => $p['n'],
				'event'   => 'event' === $p['kind'],
				'city'    => $p['city'],
				'name'    => $p['name'],
				'address' => $p['address'],
				'lat'     => dos_saab_ft_coord( $c[0] ),
				'lng'     => dos_saab_ft_coord( $c[1] ),
			);
		}
	}

	$out = '<div class="saab-ft" data-saab-ft>';

	if ( in_array( 'filter', $show, true ) ) {
		$out .= '<div class="saab-ft__filter" role="group" aria-label="' . esc_attr__( 'Show food truck parks in', 'dos-saab-food-trucks' ) . '" data-saab-ft-filter hidden>';
		$out .= '<button type="button" class="saab-ft__chip" data-city="all" aria-pressed="true">' . esc_html__( 'All', 'dos-saab-food-trucks' ) . ' <span class="saab-ft__chip-n">' . count( $parks ) . '</span></button>';
		foreach ( $cities as $key => $label ) {
			if ( ! $counts[ $key ] ) {
				continue;
			}
			$out .= '<button type="button" class="saab-ft__chip" data-city="' . esc_attr( $key ) . '" aria-pressed="false">' . esc_html( $label ) . ' <span class="saab-ft__chip-n">' . (int) $counts[ $key ] . '</span></button>';
		}
		$out .= '</div>';
		$out .= '<p class="saab-ft__status" data-saab-ft-status aria-live="polite"></p>';
	}

	if ( in_array( 'map', $show, true ) && $points ) {
		$out .= '<div class="saab-ft__map" data-saab-ft-map role="region" aria-label="' . esc_attr__( 'Map of Tri-Cities food truck parks', 'dos-saab-food-trucks' ) . '" hidden></div>';
		$out .= '<script type="application/json" data-saab-ft-points>' . wp_json_encode( $points, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
	}

	if ( in_array( 'table', $show, true ) ) {
		$out .= '<h2 class="saab-ft__h">' . esc_html__( 'At a glance', 'dos-saab-food-trucks' ) . '</h2>';
		$out .= '<div class="saab-ft__table-wrap"><table class="saab-ft__table"><thead><tr>';
		$out .= '<th scope="col"><span class="saab-ft__sr">' . esc_html__( 'Map marker', 'dos-saab-food-trucks' ) . '</span></th>';
		$out .= '<th scope="col">' . esc_html__( 'Park', 'dos-saab-food-trucks' ) . '</th>';
		$out .= '<th scope="col">' . esc_html__( 'City', 'dos-saab-food-trucks' ) . '</th>';
		$out .= '<th scope="col">' . esc_html__( 'Setup', 'dos-saab-food-trucks' ) . '</th>';
		$out .= '</tr></thead><tbody>';
		foreach ( $parks as $p ) {
			$out .= '<tr data-city-item="' . esc_attr( $p['city'] ) . '">';
			$out .= '<td>' . dos_saab_ft_badge( $p ) . '</td>';
			$link = in_array( 'parks', $show, true ) ? '<a href="#park-' . esc_attr( $p['id'] ) . '">' . esc_html( $p['name'] ) . '</a>' : esc_html( $p['name'] );
			$out .= '<th scope="row">' . $link . '</th>';
			$out .= '<td>' . esc_html( $cities[ $p['city'] ] ) . '</td>';
			$out .= '<td>' . esc_html( $p['setup'] ) . '</td>';
			$out .= '</tr>';
		}
		$out .= '</tbody></table></div>';
	}

	if ( in_array( 'parks', $show, true ) ) {
		$out .= '<div class="saab-ft__parks">';
		foreach ( $parks as $p ) {
			$out .= dos_saab_ft_park( $p );
		}
		$out .= '</div>';
	}

	if ( $updated ) {
		$out .= '<p class="saab-ft__updated">' . esc_html(
			sprintf(
				/* translators: %s: date, YYYY-MM-DD */
				__( 'Trucks rotate and hours change with the season. Last checked %s.', 'dos-saab-food-trucks' ),
				gmdate( 'F j, Y', (int) strtotime( $updated . ' 12:00:00 UTC' ) )
			)
		) . '</p>';
	}

	$out .= dos_saab_ft_schema( $parks );
	$out .= '</div>';
	return $out;
}

/** One park's section. */
function dos_saab_ft_park( $p ) {
	$img = '' !== $p['image'] ? esc_url( $p['image'] ) : '';
	$cls = 'saab-ft__park' . ( $img ? ' saab-ft__park--photo' : '' ) . ' saab-ft__park--' . $p['kind'];
	$out = '<section class="' . $cls . '" id="park-' . esc_attr( $p['id'] ) . '" data-city-item="' . esc_attr( $p['city'] ) . '">';
	if ( $img ) {
		$out .= '<img class="saab-ft__photo" src="' . $img . '" alt="' . esc_attr( $p['name'] ) . '" loading="lazy" decoding="async">';
	}
	$out .= '<div class="saab-ft__body">';
	$out .= '<p class="saab-ft__kicker">' . dos_saab_ft_badge( $p ) . '<span>' . esc_html( '' !== $p['area'] ? $p['area'] : dos_saab_ft_cities()[ $p['city'] ] ) . '</span></p>';
	$out .= '<h2 class="saab-ft__name">' . esc_html( $p['name'] ) . '</h2>';
	if ( '' !== $p['address'] ) {
		$out .= '<p class="saab-ft__addr">' . esc_html( $p['address'] ) . '</p>';
	}
	if ( '' !== $p['body'] ) {
		$out .= '<p class="saab-ft__text">' . esc_html( $p['body'] ) . '</p>';
	}
	if ( '' !== $p['pick'] ) {
		$review = '' !== $p['review_url'] ? esc_url( $p['review_url'] ) : '';
		$out   .= '<p class="saab-ft__pick"><span class="saab-ft__pick-label">' . esc_html__( 'Our pick:', 'dos-saab-food-trucks' ) . '</span> ' . esc_html( $p['pick'] );
		if ( $review ) {
			$out .= ' <a href="' . $review . '">' . esc_html__( 'Read our review', 'dos-saab-food-trucks' ) . '</a>';
		}
		$out .= '</p>';
	}
	$meta = array();
	if ( '' !== $p['hours'] ) {
		$meta[] = esc_html__( 'Hours:', 'dos-saab-food-trucks' ) . ' ' . esc_html( $p['hours'] );
	}
	if ( '' !== $p['address'] ) {
		$meta[] = '<a href="' . esc_url( dos_saab_ft_directions_url( $p['address'] ) ) . '" rel="noopener">' . esc_html__( 'Directions', 'dos-saab-food-trucks' ) . '</a>';
	}
	if ( $meta ) {
		$out .= '<p class="saab-ft__meta">' . implode( ' · ', $meta ) . '</p>';
	}
	$out .= '</div></section>';
	return $out;
}

/** schema.org ItemList of the places with an address, for search engines. */
function dos_saab_ft_schema( $parks ) {
	$items = array();
	$pos   = 0;
	foreach ( $parks as $p ) {
		if ( '' === $p['address'] ) {
			continue;
		}
		$place = array(
			'@type'   => 'event' === $p['kind'] ? 'Place' : 'FoodEstablishment',
			'name'    => $p['name'],
			'address' => $p['address'],
		);
		if ( '' !== $p['body'] ) {
			$place['description'] = $p['body'];
		}
		$c = dos_saab_ft_coords( $p );
		if ( $c ) {
			$place['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => dos_saab_ft_coord( $c[0] ), 'longitude' => dos_saab_ft_coord( $c[1] ) );
		}
		$items[] = array( '@type' => 'ListItem', 'position' => ++$pos, 'item' => $place );
	}
	if ( ! $items ) {
		return '';
	}
	$ld = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'ItemList',
		'name'            => 'Tri-Cities food truck parks',
		'itemListElement' => $items,
	);
	return '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
}
