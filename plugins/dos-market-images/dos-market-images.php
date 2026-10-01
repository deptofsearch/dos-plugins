<?php
/**
 * Plugin Name: DoS Market Images
 * Description: Illustrated market images for Real Estate Values Near Me — a city carousel in the homepage "Popular Real Estate Markets" section and image buttons on the By State page. Manage under Tools → Market Images. Also adds 2-post topic grids to homepage sections. Shortcodes: [revnm_market_carousel], [revnm_state_buttons].
 * Version: 1.5.2
 * Author: Department of Search
 * Text Domain: dos-market-images
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_MI_VERSION', '1.5.2' );
define( 'DOS_MI_PALETTES', 'dos_market_images_palettes' );
define( 'DOS_MI_DATA', 'dos_market_images_data' );
define( 'DOS_MI_OPTION', 'dos_market_images_settings' );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

function dos_mi_defaults() {
	return array(
		'carousel_on'   => 1,
		'carousel_max'  => 100,
		'states_on'     => 1,
		'states_page'   => 'by-state',
		'states_title'  => 'Browse by State',
		'states_filter' => 1,
		'states_hide_list' => 1,
		'state_hero_on'    => 1,
		'topics_on'        => 1,
		// One line per homepage heading: "Heading | category-slug, category-slug".
		'topics_map'       => "Start With Your Local Real Estate Market | housing-supply-inventory, news\n"
			. "Find Home Values Near You | demographics, amenities-lifestyle\n"
			. "What Determines a Home’s Value? | planning-development, schools, amenities-lifestyle\n"
			. "Understanding the Numbers | interest-rates-mortgage, taxes, insurance\n"
			. "Local Housing News and Market Factors | news, jobs-economy, government-public-policy, environment-climate-risk",
		// One line per homepage heading: "Heading | Button text | link". Added only where a section has no button yet.
		'buttons_map'      => "Popular Real Estate Markets | See Every Market by State | /by-state/\n"
			. "Start With Your Local Real Estate Market | Read Housing Supply & Inventory Articles | /category/housing-supply-inventory/\n"
			. "What Determines a Home’s Value? | Read Planning & Development Articles | /category/planning-development/\n"
			. "Understanding the Numbers | Read Interest Rate & Mortgage Articles | /category/interest-rates-mortgage/\n"
			. "Frequently Asked Questions | Read the Latest Housing News | /category/news/",
	);
}

function dos_mi_settings() {
	return wp_parse_args( (array) get_option( DOS_MI_OPTION, array() ), dos_mi_defaults() );
}

/**
 * Items as stored: cities [{key,label,page_id,attachment_id,hidden}], states [{key,label,slug,attachment_id,hidden}].
 * Cities keep the order they were loaded in (busiest market first); states are shown alphabetically.
 */
function dos_mi_data() {
	$d = get_option( DOS_MI_DATA, array() );
	return array(
		'cities' => isset( $d['cities'] ) ? (array) $d['cities'] : array(),
		'states' => isset( $d['states'] ) ? (array) $d['states'] : array(),
	);
}

function dos_mi_city_url( $item ) {
	$url = ! empty( $item['page_id'] ) && 'publish' === get_post_status( (int) $item['page_id'] ) ? get_permalink( (int) $item['page_id'] ) : '';
	return $url ? $url : '';
}

function dos_mi_state_url( $item ) {
	$page = get_page_by_path( $item['slug'] );
	return $page && 'publish' === $page->post_status ? get_permalink( $page ) : '';
}

function dos_mi_img( $id, $alt, $sizes ) {
	if ( ! $id || ! wp_attachment_is_image( $id ) ) {
		return '';
	}
	return wp_get_attachment_image(
		$id,
		'large',
		false,
		array(
			'alt'      => $alt,
			'loading'  => 'lazy',
			'decoding' => 'async',
			'sizes'    => $sizes,
			'class'    => 'rv-mi-img',
		)
	);
}

/* ---------- Front end ---------- */

