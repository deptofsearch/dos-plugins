<?php
/**
 * City Search: the markup, where it lands on the homepage, the shortcodes, and the CSS. The
 * REVNM homepage's own stylesheet is written for rv-sec / rv-search / rv-btn and for the box sitting
 * directly under the hero, so those are pinned here.
 */

require __DIR__ . '/wp-stubs-city-search.php';
require dirname( __DIR__ ) . '/plugins/dos-city-search/dos-city-search.php';

function cs_site( array $settings = array() ) {
	cs_reset();
	$GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ] = array_merge( dos_city_search_defaults(), array( 'source' => 'slug_state' ), $settings );
	cs_cities( 3 );
}

/** A REVNM site as it stands after the 2.0.0 migration of its 1.x settings. */
function cs_revnm_site() {
	cs_reset();
	$GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ] = array( 'auto_insert' => 1, 'heading' => 'Search Home Values by City', 'bg_color' => '#eaf2fc' );
	dos_city_search_maybe_migrate();
	cs_cities( 3 );
}

$hero = '<section class="rv-sec rv-hero"><h1>What&#8217;s My Home Worth?</h1><p>Hero copy.</p></section>';
$rest = "\n<section class=\"rv-sec\"><h2>How it works</h2><p>Body.</p></section>";
$box  = '<section class="dos-city-search';

echo "--- hero_class mode ---\n";
cs_site( array( 'insert_after' => 'hero_class' ) );
$out = dos_city_search_filter_content( $hero . $rest );
check( 'goes directly after the hero section', 0 === strpos( $out, $hero . "\n" . $box ), substr( $out, 0, 200 ) );
check( 'and before the next section', strpos( $out, 'How it works' ) > strpos( $out, 'dos-city-search' ) );

cs_site( array( 'insert_after' => 'hero_class' ) );
$plain = '<h1>Home</h1><p>No hero here.</p>' . $rest;
check( 'does nothing when the page has no hero (it will not guess)', $plain === dos_city_search_filter_content( $plain ) );
check( '… and is still free to insert later in the same request', false === dos_city_search_inserted() );

cs_site( array( 'insert_after' => 'hero_class', 'hero_class' => 'big-banner' ) );
$out = dos_city_search_filter_content( '<section class="big-banner">B</section><p>x</p>' );
check( 'the hero class is configurable', 0 === strpos( $out, '<section class="big-banner">B</section>' . "\n" . $box ) );

echo "\n--- auto mode ---\n";
cs_site( array( 'insert_after' => 'auto' ) );
$out = dos_city_search_filter_content( '<section class="x">Opening</section><h2>Next</h2>' );
check( 'a page that opens with a section: after that section', 0 === strpos( $out, '<section class="x">Opening</section>' . "\n" . $box ) );

cs_site( array( 'insert_after' => 'auto' ) );
$out = dos_city_search_filter_content( '<h1>Title</h1><p>Intro.</p><h2>First heading</h2><p>More.</p>' );
check( 'no opening section: right before the first H2', false !== strpos( $out, '<p>Intro.</p>' . "\n" . $box ) && strpos( $out, 'First heading' ) > strpos( $out, 'dos-city-search' ), $out );

cs_site( array( 'insert_after' => 'auto' ) );
$out = dos_city_search_filter_content( '<p>Only a paragraph.</p><p>Second.</p>' );
check( 'no section, no H2: after the first paragraph', 0 === strpos( $out, '<p>Only a paragraph.</p>' . "\n" . $box ) && false !== strpos( $out, '<p>Second.</p>' ) );

cs_site( array( 'insert_after' => 'auto' ) );
$out = dos_city_search_filter_content( 'bare text' );
check( 'nothing to anchor to: at the top', 0 === strpos( $out, $box ) );

echo "\n--- the guards ---\n";
cs_site();
$first  = dos_city_search_filter_content( '<p>One.</p>' );
$second = dos_city_search_filter_content( '<p>Two.</p>' );
check( 'inserts once, never twice in one request', 1 === substr_count( $first, $box ) && '<p>Two.</p>' === $second );

foreach ( array( 'dos-city-search', 'rv-city-search', 'ohi-city-search' ) as $marker ) {
	cs_site();
	$content = '<p>Intro.</p><section class="' . $marker . '">already here</section>';
	check( "content already holding \"$marker\": left alone", $content === dos_city_search_filter_content( $content ) );
}

cs_site( array( 'auto_insert' => 0 ) );
check( 'auto_insert off: does nothing', '<p>x</p>' === dos_city_search_filter_content( '<p>x</p>' ) );

