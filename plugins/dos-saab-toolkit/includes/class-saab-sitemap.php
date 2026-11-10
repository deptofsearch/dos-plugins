<?php
/**
 * XML sitemap: keeps WordPress's own sitemap (/wp-sitemap.xml) reachable and in step with the SEO module.
 *
 * Part of the SEO module, started from SAAB_SEO::init() after its stand-down check, so a full SEO plugin that
 * brings its own sitemap is left alone.
 *
 * Why this exists: on 2026-11-10 robots.txt pointed crawlers at /wp-sitemap.xml and that address returned the
 * homepage, as HTML, with a 200. The sitemaps behind it (wp-sitemap-posts-post-1.xml and the rest) were fine; only
 * the index was unreachable, so nothing could discover them. The stored rewrite rules still held the rules of a
 * sitemap plugin that is no longer running ("sitemap.xml", "sitemap.html", "<anything>-sitemap.xml"). One of them
 * matches "wp-sitemap.xml" and sends it to a query variable nobody registers any more, which leaves an empty query,
 * and an empty query is the front page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'SAAB_Sitemap', false ) ) {
	return;
}

class SAAB_Sitemap {

	/** Bump to rebuild the rewrite rules once more on the next page load. */
	const FLUSH_VERSION = '1';

	const FLUSH_OPTION = 'saab_sitemap_flush_version';

	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'route' ) );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'filter_provider' ), 10, 2 );

		// After every plugin has registered its rules (the restaurants module flushes at 999 for the same reason).
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 1000 );
	}

	/**
	 * Answer the two sitemap addresses from the path itself, whatever the rewrite rules matched.
	 *
	 * The flush below clears rules a removed plugin left behind, but a plugin that is still active puts its rules
	 * back on every flush. Reading the path works in both cases.
	 *
	 * @param WP $wp The request, after rewrite matching.
	 */
	public static function route( $wp ) {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) || ! wp_sitemaps_get_server()->sitemaps_enabled() ) {
			return;
		}
		if ( ! empty( $wp->query_vars['sitemap'] ) || ! empty( $wp->query_vars['sitemap-stylesheet'] ) ) {
			return; // Core's own rule matched. Nothing to repair.
		}

		$path = strtolower( trim( (string) $wp->request, '/' ) );

		if ( 'wp-sitemap.xml' === $path ) {
			$wp->query_vars    = array( 'sitemap' => 'index' );
			$wp->matched_rule  = '^wp-sitemap\.xml$';
			$wp->matched_query = 'sitemap=index';
			return;
		}

		// Core sends /sitemap.xml to /wp-sitemap.xml only when the request ends as a 404, which it never does here.
		if ( 'sitemap.xml' === $path ) {
			wp_safe_redirect( home_url( '/wp-sitemap.xml' ), 301, 'DoS SAAB Toolkit' );
			exit;
		}
	}

	/**
	 * Leave the author archive out: the SEO module marks it noindex (single-author site, it duplicates the blog),
	 * and a sitemap should not list a page that asks not to be indexed.
	 */
	public static function filter_provider( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	public static function maybe_flush_rewrite_rules() {
		if ( get_option( self::FLUSH_OPTION ) === self::FLUSH_VERSION ) {
			return;
		}

		flush_rewrite_rules();
		update_option( self::FLUSH_OPTION, self::FLUSH_VERSION );
	}
}
