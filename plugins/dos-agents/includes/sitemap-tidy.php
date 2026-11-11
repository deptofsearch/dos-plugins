<?php
/**
 * DoS - Agents: the core sitemap lists only pages worth finding, and the thin archives are noindex.
 *
 * Why (2026-11-11): the core sitemap listed one author archive (/author/<login>/, which also shows the
 * WordPress login name) and 22 tag archives. Both are thin lists of the old generic posts, with nothing a
 * visitor searches for. They leave the sitemap and get noindex, follow, so links through them still count.
 * Categories stay: "Agent Lists by State" and "Buying and Selling News" are real sections.
 *
 * The filter `dos_agents_sitemap_tidy` (false) turns all of it off without a release.
 */

defined( 'ABSPATH' ) || exit;

/** True unless turned off with the `dos_agents_sitemap_tidy` filter. */
function dos_agents_sitemap_tidy_enabled() {
	return (bool) apply_filters( 'dos_agents_sitemap_tidy', true );
}

/** wp_sitemaps_add_provider: no users (author archives) sitemap. */
function dos_agents_sitemap_providers( $provider, $name ) {
	if ( 'users' === $name && dos_agents_sitemap_tidy_enabled() ) {
		return false;
	}
	return $provider;
}

/** wp_sitemaps_taxonomies: no post tag sitemap. Categories stay. */
function dos_agents_sitemap_taxonomies( $taxonomies ) {
	if ( is_array( $taxonomies ) && dos_agents_sitemap_tidy_enabled() ) {
		unset( $taxonomies['post_tag'] );
	}
	return $taxonomies;
}

/** wp_robots: author and tag archives are noindex, follow. */
function dos_agents_sitemap_robots( $robots ) {
	if ( ! is_array( $robots ) || ! dos_agents_sitemap_tidy_enabled() ) {
		return $robots;
	}
	if ( ( function_exists( 'is_author' ) && is_author() ) || ( function_exists( 'is_tag' ) && is_tag() ) ) {
		unset( $robots['index'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
}