function dos_mi_carousel() {
	$s     = dos_mi_settings();
	$cards = array();
	foreach ( dos_mi_data()['cities'] as $c ) {
		if ( ! empty( $c['hidden'] ) ) {
			continue;
		}
		$url = dos_mi_city_url( $c );
		$img = dos_mi_img( (int) $c['attachment_id'], 'Illustration of ' . $c['label'], '(max-width: 600px) 70vw, 280px' );
		if ( ! $url || ! $img ) {
			continue; // Unpublished page or missing image: leave it out rather than show a broken card.
		}
		$cards[] = '<li class="rv-mi-slide"><a class="rv-mi-card" href="' . esc_url( $url ) . '">' . $img
			. '<span class="rv-mi-label">' . esc_html( $c['label'] ) . '</span></a></li>';
		if ( count( $cards ) >= (int) $s['carousel_max'] ) {
			break;
		}
	}
	if ( ! $cards ) {
		return '';
	}
	dos_mi_enqueue();
	return '<div class="rv-mi-carousel" role="region" aria-roledescription="carousel" aria-label="Popular real estate markets">'
		. '<button type="button" class="rv-mi-nav rv-mi-prev" aria-label="Previous markets" hidden><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
		. '<ul class="rv-mi-track">' . implode( '', $cards ) . '</ul>'
		. '<button type="button" class="rv-mi-nav rv-mi-next" aria-label="Next markets"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
		. '</div>';
}

function dos_mi_state_buttons( $counts = array() ) {
	$s      = dos_mi_settings();
	$states = dos_mi_data()['states'];
	usort(
		$states,
		function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		}
	);
	$out = array();
	foreach ( $states as $st ) {
		if ( ! empty( $st['hidden'] ) ) {
			continue;
		}
		$url = dos_mi_state_url( $st );
		$img = dos_mi_img( (int) $st['attachment_id'], 'Illustration of ' . $st['label'], '(max-width: 600px) 45vw, 200px' );
		if ( ! $url || ! $img ) {
			continue;
		}
		$n     = isset( $counts[ $st['slug'] ] ) ? (int) $counts[ $st['slug'] ] : 0;
		$sub   = $n ? '<span class="rv-mi-sub">' . esc_html( number_format_i18n( $n ) . ( 1 === $n ? ' city' : ' cities' ) ) . '</span>' : '';
		$out[] = '<li data-name="' . esc_attr( strtolower( $st['label'] ) ) . '"><a class="rv-mi-card rv-mi-state" href="' . esc_url( $url ) . '">' . $img
			. '<span class="rv-mi-label">' . esc_html( $st['label'] ) . $sub . '</span></a></li>';
	}
	if ( ! $out ) {
		return '';
	}
	dos_mi_enqueue();
	$title = '' !== $s['states_title'] ? '<h3 class="rv-mi-title">' . esc_html( $s['states_title'] ) . '</h3>' : '';
	$filter = '';
	if ( $s['states_filter'] ) {
		$filter = '<div class="rv-mi-filter"><label class="rv-mi-sr" for="rv-mi-q">Filter states</label>'
			. '<input type="search" id="rv-mi-q" placeholder="Filter states…" autocomplete="off">'
			. '<p class="rv-mi-count" aria-live="polite">' . esc_html( count( $out ) ) . ' states &amp; territories</p></div>';
	}
	return '<div class="rv-mi-states" data-total="' . esc_attr( count( $out ) ) . '">' . $title . $filter . '<ul class="rv-mi-grid">' . implode( '', $out ) . '</ul>'
		. '<p class="rv-mi-empty" hidden>No states match that filter.</p></div>';
}

add_shortcode( 'revnm_market_carousel', 'dos_mi_carousel' );
add_shortcode( 'revnm_state_buttons', 'dos_mi_state_buttons' );

/**
 * Homepage: swap the text links in "Popular Real Estate Markets" for the carousel.
 * By State page: put the state buttons above the existing state list.
 * Runtime filters, so page content that other tools regenerate is never edited.
 */
