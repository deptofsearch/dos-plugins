<?php
/**
 * DoS SAAB Toolkit: the Best in Tri-Cities hub. Which pages get a tile (slug best-…-tri-cities or a Best-Of
 * config, never the hub itself, hidden ones left off), what each tile says and shows, and that the page renders an
 * H1 and one link per tile. The Builder hub it replaces linked 2 of its 20 tiles.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Post {
	public $ID; public $post_name; public $post_title; public $post_content = '';
	function __construct( $id, $name, $title ) { $this->ID = $id; $this->post_name = $name; $this->post_title = $title; }
}

$GLOBALS['pages'] = array(
	new WP_Post( 10, 'best-in-tri-cities', 'Best in Tri-Cities' ),
	new WP_Post( 11, 'best-biscuits-gravy-in-tri-cities', 'Best Biscuits & Gravy in Tri-Cities' ),
	new WP_Post( 12, 'best-tacos-in-the-tri-cities', 'Best Tacos in the Tri-Cities' ),
	new WP_Post( 13, 'date-night', 'Best Date Night Spot in Tri-Cities' ),   // no matching slug, but a Best-Of config
	new WP_Post( 14, 'contact', 'Contact' ),
	new WP_Post( 15, 'best-soup-in-tri-cities', 'Best Soup in Tri-Cities' ),  // hidden on the hub
);
$GLOBALS['meta'] = array(
	10 => array( 'saab_bestof_hub' => '{"on":true,"intro":"First paragraph.\n\nSecond <b>one</b>.","hide":[15]}' ),
	11 => array( 'saab_bestof' => '{"dish":"Biscuits & Gravy","eyebrow":"Breakfast","hero_id":900,"picks":[{"name":"Swampy\'s"}]}' ),
	13 => array( 'saab_bestof' => '{"picks":[{"name":"Somewhere"}]}' ),
);
$GLOBALS['thumbs']  = array( 11 => 901, 12 => 902 );
$GLOBALS['images']  = array( 900, 902 );
$GLOBALS['queried'] = 10;

function add_action() {}
function add_filter() {}
function absint( $v ) { return abs( (int) $v ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function get_posts( $args ) { return $GLOBALS['pages']; }
function get_page_by_path( $path ) { foreach ( $GLOBALS['pages'] as $p ) { if ( $p->post_name === $path ) { return $p; } } return null; }
function get_post_thumbnail_id( $id ) { return $GLOBALS['thumbs'][ is_object( $id ) ? $id->ID : $id ] ?? 0; }
function wp_attachment_is_image( $id ) { return in_array( $id, $GLOBALS['images'], true ); }
function get_permalink( $p ) { return 'https://shotandabeer.com/' . $p->post_name . '/'; }
function get_queried_object_id() { return $GLOBALS['queried']; }
function get_the_title( $id ) { foreach ( $GLOBALS['pages'] as $p ) { if ( $p->ID === $id ) { return $p->post_title; } } return ''; }
function wp_get_attachment_image( $id, $size, $icon, $attr ) { return '<img data-id="' . $id . '" data-size="' . $size . '" loading="' . $attr['loading'] . '" alt="">'; }

class SAAB_Restaurants { const POST_TYPE = 'saab_restaurant'; }

require dirname( __DIR__ ) . '/plugins/dos-saab-toolkit/includes/class-saab-bestof.php';
require dirname( __DIR__ ) . '/plugins/dos-saab-toolkit/includes/class-saab-bestof-hub.php';

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

echo "--- labels ---\n";
$labels = array(
	'Best Biscuits & Gravy in Tri-Cities'     => 'Biscuits & Gravy',
	'Best Tacos in the Tri-Cities'            => 'Tacos',
	'The Best Fish & Chips in Tri Cities, WA' => 'Fish & Chips',
	'Best in Tri-Cities'                      => 'Best in Tri-Cities',
);
foreach ( $labels as $in => $want ) {
	$got = SAAB_Bestof_Hub::tile_label( $in );
	check( "\"$in\" → \"$want\"", $want === $got, $got );
}

echo "--- which pages ---\n";
check( 'the hub is the page at /best-in-tri-cities/', SAAB_Bestof_Hub::is_hub_page( 10 ) && ! SAAB_Bestof_Hub::is_hub_page( 11 ) );
$ids = array_map( function ( $p ) { return $p->ID; }, SAAB_Bestof_Hub::candidate_pages( 10 ) );
check( 'candidates: best-…-tri-cities slugs and Best-Of pages, not the hub or Contact', array( 11, 12, 13, 15 ) === $ids, json_encode( $ids ) );

$config = SAAB_Bestof_Hub::get_config( 10 );
check( 'config: on, tags stripped from the intro, soup hidden', $config['on'] && "First paragraph.\n\nSecond one." === $config['intro'] && array( 15 ) === $config['hide'], json_encode( $config ) );

$tiles = SAAB_Bestof_Hub::tiles( 10, $config );
check( 'three tiles: the hidden page is left off', 3 === count( $tiles ), json_encode( array_column( $tiles, 'label' ) ) );
check( 'a Best-Of page leads with its hero, not its featured image', 900 === $tiles[0]['image_id'] );
check( 'a Builder page uses its featured image', 902 === $tiles[1]['image_id'] );
check( 'no image on file: a plain tile', 0 === $tiles[2]['image_id'] );
check( 'every tile is linked', 3 === count( array_filter( array_column( $tiles, 'url' ) ) ) );
check( 'the Best-Of eyebrow rides along', 'Breakfast' === $tiles[0]['eyebrow'] );

echo "--- page ---\n";
$html = SAAB_Bestof_Hub::render();
check( 'one H1, the page title', 1 === substr_count( $html, '<h1' ) && false !== strpos( $html, '>Best in Tri-Cities</h1>' ) );
check( 'two intro paragraphs', false !== strpos( $html, '>First paragraph.</p>' ) && false !== strpos( $html, '>Second one.</p>' ) );
check( 'one link per tile', 3 === substr_count( $html, 'class="saab-hub__tile' ) );
check( 'tile images at medium_large, not full size', 2 === substr_count( $html, 'data-size="medium_large"' ) );
check( 'Soup is not on the page', false === strpos( $html, 'best-soup' ) );

$GLOBALS['meta'][10]['saab_bestof_hub'] = '{"on":false}';
check( 'switched off: config says off', ! SAAB_Bestof_Hub::get_config( 10 )['on'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
