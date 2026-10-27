<?php
/**
 * DoS - Agents: the homepage pieces.
 *
 * Pins the one rule the whole thing rests on — a city is offered only when its published page really
 * carries the shortcode — plus the search matching that PHP and breanm-home.js both have to agree on,
 * the carousel and hub output, the Themify layout answers, and the header swap.
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

/** Stores a city and gives it a published page carrying the shortcode, the normal live state. */
function home_city( $city, $state, $agents = 2, $content = '[breanm_agents]', $status = 'publish' ) {
	$slug = dos_agents_city_slug( $city, $state );
	$rows = array();
	for ( $i = 0; $i < $agents; $i++ ) {
		$rows[] = agents_make( array( 'name' => "$city agent $i" ) );
	}
	update_option( dos_agents_option_name( $slug ), array( 'city' => $city, 'state' => $state, 'agents' => $rows ), false );
	agents_add_page( 'in-' . $slug, $content, $status );
	dos_agents_index_touch( $slug );
	return $slug;
}

echo "--- The index only holds cities a visitor can actually use ---\n";
agents_reset();
home_city( 'Boise', 'ID', 3 );
$index = dos_agents_index();
check( 'a stored city with a published page holding the shortcode is in', isset( $index['boise-id'] ) );
check( 'the row carries city, state, url and agent count', isset( $index['boise-id'] ) && array( 'Boise', 'ID', 'https://example.com/in-boise-id/', 3 ) === array_values( $index['boise-id'] ), json_encode( $index['boise-id'] ?? null ) );

agents_reset();
home_city( 'Draftville', 'ID', 3, '[breanm_agents]', 'draft' );
check( 'a city whose page is only a draft is left out', ! isset( dos_agents_index()['draftville-id'] ) );

agents_reset();
home_city( 'Shortcodeless', 'ID', 3, 'Just some words about agents.' );
check( 'a published page without the shortcode is left out', ! isset( dos_agents_index()['shortcodeless-id'] ) );

agents_reset();
update_option( dos_agents_option_name( 'ghost-id' ), array( 'city' => 'Ghost', 'state' => 'ID', 'agents' => array() ), false );
agents_add_page( 'in-ghost-id', '[breanm_agents]' );
dos_agents_index_touch( 'ghost-id' );
check( 'a city with no stored agents is left out', ! isset( dos_agents_index()['ghost-id'] ) );

agents_reset();
update_option( dos_agents_option_name( 'nopage-id' ), array( 'city' => 'Nopage', 'state' => 'ID', 'agents' => array( agents_make() ) ), false );
dos_agents_index_touch( 'nopage-id' );
check( 'a city with no page at all is left out', ! isset( dos_agents_index()['nopage-id'] ) );

echo "\n--- A rolled-back page drops out again ---\n";
agents_reset();
home_city( 'Rollback', 'ID', 4 );
check( 'in to begin with', isset( dos_agents_index()['rollback-id'] ) );
$was = dos_agents_index_ver();
agents_add_page( 'in-rollback-id', 'The old content, with no shortcode.' );
dos_agents_index_touch( 'rollback-id' );
check( 'out once the shortcode is gone', ! isset( dos_agents_index()['rollback-id'] ) );
check( 'and the version moved, so a cached copy is not served', dos_agents_index_ver() > $was );

$steady = dos_agents_index_ver();
dos_agents_index_touch( 'rollback-id' );
check( 'touching a city that did not change leaves the version alone', $steady === dos_agents_index_ver() );

echo "\n--- save_post is what adds a city, since the agents are pushed first ---\n";
agents_reset();
$slug = dos_agents_city_slug( 'Later', 'ID' );
update_option( dos_agents_option_name( $slug ), array( 'city' => 'Later', 'state' => 'ID', 'agents' => array( agents_make() ) ), false );
dos_agents_index_touch( $slug );
check( 'pushed agents alone do not put a city in front of visitors', ! isset( dos_agents_index()[ $slug ] ) );
$page = agents_add_page( 'in-later-id', '[breanm_agents]' );
dos_agents_index_on_save( $page->ID, $page );
check( 'the city appears once the page is saved with the shortcode', isset( dos_agents_index()[ $slug ] ) );

