<?php
/**
 * The line at the foot of hub pages (city grids, state pages, the homepage) saying who compiles the listings and
 * where the data comes from. Venue pages already carry their own source line (Venue_Page::source_line()).
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Source_Note {

	const SITE_TRANSIENT = 'osn_site_latest_v1';

	/** @var string|null Request memo of the About page URL. */
	private static $about = null;

	public static function flush_memo() {
		self::$about = null;
	}

	/**
	 * @param string $checked YYYY-MM-DD of the newest check behind the page, or ''.
	 * @param bool   $site    True when the date is site-wide (state pages, the homepage): it is then not "last checked" for what the page lists.
	 */
	public static function html( $checked = '', $site = false ) {
		$parts = array();
		$name  = (string) apply_filters( 'osn_compiled_by', 'Ryan R.' );
		if ( '' !== $name ) {
			/* translators: %s: name of the person who compiles the directory */
			$parts[] = sprintf( __( 'Compiled by %s.', 'dos-outdoor-seating' ), $name );
		}
		$parts[] = __( "Listings come from Google Maps listing data and each venue's own website.", 'dos-outdoor-seating' );
		if ( self::valid_date( $checked ) ) {
			// Same format as Venue_Page::source_line(): the stored date is a calendar day, so no timezone shift.
			$date = wp_date( 'F j, Y', strtotime( $checked . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
			/* translators: %s: date */
			$parts[] = sprintf( $site ? __( 'Directory last updated %s.', 'dos-outdoor-seating' ) : __( 'Last checked %s.', 'dos-outdoor-seating' ), $date );
		}
		$text = esc_html( implode( ' ', $parts ) );
		$url  = (string) apply_filters( 'osn_about_url', self::about_url() );
		if ( '' !== $url ) {
			$text .= ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'How this directory is made', 'dos-outdoor-seating' ) . '</a>';
		}
		return '<p class="osn osn-source-note">' . $text . '</p>';
	}

	/** Permalink of the published page with the slug "about", or ''. */
	private static function about_url() {
		if ( null === self::$about ) {
			$page        = get_page_by_path( 'about' );
			self::$about = ( $page && 'publish' === $page->post_status ) ? (string) get_permalink( $page->ID ) : '';
		}
		return self::$about;
	}

	private static function valid_date( $s ) {
		return is_string( $s ) && (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * The newest enriched_at among these venues, '' when none has a valid one. Reads meta for these IDs only: the
	 * callers pass the cards on the page, whose meta cache is already primed.
	 *
	 * @param int[] $ids Venue IDs.
	 */
	public static function latest( array $ids ) {
		$max = '';
		foreach ( $ids as $id ) {
			$d = substr( (string) Util::meta( (int) $id, 'enriched_at' ), 0, 10 );
			if ( $d > $max && self::valid_date( $d ) ) {
				$max = $d;
			}
		}
		return $max;
	}

	/** The newest enriched_at across all published venues: one query, cached six hours. */
	public static function site_latest() {
		global $wpdb;
		$hit = get_transient( self::SITE_TRANSIENT );
		if ( is_string( $hit ) ) { // '' is a cached "none".
			return $hit;
		}
		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(LEFT(pm.meta_value, 10)) FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_status = 'publish' AND p.post_type = %s AND pm.meta_key = %s",
				Data_Model::POST_TYPE,
				Fields::key( 'enriched_at' )
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return ''; // Not cached: a failed query is not "no date".
		}
		$max = self::valid_date( (string) $max ) ? (string) $max : '';
		set_transient( self::SITE_TRANSIENT, $max, 6 * HOUR_IN_SECONDS );
		return $max;
	}
}
