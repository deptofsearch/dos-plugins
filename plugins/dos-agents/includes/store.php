<?php
/**
 * Agent payload: validation, sanitising and storage.
 *
 * The payload comes from an n8n workflow, but it is treated as untrusted anyway: every field is
 * coerced to its type, trimmed to a length, and anything outside the schema is dropped, so what is
 * stored (and what the public GET returns) is only ever this shape.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Tier values, best local fit first. The position here is the "Local first" order. */
function dos_agents_tiers() {
	return array( 'primary', 'served_with_sales', 'served', 'served_via_url', 'sales_only', 'none' );
}

/** Option name for a city slug ("boise-id"). */
function dos_agents_option_name( $slug ) {
	return DOS_AGENTS_OPTION_PREFIX . $slug;
}

/** "Boise" + "ID" -> "boise-id". */
function dos_agents_city_slug( $city, $state ) {
	return sanitize_title( trim( $city . '-' . $state ) );
}

/** A plain-text string, tags stripped, cut to $max characters. Anything that is not a scalar becomes ''. */
function dos_agents_str( $v, $max ) {
	if ( ! is_scalar( $v ) || is_bool( $v ) ) {
		return '';
	}
	$s = sanitize_text_field( (string) $v );
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $s, 0, $max, 'UTF-8' );
	}
	return substr( $s, 0, $max );
}

/** An integer clamped to 0..$max. */
function dos_agents_int( $v, $max ) {
	if ( ! is_numeric( $v ) ) {
		return 0;
	}
	return (int) max( 0, min( $max, (int) round( (float) $v ) ) );
}

function dos_agents_bool( $v ) {
	if ( is_string( $v ) ) {
		return in_array( strtolower( $v ), array( '1', 'true', 'yes' ), true );
	}
	return (bool) $v;
}

/** A slug-shaped string: lowercase letters, digits and hyphens only. */
function dos_agents_slugish( $v, $max ) {
	$s = strtolower( dos_agents_str( $v, $max ) );
	return trim( preg_replace( '/[^a-z0-9-]+/', '-', $s ), '-' );
}

/** Two-letter state code in capitals, or ''. */
function dos_agents_state( $v ) {
	// Read more than two characters first, so "Idaho" is refused rather than cut down to "ID".
	$s = strtoupper( dos_agents_str( $v, 10 ) );
	return preg_match( '/^[A-Z]{2}$/', $s ) ? $s : '';
}

/** A Zillow profile link, https only, or '' for anything else. */
function dos_agents_profile_url( $v ) {
	if ( ! is_string( $v ) ) {
		return '';
	}
	$u = trim( $v );
	if ( strlen( $u ) > 300 || preg_match( '/[\s<>"\'\\\\]/', $u ) ) {
		return '';
	}
	// Anchored on the exact host: "www.zillow.com.evil.com" and "user@" tricks do not match.
	if ( ! preg_match( '#^https://www\.zillow\.com/profile/[A-Za-z0-9._~%\-]+/?(\?[A-Za-z0-9._~%=&\-]*)?$#', $u ) ) {
		return '';
	}
	return esc_url_raw( $u );
}

/** YYYY-MM-DD that is a real calendar date, or ''. */
function dos_agents_date( $v ) {
	if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $v ), $m ) ) {
		return '';
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? trim( $v ) : '';
}

/** A list of short strings, empty ones dropped, capped at $n items. */
function dos_agents_str_list( $v, $n, $max ) {
	$out = array();
	foreach ( is_array( $v ) ? $v : array() as $item ) {
		$s = dos_agents_str( $item, $max );
		if ( '' !== $s ) {
			$out[] = $s;
		}
		if ( count( $out ) >= $n ) {
			break;
		}
	}
	return $out;
}

/**
 * The optional review window in whole years (1 to 20): how far back the reviews behind an agent's
 * tags and summary were taken from. Anything else (out of range, a fraction, not a number) is
 * dropped rather than clamped, because a wrong window would put a false claim on the card.
 *
 * @return int 1..20, or 0 for "not given".
 */
