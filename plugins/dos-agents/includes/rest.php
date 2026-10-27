<?php
/**
 * REST routes, namespace breanm/v1.
 *
 *   POST /agents/upsert   manage_options (an application password works). Stores one city's agents.
 *   GET  /agents/<slug>   manage_options too, read-only: lets the pushing workflow verify what it stored.
 *                         Not public: the page already shows what visitors may see.
 *   GET  /cities          public. The cities that have agent cards, for the homepage typeahead when
 *                         there are too many to ride along in the page markup. City name, state and
 *                         URL only: the same three things the page itself links to.
 *   POST /cities/rebuild  manage_options. Rebuilds that index from every stored city. Needed once
 *                         after this version is installed, because the index starts empty, and any
 *                         time a page was changed outside WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dos_agents_register_routes() {
	register_rest_route(
		'breanm/v1',
		'/agents/upsert',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'dos_agents_rest_can_manage',
			'callback'            => 'dos_agents_rest_upsert',
		)
	);

	register_rest_route(
		'breanm/v1',
		'/cities',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'dos_agents_rest_cities',
		)
	);

	register_rest_route(
		'breanm/v1',
		'/cities/rebuild',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'dos_agents_rest_can_manage',
			'callback'            => 'dos_agents_rest_rebuild',
		)
	);

	register_rest_route(
		'breanm/v1',
		'/agents/(?P<slug>[a-z0-9-]+)',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'dos_agents_rest_can_manage',
			'callback'            => 'dos_agents_rest_get',
			'args'                => array(
				'slug' => array(
					'sanitize_callback' => 'sanitize_title',
				),
			),
		)
	);
}

function dos_agents_rest_can_manage() {
	return current_user_can( 'manage_options' );
}

function dos_agents_rest_upsert( $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = $request->get_params();
	}
	$res = dos_agents_store( $body );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return rest_ensure_response(
		array(
			'ok'      => true,
			'slug'    => $res['slug'],
			'count'   => $res['count'],
			'skipped' => $res['skipped'],
		)
	);
}

function dos_agents_rest_get( $request ) {
	// dos_agents_get() runs the stored copy back through the sanitiser, so only the schema leaves.
	$stored = dos_agents_get( (string) $request['slug'] );
	if ( null === $stored ) {
		return new WP_Error( 'breanm_agents_not_found', 'No agents are stored for that city.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( $stored );
}

/**
 * The public city index. Callers come with ?v=<index version>, which changes whenever the index
 * does, so the answer can be cached hard: a stale copy would offer cities whose cards have gone.
 */
function dos_agents_rest_cities() {
	$response = rest_ensure_response( dos_agents_index_public() );
	$response->header( 'Cache-Control', 'public, max-age=3600' );
	return $response;
}

/** Rebuilds the city index and reports how many cities came out of it. */
function dos_agents_rest_rebuild() {
	$count = dos_agents_index_rebuild();
	return rest_ensure_response(
		array(
			'ok'      => true,
			'cities'  => $count,
			'version' => dos_agents_index_ver(),
		)
	);
}
