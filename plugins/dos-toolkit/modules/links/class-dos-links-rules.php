<?php
/**
 * Link rules: one phrase, one destination, and the limits that stop it being
 * used too heavily.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Links_Rules {

	const DB_VERSION = '2';

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'dos_link_rules';
	}

	public static function maybe_install() {
		if ( DOS_Settings::get( 'links_db_version' ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				phrase varchar(191) NOT NULL DEFAULT '',
				target_id bigint(20) unsigned NOT NULL DEFAULT 0,
				max_per_page smallint(5) NOT NULL DEFAULT 1,
				first_instance varchar(10) NOT NULL DEFAULT 'link',
				throttle smallint(5) NOT NULL DEFAULT 100,
				enabled tinyint(1) NOT NULL DEFAULT 1,
				opportunities bigint(20) unsigned NOT NULL DEFAULT 0,
				links_made bigint(20) unsigned NOT NULL DEFAULT 0,
				pages_linked bigint(20) unsigned NOT NULL DEFAULT 0,
				scanned_at datetime NULL DEFAULT NULL,
				last_reason varchar(20) NOT NULL DEFAULT '',
				found_in bigint(20) unsigned NOT NULL DEFAULT 0,
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY phrase (phrase)
			) {$collate};"
		);

		DOS_Settings::set( 'links_db_version', self::DB_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------- */

	public static function all( $enabled_only = false ) {
		global $wpdb;

		$table = self::table();
		$where = $enabled_only ? 'WHERE enabled = 1' : '';

		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY phrase ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Filtered, sorted, paginated rules.
	 *
	 * A site with two phrases is a list. A site with two hundred is a working
	 * set that has to be searched and narrowed, or the screen becomes a wall
	 * nobody reads.
	 *
	 * @return array rows, total, pages
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'status'   => 'all',
				'orderby'  => 'phrase',
				'order'    => 'asc',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== trim( (string) $args['search'] ) ) {
			$where[]  = 'phrase LIKE %s';
			$params[] = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
		}

		switch ( $args['status'] ) {
			case 'enabled':
				$where[] = 'enabled = 1';
				break;

			case 'disabled':
				$where[] = 'enabled = 0';
				break;

			// Phrases doing nothing: either never placed a link, or have no
			// remaining opportunity. These are the rows worth attention.
			case 'idle':
				$where[] = 'links_made = 0';
				break;

			case 'available':
				$where[] = 'opportunities > 0';
				break;
		}

		// Whitelisted: this goes straight into ORDER BY.
		$columns = array( 'phrase', 'links_made', 'opportunities', 'throttle', 'max_per_page', 'created' );
		$orderby = in_array( $args['orderby'], $columns, true ) ? $args['orderby'] : 'phrase';
		$order   = 'desc' === strtolower( $args['order'] ) ? 'DESC' : 'ASC';

		$clause = implode( ' AND ', $where );

		$total = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $params ) )
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$clause}" ) );

		$per_page = max( 1, (int) $args['per_page'] );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$sql  = "SELECT * FROM {$table} WHERE {$clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Figures for the strip at the top of the screen: the numbers worth
	 * knowing before reading any individual row.
	 */
	public static function totals() {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS phrases,
				SUM(enabled) AS enabled,
				SUM(links_made) AS links,
				SUM(opportunities) AS available,
				SUM(CASE WHEN links_made = 0 THEN 1 ELSE 0 END) AS idle,
				MAX(links_made) AS busiest
			FROM {$table}",
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();

		$links   = (int) ( $row['links'] ?? 0 );
		$busiest = (int) ( $row['busiest'] ?? 0 );

		return array(
			'phrases'   => (int) ( $row['phrases'] ?? 0 ),
			'enabled'   => (int) ( $row['enabled'] ?? 0 ),
			'links'     => $links,
			'available' => (int) ( $row['available'] ?? 0 ),
			'idle'      => (int) ( $row['idle'] ?? 0 ),
			'top_share' => $links ? round( ( $busiest / $links ) * 100, 1 ) : 0.0,
		);
	}

	/* ---------------------------------------------------------------------
	 * Bulk
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve whatever someone typed in a destination column: an ID, a URL
	 * on this site, or an exact page title.
	 */
	public static function resolve_target( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		if ( preg_match( '#^https?://#i', $value ) ) {
			return (int) url_to_postid( $value );
		}

		// A path on its own is how anyone would write a destination by hand,
		// and is what the import panel's own example shows.
		if ( 0 === strpos( $value, '/' ) ) {
			return (int) url_to_postid( home_url( $value ) );
		}

		$page = get_page_by_title( $value, OBJECT, array( 'page', 'post' ) );

		return $page ? (int) $page->ID : 0;
	}

	/**
	 * Add many phrases at once.
	 *
	 * One per line: phrase | destination | links per page | first | percent.
	 * Only the first two are required, because the rest have sensible
	 * defaults and asking for five fields per line would defeat the point.
	 *
	 * @return array Per-line result: line, ok, message.
	 */
	public static function add_many( $text ) {
		$results = array();
		$lines   = preg_split( '/
|
|
/', (string) $text );

		foreach ( $lines as $raw ) {
			$raw = trim( $raw );

			if ( '' === $raw || 0 === strpos( $raw, '#' ) ) {
				continue;
			}

			// Comma is too common inside a phrase to use as the separator.
			$parts  = array_map( 'trim', explode( '|', $raw ) );
			$phrase = isset( $parts[0] ) ? $parts[0] : '';
			$target = self::resolve_target( isset( $parts[1] ) ? $parts[1] : '' );

			if ( ! $target ) {
				$results[] = array(
					'line'    => $raw,
					'ok'      => false,
					'message' => __( 'No destination on this site matched. Use the numeric ID, a URL, or the exact title.', 'dos-toolkit' ),
				);

				continue;
			}

			$result = self::add(
				$phrase,
				$target,
				array(
					'max_per_page'   => isset( $parts[2] ) && '' !== $parts[2] ? (int) $parts[2] : 1,
					'first_instance' => isset( $parts[3] ) && 'skip' === strtolower( $parts[3] ) ? 'skip' : 'link',
					'throttle'       => isset( $parts[4] ) && '' !== $parts[4] ? (int) $parts[4] : 100,
				)
			);

			$results[] = array(
				'line'    => $raw,
				'ok'      => ! is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Added.', 'dos-toolkit' ),
			);
		}

		return $results;
	}

	public static function bulk( $action, array $ids ) {
		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( ! $ids ) {
			return 0;
		}

		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'enable':
					self::set_enabled( $id, true );
					break;

				case 'disable':
					self::set_enabled( $id, false );
					break;

				case 'delete':
					self::delete( $id );
					break;
			}
		}

		return count( $ids );
	}

	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------- */

	/**
	 * @return int|WP_Error
	 */
	public static function add( $phrase, $target_id, $args = array() ) {
		global $wpdb;

		$phrase = trim( preg_replace( '/\s+/u', ' ', (string) $phrase ) );

		if ( '' === $phrase ) {
			return new WP_Error( 'dos_links_empty', __( 'Enter a keyword phrase.', 'dos-toolkit' ) );
		}

		// A single word is too blunt to link on: it will appear everywhere,
		// in every sense, and the resulting links say nothing about the page
		// they point at.
		if ( count( preg_split( '/\s+/u', $phrase ) ) < 2 ) {
			return new WP_Error( 'dos_links_one_word', __( 'Use a phrase of at least two words. A single word matches too much to be a useful link.', 'dos-toolkit' ) );
		}

		if ( strlen( $phrase ) > 191 ) {
			return new WP_Error( 'dos_links_long', __( 'That phrase is too long.', 'dos-toolkit' ) );
		}

		$target_id = (int) $target_id;

		if ( ! $target_id || ! get_post( $target_id ) ) {
			return new WP_Error( 'dos_links_target', __( 'Choose a page for the phrase to link to.', 'dos-toolkit' ) );
		}

		if ( 'publish' !== get_post_status( $target_id ) ) {
			return new WP_Error( 'dos_links_unpublished', __( 'That destination is not published, so the links would point at nothing.', 'dos-toolkit' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'max_per_page'   => 1,
				'first_instance' => 'link',
				'throttle'       => 100,
			)
		);

		$data = array(
			'phrase'         => $phrase,
			'target_id'      => $target_id,
			'max_per_page'   => max( 1, (int) $args['max_per_page'] ),
			'first_instance' => 'skip' === $args['first_instance'] ? 'skip' : 'link',
			'throttle'       => max( 0, min( 100, (int) $args['throttle'] ) ),
			'created'        => current_time( 'mysql' ),
		);

		$existing = self::by_phrase( $phrase );

		if ( $existing ) {
			unset( $data['created'] );

			$wpdb->update( self::table(), $data, array( 'id' => (int) $existing['id'] ) );

			return (int) $existing['id'];
		}

		$wpdb->insert( self::table(), $data );

		return (int) $wpdb->insert_id;
	}

	public static function by_phrase( $phrase ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE phrase = %s", $phrase ), ARRAY_A );
	}

	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function set_enabled( $id, $enabled ) {
		global $wpdb;

		$wpdb->update( self::table(), array( 'enabled' => $enabled ? 1 : 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	/* ---------------------------------------------------------------------
	 * Statistics
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $rule_id Reset one rule's figures, or 0 for all of them.
	 */
	public static function reset_stats( $rule_id = 0 ) {
		global $wpdb;

		$table = self::table();
		$reset = "opportunities = 0, links_made = 0, pages_linked = 0, found_in = 0, last_reason = ''";

		if ( $rule_id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$reset} WHERE id = %d", (int) $rule_id ) );

			return;
		}

		$wpdb->query( "UPDATE {$table} SET {$reset}" );
	}

	/**
	 * Record why a phrase produced no links, so the dashboard can say so.
	 * Only the first reason seen is kept: it is a hint, not an audit.
	 */
	public static function set_reason( $rule_id, $reason ) {
		global $wpdb;

		$table = self::table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET last_reason = %s WHERE id = %d AND last_reason = ''",
				substr( (string) $reason, 0, 20 ),
				(int) $rule_id
			)
		);
	}

	public static function add_found( $rule_id, $count ) {
		global $wpdb;

		$table = self::table();

		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET found_in = found_in + %d WHERE id = %d", (int) $count, (int) $rule_id )
		);
	}

	/**
	 * A sentence explaining a rule that produced nothing.
	 */
	public static function explain( array $rule ) {
		if ( (int) $rule['links_made'] || (int) $rule['opportunities'] ) {
			return '';
		}

		if ( ! $rule['scanned_at'] ) {
			return __( 'Not scanned yet. Run “Scan for link opportunities”.', 'dos-toolkit' );
		}

		if ( (int) $rule['found_in'] < 1 ) {
			return __( 'This phrase does not appear in the readable text of any published page — or every occurrence sits in a heading, bold text, a list, a table, an existing link or a shortcode, where links are never placed.', 'dos-toolkit' );
		}

		switch ( $rule['last_reason'] ) {
			case 'self':
				return __( 'The only page containing this phrase is the page it points at, and a page never links to itself. Point it somewhere else.', 'dos-toolkit' );

			case 'first_only':
				return __( 'The phrase appears once on each page that has it, and this rule is set to leave the first occurrence alone — so there is never a second one to link. Switch it to link the first occurrence.', 'dos-toolkit' );

			case 'throttled':
				return __( 'The phrase was found, but the percentage limit excluded every occurrence. Raise it towards 100%.', 'dos-toolkit' );

			default:
				return __( 'The phrase was found but every occurrence was excluded by this rule\'s limits.', 'dos-toolkit' );
		}
	}

	public static function add_stats( $rule_id, $opportunities, $links_made, $pages ) {
		global $wpdb;

		$table = self::table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET opportunities = opportunities + %d, links_made = links_made + %d, pages_linked = pages_linked + %d, scanned_at = %s WHERE id = %d",
				(int) $opportunities,
				(int) $links_made,
				(int) $pages,
				current_time( 'mysql' ),
				(int) $rule_id
			)
		);
	}

	/**
	 * Share of all links this module has placed that use one phrase.
	 *
	 * The number to watch: a profile where one phrase accounts for most of
	 * the internal links is the thing the throttle exists to prevent.
	 */
	public static function share( $rule, array $all ) {
		$total = 0;

		foreach ( $all as $row ) {
			$total += (int) $row['links_made'];
		}

		if ( ! $total ) {
			return 0.0;
		}

		return round( ( (int) $rule['links_made'] / $total ) * 100, 1 );
	}
}
