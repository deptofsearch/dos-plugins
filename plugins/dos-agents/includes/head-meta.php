<?php
/**
 * The <head> tags the site was missing: one meta description, Open Graph and Twitter Card tags.
 *
 * A live audit found no description and no Open Graph on 59 of 60 pages, and no SEO plugin printing
 * them. Every description here is built from facts the page itself shows (the stored agent count, the
 * cities a hub lists, a post's own excerpt), never from a template that claims more than the cards
 * display. Where there is nothing true to say, nothing is printed: a missing description is better
 * than a weak one, because search engines write their own from the page anyway.
 *
 * The <title> is not touched. This file only adds tags.
 *
 * It stands down completely when another layer already prints these tags (Yoast, Rank Math, AIOSEO,
 * SEOPress, The SEO Framework, Jetpack's Open Graph, or DoS Toolkit's SEO module), so a site never gets two descriptions or
 * two og:title tags, and `dos_agents_head_meta` turns it off without a release.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Longest description printed, in characters. Search results cut around here anyway. */
function dos_agents_head_meta_max() {
	return max( 40, (int) apply_filters( 'dos_agents_meta_description_max', 155 ) );
}

/**
 * The name of the SEO layer that already prints these tags, or '' when none does.
 *
 * The same list DoS Toolkit's SEO module stands down for. The toolkit's own module is checked by
 * asking the toolkit whether the module is enabled and present (DOS_Toolkit::is_active), not just
 * whether its class is loaded: the class file is loaded with the toolkit whether or not the box is
 * ticked, and a loaded-but-off module prints nothing.
 */
function dos_agents_head_meta_other_layer() {
	$constants = array(
		'WPSEO_VERSION'              => 'Yoast SEO',
		'RANK_MATH_VERSION'          => 'Rank Math',
		'AIOSEO_VERSION'             => 'All in One SEO',
		'SEOPRESS_VERSION'           => 'SEOPress',
		'THE_SEO_FRAMEWORK_VERSION'  => 'The SEO Framework',
	);
	foreach ( $constants as $constant => $name ) {
		if ( defined( $constant ) ) {
			return $name;
		}
	}
	// Some AIOSEO builds define the constant late, so its accessor is checked too (the toolkit does the same).
	if ( function_exists( 'aioseo' ) ) {
		return 'All in One SEO';
	}
	// Jetpack prints Open Graph and Twitter tags when the filter says so, which its Publicize and
	// Sharing modules switch on. Ask the filter first, then the two modules, since either is how
	// Jetpack itself decides.
	if ( defined( 'JETPACK__VERSION' ) ) {
		$modules = false;
		if ( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) ) {
			$modules = Jetpack::is_module_active( 'publicize' ) || Jetpack::is_module_active( 'sharedaddy' );
		}
		if ( apply_filters( 'jetpack_enable_open_graph', $modules ) ) {
			return 'Jetpack';
		}
	}
	if ( class_exists( 'DOS_Module_SEO' ) ) {
		// Without the toolkit class the module cannot have been started by anything, but a stray copy
		// of the class is a reason to be careful rather than to double up.
		if ( ! class_exists( 'DOS_Toolkit' ) || DOS_Toolkit::is_active( DOS_Module_SEO::KEY ) ) {
			return 'DoS Toolkit SEO';
		}
	}
	return '';
}

function dos_agents_head_meta_enabled() {
	return '' === dos_agents_head_meta_other_layer() && (bool) apply_filters( 'dos_agents_head_meta', true );
}

/** The post being viewed: the queried object when it is one, else the global post. */
function dos_agents_head_queried() {
	// The queried object is the page being viewed; the global post can be something else by the time
	// wp_head runs (a widget or theme that ran its own loop).
	$post = get_queried_object();
	return $post instanceof WP_Post ? $post : get_post();
}

/**
 * What kind of page this is, and its post: array( kind, post|null ), or null for a view that gets
 * nothing (404, search, feeds, archives, attachments, admin).
 *
 * Kinds: home, by-state, hub, city, brokerages (an older city page), blog (the posts page), post, page.
 */
