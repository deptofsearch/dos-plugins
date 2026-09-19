<?php
/**
 * Storage and lookup for redirects and logged 404s.
 *
 * The redirect map is consulted on every front-end request, so it is held as
 * one cached array rather than queried per request. Exact paths only: the map
 * is a hash lookup, which costs the same whether it holds ten rules or ten
 * thousand.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Redirects_Store {

	const DB_VERSION = '1';
	const MAP_KEY    = 'dos_redirect_map';

	/* ---------------------------------------------------------------------
	 * Tables
	 * ------------------------------------------------------------------- */

	public static function redirects_table() {
		global $wpdb;

		return $wpdb->prefix . 'dos_redirects';
	}

	public static function log_table() {
		global $wpdb;

		return $wpdb->prefix . 'dos_404';
	}

	public static function maybe_install() {
		if ( DOS_Settings::get( 'redirects_db_version' ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate  = $wpdb->get_charset_collate();
		$redirects = self::redirects_table();
		$log       = self::log_table();

		// 191 characters is the longest a utf8mb4 column can be and still
		// carry a unique index on older MySQL.
		dbDelta(
			"CREATE TABLE {$redirects} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(191) NOT NULL DEFAULT '',
				target text NOT NULL,
				code smallint(4) NOT NULL DEFAULT 301,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				enabled tinyint(1) NOT NULL DEFAULT 1,
				created datetime NOT NULL,
				last_used datetime NULL DEFAULT NULL,
				origin varchar(20) NOT NULL DEFAULT 'manual',
				PRIMARY KEY  (id),
				UNIQUE KEY source (source)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				path varchar(191) NOT NULL DEFAULT '',
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				referrer varchar(255) NOT NULL DEFAULT '',
				ignored tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY path (path),
				KEY last_seen (last_seen)
			) {$collate};"
		);

		DOS_Settings::set( 'redirects_db_version', self::DB_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Normalising
	 * ------------------------------------------------------------------- */

	/**
	 * Reduce a URL or path to the form used as a lookup key.
	 *
	 * Everything is compared lowercase without a trailing slash, because
	 * /About/ and /about are the same page to everyone except a string
	 * comparison. The query string is dropped: it is preserved on the way out
	 * instead, so one rule covers a URL however it was tagged.
	 */
	public static function normalise( $path ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			return '';
		}

		// Accept a full URL as well as a path.
		if ( preg_match( '#^https?://#i', $path ) ) {
			$parsed = wp_parse_url( $path );
			$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		}

		$path = strtok( $path, '?' );
		$path = strtok( $path, '#' );
		$path = '/' . ltrim( (string) $path, '/' );
		$path = rtrim( $path, '/' );

		if ( '' === $path ) {
			return '/';
		}

		return strtolower( $path );
	}

	/**
	 * A target is either an absolute http/https URL or a path on this site.
	 * Anything else is refused outright rather than repaired.
	 *
	 * Two cases matter, and both turn a redirect table into an open redirect
	 * that the site hands out under its own name:
	 *
	 *   //evil.example/x  a protocol-relative URL. It begins with a slash, so
	 *                     anything treating "starts with /" as "internal"
	 *                     accepts it, and the browser then loads another
	 *                     origin entirely.
	 *   javascript:...    a scheme that executes rather than navigates.
	 *
	 * Prefixing a slash to make these look like paths would hide them instead
	 * of stopping them, so they are rejected.
	 */
	public static function clean_target( $target ) {
		$target = trim( (string) $target );

		if ( '' === $target ) {
			return '';
		}

		// Protocol-relative: another origin wearing a path's clothes.
		if ( 0 === strpos( $target, '//' ) ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $target ) ) {
			return esc_url_raw( $target );
		}

		// Any other scheme, including javascript:, data: and mailto:.
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $target ) ) {
			return '';
		}

		if ( 0 !== strpos( $target, '/' ) ) {
			$target = '/' . $target;
		}

		return esc_url_raw( $target );
	}

	/* ---------------------------------------------------------------------
	 * The map
	 * ------------------------------------------------------------------- */

	/**
	 * source => array( id, target, code )
	 */
	public static function map() {
		$map = wp_cache_get( self::MAP_KEY, 'dos_toolkit' );

		if ( is_array( $map ) ) {
			return $map;
		}

		$map = get_option( self::MAP_KEY, null );

		if ( ! is_array( $map ) ) {
			$map = self::rebuild_map();
		}

		wp_cache_set( self::MAP_KEY, $map, 'dos_toolkit', HOUR_IN_SECONDS );

		return $map;
	}

	public static function rebuild_map() {
		global $wpdb;

		$table = self::redirects_table();
		$rows  = $wpdb->get_results( "SELECT id, source, target, code FROM {$table} WHERE enabled = 1", ARRAY_A );
		$map   = array();

		foreach ( (array) $rows as $row ) {
			$map[ $row['source'] ] = array(
				'id'     => (int) $row['id'],
				'target' => $row['target'],
				'code'   => (int) $row['code'],
			);
		}

		update_option( self::MAP_KEY, $map, false );
		wp_cache_set( self::MAP_KEY, $map, 'dos_toolkit', HOUR_IN_SECONDS );

		return $map;
	}

	private static function flush_map() {
		delete_option( self::MAP_KEY );
		wp_cache_delete( self::MAP_KEY, 'dos_toolkit' );
	}

	/* ---------------------------------------------------------------------
	 * Redirects
	 * ------------------------------------------------------------------- */

	/**
	 * @return int|WP_Error Redirect ID, or why it was refused.
	 */
	public static function add( $source, $target, $code = 301, $origin = 'manual' ) {
		global $wpdb;

		$source = self::normalise( $source );
		$target = self::clean_target( $target );

		if ( '' === $source || '/' === $source ) {
			return new WP_Error( 'dos_bad_source', __( 'Enter the path that should redirect. The site root cannot be redirected.', 'dos-toolkit' ) );
		}

		if ( '' === $target ) {
			return new WP_Error( 'dos_bad_target', __( 'Enter where it should go. Only http and https targets are allowed.', 'dos-toolkit' ) );
		}

		// A rule pointing at itself is an infinite loop, and the browser is
		// the thing that finds out.
		if ( self::normalise( $target ) === $source && ! preg_match( '#^https?://#i', $target ) ) {
			return new WP_Error( 'dos_loop', __( 'That would redirect the path to itself.', 'dos-toolkit' ) );
		}

		if ( self::would_loop( $source, $target ) ) {
			return new WP_Error( 'dos_loop_chain', __( 'That would create a redirect loop with a rule that already exists.', 'dos-toolkit' ) );
		}

		if ( strlen( $source ) > 191 ) {
			return new WP_Error( 'dos_too_long', __( 'That path is too long to index. Redirect a shorter path.', 'dos-toolkit' ) );
		}

		$code = in_array( (int) $code, array( 301, 302, 307, 410 ), true ) ? (int) $code : 301;

		$existing = self::find( $source );

		if ( $existing ) {
			$wpdb->update(
				self::redirects_table(),
				array( 'target' => $target, 'code' => $code, 'enabled' => 1 ),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%d', '%d' ),
				array( '%d' )
			);

			self::flush_map();

			return (int) $existing['id'];
		}

		$wpdb->insert(
			self::redirects_table(),
			array(
				'source'  => $source,
				'target'  => $target,
				'code'    => $code,
				'created' => current_time( 'mysql' ),
				'origin'  => substr( (string) $origin, 0, 20 ),
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		self::flush_map();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Follow the chain from the proposed target and see whether it comes back
	 * to the proposed source. Depth is capped: a chain longer than this is
	 * already a problem whether or not it closes.
	 */
	public static function would_loop( $source, $target, $depth = 10 ) {
		if ( preg_match( '#^https?://#i', $target ) ) {
			$host = wp_parse_url( $target, PHP_URL_HOST );

			if ( $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				return false; // Leaves the site; cannot loop back through us.
			}
		}

		$map  = self::map();
		$seen = array();
		$next = self::normalise( $target );

		while ( $depth-- > 0 ) {
			if ( $next === $source ) {
				return true;
			}

			if ( isset( $seen[ $next ] ) || ! isset( $map[ $next ] ) ) {
				return false;
			}

			$seen[ $next ] = true;
			$next          = self::normalise( $map[ $next ]['target'] );
		}

		return true;
	}

	public static function find( $source ) {
		global $wpdb;

		$table = self::redirects_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s", self::normalise( $source ) ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	public static function all( $limit = 500 ) {
		global $wpdb;

		$table = self::redirects_table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ),
			ARRAY_A
		);
	}

	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::redirects_table(), array( 'id' => (int) $id ), array( '%d' ) );

		self::flush_map();
	}

	public static function set_enabled( $id, $enabled ) {
		global $wpdb;

		$wpdb->update(
			self::redirects_table(),
			array( 'enabled' => $enabled ? 1 : 0 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);

		self::flush_map();
	}

	public static function record_hit( $id ) {
		global $wpdb;

		$table = self::redirects_table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET hits = hits + 1, last_used = %s WHERE id = %d",
				current_time( 'mysql' ),
				(int) $id
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * 404 log
	 * ------------------------------------------------------------------- */

	public static function record_404( $path, $referrer = '' ) {
		global $wpdb;

		$path = self::normalise( $path );

		if ( '' === $path || strlen( $path ) > 191 ) {
			return;
		}

		$table = self::log_table();
		$now   = current_time( 'mysql' );

		// One row per path with a counter. One row per hit would let a single
		// scanner fill the table overnight.
		$updated = $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_seen = %s WHERE path = %s", $now, $path )
		);

		if ( $updated ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'path'       => $path,
				'hits'       => 1,
				'first_seen' => $now,
				'last_seen'  => $now,
				'referrer'   => substr( (string) $referrer, 0, 255 ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);
	}

	public static function log( $limit = 200, $include_ignored = false ) {
		global $wpdb;

		$table = self::log_table();
		$where = $include_ignored ? '' : 'WHERE ignored = 0';

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY hits DESC, last_seen DESC LIMIT %d", (int) $limit ),
			ARRAY_A
		);
	}

	public static function log_count() {
		global $wpdb;

		$table = self::log_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ignored = 0" );
	}

	public static function ignore_404( $id, $ignored = true ) {
		global $wpdb;

		$wpdb->update( self::log_table(), array( 'ignored' => $ignored ? 1 : 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	public static function delete_404( $id ) {
		global $wpdb;

		$wpdb->delete( self::log_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function clear_404s() {
		global $wpdb;

		$table = self::log_table();

		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Drop rows nothing has hit lately, so the table reflects the present.
	 */
	public static function prune_404s() {
		global $wpdb;

		$days = (int) DOS_Settings::get( 'redirects_log_days', 60 );

		if ( $days < 1 ) {
			return;
		}

		$table  = self::log_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s AND ignored = 0", $cutoff ) );
	}
}