add_filter(
	'the_content',
	function ( $content ) {
		static $home_done = false, $states_done = false, $hero_done = false;
		if ( ! in_the_loop() ) {
			return $content;
		}
		$s = dos_mi_settings();

		if ( ! $home_done && $s['carousel_on'] && is_front_page() && false === strpos( $content, 'rv-mi-carousel' ) ) {
			$re = '/(<h2[^>]*>\s*Popular Real Estate Markets\s*<\/h2>\s*)<ul class="rv-links">.*?<\/ul>/s';
			if ( preg_match( $re, $content ) ) {
				$html = dos_mi_carousel();
				if ( $html ) {
					$home_done = true;
					$content   = preg_replace_callback(
						$re,
						function ( $m ) use ( $html ) {
							return $m[1] . $html;
						},
						$content,
						1
					);
				}
			}
		}

		if ( ! $states_done && $s['states_on'] && is_page( $s['states_page'] ) && false === strpos( $content, 'rv-mi-states' ) ) {
			// City counts come from the existing list ("Alabama (329)"), so they stay in step with whatever generates it.
			$counts = array();
			if ( preg_match_all( '#href="[^"]*/([a-z0-9-]+)/"[^>]*>[^<]+</a>\s*<span[^>]*>\(([\d,]+)\)</span>#', $content, $mm, PREG_SET_ORDER ) ) {
				foreach ( $mm as $m ) {
					$counts[ $m[1] ] = (int) str_replace( ',', '', $m[2] );
				}
			}
			$html = dos_mi_state_buttons( $counts );
			if ( $html ) {
				$states_done = true;
				$at          = strpos( $content, '<div class="rev-st"' );
				if ( false === $at ) {
					$content = $html . $content;
				} else {
					$end     = $s['states_hide_list'] ? dos_mi_div_end( $content, $at ) : $at;
					$content = substr( $content, 0, $at ) . $html . substr( $content, $end );
				}
			}
		}

		static $topics_done = false;
		if ( ! $topics_done && $s['topics_on'] && is_front_page() && false !== strpos( $content, 'class="rv-sec' ) ) {
			$topics_done = true;
			$content     = dos_mi_topic_grids( $content );
		}

		if ( ! $hero_done && $s['state_hero_on'] && is_page() && false !== strpos( $content, 'data-noun="cities"' ) ) {
			$new = dos_mi_state_hero( $content );
			if ( $new !== $content ) {
				$hero_done = true;
				$content   = $new;
			}
		}

		return $content;
	},
	20
);

// Lets the stylesheet give the theme's featured image the same look as the plugin's hero on state pages.
add_filter(
	'body_class',
	function ( $classes ) {
		if ( is_page() && dos_mi_settings()['state_hero_on'] ) {
			$page = get_queried_object();
			foreach ( dos_mi_data()['states'] as $st ) {
				if ( $page && $st['slug'] === $page->post_name ) {
					$classes[] = 'rv-mi-state-page';
					break;
				}
			}
		}
		return $classes;
	}
);

/* ---------- Homepage topic grids ---------- */

function dos_mi_norm_heading( $t ) {
	$t = html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES, 'UTF-8' );
	$t = str_replace( array( '’', '‘', '&' ), array( "'", "'", 'and' ), $t );
	return strtolower( trim( preg_replace( '/\s+/', ' ', $t ) ) );
}

/** Parsed "Heading | cat, cat" lines as normalized heading => [category slugs]. */
function dos_mi_topics_map() {
	$map = array();
	foreach ( preg_split( '/\r?\n/', (string) dos_mi_settings()['topics_map'] ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( 2 !== count( $parts ) || '' === $parts[0] ) {
			continue;
		}
		$cats = array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $parts[1] ) ) ) );
		if ( $cats ) {
			$map[ dos_mi_norm_heading( $parts[0] ) ] = array_values( $cats );
		}
	}
	return $map;
}

