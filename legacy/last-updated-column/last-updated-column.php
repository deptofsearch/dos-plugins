<?php
/**
 * Plugin Name: Last Updated Column
 * Description: Shows the last-modified date/time for posts and pages, in wp-admin list tables (to the left of the Published Date column) and on the public-facing single post/page display (next to the published date). Only shown when a post/page has actually been edited after it was published.
 * Version: 1.0.0
 * Author: Ryan Rose
 * License: GPL v2 or later
 * Text Domain: last-updated-column
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class LUC_Last_Updated_Column {

	/**
	 * Minimum gap (in seconds) between publish time and modified time
	 * before we consider a post "actually updated". Guards against the
	 * few-second difference WordPress records on initial publish.
	 */
	const UPDATED_THRESHOLD = 60;

	public function __construct() {
		add_action( 'init', array( $this, 'register_admin_columns' ) );

		add_filter( 'the_date', array( $this, 'filter_the_date' ), 10, 4 );
		add_filter( 'get_the_date', array( $this, 'filter_the_date' ), 10, 3 );
	}

	/**
	 * Hook the admin list-table columns for every public post type
	 * (this naturally covers both "post" and "page").
	 */
	public function register_admin_columns() {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'make_column_sortable' ) );
		}

		add_action( 'pre_get_posts', array( $this, 'sort_by_column' ) );
	}

	/**
	 * Insert a "Last Updated" column immediately to the left of the
	 * built-in "date" (Published) column.
	 */
	public function add_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new_columns['last_updated'] = __( 'Last Updated', 'last-updated-column' );
			}
			$new_columns[ $key ] = $label;
		}

		// Fallback: if there was no "date" column for some reason, append ours.
		if ( ! isset( $new_columns['last_updated'] ) ) {
			$new_columns['last_updated'] = __( 'Last Updated', 'last-updated-column' );
		}

		return $new_columns;
	}

	public function render_column( $column, $post_id ) {
		if ( 'last_updated' !== $column ) {
			return;
		}

		if ( ! $this->was_updated( $post_id ) ) {
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

	public function make_column_sortable( $columns ) {
		$columns['last_updated'] = 'last_updated';
		return $columns;
	}

	public function sort_by_column( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'last_updated' === $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'modified' );
		}
	}

	/**
	 * Prepend "Updated: <date> | " directly in front of whatever the
	 * published-date output is, wherever the active theme calls
	 * the_date() / get_the_date() for a singular post or page. Only
	 * fires when the post was actually edited after publishing.
	 */
	public function filter_the_date( $the_date, $format, $post = null ) {
		if ( is_admin() || ! is_singular( array( 'post', 'page' ) ) ) {
			return $the_date;
		}

		$post = $post ? get_post( $post ) : get_post();

		if ( ! $post || ! $this->was_updated( $post->ID ) ) {
			return $the_date;
		}

		$format   = $format ? $format : get_option( 'date_format' );
		$updated  = get_post_modified_time( $format, false, $post );

		$label = sprintf(
			/* translators: %s: last updated date */
			__( 'Updated: %s', 'last-updated-column' ),
			$updated
		);

		return '<span class="luc-updated-date">' . esc_html( $label ) . '</span> | ' . $the_date;
	}

	/**
	 * True when the post's modified time is meaningfully later than its
	 * publish time (i.e. it was actually edited after going live).
	 */
	private function was_updated( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		$published = strtotime( $post->post_date_gmt ? $post->post_date_gmt : $post->post_date );
		$modified  = strtotime( $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified );

		return ( $modified - $published ) > self::UPDATED_THRESHOLD;
	}
}

new LUC_Last_Updated_Column();
