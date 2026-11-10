<?php
/**
 * Adds a modest H1 to city landing pages ("Outdoor Seating in City, ST"), state pages
 * ("Outdoor Seating in Arizona") and the front page ("Outdoor Seating Near Me").
 *
 * Themify Landing hides page titles on this site, so city pages had no H1 at all.
 * A city page is any page whose content holds a [table ... filter="City, ST"] shortcode,
 * whether or not the card takeover is on for that city.
 *
 * Numbered pages (/city-st/page/2/): the H1, the document title and the meta description say which page it is, so
 * the paged URLs are not duplicates of page 1 (they canonicalise to themselves; the Toolkit already does that).
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Page_Title {

	/** H1 stays page-sized; the page's own intro H3s drop below it so the hierarchy reads right. */
	const CSS = '.osn-page-title{margin:0 0 .6em;font-size:clamp(1.5rem,1.3rem + .6vw,1.875rem);line-height:1.2;text-align:center}.osn-page-title~h3{font-size:clamp(1.15rem,1.05rem + .4vw,1.375rem);line-height:1.25}';

	public static function hooks() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
		// Priority 5: before wpautop and shortcodes, so the raw [table] shortcode is still visible.
		add_filter( 'the_content', array( __CLASS__, 'prepend' ), 5 );
		// Priority 20: after DoS Toolkit prints its title (10), so the page number goes into the finished title.
		add_filter( 'pre_get_document_title', array( __CLASS__, 'paged_title' ), 20 );
		add_filter( 'wp_title', array( __CLASS__, 'paged_title' ), 20 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'paged_description' ), 10, 4 );
	}

	/**
	 * The page number asked for on a numbered grid page, 0 everywhere else (page 1, other pages, anything that is
	 * not a takeover grid). Reads only the main query, never loop state.
	 */
	private static function grid_page_number( $post = null ) {
		if ( is_admin() || ! is_page() ) {
			return 0;
		}
		$post = $post ? $post : get_queried_object();
		$n    = Paging::requested( $post ); // Page 1 unless $post is the queried page.
		return ( $n > 1 && Paging::grid_pages( $post ) > 0 ) ? $n : 0;
	}

	/** "... – Page 2 | Site" on a numbered grid page, the Toolkit's title otherwise. Empty stays empty. */
	public static function paged_title( $title ) {
		if ( '' === trim( (string) $title ) ) {
			return $title;
		}
		$n = self::grid_page_number();
		return $n ? Paging::title( $title, $n ) : $title;
	}

	/**
	 * "Page 2 of 9. " in front of the stored meta description of the queried grid page, only on page 2 and later.
	 * Same kind of filter as Home::hide_builder_meta; the stored value itself is never changed.
	 */
	public static function paged_description( $check, $post_id, $key, $single ) {
		static $busy = false;
		if ( null !== $check || $busy || Seo::DESC_KEY !== $key || (int) $post_id !== (int) get_queried_object_id() ) {
			return $check;
		}
		$n = self::grid_page_number();
		if ( ! $n ) {
			return $check;
		}
		$busy = true; // Reading the real value goes through this filter again.
		$text = trim( (string) get_post_meta( $post_id, $key, true ) );
		$busy = false;
		if ( '' === $text ) {
			return $check;
		}
		/* translators: 1: page number, 2: number of pages */
		$text = sprintf( __( 'Page %1$d of %2$d. ', 'dos-outdoor-seating' ), $n, Paging::grid_pages( get_queried_object() ) ) . $text;
		return $single ? $text : array( $text );
	}

	/** The "City, ST" this page lists, or '' when it isn't a city page (or already has an H1). */
	public static function city_for( $post ) {
		if ( ! $post || 'page' !== $post->post_type || ! apply_filters( 'osn_city_page_h1', true, $post ) ) {
			return '';
		}
		$content = (string) $post->post_content;
		if ( false !== stripos( $content, '<h1' ) ) {
			return '';
		}
		$filter = self::filter_from_content( $content );
		if ( '' !== $filter ) {
			// A landmark landing page ("On the Strip, Las Vegas"), whatever city filter its shortcode carries.
			$spot = Landmarks::active_for_page( $post );
			$scty = $spot ? Landmarks::city_term( $spot ) : null;
			if ( $spot && $scty ) {
				return Landmarks::h1( $spot, $scty ); // Full H1 text; see prepend().
			}
		}
		if ( '' !== $filter && ! Util::find_city( $filter ) ) {
			// A neighborhood page: "Ballard, Seattle", whatever the legacy filter says.
			$hood = Hoods::match_page( $post, $filter );
			$city = $hood ? Hoods::city_term( $hood ) : null;
			if ( $hood && $city ) {
				return Hoods::page_hood_name( $filter, $hood ) . ', ' . Util::city_label( $city->name );
			}
		}
		return $filter;
	}

	/** The `filter="..."` value of the first [table ...] shortcode in the content, or ''. Shared with Seo. */
	public static function filter_from_content( $content ) {
		if ( ! preg_match( '/\[table\b[^\]]*\bfilter="([^"]+)"/i', (string) $content, $m ) ) {
			return '';
		}
		return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
	}

	/** H1 text for a state page or the front page, '' otherwise (or when the content already has an H1). */
	public static function other_for( $post ) {
		if ( ! $post || 'page' !== $post->post_type || false !== stripos( (string) $post->post_content, '<h1' ) ) {
			return '';
		}
		$map = States::pages();
		if ( isset( $map[ $post->post_name ] ) && '' !== (string) $map[ $post->post_name ] ) {
			/* translators: %s: state name */
			return sprintf( __( 'Outdoor Seating in %s', 'dos-outdoor-seating' ), $map[ $post->post_name ] );
		}
		if ( is_front_page() && ! Home::active() ) { // The homepage takeover prints its own H1.
			return (string) apply_filters( 'osn_front_page_h1_text', __( 'Outdoor Seating Near Me', 'dos-outdoor-seating' ) );
		}
		return '';
	}

	public static function styles() {
		if ( ! is_page() ) {
			return;
		}
		$post = get_queried_object();
		if ( '' === self::city_for( $post ) && '' === self::other_for( $post ) ) {
			return;
		}
		// Printed in <head> so the heading never flashes at the theme's display size.
		wp_register_style( 'dos-outdoor-seating-title', false, array(), VERSION );
		wp_enqueue_style( 'dos-outdoor-seating-title' );
		wp_add_inline_style( 'dos-outdoor-seating-title', self::CSS );
	}

	public static function prepend( $content ) {
		if ( ! is_page() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		$city = self::city_for( $post );
		if ( '' === $city ) {
			// State pages and the front page. Themify Builder can run the_content more than once, so print once per post.
			static $done = array();
			$text = self::other_for( $post );
			if ( '' === $text || isset( $done[ $post->ID ] ) ) {
				return $content;
			}
			$done[ $post->ID ] = true;
			return '<h1 class="osn-page-title">' . esc_html( $text ) . "</h1>\n" . $content;
		}
		$n  = self::grid_page_number( $post );
		$pg = $n ? Paging::label( $n ) : ''; // " – Page 2" on numbered pages.
		if ( Landmarks::active_for_page( $post ) ) { // city_for() already returned the whole landmark H1.
			return '<h1 class="osn-page-title">' . esc_html( apply_filters( 'osn_landmark_page_h1_text', $city, $post ) . $pg ) . "</h1>\n" . $content;
		}
		/* translators: %s: "City, ST" */
		$text = apply_filters( 'osn_city_page_h1_text', sprintf( __( 'Outdoor Seating in %s', 'dos-outdoor-seating' ), $city ), $city );
		return '<h1 class="osn-page-title">' . esc_html( $text . $pg ) . "</h1>\n" . $content;
	}
}