/** Parsed "Heading | text | link" lines as normalized heading => [text, url]. */
function dos_mi_buttons_map() {
	$map = array();
	foreach ( preg_split( '/\r?\n/', (string) dos_mi_settings()['buttons_map'] ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( 3 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] && '' !== $parts[2] ) {
			$map[ dos_mi_norm_heading( $parts[0] ) ] = array( $parts[1], $parts[2] );
		}
	}
	return $map;
}

/** Adds a 2-post grid to the end of each mapped homepage section; a post is never shown twice. */
function dos_mi_topic_grids( $content ) {
	$map     = dos_mi_topics_map();
	$buttons = dos_mi_buttons_map();
	if ( ! $map && ! $buttons ) {
		return $content;
	}
	$used = array();
	return preg_replace_callback(
		'#<section class="rv-sec[^"]*">.*?</section>#s',
		function ( $m ) use ( $map, $buttons, &$used ) {
			$sec = $m[0];
			if ( ! preg_match( '#<h2[^>]*>(.*?)</h2>#s', $sec, $h ) ) {
				return $sec;
			}
			$key = dos_mi_norm_heading( $h[1] );
			$sec = dos_mi_section_grid( $sec, isset( $map[ $key ] ) ? $map[ $key ] : array(), $used );
			if ( isset( $buttons[ $key ] ) && false === strpos( $sec, 'class="rv-cta"' ) ) {
				dos_mi_enqueue();
				$url = $buttons[ $key ][1];
				$url = 0 === strpos( $url, '/' ) ? home_url( $url ) : $url;
				$sec = substr( $sec, 0, -strlen( '</section>' ) )
					. '<p class="rv-cta"><a class="rv-btn" href="' . esc_url( $url ) . '">' . esc_html( $buttons[ $key ][0] ) . '</a></p></section>';
			}
			return $sec;
		},
		$content
	);
}

/** The grid for one section (unchanged section when it has no categories or no posts). */
function dos_mi_section_grid( $sec, $cats, &$used ) {
	if ( ! $cats ) {
		return $sec;
	}
	$q = new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 2,
			'category_name'       => implode( ',', $cats ),
			'post__not_in'        => $used,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'has_password'        => false,
		)
	);
	if ( ! $q->posts ) {
		return $sec;
	}
	$cards = '';
	foreach ( $q->posts as $post ) {
		$used[] = $post->ID;
		$img    = has_post_thumbnail( $post ) ? get_the_post_thumbnail(
			$post,
			'medium_large',
			array(
				'class'    => 'rv-tp-img',
				'loading'  => 'lazy',
				'decoding' => 'async',
				'alt'      => '',
				'sizes'    => '(max-width: 600px) 100vw, 420px',
			)
		) : '<span class="rv-tp-img rv-tp-noimg" aria-hidden="true"></span>';
		$cat    = get_the_category( $post->ID );
		$label  = $cat ? '<span class="rv-tp-cat">' . esc_html( $cat[0]->name ) . '</span>' : '';
		$cards .= '<li><a class="rv-tp-card" href="' . esc_url( get_permalink( $post ) ) . '">' . $img
			. '<span class="rv-tp-body">' . $label
			. '<span class="rv-tp-title">' . esc_html( get_the_title( $post ) ) . '</span>'
			. '<span class="rv-tp-date">' . esc_html( wp_date( get_option( 'date_format' ), get_post_timestamp( $post ) ) ) . '</span></span></a></li>';
	}
	dos_mi_enqueue();
	$grid = '<div class="rv-tp"><p class="rv-tp-head">Related reading</p><ul class="rv-tp-grid">' . $cards . '</ul></div>';

	// Keep a closing call-to-action button last: the grid goes just before it.
	$body = substr( $sec, 0, -strlen( '</section>' ) );
	if ( preg_match( '#(<p class="rv-cta">.*?</p>)\s*$#s', $body, $cta, PREG_OFFSET_CAPTURE ) ) {
		return substr( $body, 0, $cta[1][1] ) . $grid . $cta[1][0] . '</section>';
	}
	return $body . $grid . '</section>';
}

