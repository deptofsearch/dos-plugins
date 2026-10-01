<?php
/**
 * Market Images: the pieces that rewrite page content, which is where a quiet
 * regression would break a live page, and the shortcode wrappers.
 *
 * The 1.6.0 bug this pins: add_shortcode() handed the shortcode's attributes
 * (an empty string for a bare tag) to dos_mi_state_buttons( $counts ), whose
 * first parameter is the by-state page's city counts.
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['options']    = array();
$GLOBALS['shortcodes'] = array();
$GLOBALS['queried']    = null;
$GLOBALS['thumb']      = false;

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function add_filter() {} function add_action() {}
function add_shortcode( $tag, $cb ) { $GLOBALS['shortcodes'][ $tag ] = $cb; }
function plugin_basename( $f ) { return 'dos-market-images/dos-market-images.php'; }
function is_admin() { return false; }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $t ) ), '-' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return $s; }
function number_format_i18n( $n ) { return number_format( $n ); }
function get_queried_object() { return $GLOBALS['queried']; }
function has_post_thumbnail() { return $GLOBALS['thumb']; }
function wp_attachment_is_image( $id ) { return (bool) $id; }
function wp_get_attachment_image( $id, $size, $icon, $attr ) { return '<img class="' . $attr['class'] . '" alt="' . $attr['alt'] . '" loading="lazy">'; }
function get_page_by_path( $slug ) { return (object) array( 'post_status' => 'publish', 'slug' => $slug ); }
function get_post_status() { return 'publish'; }
function get_permalink( $p ) { return 'https://example.com/' . ( is_object( $p ) ? $p->slug : $p ) . '/'; }
function wp_register_style() {} function wp_enqueue_style() {} function wp_add_inline_style() {}
function wp_register_script() {} function wp_enqueue_script() {} function wp_add_inline_script() {}

require dirname( __DIR__ ) . '/plugins/dos-market-images/dos-market-images.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

echo "--- the updater is wired in ---\n";
check( 'the shared updater class is loaded', class_exists( 'DOS_GitHub_Updater' ) );
check( 'the header version and the constant agree', preg_match( '/^\s*\*\s*Version:\s*' . preg_quote( DOS_MI_VERSION, '/' ) . '\s*$/m', file_get_contents( dirname( __DIR__ ) . '/plugins/dos-market-images/dos-market-images.php' ) ) === 1, DOS_MI_VERSION );

echo "\n--- dos_mi_div_end ---\n";
$html = 'x<div class="a">one<div class="b">two</div>three</div>tail';
$at   = strpos( $html, '<div class="a"' );
check( 'a flat div ends at its own close', 'x<div>a</div>' === substr( 'x<div>a</div>tail', 0, dos_mi_div_end( 'x<div>a</div>tail', 1 ) ) );
check( 'nested divs are counted, not matched to the first close', 'x<div class="a">one<div class="b">two</div>three</div>' === substr( $html, 0, dos_mi_div_end( $html, $at ) ), substr( $html, 0, dos_mi_div_end( $html, $at ) ) );
check( 'an inner div can be found on its own', '<div class="b">two</div>' === substr( $html, strpos( $html, '<div class="b"' ), dos_mi_div_end( $html, strpos( $html, '<div class="b"' ) ) - strpos( $html, '<div class="b"' ) ) );
$open = 'x<div class="a">one<div class="b">two</div>tail';
check( 'unbalanced markup removes nothing', strpos( $open, '<div class="a"' ) === dos_mi_div_end( $open, strpos( $open, '<div class="a"' ) ) );
check( 'no div at all removes nothing', 3 === dos_mi_div_end( 'abcdef', 3 ) );
check( 'closing tags are matched case-insensitively', 12 === dos_mi_div_end( '<DIV>a</DIV>z', 0 ) );

echo "\n--- heading normalisation ---\n";
check( 'curly apostrophes become straight', "what determines a home's value?" === dos_mi_norm_heading( 'What Determines a Home’s Value?' ) );
check( 'an ampersand reads as "and"', 'housing and inventory' === dos_mi_norm_heading( 'Housing &amp; Inventory' ) && 'housing and inventory' === dos_mi_norm_heading( 'Housing & Inventory' ) );
check( 'tags and runs of whitespace are dropped', 'find home values near you' === dos_mi_norm_heading( "<span>Find  Home\nValues</span>   Near You " ) );
check( 'the curly and straight spellings meet', dos_mi_norm_heading( 'Home’s' ) === dos_mi_norm_heading( "Home's" ) );

echo "\n--- topics and buttons maps ---\n";
$GLOBALS['options'][ DOS_MI_OPTION ] = array(
	'topics_map'  => "Home’s Value | Schools, Amenities Lifestyle\n\nno pipe here\n | orphan\nEmpty | , ,\nNews & Notes | news\n",
	'buttons_map' => "Markets | See Every Market | /by-state/\nBad | only two\nAlso Bad | | /x/\nFAQ | Read News | /category/news/",
);
$topics = dos_mi_topics_map();
check( 'a heading maps to its category slugs, sanitised', array( 'schools', 'amenities-lifestyle' ) === ( $topics["home's value"] ?? null ), json_encode( $topics ) );
check( '  keys are normalised headings, so "&" lines up', array( 'news' ) === ( $topics['news and notes'] ?? null ) );
check( 'lines without a pipe, a heading or any category are skipped', 2 === count( $topics ), json_encode( $topics ) );
$buttons = dos_mi_buttons_map();
check( 'a button line gives text and link', array( 'See Every Market', '/by-state/' ) === ( $buttons['markets'] ?? null ), json_encode( $buttons ) );
check( 'lines that are not three filled parts are skipped', 2 === count( $buttons ) && isset( $buttons['faq'] ), json_encode( $buttons ) );

echo "\n--- dos_mi_state_hero ---\n";
$GLOBALS['options'][ DOS_MI_DATA ] = array(
	'states' => array( array( 'key' => 'alabama', 'label' => 'Alabama', 'slug' => 'alabama', 'attachment_id' => 7, 'hidden' => 0 ) ),
);
$GLOBALS['queried'] = (object) array( 'post_name' => 'alabama' );

$page = '<p>intro</p><div class="rev-st"><div class="rev-card"><h2>Cities</h2><div class="rev-search"><div class="inner"><input></div></div><table><tr><td>x</td></tr></table></div></div><p>after</p>';

// The theme already prints the featured image, so the hero is empty and only the filter moves.
$GLOBALS['thumb'] = true;
$out = dos_mi_state_hero( $page );
check( 'the filter moves to the top of rev-card', false !== strpos( $out, '<div class="rev-card"><div class="rev-search"><div class="inner"><input></div></div><h2>Cities</h2>' ), $out );
check( '  and is not left behind in the card', 1 === substr_count( $out, 'rev-search' ) );
check( '  keeping everything else in order', 0 === strpos( $out, '<p>intro</p><div class="rev-st">' ) && '<p>after</p>' === substr( $out, -12 ) && false !== strpos( $out, '<table>' ) );
check( '  with no second image when the theme prints one', false === strpos( $out, 'rv-mi-hero' ) );

$GLOBALS['thumb'] = false;
$out = dos_mi_state_hero( $page );
check( 'without a featured image the plugin adds its own, above the list', 0 === strpos( $out, '<p>intro</p><figure class="rv-mi-hero">' ) && false !== strpos( $out, 'loading="eager" fetchpriority="high"' ), $out );

$plain = '<p>no state list here</p><div class="rev-card"><div class="rev-search"></div></div>';
check( 'content without rev-st is left alone', $plain === dos_mi_state_hero( $plain ) );

$no_filter = '<div class="rev-st"><div class="rev-card"><h2>Cities</h2></div></div>';
check( 'a list with no filter just gets the image above it', 0 === strpos( dos_mi_state_hero( $no_filter ), '<figure class="rv-mi-hero">' ) );

$GLOBALS['queried'] = (object) array( 'post_name' => 'not-a-state' );
check( 'a page that is not a state is left alone', $page === dos_mi_state_hero( $page ) );

echo "\n--- the shortcodes ---\n";
$GLOBALS['queried'] = null;
check( 'both shortcodes are registered', isset( $GLOBALS['shortcodes']['revnm_market_carousel'], $GLOBALS['shortcodes']['revnm_state_buttons'] ) );

$cb       = $GLOBALS['shortcodes']['revnm_state_buttons'];
$expected = dos_mi_state_buttons();
check( 'the state buttons render something to compare against', false !== strpos( $expected, 'Alabama' ), $expected );
check( 'a bare tag, whose $atts is an empty string, gives the plain output', $expected === $cb( '', '', 'revnm_state_buttons' ) );
check( 'attributes are never mistaken for city counts', $expected === $cb( array( 'alabama' => '5' ), '', 'revnm_state_buttons' ) && false === strpos( $cb( array( 'alabama' => '5' ) ), '5 cities' ) );
check( 'counts still work when the by-state page passes them directly', false !== strpos( dos_mi_state_buttons( array( 'alabama' => 5 ) ), '5 cities' ) );

$GLOBALS['options'][ DOS_MI_DATA ]['cities'] = array();
check( 'the carousel wrapper returns the carousel, which is empty with no cities', '' === $GLOBALS['shortcodes']['revnm_market_carousel']( '' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
