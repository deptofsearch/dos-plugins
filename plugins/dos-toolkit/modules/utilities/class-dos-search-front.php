<?php
/**
 * Keep the home page out of search results.
 *
 * A site with a static front page often puts a summary of everything on it:
 * topic links, featured briefs, a chart. Search then matches that page for
 * almost any word, and themes that show the first image in the content make
 * its card look like an article that "links to the homepage". This drops the
 * page set as the front page (and the posts page, which is only a listing)
 * from front-end searches. Nothing changes in admin, feeds or the REST API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Search_Front {

	public static function is_enabled() {
		return (bool) DOS_Settings::get( 'utilities_search_hide_front', 0 );
	}

	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'pre_get_posts', array( __CLASS__, 'exclude' ) );
	}

	/**
	 * The page IDs to leave out: the static front page and posts page, when set.
	 */
	public static function excluded_ids() {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return array();
		}

		$ids = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );

		return array_values( array_unique( $ids ) );
	}

	public static function exclude( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$ids = self::excluded_ids();

		if ( ! $ids ) {
			return;
		}

		// An unset query var comes back as '', and (array) '' is array( '' ); keep only real IDs.
		$current = array_filter( array_map( 'intval', (array) $query->get( 'post__not_in' ) ) );
		$query->set( 'post__not_in', array_values( array_unique( array_merge( $current, $ids ) ) ) );
	}
}