/**
 * State landing page: the state's image at the top, with the "Filter cities…" box moved directly under it.
 * The filter stays inside .rev-st so the page's own filter script keeps working.
 */
function dos_mi_state_hero( $content ) {
	$page = get_queried_object();
	if ( ! $page || empty( $page->post_name ) ) {
		return $content;
	}
	$state = null;
	foreach ( dos_mi_data()['states'] as $st ) {
		if ( $st['slug'] === $page->post_name && empty( $st['hidden'] ) ) {
			$state = $st;
			break;
		}
	}
	if ( ! $state ) {
		return $content;
	}
	dos_mi_enqueue();
	if ( has_post_thumbnail( $page ) ) {
		// The theme already prints the featured image above the content; don't add a second copy.
		$hero = '';
	} else {
		$img = dos_mi_img( (int) $state['attachment_id'], 'Illustration of ' . $state['label'], '(max-width: 900px) 100vw, 848px' );
		if ( ! $img ) {
			return $content;
		}
		// Above-the-fold image: load it right away rather than lazily.
		$img  = str_replace( 'loading="lazy"', 'loading="eager" fetchpriority="high"', $img );
		$hero = '<figure class="rv-mi-hero">' . $img . '</figure>';
	}

	$st_at = strpos( $content, '<div class="rev-st"' );
	if ( false === $st_at ) {
		return $content;
	}
	$card_at = strpos( $content, '<div class="rev-card">', $st_at );
	$srch_at = false === $card_at ? false : strpos( $content, '<div class="rev-search">', $card_at );
	if ( false === $srch_at ) {
		// No filter to move: just put the image above the list.
		return substr( $content, 0, $st_at ) . $hero . substr( $content, $st_at );
	}
	$srch_end = dos_mi_div_end( $content, $srch_at );
	$search   = substr( $content, $srch_at, $srch_end - $srch_at );
	$inner    = $card_at + strlen( '<div class="rev-card">' );

	// Card now opens with the filter, then its original heading and table.
	$out = substr( $content, 0, $st_at ) . $hero
		. substr( $content, $st_at, $inner - $st_at ) . $search
		. substr( $content, $inner, $srch_at - $inner )
		. substr( $content, $srch_end );
	return $out;
}

/**
 * Offset just past the </div> that closes the <div> opening at $start (nested divs counted).
 * Falls back to $start (remove nothing) if the markup doesn't balance.
 */
function dos_mi_div_end( $html, $start ) {
	if ( ! preg_match_all( '#<div\b|</div\s*>#i', $html, $m, PREG_OFFSET_CAPTURE, $start ) ) {
		return $start;
	}
	$depth = 0;
	foreach ( $m[0] as $tag ) {
		$depth += ( '</' === substr( $tag[0], 0, 2 ) ) ? -1 : 1;
		if ( 0 === $depth ) {
			return $tag[1] + strlen( $tag[0] );
		}
	}
	return $start;
}

function dos_mi_enqueue() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	wp_register_style( 'dos-market-images', false, array(), DOS_MI_VERSION );
	wp_enqueue_style( 'dos-market-images' );
	wp_add_inline_style( 'dos-market-images', dos_mi_css() );
	wp_register_script( 'dos-market-images', false, array(), DOS_MI_VERSION, true );
	wp_enqueue_script( 'dos-market-images' );
	wp_add_inline_script( 'dos-market-images', dos_mi_js() );
}

