<?php
/**
 * Keep a URL working after its slug changes.
 *
 * Renaming a published page is the most common way a site breaks its own
 * links, and the person doing it is usually improving something rather than
 * moving it. The old URL is already in search results, in other people's
 * links and in the site's own menus.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Redirects_Slug {

	public static function is_enabled() {
		$value = DOS_Settings::get( 'redirects_auto_slug', null );

		return null === $value ? true : (bool) $value;
	}

	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'post_updated', array( __CLASS__, 'on_update' ), 10, 3 );
	}

	public static function on_update( $post_id, $post_after, $post_before ) {
		if ( ! $post_after || ! $post_before ) {
			return;
		}

		// Only for something that was already published under the old URL.
		// A draft's slug has never been anywhere.
		if ( 'publish' !== $post_before->post_status || 'publish' !== $post_after->post_status ) {
			return;
		}

		if ( $post_before->post_name === $post_after->post_name ) {
			return;
		}

		if ( 'attachment' === $post_after->post_type || ! is_post_type_viewable( $post_after->post_type ) ) {
			return;
		}

		$old = get_permalink( $post_before );
		$new = get_permalink( $post_after );

		if ( ! $old || ! $new || is_wp_error( $old ) || is_wp_error( $new ) ) {
			return;
		}

		$result = DOS_Redirects_Store::add( $old, $new, 301, 'slug-change' );

		if ( is_wp_error( $result ) ) {
			DOS_Log::add( 'redirects', 'slug_redirect_failed', sprintf( '%s: %s', $post_after->post_title, $result->get_error_message() ), $post_id );

			return;
		}

		DOS_Log::add(
			'redirects',
			'slug_redirect_created',
			sprintf( '%s now redirects to %s', DOS_Redirects_Store::normalise( $old ), DOS_Redirects_Store::normalise( $new ) ),
			$post_id
		);
	}
}