function dos_agents_window_years( $v ) {
	if ( ! is_numeric( $v ) || (float) $v !== floor( (float) $v ) ) {
		return 0;
	}
	$y = (int) $v;
	return $y >= 1 && $y <= 20 ? $y : 0;
}

/** The keys of an agent's labelled review summary, in the order a card prints them. */
function dos_agents_summary_part_keys() {
	return array( 'buyers', 'sellers', 'working_style' );
}

/**
 * The optional labelled review summary: {buyers, sellers, working_style}, each a plain string of
 * up to 320 characters. Strings only (a number or list in a slot is ignored, not stringified), tags
 * stripped, whitespace collapsed, empty parts dropped, unknown keys ignored. Returns array() when no
 * part survives, which callers treat as "no summary_parts": the agent keeps its single `summary`.
 *
 * @return string[] Only the parts that have text, keyed by part, in card order.
 */
function dos_agents_sanitize_summary_parts( $v ) {
	if ( ! is_array( $v ) ) {
		return array();
	}
	$out = array();
	foreach ( dos_agents_summary_part_keys() as $k ) {
		$s = isset( $v[ $k ] ) && is_string( $v[ $k ] ) ? dos_agents_str( $v[ $k ], 320 ) : '';
		if ( '' !== $s ) {
			$out[ $k ] = $s;
		}
	}
	return $out;
}

/**
 * One agent, in the stored shape, or null when it cannot be used: no name, or no https Zillow
 * profile link (every card's main link is that profile, so an agent without one has no card).
 */