function dos_mi_css() {
	return '.rv-mi-carousel{position:relative;margin:16px 0 0}'
		. '.rv-mi-track{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(220px,calc((100% - 32px)/3));gap:16px;margin:0;padding:4px 2px 14px;list-style:none;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;overscroll-behavior-x:contain;scrollbar-width:thin}'
		. '.rv-mi-slide{scroll-snap-align:start;margin:0}'
		. '.rv-mi-card{display:block;border-radius:10px;overflow:hidden;background:#f3efe4;border:1px solid rgba(0,0,0,.08);text-decoration:none;color:#1a1a1a;transition:transform .15s ease,box-shadow .15s ease}'
		. '.rv-mi-card:hover,.rv-mi-card:focus-visible{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.14);color:#1a1a1a}'
		. '.rv-mi-card:focus-visible{outline:2px solid var(--rv-accent,#1a5fb4);outline-offset:2px}'
		. '.rv-mi-card .rv-mi-img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover;margin:0}'
		. '.rv-mi-label{display:block;padding:10px 12px;font-weight:600;line-height:1.3}'
		. '.rv-mi-nav{position:absolute;top:calc(50% - 30px);z-index:2;width:44px;height:44px;border-radius:50%;border:0;background:#fff;color:#1a1a1a;box-shadow:0 2px 10px rgba(0,0,0,.2);cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}'
		. '.rv-mi-nav[hidden]{display:none}'
		. '.rv-mi-prev{left:-10px}.rv-mi-next{right:-10px}'
		. '@media (hover:none){.rv-mi-nav{display:none}}'
		. '@media (max-width:600px){.rv-mi-track{grid-auto-columns:72%}}'
		. '.rv-mi-states{margin:1.2em 0}'
		. '.rv-mi-title{margin:0 0 .8em}'
		. '.rv-mi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin:0;padding:0;list-style:none}'
		. '.rv-mi-grid li{margin:0}'
		. '.rv-mi-state .rv-mi-label{text-align:center;font-size:.95em}'
		. '.rv-mi-hero{margin:0 0 -.4em;padding:0}'
		. '.rv-mi-hero .rv-mi-img{display:block;width:100%;height:auto;aspect-ratio:4/3;max-height:420px;object-fit:cover;border-radius:16px;margin:0}'
		. '.rv-mi-hero + .rev-st .rev-card{margin-top:1em}'
		. '.rv-mi-state-page .post-image{margin:0 0 1em}'
		. '.rv-mi-state-page .post-image img{display:block;width:100%;height:auto;aspect-ratio:4/3;max-height:420px;object-fit:cover;border-radius:16px}'
		. '@media (max-width:600px){.rv-mi-state-page .post-image img{border-radius:12px;max-height:260px}}'
		. '.rv-mi-hero + .rev-st .rev-search{margin:0 0 1.1em}'
		. '@media (max-width:600px){.rv-mi-hero .rv-mi-img{border-radius:12px;max-height:260px}}'
		. '.rv-tp{margin:20px 0 0}'
		. '.rv-tp-head{margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#6b7280}'
		. '.rv-tp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:0;padding:0;list-style:none}'
		. '.rv-tp-grid li{margin:0}'
		. '.rv-tp-card{display:flex;flex-direction:column;height:100%;border:1px solid rgba(0,0,0,.08);border-radius:10px;overflow:hidden;background:#fff;text-decoration:none;color:#1a1a1a;transition:transform .15s ease,box-shadow .15s ease}'
		. '.rv-tp-card:hover,.rv-tp-card:focus-visible{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.12);color:#1a1a1a}'
		. '.rv-tp-card:focus-visible{outline:2px solid var(--rv-accent,#1a5fb4);outline-offset:2px}'
		. '.rv-tp-card .rv-tp-img{display:block;width:100%;height:auto;aspect-ratio:16/9;object-fit:cover;margin:0}'
		. '.rv-tp-noimg{background:#f3efe4}'
		. '.rv-tp-body{display:flex;flex-direction:column;gap:4px;padding:12px 14px 14px}'
		. '.rv-tp-cat{font-size:12px;font-weight:700;color:var(--rv-accent,#1a5fb4)}'
		. '.rv-tp-title{font-weight:600;line-height:1.35}'
		. '.rv-tp-date{font-size:.85em;color:#6b7280}'
		. '@media (max-width:600px){.rv-tp-grid{grid-template-columns:1fr}}'
		. '@media (prefers-reduced-motion:reduce){.rv-tp-card{transition:none}}'
		. '.rv-mi-sub{display:block;font-weight:400;font-size:.85em;color:#6b7280;margin-top:2px}'
		. '.rv-mi-filter{margin:0 0 16px}'
		. '.rv-mi-filter input{width:100%;max-width:340px;min-height:44px;padding:.62em .9em;font:inherit;color:#1a1a1a;background:#fff;border:1px solid #d1d5db;border-radius:10px;box-sizing:border-box}'
		. '.rv-mi-filter input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.14)}'
		. '.rv-mi-count{margin:.55em 0 0;font-size:12px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#6b7280}'
		. '.rv-mi-empty{padding:1.1em 0;color:#6b7280}'
		. '.rv-mi-grid li[hidden]{display:none}'
		. '.rv-mi-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
		. '@media (max-width:600px){.rv-mi-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}}'
		. '@media (prefers-reduced-motion:reduce){.rv-mi-track{scroll-behavior:auto}.rv-mi-card{transition:none}}';
}

