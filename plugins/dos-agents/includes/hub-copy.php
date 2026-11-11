<?php
/**
 * State hubs and the blog index: the copy fixes from the SEO re-audit of 2026-11-11.
 *
 * - The hubs' stock paragraph "At Best Real Estate Agents Near Me, we help connect you with trusted real estate
 *   agents in {State}..." is left out. It says the opposite of the source line under the city boxes ("not a ranking
 *   or endorsement"), and 50 of the 51 hubs carry it. Ryan chose to drop only that paragraph (2026-11-11); the
 *   "Finding the best real estate agent in {State}" and "Some of the top cities in {State}" paragraphs stay.
 * - A paragraph that opens "Some of the top cities in {State}" a second time is left out too (Oregon has its whole
 *   closing block twice).
 * - Each hub gets a next step under its facts: pick a city below, or jump to the list of every city.
 * - The blog index, whose title Themify hides, gets an H1 and a one-sentence description.
 *
 * Nothing is written into the pages. `dos_agents_hub_copy` (false) turns the hub changes off and
 * `dos_agents_blog_head` (false) the blog heading.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dos_agents_hub_copy_enabled() {
	return (bool) apply_filters( 'dos_agents_hub_copy', true );
}

/**
 * Removes paragraphs from a hub's stored content: every one that opens with $start, or every one after the first
 * when $keep_first is true. Works on both forms the content can be stored in: <p> elements, or plain text blocks
 * separated by blank lines that wpautop turns into paragraphs later.
 */
function dos_agents_hub_drop_paragraphs( $content, $start, $keep_first ) {
	$q    = preg_quote( $start, '#' );
	$seen = false;
	$drop = static function ( $m ) use ( &$seen, $keep_first ) {
		// The stock paragraphs are 300 to 600 characters. A longer match means the pattern ran past the paragraph,
		// so nothing is removed rather than risk taking the city table or the rest of the page with it.
		if ( strlen( $m[0] ) > 2000 ) {
			return $m[0];
		}
		if ( $keep_first && ! $seen ) {
			$seen = true;
			return $m[0];
		}
		return $m[1];
	};
	// <p> paragraphs. $m[1] is whatever came before the paragraph on its line (kept).
	$out = preg_replace_callback( '#()<p\b[^>]*>\s*' . $q . '(?:(?!</p>).)*</p>[ \t]*\R?#is', $drop, $content );
	if ( null === $out ) {
		return $content;
	}
	// Plain paragraphs: a block of text at the start of a line, running to the next blank line, the next block tag
	// (where wpautop would end the paragraph too) or the end.
	$out2 = preg_replace_callback( '#(^|\R)[ \t]*' . $q . '(?:(?!\R[ \t]*\R|</?(?:p|div|h[1-6]|table|ul|ol|li|blockquote|section|style|figure|pre|hr|header|footer|article|aside|nav|form)\b).)*#isu', $drop, $out );
	return null === $out2 ? $out : $out2;
}

/** The hub's stored content with the stock "we help connect you with trusted agents" paragraph left out. */
function dos_agents_hub_trim_copy( $content, $state ) {
	if ( ! dos_agents_hub_copy_enabled() ) {
		return $content;
	}
	$content = dos_agents_hub_drop_paragraphs( (string) $content, 'At Best Real Estate Agents Near Me, we help connect', false );
	return dos_agents_hub_drop_paragraphs( $content, 'Some of the top cities in ' . dos_agents_state_name( $state ), true );
}

/** Gives the hub's city table an id, so the next-step line can link to it. Unchanged when it has one. */
function dos_agents_hub_table_anchor( $content ) {
	$out = preg_replace( '/<table\b(?![^>]*\sid=)([^>]*\bbreanm-city-table\b)/i', '<table id="all-cities"$1', (string) $content, 1, $count );
	return ( null === $out || 1 !== $count ) ? $content : $out;
}

/**
 * The next step under a hub's facts: "Pick a city below to compare its agents, or find yours among all 470 cities in
 * Washington." The second half links to the city table. '' when there is nothing to pick from.
 */
function dos_agents_render_hub_next( $state, $pages, $has_anchor ) {
	if ( ! dos_agents_hub_copy_enabled() || ! dos_agents_index_for_state( $state ) ) {
		return '';
	}
	$name = dos_agents_state_name( $state );
	if ( null === $pages || ! $has_anchor ) {
		return '<p class="breanm-hub-next">' . esc_html__( 'Pick a city below to compare its agents.', 'dos-agents' ) . '</p>';
	}
	$link = '<a href="#all-cities">' . esc_html(
		sprintf(
			/* translators: 1: number of city pages, 2: state name */
			_n( 'the %1$s city in %2$s', 'all %1$s cities in %2$s', $pages, 'dos-agents' ),
			number_format_i18n( $pages ),
			$name
		)
	) . '</a>';
	/* translators: %s: link to the list of every city, e.g. "all 470 cities in Washington" */
	return '<p class="breanm-hub-next">' . sprintf( esc_html__( 'Pick a city below to compare its agents, or find yours among %s.', 'dos-agents' ), $link ) . '</p>';
}

/** True on the blog index: the top-level page with the "blog" slug. */
function dos_agents_is_blog_index() {
	if ( ! is_page() ) {
		return false;
	}
	$post = get_queried_object();
	return $post instanceof WP_Post && 'blog' === $post->post_name && ! $post->post_parent;
}

/** template_redirect: buffers the blog index so its heading can go in after Themify has built the page. */
function dos_agents_blog_start_buffer() {
	if ( is_feed() || is_embed() || wp_doing_ajax() || ! apply_filters( 'dos_agents_blog_head', true ) || ! dos_agents_is_blog_index() ) {
		return;
	}
	ob_start( 'dos_agents_blog_add_head' );
}

/** The H1 and description for the blog index. */
function dos_agents_blog_head_html() {
	return '<header class="breanm-blog-head">'
		. '<h1 class="breanm-blog-title">' . esc_html__( 'Articles on choosing a real estate agent', 'dos-agents' ) . '</h1>'
		. '<p class="breanm-blog-intro">' . esc_html__( 'How to read agent reviews, what an agent\'s sales numbers do and do not tell you, whether a team or a solo agent suits you, and how to find one who communicates the way you like.', 'dos-agents' ) . '</p>'
		. '</header>';
}

/**
 * Puts the heading at the top of the page content. Themify Magazine prints the page as
 * <main id="content">...<div class="page-content entry-content">; if that markup is missing, or the main content
 * already has an H1, the page is returned untouched.
 */
function dos_agents_blog_add_head( $html ) {
	$main = strpos( $html, '<main id="content"' );
	if ( false === $main ) {
		return $html;
	}
	// Only on the whole page: if something flushed the buffer early, a chunk without </main> cannot be checked for
	// an H1 of its own, so it is left alone.
	$end = strpos( $html, '</main>', $main );
	if ( false === $end || false !== stripos( substr( $html, $main, $end - $main ), '<h1' ) ) {
		return $html;
	}
	$at = strpos( $html, '<div class="page-content entry-content">', $main );
	if ( false === $at ) {
		return $html;
	}
	$at += strlen( '<div class="page-content entry-content">' );
	return substr( $html, 0, $at ) . dos_agents_blog_head_html() . substr( $html, $at );
}
