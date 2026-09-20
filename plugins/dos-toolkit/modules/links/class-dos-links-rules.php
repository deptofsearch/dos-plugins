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

	public static function reset_stats() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "UPDATE {$table} SET opportunities = 0, links_made = 0, pages_linked = 0, found_in = 0, last_reason = ''" );
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