function dos_mi_js() {
	return <<<'JS'
(function () {
  function init(c) {
    var track = c.querySelector('.rv-mi-track'), prev = c.querySelector('.rv-mi-prev'), next = c.querySelector('.rv-mi-next');
    function step() { var s = track.querySelector('.rv-mi-slide'); return s ? (s.getBoundingClientRect().width + 16) * Math.max(1, Math.floor(track.clientWidth / (s.getBoundingClientRect().width + 16))) : track.clientWidth; }
    function sync() {
      prev.hidden = track.scrollLeft <= 4;
      next.hidden = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
    }
    prev.addEventListener('click', function () { track.scrollBy({ left: -step() }); });
    next.addEventListener('click', function () { track.scrollBy({ left: step() }); });
    track.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    sync();
  }
  function initFilter(root) {
    var box = root.querySelector('.rv-mi-filter input');
    if (!box) return;
    var items = [].slice.call(root.querySelectorAll('.rv-mi-grid li'));
    var count = root.querySelector('.rv-mi-count'), empty = root.querySelector('.rv-mi-empty');
    var total = items.length;
    box.addEventListener('input', function () {
      var q = box.value.trim().toLowerCase(), shown = 0;
      items.forEach(function (li) {
        var hit = !q || li.getAttribute('data-name').indexOf(q) !== -1;
        li.hidden = !hit;
        if (hit) shown++;
      });
      count.textContent = q ? shown + ' of ' + total + ' states & territories' : total + ' states & territories';
      empty.hidden = shown !== 0;
    });
  }
  function boot() {
    document.querySelectorAll('.rv-mi-carousel').forEach(init);
    document.querySelectorAll('.rv-mi-states').forEach(initFilter);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
JS;
}

/* ---------- Image palettes (reference for generating new images) ---------- */

/** state slug => [ {label, names, colors[6 hex]} ] */
function dos_mi_palettes() {
	return (array) get_option( DOS_MI_PALETTES, array() );
}

function dos_mi_clean_palette( $pl ) {
	$colors = array();
	foreach ( (array) ( isset( $pl['colors'] ) ? $pl['colors'] : array() ) as $c ) {
		$c = sanitize_hex_color( $c );
		if ( $c ) {
			$colors[] = $c;
		}
	}
	return array(
		'label'  => sanitize_text_field( isset( $pl['label'] ) && '' !== $pl['label'] ? $pl['label'] : 'Palette' ),
		'names'  => sanitize_text_field( isset( $pl['names'] ) ? $pl['names'] : '' ),
		'colors' => array_slice( $colors, 0, 8 ),
	);
}

/* ---------- Loader endpoint: lets the image upload script register the images in one call ---------- */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'revnm/v1',
			'/market-images',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'callback'            => function () {
						return dos_mi_data();
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'callback'            => 'dos_mi_rest_save',
				),
			)
		);
	}
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'revnm/v1',
			'/palettes',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'callback'            => 'dos_mi_palettes',
				),
				array(
					// Replaces the palettes of the states in the body; other states are left alone.
					'methods'             => 'POST',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'callback'            => function ( WP_REST_Request $req ) {
						$all = dos_mi_palettes();
						foreach ( (array) $req->get_json_params() as $slug => $list ) {
							$slug = sanitize_title( $slug );
							$all[ $slug ] = array_values( array_map( 'dos_mi_clean_palette', (array) $list ) );
						}
						update_option( DOS_MI_PALETTES, $all, false );
						return array( 'states' => count( $all ) );
					},
				),
			)
		);
	}
);

