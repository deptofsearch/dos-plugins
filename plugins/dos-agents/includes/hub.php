<?php
/**
 * State hub pages (/in-washington/, /in-alabama/, ...): the "cities with agent cards" block, the
 * heading, and the page layout.
 *
 * There are 49 of these and each one holds a table of every city in the state — Washington's is
 * 159,000 characters. So none of this is written into them. The block is added through `the_content`
 * and the layout through `get_post_metadata`, which means the pages themselves are never rewritten,
 * a new state hub is covered the day it is created, and turning the filters off puts every page
 * back exactly as it was.
 *
 * A hub is recognised by its slug: "in-" plus the state's name, which is how all 49 are named.
 * City pages are "in-<city>-<st>" and end in a two-letter code, so the two never collide.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The postal code a hub page is for ("in-washington" -> "WA"), or '' when the page is not a hub.
 * Memoised: the content and metadata filters both ask, repeatedly, for the same post.
 */
function dos_agents_hub_state( $post_id ) {
	static $memo = array();
	$post_id = (int) $post_id;
	if ( isset( $memo[ $post_id ] ) ) {
		return $memo[ $post_id ];
	}
	$memo[ $post_id ] = '';
	$post             = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || 0 !== strpos( (string) $post->post_name, 'in-' ) ) {
		return '';
	}
	$name = substr( (string) $post->post_name, 3 );
	foreach ( dos_agents_state_names() as $code => $state ) {
		if ( sanitize_title( $state ) === $name ) {
			$memo[ $post_id ] = $code;
			break;
		}
	}
	return $memo[ $post_id ];
}

function dos_agents_hub_enabled() {
	return (bool) apply_filters( 'dos_agents_hub_block', true );
}

/**
 * the_content: puts the heading and the cities-with-cards block above whatever the page already has.
 *
 * Only on the hub page's own view, and only once: the_content runs again for excerpts, feeds and
 * anything that renders the page a second time, and the block would otherwise appear twice.
 */
function dos_agents_hub_the_content( $content ) {
	if ( ! dos_agents_hub_enabled() || ! is_page() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$post_id = get_the_ID();
	$state   = dos_agents_hub_state( $post_id );
	if ( '' === $state ) {
		return $content;
	}
	static $done = array();
	if ( isset( $done[ $post_id ] ) ) {
		return $content;
	}
	$done[ $post_id ] = true;
	// Normally already enqueued in <head> by dos_agents_maybe_enqueue_home(); this covers the case
	// where the content filter runs and that hook did not, at the cost of the asset landing late.
	dos_agents_home_enqueue();

	$heading = sprintf(
		/* translators: %s: state name */
		__( 'Real Estate Agents in %s', 'dos-agents' ),
		dos_agents_state_name( $state )
	);

	return '<h1 class="breanm-hub-title">' . esc_html( $heading ) . '</h1>'
		. dos_agents_render_hub( $state )
		. $content;
}
