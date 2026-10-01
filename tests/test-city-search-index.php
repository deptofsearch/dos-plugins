<?php
/**
 * City Search: the index. Where each city's label and URL come from, which pages are left out,
 * how the cache is keyed, when it is thrown away, and what the REST feeds return.
 */

require __DIR__ . '/wp-stubs-city-search.php';
require dirname( __DIR__ ) . '/plugins/dos-city-search/dos-city-search.php';

function cs_set( array $settings ) {
	$GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ] = array_merge( dos_city_search_defaults(), $settings );
	dos_city_search_flush();
}

function cs_labels( array $index ) { return array_column( $index, 'n' ); }

echo "--- slug_state: labels from the page title ---\n";
cs_reset();
cs_set( array( 'source' => 'slug_state' ) );
$GLOBALS['wpdb']->rows = array(
	cs_page( 'mcallen-tx', 'McAllen TX – What&#8217;s My Home Worth' ),
	cs_page( 'coeur-d-alene-id', "Coeur d'Alene ID – What's My Home Worth" ),
	cs_page( 'kennewick-wa', 'Kennewick, WA – Market Report' ),
	cs_page( 'weird-place-tx', 'A title with no state in it' ),
	cs_page( 'nowhere-zz', 'Nowhere ZZ – Market Report' ),
);
$index = dos_city_search_build_index();
$by    = array_column( $index, 'n', 's' );

check( '"McAllen TX – …" becomes "McAllen, TX"', 'McAllen, TX' === ( $by['mcallen-tx'] ?? '' ), json_encode( $by ) );
check( 'a title with an apostrophe keeps it: "Coeur d\'Alene, ID"', "Coeur d'Alene, ID" === ( $by['coeur-d-alene-id'] ?? '' ), json_encode( $by ) );
check( '"Kennewick, WA – …" (comma already there) becomes "Kennewick, WA"', 'Kennewick, WA' === ( $by['kennewick-wa'] ?? '' ), json_encode( $by ) );
check( 'an unparseable title falls back to the slug', 'Weird Place, TX' === ( $by['weird-place-tx'] ?? '' ), json_encode( $by ) );
$meta = $GLOBALS['options'][ DOS_CITY_SEARCH_META ];
check( 'the fallback is counted and listed in the meta', 1 === $meta['fallback_n'] && array( 'weird-place-tx' ) === $meta['fallbacks'], json_encode( $meta ) );
check( 'a slug ending -zz is not a state: rejected', ! isset( $by['nowhere-zz'] ) );
check( 'every entry is n, u, s in that shape, with an absolute URL', array( 'n', 'u', 's' ) === array_keys( $index[0] ) && 0 === strpos( $index[0]['u'], 'https://example.com/' ), json_encode( $index[0] ) );
check( 'the URL is the page address', 'https://example.com/mcallen-tx/' === array_column( $index, 'u', 's' )['mcallen-tx'] );
check( 'the meta says which source built it', 'slug_state' === $meta['source'] );

echo "\n--- natural sort ---\n";
cs_reset();
cs_set( array( 'source' => 'slug_state' ) );
$GLOBALS['wpdb']->rows = array(
	cs_page( 'city10-tx', 'City10 TX – x' ),
	cs_page( 'city2-tx', 'City2 TX – x' ),
	cs_page( 'austin-tx', 'austin TX – x' ),
	cs_page( 'boise-id', 'Boise ID – x' ),
);
check( 'sorted naturally and case-insensitively (City2 before City10)', array( 'austin, TX', 'Boise, ID', 'City2, TX', 'City10, TX' ) === cs_labels( dos_city_search_build_index() ), json_encode( cs_labels( dos_city_search_build_index() ) ) );