$other = agents_add_page( 'about-us', 'Nothing to do with cities.' );
$before = dos_agents_index();
dos_agents_index_on_save( $other->ID, $other );
check( 'saving an unrelated page changes nothing', $before === dos_agents_index() );

echo "\n--- Rebuilding from the stored cities ---\n";
agents_reset();
home_city( 'Boise', 'ID' );
home_city( 'Meridian', 'ID' );
update_option( DOS_AGENTS_DISCLOSURE_OPTION, 'Some words.', false );
update_option( DOS_AGENTS_INDEX_OPTION, array( 'stale-xx' => array( 'c' => 'Stale', 's' => 'XX', 'u' => '/x/', 'n' => 1 ) ), false );
$n = dos_agents_index_rebuild();
check( 'the rebuild finds both cities', 2 === $n, (string) $n );
check( 'and drops a row no city backs any more', ! isset( dos_agents_index()['stale-xx'] ) );
check( 'the disclosure option is not mistaken for a city', ! isset( dos_agents_index()['disclosure'] ) );

echo "\n--- Search: the rules breanm-home.js has to match ---\n";
check( 'accents and punctuation go', 'st helens' === dos_agents_search_norm( "St. Helens" ) );
check( 'Saint folds to St', dos_agents_search_norm( 'Saint Helens' ) === dos_agents_search_norm( 'St. Helens' ) );
check( 'Mount folds to Mt', dos_agents_search_norm( 'Mount Vernon' ) === dos_agents_search_norm( 'Mt Vernon' ) );
check( 'Fort folds to Ft', dos_agents_search_norm( 'Fort Worth' ) === dos_agents_search_norm( 'Ft. Worth' ) );
check( 'a code is a state', array( 'WA' ) === dos_agents_search_states( 'wa', 1 ) );
check( 'a full name is a state', array( 'ID' ) === dos_agents_search_states( 'idaho', 3 ) );
check( '"new" is not enough for a state on its own', array() === dos_agents_search_states( 'new', 5 ) );
$r = dos_agents_search_readings( 'Seattle, WA' );
check( 'a comma splits city from state', array( array( 'seattle', array( 'WA' ) ) ) === $r, json_encode( $r ) );
$r = dos_agents_search_readings( 'portland or' );
check( 'a trailing state code is read as one, and the whole string as a name', 2 === count( $r ) && array( 'portland', array( 'OR' ) ) === $r[1], json_encode( $r ) );

agents_reset();
home_city( 'Springfield', 'IL' );
home_city( 'Springfield', 'MA' );
home_city( 'Springfield', 'MO' );
home_city( 'Spring Hill', 'FL' );
home_city( 'Boise', 'ID' );
$rows = dos_agents_index();

$hits = dos_agents_search_match( $rows, 'springfield' );
check( 'every Springfield matches, Spring Hill does not', 3 === count( $hits ), json_encode( array_column( $hits, 'c' ) ) );
check( 'an ambiguous city resolves to nothing', null === dos_agents_search_resolve( $rows, 'springfield' ) );
$one = dos_agents_search_resolve( $rows, 'springfield mo' );
check( 'naming the state resolves it', $one && 'MO' === $one['s'] );
$one = dos_agents_search_resolve( $rows, 'Springfield, Massachusetts' );
check( 'so does the comma form with a full state name', $one && 'MA' === $one['s'] );
$one = dos_agents_search_resolve( $rows, 'boi' );
check( 'a prefix that can only be one city resolves', $one && 'Boise' === $one['c'] );
check( 'nonsense resolves to nothing', null === dos_agents_search_resolve( $rows, 'zzzz' ) );

