<?php
/**
 * wp-admin only: Lenders-count column, "No lenders" view and State filter on the city list table.
 * Nothing here runs on the front end.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const COUNT_KEY = 'blnm_lender_count';

	public static function hooks() {
		if ( ! is_admin() ) {
			return;
		}
		$pt = Data_Model::CITY;
		add_filter( "manage_{$pt}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( "manage_edit-{$pt}_sortable_columns", array( __CLASS__, 'sortable' ) );
		add_filter( "views_edit-{$pt}", array( __CLASS__, 'views' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'state_dropdown' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'query' ) );
		add_action( 'load-edit.php', array( __CLASS__, 'backfill' ) );
	}

	private static function on_city_list() {
		return isset( $_GET['post_type'] ) && Data_Model::CITY === sanitize_key( wp_unslash( $_GET['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function columns( $cols ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $cols;
		}
		$out = array();
		foreach ( $cols as $k => $label ) {
			$out[ $k ] = $label;
			if ( 'title' === $k ) {
				$out['blnm_lenders'] = esc_html__( 'Lenders', 'dos-best-lenders' );
				$out['blnm_area']    = esc_html__( 'County / nearby towns', 'dos-best-lenders' );
			}
		}
		return $out;
	}

	public static function sortable( $cols ) {
		$cols['blnm_lenders'] = array( 'blnm_lenders', true );
		return $cols;
	}

	/** Stored count, computed from the lender JSON and saved if the meta is missing. */
	public static function count_for( $post_id ) {
		if ( metadata_exists( 'post', $post_id, self::COUNT_KEY ) ) {
			return (int) get_post_meta( $post_id, self::COUNT_KEY, true );
		}
		$n = count( Data_Model::city_lenders( $post_id ) );
		update_post_meta( $post_id, self::COUNT_KEY, $n );
		return $n;
	}

	public static function column( $col, $post_id ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( 'blnm_lenders' === $col ) {
			$n = self::count_for( $post_id );
			echo $n ? esc_html( number_format_i18n( $n ) ) : '<strong style="color:#b32d2e">0</strong>';
		} elseif ( 'blnm_area' === $col ) {
			$county = (string) get_post_meta( $post_id, 'blnm_county_name', true );
			$state  = (string) get_post_meta( $post_id, 'blnm_state', true );
			echo esc_html( trim( ( '' !== $county ? $county . ' County' : '' ) . ( '' !== $state ? ', ' . $state : '' ), ', ' ) );
			if ( 0 === self::count_for( $post_id ) ) {
				$bits = array();
				foreach ( Data_Model::city_nearby( $post_id ) as $t ) {
					$bits[] = trim( $t['city'] . ( $t['state'] ? ', ' . $t['state'] : '' ) );
				}
				echo '<br><small>' . ( $bits ? esc_html( sprintf( /* translators: %s: towns */ __( 'Nearby: %s', 'dos-best-lenders' ), implode( '; ', $bits ) ) ) : esc_html__( 'No nearby towns suggested', 'dos-best-lenders' ) ) . '</small>';
			}
		}
	}

	/** Backfill missing count meta for cities (capped per page load) before the table queries it. */
	public static function backfill() {
		if ( ! self::on_city_list() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => Data_Model::CITY,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => self::COUNT_KEY, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			self::count_for( $id );
		}
	}

	private static function zero_query_args() {
		return array(
			'post_type'  => Data_Model::CITY,
			'meta_query' => array( array( 'key' => self::COUNT_KEY, 'value' => 0, 'type' => 'NUMERIC', 'compare' => '=' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		);
	}

	public static function views( $views ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $views;
		}
		$q = new \WP_Query(
			array_merge(
				self::zero_query_args(),
				array(
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			)
		);
		$current = ! empty( $_GET['blnm_zero'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$url     = add_query_arg( array( 'post_type' => Data_Model::CITY, 'blnm_zero' => 1 ), admin_url( 'edit.php' ) );
		$views['blnm_zero'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'No lenders', 'dos-best-lenders' ),
			esc_html( number_format_i18n( (int) $q->found_posts ) )
		);
		return $views;
	}

	public static function state_dropdown( $post_type, $which = 'top' ) {
		if ( Data_Model::CITY !== $post_type || 'top' !== $which || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		global $wpdb;
		$states = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value", 'blnm_state' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $states ) {
			return;
		}
		$cur = isset( $_GET['blnm_state'] ) ? Data_Model::sanitize_state( wp_unslash( $_GET['blnm_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="blnm_state"><option value="">' . esc_html__( 'All states', 'dos-best-lenders' ) . '</option>';
		foreach ( $states as $s ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $s ), selected( $cur, $s, false ) );
		}
		echo '</select>';
	}

	public static function query( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || Data_Model::CITY !== $q->get( 'post_type' ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$meta = (array) $q->get( 'meta_query' );
		if ( ! empty( $_GET['blnm_zero'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$meta[] = self::zero_query_args()['meta_query'][0];
		}
		if ( ! empty( $_GET['blnm_state'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$st = Data_Model::sanitize_state( wp_unslash( $_GET['blnm_state'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' !== $st ) {
				$meta[] = array( 'key' => 'blnm_state', 'value' => $st );
			}
		}
		if ( $meta ) {
			$q->set( 'meta_query', $meta );
		}
		if ( 'blnm_lenders' === $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', self::COUNT_KEY );
			$q->set( 'orderby', 'meta_value_num' );
		}
	}
}
