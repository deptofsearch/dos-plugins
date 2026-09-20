<?php
/**
 * The page-level view of internal linking.
 *
 * The phrases table answers "how is this phrase being used". This answers the
 * other half: which pages link out, which pages are linked to, and which do
 * neither. A page nothing links to is invisible to the site's own structure
 * however well its phrases are configured, and the phrase-centric view cannot
 * show that.
 *
 * Counts cover every internal link on a page, not only the ones this module
 * placed — a link written by hand carries exactly as much weight.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Links_Pages {

	const DB_VERSION = '1';

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'dos_link_pages';
	}

	public static function maybe_install() {
		if ( DOS_Settings::get( 'links_pages_db_version' ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				post_id bigint(20) unsigned NOT NULL,
				out_module int(11) unsigned NOT NULL DEFAULT 0,
				out_manual int(11) unsigned NOT NULL DEFAULT 0,
				in_module int(11) unsigned NOT NULL DEFAULT 0,
				in_manual int(11) unsigned NOT NULL DEFAULT 0,
				updated datetime NOT NULL,
				PRIMARY KEY  (post_id),
				KEY out_module (out_module),
				KEY in_module (in_module)
			) {$collate};"
		);

		DOS_Settings::set( 'links_pages_db_version', self::DB_VERSION );
	}

	public static function reset() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Add to a page's figures, creating its row if this is the first thing
	 * seen about it. Inbound links arrive while scanning some other page, so
	 * rows cannot be created in order.
	 */
	public static function add( $post_id, $out_module = 0, $out_manual = 0, $in_module = 0, $in_manual = 0 ) {
		global $wpdb;

		$table = self::table();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (post_id, out_module, out_manual, in_module, in_manual, updated)
				VALUES (%d, %d, %d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE
					out_module = out_module + %d,
					out_manual = out_manual + %d,
					in_module = in_module + %d,
					in_manual = in_manual + %d,
					updated = %s",
				(int) $post_id,
				(int) $out_module,
				(int) $out_manual,
				(int) $in_module,
				(int) $in_manual,
				current_time( 'mysql' ),
				(int) $out_module,
				(int) $out_manual,
				(int) $in_module,
				(int) $in_manual,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * @return array rows, total, pages
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => 'all',
				'orderby'  => 'out_total',
				'order'    => 'desc',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$table = self::table();
		$where = array( '1=1' );

		switch ( $args['status'] ) {
			case 'no_out':
				$where[] = '(out_module + out_manual) = 0';
				break;

			case 'no_in':
				$where[] = '(in_module + in_manual) = 0';
				break;

			// Nothing points at it and it points at nothing: as far as the
			// site's own structure is concerned, it is not there.
			case 'isolated':
				$where[] = '(out_module + out_manual) = 0 AND (in_module + in_manual) = 0';
				break;

			case 'linked':
				$where[] = '(out_module + out_manual) > 0';
				break;
		}

		$columns = array(
			'out_total' => '(out_module + out_manual)',
			'in_total'  => '(in_module + in_manual)',
			'out_module' => 'out_module',
			'in_module'  => 'in_module',
			'post_id'    => 'post_id',
		);

		$orderby = isset( $columns[ $args['orderby'] ] ) ? $columns[ $args['orderby'] ] : $columns['out_total'];
		$order   = 'asc' === strtolower( $args['order'] ) ? 'ASC' : 'DESC';
		$clause  = implode( ' AND ', $where );

		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$clause}" );
		$per_page = max( 1, (int) $args['per_page'] );
		$page     = max( 1, (int) $args['page'] );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY {$orderby} {$order}, post_id ASC LIMIT %d OFFSET %d",
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	public static function totals() {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS pages,
				SUM(out_module + out_manual) AS links,
				SUM(CASE WHEN (out_module + out_manual) = 0 THEN 1 ELSE 0 END) AS no_out,
				SUM(CASE WHEN (in_module + in_manual) = 0 THEN 1 ELSE 0 END) AS no_in
			FROM {$table}",
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();

		return array(
			'pages'  => (int) ( $row['pages'] ?? 0 ),
			'links'  => (int) ( $row['links'] ?? 0 ),
			'no_out' => (int) ( $row['no_out'] ?? 0 ),
			'no_in'  => (int) ( $row['no_in'] ?? 0 ),
		);
	}

	/**
	 * Internal links in a piece of content, split by who placed them and
	 * where they point.
	 *
	 * @param array $targets rule_id => target post ID.
	 *
	 * @return array out_module, out_manual, to (post_id => array(module, manual))
	 */
	public static function analyse_links( $html, array $targets ) {
		$out = array( 'out_module' => 0, 'out_manual' => 0, 'to' => array() );

		if ( ! preg_match_all( '#<a\b([^>]*)>#i', (string) $html, $tags, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $tags as $tag ) {
			$attrs = $tag[1];

			if ( preg_match( '/data-dos-link="(\d+)"/i', $attrs, $m ) ) {
				$out['out_module']++;

				$target = isset( $targets[ (int) $m[1] ] ) ? (int) $targets[ (int) $m[1] ] : 0;

				if ( $target ) {
					$out['to'][ $target ]['module'] = ( $out['to'][ $target ]['module'] ?? 0 ) + 1;
				}

				continue;
			}

			if ( ! preg_match( '/href=["\']([^"\']+)["\']/i', $attrs, $m ) ) {
				continue;
			}

			$target = self::resolve( $m[1] );

			if ( ! $target ) {
				continue; // Off-site, an anchor, a mailto: not internal linking.
			}

			$out['out_manual']++;
			$out['to'][ $target ]['manual'] = ( $out['to'][ $target ]['manual'] ?? 0 ) + 1;
		}

		return $out;
	}

	/**
	 * A URL to a post on this site, or 0. Resolutions are cached for the
	 * request, since the same navigation link appears on every page.
	 */
	private static function resolve( $url ) {
		static $cache = array();

		$url = trim( (string) $url );

		if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '#^(mailto|tel|javascript):#i', $url ) ) {
			return 0;
		}

		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$full = $url;

		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$full = home_url( $url );
		}

		$host = wp_parse_url( $full, PHP_URL_HOST );

		if ( $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$cache[ $url ] = 0;

			return 0;
		}

		$cache[ $url ] = (int) url_to_postid( $full );

		return $cache[ $url ];
	}
}
