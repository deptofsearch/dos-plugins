<?php
/**
 * llms.txt.
 *
 * A proposed convention: a Markdown index of a site at /llms.txt, so a model
 * can find the worthwhile pages without crawling everything.
 *
 * Treat this as a cheap bet, not established practice. At the time of writing
 * no major AI vendor has publicly committed to reading llms.txt. It costs one
 * generated file to participate and nothing is lost if the convention never
 * lands, which is the only reason it is here. The settings screen says as
 * much, so nobody inherits this site later believing it does more than it
 * does.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_AI_Llms {

	const QUERY_VAR = 'dos_llms_txt';
	const MAX_ITEMS = 100;

	public static function is_enabled() {
		return (bool) DOS_Settings::get( 'ai_llms_enabled', 0 );
	}

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ) );
	}

	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public static function add_rewrite() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function maybe_serve() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		echo self::generate(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Pages first, then recent posts. Both capped, because the point of the
	 * file is to be a short map rather than a second sitemap.
	 */
	public static function generate() {
		$lines = array();

		$lines[] = '# ' . self::clean( get_bloginfo( 'name' ) );

		$tagline = self::clean( DOS_Settings::get( 'seo_home_description', '' ) ) ?: self::clean( get_bloginfo( 'description' ) );

		if ( $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}

		$sections = array(
			__( 'Pages', 'dos-toolkit' ) => array( 'post_type' => 'page', 'orderby' => 'menu_order title', 'order' => 'ASC' ),
			__( 'Posts', 'dos-toolkit' ) => array( 'post_type' => 'post', 'orderby' => 'date', 'order' => 'DESC' ),
		);

		foreach ( $sections as $heading => $args ) {
			$items = get_posts( array_merge(
				$args,
				array(
					'post_status'            => 'publish',
					'posts_per_page'         => self::MAX_ITEMS,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			) );

			if ( ! $items ) {
				continue;
			}

			$lines[] = '';
			$lines[] = '## ' . $heading;
			$lines[] = '';

			foreach ( $items as $item ) {
				$title = self::clean( get_the_title( $item ) );

				if ( '' === $title ) {
					continue;
				}

				$summary = self::summary( $item );

				$lines[] = $summary
					? sprintf( '- [%s](%s): %s', $title, get_permalink( $item ), $summary )
					: sprintf( '- [%s](%s)', $title, get_permalink( $item ) );
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Prefer whatever the SEO module already decided this page says about
	 * itself, so the two never disagree.
	 */
	private static function summary( $post ) {
		$manual = get_post_meta( $post->ID, '_dos_seo_description', true );

		if ( $manual ) {
			return self::clean( $manual );
		}

		if ( $post->post_excerpt ) {
			return self::clean( $post->post_excerpt );
		}

		return '';
	}

	private static function clean( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		// Newlines would break the list item; brackets would break the link.
		return trim( str_replace( array( '[', ']' ), array( '(', ')' ), $text ) );
	}
}
