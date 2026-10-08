<?php
/**
 * Page-style layout for city and lender pages on Themify Magazine. Without this they render like blog
 * posts: a sidebar column plus date, author, category and post navigation. Pages on this site are
 * sidebar-none, default_width, and these should match.
 *
 * Themify picks the single layout and the meta it prints from per-post meta keys (`layout`,
 * `hide_post_date`, `hide_post_meta`, ...). Rather than write those keys to every post, the plugin answers
 * them through get_post_metadata, so existing drafts and future upserts are covered with no data change.
 * The body class and the CSS in blnm-brand.css are the fallback if a theme update reads them another way.
 * Filter `blnm_page_layout` (default true) turns all of it off.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Layout {

	/** Themify per-post settings answered for city and lender posts. */
	const THEMIFY_META = array(
		'layout'           => 'sidebar-none',
		'content_width'    => 'default_width',
		'hide_post_date'   => 'yes',
		'hide_post_meta'   => 'yes',
		'hide_meta_all'    => 'yes',
		'hide_post_image'  => 'yes',
		'hide_image'       => 'yes',
		'post_navigation'  => 'no',
		'hide_post_nav'    => 'yes',
	);

	public static function hooks() {
		add_filter( 'get_post_metadata', array( __CLASS__, 'themify_meta' ), 10, 4 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 99 );
	}

	private static function enabled() {
		return (bool) apply_filters( 'blnm_page_layout', true );
	}

	private static function is_ours( $post_id ) {
		return in_array( get_post_type( $post_id ), array( Data_Model::CITY, Data_Model::LENDER ), true );
	}

	/** A Page whose content holds [blnm_state_index state=...] (the hub, not the no-state list). Memoised: this filter runs for every meta read. */
	private static function is_hub_page( $post_id ) {
		static $memo = array();
		if ( ! isset( $memo[ $post_id ] ) ) {
			$post = get_post( $post_id );
			$memo[ $post_id ] = $post && 'page' === $post->post_type && (bool) preg_match( '/\[blnm_state_index\b[^\]]*\bstate=/', (string) $post->post_content );
		}
		return $memo[ $post_id ];
	}

	public static function themify_meta( $value, $object_id, $meta_key, $single ) {
		if ( null !== $value || '' === $meta_key || ! isset( self::THEMIFY_META[ $meta_key ] ) ) {
			return $value;
		}
		if ( is_admin() || ! self::enabled() ) {
			return $value;
		}
		// State hub pages: the hub prints its own hero figure, so Themify must not print the featured image too.
		if ( ( 'hide_post_image' === $meta_key || 'hide_image' === $meta_key ) && self::is_hub_page( $object_id ) ) {
			return $single ? 'yes' : array( 'yes' );
		}
		if ( ! self::is_ours( $object_id ) ) {
			return $value;
		}
		$v = self::THEMIFY_META[ $meta_key ];
		return $single ? $v : array( $v );
	}

	public static function body_class( $classes ) {
		// State hub pages: the class lets blnm.css hide Themify's own featured-image figure (the hub prints its hero).
		if ( self::enabled() && is_page() && self::is_hub_page( (int) get_queried_object_id() ) ) {
			$classes[] = 'blnm-hub-page';
		}
		if ( ! self::enabled() || ! is_singular( array( Data_Model::CITY, Data_Model::LENDER ) ) ) {
			return $classes;
		}
		$classes   = array_diff( $classes, array( 'sidebar1', 'sidebar2', 'sidebar-left', 'sidebar1 sidebar-left', 'full_width' ) );
		$classes[] = 'sidebar-none';
		$classes[] = 'default_width';
		$classes[] = 'blnm-page-layout';
		return array_values( array_unique( $classes ) );
	}
}
