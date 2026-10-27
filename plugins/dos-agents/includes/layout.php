<?php
/**
 * Page layout for city pages on Themify Magazine.
 *
 * City pages are ordinary Pages, and the site's Pages are sidebar-none — but the city pages carry
 * `sidebar1`, so each one prints a "Recent Posts" column beside a list of agents. There is no
 * relation between the two, and on a phone the sidebar lands under the cards as a tail of blog links.
 *
 * Themify reads its per-post settings from meta keys. Rather than write those keys to 28,000 pages,
 * they are answered through get_post_metadata, so nothing is stored and a city page that stops
 * holding the shortcode goes back to the theme's own behaviour on the next load. The body class and
 * the CSS in breanm-brand.css are the fallback if a theme update reads them another way.
 *
 * Filter `dos_agents_page_layout` (default true) turns it off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Themify per-post settings answered for a city page. */
function dos_agents_themify_meta_map() {
	return array(
		'layout'          => 'sidebar-none',
		'content_width'   => 'default_width',
		'hide_post_date'  => 'yes',
		'hide_post_meta'  => 'yes',
		'hide_meta_all'   => 'yes',
		'post_navigation' => 'no',
		'hide_post_nav'   => 'yes',
	);
}

function dos_agents_layout_enabled() {
	return (bool) apply_filters( 'dos_agents_page_layout', true );
}

/**
 * A Page whose content holds [breanm_agents]. Memoised: get_post_metadata runs for every meta read
 * on the page, and this would otherwise re-scan the content each time.
 */
function dos_agents_is_city_page( $post_id ) {
	static $memo = array();
	// has_shortcode() answers false for a shortcode that is not registered yet, and init is what
	// registers ours. Answering before then would pin a city page as "not one" for the whole request.
	if ( ! did_action( 'init' ) ) {
		return false;
	}
	$post_id = (int) $post_id;
	if ( ! isset( $memo[ $post_id ] ) ) {
		$post             = get_post( $post_id );
		$memo[ $post_id ] = $post && 'page' === $post->post_type
			&& has_shortcode( (string) $post->post_content, 'breanm_agents' );
	}
	return $memo[ $post_id ];
}

/**
 * The pages this module takes over: a city page, or a state hub. Both are ours to lay out, and both
 * carried Themify's `sidebar1` — a hub printed a "Recent Posts" column beside a table of 470 cities.
 */
function dos_agents_layout_applies( $post_id ) {
	return dos_agents_is_city_page( $post_id ) || '' !== dos_agents_hub_state( $post_id );
}

function dos_agents_layout_meta( $value, $object_id, $meta_key, $single ) {
	if ( null !== $value || '' === $meta_key ) {
		return $value;
	}
	$map = dos_agents_themify_meta_map();
	if ( ! isset( $map[ $meta_key ] ) ) {
		return $value;
	}
	// wp-admin keeps the real (empty) values, so editing a page still shows what is actually set.
	if ( is_admin() || ! dos_agents_layout_enabled() || ! dos_agents_layout_applies( $object_id ) ) {
		return $value;
	}
	return $single ? $map[ $meta_key ] : array( $map[ $meta_key ] );
}

function dos_agents_layout_body_class( $classes ) {
	$post_id = (int) get_queried_object_id();
	if ( ! dos_agents_layout_enabled() || ! is_page() || ! dos_agents_layout_applies( $post_id ) ) {
		return $classes;
	}
	$classes   = array_diff( $classes, array( 'sidebar1', 'sidebar2', 'sidebar-left', 'sidebar-right' ) );
	$classes[] = 'sidebar-none';
	$classes[] = 'default_width';
	$classes[] = 'breanm-agents-page';
	// Hubs get their own class too: their headings carry inline fonts the stylesheet has to beat.
	if ( '' !== dos_agents_hub_state( $post_id ) ) {
		$classes[] = 'breanm-hub-page';
	}
	return array_values( array_unique( $classes ) );
}