home_city( 'Spring', 'TX' );
$rows = dos_agents_index();
$hits = dos_agents_search_match( $rows, 'spring' );
check( 'the city actually called Spring sorts above the ones it is only a prefix of', 'Spring' === $hits[0]['c'], json_encode( array_column( $hits, 'c' ) ) );
check( 'and it is the only whole-name hit', 1 === count( array_filter( $hits, static function ( $h ) { return $h['rank'] <= 1; } ) ) );
$ranked = dos_agents_search_match( $rows, 'springfield il' );
check( 'naming the state leaves only that Springfield', 1 === count( $ranked ) && 'IL' === $ranked[0]['s'], json_encode( array_map( static function ( $h ) { return $h['s'] . ':' . $h['rank']; }, $ranked ) ) );
check( 'and it is a whole-name, state-qualified hit, the best rank there is', 0 === $ranked[0]['rank'] );
$hits = dos_agents_search_match( $rows, 'hill' );
check( 'a later word can be matched from its start', 1 === count( $hits ) && 'Spring Hill' === $hits[0]['c'] );

echo "\n--- The no-JavaScript path ---\n";
agents_reset();
home_city( 'Boise', 'ID' );
home_city( 'Springfield', 'IL' );
home_city( 'Springfield', 'MA' );
$GLOBALS['wp']['search'] = true;
$GLOBALS['wp']['query']  = array( 's' => 'Boise' );
$_GET['breanm_city']     = '1';
/** Runs the fallback and reports where it sent the visitor, or null when it let the query through. */
function home_fallback() {
	$GLOBALS['wp']['redirect'] = null;
	try {
		dos_agents_search_fallback();
	} catch ( RedirectHalt $e ) { // The real code exits here.
		return array( $e->url, $e->status );
	}
	return null;
}
check( 'one match redirects to the city', array( 'https://example.com/in-boise-id/', 302 ) === home_fallback(), json_encode( $GLOBALS['wp']['redirect'] ) );

$GLOBALS['wp']['query'] = array( 's' => 'Springfield' );
check( 'an ambiguous query falls through to the theme', null === home_fallback() );

$GLOBALS['wp']['query'] = array( 's' => '' );
check( 'an empty query falls through', null === home_fallback() );

unset( $_GET['breanm_city'] );
$GLOBALS['wp']['query'] = array( 's' => 'Boise' );
check( 'a search that did not come from our form is left alone', null === home_fallback() );
$_GET = array();

$_GET['breanm_city']     = '1';
$GLOBALS['wp']['search'] = false;
$GLOBALS['wp']['query']  = array( 's' => 'Boise' );
check( 'a page that is not a search is left alone', null === home_fallback() );
$_GET = array();
$GLOBALS['wp']['search'] = true;

echo "\n--- Browse by state ---\n";
agents_reset();
home_city( 'Boise', 'ID' );
home_city( 'Meridian', 'ID' );
home_city( 'Nampa', 'ID' );
home_city( 'Seattle', 'WA' );
home_city( 'Tacoma', 'WA' );
home_city( 'Reno', 'NV' );
agents_add_page( 'in-idaho', 'Every city in Idaho.' );
agents_add_page( 'in-washington', 'Every city in Washington.' );

$counts = dos_agents_index_states();
check( 'cities are counted per state', array( 'ID' => 3, 'WA' => 2, 'NV' => 1 ) === $counts, json_encode( $counts ) );
check( 'the state with the most cities comes first', 'ID' === array_key_first( $counts ) );

