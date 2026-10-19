<?php
/**
 * Homepage takeover: while switched on, the front page is drawn by this plugin's own template.
 *
 * Order: hero (eyebrow, H1, intro, search-type buttons, search box), category cards, latest reviews, city links.
 * Nothing in the site is edited; switching the setting off restores the homepage as it was.
 *
 * Why a template and not a the_content filter (0.1.0 used one, as DoS Outdoor Seating does): on shotandabeer.com the
 * 0.1.0 takeover never showed. A content filter only works when the front page is a static page AND the theme runs it
 * through the main loop's the_content; a front page set to "Your latest posts", or a Themify layout that builds the
 * front page some other way, skips it. template_include swaps the whole template instead, so it works with either
 * Reading setting and whatever Themify Builder holds for the page (the template never calls the_content, so Builder
 * has nothing to print). The theme's header and footer still come from get_header() / get_footer().
 *
 * @package DOS_SAAB
 */

namespace DOS_SAAB;

defined( 'ABSPATH' ) || exit;

final class Home {

	const OPTION    = 'saab_home';
	const IMAGE_KEY = 'saab_card_image';

	public static function defaults() {
		return array(
			'on'      => false,
			'eyebrow' => 'Tri-Cities food & drink reviews',
			'h1'      => 'What are you hungry for?',
			'intro'   => "Search a restaurant, a cuisine, or one specific dish. Every review says what we ordered and whether we'd get it again.",
			'cats'    => '',
			'cats_shown' => 8,
			'latest'  => 6,
			'cities'  => 8,
		);
	}

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	public static function hooks() {
		// Late, so a theme or Builder template swap at the default priority has already run and is overridden.
		add_filter( 'template_include', array( __CLASS__, 'template' ), PHP_INT_MAX - 10 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'template_redirect', array( __CLASS__, 'nocache_preview' ), 1 );
	}

	/* ---------------------------------------------------------------- state of the request */