function dos_agents_head_meta_view() {
	if ( is_admin() || is_feed() || is_embed() || is_404() || is_search() ) {
		return null;
	}
	if ( is_front_page() ) {
		return array( 'home', is_singular() ? dos_agents_head_queried() : null );
	}
	if ( is_home() ) {
		return array( 'blog', null );
	}
	if ( ! is_singular() ) {
		return null;
	}
	$post = dos_agents_head_queried();
	if ( ! $post instanceof WP_Post || is_attachment() ) {
		return null;
	}
	if ( 'post' === $post->post_type ) {
		return array( 'post', $post );
	}
	if ( 'page' !== $post->post_type ) {
		return array( 'page', $post );
	}
	if ( '' !== dos_agents_hub_state( $post->ID ) && dos_agents_hub_enabled() ) {
		return array( 'hub', $post );
	}
	if ( 'by-state' === $post->post_name ) {
		return array( 'by-state', $post );
	}
	// The live site's /blog/ is an ordinary page holding a posts module, not WordPress's posts page,
	// so is_home() never sees it. A top-level page with the "blog" slug is the blog index too.
	if ( 'blog' === $post->post_name && ! $post->post_parent ) {
		return array( 'blog', $post );
	}
	if ( null !== dos_agents_head_meta_city( $post ) ) {
		return array( 'city', $post );
	}
	// The older city pages hold a brokerage summary instead of the shortcode. Detected by the heading
	// in the stored content, never the slug: hundreds of them are published under broken slugs.
	if ( null !== dos_agents_head_meta_brokerages( $post ) ) {
		return array( 'brokerages', $post );
	}
	return array( 'page', $post );
}

/**
 * The city of an older brokerage-summary page: array( city, ST, brokerages ), or null.
 *
 * Reads the stored content once, with no queries. The page is recognized by its heading, "Top-Rated
 * Real Estate Brokerages in {City}, {ST}: ...", which survives wpautop, entity forms and tags inside
 * the h3. The count is the number of entries showing "(Sales:", which is what the page lists.
 */