$html = dos_agents_render_states();
check( 'every state gets a card', 3 === substr_count( $html, 'breanm-home__sc"' ), $html );
check( 'a state with a hub page is a link', false !== strpos( $html, 'href="https://example.com/in-idaho/"' ) );
check( 'a state with no hub page is not a link', false === strpos( $html, 'in-nevada' ) && false !== strpos( $html, '<div class="breanm-home__sc-link">' ) );
check( 'the card says how many cities', false !== strpos( $html, '>3 cities<' ) && false !== strpos( $html, '>1 city<' ) );
check( 'the note counts cities and states', false !== strpos( $html, '6 cities across 3 states' ), $html );
check( 'the state outline is drawn inline', false !== strpos( $html, '<svg class="breanm-home__sc-img"' ) );
check( 'the arrows ship hidden, for the script to reveal', 2 === substr_count( $html, 'breanm-home__car-btn' ) && 2 === substr_count( $html, 'hidden>' ) );

agents_reset();
check( 'with no cities at all the carousel prints nothing', '' === dos_agents_render_states() );

echo "\n--- Browse by state as a grid ---\n";
agents_reset();
home_city( 'Boise', 'ID' );
home_city( 'Seattle', 'WA' );
$grid = dos_agents_render_states( true );
check( 'the grid lists every state', 2 === substr_count( $grid, 'breanm-home__sc"' ), $grid );
check( 'it is a grid, not a scrolling row', false !== strpos( $grid, 'breanm-home__grid' ) && false === strpos( $grid, 'breanm-home__car' ) );
check( 'and carries no carousel hook or arrows', false === strpos( $grid, 'data-breanm-carousel' ) && false === strpos( $grid, 'breanm-home__car-btn' ) );
check( 'the grid carries a filter box, shipped hidden for the script to reveal', false !== strpos( $grid, 'breanm-home__filter' ) && false !== strpos( $grid, 'hidden' ) && false !== strpos( $grid, 'data-breanm-state-filter' ) );
check( 'with the count labels handed over for translation', false !== strpos( $grid, 'data-label-all=' ) && false !== strpos( $grid, 'data-label-some=' ) && false !== strpos( $grid, 'data-label-none=' ) );
check( 'every card carries what the filter matches on', 2 === substr_count( $grid, 'data-name="' ) && 2 === substr_count( $grid, 'data-code="' ), $grid );
check( 'a two-word state is normalised for matching', false !== strpos( dos_agents_render_states( true ), 'data-name="' ) );
check( 'the carousel gets no filter box', false === strpos( dos_agents_render_states(), 'breanm-home__filter' ) );

$viaShortcode = dos_agents_state_index_shortcode( array( 'layout' => 'grid' ) );
check( 'layout="grid" on the shortcode reaches it', false !== strpos( $viaShortcode, 'breanm-home__grid' ) );
check( 'and the default is still the carousel', false !== strpos( dos_agents_state_index_shortcode( array() ), 'data-breanm-carousel' ) );

echo "\n--- A state hub block ---\n";
agents_reset();
home_city( 'Seattle', 'WA', 18 );
home_city( 'Tacoma', 'WA', 12 );
home_city( 'Boise', 'ID', 9 );
$html = dos_agents_render_hub( 'WA' );
check( 'the heading names the state and counts its cities', false !== strpos( $html, '2 cities in Washington with agent cards' ), $html );
check( 'only that state is listed', false !== strpos( $html, '>Seattle<' ) && false !== strpos( $html, '>Tacoma<' ) && false === strpos( $html, '>Boise<' ) );
check( 'each city says how many agents', false !== strpos( $html, '>18 agents<' ) );
$html = dos_agents_render_hub( 'OR' );
check( 'a state with nothing yet says so by name', false !== strpos( $html, 'No city in Oregon has agent cards yet' ), $html );

