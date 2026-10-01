<?php
/**
 * The city index: building it from the site's pages, caching it, keeping it fresh, and serving it
 * over REST. Every entry is array( 'n' => label, 'u' => absolute URL, 's' => page slug ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two-letter codes a city page slug can end in (50 states + DC + PR).
 */
function dos_city_search_states() {
	return array(
		'al', 'ak', 'az', 'ar', 'ca', 'co', 'ct', 'de', 'dc', 'fl', 'ga', 'hi', 'id', 'il', 'in', 'ia', 'ks',
		'ky', 'la', 'me', 'md', 'ma', 'mi', 'mn', 'ms', 'mo', 'mt', 'ne', 'nv', 'nh', 'nj', 'nm', 'ny', 'nc',
		'nd', 'oh', 'ok', 'or', 'pa', 'ri', 'sc', 'sd', 'tn', 'tx', 'ut', 'vt', 'va', 'wa', 'wv', 'wi', 'wy', 'pr',
	);
}

/**
 * Which way the city list is built: 'slug_state' (top-level pages whose URL ends in a state code)
 * or 'taxonomy' (pages tagged with a City term). 'auto' picks the taxonomy when the site has it.
 */
function dos_city_search_resolve_source() {
	$o = dos_city_search_settings();
	if ( in_array( $o['source'], array( 'slug_state', 'taxonomy' ), true ) ) {
		return $o['source'];
	}
	return taxonomy_exists( $o['taxonomy'] ) ? 'taxonomy' : 'slug_state';
}

/**
 * "McAllen TX – What's My Home Worth" -> "McAllen", given the state code "TX". Null when the title
 * doesn't start "City ST".
 */
function dos_city_search_city_from_title( $title, $code ) {
	$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' );
	$parts = preg_split( '/\s+[–—-]\s+/u', $title, 2 );
	$name  = trim( $parts[0] );

	if ( preg_match( '/^(.+?)[\s,]+' . $code . '$/u', $name, $m ) ) {
		return trim( $m[1] );
	}
	return null;
}

/**
 * Published top-level pages whose slug ends in a state code. Labels come from the page title; a
 * title that doesn't parse falls back to a label built from the slug and is counted in $fallbacks.
 */
function dos_city_search_collect_slug_state( &$fallbacks ) {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT post_title, post_name FROM {$wpdb->posts}
		 WHERE post_type = 'page' AND post_status = 'publish' AND post_parent = 0
		 AND post_name REGEXP '^[a-z0-9-]+-[a-z]{2}$'"
	);

	$states = array_flip( dos_city_search_states() );
	$out    = array();

	foreach ( (array) $rows as $row ) {
		$slug = $row->post_name;
		$st   = substr( $slug, -2 );
		if ( ! isset( $states[ $st ] ) ) {
			continue;
		}

		$code = strtoupper( $st );
		$city = dos_city_search_city_from_title( $row->post_title, $code );
		if ( null === $city ) {
			$city        = ucwords( str_replace( '-', ' ', substr( $slug, 0, -3 ) ) );
			$fallbacks[] = $slug;
		}

		$out[] = array(
			'n' => $city . ', ' . $code,
			// What the page's address is on a site with pretty permalinks, without a get_permalink() per row.
			'u' => home_url( '/' . $slug . '/' ),
			's' => $slug,
		);
	}

	return $out;
}

/**
 * One entry per City term that has a published page tagged with it. Pages tagged with more than
 * one city are skipped (they're hubs or guides, not a city's page); if two pages share a city, the
 * oldest wins. Both cases are listed in $skipped for the dashboard.
 */
function dos_city_search_collect_taxonomy( &$skipped ) {
	$tax = dos_city_search_settings()['taxonomy'];
	$out = array();

	if ( ! taxonomy_exists( $tax ) ) {
		return $out;
	}

	$ids = get_posts(
		array(
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => $tax,
					'operator' => 'EXISTS',
				),
			),
		)
	);

	$seen = array();
	foreach ( $ids as $id ) {
		$terms = wp_get_object_terms( $id, $tax );
		if ( is_wp_error( $terms ) || 1 !== count( $terms ) ) {
			$skipped[] = array( 'id' => $id, 'why' => 'tagged with ' . ( is_wp_error( $terms ) ? 0 : count( $terms ) ) . ' cities' );
			continue;
		}
		$term = $terms[0];
		if ( isset( $seen[ $term->term_id ] ) ) {
			$skipped[] = array( 'id' => $id, 'why' => 'another page already covers ' . $term->name );
			continue;
		}
		$seen[ $term->term_id ] = true;

		$out[] = array(
			'n' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'u' => esc_url_raw( get_permalink( $id ) ),
			's' => (string) get_post_field( 'post_name', $id ),
		);
	}

	return $out;
}

