<?php
/**
 * Registers and enqueues assets/osn.css and assets/osn.js, only where a shortcode or venue page needs them.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/**
	 * Themify Landing header fixes, site-wide: collapse the empty 42px desktop side-menu block (no menu items),
	 * trim the logo's ~80px of vertical padding, and on phones keep the text logo clear of the menu icon.
	 */
	const MOBILE_LOGO_CSS = '#mobile-menu.sidemenu-off:not(:has(#main-nav-wrap li)){padding:0!important;margin:0!important;min-height:0!important;height:0!important;overflow:hidden!important}@media (min-width:601px){#site-logo{padding-top:20px!important;padding-bottom:8px!important}#headerwrap{padding-bottom:0!important}}@media (max-width:600px){#site-logo{padding-right:52px!important;text-align:left!important}#site-logo a,#site-logo span{font-size:clamp(20px,6.2vw,26px)!important;line-height:1.15!important}}';

	public static function hooks() {
		add_action( 'wp_head', array( __CLASS__, 'mobile_logo_fix' ), 99 );
	}

	public static function mobile_logo_fix() {
		if ( apply_filters( 'osn_mobile_logo_fix', true ) ) {
			echo '<style id="osn-mobile-logo">' . self::MOBILE_LOGO_CSS . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- constant CSS.
		}
	}

	public static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$url = plugins_url( 'assets/', PLUGIN_FILE );
		wp_enqueue_style( 'dos-outdoor-seating', $url . 'osn.css', array(), VERSION );

		$vars = '';
		$accent = sanitize_hex_color( (string) get_option( 'osn_accent_color', '' ) );
		$ink    = sanitize_hex_color( (string) get_option( 'osn_ink_color', '' ) );
		if ( $accent ) {
			$vars .= '--osn-accent:' . $accent . ';';
		}
		if ( $ink ) {
			$vars .= '--osn-ink:' . $ink . ';';
		}
		if ( $vars ) {
			wp_add_inline_style( 'dos-outdoor-seating', '.osn{' . $vars . '}' );
		}

		wp_enqueue_script(
			'dos-outdoor-seating',
			$url . 'osn.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