echo "\n--- The city box ---\n";
agents_reset();
home_city( 'Boise', 'ID' );
$html = dos_agents_city_search_shortcode( array() );
check( 'the form posts to the site root so it works without JavaScript', false !== strpos( $html, 'action="https://example.com/"' ) );
check( 'it carries the marker the fallback looks for', false !== strpos( $html, 'name="breanm_city" value="1"' ) );
check( 'the cities route carries the index version', false !== strpos( $html, 'v=' . dos_agents_index_ver() ) );
check( 'a small index rides along in the markup', false !== strpos( $html, 'data-cities=' ) );
check( 'the messages are handed over for translation', false !== strpos( $html, 'data-msg-many=' ) && false !== strpos( $html, 'data-msg-none=' ) );

add_filter( 'dos_agents_inline_max', static function () { return 0; } );
$html = dos_agents_city_search_shortcode( array() );
check( 'past the cap the browser fetches the index instead', false === strpos( $html, 'data-cities=' ) );
$GLOBALS['filters'] = array();

echo "\n--- A hostile city name cannot break out ---\n";
agents_reset();
// A quote survives sanitising (only tags are stripped), so this is the character that would end the
// data-cities attribute early if the JSON were not escaped on its way in.
home_city( 'Ev"il', 'ID' );
$html = dos_agents_city_search_shortcode( array() );
check( 'the quote is escaped inside data-cities', false !== strpos( $html, '&quot;' ) && false === strpos( $html, 'data-cities="[{"c":"Ev"' ), substr( $html, strpos( $html, 'data-cities' ), 120 ) );
check( 'the attribute still parses back to the city name', 'Ev"il' === json_decode( html_entity_decode( substr( $html, strpos( $html, 'data-cities="' ) + 13, strpos( $html, '"', strpos( $html, 'data-cities="' ) + 13 ) - strpos( $html, 'data-cities="' ) - 13 ), ENT_QUOTES ), true )[0]['c'] );

agents_reset();
home_city( 'Ev<script>alert(1)</script>il', 'ID' );
$html = dos_agents_render_hub( 'ID' );
check( 'a tag in a city name never reaches the hub card', false === strpos( $html, '<script>' ) );

echo "\n--- City pages render as pages, not posts ---\n";
agents_reset();
$city  = agents_add_page( 'in-boise-id', 'Agents in Boise. [breanm_agents]' );
$plain = agents_add_page( 'about-us', 'About this site.' );
check( 'a city page is given the no-sidebar layout', 'sidebar-none' === dos_agents_layout_meta( null, $city->ID, 'layout', true ) );
check( 'and its post date is hidden', 'yes' === dos_agents_layout_meta( null, $city->ID, 'hide_post_date', true ) );
check( 'an ordinary page is left to the theme', null === dos_agents_layout_meta( null, $plain->ID, 'layout', true ) );
check( 'a key we do not own is left alone', null === dos_agents_layout_meta( null, $city->ID, 'something_else', true ) );
check( 'a value already set wins', 'sidebar1' === dos_agents_layout_meta( 'sidebar1', $city->ID, 'layout', true ) );

$GLOBALS['wp']['page']    = true;
$GLOBALS['wp']['queried'] = $city->ID;
$classes = dos_agents_layout_body_class( array( 'page', 'sidebar1', 'default_width' ) );
check( 'the sidebar class is swapped out', ! in_array( 'sidebar1', $classes, true ) && in_array( 'sidebar-none', $classes, true ), json_encode( $classes ) );
check( 'and the page is marked for the stylesheet', in_array( 'breanm-agents-page', $classes, true ) );
$GLOBALS['wp']['queried'] = $plain->ID;
check( 'an ordinary page keeps its classes', array( 'page', 'sidebar1' ) === dos_agents_layout_body_class( array( 'page', 'sidebar1' ) ) );

add_filter( 'dos_agents_page_layout', '__return_false' );
$GLOBALS['wp']['queried'] = $city->ID;
check( 'the filter turns the whole thing off', null === dos_agents_layout_meta( null, $city->ID, 'layout', true ) );
$GLOBALS['filters'] = array();

