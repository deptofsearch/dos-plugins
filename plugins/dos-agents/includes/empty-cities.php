<?php
/**
 * City pages with no agents to show: noindex, follow, and out of the sitemap.
 *
 * 876 city pages (November 2026) hold only "Real Estate Agents in {City}, {ST}" and the sentence "There are no
 * agents we have found that service this area", with no cards and no brokerage summary. Google reports an empty
 * page that returns 200 as a soft 404, and a few hundred of them dilute every crawl.
 *
 * "Empty" is read from the page's stored content, never from a list: a page is empty while it holds that sentence
 * and no [breanm_agents] shortcode. When the sweep gives the city cards it rewrites the page with the shortcode,
 * and the page is indexable again at its next view and back in the sitemap once the save is seen. The page's own
 * text is not changed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_AGENTS_EMPTY_SENTENCE', 'There are no agents we have found that service this area' );
define( 'DOS_AGENTS_EMPTY_TRANSIENT', 'breanm_agents_empty_ids_v1' );

/** True when this stored content is an empty city page. */
function dos_agents_is_empty_city_content( $content ) {
	$content = (string) $content;
	return false !== strpos( $content, DOS_AGENTS_EMPTY_SENTENCE ) && ! has_shortcode( $content, 'breanm_agents' );
}

/** True for a published Page whose content is an empty city page. */
function dos_agents_is_empty_city_page( $post ) {
	return $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status
		&& dos_agents_is_empty_city_content( $post->post_content );
}

/** wp_robots: an empty city page is noindex, follow. Decided live from the page, so a stale cache cannot hide one. */
function dos_agents_empty_robots( $robots ) {
	if ( ! is_page() ) {
		return $robots;
	}
	$post = get_queried_object();
	if ( dos_agents_is_empty_city_page( $post ) ) {
		unset( $robots['index'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
}

/**
 * IDs of the empty city pages, for the sitemap only. One LIKE scan over published pages, cached for 12 hours and
 * cleared when a saved or deleted page crosses between empty and not (dos_agents_empty_on_save()).
 *
 * @return int[]
 */
function dos_agents_empty_city_ids() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	$pre = apply_filters( 'dos_agents_pre_empty_city_ids', null );
	if ( is_array( $pre ) ) {
		return $memo = array_map( 'intval', $pre );
	}
	$hit = get_transient( DOS_AGENTS_EMPTY_TRANSIENT );
	if ( is_array( $hit ) ) {
		return $memo = array_map( 'intval', $hit );
	}
	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s AND post_content NOT LIKE %s",
			'%' . $wpdb->esc_like( DOS_AGENTS_EMPTY_SENTENCE ) . '%',
			'%' . $wpdb->esc_like( '[breanm_agents' ) . '%'
		)
	);
	if ( '' !== (string) $wpdb->last_error ) {
		return $memo = array(); // Leave every page in rather than cache a failed scan.
	}
	$ids = array_map( 'intval', (array) $ids );
	set_transient( DOS_AGENTS_EMPTY_TRANSIENT, $ids, 12 * HOUR_IN_SECONDS );
	return $memo = $ids;
}

/** wp_sitemaps_posts_query_args: leave the empty city pages out of the Pages sitemap (and its page count). */
function dos_agents_empty_sitemap_args( $args, $post_type ) {
	if ( 'page' !== $post_type ) {
		return $args;
	}
	$ids = dos_agents_empty_city_ids();
	if ( $ids ) {
		$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $ids );
	}
	return $args;
}

/**
 * save_post: clear the cached set only when this page crossed between empty and not, so the hourly sweep's
 * ordinary page edits do not force a rescan.
 */
function dos_agents_empty_on_save( $post_id, $post ) {
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	dos_agents_empty_sync( $post_id, dos_agents_is_empty_city_page( $post ) );
}

/** deleted_post: a deleted page is never empty-and-published, so drop it if the set holds it. */
function dos_agents_empty_on_delete( $post_id, $post = null ) {
	dos_agents_empty_sync( $post_id, false );
}

/** Clears the cached set when it disagrees with $now about this page. */
function dos_agents_empty_sync( $post_id, $now ) {
	$hit = get_transient( DOS_AGENTS_EMPTY_TRANSIENT );
	if ( ! is_array( $hit ) ) {
		return; // Nothing cached to be wrong.
	}
	if ( in_array( (int) $post_id, array_map( 'intval', $hit ), true ) !== (bool) $now ) {
		delete_transient( DOS_AGENTS_EMPTY_TRANSIENT );
	}
}
