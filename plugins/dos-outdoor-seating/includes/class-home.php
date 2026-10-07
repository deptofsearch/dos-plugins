<?php
/**
 * Homepage takeover (setting osn_home_takeover): replaces the front page's content with one fixed layout.
 *
 * Order: H1, intro, SEO copy's first heading + paragraph (left half) beside the city search (right half), rest of
 * the SEO copy, "Popular cities" carousel,
 * "Browse by state" tiles. Nothing is edited in the page; turning the setting off restores the page as it was.
 *
 * Themify Builder note: the front page on outdoorseatingnearme.com is a Builder page, so Builder injects its own
 * layout through the_content. Three layers keep it out when the takeover is on, none of which needs Builder installed:
 *  1. a get_post_metadata filter hides Builder's stored layout (_themify_builder_settings_json) from the front-page
 *     main query, so Builder has nothing to print;
 *  2. a the_content filter at PHP_INT_MAX - 10 returns only our markup (it runs after Builder's priority 11 hook);
 *  3. body.osn-home-on CSS hides any .themify_builder_content that does not contain .osn-home.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Home {

	const OPT_ON     = 'osn_home_takeover';
	const OPT_INTRO  = 'osn_home_intro';
	const OPT_H1     = 'osn_home_h1';
	const OPT_SEARCH_HEADING = 'osn_home_search_heading';
	const OPT_SEO    = 'osn_home_seo_html';
	const OPT_CAR    = 'osn_home_carousel';
	const OPT_IMAGES = 'osn_home_state_images';
	const TRANSIENT  = 'osn_home_data_v2';
	const MAX_SLIDES = 24;

	const DEFAULT_H1             = 'Find Restaurants with Outdoor Seating';
	const DEFAULT_SEARCH_HEADING = 'Search by city';
	const DEFAULT_INTRO = 'Find restaurants, bars, breweries, and cafés with patios, decks, rooftops, and sidewalk tables across Washington, Oregon, California, Idaho, Arizona, and Nevada. Search your city to see who has outdoor seating, then filter for happy hour, dog-friendly patios, brunch, and more.';

	const DEFAULT_SEO = '<h2>Outdoor Dining, City by City</h2>
<p>Outdoor Seating Near Me lists more than 8,000 restaurants, bars, breweries, and coffee shops with outdoor tables in over 1,100 cities across the western United States. Every city page shows the places we know of with a patio, deck, rooftop, or sidewalk seating, along with hours, ratings, and directions.</p>
<h3>Filter for What You Want</h3>
<p>Looking for a dog-friendly patio, a rooftop for sunset drinks, or brunch in the sun? Filter any city by happy hour, dogs welcome, live music, brunch, cocktails, fire pits, and more, then open a restaurant to see its full details before you go.</p>
<h3>Where We Cover</h3>
<p>We cover <a href="/washington/">Washington</a>, <a href="/oregon/">Oregon</a>, <a href="/california/">California</a>, <a href="/idaho/">Idaho</a>, <a href="/arizona/">Arizona</a>, and <a href="/nevada/">Nevada</a>, and we add new cities and details regularly.</p>';

	/** Builder meta keys hidden from the front-page main query while the takeover is on. */
	const BUILDER_META = array( '_themify_builder_settings_json', '_themify_builder_settings' );

	public static function hooks() {
		add_filter( 'the_content', array( __CLASS__, 'content' ), PHP_INT_MAX - 10 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'get_post_metadata', array( __CLASS__, 'hide_builder_meta' ), 10, 4 );
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush' ) );
		add_action( 'update_option_page_on_front', array( __CLASS__, 'flush' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/* ---------------------------------------------------------------- state of the request */

	/** True when the takeover should render on this request. */
	public static function active() {
		if ( is_admin() || ! did_action( 'wp' ) || ! is_front_page() || ! is_page() ) {
			return false;
		}
		if ( get_option( self::OPT_ON ) ) {
			return true;
		}
		// Preview for admins: /?osn_home_preview=1 shows the takeover while the setting is off.
		return isset( $_GET['osn_home_preview'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function preview_url() {
		return add_query_arg( 'osn_home_preview', '1', home_url( '/' ) );
	}

	public static function body_class( $classes ) {
		if ( self::active() ) {
			$classes[] = 'osn-home-on';
		}
		return $classes;
	}

	public static function enqueue() {
		if ( self::active() ) {
			Assets::enqueue(); // In <head>, so the tightened header never flashes at the old spacing.
		}
	}

	/** Layer 1: Builder sees no stored layout for the front page, so it prints nothing. */
	public static function hide_builder_meta( $check, $post_id, $key, $single ) {
		if ( null !== $check || ! in_array( $key, self::BUILDER_META, true ) || ! self::active() ) {
			return $check;
		}
		if ( (int) $post_id !== (int) get_queried_object_id() || isset( $_GET['tb-id'], $_GET['tb-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $check;
		}
		return $single ? '' : array();
	}

	/** Layer 2: the whole page content becomes our markup, once, for the main front-page loop only. */
	public static function content( $content ) {
		global $wp_current_filter;
		static $done = false;
		if ( ! in_the_loop() || ! is_main_query() || doing_filter( 'get_the_excerpt' ) || ! self::active() ) {
			return $content;
		}
		if ( (int) get_the_ID() !== (int) get_queried_object_id() ) {
			return $content;
		}
		// Nested the_content calls (Builder modules, shortcodes) are not the page body.
		if ( count( array_keys( (array) $wp_current_filter, 'the_content', true ) ) > 1 ) {
			return $content;
		}
		if ( $done && apply_filters( 'osn_home_once', true ) ) {
			return '';
		}
		$done = true;
		return self::render();
	}

	/* ---------------------------------------------------------------- options */

	public static function intro() {
		$v = trim( (string) get_option( self::OPT_INTRO, '' ) );
		return '' !== $v ? $v : self::DEFAULT_INTRO;
	}

	/** Homepage H1 (option, else default); the osn_front_page_h1_text filter is applied on top at render time. */
	public static function h1() {
		$v = trim( (string) get_option( self::OPT_H1, '' ) );
		return '' !== $v ? $v : self::DEFAULT_H1;
	}

	public static function search_heading() {
		$v = trim( (string) get_option( self::OPT_SEARCH_HEADING, '' ) );
		return '' !== $v ? $v : self::DEFAULT_SEARCH_HEADING;
	}

	public static function seo_html() {
		$v = trim( (string) get_option( self::OPT_SEO, '' ) );
		return wp_kses_post( '' !== $v ? $v : self::DEFAULT_SEO );
	}

	/** @return array[] Each { label, url, attachment_id }. Invalid stored JSON reads as empty. */
	public static function carousel() {
		$raw = get_option( self::OPT_CAR, '' );
		$out = self::clean_carousel( $raw );
		return is_wp_error( $out ) ? array() : $out;
	}

	/**
	 * @param mixed $raw JSON string or array of { label, url, attachment_id }.
	 * @return array[]|\WP_Error Normalized rows, or an error naming the first problem.
	 */
	public static function clean_carousel( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return array();
			}
			$raw = json_decode( $raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new \WP_Error( 'osn_carousel_json', 'Carousel is not valid JSON: ' . json_last_error_msg() . '.' );
			}
		}
		if ( null === $raw || array() === $raw ) {
			return array();
		}
		if ( ! is_array( $raw ) || ( array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) ) {
			return new \WP_Error( 'osn_carousel_shape', 'Carousel must be a JSON array of objects: [{"label":"Seattle, WA","url":"https://…","attachment_id":123}].' );
		}
		if ( count( $raw ) > self::MAX_SLIDES ) {
			return new \WP_Error( 'osn_carousel_size', 'Carousel holds at most ' . self::MAX_SLIDES . ' cities.' );
		}
		$out = array();
		foreach ( $raw as $i => $row ) {
			$label = is_array( $row ) ? trim( sanitize_text_field( (string) ( $row['label'] ?? '' ) ) ) : '';
			$url   = is_array( $row ) ? trim( (string) ( $row['url'] ?? '' ) ) : '';
			if ( '' !== $url && '/' === $url[0] ) {
				$url = home_url( $url );
			}
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' === $label || '' === $url ) {
				return new \WP_Error( 'osn_carousel_row', 'Carousel item ' . ( $i + 1 ) . ' needs a label and a valid http(s) url.' );
			}
			$out[] = array(
				'label'         => $label,
				'url'           => $url,
				'attachment_id' => max( 0, (int) ( $row['attachment_id'] ?? 0 ) ),
			);
		}
		return $out;
	}

	/** Normalized JSON text for storing the carousel option. */
	public static function carousel_json( array $rows ) {
		return $rows ? wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
	}

	/** @param mixed $raw JSON string or array { WA: id|url }. @return array<string,int|string> */
	public static function clean_state_images( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( trim( $raw ), true );
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $code => $v ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $code ) );
			if ( ! States::has( $code ) ) {
				continue;
			}
			if ( is_numeric( $v ) && (int) $v > 0 ) {
				$out[ $code ] = (int) $v;
			} elseif ( is_string( $v ) && '' !== trim( $v ) ) {
				$u = esc_url_raw( trim( $v ) );
				if ( '' !== $u ) {
					$out[ $code ] = $u;
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- data: all cities, state counts, default images */

	public static function flush() {
		delete_transient( self::TRANSIENT );
	}

	public static function on_save( $post_id, $post ) {
		if ( ! $post || 'page' !== $post->post_type || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$slugs = array_keys( States::pages() );
		if ( in_array( $post->post_name, $slugs, true ) || (int) get_option( 'page_on_front' ) === (int) $post_id ) {
			self::flush();
		}
	}

	/**
	 * @return array { cities: array[] { n, u, s, p }, counts: {WA: int}, pages: {WA: page ID}, images: {WA: url} }.
	 *         Cached 12 hours; cleared when a state page or the front page is saved.
	 */
	public static function data() {
		$hit = get_transient( self::TRANSIENT );
		if ( is_array( $hit ) && isset( $hit['cities'], $hit['counts'], $hit['images'], $hit['pages'] ) ) {
			return $hit;
		}
		$cities = array();
		$counts = array();
		$pages  = array();
		foreach ( States::names() as $code => $name ) {
			$page = State_Cities::page_for( $code );
			if ( $page && 'publish' === $page->post_status ) {
				$pages[ $code ] = (int) $page->ID;
			}
			$rows = ( $page && 'publish' === $page->post_status ) ? State_Cities::parse_links( $page->post_content, $code ) : array();
			$counts[ $code ] = count( $rows );
			foreach ( $rows as $r ) {
				$cities[ $r['key'] ] = array(
					'n' => $r['name'] . ', ' . $code,
					'u' => $r['url'],
					's' => $code,
					'p' => City_Index::relative( $r['url'] ),
				);
			}
		}
		// Cities with a landing page the state pages do not list still belong in the search.
		foreach ( City_Index::get() as $c ) {
			$key = State_Cities::local_key( $c['u'] );
			if ( null !== $key && ! isset( $cities[ $key ] ) ) {
				$cities[ $key ] = array(
					'n' => $c['n'],
					'u' => $c['u'],
					's' => $c['s'],
					'p' => City_Index::relative( $c['u'] ),
				);
			}
		}
		$cities = array_values( $cities );
		usort(
			$cities,
			function ( $a, $b ) {
				return strnatcasecmp( $a['n'], $b['n'] );
			}
		);
		$data = array(
			'cities' => $cities,
			'counts' => $counts,
			'pages'  => $pages,
			'images' => self::parse_front_images(),
		);
		set_transient( self::TRANSIENT, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/** All cities across the six states, for the home search and GET /osn/v1/cities?scope=all. */
	public static function all_cities( $state = '' ) {
		$list  = self::data()['cities'];
		$state = strtoupper( (string) $state );
		if ( '' !== $state ) {
			$list = array_values(
				array_filter(
					$list,
					function ( $c ) use ( $state ) {
						return $c['s'] === $state;
					}
				)
			);
		}
		return $list;
	}

	/** State code => image URL from the current front page's content (<a href=".../washington/"><img src=...>). */
	private static function parse_front_images() {
		$front = (int) get_option( 'page_on_front' );
		$post  = $front ? get_post( $front ) : null;
		if ( ! $post || ! preg_match_all( '~<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', (string) $post->post_content, $m, PREG_SET_ORDER ) ) {
			return array();
		}
		$by_slug = array();
		foreach ( States::pages() as $slug => $name ) {
			$code = array_search( $name, States::names(), true );
			if ( false !== $code ) {
				$by_slug[ $slug ] = $code;
			}
		}
		$out = array();
		foreach ( $m as $row ) {
			$key = State_Cities::local_key( $row[2] );
			if ( null !== $key && isset( $by_slug[ $key ] ) && ! isset( $out[ $by_slug[ $key ] ] )
				&& preg_match( '~<img\b[^>]*?\b(?:data-src|src)\s*=\s*(["\'])(.*?)\1~is', $row[3], $im ) ) {
				$out[ $by_slug[ $key ] ] = esc_url_raw( html_entity_decode( $im[2], ENT_QUOTES, 'UTF-8' ) );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- render */

	private static function patios( $n ) {
		/* translators: %s: number of venues */
		return sprintf( _n( '%s patio', '%s patios', $n, 'dos-outdoor-seating' ), number_format_i18n( $n ) );
	}

	private static function band() {
		return '<span class="osn-card__media--band osn-home__band" style="' . esc_attr( Icons::neutral_style() ) . '">' . Icons::svg( 'utensils-crossed' ) . '</span>';
	}

	public static function render() {
		Assets::enqueue();
		$data  = self::data();
		$title = (string) apply_filters( 'osn_front_page_h1_text', self::h1() );

		ob_start();
		?>
<div class="osn osn-home">
<h1 class="osn-home__title"><?php echo esc_html( $title ); ?></h1>
<p class="osn-home__intro"><?php echo esc_html( self::intro() ); ?></p>
		<?php
		// The SEO copy's opening (everything through its first </p>) sits beside the search box; the rest follows below.
		$seo  = self::seo_html();
		$cut  = stripos( $seo, '</p>' );
		$lead = false === $cut ? $seo : substr( $seo, 0, $cut + 4 );
		$rest = false === $cut ? '' : substr( $seo, $cut + 4 );
		echo '<div class="osn-home__lead">';
		echo City_Search::render( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
			array(
				'heading'     => self::search_heading(),
				'intro'       => __( 'Start typing a city and pick it from the list.', 'dos-outdoor-seating' ),
				'placeholder' => __( 'Start typing a city', 'dos-outdoor-seating' ),
				'button'      => __( 'Search', 'dos-outdoor-seating' ),
			),
			array(),
			'',
			false,
			add_query_arg( 'scope', 'all', rest_url( Rest::NAMESPACE_V1 . '/cities' ) )
		);
		if ( '' !== trim( $lead ) ) {
			echo '<div class="osn-home__seo osn-home__seo--lead">' . $lead . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_kses_post above.
		}
		echo '</div>';
		if ( '' !== trim( $rest ) ) {
			echo '<div class="osn-home__seo">' . $rest . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_kses_post above.
		}
		echo self::carousel_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo self::states_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		?>
</div>
		<?php
		return ob_get_clean();
	}

	private static function carousel_html() {
		$rows = self::carousel();
		if ( ! $rows ) {
			return '';
		}
		$counts = array();
		$ids    = array();
		foreach ( $rows as $r ) {
			if ( $r['attachment_id'] ) {
				$ids[] = $r['attachment_id'];
			}
			if ( preg_match( '/,\s*([A-Za-z]{2})$/', $r['label'], $m ) && States::has( strtoupper( $m[1] ) ) ) {
				$counts[ strtoupper( $m[1] ) ] = true;
			}
		}
		if ( $ids ) {
			_prime_post_caches( array_values( array_unique( $ids ) ), false, true );
		}
		$venues = array();
		foreach ( array_keys( $counts ) as $code ) {
			$venues[ $code ] = State_Cities::data( $code );
		}

		$items = array();
		$li    = '';
		foreach ( $rows as $i => $r ) {
			$n = 0;
			if ( preg_match( '/,\s*([A-Za-z]{2})$/', $r['label'], $m ) ) {
				$key = State_Cities::local_key( $r['url'] );
				$n   = ( null !== $key && isset( $venues[ strtoupper( $m[1] ) ][ $key ] ) ) ? (int) $venues[ strtoupper( $m[1] ) ][ $key ]['count'] : 0;
			}
			$img = $r['attachment_id'] ? wp_get_attachment_image(
				$r['attachment_id'],
				'medium_large',
				false,
				array(
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
					'sizes'    => '(max-width: 600px) 72vw, (max-width: 900px) 40vw, 262px',
				)
			) : '';
			$li   .= '<li class="osn-hc__slide"><a class="osn-hc__card' . ( '' === $img ? ' osn-hc__card--band' : '' ) . '" href="' . esc_url( $r['url'] ) . '">'
				. '<span class="osn-hc__media">' . ( '' !== $img ? $img : self::band() ) . '</span>'
				. '<span class="osn-hc__label"><span class="osn-hc__name">' . esc_html( $r['label'] ) . '</span>'
				. ( $n > 0 ? '<span class="osn-hc__count">' . esc_html( self::patios( $n ) ) . '</span>' : '' )
				. '</span></a></li>';
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $r['label'],
				'url'      => $r['url'],
			);
		}
		$arrow = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="%s" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		$out   = '<section class="osn-home__section osn-hc" data-osn-carousel aria-labelledby="osn-hc-h">'
			. '<h2 id="osn-hc-h">' . esc_html__( 'Popular cities', 'dos-outdoor-seating' ) . '</h2>'
			. '<div class="osn-hc__frame" role="region" aria-roledescription="carousel" aria-label="' . esc_attr__( 'Popular cities', 'dos-outdoor-seating' ) . '">'
			. '<button type="button" class="osn-hc__nav osn-hc__prev" aria-label="' . esc_attr__( 'Previous cities', 'dos-outdoor-seating' ) . '" hidden>' . sprintf( $arrow, 'M15 5l-7 7 7 7' ) . '</button>'
			. '<ul class="osn-hc__track" tabindex="0" aria-label="' . esc_attr__( 'Popular cities, scroll sideways for more', 'dos-outdoor-seating' ) . '">' . $li . '</ul>'
			. '<button type="button" class="osn-hc__nav osn-hc__next" aria-label="' . esc_attr__( 'Next cities', 'dos-outdoor-seating' ) . '" hidden>' . sprintf( $arrow, 'M9 5l7 7-7 7' ) . '</button>'
			. '</div></section>';
		return $out . Util::json_ld(
			array(
				'@context'        => 'https://schema.org',
				'@type'           => 'ItemList',
				'name'            => 'Popular cities for outdoor seating',
				'numberOfItems'   => count( $items ),
				'itemListElement' => $items,
			)
		);
	}

	private static function states_html( array $data ) {
		$saved = self::clean_state_images( get_option( self::OPT_IMAGES, array() ) );
		$li    = '';
		foreach ( States::names() as $code => $name ) {
			$pid = (int) ( $data['pages'][ $code ] ?? 0 ); // Published state page ID, cached in data() so this loop runs no queries.
			$n   = (int) ( $data['counts'][ $code ] ?? 0 );
			if ( ! $pid && $n < 1 ) {
				continue; // Only states with a published page (or parsed city links) get a tile.
			}
			$url  = $pid ? get_permalink( $pid ) : home_url( '/' . States::slug( $code ) . '/' );
			$img  = '';
			$src  = $saved[ $code ] ?? ( $data['images'][ $code ] ?? '' );
			if ( is_int( $src ) ) {
				$img = wp_get_attachment_image(
					$src,
					'medium',
					false,
					array(
						'alt'      => '',
						'loading'  => 'lazy',
						'decoding' => 'async',
						'sizes'    => '(max-width: 480px) 45vw, (max-width: 900px) 30vw, 170px',
					)
				);
			} elseif ( '' !== $src ) {
				$img = '<img src="' . esc_url( $src ) . '" alt="" loading="lazy" decoding="async">';
			}
			$li .= '<li><a class="osn-hs__tile" href="' . esc_url( $url ) . '"><span class="osn-hs__media">' . ( '' !== $img ? $img : self::band() ) . '</span>'
				. '<span class="osn-hs__name">' . esc_html( $name ) . '</span>'
				. ( $n > 0 ? '<span class="osn-hs__count">' . esc_html( sprintf( _n( '%s city', '%s cities', $n, 'dos-outdoor-seating' ), number_format_i18n( $n ) ) ) . '</span>' : '' )
				. '</a></li>';
		}
		return '<section class="osn-home__section osn-hs" aria-labelledby="osn-hs-h"><h2 id="osn-hs-h">' . esc_html__( 'Browse by state', 'dos-outdoor-seating' ) . '</h2><ul class="osn-hs__grid">' . $li . '</ul></section>';
	}

	/* ---------------------------------------------------------------- REST: GET/POST /osn/v1/home */

	public static function register_routes() {
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/home',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_set' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => array(
						'h1'             => array( 'type' => 'string' ),
						'search_heading' => array( 'type' => 'string' ),
						'intro'        => array( 'type' => 'string' ),
						'seo_html'     => array( 'type' => 'string' ),
						'carousel'     => array(),
						'state_images' => array(),
					),
				),
			)
		);
	}

	private static function config() {
		return array(
			'takeover'     => (bool) get_option( self::OPT_ON ),
			'h1'             => self::h1(),
			'search_heading' => self::search_heading(),
			'intro'        => self::intro(),
			'seo_html'     => self::seo_html(),
			'carousel'     => self::carousel(),
			'state_images' => (object) self::clean_state_images( get_option( self::OPT_IMAGES, array() ) ),
			'preview_url'  => self::preview_url(),
		);
	}

	public static function rest_get() {
		return rest_ensure_response( self::config() );
	}

	/** Fields left out are kept; an empty h1 / search_heading / intro / seo_html / carousel / state_images resets that field to its default. */
	public static function rest_set( \WP_REST_Request $request ) {
		$p       = $request->get_json_params();
		$p       = is_array( $p ) && $p ? $p : $request->get_params();
		$updates = array();
		if ( array_key_exists( 'h1', $p ) ) {
			$updates[ self::OPT_H1 ] = trim( sanitize_text_field( (string) $p['h1'] ) );
		}
		if ( array_key_exists( 'search_heading', $p ) ) {
			$updates[ self::OPT_SEARCH_HEADING ] = trim( sanitize_text_field( (string) $p['search_heading'] ) );
		}
		if ( array_key_exists( 'intro', $p ) ) {
			$updates[ self::OPT_INTRO ] = sanitize_textarea_field( (string) $p['intro'] );
		}
		if ( array_key_exists( 'seo_html', $p ) ) {
			$updates[ self::OPT_SEO ] = wp_kses_post( (string) $p['seo_html'] );
		}
		if ( array_key_exists( 'carousel', $p ) ) {
			$rows = self::clean_carousel( $p['carousel'] );
			if ( is_wp_error( $rows ) ) {
				return new \WP_Error( $rows->get_error_code(), $rows->get_error_message(), array( 'status' => 400 ) );
			}
			$updates[ self::OPT_CAR ] = self::carousel_json( $rows );
		}
		if ( array_key_exists( 'state_images', $p ) ) {
			$updates[ self::OPT_IMAGES ] = self::clean_state_images( $p['state_images'] );
		}
		foreach ( $updates as $k => $v ) {
			if ( '' === $v || array() === $v ) {
				delete_option( $k );
			} else {
				update_option( $k, $v, false );
			}
		}
		if ( $updates ) {
			do_action( 'osn_home_changed', array_keys( $updates ) );
		}
		return rest_ensure_response( array( 'updated' => array_keys( $updates ) ) + self::config() );
	}
}
