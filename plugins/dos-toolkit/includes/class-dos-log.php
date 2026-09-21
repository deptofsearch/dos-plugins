<?php
/**
 * Audit trail. Anything that changes site data writes a row here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Log {

	const DB_VERSION = '1';

	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install_table' ) );
	}

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'dos_log';
	}


	/**
	 * The stored version says this table was created once. It does not say
	 * the table is there now. A restore from a partial backup, a move between
	 * hosts, a `dbDelta` that failed quietly — each leaves the flag set and
	 * the table gone, and every screen reading it then reports an empty
	 * result rather than a missing table. Empty is a plausible answer, which
	 * is what makes it the wrong one to give.
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();

		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	public static function maybe_install_table() {
		if ( DOS_Settings::get( 'log_db_version' ) === self::DB_VERSION && self::table_exists() ) {
			return;
		}

		self::install_table();
	}

	public static function install_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at datetime NOT NULL,
			module varchar(40) NOT NULL DEFAULT '',
			action varchar(60) NOT NULL DEFAULT '',
			message text NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			dry_run tinyint(1) NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY module (module),
			KEY logged_at (logged_at)
		) {$collate};";

		dbDelta( $sql );

		DOS_Settings::set( 'log_db_version', self::DB_VERSION );
	}

	public static function add( $module, $action, $message = '', $object_id = 0, $dry_run = false ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'logged_at' => current_time( 'mysql' ),
				'module'    => substr( (string) $module, 0, 40 ),
				'action'    => substr( (string) $action, 0, 60 ),
				'message'   => (string) $message,
				'object_id' => (int) $object_id,
				'dry_run'   => $dry_run ? 1 : 0,
				'user_id'   => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);
	}

	public static function recent( $limit = 200, $module = '' ) {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 1000, (int) $limit ) );

		if ( $module ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE module = %s ORDER BY id DESC LIMIT %d", $module, $limit )
			);
		}

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	public static function clear() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Trim to the retention window. Called after batch runs so the table does
	 * not grow without bound on sites with large media libraries.
	 */
	public static function prune() {
		global $wpdb;

		$days = (int) DOS_Settings::get( 'log_retention_days', 90 );

		if ( $days < 1 ) {
			return;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %s", $cutoff ) );
	}
}