echo "\n--- A rebuild does not hold every city in memory ---\n";
agents_reset();
home_city( 'Boise', 'ID' );
home_city( 'Seattle', 'WA' );
update_option( DOS_AGENTS_DISCLOSURE_OPTION, 'Some words.', false );
$GLOBALS['wp']['cache_deletes'] = array();
dos_agents_index_rebuild();
check( 'every city option read is dropped from the cache again', in_array( dos_agents_option_name( 'boise-id' ), $GLOBALS['wp']['cache_deletes'], true ) && in_array( dos_agents_option_name( 'seattle-wa' ), $GLOBALS['wp']['cache_deletes'], true ), json_encode( $GLOBALS['wp']['cache_deletes'] ) );
check( 'our own options are skipped, not read as cities', 2 === count( dos_agents_index() ) );

echo "\n--- A page deleted outright leaves the index ---\n";
agents_reset();
home_city( 'Boise', 'ID' );
$page = $GLOBALS['wp']['pages']['in-boise-id'];
check( 'in to begin with', isset( dos_agents_index()['boise-id'] ) );
unset( $GLOBALS['wp']['pages']['in-boise-id'] );   // force-deleted: no save_post fires
dos_agents_index_on_delete( $page->ID, $page );
check( 'gone once the page is deleted', ! isset( dos_agents_index()['boise-id'] ) );
$other = agents_add_page( 'about-us', 'Nothing to do with cities.' );
$before = dos_agents_index();
dos_agents_index_on_delete( $other->ID, $other );
check( 'deleting an unrelated page changes nothing', $before === dos_agents_index() );

echo "\n--- The layout filter keeps quiet until init ---\n";
agents_reset();
$city = agents_add_page( 'in-boise-id', 'Agents in Boise. [breanm_agents]' );
$GLOBALS['wp']['did_init'] = false;
check( 'before init it answers nothing, rather than memoising a wrong no', null === dos_agents_layout_meta( null, $city->ID, 'layout', true ) );
$GLOBALS['wp']['did_init'] = true;
check( 'after init the city page is recognised', 'sidebar-none' === dos_agents_layout_meta( null, $city->ID, 'layout', true ) );

echo "\n--- State hub pages are recognised by their slug ---\n";
agents_reset();
$wa = agents_add_page( 'in-washington', 'All cities in Washington. <table>...</table>' );
$nh = agents_add_page( 'in-new-hampshire', 'All cities in New Hampshire.' );
$city = agents_add_page( 'in-boise-id', 'Agents in Boise. [breanm_agents]' );
$other = agents_add_page( 'in-house-financing', 'Nothing to do with states.' );
check( 'a one-word state hub is found', 'WA' === dos_agents_hub_state( $wa->ID ) );
check( 'so is a two-word one', 'NH' === dos_agents_hub_state( $nh->ID ) );
check( 'a city page is not a hub', '' === dos_agents_hub_state( $city->ID ) );
check( 'and neither is an unrelated in- page', '' === dos_agents_hub_state( $other->ID ) );

echo "\n--- The hub block is added without touching the page ---\n";
agents_reset();
home_city( 'Seattle', 'WA', 18 );
home_city( 'Tacoma', 'WA', 12 );
$wa = agents_add_page( 'in-washington', '<h3>All Cities in Washington (470)</h3>' );
$GLOBALS['wp']['page'] = true;
$GLOBALS['wp']['queried'] = $wa->ID;
$out = dos_agents_hub_the_content( '<h3>All Cities in Washington (470)</h3>' );
check( 'the state name becomes the h1', false !== strpos( $out, '<h1 class="breanm-hub-title">Real Estate Agents in Washington</h1>' ), substr( $out, 0, 160 ) );
check( 'the cities-with-cards block is there', false !== strpos( $out, '2 cities in Washington with agent cards' ) );
check( "the page's own content is kept, after it", false !== strpos( $out, 'All Cities in Washington (470)' ) && strpos( $out, 'breanm-hub-title' ) < strpos( $out, 'All Cities' ) );
$again = dos_agents_hub_the_content( '<h3>All Cities in Washington (470)</h3>' );
check( 'a second pass over the same page adds nothing', false === strpos( $again, 'breanm-hub-title' ) );