	/**
	 * True when the takeover renders on this request: the setting is on, or an admin is previewing. Any front page
	 * counts, a static page or the latest-posts home, but not page 2 and later of a latest-posts home, which stays
	 * the theme's post list.
	 */
	public static function active() {
		if ( is_admin() || ! did_action( 'wp' ) || ! is_front_page() || is_paged() || is_feed() ) {
			return false;
		}
		if ( self::settings()['on'] ) {
			return true;
		}
		return isset( $_GET['saab_home_preview'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function preview_url() {
		return add_query_arg( 'saab_home_preview', '1', home_url( '/' ) );
	}

	/** An admin preview must never be stored by a page cache. */
	public static function nocache_preview() {
		if ( isset( $_GET['saab_home_preview'] ) && self::active() ) { // phpcs:ignore WordPress.Security.NonceVerification
			nocache_headers();
		}
	}

	public static function body_class( $classes ) {
		if ( self::active() ) {
			$classes[] = 'saab-home-on';
		}
		return $classes;
	}

	public static function enqueue() {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( 'saab-home', DOS_SAAB_HOME_URL . 'assets/saab-home.css', array(), DOS_SAAB_HOME_VERSION );
		wp_enqueue_script( 'saab-home', DOS_SAAB_HOME_URL . 'assets/saab-home.js', array(), DOS_SAAB_HOME_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
	}

	/** The front page is drawn by templates/front-page.php: the theme's header, this layout, the theme's footer. */
	public static function template( $template ) {
		if ( ! self::active() ) {
			return $template;
		}
		$ours = dirname( DOS_SAAB_HOME_FILE ) . '/templates/front-page.php';
		return is_readable( $ours ) ? $ours : $template;
	}

	/* ---------------------------------------------------------------- data */

	/**
	 * Categories for the cards: the slugs listed in the setting, in that order; or, when it is empty, every category
	 * with a published review, most reviews first, leaving out the default (Uncategorized) one.
	 *
	 * @return \WP_Term[]
	 */
	public static function categories() {
		$slugs = array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', (string) self::settings()['cats'] ) ) );
		if ( $slugs ) {
			$out = array();
			foreach ( $slugs as $slug ) {
				$t = get_term_by( 'slug', $slug, 'category' );
				if ( $t && ! is_wp_error( $t ) ) {
					$out[] = $t;
				}
			}
			return $out;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'exclude'    => array( (int) get_option( 'default_category' ) ),
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * A card's photo: the attachment set on the category (Posts → Categories → edit), else the featured image of the
	 * category's newest review that has one, else none (the card shows a grey band).
	 */
	public static function category_image_id( \WP_Term $term ) {
		$id = (int) get_term_meta( $term->term_id, self::IMAGE_KEY, true );
		if ( $id && wp_attachment_is_image( $id ) ) {
			return $id;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'cat'            => $term->term_id,
				'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? (int) get_post_thumbnail_id( $ids[0] ) : 0;
	}

	/** First term name of $tax on a post, or ''. */
	private static function term_name( $post_id, $tax ) {
		if ( ! taxonomy_exists( $tax ) ) {
			return '';
		}
		$t = get_the_terms( $post_id, $tax );
		return ( $t && ! is_wp_error( $t ) ) ? html_entity_decode( $t[0]->name, ENT_QUOTES, 'UTF-8' ) : '';
	}

	/* ---------------------------------------------------------------- markup */

	public static function render() {
		$s = self::settings();
		ob_start();
		echo '<div class="saab-home">';
		echo self::hero_html( $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo self::categories_html( (int) $s['cats_shown'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo self::latest_html( (int) $s['latest'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo self::cities_html( (int) $s['cities'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo '</div>';
		return ob_get_clean();
	}

	private static function hero_html( array $s ) {
		$groups = Search::groups();
		$scopes = array( '' => __( 'Everything', 'dos-saab-home' ) ) + $groups;
		$ph     = array(
			''  => __( 'Restaurant, cuisine or dish', 'dos-saab-home' ),
			'r' => __( 'Search restaurants', 'dos-saab-home' ),
			'c' => __( 'Search cuisines', 'dos-saab-home' ),
			'd' => __( 'Search dishes', 'dos-saab-home' ),
			'x' => __( 'Search cities', 'dos-saab-home' ),
		);
		$data = array(
			// Terms only: small enough to ride in the page so suggestions start at once. The script swaps in the full
			// index (terms plus every review's dish) from `full` the first time the box is used.
			'index'    => Search::index(),
			'full'     => rest_url( Search::NS . '/index' ),
			'groups'   => $groups,
			'ph'       => $ph,
			'per'      => Search::PER_GROUP,
			'perScope' => Search::PER_SCOPE,
			'empty'    => __( 'Nothing matches “%s” yet.', 'dos-saab-home' ),
			/* translators: 1: number of matches, 2: kind in lower case, e.g. "dishes" */
			'all'      => __( 'All %1$d %2$s', 'dos-saab-home' ),
		);

		$o  = '<section class="saab-hero"><div class="saab-hero__in">';
		if ( '' !== trim( $s['eyebrow'] ) ) {
			$o .= '<p class="saab-eyebrow">' . esc_html( $s['eyebrow'] ) . '</p>';
		}
		$o .= '<h1 class="saab-hero__title">' . esc_html( $s['h1'] ) . '</h1>';
		if ( '' !== trim( $s['intro'] ) ) {
			$o .= '<p class="saab-hero__intro">' . esc_html( $s['intro'] ) . '</p>';
		}
		$o .= '<div class="saab-scopes" role="group" aria-label="' . esc_attr__( 'Search in', 'dos-saab-home' ) . '">';
		foreach ( $scopes as $key => $label ) {
			$o .= '<button type="button" class="saab-scope" data-scope="' . esc_attr( $key ) . '" aria-pressed="' . ( '' === $key ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
		}
		$o .= '</div>';
		$o .= '<form class="saab-search" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '" data-saab-search>';
		$o .= '<label class="saab-sr" for="saab-q">' . esc_html__( 'Search restaurants, cuisines and dishes', 'dos-saab-home' ) . '</label>';
		$o .= '<input id="saab-q" class="saab-search__input" type="search" name="s" autocomplete="off" placeholder="' . esc_attr( $ph[''] ) . '" role="combobox" aria-expanded="false" aria-controls="saab-list" aria-autocomplete="list">';
		$o .= '<button type="submit" class="saab-search__go" aria-label="' . esc_attr__( 'Search', 'dos-saab-home' ) . '"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14z M21 21l-5-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg></button>';
		$o .= '<div id="saab-list" class="saab-search__list" role="listbox" aria-label="' . esc_attr__( 'Suggestions', 'dos-saab-home' ) . '" hidden></div>';
		$o .= '<script type="application/json" class="saab-search__data">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
		$o .= '</form>';
		$o .= '</div></section>';
		return $o;
	}

	/**
	 * The category cards, folded: the first $shown show, the rest sit behind a "Show all" button (0 = no fold).
	 *
	 * Every card is in the markup either way, so search engines and visitors without JavaScript get them all; the
	 * script folds the extras (adds .is-folded) and reveals the button, so nothing is hidden that cannot be opened.
	 */
	private static function categories_html( $shown ) {
		$cats = self::categories();
		if ( ! $cats ) {
			return '';
		}
		$extra = $shown > 0 ? max( 0, count( $cats ) - $shown ) : 0;
		$o     = '<section class="saab-sec saab-catsec" aria-labelledby="saab-cats-h"' . ( $extra ? ' data-saab-fold' : '' ) . '><h2 id="saab-cats-h" class="saab-sec__title">' . esc_html__( 'Pick a category', 'dos-saab-home' ) . '</h2><ul id="saab-cats" class="saab-cats">';
		foreach ( $cats as $i => $t ) {
			$img  = self::category_image_id( $t );
			$html = $img ? wp_get_attachment_image(
				$img,
				'medium_large',
				false,
				array(
					'class'    => 'saab-cat__img',
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
					'sizes'    => '(max-width: 600px) 50vw, 280px',
				)
			) : '';
			$desc = trim( wp_strip_all_tags( term_description( $t ) ) );
			$o   .= '<li' . ( $extra && $i >= $shown ? ' class="saab-cats__extra"' : '' ) . '><a class="saab-cat' . ( $html ? ' saab-cat--photo' : '' ) . '" href="' . esc_url( get_term_link( $t ) ) . '">'
				. $html
				. '<span class="saab-cat__text"><span class="saab-cat__name">' . esc_html( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) ) . '</span>'
				. ( '' !== $desc ? '<span class="saab-cat__blurb">' . esc_html( wp_trim_words( $desc, 8 ) ) . '</span>' : '' )
				. '<span class="saab-cat__count">' . esc_html( Search::reviews( (int) $t->count ) ) . '</span></span></a></li>';
		}
		$o .= '</ul>';
		if ( $extra ) {
			/* translators: %d: number of categories */
			$all = sprintf( _n( 'Show all %d category', 'Show all %d categories', count( $cats ), 'dos-saab-home' ), count( $cats ) );
			$o  .= '<button type="button" class="saab-fold" aria-controls="saab-cats" aria-expanded="false" data-more="' . esc_attr( $all ) . '" data-less="' . esc_attr__( 'Show fewer', 'dos-saab-home' ) . '" hidden>'
				. '<span class="saab-fold__label">' . esc_html( $all ) . '</span>'
				. '<svg class="saab-fold__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>'
				. '</button>';
		}
		return $o . '</section>';
	}

	private static function latest_html( $n ) {
		if ( $n < 1 ) {
			return '';
		}
		$posts = get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => min( 24, $n ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		if ( ! $posts ) {
			return '';
		}
		$o  = '<section class="saab-sec" aria-labelledby="saab-latest-h"><div class="saab-sec__head"><h2 id="saab-latest-h" class="saab-sec__title">' . esc_html__( 'Fresh off the table', 'dos-saab-home' ) . '</h2>';
		$pp = (int) get_option( 'page_for_posts' );
		if ( $pp ) {
			$o .= '<a class="saab-sec__more" href="' . esc_url( get_permalink( $pp ) ) . '">' . esc_html__( 'All reviews', 'dos-saab-home' ) . '</a>';
		}
		$o .= '</div><ul class="saab-latest">';
		foreach ( $posts as $p ) {
			$cats  = get_the_category( $p->ID );
			$place = array_filter( array( self::term_name( $p->ID, 'saab_restaurant' ), self::term_name( $p->ID, 'saab_city' ) ) );
			$thumb = get_the_post_thumbnail(
				$p,
				'thumbnail',
				array(
					'class'    => 'saab-review__img',
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			);
			$o .= '<li><a class="saab-review" href="' . esc_url( get_permalink( $p ) ) . '">'
				. '<span class="saab-review__media">' . $thumb . '</span>'
				. '<span class="saab-review__text">'
				. ( $cats ? '<span class="saab-review__cat">' . esc_html( html_entity_decode( $cats[0]->name, ENT_QUOTES, 'UTF-8' ) ) . '</span>' : '' )
				. '<span class="saab-review__title">' . esc_html( html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) ) . '</span>'
				. ( $place ? '<span class="saab-review__place">' . esc_html( implode( ' · ', $place ) ) . '</span>' : '' )
				. '</span></a></li>';
		}
		return $o . '</ul></section>';
	}

	private static function cities_html( $n ) {
		if ( $n < 1 || ! taxonomy_exists( 'saab_city' ) ) {
			return '';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'saab_city',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => min( 30, $n ),
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		$o = '<section class="saab-cities" aria-labelledby="saab-city-h"><h2 id="saab-city-h" class="saab-cities__title">' . esc_html__( 'Eat in', 'dos-saab-home' ) . '</h2><ul class="saab-cities__list">';
		foreach ( $terms as $t ) {
			$url = get_term_link( $t );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$o .= '<li><a class="saab-city" href="' . esc_url( $url ) . '">' . esc_html( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) ) . '</a></li>';
		}
		return $o . '</ul></section>';
	}
}