// Sets a post's featured image without saving the post itself, so its modified date (and any
// "Updated:" label other plugins show) stays as it was.
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'revnm/v1',
			'/set-thumbnail',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'edit_others_posts' );
				},
				'callback'            => function ( WP_REST_Request $req ) {
					$post  = absint( $req['post'] );
					$media = absint( $req['media'] );
					if ( ! get_post( $post ) || ! wp_attachment_is_image( $media ) ) {
						return new WP_Error( 'dos_mi_bad', 'Unknown post or image.', array( 'status' => 400 ) );
					}
					if ( ! empty( $req['only_if_empty'] ) && has_post_thumbnail( $post ) ) {
						return array( 'post' => $post, 'set' => false, 'reason' => 'already has one' );
					}
					set_post_thumbnail( $post, $media );
					return array( 'post' => $post, 'set' => get_post_thumbnail_id( $post ) === $media );
				},
			)
		);
	}
);

// Puts a post's modified date back to its publish date (for posts whose date was bumped by a
// featured-image-only change). Written directly so saving doesn't bump it again.
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'revnm/v1',
			'/reset-modified',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'edit_others_posts' );
				},
				'callback'            => function ( WP_REST_Request $req ) {
					global $wpdb;
					$post = get_post( absint( $req['post'] ) );
					if ( ! $post || 'post' !== $post->post_type ) {
						return new WP_Error( 'dos_mi_bad', 'Unknown post.', array( 'status' => 400 ) );
					}
					$wpdb->update(
						$wpdb->posts,
						array(
							'post_modified'     => $post->post_date,
							'post_modified_gmt' => $post->post_date_gmt,
						),
						array( 'ID' => $post->ID )
					);
					clean_post_cache( $post->ID );
					return array( 'post' => $post->ID, 'modified' => get_post( $post->ID )->post_modified );
				},
			)
		);
	}
);

function dos_mi_rest_save( WP_REST_Request $req ) {
	$body = $req->get_json_params();
	$data = dos_mi_data();
	$keep = function ( $old, $key ) {
		foreach ( $old as $o ) {
			if ( $o['key'] === $key ) {
				return $o;
			}
		}
		return array();
	};

	foreach ( array( 'cities', 'states' ) as $kind ) {
		if ( ! isset( $body[ $kind ] ) || ! is_array( $body[ $kind ] ) ) {
			continue;
		}
		$clean = array();
		foreach ( $body[ $kind ] as $it ) {
			$key = sanitize_title( isset( $it['key'] ) ? $it['key'] : '' );
			if ( '' === $key ) {
				continue;
			}
			$prev = $keep( $data[ $kind ], $key );
			$row  = array(
				'key'           => $key,
				'label'         => sanitize_text_field( isset( $it['label'] ) ? $it['label'] : $key ),
				'attachment_id' => absint( isset( $it['attachment_id'] ) ? $it['attachment_id'] : 0 ),
				'hidden'        => ! empty( $prev['hidden'] ) ? 1 : 0, // A hide set on the dashboard survives a reload.
			);
			if ( 'cities' === $kind ) {
				$row['page_id'] = absint( isset( $it['page_id'] ) ? $it['page_id'] : 0 );
			} else {
				$row['slug'] = sanitize_title( isset( $it['slug'] ) ? $it['slug'] : $key );
			}
			$clean[] = $row;
		}
		$data[ $kind ] = $clean;
	}

	update_option( DOS_MI_DATA, $data, false );
	return array(
		'cities' => count( $data['cities'] ),
		'states' => count( $data['states'] ),
	);
}
