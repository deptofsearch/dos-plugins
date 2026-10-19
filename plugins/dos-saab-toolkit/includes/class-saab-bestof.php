<?php
/**
 * "Best X in Tri-Cities" pages in the restaurant-page style (layout B,
 * "Editorial Rows"): hero photo, intro beside a "What I Look For"
 * checklist, one wide row per pick pulled live from its restaurant page,
 * and an "Also on the Menu At" table of other restaurant pages whose menu
 * lists the dish.
 *
 * A page opts in by having `saab_bestof` post meta (JSON, see
 * sanitize_config()). Without it the page renders exactly as before — the
 * original page/Builder content is left untouched in the database, so
 * clearing the meta restores the old page.
 *
 * Loaded and started by SAAB_Restaurants::init().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'SAAB_Bestof', false ) ) {
	return;
}

class SAAB_Bestof {

	const META_KEY = 'saab_bestof';

	/** Where the "Best in Tri-Cities" eyebrow links. */
	const INDEX_PATH = '/best-in-tri-cities/';

	/** Cache for the "Also on the Menu At" scan, per page. */
	const ALSO_CACHE_PREFIX = 'saab_bestof_also_';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 11 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_page', array( __CLASS__, 'save_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );

		// A restaurant's menu or dishes changing can change any Best-Of table.
		add_action( 'save_post_' . SAAB_Restaurants::POST_TYPE, array( __CLASS__, 'flush_also_cache' ) );
		add_action( 'updated_post_meta', array( __CLASS__, 'maybe_flush_on_meta' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Config
	 * ------------------------------------------------------------------- */

	public static function register_meta() {
		register_post_meta( 'page', self::META_KEY, array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => array( __CLASS__, 'sanitize_meta' ),
			'auth_callback'     => function () {
				return current_user_can( 'edit_pages' );
			},
		) );
	}

	/**
	 * Stored shape:
	 * {
	 *   "dish":     "Biscuits & Gravy",
	 *   "match":    ["biscuit gravy"],            // every word of any phrase must appear
	 *   "eyebrow":  "Breakfast",
	 *   "intro":    "...",
	 *   "note":     "...",                          // optional italic aside
	 *   "criteria": [{"label": "The biscuits", "text": "..."}],
	 *   "picks":    [{"business_id": "0x..", "name": "Swampy's BBQ", "city": "Kennewick",
	 *                 "dish": "Double Pepperoni",   // optional: what to order here
	 *                 "note": "Amazing value",       // optional short aside
	 *                 "photo_id": 0,                 // optional: Ryan's photo choice for this row
	 *                 "no_photo": false}],           // optional: true hides this row's photo
	 *   "hero_id":  0,                              // optional; else the featured image
	 *   "hero_caption": "Bacon Cheeseburger @ The Jackalope Bar & Grill"   // optional
	 * }
	 */
	public static function sanitize_meta( $value ) {
		// update_metadata() has already unslashed the value by the time this runs.
		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		return wp_json_encode( self::sanitize_config( $decoded ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function sanitize_config( $c ) {
		$text = function ( $v ) {
			return trim( wp_strip_all_tags( (string) $v ) );
		};

		$out = array(
			'dish'     => $text( isset( $c['dish'] ) ? $c['dish'] : '' ),
			'match'    => array(),
			'eyebrow'  => $text( isset( $c['eyebrow'] ) ? $c['eyebrow'] : '' ),
			'intro'    => $text( isset( $c['intro'] ) ? $c['intro'] : '' ),
			'note'     => $text( isset( $c['note'] ) ? $c['note'] : '' ),
			'criteria' => array(),
			'picks'    => array(),
			'hero_id'  => isset( $c['hero_id'] ) ? absint( $c['hero_id'] ) : 0,
			'hero_caption' => $text( isset( $c['hero_caption'] ) ? $c['hero_caption'] : '' ),
		);

		foreach ( (array) ( isset( $c['match'] ) ? $c['match'] : array() ) as $phrase ) {
			$phrase = $text( $phrase );
			if ( '' !== $phrase ) {
				$out['match'][] = $phrase;
			}
		}
		foreach ( (array) ( isset( $c['criteria'] ) ? $c['criteria'] : array() ) as $row ) {
			if ( is_array( $row ) && ( ! empty( $row['label'] ) || ! empty( $row['text'] ) ) ) {
				$out['criteria'][] = array( 'label' => $text( $row['label'] ), 'text' => $text( $row['text'] ) );
			}
		}
		foreach ( (array) ( isset( $c['picks'] ) ? $c['picks'] : array() ) as $row ) {
			if ( is_array( $row ) && ( ! empty( $row['business_id'] ) || ! empty( $row['name'] ) ) ) {
				$out['picks'][] = array(
					'business_id' => $text( isset( $row['business_id'] ) ? $row['business_id'] : '' ),
					'name'        => $text( isset( $row['name'] ) ? $row['name'] : '' ),
					'city'        => $text( isset( $row['city'] ) ? $row['city'] : '' ),
					'dish'        => $text( isset( $row['dish'] ) ? $row['dish'] : '' ),
					'note'        => $text( isset( $row['note'] ) ? $row['note'] : '' ),
					'photo_id'    => isset( $row['photo_id'] ) ? absint( $row['photo_id'] ) : 0,
					'no_photo'    => ! empty( $row['no_photo'] ),
				);
			}
		}

		return $out;
	}

	public static function get_config( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$config = self::sanitize_config( $decoded );
		return $config['picks'] ? $config : null;
	}

	public static function is_bestof() {
		return is_page() && null !== self::get_config( get_queried_object_id() );
	}

	/* ---------------------------------------------------------------------
	 * Template, body class, assets
	 * ------------------------------------------------------------------- */

	/** Our own template, so Themify Builder's layout for the page is bypassed rather than fought. */
	public static function template_include( $template ) {
		if ( ! self::is_bestof() ) {
			return $template;
		}
		$ours = SAAB_TOOLKIT_DIR . 'templates/bestof.php';
		return file_exists( $ours ) ? $ours : $template;
	}

	public static function filter_body_class( $classes ) {
		if ( ! self::is_bestof() ) {
			return $classes;
		}
		$classes = array_filter( $classes, function ( $class ) {
			return ! preg_match( '/^sidebar(1|2)/', $class ) && 'sidebar-left' !== $class;
		} );
		$classes[] = 'sidebar-none';
		$classes[] = 'saab-bestof-page';
		return $classes;
	}

	public static function enqueue_assets() {
		if ( ! self::is_bestof() ) {
			return;
		}
		$css = SAAB_TOOLKIT_DIR . 'assets/bestof.css';
		wp_enqueue_style( 'saab-bestof', SAAB_TOOLKIT_URL . 'assets/bestof.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : SAAB_TOOLKIT_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Matching
	 * ------------------------------------------------------------------- */

	/** Lowercase words with "&" as "and", punctuation dropped and simple plurals folded ("biscuits" = "biscuit"). */
	private static function words( $text ) {
		$text  = strtolower( str_replace( '&', ' and ', remove_accents( (string) $text ) ) );
		$text  = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		$words = array();
		foreach ( preg_split( '/\s+/', trim( $text ) ) as $w ) {
			if ( '' === $w ) {
				continue;
			}
			if ( strlen( $w ) > 3 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
				$w = substr( $w, 0, -1 );
			}
			$words[] = $w;
		}
		return $words;
	}

	/** True when every word of at least one match phrase appears in $text. */
	public static function matches( $text, $phrases ) {
		$have = self::words( $text );
		foreach ( $phrases as $phrase ) {
			$need = self::words( $phrase );
			if ( $need && ! array_diff( $need, $have ) ) {
				return true;
			}
		}
		return false;
	}

	/** The restaurant post whose saab_business_ids holds $business_id (any status), or null. */
	private static function find_restaurant( $business_id ) {
		if ( '' === $business_id ) {
			return null;
		}
		$found = get_posts( array(
			'post_type'      => SAAB_Restaurants::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'     => 'saab_business_ids',
					'value'   => '"' . $business_id . '"',
					'compare' => 'LIKE',
				),
			),
			'no_found_rows'  => true,
		) );
		return $found ? $found[0] : null;
	}

	/* ---------------------------------------------------------------------
	 * Pick rows
	 * ------------------------------------------------------------------- */

	/** "Eggs Benedict at Rattlesnake Brewery – September 14, 2025" → "Eggs Benedict". */
	private static function dish_from_post_title( $post_id ) {
		$title = SAAB_Restaurants::strip_trailing_date( SAAB_Restaurants::get_raw_title( $post_id ) );
		$parts = preg_split( '/\s+at\s+/i', $title, 2 );
		return trim( $parts[0] );
	}

	private static function pick_data( $pick, $config ) {
		$post = self::find_restaurant( $pick['business_id'] );

		$data = array(
			'name'      => $pick['name'],
			'place'     => $pick['city'] ? $pick['city'] . ', WA' : '',
			'url'       => '',
			'photo_id'  => 0,
			'caption'   => '',
			'had'       => null,
			'also'      => array(),
			'rating'    => '',
			'reviews'   => 0,
			'posts'     => 0,
			'order'     => $pick['dish'],
			'order_phrases' => array(),
			'empty_label'   => $pick['dish'] ? $pick['dish'] : $config['dish'],
			'note'      => $pick['note'],
		);
		if ( ! $post ) {
			return self::apply_photo_override( $data, $pick ); // No restaurant page yet: name, city, dish.
		}

		$id            = $post->ID;
		$data['name']  = SAAB_Restaurants::get_raw_title( $id );
		$place         = SAAB_Restaurants::get_place_label( $id );
		$data['place'] = $place ? $place : $data['place'];
		$data['url']   = 'publish' === $post->post_status ? get_permalink( $post ) : '';
		$data['posts'] = count( SAAB_Restaurants::get_json_meta( $id, 'saab_linked_posts' ) );

		$rating = get_post_meta( $id, 'saab_rating', true );
		if ( '' !== $rating && (float) $rating > 0 ) {
			$data['rating']  = number_format( (float) $rating, 1 );
			$data['reviews'] = (int) get_post_meta( $id, 'saab_review_count', true );
		}

		$dishes = array_values( array_filter( SAAB_Restaurants::get_json_meta( $id, 'saab_ryan_dishes' ), 'is_array' ) );
		usort( $dishes, function ( $a, $b ) {
			return ( isset( $b['posts'] ) ? (int) $b['posts'] : 1 ) - ( isset( $a['posts'] ) ? (int) $a['posts'] : 1 );
		} );

		// "I had": the pick's own dish first ("Peppercorn Sirloin | Ribeye" is two
		// options), then the page's general match phrases.
		$phrase_sets = array();
		if ( '' !== $pick['dish'] ) {
			$data['order_phrases'] = preg_split( '/\s+[|&]\s+|\s+and\s+(?=[A-Z])/', $pick['dish'] );
			$phrase_sets[]         = $data['order_phrases'];
		}
		$phrase_sets[] = $config['match'];
		foreach ( $phrase_sets as $phrases ) {
			foreach ( $dishes as $dish ) {
				if ( ! empty( $dish['name'] ) && self::matches( $dish['name'], $phrases ) ) {
					$data['had'] = $dish;
					break 2;
				}
			}
		}

		foreach ( $dishes as $dish ) {
			if ( empty( $dish['name'] ) || ( $data['had'] && $dish['name'] === $data['had']['name'] ) ) {
				continue;
			}
			$n            = isset( $dish['posts'] ) ? (int) $dish['posts'] : 1;
			$data['also'][] = $dish['name'] . ( $n > 1 ? ' (' . $n . "\xC3\x97)" : '' );
			if ( count( $data['also'] ) >= 3 ) {
				break;
			}
		}

		// No dish on file matched: look through the linked review posts' titles for one
		// about this page's dish ("Breakfast Burrito at Amendment XXI – ..."), newest first.
		if ( ! $data['had'] ) {
			foreach ( SAAB_Restaurants::get_linked_posts_newest_first( $id ) as $linked ) {
				if ( 'publish' !== $linked->post_status ) {
					continue;
				}
				$title_dish = self::dish_from_post_title( $linked->ID );
				foreach ( $phrase_sets as $phrases ) {
					if ( $title_dish && self::matches( $title_dish, $phrases ) ) {
						$data['had'] = array( 'name' => $title_dish, 'post_id' => $linked->ID );
						break 2;
					}
				}
			}
		}

		// Photo: only one that shows this page's dish (the verified dish photo, else
		// the featured image of the review post about it). An unrelated dish photo
		// would mislead on a Best-Of page (a pizza on the breakfast burrito list), so
		// without one the row shows a plain tile instead. Pages with no dish to match
		// (Date Night) use the restaurant's newest photo.
		if ( $data['had'] ) {
			$photo = SAAB_Restaurants::dish_photo_id( $data['had'] );
			if ( ! $photo && ! empty( $data['had']['post_id'] ) ) {
				$photo = (int) get_post_thumbnail_id( (int) $data['had']['post_id'] );
			}
			if ( $photo ) {
				$data['photo_id'] = $photo;
				$data['caption']  = $data['had']['name'] . ( ! empty( $data['had']['post_id'] ) ? ' · ' . get_the_date( 'M Y', (int) $data['had']['post_id'] ) : '' );
			}
		} elseif ( ! $config['match'] && '' === $pick['dish'] && ! $pick['photo_id'] && ! $pick['no_photo'] ) {
			foreach ( SAAB_Restaurants::get_photo_pool( $id ) as $attachment_id => $source_id ) {
				$data['photo_id'] = (int) $attachment_id;
				if ( $source_id && $source_id !== $id ) {
					$data['caption'] = self::dish_from_post_title( $source_id ) . ' · ' . get_the_date( 'M Y', $source_id );
				}
				break;
			}
		}

		return self::apply_photo_override( $data, $pick );
	}

	/**
	 * Ryan's own photo choice for this row (set with "Choose photo" in the Best-Of
	 * Layout box) beats the automatic one. Caption: the dish he had, else the dish
	 * from the post the photo was uploaded to, else the pick's "Order" dish.
	 */
	private static function apply_photo_override( $data, $pick ) {
		if ( $pick['no_photo'] ) {
			// Ryan removed the photo for this row: show the plain tile instead.
			$data['photo_id'] = 0;
			$data['caption']  = '';
			return $data;
		}
		$photo = (int) $pick['photo_id'];
		if ( ! $photo || ! wp_attachment_is_image( $photo ) ) {
			return $data;
		}
		$data['photo_id'] = $photo;
		$parent           = (int) wp_get_post_parent_id( $photo );
		if ( $data['had'] && ( ! $parent || (int) $data['had']['post_id'] === $parent ) ) {
			$data['caption'] = $data['had']['name'] . ( ! empty( $data['had']['post_id'] ) ? ' · ' . get_the_date( 'M Y', (int) $data['had']['post_id'] ) : '' );
		} elseif ( $parent && 'post' === get_post_type( $parent ) ) {
			$data['caption'] = self::dish_from_post_title( $parent ) . ' · ' . get_the_date( 'M Y', $parent );
		} elseif ( '' !== $pick['dish'] ) {
			// A photo not attached to a review post: caption it with what to order here.
			$data['caption'] = str_replace( ' | ', ' or ', $pick['dish'] );
		} else {
			$data['caption'] = '';
		}
		return $data;
	}

	private static function render_pick( $d ) {
		ob_start();
		?>
		<article class="saab-bestof__row">
			<figure class="saab-bestof__photo<?php echo $d['photo_id'] ? '' : ' saab-bestof__photo--empty'; ?>">
				<?php if ( $d['photo_id'] ) : ?>
					<?php
					echo wp_get_attachment_image( $d['photo_id'], 'large', false, array(
						'loading' => 'lazy',
						'sizes'   => '(max-width: 760px) 100vw, 480px',
						'alt'     => $d['caption'] ? trim( explode( ' · ', $d['caption'] )[0] ) . ' at ' . $d['name'] : $d['name'],
					) );
					?>
					<?php if ( $d['caption'] ) : ?>
						<figcaption><?php echo esc_html( $d['caption'] ); ?></figcaption>
					<?php endif; ?>
				<?php else : ?>
					<span><?php echo esc_html( $d['empty_label'] ? 'No ' . strtolower( str_replace( ' | ', ' or ', $d['empty_label'] ) ) . ' photo yet' : $d['name'] ); ?></span>
				<?php endif; ?>
			</figure>
			<div class="saab-bestof__row-body">
				<h3><?php echo esc_html( $d['name'] ); ?> <?php if ( $d['place'] ) : ?><span class="saab-bestof__city"><?php echo esc_html( $d['place'] ); ?></span><?php endif; ?></h3>
				<dl>
					<?php if ( $d['had'] ) : ?>
						<dt>I had</dt>
						<dd>
							<?php if ( ! empty( $d['had']['post_id'] ) && 'publish' === get_post_status( (int) $d['had']['post_id'] ) ) : ?>
								<a href="<?php echo esc_url( get_permalink( (int) $d['had']['post_id'] ) ); ?>"><?php echo esc_html( $d['had']['name'] ); ?></a>, <?php echo esc_html( get_the_date( 'F Y', (int) $d['had']['post_id'] ) ); ?>
							<?php else : ?>
								<?php echo esc_html( $d['had']['name'] ); ?>
							<?php endif; ?>
						</dd>
					<?php endif; ?>
					<?php if ( $d['order'] && ( ! $d['had'] || ! self::matches( $d['had']['name'], $d['order_phrases'] ) ) ) : ?>
						<dt>Order</dt>
						<dd><?php echo esc_html( str_replace( ' | ', ' or ', $d['order'] ) ); ?></dd>
					<?php endif; ?>
					<?php if ( $d['note'] ) : ?>
						<dt>Why</dt>
						<dd><?php echo esc_html( $d['note'] ); ?></dd>
					<?php endif; ?>
					<?php if ( $d['also'] ) : ?>
						<dt>Also good here</dt>
						<dd><?php echo esc_html( implode( ', ', $d['also'] ) ); ?></dd>
					<?php endif; ?>
					<?php if ( $d['rating'] ) : ?>
						<dt>Google</dt>
						<dd class="saab-bestof__num">&#9733; <?php echo esc_html( $d['rating'] ); ?><?php echo $d['reviews'] ? ' (' . esc_html( number_format_i18n( $d['reviews'] ) ) . ' reviews)' : ''; ?></dd>
					<?php endif; ?>
					<?php if ( $d['posts'] > 1 ) : ?>
						<dt>Visits</dt>
						<dd class="saab-bestof__num"><?php echo (int) $d['posts']; ?> posts</dd>
					<?php endif; ?>
				</dl>
				<?php if ( $d['url'] ) : ?>
					<p class="saab-bestof__more"><a href="<?php echo esc_url( $d['url'] ); ?>">Restaurant page &rarr;</a></p>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * "Also on the Menu At": other published restaurant pages whose menu
	 * lists the dish. Only places with a page are shown, so the table grows
	 * on its own as pages are added.
	 * ------------------------------------------------------------------- */

	private static function also_on_menu( $page_id, $config ) {
		$key    = self::ALSO_CACHE_PREFIX . $page_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$skip = array();
		foreach ( $config['picks'] as $pick ) {
			$skip[] = $pick['business_id'];
		}

		$rows  = array();
		$posts = get_posts( array(
			'post_type'      => SAAB_Restaurants::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		) );
		foreach ( $posts as $post ) {
			$ids = SAAB_Restaurants::get_json_meta( $post->ID, 'saab_business_ids' );
			if ( array_intersect( $ids, $skip ) ) {
				continue;
			}
			$items = array();
			foreach ( SAAB_Restaurants::get_json_meta( $post->ID, 'saab_menu' ) as $item ) {
				$name = is_array( $item ) ? ( ! empty( $item['item'] ) ? $item['item'] : ( ! empty( $item['name'] ) ? $item['name'] : '' ) ) : '';
				if ( $name && self::matches( $name, $config['match'] ) ) {
					$items[] = $name;
				}
			}
			if ( $items ) {
				$deepest = SAAB_Restaurants::get_deepest_region_term( $post->ID );
				$rows[]  = array(
					'id'    => $post->ID,
					'name'  => SAAB_Restaurants::get_raw_title( $post->ID ),
					'city'  => $deepest ? $deepest->name : '',
					'items' => array_slice( array_unique( $items ), 0, 3 ),
				);
			}
		}

		usort( $rows, function ( $a, $b ) {
			return strnatcasecmp( $a['city'] . ' ' . $a['name'], $b['city'] . ' ' . $b['name'] );
		} );

		$rows = array_slice( $rows, 0, 15 );
		set_transient( $key, $rows, 12 * HOUR_IN_SECONDS );
		return $rows;
	}

	public static function flush_also_cache() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . self::ALSO_CACHE_PREFIX ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . self::ALSO_CACHE_PREFIX ) . '%'
		) );
	}

	public static function maybe_flush_on_meta( $meta_id, $object_id, $meta_key ) {
		if ( in_array( $meta_key, array( 'saab_menu', 'saab_business_ids' ), true ) ) {
			self::flush_also_cache();
		} elseif ( self::META_KEY === $meta_key ) {
			delete_transient( self::ALSO_CACHE_PREFIX . $object_id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------- */

	public static function render() {
		$page_id = get_queried_object_id();
		$config  = self::get_config( $page_id );
		if ( ! $config ) {
			return '';
		}

		$hero_id = $config['hero_id'] ? $config['hero_id'] : (int) get_post_thumbnail_id( $page_id );
		$picks   = array();
		foreach ( $config['picks'] as $pick ) {
			$picks[] = self::pick_data( $pick, $config );
		}
		$also = self::also_on_menu( $page_id, $config );

		ob_start();
		?>
		<div class="saab-bestof">
			<header class="saab-bestof__header">
				<p class="saab-bestof__eyebrow">
					<a href="<?php echo esc_url( home_url( self::INDEX_PATH ) ); ?>">Best in Tri-Cities</a><?php echo $config['eyebrow'] ? ' &middot; ' . esc_html( $config['eyebrow'] ) : ''; ?>
				</p>
				<h1 class="saab-bestof__title"><?php echo esc_html( get_the_title( $page_id ) ); ?></h1>
			</header>

			<?php if ( $hero_id ) : ?>
				<figure class="saab-bestof__hero">
					<?php echo wp_get_attachment_image( $hero_id, 'full', false, array( 'sizes' => '(max-width: 1200px) 100vw, 1200px', 'fetchpriority' => 'high', 'alt' => $config['hero_caption'] ? $config['hero_caption'] : get_the_title( $page_id ) ) ); ?>
					<?php if ( $config['hero_caption'] ) : ?>
						<figcaption><?php echo esc_html( $config['hero_caption'] ); ?></figcaption>
					<?php endif; ?>
				</figure>
			<?php endif; ?>

			<div class="saab-bestof__intro">
				<div class="saab-bestof__intro-text">
					<?php if ( $config['intro'] ) : ?>
						<p class="saab-bestof__lede"><?php echo esc_html( $config['intro'] ); ?></p>
					<?php endif; ?>
					<?php if ( $config['note'] ) : ?>
						<p class="saab-bestof__note"><?php echo esc_html( $config['note'] ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $config['criteria'] ) : ?>
					<aside class="saab-bestof__checklist">
						<h2>What I Look For</h2>
						<ul>
							<?php foreach ( $config['criteria'] as $c ) : ?>
								<li><span><b><?php echo esc_html( $c['label'] ); ?></b><?php echo esc_html( $c['text'] ); ?></span></li>
							<?php endforeach; ?>
						</ul>
					</aside>
				<?php endif; ?>
			</div>

			<section class="saab-bestof__picks">
				<h2>My Picks</h2>
				<div class="saab-bestof__rows">
					<?php foreach ( $picks as $d ) : ?>
						<?php echo self::render_pick( $d ); // Escaped inside. ?>
					<?php endforeach; ?>
				</div>
			</section>

			<?php if ( $also ) : ?>
				<section class="saab-bestof__also">
					<h2>Also on the Menu At</h2>
					<p class="saab-bestof__sub">Other local spots with a page here whose menu lists <?php echo esc_html( $config['dish'] ? strtolower( $config['dish'] ) : 'it' ); ?>.</p>
					<div class="saab-bestof__table-wrap">
						<table class="saab-bestof__table">
							<thead><tr><th>Place</th><th>City</th><th>On the menu as</th></tr></thead>
							<tbody>
								<?php foreach ( $also as $row ) : ?>
									<tr>
										<td><a href="<?php echo esc_url( get_permalink( $row['id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
										<td><?php echo esc_html( $row['city'] ); ?></td>
										<td><?php echo esc_html( implode( ', ', $row['items'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Admin: raw JSON editor, so the page's text can be tweaked in WordPress
	 * ------------------------------------------------------------------- */

	public static function add_meta_box() {
		add_meta_box( 'saab-bestof', 'Best-Of Layout', array( __CLASS__, 'render_meta_box' ), 'page', 'normal', 'default' );
	}

	/** Media library + the per-pick photo picker, on page edit screens only. */
	public static function enqueue_admin( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'page' !== get_post_type() ) {
			return;
		}
		wp_enqueue_media();
		$js = SAAB_TOOLKIT_DIR . 'assets/bestof-admin.js';
		wp_enqueue_script( 'saab-bestof-admin', SAAB_TOOLKIT_URL . 'assets/bestof-admin.js', array( 'jquery' ), file_exists( $js ) ? (string) filemtime( $js ) : SAAB_TOOLKIT_VERSION, true );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'saab_bestof_save', 'saab_bestof_nonce' );
		$raw     = get_post_meta( $post->ID, self::META_KEY, true );
		$decoded = $raw ? json_decode( $raw, true ) : null;
		$pretty  = is_array( $decoded ) ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
		?>
		<p>When this holds a config with at least one pick, the page uses the restaurant-style Best-Of layout instead of its normal content. Empty it to go back to the original page (nothing else is changed).</p>
		<div id="saab-bestof-photos" data-thumbs="<?php echo esc_attr( wp_json_encode( self::pick_thumbs( $decoded ) ) ); ?>"></div>
		<details style="margin-top:12px">
			<summary style="cursor:pointer;font-weight:600">Edit the page text and picks (JSON)</summary>
			<textarea id="saab-bestof-json" name="saab_bestof" rows="18" style="width:100%;font-family:monospace;font-size:12px;margin-top:8px"><?php echo esc_textarea( $pretty ); ?></textarea>
		</details>
		<p class="description">Fields: dish, match (phrases; every word of one phrase must appear in a dish or menu item), eyebrow, intro, note, criteria [{label, text}], picks [{business_id, name, city}], hero_id (optional; defaults to the featured image).</p>
		<?php
	}

	/** Thumbnail URLs for picks that already have a chosen photo, keyed by attachment ID. */
	private static function pick_thumbs( $config ) {
		$thumbs = array();
		if ( is_array( $config ) && ! empty( $config['picks'] ) ) {
			foreach ( $config['picks'] as $pick ) {
				$id = isset( $pick['photo_id'] ) ? absint( $pick['photo_id'] ) : 0;
				if ( $id ) {
					$src = wp_get_attachment_image_url( $id, 'thumbnail' );
					if ( $src ) {
						$thumbs[ $id ] = $src;
					}
				}
			}
		}
		return $thumbs;
	}

	public static function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['saab_bestof_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['saab_bestof_nonce'] ), 'saab_bestof_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['saab_bestof'] ) ? trim( wp_unslash( $_POST['saab_bestof'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized as JSON below.
		if ( '' === $raw ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			// update_post_meta() runs sanitize_meta(), which re-encodes cleanly.
			update_post_meta( $post_id, self::META_KEY, wp_slash( wp_json_encode( $decoded ) ) );
		}
	}
}
