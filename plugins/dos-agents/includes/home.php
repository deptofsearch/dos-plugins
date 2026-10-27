<?php
/**
 * The homepage pieces: [breanm_city_search] and [breanm_state_index].
 *
 *   [breanm_city_search]              the city box, with a typeahead over the cities that have cards.
 *   [breanm_state_index]              "Browse by state": one card per state, with its city count.
 *   [breanm_state_index state="WA"]   the cities in one state, for the top of that state's hub page.
 *
 * All three read dos_agents_index(), so what the box can find, what the counts claim and what the
 * hubs list are the same set of cities by construction.
 *
 * Every card and option is in the HTML before the script runs. The typeahead is an improvement on a
 * form that already works.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dos_agents_register_home_shortcodes() {
	add_shortcode( 'breanm_city_search', 'dos_agents_city_search_shortcode' );
	add_shortcode( 'breanm_state_index', 'dos_agents_state_index_shortcode' );
}

/**
 * How many cities ride along in the page markup. Beyond this the script fetches the index from the
 * cities route instead, so the homepage does not grow without limit as the sweep works through the
 * queue. 400 covers the index comfortably today and leaves room to grow.
 */
function dos_agents_inline_max() {
	return max( 0, (int) apply_filters( 'dos_agents_inline_max', 400 ) );
}

/** The cities route, carrying the index version so a browser never keeps yesterday's list. */
function dos_agents_cities_url() {
	return add_query_arg( 'v', dos_agents_index_ver(), rest_url( 'breanm/v1/cities' ) );
}

/** wp_enqueue_scripts and the shortcodes themselves: the homepage stylesheet and script. */
function dos_agents_home_enqueue() {
	$base = plugin_dir_url( DOS_AGENTS_FILE ) . 'assets/';
	wp_enqueue_style( 'breanm-home', $base . 'breanm-home.css', array(), DOS_AGENTS_VERSION );
	wp_enqueue_script( 'breanm-home', $base . 'breanm-home.js', array(), DOS_AGENTS_VERSION, true );
}

function dos_agents_maybe_enqueue_home() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}
	// A state hub never holds the shortcode: hub.php renders the block straight into the content, so
	// nothing would ask for the stylesheet and the city cards would come out as a bulleted list.
	if ( '' !== dos_agents_hub_state( $post->ID ) && dos_agents_hub_enabled() ) {
		dos_agents_home_enqueue();
		return;
	}
	$content = (string) $post->post_content;
	if ( has_shortcode( $content, 'breanm_city_search' ) || has_shortcode( $content, 'breanm_state_index' ) ) {
		dos_agents_home_enqueue();
	}
}

/**
 * [breanm_city_search]: the box. With JavaScript it suggests cities as you type and goes straight to
 * the one picked; without it, the form submits and dos_agents_search_fallback() redirects when the
 * query means exactly one city.
 */
function dos_agents_city_search_shortcode( $atts ) {
	static $n = 0;
	++$n;
	$id = 'breanm-cs-' . $n;
	$a  = shortcode_atts(
		array(
			'heading'     => __( 'Find an agent in your city', 'dos-agents' ),
			'placeholder' => __( 'Type a city (for example Boise)', 'dos-agents' ),
			'button'      => __( 'Search', 'dos-agents' ),
		),
		$atts,
		'breanm_city_search'
	);
	dos_agents_home_enqueue();

	$index  = dos_agents_index_public();
	$inline = count( $index ) <= dos_agents_inline_max() ? $index : null;

	ob_start();
	?>
<section class="breanm-home breanm-home__search" data-api="<?php echo esc_url( dos_agents_cities_url() ); ?>"
	data-msg-many="<?php esc_attr_e( 'More than one city matches. Pick one.', 'dos-agents' ); ?>"
	data-msg-none="<?php esc_attr_e( 'No city with agent cards matches that yet. Try a nearby city, or browse by state below.', 'dos-agents' ); ?>"
	<?php if ( null !== $inline ) : ?>
	data-cities="<?php echo esc_attr( (string) wp_json_encode( $inline ) ); ?>"
	<?php endif; ?>
	>
	<h2 class="breanm-home__search-heading"><?php echo esc_html( $a['heading'] ); ?></h2>
	<form class="breanm-home__search-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" autocomplete="off">
		<input type="hidden" name="breanm_city" value="1">
		<label class="breanm-home__sr" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'City name', 'dos-agents' ); ?></label>
		<div class="breanm-home__search-wrap">
			<input id="<?php echo esc_attr( $id ); ?>" class="breanm-home__input" name="s" type="text"
				placeholder="<?php echo esc_attr( $a['placeholder'] ); ?>"
				role="combobox" aria-autocomplete="list" aria-expanded="false"
				aria-controls="<?php echo esc_attr( $id ); ?>-list"
				spellcheck="false" autocapitalize="words" enterkeyhint="go">
			<ul id="<?php echo esc_attr( $id ); ?>-list" class="breanm-home__list" role="listbox" hidden></ul>
		</div>
		<button type="submit" class="breanm-home__btn"><?php echo esc_html( $a['button'] ); ?></button>
	</form>
	<p class="breanm-home__msg" role="status" aria-live="polite"></p>
</section>
	<?php
	return ob_get_clean();
}

/**
 * [breanm_state_index]: the carousel with no state, one state's cities with state="WA".
 * layout="grid" lays the state cards out as a full grid instead of a scrolling row — what the
 * by-state page wants, where browsing every state IS the page and hiding 46 of them behind a
 * horizontal scroll would be perverse.
 */
