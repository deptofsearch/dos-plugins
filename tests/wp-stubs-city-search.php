<?php
/**
 * Minimal WordPress stubs, enough to load plugins/dos-city-search and run its index, markup,
 * insertion, REST and migration code. $GLOBALS['cs'] holds what the "site" looks like; cs_reset()
 * puts everything back so each scenario starts as a fresh request on a fresh site.
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['writes']     = array();   // every update_option() call: the option name
$GLOBALS['filters']    = array();
$GLOBALS['actions']    = array();
$GLOBALS['shortcodes'] = array();
$GLOBALS['routes']     = array();   // "namespace/route" => args
$GLOBALS['cs']         = array();

class WP_REST_Response {
	public $data;
	public $headers = array();
	public function __construct( $data = null ) { $this->data = $data; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}

function cs_reset() {
	$GLOBALS['options']    = array();
	$GLOBALS['transients'] = array();
	$GLOBALS['writes']     = array();
	$GLOBALS['filters']    = array();
	$GLOBALS['actions']    = array();
	$GLOBALS['shortcodes'] = array();
	$GLOBALS['routes']     = array();
	$GLOBALS['wpdb']->rows = array();
	$GLOBALS['cs']         = array(
		'front'      => true,
		'loop'       => true,
		'main'       => true,
		'taxonomies' => array(),   // registered taxonomy names
		'page_ids'   => array(),   // ids get_posts() returns
		'terms'      => array(),   // post id => list of term objects
		'slugs'      => array(),   // post id => post_name
		'paths'      => array(),   // get_page_by_path() slug => object
		'can'        => true,
		'active'     => array(),   // active plugin basenames
		'deactivated' => array(),
		'styles'     => array(),
	);
	dos_city_search_inserted( false );
}

// ---- options, transients, hooks --------------------------------------------------------------
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['writes'][] = $k; $GLOBALS['options'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; $GLOBALS['ttls'][ $k ] = $t; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function add_filter( $t, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][ $t ][] = $cb; return true; }
function add_action( $t, $cb = null, $p = 10, $n = 1 ) { $GLOBALS['actions'][ $t ][ $p ][] = $cb; return true; }
function apply_filters( $t, $v ) {
	foreach ( $GLOBALS['filters'][ $t ] ?? array() as $cb ) { $v = call_user_func( $cb, $v ); }
	return $v;
}
function do_action_stub( $t, ...$args ) {
	$by = $GLOBALS['actions'][ $t ] ?? array();
	ksort( $by );
	foreach ( $by as $cbs ) { foreach ( $cbs as $cb ) { call_user_func_array( $cb, $args ); } }
}
function add_shortcode( $tag, $cb ) { $GLOBALS['shortcodes'][ $tag ] = $cb; }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ $ns . $route ] = $args; }
function plugin_basename( $f ) { return 'dos-city-search/dos-city-search.php'; }
function is_admin() { return false; }
function add_management_page() {}
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function wp_register_style( $h ) { $GLOBALS['cs']['styles'][] = $h; }
function wp_enqueue_style() {} function wp_add_inline_style() {}
function wp_register_script() {} function wp_enqueue_script() {} function wp_add_inline_script() {}

// ---- escaping and sanitising -----------------------------------------------------------------
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function is_wp_error( $t ) { return false; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', (string) $c ) ? $c : ''; }
function absint( $v ) { return abs( (int) $v ); }
function home_url( $p = '/' ) { return 'https://example.com' . $p; }
function rest_url( $p = '' ) { return 'https://example.com/wp-json/' . $p; }

// ---- the site: request state, pages, taxonomy ------------------------------------------------
function is_front_page() { return $GLOBALS['cs']['front']; }
function in_the_loop() { return $GLOBALS['cs']['loop']; }
function is_main_query() { return $GLOBALS['cs']['main']; }
function current_user_can() { return $GLOBALS['cs']['can']; }
function taxonomy_exists( $t ) { return in_array( $t, $GLOBALS['cs']['taxonomies'], true ); }
function get_posts() { return $GLOBALS['cs']['page_ids']; }
function wp_get_object_terms( $id, $tax ) { return $GLOBALS['cs']['terms'][ $id ] ?? array(); }
function get_post_type( $id ) { return 'page'; }
function get_post_field( $f, $id ) { return $GLOBALS['cs']['slugs'][ $id ] ?? ''; }
function get_permalink( $p ) {
	$id = is_object( $p ) ? $p->ID : $p;
	return 'https://example.com/' . ( $GLOBALS['cs']['slugs'][ $id ] ?? 'p' . $id ) . '/';
}
function get_page_by_path( $slug ) { return $GLOBALS['cs']['paths'][ $slug ] ?? null; }
function is_plugin_active( $p ) { return in_array( $p, $GLOBALS['cs']['active'], true ); }
function deactivate_plugins( $p ) { $GLOBALS['cs']['deactivated'][] = $p; $GLOBALS['cs']['active'] = array_diff( $GLOBALS['cs']['active'], array( $p ) ); }

// The slug source's one SQL query: tests hand it the rows the database would have matched.
$GLOBALS['wpdb'] = new class() {
	public $posts = 'wp_posts';
	public $rows  = array();
	public function get_results( $sql ) { return $this->rows; }
};

function cs_page( $slug, $title ) { return (object) array( 'post_name' => $slug, 'post_title' => $title ); }
function cs_term( $id, $name ) { return (object) array( 'term_id' => $id, 'name' => $name ); }

// ---- test helpers ----------------------------------------------------------------------------
$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check( $label, $cond, $detail = '' ) {
	if ( $cond ) { $GLOBALS['pass']++; echo "  PASS  $label\n"; }
	else { $GLOBALS['fail']++; echo "  FAIL  $label  $detail\n"; }
}

function finish() {
	echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
	exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
}

/** One dos-cs-N id is handed out per markup call; strip it so two renders can be compared. */
function cs_norm( $html ) { return preg_replace( '/dos-cs-\d+/', 'dos-cs-N', $html ); }

/** A site with this many slug_state city pages, in a fresh request. */
function cs_cities( $n ) {
	$rows = array();
	for ( $i = 1; $i <= $n; $i++ ) { $rows[] = cs_page( 'city' . $i . '-tx', 'City' . $i . ' TX – Home Values' ); }
	$GLOBALS['wpdb']->rows = $rows;
	dos_city_search_flush();
}