function dos_agents_sanitize_agent( $a ) {
	if ( ! is_array( $a ) ) {
		return null;
	}
	$name = dos_agents_str( isset( $a['name'] ) ? $a['name'] : '', 120 );
	$url  = dos_agents_profile_url( isset( $a['profile_url'] ) ? $a['profile_url'] : '' );
	if ( '' === $name || '' === $url ) {
		return null;
	}

	$g    = function ( $k ) use ( $a ) {
		return isset( $a[ $k ] ) ? $a[ $k ] : null;
	};
	$tier = dos_agents_str( $g( 'tier' ), 24 );
	if ( ! in_array( $tier, dos_agents_tiers(), true ) ) {
		$tier = 'none';
	}

	$office_in = is_array( $g( 'office' ) ) ? $g( 'office' ) : array();
	$office    = array(
		'city'  => dos_agents_str( isset( $office_in['city'] ) ? $office_in['city'] : '', 80 ),
		'state' => dos_agents_state( isset( $office_in['state'] ) ? $office_in['state'] : '' ),
		'zip'   => preg_replace( '/[^0-9-]/', '', dos_agents_str( isset( $office_in['zip'] ) ? $office_in['zip'] : '', 10 ) ),
	);

	$zip_sales = array();
	foreach ( is_array( $g( 'zip_sales' ) ) ? $g( 'zip_sales' ) : array() as $z ) {
		if ( ! is_array( $z ) ) {
			continue;
		}
		$zip = preg_replace( '/[^0-9-]/', '', dos_agents_str( isset( $z['zip'] ) ? $z['zip'] : '', 10 ) );
		if ( '' !== $zip ) {
			$zip_sales[] = array(
				'zip'   => $zip,
				'sales' => dos_agents_int( isset( $z['sales'] ) ? $z['sales'] : 0, 1000000 ),
			);
		}
		if ( count( $zip_sales ) >= 100 ) {
			break;
		}
	}

	$tags = array();
	foreach ( is_array( $g( 'tags' ) ) ? $g( 'tags' ) : array() as $t ) {
		if ( ! is_array( $t ) ) {
			continue;
		}
		$slug  = dos_agents_slugish( isset( $t['slug'] ) ? $t['slug'] : '', 60 );
		$label = dos_agents_str( isset( $t['label'] ) ? $t['label'] : '', 60 );
		$count = dos_agents_int( isset( $t['count'] ) ? $t['count'] : 0, 1000 );
		// A tag nobody mentioned says nothing, and a chip for it would filter to no one.
		if ( '' !== $slug && '' !== $label && $count > 0 ) {
			$tags[] = array(
				'slug'  => $slug,
				'label' => $label,
				'count' => $count,
			);
		}
		if ( count( $tags ) >= 20 ) {
			break;
		}
	}

	$rating = is_numeric( $g( 'rating' ) ) ? round( max( 0, min( 5, (float) $g( 'rating' ) ) ), 2 ) : 0;

	$out = array(
		'zuid'            => preg_replace( '/[^A-Za-z0-9_.:-]/', '', dos_agents_str( $g( 'zuid' ), 64 ) ),
		'name'            => $name,
		'profile_url'     => $url,
		'brokerage'       => dos_agents_str( $g( 'brokerage' ), 120 ),
		'is_team'         => dos_agents_bool( $g( 'is_team' ) ),
		'is_top_agent'    => dos_agents_bool( $g( 'is_top_agent' ) ),
		'rating'          => $rating,
		'rating_count'    => dos_agents_int( $g( 'rating_count' ), 1000000 ),
		'sales_in_city'   => dos_agents_int( $g( 'sales_in_city' ), 1000000 ),
		'sales_12mo'      => dos_agents_int( $g( 'sales_12mo' ), 1000000 ),
		'price_range'     => dos_agents_str( $g( 'price_range' ), 40 ),
		'avg_price'       => dos_agents_str( $g( 'avg_price' ), 40 ),
		'years'           => dos_agents_int( $g( 'years' ), 100 ),
		'specialties'     => dos_agents_str_list( $g( 'specialties' ), 12, 40 ),
		'tier'            => $tier,
		'office'          => $office,
		'areas'           => dos_agents_str_list( $g( 'areas' ), 60, 80 ),
		'area_evidence'   => dos_agents_str( $g( 'area_evidence' ), 500 ),
		'zip_sales'       => $zip_sales,
		'summary'         => dos_agents_str( $g( 'summary' ), 1500 ),
		'tags'            => $tags,
		'reviews_analyzed' => dos_agents_int( $g( 'reviews_analyzed' ), 1000 ),
		'last_review'     => dos_agents_date( $g( 'last_review' ) ),
		'tagged_at'       => dos_agents_str( $g( 'tagged_at' ), 40 ),
	);
	// Present only when the payload had a usable one, so an agent without it stores exactly what it did before.
	$parts = dos_agents_sanitize_summary_parts( $g( 'summary_parts' ) );
	if ( $parts ) {
		$at  = array_search( 'summary', array_keys( $out ), true ) + 1;
		$out = array_slice( $out, 0, $at, true ) + array( 'summary_parts' => $parts ) + array_slice( $out, $at, null, true );
	}
	// Same: present only when the payload gave a usable window, so old payloads store the same keys as before.
	$window = dos_agents_window_years( $g( 'reviews_window_years' ) );
	if ( $window ) {
		$at  = array_search( 'reviews_analyzed', array_keys( $out ), true ) + 1;
		$out = array_slice( $out, 0, $at, true ) + array( 'reviews_window_years' => $window ) + array_slice( $out, $at, null, true );
	}
	return $out;
}

/**
 * The optional disclosure that travels with a city: {title, paragraphs}.
 * Strings only, tags stripped, whitespace inside a paragraph collapsed, empty paragraphs dropped.
 * Returns null when there is nothing to show (not an object, or no paragraph left), in which case
 * the page falls back to the breanm_agents_disclosure option and then the built-in default.
 */
