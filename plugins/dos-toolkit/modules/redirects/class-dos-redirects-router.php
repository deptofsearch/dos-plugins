<?php
/**
 * The front-end half: send matching requests on, and log the ones that hit
 * nothing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Redirects_Router {

	/**
	 * Requests that must never be redirected or logged. A redirect on any of
	 * these locks someone out of their own site.
	 */
	const NEVER = array( '/wp-admin', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-json' );

	public static function init() {
		// Early enough to beat WordPress's own guess-a-close-match redirect,
		// which would otherwise send a renamed URL somewhere of its choosing.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log' ), 999 );
	}

	public static function current_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		return (string) $uri;
	}

	private static function is_excluded( $path ) {
		foreach ( self::NEVER as $prefix ) {
			if ( 0 === stripos( $path, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private static function skip_request() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		// A redirect answers a GET. Redirecting a POST would throw the body
		// away and the sender would never know.
		return 'GET' !== $method && 'HEAD' !== $method;
	}

	public static function maybe_redirect() {
		if ( self::skip_request() ) {
			return;
		}

		$raw  = self::current_path();
		$path = DOS_Redirects_Store::normalise( $raw );

		if ( '' === $path || '/' === $path || self::is_excluded( $raw ) ) {
			return;
		}

		$map = DOS_Redirects_Store::map();

		if ( ! isset( $map[ $path ] ) ) {
			return;
		}

		$rule = $map[ $path ];

		DOS_Redirects_Store::record_hit( $rule['id'] );

		if ( 410 === (int) $rule['code'] ) {
			// Gone is not a redirect: it tells a crawler to stop asking.
			status_header( 410 );
			nocache_headers();

			wp_die(
				esc_html__( 'This page has been removed.', 'dos-toolkit' ),
				esc_html__( 'Gone', 'dos-toolkit' ),
				array( 'response' => 410 )
			);
		}

		$target = self::with_query( $rule['target'], $raw );

		// wp_redirect rather than wp_safe_redirect: a redirect to another
		// site is a legitimate thing to configure, and the target was
		// sanitised to http/https when it was saved.
		wp_redirect( $target, (int) $rule['code'] );
		exit;
	}

	/**
	 * Carry the original query string across, unless the target sets its own.
	 * A campaign tag on the old URL should survive the move.
	 */
	public static function with_query( $target, $raw ) {
		$incoming = wp_parse_url( $raw, PHP_URL_QUERY );

		if ( ! $incoming ) {
			return $target;
		}

		if ( false !== strpos( $target, '?' ) ) {
			return $target;
		}

		return $target . '?' . $incoming;
	}

	/* ---------------------------------------------------------------------
	 * 404 logging
	 * ------------------------------------------------------------------- */

	public static function logging_enabled() {
		$value = DOS_Settings::get( 'redirects_log_404', null );

		return null === $value ? true : (bool) $value;
	}

	/**
	 * Requests that are noise rather than broken links. A site on the open
	 * internet is probed constantly, and a log full of wp-config fishing is a
	 * log nobody reads.
	 */
	public static function is_noise( $path ) {
		$patterns = array(
			'\.php$',
			'\.env$',
			'\.git',
			'\.sql$',
			'\.ya?ml$',
			'/vendor/',
			'/\.well-known/',
			'wp-config',
			'phpmyadmin',
			'/autodiscover',
			'/owa/',
			'\.asp[x]?$',
			'\.cgi$',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( '#' . $pattern . '#i', $path ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'dos_toolkit_404_is_noise', false, $path );
	}

	public static function maybe_log() {
		if ( ! is_404() || self::skip_request() || ! self::logging_enabled() ) {
			return;
		}

		$raw  = self::current_path();
		$path = DOS_Redirects_Store::normalise( $raw );

		if ( '' === $path || '/' === $path || self::is_excluded( $raw ) || self::is_noise( $path ) ) {
			return;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		DOS_Redirects_Store::record_404( $path, $referrer );
	}
}