cs_site();
$GLOBALS['cs']['main'] = false;
check( 'not the main query: does nothing', '<p>x</p>' === dos_city_search_filter_content( '<p>x</p>' ) );
cs_site();
$GLOBALS['cs']['loop'] = false;
check( 'outside the loop: does nothing', '<p>x</p>' === dos_city_search_filter_content( '<p>x</p>' ) );
cs_site();
$GLOBALS['cs']['front'] = false;
check( 'not the front page: does nothing', '<p>x</p>' === dos_city_search_filter_content( '<p>x</p>' ) );

echo "\n--- shortcodes ---\n";
cs_site( array( 'section_class' => 'rv-sec' ) );
dos_city_search_register_front();
check( 'all three shortcodes are registered', isset( $GLOBALS['shortcodes']['dos_city_search'], $GLOBALS['shortcodes']['revnm_city_search'], $GLOBALS['shortcodes']['ohi_city_search'] ) );
check( 'and the content filter runs after shortcodes (priority is 20 in the registration)', in_array( 'dos_city_search_filter_content', $GLOBALS['filters']['the_content'], true ) );
$a = cs_norm( call_user_func( $GLOBALS['shortcodes']['dos_city_search'] ) );
$b = cs_norm( call_user_func( $GLOBALS['shortcodes']['revnm_city_search'] ) );
$c = cs_norm( call_user_func( $GLOBALS['shortcodes']['ohi_city_search'] ) );
check( 'they produce identical markup', $a === $b && $b === $c && '' !== $a );

echo "\n--- markup ---\n";
cs_site();
$html = dos_city_search_markup();
check( 'canonical classes', false !== strpos( $html, 'class="dos-city-search"' ) && false !== strpos( $html, 'class="dos-cs-form"' ) && false !== strpos( $html, 'class="dos-cs-wrap"' ) && false !== strpos( $html, 'class="dos-cs-list"' ) && false !== strpos( $html, 'class="dos-cs-msg"' ) && false !== strpos( $html, 'class="dos-cs-btn"' ) && false !== strpos( $html, 'class="dos-cs-sr"' ) );
check( 'ids are dos-cs-N', 1 === preg_match( '/id="dos-cs-\d+"/', $html ) && 1 === preg_match( '/aria-controls="dos-cs-\d+-list"/', $html ) );
check( 'the form works without JavaScript: action, method get, name="s"', false !== strpos( $html, 'action="https://example.com/"' ) && false !== strpos( $html, 'method="get"' ) && false !== strpos( $html, 'name="s"' ) );
check( 'no REVNM or OHI class leaks into the plain markup', false === strpos( $html, 'rv-' ) && false === strpos( $html, 'ohi-' ) );
check( 'the REST address is always there', false !== strpos( $html, 'data-api="https://example.com/wp-json/dos-city-search/v1/cities"' ) );
check( 'the script is configured by data attributes', false !== strpos( $html, 'data-max="8"' ) && false !== strpos( $html, 'data-all="0"' ) && false !== strpos( $html, 'data-source="slug_state"' ) );
check( 'no browse link when the hub page does not exist', false === strpos( $html, 'data-browse' ) );

cs_site();
$GLOBALS['cs']['paths']['by-city'] = (object) array( 'ID' => 40, 'post_status' => 'publish' );
$GLOBALS['cs']['slugs'][40]        = 'by-city';
check( 'the browse link points at the by-city page when it is published', false !== strpos( dos_city_search_markup(), 'data-browse="https://example.com/by-city/"' ) );
$GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ]['browse_slug'] = '';
check( 'and an empty browse_slug turns it off', false === strpos( dos_city_search_markup(), 'data-browse' ) );

cs_site( array( 'section_class' => 'rv-sec rv-city-search rv-sec dos-city-search', 'form_class' => 'rv-search rv-search', 'button_class' => 'rv-btn  rv-btn' ) );
$html = dos_city_search_markup();
check( 'extra classes are appended once each', false !== strpos( $html, 'class="dos-city-search rv-sec rv-city-search"' ) && false !== strpos( $html, 'class="dos-cs-form rv-search"' ) && false !== strpos( $html, 'class="dos-cs-btn rv-btn"' ), $html );
check( 'a repeated extra class appears once in the whole markup', 1 === substr_count( $html, 'rv-btn' ) && 1 === substr_count( $html, 'rv-search' ), $html );