$plain = agents_add_page( 'about-us', 'About.' );
$GLOBALS['wp']['queried'] = $plain->ID;
check( 'an ordinary page is returned untouched', 'About.' === dos_agents_hub_the_content( 'About.' ) );

$GLOBALS['wp']['queried'] = agents_add_page( 'in-oregon', 'Oregon cities.' )->ID;
$GLOBALS['wp']['in_loop'] = false;
check( 'and so is a hub outside the main loop', 'Oregon cities.' === dos_agents_hub_the_content( 'Oregon cities.' ) );
$GLOBALS['wp']['in_loop'] = true;

echo "\n--- A hub asks for the stylesheet it needs ---\n";
agents_reset();
home_city( 'Seattle', 'WA', 18 );
$wa = agents_add_page( 'in-washington', '<h3>All Cities in Washington (470)</h3>' );
$GLOBALS['wp']['singular'] = true;
$GLOBALS['wp']['post'] = $wa;
$GLOBALS['wp']['page'] = true;
$GLOBALS['wp']['queried'] = $wa->ID;
dos_agents_maybe_enqueue_home();
check( 'the hub page enqueues breanm-home.css in head', wp_style_is( 'breanm-home' ), json_encode( array_keys( $GLOBALS['wp']['styles'] ) ) );
$GLOBALS['wp']['styles'] = array();
dos_agents_hub_the_content( '<h3>x</h3>' );
check( 'and the content filter asks again as a fallback', wp_style_is( 'breanm-home' ) );
$GLOBALS['wp']['singular'] = false;

echo "\n--- Hubs get the page layout too ---\n";
agents_reset();
$wa = agents_add_page( 'in-washington', '<h3>All Cities in Washington (470)</h3>' );
$GLOBALS['wp']['page'] = true;
$GLOBALS['wp']['queried'] = $wa->ID;
check( 'a hub loses its sidebar', 'sidebar-none' === dos_agents_layout_meta( null, $wa->ID, 'layout', true ) );
$classes = dos_agents_layout_body_class( array( 'page', 'sidebar1' ) );
check( 'the body class swaps the sidebar out', ! in_array( 'sidebar1', $classes, true ) && in_array( 'sidebar-none', $classes, true ), json_encode( $classes ) );
check( 'and marks it as a hub for the stylesheet', in_array( 'breanm-hub-page', $classes, true ) );

add_filter( 'dos_agents_hub_block', '__return_false' );
check( 'the filter turns the block off', '<h3>x</h3>' === dos_agents_hub_the_content( '<h3>x</h3>' ) );
$GLOBALS['filters'] = array();

echo "\n--- The header wordmark ---\n";
$themify = '<div id="site-logo"><a href="https://example.com/"><span>Best Real Estate Agents Near Me</span></a></div>';
$out     = dos_agents_brand_swap_logo( $themify );
check( 'the wordmark replaces what was inside the link', false !== strpos( $out, 'breanm-brand__name' ) && false === strpos( $out, '<span>Best Real Estate Agents Near Me</span>' ), $out );
check( 'the link keeps its href', false !== strpos( $out, 'href="https://example.com/"' ) );
check( 'the link is given the brand class', false !== strpos( $out, 'class="breanm-brand__link"' ) );
check( 'the tagline is there', false !== strpos( $out, 'Which agents actually sell in your city' ) );
$plain_html = '<header><h1>Some other theme</h1></header>';
check( 'a theme without that markup is returned untouched', $plain_html === dos_agents_brand_swap_logo( $plain_html ) );
check( 'the body gets the brand class', in_array( 'breanm-brand', dos_agents_brand_body_class( array( 'home' ) ), true ) );

finish();
