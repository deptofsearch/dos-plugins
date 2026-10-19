<?php
/**
 * Restaurant browse pages: /restaurants/ (every place) and the region
 * archives under /restaurants/in/washington/tri-cities/kennewick/.
 *
 * Full width, no theme sidebar. Every place in the region renders server-side
 * as a photo card, A–Z, so the page works and indexes without JavaScript;
 * restaurant-browse.js then filters those cards in place by name/dish search,
 * city pill and cuisine pill. Dish search reads a JSON block of each place's
 * menu items, Google-review dishes and Ryan's dishes.
 *
 * Loaded and started by SAAB_Restaurants::init().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'SAAB_Restaurant_Browse', false ) ) {
	return;
}

class SAAB_Restaurant_Browse {

	/** Top-level "all places" URL: /restaurants/. */
	const ALL_SLUG = 'restaurants';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ), 11 );
		add_action( 'pre_get_posts', array( __CLASS__, 'widen_browse_query' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * The post type is registered with rewrite disabled (root-level single
	 * URLs are built by hand), so core adds no archive rule for it. Map the
	 * bare /restaurants/ path to the post type archive ourselves; the region
	 * rules under /restaurants/in/ are longer and unaffected.
	 */
	public static function add_rewrite_rule() {
		add_rewrite_rule( '^' . self::ALL_SLUG . '/?$', 'index.php?post_type=' . SAAB_Restaurants::POST_TYPE, 'top' );
	}

	public static function is_browse( $query = null ) {
		if ( null === $query ) {
			global $wp_query;
			$query = $wp_query;
		}
		if ( ! $query ) {
			return false;
		}

		return $query->is_tax( SAAB_Restaurants::TAXONOMY ) || $query->is_post_type_archive( SAAB_Restaurants::POST_TYPE );
	}

	/** One unpaginated page, A–Z, published restaurants only. */
	public static function widen_browse_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! self::is_browse( $query ) ) {
			return;
		}

		$query->set( 'post_type', SAAB_Restaurants::POST_TYPE );
		$query->set( 'post_status', 'publish' );
		$query->set( 'posts_per_page', -1 );
		$query->set( 'orderby', 'title' );
		$query->set( 'order', 'ASC' );
		$query->set( 'no_found_rows', true );
	}

	public static function template_include( $template ) {
		if ( ! self::is_browse() ) {
			return $template;
		}

		$ours = SAAB_TOOLKIT_DIR . 'templates/restaurant-browse.php';
		return file_exists( $ours ) ? $ours : $template;
	}

	/** Same three-way sidebar removal as the single page; see SAAB_Restaurants::filter_body_class(). */
	public static function filter_body_class( $classes ) {
		if ( ! self::is_browse() ) {
			return $classes;
		}

		$classes = array_filter( $classes, function ( $class ) {
			return ! preg_match( '/^sidebar(1|2)/', $class ) && 'sidebar-left' !== $class;
		} );

		$classes[] = 'sidebar-none';
		$classes[] = 'saab-restaurant-page';
		$classes[] = 'saab-browse-page';

		return $classes;
	}

	public static function filter_document_title( $parts ) {
		if ( self::is_browse() ) {
			$parts['title'] = self::heading();
		}
		return $parts;
	}

	public static function enqueue_assets() {
		if ( ! self::is_browse() ) {
			return;
		}

		// filemtime versions for the same one-year-cache reason as restaurants.css.
		$css = SAAB_TOOLKIT_DIR . 'assets/restaurant-browse.css';
		$js  = SAAB_TOOLKIT_DIR . 'assets/restaurant-browse.js';

		wp_enqueue_style( 'saab-restaurant-browse', SAAB_TOOLKIT_URL . 'assets/restaurant-browse.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : SAAB_TOOLKIT_VERSION );
		wp_enqueue_script( 'saab-restaurant-browse', SAAB_TOOLKIT_URL . 'assets/restaurant-browse.js', array(), file_exists( $js ) ? (string) filemtime( $js ) : SAAB_TOOLKIT_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Page parts
	 * ------------------------------------------------------------------- */

	/** The region term being browsed, or null on /restaurants/. */
	private static function current_term() {
		if ( ! is_tax( SAAB_Restaurants::TAXONOMY ) ) {
			return null;
		}
		$term = get_queried_object();
		return ( $term instanceof WP_Term ) ? $term : null;
	}

	/** "Kennewick, WA" for a city, "Tri-Cities, WA" for an area, "Washington" for a state. */
	private static function place_label( $term ) {
		$ancestors = get_ancestors( $term->term_id, SAAB_Restaurants::TAXONOMY, 'taxonomy' );
		if ( ! $ancestors ) {
			return $term->name;
		}

		$state = get_term( end( $ancestors ), SAAB_Restaurants::TAXONOMY );
		if ( ! $state || is_wp_error( $state ) ) {
			return $term->name;
		}

		return $term->name . ', ' . SAAB_Restaurants::state_abbreviation( $state->name );
	}

	public static function heading() {
		$term = self::current_term();
		return $term ? 'Restaurants in ' . self::place_label( $term ) : 'Local Restaurants';
	}

	/** Restaurants › Washington › Tri-Cities › Kennewick; the current level is plain text. */
	private static function render_breadcrumb( $term ) {
		$crumbs = array();

		if ( $term ) {
			$crumbs[] = '<a href="' . esc_url( home_url( '/' . self::ALL_SLUG . '/' ) ) . '">Restaurants</a>';

			$ancestors = array_reverse( get_ancestors( $term->term_id, SAAB_Restaurants::TAXONOMY, 'taxonomy' ) );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, SAAB_Restaurants::TAXONOMY );
				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					$crumbs[] = '<a href="' . esc_url( get_term_link( $ancestor ) ) . '">' . esc_html( $ancestor->name ) . '</a>';
				}
			}
			$crumbs[] = '<span aria-current="page">' . esc_html( $term->name ) . '</span>';
		}

		if ( ! $crumbs ) {
			return '';
		}

		return '<nav class="saab-browse__crumbs" aria-label="Breadcrumb">' . implode( ' <span aria-hidden="true">&rsaquo;</span> ', $crumbs ) . '</nav>';
	}

	/** Blog cuisine categories read "Vietnamese Food in Tri-Cities"; a pill or card only needs "Vietnamese Food". */
	private static function short_cuisine( $name ) {
		return trim( preg_replace( '/\s+in\s+.+$/i', '', $name ) );
	}

	/** Everything a card and the filter need for one place. */
	private static function card_data( $post ) {
		$post_id = $post->ID;

		$city_term = SAAB_Restaurants::get_deepest_region_term( $post_id );

		$cuisines = array();
		foreach ( get_the_category( $post_id ) as $category ) {
			$cuisines[ $category->slug ] = self::short_cuisine( $category->name );
		}

		// Dish names for search: menu items, Google-review dishes, Ryan's dishes.
		$dishes = array();
		foreach ( array( 'saab_ryan_dishes', 'saab_popular_dishes', 'saab_menu' ) as $key ) {
			foreach ( SAAB_Restaurants::get_json_meta( $post_id, $key ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name = ! empty( $row['name'] ) ? $row['name'] : ( ! empty( $row['item'] ) ? $row['item'] : '' );
				$name = trim( wp_strip_all_tags( (string) $name ) );
				if ( '' !== $name ) {
					$dishes[ strtolower( $name ) ] = $name;
				}
			}
		}

		$pool     = SAAB_Restaurants::get_photo_pool( $post_id );
		$photo_id = $pool ? (int) key( $pool ) : 0;

		$rating = get_post_meta( $post_id, 'saab_rating', true );

		return array(
			'id'        => $post_id,
			'title'     => get_the_title( $post ),
			'url'       => get_permalink( $post ),
			'city'      => $city_term ? $city_term->name : '',
			'city_slug' => $city_term ? $city_term->slug : '',
			'cuisines'  => $cuisines,
			'dishes'    => array_values( $dishes ),
			'photo_id'  => $photo_id,
			'visits'    => count( SAAB_Restaurants::get_json_meta( $post_id, 'saab_linked_posts' ) ),
			'rating'    => '' !== $rating ? number_format( (float) $rating, 1 ) : '',
		);
	}

	/** A pill row, or nothing when there's only one choice to make. */
	private static function render_pills( $group, $label, $options ) {
		if ( count( $options ) < 2 ) {
			return '';
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		ob_start();
		?>
		<div class="saab-browse__pills" role="group" aria-label="<?php echo esc_attr( $label ); ?>" data-group="<?php echo esc_attr( $group ); ?>">
			<span class="saab-browse__pills-label"><?php echo esc_html( $label ); ?></span>
			<button type="button" class="saab-browse__pill" data-value="" aria-pressed="true">All</button>
			<?php foreach ( $options as $slug => $name ) : ?>
				<button type="button" class="saab-browse__pill" data-value="<?php echo esc_attr( $slug ); ?>" aria-pressed="false"><?php echo esc_html( $name ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_card( $card ) {
		ob_start();
		?>
		<li class="saab-browse__card" data-id="<?php echo (int) $card['id']; ?>" data-city="<?php echo esc_attr( $card['city_slug'] ); ?>" data-cuisines="<?php echo esc_attr( implode( ' ', array_keys( $card['cuisines'] ) ) ); ?>">
			<a class="saab-browse__card-link" href="<?php echo esc_url( $card['url'] ); ?>">
				<span class="saab-browse__card-photo">
					<?php if ( $card['photo_id'] ) : ?>
						<?php
						echo wp_get_attachment_image( $card['photo_id'], 'medium_large', false, array(
							'loading' => 'lazy',
							'sizes'   => '(max-width: 600px) 100vw, (max-width: 1000px) 50vw, 360px',
							'alt'     => $card['title'],
						) );
						?>
					<?php else : ?>
						<span class="saab-browse__card-initial" aria-hidden="true"><?php echo esc_html( mb_substr( $card['title'], 0, 1 ) ); ?></span>
					<?php endif; ?>
					<?php if ( $card['visits'] ) : ?>
						<span class="saab-browse__card-flag">Ryan's eaten here</span>
					<?php endif; ?>
				</span>
				<span class="saab-browse__card-body">
					<span class="saab-browse__card-title"><?php echo esc_html( $card['title'] ); ?></span>
					<span class="saab-browse__card-meta">
						<?php echo esc_html( implode( ' · ', array_filter( array_merge( array( $card['city'] ), array_values( $card['cuisines'] ) ) ) ) ); ?>
					</span>
					<span class="saab-browse__card-dishes" hidden></span>
					<span class="saab-browse__card-foot">
						<?php if ( $card['visits'] ) : ?>
							<span><?php echo esc_html( sprintf( _n( '%d post', '%d posts', $card['visits'] ), $card['visits'] ) ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $card['rating'] ) : ?>
							<span>&#9733; <?php echo esc_html( $card['rating'] ); ?> Google</span>
						<?php endif; ?>
					</span>
				</span>
			</a>
		</li>
		<?php
		return ob_get_clean();
	}

	/** The whole page body; the template wraps it in the theme's layout markup. */
	public static function render() {
		global $wp_query;

		$term  = self::current_term();
		$cards = array();
		foreach ( $wp_query->posts as $post ) {
			$cards[] = self::card_data( $post );
		}

		// orderby=title is a byte sort; natural, case-insensitive A–Z reads better ("7th Street" before "Atomic").
		usort( $cards, function ( $a, $b ) {
			return strnatcasecmp( $a['title'], $b['title'] );
		} );

		$cities   = array();
		$cuisines = array();
		foreach ( $cards as $card ) {
			if ( $card['city_slug'] ) {
				$cities[ $card['city_slug'] ] = $card['city'];
			}
			$cuisines = array_merge( $cuisines, $card['cuisines'] );
		}

		$search_data = array();
		foreach ( $cards as $card ) {
			$search_data[ $card['id'] ] = array(
				't' => $card['title'],
				'm' => implode( ' ', array_merge( array( $card['city'] ), array_values( $card['cuisines'] ) ) ),
				'd' => $card['dishes'],
			);
		}

		$description = $term ? trim( $term->description ) : '';
		$where       = $term ? self::place_label( $term ) : 'the Tri-Cities and beyond';
		$count       = count( $cards );

		ob_start();
		?>
		<div class="saab-browse">
			<?php echo self::render_breadcrumb( $term ); // Escaped inside. ?>

			<header class="saab-browse__header">
				<h1 class="saab-browse__title"><?php echo esc_html( self::heading() ); ?></h1>
				<?php if ( $description ) : ?>
					<div class="saab-browse__intro"><?php echo wp_kses_post( wpautop( $description ) ); ?></div>
				<?php else : ?>
					<p class="saab-browse__intro">Local and regional spots in <?php echo esc_html( $where ); ?>, A to Z. Search by name or by dish, or narrow the list by city and cuisine.</p>
				<?php endif; ?>
			</header>

			<?php if ( $cards ) : ?>
				<h2 class="saab-browse__count-heading">Browse All <?php echo (int) $count; ?> <?php echo 1 === $count ? 'Place' : 'Places'; ?></h2>

				<form class="saab-browse__search" role="search" action="" method="get">
					<label class="screen-reader-text" for="saab-browse-q">Search places or dishes</label>
					<input id="saab-browse-q" type="search" name="q" autocomplete="off" placeholder="Search places or dishes (try &ldquo;birria ramen&rdquo;)">
				</form>

				<?php echo self::render_pills( 'city', 'City', $cities ); // Escaped inside. ?>
				<?php echo self::render_pills( 'cuisine', 'Cuisine', $cuisines ); // Escaped inside. ?>

				<p class="saab-browse__status" aria-live="polite">Showing <?php echo (int) $count; ?> <?php echo 1 === $count ? 'place' : 'places'; ?></p>

				<ul class="saab-browse__grid">
					<?php foreach ( $cards as $card ) : ?>
						<?php echo self::render_card( $card ); // Escaped inside. ?>
					<?php endforeach; ?>
				</ul>

				<div class="saab-browse__empty" hidden>
					<p>No places match that yet.</p>
					<button type="button" class="saab-browse__clear">Clear search and filters</button>
				</div>

				<script type="application/json" id="saab-browse-data"><?php echo wp_json_encode( $search_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
			<?php else : ?>
				<p class="saab-browse__status">No restaurant pages here yet.</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
