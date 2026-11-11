<?php
/**
 * 301s from the old __trashed-NN URLs to the city pages they became.
 *
 * 343 city pages (AL, MA, NH, RI) were published under slugs like __trashed-75 after a bulk trash and
 * restore in September 2026, and were renamed to in-<city>-<st> in 0.3.3. Core keeps old-slug redirects
 * for posts only (wp_check_for_changed_slugs skips hierarchical types), so Pages need their own.
 *
 * It acts only on a 404, so it does nothing while a page still holds its old slug, and the release can
 * go live before the renames. The target comes from the page ID, so a later slug change still lands.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Old slug => page ID. Read once per request, and only on a 404 that looks like an old slug. */
function dos_agents_old_slug_map() {
	static $map = null;
	if ( null === $map ) {
		$map = (array) require __DIR__ . '/old-slugs-map.php';
	}
	return $map;
}

/** The single path segment of the request, or '' when the path has more than one. */
function dos_agents_old_slug_from_request( $uri ) {
	$path = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );
	return ( '' === $path || false !== strpos( $path, '/' ) ) ? '' : strtolower( $path );
}

function dos_agents_old_slug_redirect() {
	if ( ! is_404() ) {
		return;
	}
	$slug = dos_agents_old_slug_from_request( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a fixed list only.
	if ( 0 !== strpos( $slug, '__trashed' ) ) {
		return;
	}
	$map = dos_agents_old_slug_map();
	if ( ! isset( $map[ $slug ] ) ) {
		return;
	}
	$post = get_post( (int) $map[ $slug ] );
	// A page sent back to the trash, or one still holding the old slug, would only loop.
	if ( ! $post || 'publish' !== $post->post_status || $slug === $post->post_name ) {
		return;
	}
	wp_safe_redirect( get_permalink( $post ), 301 );
	exit;
}
