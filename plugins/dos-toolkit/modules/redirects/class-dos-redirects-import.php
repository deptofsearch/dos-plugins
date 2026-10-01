<?php
/**
 * Importing rules from the Redirection plugin.
 *
 * Reads Redirection's tables directly, so it works whether that plugin is
 * still active or already deactivated, and never changes them. Only the rules
 * that mean the same thing here come across: an enabled, exact-URL rule that
 * redirects. Everything else is skipped and named, because the alternatives
 * are worse — importing a regex as a literal path would match nothing, and
 * importing a login-only rule as a plain one would redirect people it never
 * applied to.
 *
 * Paths are normalised, targets cleaned and loops refused by the store's own
 * methods, so an imported rule is held to exactly what a hand-typed one is.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Redirects_Import {

	public static function items_table() {
		global $wpdb;

		return $wpdb->prefix . 'redirection_items';
	}

	private static function groups_table() {
		global $wpdb;

		return $wpdb->prefix . 'redirection_groups';
	}

	private static function table_exists( $table ) {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * Every row is examined, not only the importable ones, so each skipped
	 * rule gets a note. The source table is never modified, so offsets stay
	 * valid for the whole run.
	 */
	public static function count() {
		global $wpdb;

		$table = self::items_table();

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Decide what to do with one Redirection row, without touching the store.
	 *
	 * @param array  $row          A row of redirection_items.
	 * @param string $group_status Status of the row's group; a rule in a
	 *                             disabled group never ran, so it is not
	 *                             switched on by importing it.
	 * @return array import (bool), note (string, '' when there is nothing to
	 *               say), and for an import: source, target, code.
	 */
	public static function classify( array $row, $group_status = 'enabled' ) {
		$id     = (int) $row['id'];
		$url    = (string) $row['url'];
		$target = (string) $row['action_data'];
		$code   = (int) $row['action_code'];

		if ( 'enabled' !== (string) $row['status'] ) {
			/* translators: 1: Redirection item ID, 2: source path */
			return self::skip( sprintf( __( 'Redirection item %1$d (%2$s): disabled, so not imported.', 'dos-toolkit' ), $id, $url ) );
		}

		if ( 'enabled' !== (string) $group_status ) {
			/* translators: 1: Redirection item ID, 2: source path */
			return self::skip( sprintf( __( 'Redirection item %1$d (%2$s): its group is disabled in Redirection, so it was not running. Not imported.', 'dos-toolkit' ), $id, $url ) );
		}

		if ( ! empty( $row['regex'] ) ) {
			/* translators: 1: Redirection item ID, 2: source pattern, 3: target */
			return self::skip( sprintf( __( 'Redirection item %1$d: regex rule, not imported because redirects here match exact paths only. Pattern %2$s goes to %3$s — rewrite it by hand as one rule per path.', 'dos-toolkit' ), $id, $url, $target ) );
		}

		if ( 'url' !== (string) $row['match_type'] ) {
			/* translators: 1: Redirection item ID, 2: source path, 3: match type */
			return self::skip( sprintf( __( 'Redirection item %1$d (%2$s): uses the "%3$s" match type, which depends on something other than the URL and has no equivalent here. Not imported.', 'dos-toolkit' ), $id, $url, (string) $row['match_type'] ) );
		}

		if ( 'url' !== (string) $row['action_type'] ) {
			/* translators: 1: Redirection item ID, 2: source path, 3: action type */
			return self::skip( sprintf( __( 'Redirection item %1$d (%2$s): action type "%3$s" is not a redirect to a URL. Not imported.', 'dos-toolkit' ), $id, $url, (string) $row['action_type'] ) );
		}

		// The store drops the query string from a path and carries the
		// visitor's own across, so a rule for /page?id=1 would become a rule
		// for every /page.
		if ( false !== strpos( $url, '?' ) ) {
			/* translators: 1: Redirection item ID, 2: source URL */
			return self::skip( sprintf( __( 'Redirection item %1$d (%2$s): matches on a query string. Redirects here match the path only, so importing it would redirect the whole page. Not imported.', 'dos-toolkit' ), $id, $url ) );
		}

		$note = '';

		if ( ! in_array( $code, DOS_Redirects_Store::CODES, true ) ) {
			/* translators: 1: Redirection item ID, 2: status code */
			$note = sprintf( __( 'Redirection item %1$d: status %2$d is not supported here, imported as 301.', 'dos-toolkit' ), $id, $code );
			$code = 301;
		}

		return array(
			'import' => true,
			'note'   => $note,
			'source' => $url,
			'target' => $target,
			'code'   => $code,
		);
	}

	private static function skip( $note ) {
		return array(
			'import' => false,
			'note'   => $note,
		);
	}

	/**
	 * @return array group id => status. Empty when Redirection has no groups
	 *               table, in which case every group is treated as enabled.
	 */
	private static function group_statuses( array $ids ) {
		global $wpdb;

		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$table = self::groups_table();

		if ( ! $ids || ! self::table_exists( $table ) ) {
			return array();
		}

		$in   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE id IN ( {$in} )", $ids ), ARRAY_A );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['status'];
		}

		return $out;
	}

	/**
	 * One pass of the job.
	 *
	 * @return array processed, changed, notes, imported (rules written, or
	 *               that would be, as "source -> target (code)" strings).
	 */
	public static function run( $offset, $size, $dry_run ) {
		global $wpdb;

		$table = self::items_table();

		if ( ! self::table_exists( $table ) ) {
			return array(
				'processed' => 0,
				'changed'   => 0,
				'notes'     => array(
					/* translators: %s: database table name */
					sprintf( __( 'The table %s does not exist, so there is nothing to import. Redirection may never have been installed here, or its data was removed.', 'dos-toolkit' ), $table ),
				),
				'imported'  => array(),
			);
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", (int) $size, (int) $offset ),
			ARRAY_A
		);

		$groups   = self::group_statuses( wp_list_pluck( $rows, 'group_id' ) );
		$notes    = array();
		$imported = array();
		$skipped  = 0;

		foreach ( $rows as $row ) {
			$group  = isset( $row['group_id'], $groups[ (int) $row['group_id'] ] ) ? $groups[ (int) $row['group_id'] ] : 'enabled';
			$result = self::classify( $row, $group );

			if ( $result['note'] ) {
				$notes[] = $result['note'];
			}

			if ( ! $result['import'] ) {
				$skipped++;
				continue;
			}

			$id = (int) $row['id'];

			// add() would update an existing rule in place. An importer must
			// not change a rule somebody already made here.
			if ( DOS_Redirects_Store::find( $result['source'] ) ) {
				$skipped++;
				/* translators: 1: Redirection item ID, 2: source path */
				$notes[] = sprintf( __( 'Redirection item %1$d (%2$s): a redirect for that path already exists here, left alone.', 'dos-toolkit' ), $id, $result['source'] );
				continue;
			}

			$checked = DOS_Redirects_Store::validate( $result['source'], $result['target'] );

			if ( is_wp_error( $checked ) ) {
				$skipped++;
				/* translators: 1: Redirection item ID, 2: source path, 3: why it was refused */
				$notes[] = sprintf( __( 'Redirection item %1$d (%2$s): refused. %3$s', 'dos-toolkit' ), $id, $result['source'], $checked->get_error_message() );
				continue;
			}

			if ( ! $dry_run ) {
				$added = DOS_Redirects_Store::add( $result['source'], $result['target'], $result['code'], 'redirection' );

				if ( is_wp_error( $added ) ) {
					$skipped++;
					/* translators: 1: Redirection item ID, 2: source path, 3: why it was refused */
					$notes[] = sprintf( __( 'Redirection item %1$d (%2$s): refused. %3$s', 'dos-toolkit' ), $id, $result['source'], $added->get_error_message() );
					continue;
				}
			}

			$imported[] = sprintf( '%s -> %s (%d)', $checked[0], $checked[1], $result['code'] );
		}

		$notes[] = sprintf(
			/* translators: 1: rows examined, 2: rules imported or that would be, 3: rows skipped */
			$dry_run
				? __( 'This pass: %1$d Redirection rules examined. Would import %2$d; %3$d skipped.', 'dos-toolkit' )
				: __( 'This pass: %1$d Redirection rules examined. Imported %2$d; %3$d skipped.', 'dos-toolkit' ),
			count( $rows ),
			count( $imported ),
			$skipped
		);

		return array(
			'processed' => count( $rows ),
			'changed'   => count( $imported ),
			'notes'     => $notes,
			'imported'  => $imported,
		);
	}
}