echo "\n--- taxonomy source ---\n";
cs_reset();
cs_set( array( 'source' => 'taxonomy' ) );
$GLOBALS['cs']['taxonomies'] = array( 'ohi_city' );
$GLOBALS['cs']['page_ids']   = array( 3, 5, 9, 12 );   // get_posts() orders by ID, oldest first
$GLOBALS['cs']['slugs']      = array( 3 => 'austin-open-houses', 5 => 'austin-guide', 9 => 'texas-hub', 12 => 'boise-open-houses' );
$GLOBALS['cs']['terms']      = array(
	3  => array( cs_term( 1, 'Austin' ) ),
	5  => array( cs_term( 1, 'Austin' ) ),
	9  => array( cs_term( 1, 'Austin' ), cs_term( 2, 'Boise' ) ),
	12 => array( cs_term( 2, 'Boise' ) ),
);
$index = dos_city_search_build_index();
$meta  = $GLOBALS['options'][ DOS_CITY_SEARCH_META ];
check( 'labels are the term names, in order', array( 'Austin', 'Boise' ) === cs_labels( $index ), json_encode( $index ) );
check( 'the oldest page wins a shared city', 'https://example.com/austin-open-houses/' === $index[0]['u'] && 'austin-open-houses' === $index[0]['s'], json_encode( $index[0] ) );
check( 'a page with several cities is skipped', 9 === ( $meta['skipped'][1]['id'] ?? 0 ) && false !== strpos( $meta['skipped'][1]['why'], '2 cities' ), json_encode( $meta['skipped'] ) );
check( 'the later page for the same city is skipped, and says why', 5 === ( $meta['skipped'][0]['id'] ?? 0 ) && false !== strpos( $meta['skipped'][0]['why'], 'Austin' ), json_encode( $meta['skipped'] ) );
check( 'the meta records the skips and the source', 2 === count( $meta['skipped'] ) && 'taxonomy' === $meta['source'] );

cs_reset();
cs_set( array( 'source' => 'taxonomy' ) );
check( 'taxonomy source with the taxonomy missing yields an empty list, not an error', array() === dos_city_search_build_index() );

echo "\n--- auto source ---\n";
cs_reset();
cs_set( array( 'source' => 'auto' ) );
check( 'auto resolves to slug_state when the taxonomy does not exist', 'slug_state' === dos_city_search_resolve_source() );
$GLOBALS['cs']['taxonomies'] = array( 'ohi_city' );
check( 'auto resolves to taxonomy when it does', 'taxonomy' === dos_city_search_resolve_source() );
cs_set( array( 'source' => 'auto', 'taxonomy' => 'market_city' ) );
check( 'auto follows the configured taxonomy name, not a fixed one', 'slug_state' === dos_city_search_resolve_source() );
cs_set( array( 'source' => 'slug_state' ) );
check( 'an explicit source is not second-guessed', 'slug_state' === dos_city_search_resolve_source() );

echo "\n--- the cache ---\n";
cs_reset();
cs_set( array( 'source' => 'slug_state' ) );
$GLOBALS['wpdb']->rows = array( cs_page( 'mcallen-tx', 'McAllen TX – x' ) );
$GLOBALS['transients']['revnm_city_index_v1'] = array( 'Old Town, TX|old-town-tx' );
$got = dos_city_search_get_index();
check( 'the old revnm_city_index_v1 transient (strings) is never read', array( 'McAllen, TX' ) === cs_labels( $got ), json_encode( $got ) );
check( 'the new index is cached under the v2 key', isset( $GLOBALS['transients']['dos_city_search_index_v2'] ) && 'dos_city_search_index_v2' === DOS_CITY_SEARCH_TRANSIENT );
check( 'the old transient is left alone', array( 'Old Town, TX|old-town-tx' ) === $GLOBALS['transients']['revnm_city_index_v1'] );
check( 'a non-empty index is cached for 12 hours', 12 * HOUR_IN_SECONDS === $GLOBALS['ttls'][ DOS_CITY_SEARCH_TRANSIENT ] );
check( 'the build records how many cities there are', 1 === $GLOBALS['options'][ DOS_CITY_SEARCH_META ]['count'] );
$GLOBALS['wpdb']->rows = array();
check( 'a cached index is served without rebuilding', 1 === count( dos_city_search_get_index() ) );

dos_city_search_flush();
$got = dos_city_search_get_index();
check( 'an empty index is cached for only 5 minutes (the taxonomy may not have registered yet)', array() === $got && 5 * MINUTE_IN_SECONDS === $GLOBALS['ttls'][ DOS_CITY_SEARCH_TRANSIENT ], (string) ( $GLOBALS['ttls'][ DOS_CITY_SEARCH_TRANSIENT ] ?? 'none' ) );

