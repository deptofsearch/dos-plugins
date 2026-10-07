<?php
/**
 * Cached index of cities that have a linked landing page; feeds the city search box and GET /osn/v1/cities.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class City_Index {

	const TRANSIENT = 'osn_city_index_v2';

	public static function hooks() {
		// The index lists cities with a linked landing page: flush when a city term or its landing link changes,
		// and (from the upsert) when a venue is created or changes status or city.
		add_action( 'created_' . Data_Model::CITY, array( __CLASS__, 'flush' ) );
		add_action( 'edited_' . Data_Model::CITY, array( __CLASS__, 'flush' ) );
		add_action( 'delete_' . Data_Model::CITY, array( __CLASS__, 'flush' ) );
		add_action( 'added_term_meta', array( __CLASS__, 'on_term_meta' ), 10, 3 );
		add_action( 'updated_term_meta', array( __CLASS__, 'on_term_meta' ), 10, 3 );
		// Manual edits in wp-admin: keep each city's cached sorted venue list honest.
		add_action(
			'transition_post_status',
			function ( $new, $old, $post ) {
				if ( Data_Model::POST_TYPE === $post->post_type && $new !== $old ) {
					self::purge_post_cities( $post->ID );
				}
			},
			10,
			3
		);
		add_action(
			'before_delete_post',
			function ( $post_id ) {
				if ( Data_Model::POST_TYPE === get_post_type( $post_id ) ) {
					self::purge_post_cities( $post_id );
				}
			}
		);
		add_action(
			'set_object_terms',
			function ( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
				if ( Data_Model::CITY === $taxonomy && array_diff( (array) $tt_ids, (array) $old_tt_ids ) ) {
					foreach ( array_merge( (array) $tt_ids, (array) $old_tt_ids ) as $tt ) {
						$t = get_term_by( 'term_taxonomy_id', (int) $tt, Data_Model::CITY );
						if ( $t ) {
							Util::purge_sorted( $t->term_id );
						}
					}
				}
			},
			10,
			6
		);
		add_action(
			'transition_post_status',
			function ( $new, $old, $post ) {
				if ( 'page' === $post->post_type && ( 'publish' === $new || 'publish' === $old ) ) {
					self::flush();
				}
			},
			10,
			3
		);
	}

	public static function on_term_meta( $meta_id, $term_id, $key ) {
		if ( 'osn_landing_page_id' === $key || 'osn_state' === $key ) {
			self::flush();
		}
	}

	private static function purge_post_cities( $post_id ) {
		$terms = get_the_terms( $post_id, Data_Model::CITY );
		foreach ( ( $terms && ! is_wp_error( $terms ) ) ? $terms : array() as $t ) {
			Util::purge_sorted( $t->term_id );
		}
	}

	public static function flush() {
		delete_transient( self::TRANSIENT );
		State_Cities::flush();
	}

	/** @return array[] Each { n: "City, ST", u: landing page URL, s: "ST" }, sorted by name; optionally one state only. */
	public static function get( $state = '' ) {
		$index = get_transient( self::TRANSIENT );
		if ( false === $index ) {
			$index = self::build();
			set_transient( self::TRANSIENT, $index, 12 * HOUR_IN_SECONDS );
		}
		$state = strtoupper( (string) $state );
		if ( '' !== $state ) {
			$index = array_values(
				array_filter(
					$index,
					function ( $c ) use ( $state ) {
						return $c['s'] === $state;
					}
				)
			);
		}
		return $index;
	}

	/** URL as a path relative to the site, e.g. /phoenix-az/. */
	public static function relative( $url ) {
		$home = untrailingslashit( home_url() );
		if ( 0 === strpos( $url, $home . '/' ) ) {
			return substr( $url, strlen( $home ) );
		}
		return $url;
	}

	public static function build() {
		$out   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => Data_Model::CITY,
				'hide_empty' => false,
				'number'     => 0,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'osn_landing_page_id',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		Util::prime_landing_pages( $terms ); // One query for every landing page instead of one per term.
		foreach ( $terms as $term ) {
			$url = Util::city_url( $term );
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'n' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'u' => esc_url_raw( $url ),
				's' => strtoupper( (string) get_term_meta( $term->term_id, 'osn_state', true ) ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strnatcasecmp( $a['n'], $b['n'] );
			}
		);
		return $out;
	}
}
