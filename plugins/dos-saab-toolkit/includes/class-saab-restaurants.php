<?php
/**
 * Restaurants module: a `saab_restaurant` post type synced by n8n from Google
 * data, with a hand-written "Ryan's Take" as the post body.
 *
 * Permalinks are root-level to match the rest of the site
 * (/atomic-ale-brewpub-richland-wa/, not /restaurants/atomic-ale-.../), so the
 * post type is registered with rewrite disabled and its URL is built and
 * matched by hand. Region archives (cuisine stays on the shared `category`
 * taxonomy) live under /restaurants/in/washington/tri-cities/richland/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Before the guard below: PHP declares this file's class before its first line runs (early binding), so that
// guard always returns and nothing after it executes.
require_once __DIR__ . '/class-saab-dishes.php';

if ( class_exists( 'SAAB_Restaurants', false ) ) {
	return;
}

class SAAB_Restaurants {

	const POST_TYPE = 'saab_restaurant';
	const TAXONOMY  = 'saab_region';

	/**
	 * Bump this to force flush_rewrite_rules() to run once more on the next
	 * request — e.g. after changing the region rewrite slug. Ordinary
	 * requests never flush; only a version mismatch does.
	 */
	const FLUSH_VERSION = '2';

	/** JSON-encoded meta keys, decoded through get_json_meta(). */
	private static $json_meta_keys = array(
		'saab_business_ids',
		'saab_locations',
		'saab_menu',
		'saab_popular_dishes',
		'saab_ryan_dishes',
		'saab_predicted_pick',
		'saab_features',
		'saab_linked_posts',
		'saab_similar',
		'saab_photo_ids',
		'saab_photo_exclude',
	);

	/** Full meta contract: key => register_post_meta() args. n8n writes most of these. */
	private static $meta_schema = array(
		'saab_business_ids'   => array( 'type' => 'string' ),
		'saab_locations'      => array( 'type' => 'string' ),
		'saab_menu'           => array( 'type' => 'string' ),
		'saab_menu_checked_at' => array( 'type' => 'string' ),
		'saab_menu_source_url' => array( 'type' => 'string' ),
		'saab_popular_dishes' => array( 'type' => 'string' ),
		'saab_ryan_dishes'    => array( 'type' => 'string' ),
		'saab_predicted_pick' => array( 'type' => 'string' ),
		'saab_rating'         => array( 'type' => 'number' ),
		'saab_review_count'   => array( 'type' => 'integer' ),
		'saab_price_range'    => array( 'type' => 'string' ),
		'saab_features'       => array( 'type' => 'string' ),
		'saab_locally_owned'  => array( 'type' => 'boolean' ),
		'saab_website'        => array( 'type' => 'string' ),
		'saab_phone'          => array( 'type' => 'string' ),
		'saab_linked_posts'   => array( 'type' => 'string' ),
		'saab_similar'        => array( 'type' => 'string' ),
		'saab_synced_at'      => array( 'type' => 'string' ),
		'saab_photo_ids'      => array( 'type' => 'string' ),
		'saab_photo_exclude'  => array( 'type' => 'string' ),
	);

	public static function init() {
		// The toolkit starts modules at init priority 0, before core has
		// registered `category` and before other plugins register their post
		// types. Register at the normal priority, and flush only once every
		// plugin's rules exist — a flush at priority 0 would drop theirs.
		add_action( 'init', array( __CLASS__, 'register' ), 10 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 999 );

		add_filter( 'post_type_link', array( __CLASS__, 'filter_permalink' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'widen_root_query' ) );
		add_filter( 'wp_unique_post_slug', array( __CLASS__, 'unique_slug_cross_type' ), 10, 6 );

		// Themify "Magazine" renders a sidebar next to the content on every
		// singular post; a restaurant page wants the full width instead. See
		// the docblock above filter_themify_layout_meta() for the three-layer
		// approach (we can't read the theme's PHP to confirm the native route
		// still works on every version).
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_themify_layout_meta' ), 10, 4 );
		add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );

		// The page heading and browser title read "Thai Elephant – Kennewick, WA".
		add_filter( 'the_title', array( __CLASS__, 'filter_heading_title' ), 10, 2 );
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_document_title' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'render_editor_note' ) );

		add_filter( 'the_content', array( __CLASS__, 'render_content' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_head', array( __CLASS__, 'render_schema' ) );

		require_once __DIR__ . '/class-saab-restaurant-browse.php';
		SAAB_Restaurant_Browse::init();

		require_once __DIR__ . '/class-saab-bestof.php';
		SAAB_Bestof::init();

		require_once __DIR__ . '/class-saab-bestof-hub.php';
		SAAB_Bestof_Hub::init();
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------- */

	public static function register() {
		self::register_post_type();
		self::register_taxonomy();
		self::register_meta();
	}

	private static function register_post_type() {
		register_post_type( self::POST_TYPE, array(
			'labels'       => array(
				'name'          => 'Restaurants',
				'singular_name' => 'Restaurant',
				'add_new_item'  => 'Add New Restaurant',
				'edit_item'     => 'Edit Restaurant',
				'view_item'     => 'View Restaurant',
				'search_items'  => 'Search Restaurants',
				'not_found'     => 'No restaurants found',
			),
			'public'       => true,
			'show_in_rest' => true,
			'rest_base'    => 'restaurants',
			// /restaurants/ is the browse-all page; its rewrite rule is added by
			// SAAB_Restaurant_Browse since core adds none with rewrite disabled.
			'has_archive'  => 'restaurants',
			'menu_icon'    => 'dashicons-food',
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
			'taxonomies'   => array( 'category', self::TAXONOMY ),
			// Permalinks are root-level and built by hand in filter_permalink() —
			// letting core generate rewrite rules here would only produce a
			// /restaurant/%postname%/ pattern nothing ever matches.
			'rewrite'      => false,
		) );
	}

	private static function register_taxonomy() {
		register_taxonomy( self::TAXONOMY, self::POST_TYPE, array(
			'labels'            => array(
				'name'          => 'Regions',
				'singular_name' => 'Region',
			),
			'hierarchical'      => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			// Terms nest State > Area > City, so an archive URL reads as
			// /restaurants/in/washington/tri-cities/richland/.
			'rewrite'           => array(
				'slug'         => 'restaurants/in',
				'hierarchical' => true,
			),
		) );
	}

	private static function register_meta() {
		foreach ( self::$meta_schema as $key => $args ) {
			$register = array(
				'type'          => $args['type'],
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			);

			if ( in_array( $key, self::$json_meta_keys, true ) ) {
				$register['sanitize_callback'] = array( __CLASS__, 'sanitize_json_meta' );
			}

			register_post_meta( self::POST_TYPE, $key, $register );
		}
	}

	/**
	 * Canonicalise through json_decode/wp_json_encode so junk never lands in
	 * the column. An unparsable payload is stored as an empty string rather
	 * than rejected outright — n8n retries are simpler against a 200 than a
	 * REST validation error.
	 */
	public static function sanitize_json_meta( $value ) {
		$decoded = json_decode( (string) $value, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return '';
		}

		// Keep non-ASCII (the en dash in "11 AM–2:30 PM") and slashes literal:
		// an escaped – loses its backslash to wp_unslash() on some save paths.
		return wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Registering the CPT changes the rewrite rules, but running
	 * flush_rewrite_rules() on every request would cost a full rule rebuild
	 * per page load. Flush once, when the stored version doesn't match —
	 * i.e. the first request after the module is enabled, or after a bump
	 * to FLUSH_VERSION.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( get_option( 'saab_restaurants_flush_version' ) === self::FLUSH_VERSION ) {
			return;
		}

		flush_rewrite_rules();
		update_option( 'saab_restaurants_flush_version', self::FLUSH_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Root-level permalinks
	 * ------------------------------------------------------------------- */

	/**
	 * Published restaurants live at the site root, same as posts and pages.
	 * Drafts and previews stay on the default ?post_type=...&p=... URL —
	 * there's no rewrite rule for them to resolve against anyway.
	 */
	public static function filter_permalink( $post_link, $post ) {
		if ( self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return $post_link;
		}

		return home_url( '/' . $post->post_name . '/' );
	}

	/**
	 * A root-level slug is matched by WordPress's existing /%postname%/
	 * rules. WP::parse_request only keeps the verbose page rule (`pagename`)
	 * when a real page exists at that path; anything else falls through to
	 * the post rule as a bare `name` query var, which WP_Query defaults to
	 * 'post' — so a restaurant at that slug 404s. Widen the post_type for
	 * that case only. `pagename` requests are real pages (including child
	 * pages like /parent/child/) and are left untouched.
	 */
	public static function widen_root_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) ) {
			return; // An explicit post_type means this isn't a bare root-slug request.
		}
		if ( '' === $query->get( 'name' ) || '' !== $query->get( 'pagename' ) ) {
			return;
		}

		$query->set( 'post_type', array( 'post', 'page', self::POST_TYPE ) );
	}

	/**
	 * Posts, pages and restaurants now share one slug namespace at the root.
	 * Core's own uniqueness check only looks within a post's own type, so a
	 * restaurant and a page could otherwise both claim /richland-diner/.
	 * Re-run the check across the other side of that namespace and suffix
	 * whichever slug loses.
	 */
	public static function unique_slug_cross_type( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( self::POST_TYPE === $post_type ) {
			$other_types = array( 'post', 'page' );
		} elseif ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
			$other_types = array( self::POST_TYPE );
		} else {
			return $slug;
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $other_types ), '%s' ) );
		$sql          = "SELECT post_name FROM $wpdb->posts WHERE post_name = %s AND post_type IN ($placeholders) AND post_status != 'trash' AND ID != %d LIMIT 1";

		$conflict = $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $slug ), $other_types, array( $post_id ) ) ) );

		if ( ! $conflict ) {
			return $slug;
		}

		$suffix = 2;
		do {
			$alt_slug = _truncate_post_slug( $original_slug, 200 - ( strlen( (string) $suffix ) + 1 ) ) . '-' . $suffix;
			$conflict = $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $alt_slug ), $other_types, array( $post_id ) ) ) );
			$suffix++;
		} while ( $conflict );

		return $alt_slug;
	}

	/* ---------------------------------------------------------------------
	 * Theme sidebar removal (Themify "Magazine")
	 * ------------------------------------------------------------------- */

	/**
	 * We can't inspect the theme's PHP, so this drops the sidebar three ways
	 * at once rather than trusting any single one:
	 *
	 * a. This filter — Themify's own per-post "Sidebar Option" (postmeta key
	 *    `layout`) is short-circuited to 'sidebar-none' for a restaurant page,
	 *    the native route the theme itself reads.
	 * b. filter_body_class() — strips the sidebar1/sidebar2 classes Themify
	 *    puts on <body> and adds 'sidebar-none' in case the theme also keys
	 *    off the body class rather than (or in addition to) the postmeta.
	 * c. restaurants.css — a plain CSS backstop (`#sidebar { display: none }`
	 *    etc.) in case neither (a) nor (b) is what this theme version
	 *    actually checks.
	 *
	 * Nothing here writes to the database; it only changes what
	 * get_post_meta( $id, 'layout', true ) returns for the duration of the
	 * request, and only for a restaurant page's own post.
	 */
	public static function filter_themify_layout_meta( $value, $object_id, $meta_key, $single ) {
		if ( is_admin() || 'layout' !== $meta_key || ! is_singular( self::POST_TYPE ) ) {
			return $value;
		}
		if ( self::POST_TYPE !== get_post_type( $object_id ) ) {
			return $value;
		}

		return $single ? 'sidebar-none' : array( 'sidebar-none' );
	}

	/**
	 * Removes Themify's sidebar1/sidebar2/sidebar-left body classes on a
	 * restaurant page and adds 'sidebar-none' plus 'saab-restaurant-page' —
	 * the latter is also what restaurants.css hooks its CSS backstop on.
	 */
	public static function filter_body_class( $classes ) {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return $classes;
		}

		$classes = array_filter( $classes, function ( $class ) {
			return ! preg_match( '/^sidebar(1|2)/', $class ) && 'sidebar-left' !== $class;
		} );

		$classes[] = 'sidebar-none';
		$classes[] = 'saab-restaurant-page';

		return $classes;
	}

	/* ---------------------------------------------------------------------
	 * Heading: restaurant name plus city
	 * ------------------------------------------------------------------- */

	/** "Kennewick, WA" for a restaurant, or '' when it has no region yet. */
	public static function get_place_label( $post_id ) {
		$deepest = self::get_deepest_region_term( $post_id );
		if ( ! $deepest ) {
			return '';
		}

		$ancestors = get_ancestors( $deepest->term_id, self::TAXONOMY, 'taxonomy' );
		if ( ! $ancestors ) {
			return $deepest->name;
		}

		$state = get_term( end( $ancestors ), self::TAXONOMY );
		if ( ! $state || is_wp_error( $state ) ) {
			return $deepest->name;
		}

		return $deepest->name . ', ' . self::state_abbreviation( $state->name );
	}

	/**
	 * Adds the city to the theme's <h1> on a restaurant page. Only the
	 * queried restaurant's own title inside the main loop changes — menus,
	 * widgets, the admin and every get_the_title() call made for other posts
	 * keep the plain name; this module's own code reads the raw post_title.
	 */
	public static function filter_heading_title( $title, $post_id = 0 ) {
		if ( is_admin() || ! $post_id || ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $title;
		}
		if ( (int) $post_id !== (int) get_queried_object_id() ) {
			return $title;
		}

		$place = self::get_place_label( $post_id );
		return '' === $place ? $title : $title . ' &#8211; ' . esc_html( $place );
	}

	public static function filter_document_title( $parts ) {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return $parts;
		}

		$post_id = get_queried_object_id();
		$place   = self::get_place_label( $post_id );
		if ( '' !== $place ) {
			$parts['title'] = self::get_raw_title( $post_id ) . ' – ' . $place;
		}

		return $parts;
	}

	/* ---------------------------------------------------------------------
	 * Meta helpers
	 * ------------------------------------------------------------------- */

	/**
	 * One reader for every JSON-holding meta key: decode, and fall back to
	 * $default on anything that isn't valid JSON or isn't the shape we
	 * expect. n8n writes these over REST — bad data should degrade the page
	 * quietly, not throw a notice.
	 */
	public static function get_json_meta( $post_id, $key, $default = array() ) {
		$raw = get_post_meta( $post_id, $key, true );
		if ( ! $raw ) {
			return $default;
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return $default;
		}

		return $decoded;
	}

	/**
	 * The sync contract names a menu row's dish `item`; the template reads
	 * `name`. Accept either so a row is never silently dropped.
	 */
	private static function normalize_menu_item( $item ) {
		if ( ! is_array( $item ) ) {
			return array();
		}
		if ( empty( $item['name'] ) && ! empty( $item['item'] ) ) {
			$item['name'] = $item['item'];
		}
		return $item;
	}

	private static function get_bool_meta( $post_id, $key ) {
		return (bool) get_post_meta( $post_id, $key, true );
	}

	/* ---------------------------------------------------------------------
	 * Admin: "Restaurant data" meta box
	 * ------------------------------------------------------------------- */

	public static function add_meta_box() {
		add_meta_box(
			'saab-restaurant-data',
			'Restaurant data',
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'saab_restaurants_save', 'saab_restaurants_nonce' );

		$rating        = get_post_meta( $post->ID, 'saab_rating', true );
		$review_count  = get_post_meta( $post->ID, 'saab_review_count', true );
		$price_range   = get_post_meta( $post->ID, 'saab_price_range', true );
		$menu_checked  = get_post_meta( $post->ID, 'saab_menu_checked_at', true );
		$synced_at     = get_post_meta( $post->ID, 'saab_synced_at', true );
		$locations     = self::get_json_meta( $post->ID, 'saab_locations' );
		$menu          = self::get_json_meta( $post->ID, 'saab_menu' );
		$linked_posts  = self::get_json_meta( $post->ID, 'saab_linked_posts' );
		$photo_ids     = self::get_json_meta( $post->ID, 'saab_photo_ids' );
		$photo_exclude = self::get_json_meta( $post->ID, 'saab_photo_exclude' );
		?>
		<p class="description">Synced by n8n. Read-only here — edit at the source, not in WordPress.</p>
		<ul style="margin:0 0 1em">
			<li><strong>Rating:</strong> <?php echo '' !== $rating ? esc_html( $rating ) : '—'; ?></li>
			<li><strong>Reviews:</strong> <?php echo '' !== $review_count ? esc_html( $review_count ) : '—'; ?></li>
			<li><strong>Price range:</strong> <?php echo $price_range ? esc_html( $price_range ) : '—'; ?></li>
			<li><strong>Locations:</strong> <?php echo (int) count( $locations ); ?></li>
			<li><strong>Menu items:</strong> <?php echo (int) count( $menu ); ?></li>
			<li><strong>Menu checked:</strong> <?php echo $menu_checked ? esc_html( $menu_checked ) : '—'; ?></li>
			<li><strong>Last synced:</strong> <?php echo $synced_at ? esc_html( $synced_at ) : '—'; ?></li>
		</ul>
		<p>
			<label for="saab_linked_posts_input"><strong>Linked review posts</strong></label><br>
			<input type="text" id="saab_linked_posts_input" name="saab_linked_posts_input"
				value="<?php echo esc_attr( implode( ', ', $linked_posts ) ); ?>" class="widefat" placeholder="123, 456">
			<span class="description">Comma-separated post IDs. Saved as saab_linked_posts.</span>
		</p>
		<?php if ( $linked_posts ) : ?>
			<p><strong>Currently linked:</strong></p>
			<ul>
				<?php foreach ( $linked_posts as $linked_id ) : ?>
					<?php $linked = get_post( $linked_id ); ?>
					<?php if ( $linked ) : ?>
						<li><?php echo esc_html( get_the_title( $linked ) ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p>
			<label for="saab_photo_ids_input"><strong>Photo picks (pinned first)</strong></label><br>
			<input type="text" id="saab_photo_ids_input" name="saab_photo_ids_input"
				value="<?php echo esc_attr( implode( ', ', $photo_ids ) ); ?>" class="widefat" placeholder="123, 456">
			<span class="description">Comma-separated attachment IDs, shown first in the photo pool. Saved as saab_photo_ids.</span>
		</p>
		<?php if ( $photo_ids ) : ?>
			<?php self::render_meta_box_thumbnails( $photo_ids ); ?>
		<?php endif; ?>

		<p>
			<label for="saab_photo_exclude_input"><strong>Hide these photos</strong></label><br>
			<input type="text" id="saab_photo_exclude_input" name="saab_photo_exclude_input"
				value="<?php echo esc_attr( implode( ', ', $photo_exclude ) ); ?>" class="widefat" placeholder="789">
			<span class="description">Comma-separated attachment IDs to leave out of the pool. Saved as saab_photo_exclude.</span>
		</p>
		<?php if ( $photo_exclude ) : ?>
			<?php self::render_meta_box_thumbnails( $photo_exclude ); ?>
		<?php endif; ?>
		<?php
	}

	/** Small preview thumbnails under a Photo picks / Hide these photos field, so an ID typo is obvious at a glance. */
	private static function render_meta_box_thumbnails( $ids ) {
		?>
		<p>
			<?php foreach ( $ids as $id ) : ?>
				<?php echo wp_get_attachment_image( (int) $id, array( 48, 48 ), true, array( 'style' => 'margin:0 4px 4px 0', 'alt' => '' ) ); ?>
			<?php endforeach; ?>
		</p>
		<?php
	}

	public static function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['saab_restaurants_nonce'] ) || ! wp_verify_nonce( $_POST['saab_restaurants_nonce'], 'saab_restaurants_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST['saab_linked_posts_input'] ) ? wp_unslash( $_POST['saab_linked_posts_input'] ) : '';
		$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );

		update_post_meta( $post_id, 'saab_linked_posts', wp_json_encode( $ids ) );

		$photo_ids_raw = isset( $_POST['saab_photo_ids_input'] ) ? wp_unslash( $_POST['saab_photo_ids_input'] ) : '';
		$photo_ids     = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $photo_ids_raw ) ) ) ) );
		update_post_meta( $post_id, 'saab_photo_ids', wp_json_encode( $photo_ids ) );

		$photo_exclude_raw = isset( $_POST['saab_photo_exclude_input'] ) ? wp_unslash( $_POST['saab_photo_exclude_input'] ) : '';
		$photo_exclude     = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $photo_exclude_raw ) ) ) ) );
		update_post_meta( $post_id, 'saab_photo_exclude', wp_json_encode( $photo_exclude ) );
	}

	/**
	 * The post body doubles as "Ryan's Take" on the front end — say so right
	 * above the editor, since nothing about the label "Content" makes that
	 * obvious.
	 */
	public static function render_editor_note( $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}
		echo '<p class="description" style="margin:8px 0 1em">' .
			esc_html( "The post body below is Ryan's Take — the hand-written review shown on the front end. Everything else on the page is synced." ) .
			'</p>';
	}

	/* ---------------------------------------------------------------------
	 * Front end: page body
	 * ------------------------------------------------------------------- */

	public static function render_content( $content ) {
		if ( ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();

		// The page's photo data set: the pool (own featured image, pinned
		// saab_photo_ids, then linked posts' featured images, newest first,
		// minus saab_photo_exclude) drives the strip; dish card photos are
		// prepended to it when a dish's photo isn't already in the pool, so
		// the lightbox has data for every photo the page can show. Built once
		// here so the strip, dish board and lightbox agree on indices.
		$pool      = self::get_photo_pool( $post_id );
		$photos    = self::merge_dish_photos( $post_id, self::pool_to_list( $pool ) );
		$photo_map = self::photo_index_map( $photos );

		// Full-width, single-column layout: header, photo strip, take,
		// predicted pick, the merged dish board, a "Visit" panel (today's
		// status/address, hours, good-to-know), the menu, visit history, then
		// similar spots. No sidebar/aside — see filter_body_class() and
		// restaurants.css for how the theme's own sidebar is removed.
		$sections   = array();
		$sections[] = self::render_header( $post_id );
		$sections[] = self::render_photo_strip( $post_id, array_keys( $pool ), $photo_map );
		$sections[] = self::render_take( $post_id, $content );
		$sections[] = self::render_predicted_pick( $post_id );
		$sections[] = self::render_dishes_board( $post_id, $photo_map );
		$sections[] = self::render_visit( $post_id );
		$sections[] = self::render_menu( $post_id );
		$sections[] = self::render_visits( $post_id );
		$sections[] = self::render_similar( $post_id );

		$photo_extras = '';
		if ( $photos ) {
			$photo_extras = self::render_lightbox_markup() . self::render_photos_data_script( $photos );
		}

		return '<div class="saab-restaurant">' . implode( '', array_filter( $sections ) ) . $photo_extras . '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Photos: pool, alt text, and the lightbox data set
	 * ------------------------------------------------------------------- */

	/**
	 * The page's photo pool, in order and deduped by attachment ID: the
	 * restaurant's own featured image, then anything pinned in
	 * saab_photo_ids, then linked review posts' featured images (newest
	 * post first). Anything listed in saab_photo_exclude never makes it in.
	 * Returns attachment_id => source post ID (or null when a pinned photo
	 * has no post it was uploaded to), preserving pool order.
	 */
	public static function get_photo_pool( $post_id ) {
		$pool    = array();
		$exclude = array_map( 'absint', self::get_json_meta( $post_id, 'saab_photo_exclude' ) );

		$add = function ( $attachment_id, $source_id ) use ( &$pool, $exclude ) {
			$attachment_id = (int) $attachment_id;
			if ( ! $attachment_id || in_array( $attachment_id, $exclude, true ) || isset( $pool[ $attachment_id ] ) ) {
				return;
			}
			$pool[ $attachment_id ] = $source_id;
		};

		$add( get_post_thumbnail_id( $post_id ), $post_id );

		foreach ( self::get_json_meta( $post_id, 'saab_photo_ids' ) as $pinned_id ) {
			$pinned_id  = (int) $pinned_id;
			$attachment = get_post( $pinned_id );
			$source_id  = ( $attachment && $attachment->post_parent ) ? $attachment->post_parent : null;
			$add( $pinned_id, $source_id );
		}

		foreach ( self::get_linked_posts_newest_first( $post_id ) as $linked_post ) {
			$add( get_post_thumbnail_id( $linked_post ), $linked_post->ID );
		}

		return $pool;
	}

	/** saab_linked_posts resolved to WP_Post objects, newest post_date first, dropping any ID that no longer exists. */
	public static function get_linked_posts_newest_first( $post_id ) {
		$posts = array();
		foreach ( self::get_json_meta( $post_id, 'saab_linked_posts' ) as $linked_id ) {
			$linked_post = get_post( (int) $linked_id );
			if ( $linked_post ) {
				$posts[] = $linked_post;
			}
		}

		usort( $posts, function ( $a, $b ) {
			return strcmp( $b->post_date, $a->post_date );
		} );

		return $posts;
	}

	/** Reshapes get_photo_pool()'s assoc array into the {id, source_id} list the rest of the photo code works with. */
	private static function pool_to_list( $pool ) {
		$list = array();
		foreach ( $pool as $id => $source_id ) {
			$list[] = array(
				'id'        => $id,
				'source_id' => $source_id,
			);
		}
		return $list;
	}

	/**
	 * Prepends a dish card's photo to the page's photo list when it isn't
	 * already in the pool — e.g. Ryan ate the dish on a visit that isn't (or
	 * isn't yet) one of the linked review posts. Dish card photos always
	 * show and always open the lightbox; saab_photo_exclude only trims the
	 * strip/pool, not a photo the card itself is already displaying.
	 */
	/**
	 * Ryan's dishes as the page shows them: saab_ryan_dishes with near-duplicate names merged, visits counted
	 * from the linked review titles, and a photo for every card that has one.
	 *
	 * The syncs match dishes by exact name and read each new post's title once, so on Hill's "Pulled Pork Eggs
	 * Benedict" (2021) and "Pulled Pork Benedict" (2025) were two cards, and "Breakfast at Hill's Restaurant —
	 * Pulled Pork Benedict" (March 2026) was linked to the page but on neither card. Here:
	 * - names that are the same dish (SAAB_Dishes::same()) share one card, under the most-eaten name;
	 * - a linked post whose title names the dish counts as a visit: the card shows the larger of the synced count
	 *   and the number of such posts, and "Last time" is the newest of them;
	 * - the photo is the first photo the sync verified for any merged name, newest visit first. When the sync found
	 *   none (photo_id 0), a visit whose title names only this dish lends its featured image: the title says the
	 *   photo is of this dish. A post titled "Spring Rolls and Beef Pho" still lends nothing to "Spring Rolls".
	 *
	 * @return array[] Each { name, post_id, posts, photo_id }, most visits first.
	 */
	public static function get_ryan_dishes( $post_id ) {
		static $cache = array();
		if ( isset( $cache[ $post_id ] ) ) {
			return $cache[ $post_id ];
		}
		$raw = array();
		foreach ( self::get_json_meta( $post_id, 'saab_ryan_dishes' ) as $dish ) {
			if ( ! is_array( $dish ) || empty( $dish['name'] ) || empty( $dish['post_id'] ) ) {
				continue;
			}
			$dish['date'] = (string) get_post_field( 'post_date', (int) $dish['post_id'] );
			$raw[]        = $dish;
		}
		$linked = self::get_linked_posts_newest_first( $post_id );
		$titles = array();
		foreach ( $linked as $p ) {
			$titles[ $p->ID ] = SAAB_Dishes::from_title( $p->post_title );
		}

		$out = array();
		foreach ( SAAB_Dishes::merge( $raw ) as $g ) {
			$names = $g['names'];
			$named = function ( $title_dish ) use ( $names ) {
				foreach ( $names as $n ) {
					if ( SAAB_Dishes::same( $n, $title_dish ) ) {
						return true;
					}
				}
				return false;
			};

			// Visits: linked posts whose title names this dish (newest first, as $linked is).
			$visits = array();
			foreach ( $linked as $p ) {
				if ( $named( $titles[ $p->ID ] ) ) {
					$visits[] = $p;
				}
			}
			$card = array(
				'name'     => $g['name'],
				'post_id'  => $g['post_id'],
				'posts'    => max( (int) $g['posts'], count( $visits ) ),
				'photo_id' => 0,
			);
			if ( $visits && strcmp( $visits[0]->post_date, $g['date'] ) > 0 ) {
				$card['post_id'] = (int) $visits[0]->ID;
			}

			foreach ( $g['photo_ids'] as $id ) {
				if ( $id ) {
					$card['photo_id'] = $id;
					break;
				}
			}
			if ( ! $card['photo_id'] ) {
				$candidates = $visits;
				$seen       = array_flip( wp_list_pluck( $visits, 'ID' ) );
				foreach ( $g['post_ids'] as $id ) {
					$p = isset( $seen[ $id ] ) ? null : get_post( $id );
					if ( $p ) {
						$candidates[] = $p;
					}
				}
				foreach ( $candidates as $p ) {
					$thumb = (int) get_post_thumbnail_id( $p );
					if ( $thumb && $named( SAAB_Dishes::from_title( $p->post_title ) ) ) {
						$card['photo_id'] = $thumb;
						break;
					}
				}
			}
			$out[] = $card;
		}
		usort( $out, function ( $a, $b ) {
			return $b['posts'] <=> $a['posts'];
		} );
		$cache[ $post_id ] = $out;
		return $out;
	}

	/**
	 * The photo for a dish card. The sync sets `photo_id` on a dish after
	 * checking the image actually shows that dish: an attachment ID to use,
	 * or 0 for "this post has no photo of this dish" (a post titled
	 * "Spring Rolls and Beef Pho" whose only photo is the pho). Without the
	 * key, fall back to the post's featured image.
	 */
	public static function dish_photo_id( $dish ) {
		if ( is_array( $dish ) && array_key_exists( 'photo_id', $dish ) ) {
			return (int) $dish['photo_id'];
		}
		return empty( $dish['post_id'] ) ? 0 : (int) get_post_thumbnail_id( (int) $dish['post_id'] );
	}

	private static function merge_dish_photos( $post_id, $photos ) {
		$known = array();
		foreach ( $photos as $photo ) {
			$known[ $photo['id'] ] = true;
		}

		$prepend = array();
		foreach ( self::get_ryan_dishes( $post_id ) as $dish ) {
			$thumb_id = (int) $dish['photo_id'];
			if ( ! $thumb_id || isset( $known[ $thumb_id ] ) ) {
				continue;
			}
			$known[ $thumb_id ] = true;
			$prepend[]          = array(
				'id'        => $thumb_id,
				'source_id' => (int) $dish['post_id'],
			);
		}

		return array_merge( $prepend, $photos );
	}

	/** attachment_id => its position in the merged photo list, for data-photo-index attributes. */
	private static function photo_index_map( $photos ) {
		$map = array();
		foreach ( $photos as $i => $photo ) {
			$map[ $photo['id'] ] = $i;
		}
		return $map;
	}

	/** The largest registered size at or below 1536×1536, falling back to 'large' — used for both the lightbox src and strip/card hrefs. */
	public static function photo_full_src( $attachment_id ) {
		$src = wp_get_attachment_image_url( $attachment_id, '1536x1536' );
		return $src ? $src : wp_get_attachment_image_url( $attachment_id, 'large' );
	}

	/**
	 * Never overwrites the attachment's own alt text in the Media Library —
	 * this only supplies a fallback at render time when that field was left
	 * empty.
	 */
	private static function resolved_alt( $attachment_id, $fallback ) {
		$stored = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		return '' !== $stored ? $stored : $fallback;
	}

	/**
	 * The unfiltered post_title, not get_the_title()'s display version.
	 * wptexturize (run by the 'the_title' filter) turns a plain " - " into
	 * the "&#8211;" entity before strip_trailing_date() ever sees it, so a
	 * hyphen-separated date never matches; parsing the raw title sidesteps
	 * that rather than trying to detect both a character and its entity.
	 */
	public static function get_raw_title( $post_id ) {
		$post = get_post( $post_id );
		return $post ? $post->post_title : '';
	}

	/**
	 * Review post titles look like "Beef Pho at Yummie Pho – December 8,
	 * 2025" or "... - January 12 2023". Strips from the last dash-like
	 * separator followed by a month name through the end, so a photo
	 * caption or fallback alt reads as a plain title. Expects the raw
	 * post_title (see get_raw_title()), not get_the_title()'s output.
	 */
	public static function strip_trailing_date( $title ) {
		$months   = 'January|February|March|April|May|June|July|August|September|October|November|December';
		$stripped = preg_replace( '/\s[\x{2013}\x{2014}-]\s*(?:' . $months . ')\b.*$/u', '', $title );
		return trim( $stripped );
	}

	/**
	 * The JSON the lightbox reads: for each photo in the merged list, a full
	 * src ('1536x1536' falling back to 'large'), its srcset, alt text, a
	 * caption (source title without its trailing date, plus the source's
	 * publish month), and the source post's URL.
	 */
	private static function photo_data_json( $photos ) {
		$data = array();
		foreach ( $photos as $photo ) {
			$id            = $photo['id'];
			$source_id     = $photo['source_id'];
			$title         = $source_id ? self::get_raw_title( $source_id ) : self::get_raw_title( $id );
			$caption_title = self::strip_trailing_date( $title );
			$date          = $source_id ? get_the_date( 'F Y', $source_id ) : '';
			$srcset        = wp_get_attachment_image_srcset( $id, '1536x1536' );

			$data[] = array(
				'src'     => self::photo_full_src( $id ),
				'srcset'  => $srcset ? $srcset : '',
				'alt'     => self::resolved_alt( $id, $caption_title ),
				'caption' => $date ? $caption_title . ' — ' . $date : $caption_title,
				'url'     => $source_id ? get_permalink( $source_id ) : get_permalink( $id ),
			);
		}
		return $data;
	}

	private static function render_photos_data_script( $photos ) {
		return sprintf(
			'<script type="application/json" class="saab-restaurant__photos-data">%s</script>',
			wp_json_encode( self::photo_data_json( $photos ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/** One lightbox dialog per page, built once; restaurants.js fills it in and toggles its `hidden` attribute. */
	private static function render_lightbox_markup() {
		ob_start();
		?>
		<div class="saab-restaurant__lightbox" id="saab-restaurant-lightbox" role="dialog" aria-modal="true" aria-label="Photo viewer" hidden>
			<button type="button" class="saab-restaurant__lightbox-close" aria-label="Close">&times;</button>
			<button type="button" class="saab-restaurant__lightbox-prev" aria-label="Previous photo">&#8249;</button>
			<figure class="saab-restaurant__lightbox-figure">
				<img class="saab-restaurant__lightbox-image" alt="">
				<figcaption class="saab-restaurant__lightbox-caption">
					<span class="saab-restaurant__lightbox-caption-text"></span>
					<a class="saab-restaurant__lightbox-link" href="#">Read the post</a>
				</figcaption>
				<p class="saab-restaurant__lightbox-counter"></p>
			</figure>
			<button type="button" class="saab-restaurant__lightbox-next" aria-label="Next photo">&#8250;</button>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The photo strip right after the header: one large photo plus two
	 * stacked (3+ pool photos), two side by side (exactly 2), or a single
	 * wide crop (1). "See all" always shows once there are 2+ photos — even
	 * when every pool photo is already visible, it's still the only way
	 * into the lightbox's captions and "Read the post" links, so it earns a
	 * place rather than appearing only on overflow. Without JS every item is
	 * a plain link to the full-size image.
	 */
	private static function render_photo_strip( $post_id, $pool_ids, $index_map ) {
		$count = count( $pool_ids );
		if ( ! $count ) {
			return '';
		}

		$shown            = min( 3, $count );
		$visible          = array_slice( $pool_ids, 0, $shown );
		$restaurant_title = self::get_raw_title( $post_id );

		ob_start();
		?>
		<div class="saab-restaurant__photo-strip saab-restaurant__photo-strip--count-<?php echo (int) $shown; ?>">
			<?php foreach ( $visible as $i => $attachment_id ) : ?>
				<?php
				$is_first = 0 === $i;
				$is_last  = $i === $shown - 1;
				$size     = $is_first ? 'large' : 'medium_large';
				$sizes    = $is_first
					? '(max-width: 600px) 80vw, (max-width: 820px) 100vw, 66vw'
					: '(max-width: 600px) 80vw, (max-width: 820px) 50vw, 33vw';
				$img_attr = array(
					'alt'   => self::resolved_alt( $attachment_id, $restaurant_title ),
					'sizes' => $sizes,
				);
				if ( $is_first ) {
					$img_attr['loading']       = 'eager';
					$img_attr['fetchpriority'] = 'high';
				} else {
					$img_attr['loading'] = 'lazy';
				}
				$index    = isset( $index_map[ $attachment_id ] ) ? (int) $index_map[ $attachment_id ] : 0;
				$full_src = self::photo_full_src( $attachment_id );
				?>
				<div class="saab-restaurant__photo-strip-item">
					<a class="saab-restaurant__photo-strip-link" href="<?php echo esc_url( $full_src ); ?>" data-photo-index="<?php echo $index; ?>">
						<?php echo wp_get_attachment_image( $attachment_id, $size, false, $img_attr ); ?>
					</a>
					<?php if ( $is_last && $count >= 2 ) : ?>
						<a class="saab-restaurant__photo-strip-more" href="<?php echo esc_url( $full_src ); ?>" data-photo-index="<?php echo $index; ?>">
							<?php echo esc_html( sprintf( 'See all %d photos', $count ) ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_header( $post_id ) {
		ob_start();
		?>
		<header class="saab-restaurant__header">
			<?php $region = self::render_region_line( $post_id ); ?>
			<?php if ( $region ) : ?>
				<p class="saab-restaurant__region"><?php echo $region; // phpcs: escaped in render_region_line() ?></p>
			<?php endif; ?>

			<?php $cuisines = get_the_category( $post_id ); ?>
			<?php if ( $cuisines ) : ?>
				<p class="saab-restaurant__cuisines">
					<?php foreach ( $cuisines as $i => $term ) : ?>
						<?php echo $i > 0 ? ', ' : ''; ?><a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<p class="saab-restaurant__badges">
				<?php if ( self::get_bool_meta( $post_id, 'saab_locally_owned' ) ) : ?>
					<span class="saab-restaurant__badge">Locally owned</span>
				<?php endif; ?>

				<?php $rating = get_post_meta( $post_id, 'saab_rating', true ); ?>
				<?php $review_count = get_post_meta( $post_id, 'saab_review_count', true ); ?>
				<?php if ( '' !== $rating && '' !== $review_count ) : ?>
					<span class="saab-restaurant__rating">
						&#9733; <?php echo esc_html( number_format( (float) $rating, 1 ) ); ?>
						(<?php echo esc_html( number_format_i18n( (int) $review_count ) ); ?> Google reviews)
					</span>
				<?php endif; ?>

				<?php $hours = self::first_location_hours( $post_id ); ?>
				<?php if ( $hours ) : ?>
					<span class="saab-restaurant__open-now" data-hours="<?php echo esc_attr( wp_json_encode( $hours ) ); ?>" hidden></span>
				<?php endif; ?>
			</p>
		</header>
		<?php
		return ob_get_clean();
	}

	/**
	 * The most specific saab_region term assigned to the post (City, in the
	 * usual State > Area > City hierarchy) — shared by render_region_line()
	 * and the photo alt-text builder, which only needs the city name.
	 */
	public static function get_deepest_region_term( $post_id ) {
		$terms = get_the_terms( $post_id, self::TAXONOMY );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return null;
		}

		$deepest       = null;
		$deepest_depth = -1;
		foreach ( $terms as $term ) {
			$depth = count( get_ancestors( $term->term_id, self::TAXONOMY, 'taxonomy' ) );
			if ( $depth > $deepest_depth ) {
				$deepest       = $term;
				$deepest_depth = $depth;
			}
		}

		return $deepest;
	}

	private static function get_city_name( $post_id ) {
		$deepest = self::get_deepest_region_term( $post_id );
		return $deepest ? $deepest->name : '';
	}

	/**
	 * "City, ST" built from the deepest assigned region term and its
	 * top-level ancestor (State > Area > City, so the ancestor two levels up
	 * from City). A top-level term with no children just prints on its own.
	 */
	private static function render_region_line( $post_id ) {
		$deepest = self::get_deepest_region_term( $post_id );
		if ( ! $deepest ) {
			return '';
		}

		$ancestors = get_ancestors( $deepest->term_id, self::TAXONOMY, 'taxonomy' );
		$state     = null;
		if ( $ancestors ) {
			$state_id = end( $ancestors ); // Top of the chain is the State term.
			$state    = get_term( $state_id, self::TAXONOMY );
		}

		$city_link = '<a href="' . esc_url( get_term_link( $deepest ) ) . '">' . esc_html( $deepest->name ) . '</a>';

		if ( $state && ! is_wp_error( $state ) ) {
			$state_abbr = self::state_abbreviation( $state->name );
			return $city_link . ', ' . esc_html( $state_abbr );
		}

		return $city_link;
	}

	public static function state_abbreviation( $name ) {
		$states = array(
			'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR', 'California' => 'CA',
			'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE', 'Florida' => 'FL', 'Georgia' => 'GA',
			'Hawaii' => 'HI', 'Idaho' => 'ID', 'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA',
			'Kansas' => 'KS', 'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD',
			'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS', 'Missouri' => 'MO',
			'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV', 'New Hampshire' => 'NH', 'New Jersey' => 'NJ',
			'New Mexico' => 'NM', 'New York' => 'NY', 'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH',
			'Oklahoma' => 'OK', 'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC',
			'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT', 'Vermont' => 'VT',
			'Virginia' => 'VA', 'Washington' => 'WA', 'West Virginia' => 'WV', 'Wisconsin' => 'WI', 'Wyoming' => 'WY',
			'District of Columbia' => 'DC',
		);

		return isset( $states[ $name ] ) ? $states[ $name ] : $name;
	}

	private static function first_location_hours( $post_id ) {
		$locations = self::get_json_meta( $post_id, 'saab_locations' );
		if ( ! $locations || empty( $locations[0]['hours'] ) || ! is_array( $locations[0]['hours'] ) ) {
			return null;
		}

		return $locations[0]['hours'];
	}

	private static function render_take( $post_id, $content ) {
		$has_content = '' !== trim( wp_strip_all_tags( $content ) );

		// No take written yet, but Ryan has eaten here (linked review posts):
		// "I haven't eaten here yet" would be false, so leave the section out
		// until he writes one. The dishes section already shows what he ate.
		if ( ! $has_content && ( self::get_json_meta( $post_id, 'saab_linked_posts' ) || self::get_json_meta( $post_id, 'saab_ryan_dishes' ) ) ) {
			return '';
		}

		ob_start();
		?>
		<section class="saab-restaurant__take">
			<h2>Ryan's Take</h2>
			<?php if ( $has_content ) : ?>
				<?php echo $content; // Already-filtered post HTML, trusted like any other post body. ?>
			<?php else : ?>
				<p>I haven't eaten here yet.</p>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * A dashed box predicting what Ryan would order, shown only until he's
	 * actually eaten here — once saab_ryan_dishes has anything in it, the
	 * dish board below covers the same ground with real data.
	 */
	private static function render_predicted_pick( $post_id ) {
		$ryan_dishes = self::get_json_meta( $post_id, 'saab_ryan_dishes' );
		$predicted   = self::get_json_meta( $post_id, 'saab_predicted_pick' );

		if ( $ryan_dishes || empty( $predicted['dish'] ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="saab-restaurant__predicted-pick">
			<p>Haven't eaten here yet. Based on what I usually love, I'd start with
				<strong><?php echo esc_html( $predicted['dish'] ); ?></strong>.</p>
			<?php if ( ! empty( $predicted['reason'] ) ) : ?>
				<p><?php echo esc_html( $predicted['reason'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * "The dishes" board: saab_ryan_dishes and saab_popular_dishes merged
	 * into one grid, matched case-insensitively by name so a dish Ryan ate
	 * that Google reviewers also mention gets a single card with both tags.
	 */
	private static function render_dishes_board( $post_id, $photo_index = array() ) {
		$popular_dishes = self::get_json_meta( $post_id, 'saab_popular_dishes' );

		$cards = array(); // Keyed by lowercase name so both sources land on one card.

		// Ryan's dishes come merged and with their photos resolved (see get_ryan_dishes()).
		foreach ( self::get_ryan_dishes( $post_id ) as $dish ) {
			$cards[ strtolower( $dish['name'] ) ] = array(
				'name'     => $dish['name'],
				'post_id'  => (int) $dish['post_id'],
				'posts'    => (int) $dish['posts'],
				'photo_id' => (int) $dish['photo_id'],
				'mentions' => null,
				'source'   => null,
			);
		}

		foreach ( $popular_dishes as $dish ) {
			if ( empty( $dish['name'] ) ) {
				continue;
			}
			$key = strtolower( $dish['name'] );
			if ( ! isset( $cards[ $key ] ) ) {
				foreach ( $cards as $k => $c ) {
					if ( null !== $c['post_id'] && SAAB_Dishes::same( $c['name'], $dish['name'] ) ) {
						$key = $k;
						break;
					}
				}
			}
			$mentions = ! empty( $dish['mentions'] ) ? (int) $dish['mentions'] : null;
			$source   = isset( $dish['source'] ) ? $dish['source'] : null;

			if ( isset( $cards[ $key ] ) ) {
				$cards[ $key ]['mentions'] = $mentions;
				$cards[ $key ]['source']   = $source;
			} else {
				$cards[ $key ] = array(
					'name'     => $dish['name'],
					'post_id'  => null,
					'posts'    => null,
					'mentions' => $mentions,
					'source'   => $source,
				);
			}
		}

		if ( ! $cards ) {
			return '';
		}

		$has_ryan_dishes = (bool) array_filter( $cards, function ( $card ) {
			return null !== $card['post_id'];
		} );

		$cards = array_values( $cards );
		usort( $cards, function ( $a, $b ) {
			$score_a = ( $a['post_id'] ? 1000 + $a['posts'] * 10 : 0 ) + (int) $a['mentions'];
			$score_b = ( $b['post_id'] ? 1000 + $b['posts'] * 10 : 0 ) + (int) $b['mentions'];
			return $score_b <=> $score_a;
		} );

		$restaurant_title = self::get_raw_title( $post_id );
		$city             = self::get_city_name( $post_id );

		ob_start();
		?>
		<section class="saab-restaurant__dishes">
			<h2>The Dishes</h2>
			<p class="saab-restaurant__dishes-sub">
				<?php if ( $has_ryan_dishes ) : ?>
					Dishes I've ordered, plus what Google reviewers mention most. Shaded cards are dishes I've had.
				<?php else : ?>
					What Google reviewers mention most.
				<?php endif; ?>
			</p>
			<div class="saab-restaurant__board">
				<?php foreach ( $cards as $card ) : ?>
					<?php $is_ryan_dish = null !== $card['post_id']; ?>
					<?php // A dish Ryan ate shows that visit's featured image across the card's top; a
					// Google-only card, or a Ryan dish whose post has no featured image, stays text-only. ?>
					<?php $thumb_id = $is_ryan_dish ? (int) $card['photo_id'] : 0; ?>
					<article class="saab-restaurant__card<?php echo $is_ryan_dish ? ' saab-restaurant__card--ate' : ''; ?>">
						<?php if ( $thumb_id ) : ?>
							<?php
							$alt = $city
								? sprintf( '%1$s at %2$s, %3$s', $card['name'], $restaurant_title, $city )
								: sprintf( '%1$s at %2$s', $card['name'], $restaurant_title );
							$index = isset( $photo_index[ $thumb_id ] ) ? (int) $photo_index[ $thumb_id ] : 0;
							?>
							<a class="saab-restaurant__card-photo-link" href="<?php echo esc_url( self::photo_full_src( $thumb_id ) ); ?>" data-photo-index="<?php echo $index; ?>">
								<?php
								echo wp_get_attachment_image( $thumb_id, 'medium_large', false, array(
									'class'   => 'saab-restaurant__card-photo',
									'alt'     => self::resolved_alt( $thumb_id, $alt ),
									'loading' => 'lazy',
									'sizes'   => '(max-width: 820px) 50vw, 260px',
								) );
								?>
							</a>
						<?php endif; ?>
						<div class="saab-restaurant__card-body">
							<h3><?php echo esc_html( $card['name'] ); ?></h3>
							<div class="saab-restaurant__card-tags">
								<?php if ( $is_ryan_dish ) : ?>
									<span class="saab-restaurant__tag saab-restaurant__tag--ate">
										<?php echo $card['posts'] > 1 ? esc_html( "I've had it \xC2\xB7 {$card['posts']}\xC3\x97" ) : esc_html( "I've had it" ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $card['mentions'] ) : ?>
									<span class="saab-restaurant__tag"><?php echo esc_html( $card['mentions'] . ' reviews mention it' ); ?></span>
								<?php elseif ( 'google_tag' === $card['source'] ) : ?>
									<span class="saab-restaurant__tag">Popular on Google</span>
								<?php endif; ?>
							</div>
							<?php if ( $is_ryan_dish ) : ?>
								<p class="saab-restaurant__card-line">Last time: <a href="<?php echo esc_url( get_permalink( $card['post_id'] ) ); ?>"><?php echo esc_html( get_the_date( 'F Y', $card['post_id'] ) ); ?></a></p>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function render_menu( $post_id ) {
		$menu = self::get_json_meta( $post_id, 'saab_menu' );
		if ( ! $menu ) {
			return '';
		}

		$menu_by_section = array();
		foreach ( $menu as $item ) {
			$item = self::normalize_menu_item( $item );
			if ( empty( $item['name'] ) ) {
				continue;
			}
			$section = ! empty( $item['section'] ) ? $item['section'] : 'Menu';
			$menu_by_section[ $section ][] = $item;
		}

		$checked_at  = get_post_meta( $post_id, 'saab_menu_checked_at', true );
		$source_url  = get_post_meta( $post_id, 'saab_menu_source_url', true );

		ob_start();
		?>
		<section class="saab-restaurant__menu">
			<h2>The Menu</h2>
			<input type="search" class="saab-restaurant__menu-search" placeholder="Search the menu&hellip;">

			<div class="saab-restaurant__menu-accordion">
				<?php $first_section = true; ?>
				<?php foreach ( $menu_by_section as $section => $items ) : ?>
					<?php // One section open at a time; restaurants.js closes the others when one opens. ?>
					<details class="saab-restaurant__menu-section"<?php echo $first_section ? ' open' : ''; ?>>
					<?php $first_section = false; ?>
						<summary>
							<span><?php echo esc_html( $section ); ?></span>
							<span class="saab-restaurant__menu-count"><?php echo esc_html( 1 === count( $items ) ? '1 item' : count( $items ) . ' items' ); ?></span>
						</summary>
						<ul>
							<?php foreach ( $items as $item ) : ?>
								<li class="saab-restaurant__menu-item">
									<span class="saab-restaurant__menu-item-name"><?php echo esc_html( $item['name'] ); ?></span>
									<?php if ( ! empty( $item['description'] ) ) : ?>
										<span class="saab-restaurant__menu-item-desc"><?php echo esc_html( $item['description'] ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endforeach; ?>
			</div>

			<p class="saab-restaurant__menu-meta">
				<?php if ( $checked_at ) : ?>
					Menu checked <?php echo esc_html( date_i18n( 'F Y', strtotime( $checked_at ) ) ); ?>.
				<?php endif; ?>
				<?php if ( $source_url ) : ?>
					<a href="<?php echo esc_url( $source_url ); ?>">See the full menu</a>
				<?php endif; ?>
			</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * "{N} visits since {year}" plus the linked review posts grouped by
	 * year, newest first. N counts saab_linked_posts as stored; a linked
	 * post that's since been trashed just doesn't get a row.
	 */
	private static function render_visits( $post_id ) {
		$linked = self::get_json_meta( $post_id, 'saab_linked_posts' );
		if ( ! $linked ) {
			return '';
		}

		$by_year = array();
		foreach ( $linked as $linked_id ) {
			$linked_post = get_post( (int) $linked_id );
			if ( ! $linked_post ) {
				continue;
			}
			$by_year[ get_the_date( 'Y', $linked_post ) ][] = $linked_post;
		}

		if ( ! $by_year ) {
			return '';
		}

		krsort( $by_year );
		foreach ( $by_year as $year => $posts ) {
			usort( $posts, function ( $a, $b ) {
				return strcmp( $b->post_date, $a->post_date );
			} );
			$by_year[ $year ] = $posts;
		}
		$count         = count( $linked );
		$earliest_year = min( array_keys( $by_year ) );

		ob_start();
		?>
		<section class="saab-restaurant__visits">
			<h2><?php echo esc_html( sprintf( '%d %s Since %s', $count, 1 === $count ? 'Visit' : 'Visits', $earliest_year ) ); ?></h2>
			<div class="saab-restaurant__years">
				<?php foreach ( $by_year as $year => $posts ) : ?>
					<div class="saab-restaurant__year">
						<span class="saab-restaurant__year-label"><?php echo esc_html( $year ); ?></span>
						<ul>
							<?php foreach ( $posts as $linked_post ) : ?>
								<?php $thumb_id = get_post_thumbnail_id( $linked_post ); ?>
								<li class="saab-restaurant__visit-item">
									<a href="<?php echo esc_url( get_permalink( $linked_post ) ); ?>">
										<?php if ( $thumb_id ) : ?>
											<?php
											$alt = self::strip_trailing_date( self::get_raw_title( $linked_post->ID ) );
											echo wp_get_attachment_image( $thumb_id, 'thumbnail', false, array(
												'class'   => 'saab-restaurant__visit-thumb',
												'alt'     => self::resolved_alt( $thumb_id, $alt ),
												'loading' => 'lazy',
											) );
											?>
										<?php else : ?>
											<?php // No featured image: an empty placeholder keeps titles aligned with rows that have one. ?>
											<span class="saab-restaurant__visit-thumb-placeholder" aria-hidden="true"></span>
										<?php endif; ?>
										<span><?php echo esc_html( get_the_title( $linked_post ) ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
			<?php // restaurants.js folds everything after the two most recent posts behind this button; without JS the full list stays visible. ?>
			<button type="button" class="saab-restaurant__visits-toggle" aria-expanded="false" hidden></button>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * "Visit": one bordered panel, full width, laid out in three columns
	 * that wrap on narrow screens — Today (open/closed status, address, and
	 * the "Locations (N)" list when there's more than one), Hours (the full
	 * table, today's row highlighted client-side) and Good to Know (feature
	 * chips plus the "All Details" expander). Replaces the old sticky aside;
	 * nothing here is sticky any more.
	 */
	private static function render_visit( $post_id ) {
		$locations = self::get_json_meta( $post_id, 'saab_locations' );
		$features  = self::get_json_meta( $post_id, 'saab_features' );

		if ( ! $locations && ! $features ) {
			return '';
		}

		$first = $locations ? $locations[0] : null;
		$hours = self::first_location_hours( $post_id );

		ob_start();
		?>
		<section class="saab-restaurant__visit">
			<h2>Visit</h2>
			<div class="saab-restaurant__visit-panel">
				<div class="saab-restaurant__visit-col saab-restaurant__visit-col--today">
					<h3>Today</h3>

					<?php if ( $hours ) : ?>
						<p class="saab-restaurant__visit-status" data-hours="<?php echo esc_attr( wp_json_encode( $hours ) ); ?>" hidden></p>
					<?php endif; ?>

					<?php if ( $first ) : ?>
						<p class="saab-restaurant__address">
							<?php if ( ! empty( $first['address'] ) ) : ?>
								<strong><?php echo esc_html( $first['address'] ); ?></strong>
							<?php endif; ?>
							<?php
							$city_state = trim( trim( ( $first['city'] ?? '' ) . ( ! empty( $first['city'] ) && ! empty( $first['state'] ) ? ', ' : '' ) . ( $first['state'] ?? '' ) ) . ( ! empty( $first['zip'] ) ? ' ' . $first['zip'] : '' ) );
							?>
							<?php if ( $city_state ) : ?>
								<br><?php echo esc_html( $city_state ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $first['phone'] ) ) : ?>
								<br><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $first['phone'] ) ); ?>"><?php echo esc_html( $first['phone'] ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $first['place_link'] ) ) : ?>
								<br><a href="<?php echo esc_url( $first['place_link'] ); ?>">Open in Google Maps</a>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<?php if ( count( $locations ) > 1 ) : ?>
						<h3><?php echo esc_html( sprintf( 'Locations (%d)', count( $locations ) ) ); ?></h3>
						<ul class="saab-restaurant__locations-list">
							<?php foreach ( $locations as $location ) : ?>
								<li class="saab-restaurant__location">
									<p class="saab-restaurant__location-name"><strong><?php echo esc_html( $location['name'] ?? '' ); ?></strong><br>
										<?php echo esc_html( $location['address'] ?? '' ); ?></p>

									<?php if ( ! empty( $location['phone'] ) ) : ?>
										<p><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $location['phone'] ) ); ?>"><?php echo esc_html( $location['phone'] ); ?></a></p>
									<?php endif; ?>

									<?php if ( ! empty( $location['place_link'] ) ) : ?>
										<p><a href="<?php echo esc_url( $location['place_link'] ); ?>">Open in Google Maps</a></p>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<div class="saab-restaurant__visit-col saab-restaurant__visit-col--hours">
					<?php if ( $hours ) : ?>
						<h3>Hours</h3>
						<table class="saab-restaurant__hours">
							<?php foreach ( array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ) as $day ) : ?>
								<?php if ( ! isset( $hours[ $day ] ) ) : continue; endif; ?>
								<tr data-day="<?php echo esc_attr( $day ); ?>">
									<th scope="row"><?php echo esc_html( $day ); ?></th>
									<td><?php echo esc_html( implode( ', ', (array) $hours[ $day ] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</table>
					<?php endif; ?>
				</div>

				<div class="saab-restaurant__visit-col saab-restaurant__visit-col--know">
					<?php if ( $features ) : ?>
						<h3>Good to Know</h3>
						<?php $short_groups = array_slice( $features, 0, 3, true ); ?>
						<ul class="saab-restaurant__chips">
							<?php foreach ( $short_groups as $labels ) : ?>
								<?php foreach ( array_slice( (array) $labels, 0, 3 ) as $label ) : ?>
									<li class="saab-restaurant__chip"><?php echo esc_html( $label ); ?></li>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</ul>
						<details class="saab-restaurant__all-details">
							<summary>All Details</summary>
							<?php foreach ( $features as $group => $labels ) : ?>
								<div class="saab-restaurant__feature-group">
									<h3><?php echo esc_html( $group ); ?></h3>
									<ul class="saab-restaurant__chips">
										<?php foreach ( (array) $labels as $label ) : ?>
											<li class="saab-restaurant__chip"><?php echo esc_html( $label ); ?></li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endforeach; ?>
						</details>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function render_similar( $post_id ) {
		$similar = self::get_json_meta( $post_id, 'saab_similar' );
		if ( ! $similar ) {
			return '';
		}

		ob_start();
		?>
		<section class="saab-restaurant__similar">
			<h2>Similar Local Spots</h2>
			<ul>
				<?php foreach ( $similar as $similar_id ) : ?>
					<?php $similar_post = get_post( (int) $similar_id ); ?>
					<?php if ( ! $similar_post || self::POST_TYPE !== $similar_post->post_type ) : continue; endif; ?>
					<li><a href="<?php echo esc_url( get_permalink( $similar_post ) ); ?>"><?php echo esc_html( get_the_title( $similar_post ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	public static function enqueue_assets() {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}

		// Version by file mtime, not the plugin version: the host serves these
		// with a one-year cache, so an unchanged ?ver= keeps stale CSS/JS in
		// browsers after every upload.
		$css = SAAB_TOOLKIT_DIR . 'assets/restaurants.css';
		$js  = SAAB_TOOLKIT_DIR . 'assets/restaurants.js';

		wp_enqueue_style( 'saab-restaurants', SAAB_TOOLKIT_URL . 'assets/restaurants.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : SAAB_TOOLKIT_VERSION );
		wp_enqueue_script( 'saab-restaurants', SAAB_TOOLKIT_URL . 'assets/restaurants.js', array(), file_exists( $js ) ? (string) filemtime( $js ) : SAAB_TOOLKIT_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------- */

	/**
	 * A separate Restaurant script, not merged into the SEO module's @graph.
	 * class-saab-seo.php only adds a BlogPosting node when is_singular( 'post' )
	 * is true, so on a saab_restaurant page it emits nothing but the sitewide
	 * Organization/WebSite nodes — there's nothing here for a Restaurant type
	 * to conflict with, and two ld+json scripts on one page is valid.
	 */
	public static function render_schema() {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}

		$post_id   = get_queried_object_id();
		$locations = self::get_json_meta( $post_id, 'saab_locations' );
		$first     = $locations ? $locations[0] : null;

		$restaurant = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Restaurant',
			'name'     => self::get_raw_title( $post_id ),
			'url'      => get_permalink( $post_id ),
		);

		if ( $first ) {
			$restaurant['address'] = array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $first['address'] ?? '',
				'addressLocality' => $first['city'] ?? '',
				'addressRegion'   => $first['state'] ?? '',
			);

			if ( isset( $first['lat'], $first['lng'] ) ) {
				$restaurant['geo'] = array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => $first['lat'],
					'longitude' => $first['lng'],
				);
			}

			if ( ! empty( $first['phone'] ) ) {
				$restaurant['telephone'] = $first['phone'];
			}
		}

		$cuisines = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $cuisines ) && $cuisines ) {
			$restaurant['servesCuisine'] = $cuisines;
		}

		// No aggregateRating: the rating shown on the page comes from Google
		// Maps, and Google's structured-data policy forbids marking up ratings
		// collected on another site.

		$menu = self::get_json_meta( $post_id, 'saab_menu' );
		if ( $menu ) {
			$menu_by_section = array();
			foreach ( $menu as $item ) {
				$item = self::normalize_menu_item( $item );
				if ( empty( $item['name'] ) ) {
					continue;
				}
				$section = ! empty( $item['section'] ) ? $item['section'] : 'Menu';
				$menu_by_section[ $section ][] = $item;
			}

			$menu_sections = array();
			foreach ( $menu_by_section as $section => $items ) {
				$menu_items = array();
				foreach ( $items as $item ) {
					$menu_item = array( '@type' => 'MenuItem', 'name' => $item['name'] );

					if ( ! empty( $item['description'] ) ) {
						$menu_item['description'] = $item['description'];
					}

					$menu_items[] = $menu_item;
				}

				$menu_sections[] = array(
					'@type'       => 'MenuSection',
					'name'        => $section,
					'hasMenuItem' => $menu_items,
				);
			}

			$restaurant['hasMenu'] = array(
				'@type'          => 'Menu',
				'hasMenuSection' => $menu_sections,
			);
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $restaurant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}
}