echo "\n--- invalidation ---\n";
dos_city_search_register_index_hooks();
check( 'the hooks are registered', isset( $GLOBALS['actions']['transition_post_status'], $GLOBALS['actions']['set_object_terms'], $GLOBALS['actions']['edited_ohi_city'], $GLOBALS['actions']['delete_ohi_city'] ) );

function cs_primed() { $GLOBALS['transients'][ DOS_CITY_SEARCH_TRANSIENT ] = array( 'x' ); }
function cs_cleared() { return ! isset( $GLOBALS['transients'][ DOS_CITY_SEARCH_TRANSIENT ] ); }
$page = (object) array( 'post_type' => 'page' );

cs_primed(); dos_city_search_on_transition( 'publish', 'draft', $page );
check( 'publishing a page clears it', cs_cleared() );
cs_primed(); dos_city_search_on_transition( 'draft', 'publish', $page );
check( 'unpublishing a page clears it', cs_cleared() );
cs_primed(); dos_city_search_on_transition( 'publish', 'publish', $page );
check( 'saving a published page clears it (a rename no longer waits 12 hours)', cs_cleared() );
cs_primed(); dos_city_search_on_transition( 'draft', 'draft', $page );
check( 'saving a draft leaves it', ! cs_cleared() );
cs_primed(); dos_city_search_on_transition( 'publish', 'draft', (object) array( 'post_type' => 'post' ) );
check( 'a post is not a city page', ! cs_cleared() );

cs_set( array( 'source' => 'slug_state' ) );
cs_primed(); dos_city_search_on_terms( 3, array(), array(), 'ohi_city' );
check( 'tagging a page does nothing on the slug source', ! cs_cleared() );
cs_primed(); dos_city_search_on_term_change();
check( 'renaming a city does nothing on the slug source', ! cs_cleared() );

cs_set( array( 'source' => 'taxonomy' ) );
cs_primed(); dos_city_search_on_terms( 3, array(), array(), 'ohi_city' );
check( 'tagging a page clears it on the taxonomy source', cs_cleared() );
cs_primed(); dos_city_search_on_terms( 3, array(), array(), 'category' );
check( 'tagging with some other taxonomy does not', ! cs_cleared() );
cs_primed(); dos_city_search_on_term_change();
check( 'renaming or deleting a city clears it on the taxonomy source', cs_cleared() );
cs_primed(); do_action_stub( 'update_option_' . DOS_CITY_SEARCH_OPTION );
check( 'saving the settings clears it (a changed source shows at once)', cs_cleared() );

echo "\n--- REST ---\n";
cs_reset();
cs_set( array( 'source' => 'slug_state' ) );
$GLOBALS['wpdb']->rows = array( cs_page( 'mcallen-tx', 'McAllen TX – x' ), cs_page( 'boise-id', 'Boise ID – x' ) );
dos_city_search_register_rest();
do_action_stub( 'rest_api_init' );
check( 'both routes are registered', isset( $GLOBALS['routes']['dos-city-search/v1/cities'], $GLOBALS['routes']['revnm/v1/cities'] ), implode( ',', array_keys( $GLOBALS['routes'] ) ) );
check( 'both are public', '__return_true' === $GLOBALS['routes']['dos-city-search/v1/cities']['permission_callback'] && '__return_true' === $GLOBALS['routes']['revnm/v1/cities']['permission_callback'] );

$new = call_user_func( $GLOBALS['routes']['dos-city-search/v1/cities']['callback'] );
check( 'dos-city-search/v1 returns the objects', array( 'n' => 'Boise, ID', 'u' => 'https://example.com/boise-id/', 's' => 'boise-id' ) === $new->data[0], json_encode( $new->data ) );
check( 'dos-city-search/v1 is cacheable for an hour', 'public, max-age=3600' === $new->headers['Cache-Control'] );

$old = call_user_func( $GLOBALS['routes']['revnm/v1/cities']['callback'] );
check( 'revnm/v1 still returns "label|slug" strings', array( 'Boise, ID|boise-id', 'McAllen, TX|mcallen-tx' ) === $old->data, json_encode( $old->data ) );
check( 'revnm/v1 is cacheable for an hour', 'public, max-age=3600' === $old->headers['Cache-Control'] );

finish();
