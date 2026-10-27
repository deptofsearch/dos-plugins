<?php
/**
 * The brand layer: Libre Franklin, the --breanm- tokens and the wordmark, applied over Themify
 * Magazine site-wide. Light theme only.
 *
 * The palette and type are the ones the agent cards already use, lifted out of assets/breanm-agents.css
 * so the cards stop being an island of their own design on a page that looks like something else.
 *
 * Two filters turn it off without a release: `dos_agents_brand` (the whole layer) and
 * `dos_agents_brand_logo` (the header wordmark alone, when the site wants a Customizer logo instead).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dos_agents_brand_enabled() {
	return (bool) apply_filters( 'dos_agents_brand', true );
}

/** The site name, in two weights: the plain part and the muted "Near Me". */
function dos_agents_brand_wordmark() {
	// A house under a magnifier, the site's own mark, drawn rather than loaded so it takes the link colour.
	$mark = '<svg class="breanm-brand__mark" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
		. ' stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
		. '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5.5h4V20"/></svg>';

	return '<span class="breanm-brand__name">' . $mark
		. '<b>' . esc_html__( 'Best Real Estate Agents', 'dos-agents' ) . '</b> '
		. '<i>' . esc_html__( 'Near Me', 'dos-agents' ) . '</i></span>'
		. '<span class="breanm-brand__tag">' . esc_html__( 'Which agents actually sell in your city', 'dos-agents' ) . '</span>';
}

function dos_agents_brand_enqueue() {
	if ( ! dos_agents_brand_enabled() ) {
		return;
	}
	wp_enqueue_style(
		'breanm-brand',
		plugin_dir_url( DOS_AGENTS_FILE ) . 'assets/breanm-brand.css',
		array(),
		DOS_AGENTS_VERSION
	);
}

function dos_agents_brand_body_class( $classes ) {
	if ( dos_agents_brand_enabled() ) {
		$classes[] = 'breanm-brand';
	}
	return $classes;
}

/**
 * Themify Magazine prints <div id="site-logo"><a ...><span>Site title</span></a></div> from its own
 * template with no filter to hook, so the swap happens on the finished HTML. If the markup is not
 * there — a theme update, another theme — the page is returned untouched and the site keeps its
 * own header.
 */
function dos_agents_brand_start_buffer() {
	if ( is_feed() || is_embed() || wp_doing_ajax() ) {
		return;
	}
	if ( ! dos_agents_brand_enabled() || ! apply_filters( 'dos_agents_brand_logo', true ) ) {
		return;
	}
	ob_start( 'dos_agents_brand_swap_logo' );
}

function dos_agents_brand_swap_logo( $html ) {
	if ( false === strpos( $html, 'id="site-logo"' ) ) {
		return $html;
	}
	$mark = dos_agents_brand_wordmark();
	// preg_replace reads \ and $ in the replacement; the wordmark is ours, but escape them anyway.
	$safe = str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $mark );
	$out  = preg_replace(
		'#(<div id="site-logo"[^>]*>\s*<a\b)([^>]*>)(.*?)(</a>)#s',
		'$1 class="breanm-brand__link"$2' . $safe . '$4',
		$html,
		1
	);
	return is_string( $out ) ? $out : $html;
}

/** The brand favicon, only when WordPress has no Site Icon of its own (Customize > Site Identity). */
function dos_agents_brand_icons() {
	if ( ! dos_agents_brand_enabled() || has_site_icon() ) {
		return;
	}
	echo '<link rel="icon" type="image/svg+xml" href="'
		. esc_url( plugin_dir_url( DOS_AGENTS_FILE ) . 'assets/brand/breanm-favicon.svg' ) . "\">\n";
}