function dos_agents_sanitize_disclosure( $v ) {
	if ( ! is_array( $v ) ) {
		return null;
	}
	$clean = function ( $s, $max ) {
		if ( ! is_string( $s ) ) {
			return '';
		}
		$s = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
	};
	$paragraphs = array();
	foreach ( isset( $v['paragraphs'] ) && is_array( $v['paragraphs'] ) ? $v['paragraphs'] : array() as $p ) {
		$p = $clean( $p, 900 );
		if ( '' !== $p ) {
			$paragraphs[] = $p;
		}
		if ( count( $paragraphs ) >= 6 ) {
			break;
		}
	}
	if ( ! $paragraphs ) {
		return null;
	}
	return array(
		'title'      => $clean( isset( $v['title'] ) ? $v['title'] : '', 80 ),
		'paragraphs' => $paragraphs,
	);
}

/**
 * The whole payload, in the stored shape.
 *
 * @param mixed $raw Decoded JSON.
 * @return array|WP_Error {city, state, generated_at, agents, skipped}. `skipped` counts agents
 *                        dropped as unusable or past the cap; it is reported, not stored.
 */
function dos_agents_sanitize_payload( $raw ) {
	if ( ! is_array( $raw ) ) {
		return new WP_Error( 'breanm_agents_bad_payload', 'The payload must be a JSON object.', array( 'status' => 400 ) );
	}
	$city  = dos_agents_str( isset( $raw['city'] ) ? $raw['city'] : '', 80 );
	$state = dos_agents_state( isset( $raw['state'] ) ? $raw['state'] : '' );
	if ( '' === $city || '' === $state ) {
		return new WP_Error( 'breanm_agents_bad_city', 'The payload needs a city and a two-letter state.', array( 'status' => 400 ) );
	}
	if ( ! isset( $raw['agents'] ) || ! is_array( $raw['agents'] ) ) {
		return new WP_Error( 'breanm_agents_bad_agents', 'The payload needs an agents list.', array( 'status' => 400 ) );
	}

	$agents  = array();
	$skipped = 0;
	foreach ( $raw['agents'] as $a ) {
		if ( count( $agents ) >= DOS_AGENTS_MAX_AGENTS ) {
			++$skipped;
			continue;
		}
		$clean = dos_agents_sanitize_agent( $a );
		if ( null === $clean ) {
			++$skipped;
			continue;
		}
		$agents[] = $clean;
	}

	$out = array(
		'city'         => $city,
		'state'        => $state,
		'generated_at' => dos_agents_str( isset( $raw['generated_at'] ) ? $raw['generated_at'] : '', 40 ),
		'agents'       => $agents,
		'skipped'      => $skipped,
	);
	// Present only when the payload had a usable one, so a city without it stores exactly what it did before.
	$disclosure = dos_agents_sanitize_disclosure( isset( $raw['disclosure'] ) ? $raw['disclosure'] : null );
	if ( null !== $disclosure ) {
		$out['disclosure'] = $disclosure;
	}
	return $out;
}

/**
 * Stores a sanitised payload under its city's option, not autoloaded.
 *
 * @return array|WP_Error {slug, count, skipped}
 */
function dos_agents_store( $raw ) {
	$payload = dos_agents_sanitize_payload( $raw );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}
	$slug = dos_agents_city_slug( $payload['city'], $payload['state'] );
	if ( '' === $slug ) {
		return new WP_Error( 'breanm_agents_bad_city', 'The city could not be turned into a slug.', array( 'status' => 400 ) );
	}
	$skipped = $payload['skipped'];
	unset( $payload['skipped'] );

	update_option( dos_agents_option_name( $slug ), $payload, false );
	// The city only enters the index once its page is published and carries the shortcode, so this
	// is usually a no-op here and the save_post hook is what adds it a moment later.
	dos_agents_index_touch( $slug );

	return array(
		'slug'    => $slug,
		'count'   => count( $payload['agents'] ),
		'skipped' => $skipped,
	);
}

/**
 * The stored payload for a city slug, or null.
 *
 * The stored copy is run back through the sanitiser on every read: it is cheap, and it means a
 * hand-edited option, an older shape or a partial write can never reach a page or the REST output
 * with a field that is not in the schema, or with an agent that has no usable profile link.
 */
