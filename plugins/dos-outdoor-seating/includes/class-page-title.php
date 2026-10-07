<?php
/**
 * Adds a modest H1 to city landing pages ("Outdoor Seating in City, ST"), state pages
 * ("Outdoor Seating in Arizona") and the front page ("Outdoor Seating Near Me").
 *
 * Themify Landing hides page titles on this site, so city pages had no H1 at all.
 * A city page is any page whose content holds a [table ... filter="City, ST"] shortcode,
 * whether or not the card takeover is on for that city.
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
		if ( Landmarks::active_for_page( $post ) ) { // city_for() already returned the whole landmark H1.
			return '<h1 class="osn-page-title">' . esc_html( apply_filters( 'osn_landmark_page_h1_text', $city, $post ) ) . "</h1>\n" . $content;
		}
		/* translators: %s: "City, ST" */
		$text = apply_filters( 'osn_city_page_h1_text', sprintf( __( 'Outdoor Seating in %s', 'dos-outdoor-seating' ), $city ), $city );
		return '<h1 class="osn-page-title">' . esc_html( $text ) . "</h1>\n" . $content;
	}
}
