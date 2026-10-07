<?php
/**
 * Brand layer for the public site: Google Fonts, the --blnm- token stylesheet applied over Themify
 * Magazine (scoped under body.blnm-brand), the horizontal logo in the header, and the favicon fallback.
 * Light theme only for now.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Brand {

	const FONTS = 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Newsreader:wght@600&family=Public+Sans:wght@400;600&display=swap';
	const CSS   = 'dos-best-lenders-brand';

	public static function hooks() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 5 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'hints' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'start_logo_buffer' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'icons' ), 2 );
	}

	public static function url( $file ) {
		return plugins_url( 'assets/' . $file, PLUGIN_FILE );
	}

	public static function enqueue() {
		wp_enqueue_style( 'dos-best-lenders-fonts', self::FONTS, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters -- Google Fonts URL carries its own version.
		wp_enqueue_style( self::CSS, self::url( 'blnm-brand.css' ), array( 'dos-best-lenders-fonts' ), VERSION );
	}

	public static function body_class( $classes ) {
		$classes[] = 'blnm-brand';
		return $classes;
	}

	public static function hints( $urls, $relation ) {
		if ( 'preconnect' === $relation ) {
			$urls[] = 'https://fonts.googleapis.com';
			$urls[] = array(
				'href'        => 'https://fonts.gstatic.com',
				'crossorigin' => 'anonymous',
			);
		}
		return $urls;
	}

	/**
	 * Header logo. Themify Magazine prints <div id="site-logo"><a ...><span>Site title</span></a></div>
	 * (or an <img> when a logo is set in the Customizer) from its own template with no documented filter,
	 * so swap the link's contents on the finished HTML. If the markup is not found the page is left alone.
	 */
	public static function start_logo_buffer() {
		if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() || '0' === Settings::get( 'brand_logo' ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'swap_logo' ) );
	}

	public static function swap_logo( $html ) {
		if ( false === strpos( $html, 'id="site-logo"' ) ) {
			return $html;
		}
		$img = '<img class="blnm-logo" src="' . esc_url( self::url( 'brand/blnm-logo-horizontal.svg' ) ) . '" width="422" height="64" alt="' . esc_attr__( 'Best Lenders Near Me', 'dos-best-lenders' ) . '">';
		$out = preg_replace( '#(<div id="site-logo"[^>]*>\s*<a\b[^>]*>)(.*?)(</a>)#s', '$1' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $img ) . '$3', $html, 1 );
		return is_string( $out ) ? $out : $html;
	}

	/** Brand favicon only when WordPress has no Site Icon (Appearance > Customize > Site Identity). */
	public static function icons() {
		if ( has_site_icon() ) {
			return;
		}
		echo '<link rel="icon" type="image/svg+xml" href="' . esc_url( self::url( 'brand/blnm-favicon.svg' ) ) . "\">\n";
		echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( self::url( 'brand/blnm-apple-touch-180.png' ) ) . "\">\n";
	}
}