function dos_agents_head_meta_brokerages( $post ) {
	static $memo = array();
	$key = spl_object_id( $post );
	if ( isset( $memo[ $key ] ) && $memo[ $key ][0] === $post->post_content ) {
		return $memo[ $key ][1] ?: null;
	}
	$content = (string) $post->post_content;
	$found   = false;
	if ( false !== stripos( $content, 'Top-Rated' ) && ! has_shortcode( $content, 'breanm_agents' )
		&& preg_match( '/<h3\b[^>]*>((?:(?!<\/h3>).)*Top-Rated(?:(?!<\/h3>).)*)<\/h3>/is', $content, $h ) ) {
		$heading = html_entity_decode( wp_strip_all_tags( $h[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$heading = (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $heading );
		if ( preg_match( '/Top-Rated Real Estate Brokerages in (.+?)\s*,\s*([A-Za-z]{2})\s*:/iu', $heading, $m ) && '' !== trim( $m[1] ) ) {
			$found = array( trim( $m[1] ), strtoupper( $m[2] ), preg_match_all( '/\(\s*Sales\s*:/i', $content ) );
		}
	}
	$memo[ $key ] = array( $content, $found );
	return $found ?: null;
}

/**
 * The city a page is for: array( slug, city, ST, agents|null ), or null when it is not a city page.
 *
 * The index row (one non-autoloaded option, no query per agent) gives the stored agent count. A page
 * that holds the shortcode but has no row yet (agents not pushed, or held back) still gets a city
 * name, read off its slug, and a null count so the description makes no claim about how many.
 */
function dos_agents_head_meta_city( $post ) {
	static $memo = array();
	if ( isset( $memo[ $post->ID ] ) ) {
		return $memo[ $post->ID ] ?: null;
	}
	$memo[ $post->ID ] = false;

	$slug = dos_agents_city_slug_from_page( $post->post_name );
	if ( '' === $slug ) {
		return null;
	}
	$index = dos_agents_index();
	if ( isset( $index[ $slug ] ) && '' !== $index[ $slug ]['c'] && '' !== $index[ $slug ]['s'] ) {
		$row               = $index[ $slug ];
		$memo[ $post->ID ] = array( $slug, $row['c'], $row['s'], (int) $row['n'] );
		return $memo[ $post->ID ];
	}
	// No row: only a page that looks like a city page counts, i.e. "<city>-<st>" with a real state
	// code on the end, or one that holds the shortcode.
	if ( ! preg_match( '/^(.+)-([a-z]{2})$/', $slug, $m ) || ! isset( dos_agents_state_names()[ strtoupper( $m[2] ) ] ) ) {
		return null;
	}
	if ( ! has_shortcode( (string) $post->post_content, 'breanm_agents' ) ) {
		return null;
	}
	$memo[ $post->ID ] = array( $slug, ucwords( str_replace( '-', ' ', $m[1] ) ), strtoupper( $m[2] ), null );
	return $memo[ $post->ID ];
}

/** Plain text from markup: shortcodes, tags and entities gone, whitespace collapsed. */
function dos_agents_head_plain( $s ) {
	$s = (string) $s;
	if ( function_exists( 'strip_shortcodes' ) ) {
		$s = strip_shortcodes( $s );
	}
	// Shortcodes nothing has registered are left in place by strip_shortcodes().
	$s = preg_replace( '/\[\/?[A-Za-z0-9_-]+(?:\s[^\]]*)?\]/', ' ', $s );
	$s = wp_strip_all_tags( (string) $s );
	$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
}

function dos_agents_head_len( $s ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
}

function dos_agents_head_sub( $s, $len, $start = 0 ) {
	return function_exists( 'mb_substr' ) ? mb_substr( $s, $start, $len, 'UTF-8' ) : substr( $s, $start, $len );
}

/**
 * Cuts $text to at most $max characters on a word boundary. A cut text ends in an ellipsis, after
 * dropping any comma, colon or dash the cut left dangling, so it never ends in a stray mark.
 */
function dos_agents_head_trim( $text, $max ) {
	$text = trim( (string) $text );
	if ( dos_agents_head_len( $text ) <= $max ) {
		return $text;
	}
	// One character is kept back for the ellipsis. If the cut lands inside a word (the last kept
	// character and the first dropped one are both not spaces), back up to the previous space.
	$cut  = dos_agents_head_sub( $text, $max - 1 );
	$next = dos_agents_head_sub( $text, 1, $max - 1 );
	if ( ! preg_match( '/\s/u', $next ) && ! preg_match( '/\s$/u', $cut ) && preg_match( '/^(.*)\s\S*$/us', $cut, $m ) ) {
		$cut = $m[1];
	}
	$cut = (string) preg_replace( '/[\s,;:(\/\-\x{2013}\x{2014}]+$/u', '', $cut );
	return $cut . "\xe2\x80\xa6";
}

/**
 * The first sentences of a post's text, within the limit. Prefers to end on a sentence, as long as
 * that keeps a reasonable amount of the text; otherwise falls back to the word-boundary cut.
 */
function dos_agents_head_lead( $text, $max ) {
	if ( dos_agents_head_len( $text ) <= $max ) {
		return $text;
	}
	$window = dos_agents_head_sub( $text, $max );
	if ( preg_match( '/^(.{60,}[.!?])(?=\s|$)/us', $window, $m ) ) {
		return $m[1];
	}
	return dos_agents_head_trim( $text, $max );
}

/** A post's own excerpt (a manual one only; WordPress's auto-excerpt is not stored), as plain text. */
function dos_agents_head_excerpt( $post ) {
	if ( ! $post instanceof WP_Post || ! empty( $post->post_password ) ) {
		return '';
	}
	return dos_agents_head_plain( isset( $post->post_excerpt ) ? $post->post_excerpt : '' );
}

/** Cities and states with agent cards, from the index: array( cities, states ). */
function dos_agents_head_counts() {
	$cities = count( dos_agents_index() );
	return array( $cities, count( dos_agents_index_states() ) );
}

/** "1 city" or "12 cities", each with its own plural. */
function dos_agents_head_cities( $n ) {
	/* translators: %s: number of cities */
	return sprintf( _n( '%s city', '%s cities', $n, 'dos-agents' ), number_format_i18n( $n ) );
}

/** "1 state" or "3 states". */
function dos_agents_head_states( $n ) {
	/* translators: %s: number of states */
	return sprintf( _n( '%s state', '%s states', $n, 'dos-agents' ), number_format_i18n( $n ) );
}

/** The description for a view, or '' when there is nothing true and useful to say. */
function dos_agents_head_description( $kind, $post ) {
	$text = '';
	switch ( $kind ) {
		case 'city':
			$city = dos_agents_head_meta_city( $post );
			if ( null === $city ) {
				break;
			}
			$place = $city[1] . ', ' . $city[2];
			if ( null === $city[3] || $city[3] < 1 ) {
				// Nothing stored (or nothing shown yet): say what the page is for, with no count.
				$text = sprintf(
					/* translators: %s: city and state, e.g. "Boise, ID" */
					__( 'Real estate agents in %s: ratings, local sales, what reviewers say, and how recent the reviews are.', 'dos-agents' ),
					$place
				);
			} elseif ( 1 === $city[3] ) {
				$text = sprintf(
					/* translators: %s: city and state, e.g. "Boise, ID" */
					__( 'The real estate agent in %s: rating, local sales, what reviewers say, and how recent the reviews are.', 'dos-agents' ),
					$place
				);
			} else {
				$text = sprintf(
					/* translators: 1: number of agents, 2: city and state, e.g. "Boise, ID" */
					__( 'Compare %1$s real estate agents in %2$s: ratings, local sales, what reviewers say about each one, and how recent the reviews are.', 'dos-agents' ),
					number_format_i18n( $city[3] ),
					$place
				);
			}
			break;

		case 'brokerages':
			$b = dos_agents_head_meta_brokerages( $post );
			if ( null === $b ) {
				break;
			}
			$place = $b[0] . ', ' . $b[1];
			if ( $b[2] >= 2 ) {
				$full  = sprintf(
					/* translators: 1: number of brokerages, 2: city and state, e.g. "Boise, ID" */
					__( 'Compare %1$s real estate brokerages in %2$s by sales, rating and what clients say about them.', 'dos-agents' ),
					number_format_i18n( $b[2] ),
					$place
				);
				$short = sprintf(
					/* translators: 1: number of brokerages, 2: city and state */
					__( 'Compare %1$s real estate brokerages in %2$s.', 'dos-agents' ),
					number_format_i18n( $b[2] ),
					$place
				);
			} elseif ( 1 === $b[2] ) {
				/* translators: %s: city and state */
				$full  = sprintf( __( 'A real estate brokerage serving %s: its sales, rating and what clients say about it.', 'dos-agents' ), $place );
				/* translators: %s: city and state */
				$short = sprintf( __( 'A real estate brokerage serving %s.', 'dos-agents' ), $place );
			} else {
				/* translators: %s: city and state */
				$full  = sprintf( __( 'Real estate brokerages in %s: sales, ratings and what clients say.', 'dos-agents' ), $place );
				/* translators: %s: city and state */
				$short = sprintf( __( 'Real estate brokerages in %s.', 'dos-agents' ), $place );
			}
			// A very long city name pushes the full sentence past the limit; use the short one rather
			// than let the trim cut mid-sentence.
			$text = $short;
			foreach ( array( $full, $short ) as $candidate ) {
				if ( dos_agents_head_len( $candidate ) <= dos_agents_head_meta_max() ) {
					$text = $candidate;
					break;
				}
			}
			break;

		case 'hub':
			$state = dos_agents_hub_state( $post->ID );
			$name  = dos_agents_state_name( $state );
			$rows  = array_values( dos_agents_index_for_state( $state ) );
			$n     = count( $rows );
			if ( 0 === $n ) {
				$text = sprintf(
					/* translators: %s: state name */
					__( 'Real estate agents in %s. Pick a city to compare local agents and what reviewers say about them.', 'dos-agents' ),
					$name
				);
				break;
			}
			// Biggest first by agent count, then by name, so the three named are the three a visitor most
			// expects to see.
			usort(
				$rows,
				static function ( $a, $b ) {
					return array( -$a['n'], $a['c'] ) <=> array( -$b['n'], $b['c'] );
				}
			);
			if ( $n >= 3 ) {
				$pick = sprintf(
					/* translators: 1, 2, 3: city names */
					__( ', including %1$s, %2$s and %3$s', 'dos-agents' ),
					$rows[0]['c'],
					$rows[1]['c'],
					$rows[2]['c']
				);
			} elseif ( 2 === $n ) {
				/* translators: 1, 2: city names */
				$pick = sprintf( __( ': %1$s and %2$s', 'dos-agents' ), $rows[0]['c'], $rows[1]['c'] );
			} else {
				/* translators: %s: city name */
				$pick = sprintf( __( ': %s', 'dos-agents' ), $rows[0]['c'] );
			}
			// Mirrors the hub's own heading ("N cities with agent cards"): the hub page also lists every
			// city in the state, so the count has to say it is the ones with cards.
			$lead = sprintf(
				/* translators: 1: state name, 2: number of cities, 3: the city list, already punctuated */
				_n( 'Real estate agents in %1$s: %2$s city with agent cards%3$s.', 'Real estate agents in %1$s: %2$s cities with agent cards%3$s.', $n, 'dos-agents' ),
				$name,
				number_format_i18n( $n ),
				$pick
			);
			// Three long city names push the full call to action past the limit, and a cut in the middle
			// of it reads badly. Fall back to a shorter one, then to the lead sentence alone, rather than
			// let the trim chop a sentence.
			$text = $lead;
			foreach ( array(
				$lead . ' ' . ( 1 === $n ? __( 'Pick the city to compare local agents and what reviewers say about them.', 'dos-agents' ) : __( 'Pick a city to compare local agents and what reviewers say about them.', 'dos-agents' ) ),
				$lead . ' ' . __( 'Pick a city to compare local agents.', 'dos-agents' ),
			) as $candidate ) {
				if ( dos_agents_head_len( $candidate ) <= dos_agents_head_meta_max() ) {
					$text = $candidate;
					break;
				}
			}
			break;

		case 'home':
		case 'by-state':
			list( $cities, $states ) = dos_agents_head_counts();
			if ( $cities < 1 ) {
				$text = __( 'Compare real estate agents city by city: what reviewers say about each agent and how recent the reviews are.', 'dos-agents' );
			} elseif ( 'home' === $kind ) {
				$text = sprintf(
					/* translators: 1: "12 cities", 2: "3 states" */
					__( 'Compare real estate agents in %1$s across %2$s: what reviewers say about each agent, how recent the reviews are, and sales in the city.', 'dos-agents' ),
					dos_agents_head_cities( $cities ),
					dos_agents_head_states( $states )
				);
			} else {
				$text = sprintf(
					/* translators: 1: "3 states", 2: "12 cities" */
					__( 'Browse real estate agents by state: %1$s and %2$s with agent cards. Pick a state, then a city, to compare local agents.', 'dos-agents' ),
					dos_agents_head_states( $states ),
					dos_agents_head_cities( $cities )
				);
			}
			break;

		case 'blog':
			$text = dos_agents_head_excerpt( $post ); // A /blog/ page's own excerpt wins; the posts page has none here.
			if ( '' !== $text ) {
				break;
			}
			$text = sprintf(
				/* translators: %s: site name */
				__( 'Articles from %s about working with a real estate agent.', 'dos-agents' ),
				dos_agents_head_site_name()
			);
			break;

		case 'post':
			$text = dos_agents_head_excerpt( $post );
			if ( '' === $text && $post instanceof WP_Post && empty( $post->post_password ) ) {
				$text = dos_agents_head_lead( dos_agents_head_plain( $post->post_content ), dos_agents_head_meta_max() );
			}
			break;

		default:
			// Any other page: its own excerpt or nothing.
			$text = dos_agents_head_excerpt( $post );
	}

	$text = dos_agents_head_trim( dos_agents_head_plain( $text ), dos_agents_head_meta_max() );
	$text = (string) apply_filters( 'dos_agents_meta_description', $text, $post, $kind );
	return dos_agents_head_trim( dos_agents_head_plain( $text ), dos_agents_head_meta_max() );
}

function dos_agents_head_site_name() {
	return trim( html_entity_decode( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/** A post or page title as plain text: WordPress hands titles back with &#8211; and &#038; in them. */
function dos_agents_head_post_title( $post ) {
	// The raw title, run through the title filters (texturize) like get_the_title() but without its
	// "Protected: " and "Private: " prefixes, which belong in the page heading and not in a share card.
	$raw   = $post instanceof WP_Post ? (string) $post->post_title : '';
	$title = (string) apply_filters( 'the_title', $raw, $post instanceof WP_Post ? $post->ID : 0 );
	return trim( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/** The Open Graph title: a clean one, not the WordPress document title. */
function dos_agents_head_og_title( $kind, $post ) {
	$title = '';
	switch ( $kind ) {
		case 'city':
			$city = dos_agents_head_meta_city( $post );
			if ( null !== $city ) {
				/* translators: %s: city and state, e.g. "Boise, ID" (the page's H1) */
				$title = sprintf( __( 'Real Estate Agents in %s', 'dos-agents' ), $city[1] . ', ' . $city[2] );
			}
			break;
		case 'brokerages':
			$b = dos_agents_head_meta_brokerages( $post );
			if ( null !== $b ) {
				/* translators: %s: city and state, e.g. "Boise, ID" */
				$title = sprintf( __( 'Real Estate Brokerages in %s', 'dos-agents' ), $b[0] . ', ' . $b[1] );
			}
			break;
		case 'hub':
			/* translators: %s: state name (the hub's H1) */
			$title = sprintf( __( 'Real Estate Agents in %s', 'dos-agents' ), dos_agents_state_name( dos_agents_hub_state( $post->ID ) ) );
			break;
		case 'home':
			$title = dos_agents_head_site_name();
			break;
		case 'blog':
			$page_id = $post instanceof WP_Post ? (int) $post->ID : (int) get_option( 'page_for_posts' );
			$title   = $page_id ? dos_agents_head_post_title( get_post( $page_id ) ) : '';
			break;
		default:
			$title = $post instanceof WP_Post ? dos_agents_head_post_title( $post ) : '';
	}
	if ( '' === $title ) {
		$title = dos_agents_head_site_name();
	}
	return (string) apply_filters( 'dos_agents_og_title', $title, $post, $kind );
}

/** The page's canonical URL: the one WordPress itself reports, https as it reports it. */
function dos_agents_head_url( $kind, $post ) {
	if ( 'home' === $kind ) {
		return home_url( '/' );
	}
	if ( 'blog' === $kind && ! $post instanceof WP_Post ) {
		$page_id = (int) get_option( 'page_for_posts' );
		return $page_id ? (string) get_permalink( $page_id ) : home_url( '/' );
	}
	$url = function_exists( 'wp_get_canonical_url' ) ? wp_get_canonical_url( $post ) : '';
	return $url ? (string) $url : (string) get_permalink( $post );
}

/** True for a URL whose path ends in .svg: Open Graph needs a raster image. */
function dos_agents_head_is_svg( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	return 1 === preg_match( '/\.svgz?$/i', $path );
}

/**
 * The share image: the post's featured image, else the Site Icon at 512px, else none.
 *
 * Never the plugin's SVG favicon (assets/brand/breanm-favicon.svg): Open Graph readers do not render
 * SVG. A filter result that points at one is dropped for the same reason.
 *
 * @return array {url, width, height, alt} or array() when there is none.
 */
function dos_agents_head_image( $kind, $post ) {
	$image = array();
	if ( $post instanceof WP_Post && has_post_thumbnail( $post ) ) {
		$id  = (int) get_post_thumbnail_id( $post );
		$src = $id ? wp_get_attachment_image_src( $id, 'large' ) : false;
		if ( is_array( $src ) && ! empty( $src[0] ) ) {
			$alt   = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			$image = array(
				'url'    => (string) $src[0],
				'width'  => isset( $src[1] ) ? (int) $src[1] : 0,
				'height' => isset( $src[2] ) ? (int) $src[2] : 0,
				'alt'    => '' !== $alt ? $alt : dos_agents_head_post_title( $post ),
			);
		}
	}
	if ( ! $image ) {
		$icon = (string) get_site_icon_url( 512 );
		if ( '' !== $icon ) {
			$image = array(
				'url'    => $icon,
				'width'  => 512,
				'height' => 512,
				'alt'    => dos_agents_head_site_name(),
			);
		}
	}
	$image = apply_filters( 'dos_agents_og_image', $image, $post, $kind );
	if ( ! is_array( $image ) || empty( $image['url'] ) || dos_agents_head_is_svg( $image['url'] ) ) {
		return array();
	}
	return array(
		'url'    => (string) $image['url'],
		'width'  => isset( $image['width'] ) ? (int) $image['width'] : 0,
		'height' => isset( $image['height'] ) ? (int) $image['height'] : 0,
		'alt'    => isset( $image['alt'] ) ? (string) $image['alt'] : '',
	);
}

/** Prints one <meta> tag. */
function dos_agents_head_tag( $attr, $name, $content ) {
	if ( '' === (string) $content ) {
		return;
	}
	echo '<meta ' . $attr . '="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . "\">\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- $attr is one of two literals.
}

/** wp_head: the description, Open Graph and Twitter Card tags for this view. */
function dos_agents_head_meta() {
	if ( ! dos_agents_head_meta_enabled() ) {
		return;
	}
	$view = dos_agents_head_meta_view();
	if ( null === $view ) {
		return;
	}
	list( $kind, $post ) = $view;

	$desc  = dos_agents_head_description( $kind, $post );
	$title = dos_agents_head_og_title( $kind, $post );
	$url   = dos_agents_head_url( $kind, $post );
	$image = dos_agents_head_image( $kind, $post );

	echo "<!-- DoS Agents head meta -->\n";
	dos_agents_head_tag( 'name', 'description', $desc );

	dos_agents_head_tag( 'property', 'og:type', 'post' === $kind ? 'article' : 'website' );
	dos_agents_head_tag( 'property', 'og:site_name', dos_agents_head_site_name() );
	dos_agents_head_tag( 'property', 'og:title', $title );
	dos_agents_head_tag( 'property', 'og:description', $desc );
	if ( '' !== $url ) {
		echo '<meta property="og:url" content="' . esc_url( $url ) . "\">\n";
	}
	dos_agents_head_tag( 'property', 'og:locale', str_replace( '-', '_', (string) get_locale() ) );
	if ( $image ) {
		echo '<meta property="og:image" content="' . esc_url( $image['url'] ) . "\">\n";
		if ( $image['width'] > 0 && $image['height'] > 0 ) {
			dos_agents_head_tag( 'property', 'og:image:width', (string) $image['width'] );
			dos_agents_head_tag( 'property', 'og:image:height', (string) $image['height'] );
		}
		dos_agents_head_tag( 'property', 'og:image:alt', $image['alt'] );
	}

	// Large card only for a wide picture: a square 512px Site Icon stretched into the large card is
	// cropped into a blur, and the plain summary card shows it as the thumbnail it is.
	$wide = $image && $image['width'] >= 600;
	dos_agents_head_tag( 'name', 'twitter:card', $wide ? 'summary_large_image' : 'summary' );
	dos_agents_head_tag( 'name', 'twitter:title', $title );
	dos_agents_head_tag( 'name', 'twitter:description', $desc );
	if ( $image ) {
		echo '<meta name="twitter:image" content="' . esc_url( $image['url'] ) . "\">\n";
	}
	echo "<!-- /DoS Agents head meta -->\n";
}