function dos_agents_get( $slug ) {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return null;
	}
	$p = get_option( dos_agents_option_name( $slug ), null );
	if ( ! is_array( $p ) || ! isset( $p['agents'] ) || ! is_array( $p['agents'] ) ) {
		return null;
	}
	$clean = dos_agents_sanitize_payload( $p );
	if ( is_wp_error( $clean ) ) {
		return null;
	}
	unset( $clean['skipped'] );
	return $clean;
}

/** Every key of a stored agent, with an empty value of the right type. */
function dos_agents_agent_defaults() {
	return array(
		'zuid'             => '',
		'name'             => '',
		'profile_url'      => '',
		'brokerage'        => '',
		'is_team'          => false,
		'is_top_agent'     => false,
		'rating'           => 0,
		'rating_count'     => 0,
		'sales_in_city'    => 0,
		'sales_12mo'       => 0,
		'price_range'      => '',
		'avg_price'        => '',
		'years'            => 0,
		'specialties'      => array(),
		'tier'             => 'none',
		'office'           => array(
			'city'  => '',
			'state' => '',
			'zip'   => '',
		),
		'areas'            => array(),
		'area_evidence'    => '',
		'zip_sales'        => array(),
		'summary'          => '',
		'summary_parts'    => array(),
		'tags'             => array(),
		'reviews_analyzed' => 0,
		'reviews_window_years' => 0,
		'last_review'      => '',
		'tagged_at'        => '',
	);
}

/**
 * An agent for display: every key present and of the right type, so a missing field is an empty one
 * rather than a PHP warning. Returns null when there is no name or no https Zillow profile link, since
 * a card without one would have an empty href.
 */
function dos_agents_normalise_agent( $a ) {
	if ( ! is_array( $a ) ) {
		return null;
	}
	$d = dos_agents_agent_defaults();
	$n = array_merge( $d, array_intersect_key( $a, $d ) );

	$office      = is_array( $n['office'] ) ? $n['office'] : array();
	$n['office'] = array_merge( $d['office'], array_intersect_key( $office, $d['office'] ) );
	foreach ( array( 'specialties', 'areas', 'zip_sales', 'tags' ) as $k ) {
		$n[ $k ] = is_array( $n[ $k ] ) ? $n[ $k ] : array();
	}
	$n['tags'] = array_values(
		array_filter(
			$n['tags'],
			function ( $t ) {
				return is_array( $t ) && isset( $t['slug'], $t['label'], $t['count'] ) && '' !== $t['slug'];
			}
		)
	);
	$n['areas'] = array_values( array_filter( $n['areas'], 'is_string' ) );
	// Same rules as on the way in, so a hand-edited option or a payload handed straight to the renderer is safe too.
	$n['summary_parts'] = dos_agents_sanitize_summary_parts( $n['summary_parts'] );
	foreach ( array( 'zuid', 'name', 'profile_url', 'brokerage', 'price_range', 'avg_price', 'area_evidence', 'summary', 'last_review', 'tagged_at', 'tier' ) as $k ) {
		$n[ $k ] = is_scalar( $n[ $k ] ) ? (string) $n[ $k ] : '';
	}
	foreach ( array( 'rating_count', 'sales_in_city', 'sales_12mo', 'years', 'reviews_analyzed' ) as $k ) {
		$n[ $k ] = is_numeric( $n[ $k ] ) ? (int) $n[ $k ] : 0;
	}
	// Same rules as on the way in; 0 means "no window".
	$n['reviews_window_years'] = dos_agents_window_years( $n['reviews_window_years'] );
	$n['rating']  = is_numeric( $n['rating'] ) ? (float) $n['rating'] : 0;
	$n['is_team'] = (bool) $n['is_team'];
	if ( ! in_array( $n['tier'], dos_agents_tiers(), true ) ) {
		$n['tier'] = 'none';
	}
	$n['profile_url'] = dos_agents_profile_url( $n['profile_url'] );
	if ( '' === $n['name'] || '' === $n['profile_url'] ) {
		return null;
	}
	return $n;
}
