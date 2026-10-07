<?php
/**
 * Post type, taxonomies, term meta, post meta, activation seed.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Data_Model {

	const POST_TYPE = 'osn_venue';
	const CITY      = 'osn_city';
	const AMENITY   = 'osn_amenity';
	const CATEGORY  = 'osn_category';
	const HOOD      = 'osn_hood';
	const LANDMARK  = 'osn_landmark';

	/** slug => display name. Seeded on activation. */
	const AMENITIES = array(
		'outdoor-seating'      => 'Outdoor Seating',
		'rooftop'              => 'Rooftop',
		'waterfront'           => 'Waterfront',
		'dog-friendly'         => 'Dog Friendly',
		'kid-friendly'         => 'Kid Friendly',
		'live-music'           => 'Live Music',
		'happy-hour'           => 'Happy Hour',
		'sports-on-tv'         => 'Sports on TV',
		'reservations'         => 'Reservations',
		'heated-patio'         => 'Heated Patio',
		'fire-pit'             => 'Fire Pit',
		'cocktails'            => 'Cocktails',
		'beer'                 => 'Beer',
		'wine'                 => 'Wine',
		'brunch'               => 'Brunch',
		'breakfast'            => 'Breakfast',
		'lunch'                => 'Lunch',
		'dinner'               => 'Dinner',
		'vegetarian'           => 'Vegetarian',
		'takeout'              => 'Takeout',
		'delivery'             => 'Delivery',
		'wheelchair-accessible' => 'Wheelchair Accessible',
	);

	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Venues', 'dos-outdoor-seating' ),
					'singular_name' => __( 'Venue', 'dos-outdoor-seating' ),
					'add_new_item'  => __( 'Add New Venue', 'dos-outdoor-seating' ),
					'edit_item'     => __( 'Edit Venue', 'dos-outdoor-seating' ),
					'menu_name'     => __( 'Venues', 'dos-outdoor-seating' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'venues',
				'has_archive'  => false,
				'menu_icon'    => 'dashicons-store',
				'rewrite'      => array( 'slug' => 'restaurants', 'with_front' => false ),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
				'taxonomies'   => array( self::CITY, self::AMENITY, self::CATEGORY, self::HOOD, self::LANDMARK ),
			)
		);

		$tax = array(
			self::CITY     => array( 'Cities', 'City', 'cities' ),
			self::AMENITY  => array( 'Amenities', 'Amenity', 'amenities' ),
			self::CATEGORY => array( 'Categories', 'Category', 'venue-categories' ),
		);
		foreach ( $tax as $slug => $l ) {
			register_taxonomy(
				$slug,
				self::POST_TYPE,
				array(
					'labels'            => array(
						'name'          => $l[0],
						'singular_name' => $l[1],
					),
					'hierarchical'       => false,
					'public'             => false,
					'publicly_queryable' => false,
					'query_var'          => false,
					'rewrite'            => false,
					'show_ui'            => true,
					'show_in_rest'       => true,
					'show_admin_column'  => true,
					'show_in_nav_menus'  => false,
				)
			);
		}

		// Neighborhoods: top-level terms are districts, children are neighborhoods (see Hoods). Not public, no archives.
		register_taxonomy(
			self::HOOD,
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Neighborhoods', 'dos-outdoor-seating' ),
					'singular_name' => __( 'Neighborhood', 'dos-outdoor-seating' ),
				),
				'hierarchical'       => true,
				'public'             => false,
				'publicly_queryable' => false,
				'query_var'          => false,
				'rewrite'            => false,
				'show_ui'            => true,
				'show_in_rest'       => false,
				'show_admin_column'  => true,
				'show_in_nav_menus'  => false,
			)
		);
		register_term_meta( self::HOOD, 'osn_city', array( 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => 'sanitize_text_field' ) );
		register_term_meta( self::HOOD, 'osn_page_id', array( 'type' => 'integer', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => 'absint' ) );
		register_term_meta( self::HOOD, 'osn_redirect', array( 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => 'esc_url_raw' ) );

		// Landmark zones (see Landmarks): flat, per-city "areas that are not neighborhoods" (a Strip, a casino district, a boardwalk).
		register_taxonomy(
			self::LANDMARK,
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Landmark Zones', 'dos-outdoor-seating' ),
					'singular_name' => __( 'Landmark Zone', 'dos-outdoor-seating' ),
				),
				'hierarchical'       => false,
				'public'             => false,
				'publicly_queryable' => false,
				'query_var'          => false,
				'rewrite'            => false,
				'show_ui'            => true,
				'show_in_rest'       => false,
				'show_admin_column'  => true,
				'show_in_nav_menus'  => false,
			)
		);
		register_term_meta( self::LANDMARK, 'osn_city', array( 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => 'sanitize_text_field' ) );
		register_term_meta( self::LANDMARK, 'osn_order', array( 'type' => 'integer', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => 'absint' ) );
		register_term_meta( self::LANDMARK, 'osn_h1', array( 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => 'sanitize_text_field' ) );
		register_term_meta( self::LANDMARK, 'osn_page_id', array( 'type' => 'integer', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => 'absint' ) );
		foreach ( array( Landmarks::CASINO_KEY, Landmarks::CASINO_SLUG_KEY ) as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}

		register_term_meta(
			self::CITY,
			'osn_state',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => function ( $v ) {
					return Fields::sanitize( 'state', $v );
				},
			)
		);
		register_term_meta(
			self::CITY,
			'osn_landing_page_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			)
		);

		foreach ( Fields::definitions() as $name => $def ) {
			register_post_meta(
				self::POST_TYPE,
				Fields::key( $name ),
				array(
					'type'              => $def[0],
					'single'            => true,
					'show_in_rest'      => ! isset( $def[2] ) || $def[2],
					'sanitize_callback' => function ( $value ) use ( $name ) {
						return Fields::sanitize( $name, $value );
					},
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/** One-time 0.1.x to 0.2.0 meta migration. Safe to re-run. */
	public static function maybe_upgrade() {
		if ( version_compare( (string) get_option( 'osn_db_version', '0' ), '0.2.0', '>=' ) ) {
			return;
		}
		global $wpdb;
		$ids = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'osn_venue'";
		$m   = $wpdb->postmeta;
		// Editorial summary becomes summary.
		$wpdb->query( "UPDATE {$m} SET meta_key = 'osn_summary' WHERE meta_key = 'osn_editorial_summary' AND post_id IN ({$ids})" ); // phpcs:ignore WordPress.DB
		// Dropped fields, the single-pass hash, and Places-shaped hours (replaced by the Maps Data shape on the next pass).
		$wpdb->query( "DELETE FROM {$m} WHERE meta_key IN ('osn_review_summary','osn_attributes_json','osn_hash') AND post_id IN ({$ids})" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$m} WHERE meta_key = 'osn_hours_json' AND meta_value LIKE '%periods%' AND post_id IN ({$ids})" ); // phpcs:ignore WordPress.DB
		update_option( 'osn_db_version', '0.2.0' );
	}

	public static function activate() {
		self::register();
		foreach ( self::AMENITIES as $slug => $name ) {
			if ( ! term_exists( $slug, self::AMENITY ) ) {
				wp_insert_term( $name, self::AMENITY, array( 'slug' => $slug ) );
			}
		}
		flush_rewrite_rules();
	}
}