echo "\n--- inline data ---\n";
cs_site();
cs_cities( 200 );
$html = dos_city_search_markup();
check( '200 cities: the list rides along in data-cities', false !== strpos( $html, 'data-cities="' ) && 200 === count( json_decode( html_entity_decode( preg_replace( '/^.*data-cities="([^"]*)".*$/s', '$1', $html ) ), true ) ) );
check( '200 cities: data-api is still there', false !== strpos( $html, 'data-api=' ) );
cs_cities( 201 );
$html = dos_city_search_markup();
check( '201 cities: no inline list', false === strpos( $html, 'data-cities' ) );
check( '201 cities: data-api carries the load', false !== strpos( $html, 'data-api=' ) );

echo "\n--- the homepage does not load a big list to count it ---\n";
cs_site();
cs_cities( 500 );
dos_city_search_build_index();
dos_city_search_flush();
$html = dos_city_search_markup();
check( 'with a stored count over the limit: no inline list, and the index was not loaded', false === strpos( $html, 'data-cities' ) && ! isset( $GLOBALS['transients'][ DOS_CITY_SEARCH_TRANSIENT ] ) );
check( '… the REST address is still there', false !== strpos( $html, 'data-api=' ) );
cs_site();
dos_city_search_build_index();
dos_city_search_flush();
$html = dos_city_search_markup();
check( 'with a stored count under the limit: inline, loading the index', false !== strpos( $html, 'data-cities' ) && isset( $GLOBALS['transients'][ DOS_CITY_SEARCH_TRANSIENT ] ) );
cs_site();
cs_cities( 500 );
unset( $GLOBALS['options'][ DOS_CITY_SEARCH_META ] );
$html = dos_city_search_markup();
check( 'no count yet: loads once, then decides correctly', false === strpos( $html, 'data-cities' ) && isset( $GLOBALS['transients'][ DOS_CITY_SEARCH_TRANSIENT ] ) && 500 === $GLOBALS['options'][ DOS_CITY_SEARCH_META ]['count'] );

echo "\n--- a REVNM site after migration ---\n";
cs_revnm_site();
$html = dos_city_search_markup();
check( 'the section keeps rv-sec rv-city-search', false !== strpos( $html, 'class="dos-city-search rv-sec rv-city-search"' ), $html );
check( 'the form keeps rv-search', false !== strpos( $html, 'class="dos-cs-form rv-search"' ) );
check( 'the button keeps rv-btn', false !== strpos( $html, 'class="dos-cs-btn rv-btn"' ) );
cs_revnm_site();
$out = dos_city_search_filter_content( $hero . $rest );
check( 'it lands directly under the hero, as before', 0 === strpos( $out, $hero . "\n" . $box . ' rv-sec rv-city-search"' ), substr( $out, 0, 260 ) );
cs_revnm_site();
$plain = '<h1>Home</h1><p>No hero here.</p>';
check( 'and still shows nothing on a homepage without a hero', $plain === dos_city_search_filter_content( $plain ) );

echo "\n--- CSS ---\n";
cs_site();
$css = dos_city_search_css();
check( 'plain site: self-contained, including the button look', false !== strpos( $css, '.dos-cs-btn{margin:0;padding-left:20px' ) && false !== strpos( $css, '--dos-cs-accent' ) && false !== strpos( $css, '--dos-cs-bg:#eaf2fc' ) );
check( 'never styles .rv-* or .ohi-*, and has no .revnm-home scope', 1 !== preg_match( '/\.(rv|ohi)-|revnm-home/', $css ), $css );

cs_revnm_site();
$css = dos_city_search_css();
check( 'REVNM site: the button keeps its layout', false !== strpos( $css, '.dos-city-search .dos-cs-btn{width:auto;flex:0 0 auto;height:48px' ) );
check( 'REVNM site: the button look is left to the site (no colours on the button)', false === strpos( $css, 'background:var(--dos-cs-accent)' ) && false === strpos( $css, '.dos-cs-btn:hover' ) );
check( 'REVNM site: the box background still wins over a scoped site rule (three-class selector)', false !== strpos( $css, '.dos-city-search.dos-city-search.dos-city-search{background:var(--dos-cs-bg)' ) );
check( 'REVNM site: still never styles .rv-*', 1 !== preg_match( '/\.(rv|ohi)-|revnm-home/', $css ), $css );

cs_site( array( 'bg_color' => 'not-a-colour', 'accent_color' => '#123456' ) );
$css = dos_city_search_css();
check( 'a bad stored colour falls back to the default; a good one is used', false !== strpos( $css, '--dos-cs-bg:#eaf2fc' ) && false !== strpos( $css, '--dos-cs-accent:#123456' ) );

finish();
