<?php
/**
 * Last-updated dates.
 *
 * Two independent things, ported from the Last Updated Column plugin: a
 * sortable column in the admin list tables, and an "Updated:" line in front of
 * the published date on the front end.
 *
 * Both only appear when a post was genuinely edited after it went live.
 * WordPress records a modified time a second or two after the publish time on
 * every post, so a naive comparison marks everything as updated.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Last_Updated {

	/**
	 * How far apart the two timestamps must be before an edit counts as a
	 * real one rather than the gap WordPress leaves at publish.
	 */
	const THRESHOLD = 60;

	public static function column_enabled() {
		return (bool) DOS_Settings::get( 'utilities_updated_column', 0 );
	}

	public static function front_end_enabled() {
		return (bool) DOS_Settings::get( 'utilities_updated_front', 0 );
	}

	public static function init() {
		if ( self::column_enabled() ) {
			add_action( 'admin_init', array( __CLASS__, 'register_columns' ) );
			add_action( 'pre_get_posts', array( __CLASS__, 'sort' ) );
		}

		if ( self::front_end_enabled() ) {
			// the_date passes ( $the_date, $format, $before, $after );
			// get_the_date passes ( $the_date, $format, $post ). Sharing one
			// callback between them, as the original did, meant $before
			// arrived where a post was expected and the_date silently never
			// worked on any theme that passed one.
			add_filter( 'the_date', array( __CLASS__, 'filter_the_date' ), 10, 2 );
			add_filter( 'get_the_date', array( __CLASS__, 'filter_get_the_date' ), 10, 3 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Was it really updated
	 * ------------------------------------------------------------------- */

	public static function was_updated( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return false;
		}

		$published = strtotime( $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : $post->post_date );
		$modified  = strtotime( $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified );

		if ( ! $published || ! $modified ) {
			return false;
		}

		return ( $modified - $published ) > self::THRESHOLD;
	}

	/* ---------------------------------------------------------------------
	 * Admin column
	 * ------------------------------------------------------------------- */

	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );

		unset( $types['attachment'] );

		return apply_filters( 'dos_toolkit_last_updated_post_types', array_values( $types ) );
	}

	public static function register_columns() {
		foreach ( self::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( __CLASS__, 'sortable' ) );
		}
	}

	/**
	 * Immediately left of the built-in Published column, so the two dates
	 * read together.
	 */
	public static function add_column( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['dos_last_updated'] = __( 'Last Updated', 'dos-toolkit' );
			}

			$out[ $key ] = $label;
		}

		if ( ! isset( $out['dos_last_updated'] ) ) {
			$out['dos_last_updated'] = __( 'Last Updated', 'dos-toolkit' );
		}

		return $out;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'dos_last_updated' !== $column ) {
			return;
		}

		if ( ! self::was_updated( $post_id ) ) {
			echo '&#8212;';

			return;
		}

		$post = get_post( $post_id );

		printf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( get_post_modified_time( 'Y/m/d g:i:s a', false, $post ) ),
			esc_html( get_post_modified_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), false, $post ) )
		);
	}

	public static function sortable( $columns ) {
		$columns['dos_last_updated'] = 'dos_last_updated';

		return $columns;
	}

	public static function sort( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'dos_last_updated' === $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'modified' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------- */

	/**
	 * A date format meant for a machine rather than a reader.
	 *
	 * Prepending "Updated:" to one of these turns a timestamp into prose, and
	 * whatever consumes it — structured data, a feed, an Open Graph tag —
	 * gets something it cannot parse. The SEO module reads its dates through
	 * get_post_time(), which this filter never sees, but nothing stops
	 * another plugin asking get_the_date() for an ISO timestamp.
	 */
	public static function is_machine_format( $format ) {
		$format = (string) $format;

		if ( '' === $format ) {
			return false;
		}

		if ( in_array( $format, array( DATE_W3C, DATE_ATOM, DATE_RFC3339, 'c', 'U', 'Y-m-d', 'Y-m-d H:i:s' ), true ) ) {
			return true;
		}

		// A timezone offset or an escaped ISO separator means this is being
		// serialised, not shown to anyone.
		return (bool) preg_match( '/\\\\T|[cUOPTe]/', $format );
	}

	private static function label( $post, $format ) {
		$format = $format ? $format : get_option( 'date_format' );

		return sprintf(
			/* translators: %s: the date a post was last updated */
			__( 'Updated: %s', 'dos-toolkit' ),
			get_post_modified_time( $format, false, $post )
		);
	}

	private static function decorate( $the_date, $format, $post ) {
		if ( is_admin() || is_feed() || ! is_singular() ) {
			return $the_date;
		}

		if ( self::is_machine_format( $format ) ) {
			return $the_date;
		}

		$post = $post ? get_post( $post ) : get_post();

		if ( ! $post || ! self::was_updated( $post ) ) {
			return $the_date;
		}

		return '<span class="dos-updated-date">' . esc_html( self::label( $post, $format ) ) . '</span> | ' . $the_date;
	}

	public static function filter_the_date( $the_date, $format ) {
		return self::decorate( $the_date, $format, null );
	}

	public static function filter_get_the_date( $the_date, $format, $post ) {
		return self::decorate( $the_date, $format, $post );
	}
}