/**
 * Builds the index for the resolved source and records, for the Tools dashboard, when it was built
 * and what was left out (`fallbacks` for the slug source, `skipped` for the taxonomy source).
 */
function dos_city_search_build_index() {
	$source    = dos_city_search_resolve_source();
	$fallbacks = array();
	$skipped   = array();

	$out = 'taxonomy' === $source ? dos_city_search_collect_taxonomy( $skipped ) : dos_city_search_collect_slug_state( $fallbacks );

	usort(
		$out,
		function ( $a, $b ) {
			$by_name = strnatcasecmp( $a['n'], $b['n'] );
			return 0 !== $by_name ? $by_name : strcmp( $a['s'], $b['s'] );
		}
	);

	update_option(
		DOS_CITY_SEARCH_META,
		array(
			'built'      => time(),
			// So the homepage can decide inline-or-fetch without loading the whole list.
			'count'      => count( $out ),
			'source'     => $source,
			'fallbacks'  => array_slice( $fallbacks, 0, 200 ),
			'fallback_n' => count( $fallbacks ),
			'skipped'    => $skipped,
		),
		false
	);

	return $out;
}

function dos_city_search_get_index() {
	$index = get_transient( DOS_CITY_SEARCH_TRANSIENT );
	if ( ! is_array( $index ) ) {
		$index = dos_city_search_build_index();
		// An empty list is usually a taxonomy that has not registered yet, or a build in the middle of a
		// bulk change. Do not let it stand for 12 hours.
		set_transient( DOS_CITY_SEARCH_TRANSIENT, $index, $index ? 12 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	}
	return $index;
}

function dos_city_search_flush() {
	delete_transient( DOS_CITY_SEARCH_TRANSIENT );
}

// Rebuild when a page is published, unpublished, or saved while published (a rename waits for
// nothing). Posts of other types never reach the index.
function dos_city_search_on_transition( $new_status, $old_status, $post ) {
	if ( 'page' === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
		dos_city_search_flush();
	}
}

// Rebuild when a page's city tag changes. Only matters when the list is built from the taxonomy.
function dos_city_search_on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( dos_city_search_settings()['taxonomy'] === $taxonomy && 'taxonomy' === dos_city_search_resolve_source() && 'page' === get_post_type( $object_id ) ) {
		dos_city_search_flush();
	}
}

// Rebuild when a city is renamed or deleted, on the same condition.
function dos_city_search_on_term_change() {
	if ( 'taxonomy' === dos_city_search_resolve_source() ) {
		dos_city_search_flush();
	}
}

function dos_city_search_register_index_hooks() {
	add_action( 'transition_post_status', 'dos_city_search_on_transition', 10, 3 );
	add_action( 'set_object_terms', 'dos_city_search_on_terms', 10, 4 );

	$tax = dos_city_search_settings()['taxonomy'];
	add_action( 'edited_' . $tax, 'dos_city_search_on_term_change' );
	add_action( 'delete_' . $tax, 'dos_city_search_on_term_change' );

	// Changing the source or taxonomy changes what the list holds; don't wait 12 hours to show it.
	add_action( 'update_option_' . DOS_CITY_SEARCH_OPTION, 'dos_city_search_flush' );
	add_action( 'add_option_' . DOS_CITY_SEARCH_OPTION, 'dos_city_search_flush' );
}

function dos_city_search_register_rest() {
	add_action( 'rest_api_init', 'dos_city_search_register_routes' );
}

function dos_city_search_register_routes() {
	register_rest_route(
		'dos-city-search/v1',
		'/cities',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'dos_city_search_rest_cities',
		)
	);

	// Pages cached before 2.0.0 still carry the old script, which reads "label|slug" strings from here.
	register_rest_route(
		'revnm/v1',
		'/cities',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'dos_city_search_rest_cities_legacy',
		)
	);
}

function dos_city_search_rest_cities() {
	$res = new WP_REST_Response( dos_city_search_get_index() );
	$res->header( 'Cache-Control', 'public, max-age=3600' );
	return $res;
}

function dos_city_search_rest_cities_legacy() {
	$rows = array();
	foreach ( dos_city_search_get_index() as $c ) {
		$rows[] = $c['n'] . '|' . $c['s'];
	}
	$res = new WP_REST_Response( $rows );
	$res->header( 'Cache-Control', 'public, max-age=3600' );
	return $res;
}