function dos_agents_state_index_shortcode( $atts ) {
	$a     = shortcode_atts( array( 'state' => '', 'layout' => '' ), $atts, 'breanm_state_index' );
	$state = dos_agents_state( $a['state'] );
	dos_agents_home_enqueue();

	if ( '' !== $state ) {
		return dos_agents_render_hub( $state );
	}
	return dos_agents_render_states( 'grid' === strtolower( trim( (string) $a['layout'] ) ) );
}

/**
 * "Browse by state": a scroll-snapping row of state cards, richest state first. A plain scrollable
 * list; the script only adds the previous/next buttons, which stay hidden until it runs.
 */
function dos_agents_render_states( $grid = false ) {
	$counts = dos_agents_index_states();
	if ( ! $counts ) {
		return '';
	}

	$cards = '';
	foreach ( $counts as $code => $n ) {
		$name = dos_agents_state_name( $code );
		$page = dos_agents_state_page( $code );
		$body = dos_agents_outline_svg( $code )
			. '<span class="breanm-home__sc-name">' . esc_html( $name ) . '</span>'
			. '<span class="breanm-home__sc-meta">'
			/* translators: %s: number of cities */
			. esc_html( sprintf( _n( '%s city', '%s cities', $n, 'dos-agents' ), number_format_i18n( $n ) ) )
			. '</span>';
		// A state whose hub page is missing still gets a card: the count is true either way.
		$cards .= null === $page
			? '<li class="breanm-home__sc"><div class="breanm-home__sc-link">' . $body . '</div></li>'
			: '<li class="breanm-home__sc"><a class="breanm-home__sc-link" href="' . esc_url( get_permalink( $page ) ) . '">' . $body . '</a></li>';
	}

	$cities = array_sum( $counts );
	$note   = sprintf(
		/* translators: 1: number of cities, 2: number of states */
		_n(
			'%1$s city across %2$s states has agent cards so far, and more are added every day. Pick a state to see which of its cities are ready.',
			'%1$s cities across %2$s states have agent cards so far, and more are added every day. Pick a state to see which of its cities are ready.',
			$cities,
			'dos-agents'
		),
		number_format_i18n( $cities ),
		number_format_i18n( count( $counts ) )
	);

	// A grid is a plain list with no scroll affordances, so it carries no carousel hook and no arrows.
	if ( $grid ) {
		return '<section class="breanm-home breanm-home__states breanm-home__states--grid">'
			. '<p class="breanm-home__states-note">' . esc_html( $note ) . '</p>'
			. '<ul class="breanm-home__grid" aria-label="' . esc_attr__( 'States', 'dos-agents' ) . '">' . $cards . '</ul>'
			. '</section>';
	}

	return '<section class="breanm-home breanm-home__states" data-breanm-carousel>'
		. '<div class="breanm-home__states-head">'
		. '<h2 class="breanm-home__states-title">' . esc_html__( 'Browse by state', 'dos-agents' ) . '</h2>'
		. '<div class="breanm-home__car-nav">'
		. '<button type="button" class="breanm-home__car-btn" data-dir="-1" aria-label="' . esc_attr__( 'Previous states', 'dos-agents' ) . '" hidden><span aria-hidden="true">&#8249;</span></button>'
		. '<button type="button" class="breanm-home__car-btn" data-dir="1" aria-label="' . esc_attr__( 'Next states', 'dos-agents' ) . '" hidden><span aria-hidden="true">&#8250;</span></button>'
		. '</div></div>'
		. '<p class="breanm-home__states-note">' . esc_html( $note ) . '</p>'
		. '<ul class="breanm-home__car" tabindex="0" aria-label="' . esc_attr__( 'States', 'dos-agents' ) . '">' . $cards . '</ul>'
		. '</section>';
}

/**
 * [breanm_state_index state="WA"]: the cities in one state that have cards, for the top of the hub
 * page. The hub's own list of every city in the state stays below it; this says which ones are ready,
 * which is what the homepage card promised when it said "8 cities".
 */
function dos_agents_render_hub( $state ) {
	$rows = dos_agents_index_for_state( $state );
	$name = dos_agents_state_name( $state );
	if ( ! $rows ) {
		return '<section class="breanm-home breanm-home__hub"><p class="breanm-home__hub-empty">'
			. esc_html(
				sprintf(
					/* translators: %s: state name */
					__( 'No city in %s has agent cards yet. They are added city by city; check back soon.', 'dos-agents' ),
					$name
				)
			)
			. '</p></section>';
	}

	$cards = '';
	foreach ( $rows as $row ) {
		$cards .= '<li class="breanm-home__hub-card"><a class="breanm-home__hub-link" href="' . esc_url( $row['u'] ) . '">'
			. '<span class="breanm-home__hub-city">' . esc_html( $row['c'] ) . '</span>'
			. '<span class="breanm-home__hub-meta">'
			/* translators: %s: number of agents */
			. esc_html( sprintf( _n( '%s agent', '%s agents', $row['n'], 'dos-agents' ), number_format_i18n( $row['n'] ) ) )
			. '</span></a></li>';
	}

	$heading = sprintf(
		/* translators: 1: number of cities, 2: state name */
		_n( '%1$s city in %2$s with agent cards', '%1$s cities in %2$s with agent cards', count( $rows ), 'dos-agents' ),
		number_format_i18n( count( $rows ) ),
		$name
	);

	return '<section class="breanm-home breanm-home__hub">'
		. '<h2 class="breanm-home__hub-title">' . esc_html( $heading ) . '</h2>'
		. '<ul class="breanm-home__hub-grid">' . $cards . '</ul>'
		. '</section>';
}
