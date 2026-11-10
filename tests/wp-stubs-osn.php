<?php
/**
 * Minimal WordPress stubs, enough to load plugins/dos-outdoor-seating's grid classes and run Cards::render(),
 * Cards::rest_part(), the Takeover resolver, Paging and the title / H1 code. $GLOBALS['osn'] is the "site":
 * osn_world() builds a city of N venues, a city page, a neighborhood page and the terms between them, and
 * osn_request() says which page the main query is on. osn_reset() starts every scenario from a fresh request.
 */

define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'OSN\\VERSION', '0.6.19' );
define( 'OSN\\PLUGIN_FILE', dirname( __DIR__ ) . '/plugins/dos-outdoor-seating/dos-outdoor-seating.php' );

$GLOBALS['osn']    = array();
$GLOBALS['filters'] = array();
$GLOBALS['actions'] = array();
$GLOBALS['pass']   = 0;
$GLOBALS['fail']   = 0;

// ---- classes -----------------------------------------------------------------------------------
class WP_Post {
	public $post_password = '';
	public $ID = 0;
	public $post_type = 'page';
	public $post_status = 'publish';
	public $post_content = '';
	public $post_parent = 0;
	public $post_name = '';
	public $post_title = '';
}
class WP_Term {
	public $term_id = 0;
	public $name = '';
	public $slug = '';
	public $parent = 0;
	public $taxonomy = '';
	public $count = 0;
}
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_REST_Request {
	private $params;
	public function __construct( array $params = array() ) { $this->params = $params; }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function offsetGet( $k ) { return $this->get_param( $k ); }
}
class WP_REST_Response {
	public $data;
	public $headers = array();
	public function __construct( $data = null ) { $this->data = $data; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}
class WP_Query {
	public $posts = array();
	public $is_404 = false;
	public function __construct( $args = array() ) { $GLOBALS['osn']['wpq'] = ( $GLOBALS['osn']['wpq'] ?? 0 ) + 1; $this->posts = $GLOBALS['osn']['query_posts'] ?? array(); }
	public function set_404() { $this->is_404 = true; }
}

// ---- hooks, options, transients ------------------------------------------------------------------
function __return_false() { return false; }
function add_filter( $t, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][ $t ][ $p ][] = $cb; return true; }
function add_action( $t, $cb = null, $p = 10, $n = 1 ) { $GLOBALS['actions'][ $t ][ $p ][] = $cb; return true; }
// What a shortcode prints: $GLOBALS['osn']['shortcodes'] maps the exact shortcode text to its output ('' when not set).
if ( ! function_exists( 'doing_filter' ) ) {
	function doing_filter( $hook = null ) { return in_array( $hook, (array) ( $GLOBALS['wp_current_filter'] ?? array() ), true ); }
}
if ( ! function_exists( 'shortcode_exists' ) ) {
	function shortcode_exists( $tag ) { return empty( $GLOBALS['osn']['no_shortcodes'] ); }
}
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( $text ) { $GLOBALS['osn']['calls']['do_shortcode'][] = $text; return $GLOBALS['osn']['shortcodes'][ $text ] ?? ''; }
}
function apply_filters( $t, $v, ...$rest ) {
	$by = $GLOBALS['filters'][ $t ] ?? array();
	ksort( $by );
	foreach ( $by as $cbs ) { foreach ( $cbs as $cb ) { $v = call_user_func( $cb, $v, ...$rest ); } }
	return $v;
}
function osn_run_filter( $t, ...$args ) { return apply_filters( $t, ...$args ); }
function get_option( $k, $d = false ) { return $GLOBALS['osn']['options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['osn']['options'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['osn']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['osn']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['osn']['transients'][ $k ] ); return true; }
// Cron and the object cache: $GLOBALS['osn']['cron'] is { "hook|json args" => timestamp }, ['locks'] the keys added.
function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['osn']['cron'][ $hook . '|' . json_encode( $args ) ] ?? false; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { if ( ! empty( $GLOBALS['osn']['cron_fails'] ) ) { return false; } $GLOBALS['osn']['cron'][ $hook . '|' . json_encode( $args ) ] = (int) $ts; return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { unset( $GLOBALS['osn']['cron'][ $hook . '|' . json_encode( $args ) ] ); return 1; }
function wp_cache_add( $k, $v, $g = '', $t = 0 ) { if ( isset( $GLOBALS['osn']['locks'][ "$g:$k" ] ) ) { return false; } $GLOBALS['osn']['locks'][ "$g:$k" ] = $v; return true; }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['osn']['routes'][ $ns . $route ] = $args; }
function rest_ensure_response( $d ) { return $d instanceof WP_REST_Response ? $d : new WP_REST_Response( $d ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function did_action() { return 1; }
function is_admin() { return false; }

// ---- escaping, i18n, small helpers ---------------------------------------------------------------
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $s, $d = '' ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $s, $d = '' ) { echo htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $s, $d = '' ) { echo htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return str_replace( '&', '&#038;', (string) $s ); } // Core does the same to ampersands.
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function shortcode_atts( $d, $a, $t = '' ) { return array_merge( $d, array_intersect_key( (array) $a, $d ) ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_hex_color( $c ) { return ''; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function rest_url( $p = '' ) { return 'https://example.com/wp-json/' . $p; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function plugins_url( $p = '', $f = '' ) { return 'https://example.com/wp-content/plugins/dos-outdoor-seating/' . $p; }
function wp_enqueue_style() {} function wp_add_inline_style() {} function wp_enqueue_script() {}
function status_header( $c ) { $GLOBALS['osn']['status'] = $c; }
function nocache_headers() { $GLOBALS['osn']['nocache'] = true; }

// ---- the site: posts, terms, meta, caches --------------------------------------------------------
function get_post( $id = null ) {
	if ( empty( $id ) ) { return $GLOBALS['osn']['current'] ?? null; } // Core: no or zero id means the global post.
	return $GLOBALS['osn']['posts'][ (int) $id ] ?? null;
}
function get_post_status( $id ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_post_field( $f, $id ) { $p = get_post( $id ); return $p ? ( $p->$f ?? '' ) : ''; }
function get_the_title( $id = 0 ) { return $GLOBALS['osn']['titles'][ $id ] ?? 'Venue ' . $id; }
function get_permalink( $id = 0 ) {
	$p = is_object( $id ) ? $id : get_post( $id );
	if ( $p && 'page' === $p->post_type ) { return 'https://example.com/' . ( $p->post_parent ? 'testville-tx/' : '' ) . $p->post_name . '/'; }
	return 'https://example.com/restaurants/v' . ( is_object( $id ) ? $id->ID : $id ) . '/';
}
function get_post_meta( $id, $k = '', $single = false ) {
	$v = $GLOBALS['osn']['meta'][ $id ][ $k ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function update_post_meta( $id, $k, $v ) { $GLOBALS['osn']['meta'][ $id ][ $k ] = $v; $GLOBALS['osn']['calls']['meta_write'][] = array( $id, $k, $v ); return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['osn']['meta'][ $id ][ $k ] ); $GLOBALS['osn']['calls']['meta_write'][] = array( $id, $k, null ); return true; }
function get_term_meta( $id, $k = '', $single = false ) { return $GLOBALS['osn']['term_meta'][ $id ][ $k ] ?? ''; }
function taxonomy_exists( $t ) { return in_array( $t, $GLOBALS['osn']['taxonomies'] ?? array(), true ); }
function get_term( $id, $tax = '' ) { return $GLOBALS['osn']['terms'][ (int) $id ] ?? null; }
function get_term_by( $field, $value, $tax ) {
	foreach ( $GLOBALS['osn']['terms'] as $t ) {
		if ( $t->taxonomy === $tax && ( 'slug' === $field ? $t->slug : $t->name ) === $value ) { return $t; }
	}
	return false;
}
function get_terms( $args ) {
	$out = array();
	foreach ( $GLOBALS['osn']['terms'] as $t ) {
		if ( $t->taxonomy !== $args['taxonomy'] ) { continue; }
		$ok = true;
		foreach ( $args['meta_query'] ?? array() as $mq ) {
			if ( 'EXISTS' === ( $mq['compare'] ?? '' ) ) {
				$ok = $ok && isset( $GLOBALS['osn']['term_meta'][ $t->term_id ][ $mq['key'] ] );
				continue;
			}
			$ok = $ok && (string) ( $GLOBALS['osn']['term_meta'][ $t->term_id ][ $mq['key'] ] ?? '' ) === (string) $mq['value'];
		}
		if ( $ok ) { $out[] = $t; }
	}
	return ! empty( $args['number'] ) ? array_slice( $out, 0, $args['number'] ) : $out;
}
function get_the_terms( $id, $tax ) { return $GLOBALS['osn']['obj_terms'][ $id ][ $tax ] ?? false; }
function update_object_term_cache( $ids, $type ) { $GLOBALS['osn']['calls']['term_cache'][] = count( (array) $ids ); }
function update_meta_cache( $type, $ids ) { $GLOBALS['osn']['calls']['meta_cache'][] = count( (array) $ids ); }
function _prime_post_caches( $ids, $terms = true, $meta = true ) { $GLOBALS['osn']['calls']['post_cache'][] = array( count( (array) $ids ), (bool) $meta ); }
function wp_get_attachment_image() { return ''; }

// ---- added for Empty_Cities, Source_Note, State_Cities and Home (guarded: tests may define their own) ------
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $t = null, $tz = null ) { return gmdate( $f, $t ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'https://example.com' . ( '' === $p ? '' : '/' . ltrim( $p, '/' ) ); } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'user_trailingslashit' ) ) { function user_trailingslashit( $s ) { return trailingslashit( $s ); } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); } }
if ( ! function_exists( 'remove_accents' ) ) { function remove_accents( $s ) { return $s; } }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $cb ) { return true; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $path ) {
		foreach ( $GLOBALS['osn']['posts'] ?? array() as $p ) {
			if ( 'page' === $p->post_type && $p->post_name === $path && ! $p->post_parent ) { return $p; }
		}
		return null;
	}
}
/** $wpdb: prepare() fills %s / %d; get_var() and get_results() return $GLOBALS['osn']['db_var'] / ['db_rows']. */
class OSN_Stub_DB {
	public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy'; public $term_relationships = 'wp_term_relationships'; public $last_error = '';
	public function prepare( $q, ...$args ) { $GLOBALS['osn']['calls']['db'][] = $q; return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $q ), $args ); }
	public function get_var( $q ) { return $GLOBALS['osn']['db_var'] ?? null; }
	public function get_results( $q ) { return $GLOBALS['osn']['db_rows'] ?? array(); }
}
$GLOBALS['wpdb'] = new OSN_Stub_DB();

// ---- the main query ------------------------------------------------------------------------------
function is_page() { return ! empty( $GLOBALS['osn']['is_page'] ); }
function get_queried_object() { return $GLOBALS['osn']['queried'] ?? null; }
function get_queried_object_id() { $p = get_queried_object(); return $p ? $p->ID : 0; }
function get_query_var( $v ) { return 'paged' === $v ? ( $GLOBALS['osn']['paged'] ?? 0 ) : ''; }
function in_the_loop() { return empty( $GLOBALS['osn']['not_loop'] ); }
function is_main_query() { return empty( $GLOBALS['osn']['not_main'] ); }
if ( ! function_exists( 'is_singular' ) ) { function is_singular( $t = '' ) { return is_page() && ( '' === $t || 'page' === $t ); } }
function is_front_page() { return false; }

// ---- test helpers --------------------------------------------------------------------------------
function check( $label, $cond, $detail = '' ) {
	if ( $cond ) { $GLOBALS['pass']++; echo "  PASS  $label\n"; }
	else { $GLOBALS['fail']++; echo "  FAIL  $label  $detail\n"; }
}
function finish() {
	echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
	exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
}

/** The plugin classes under test, in the order the plugin file loads them. */
function osn_load() {
	foreach ( array( 'fields', 'data-model', 'icons', 'states', 'util', 'assets', 'city-index', 'empty-cities', 'source-note', 'city-search', 'state-cities', 'home', 'hoods', 'landmarks', 'paging', 'cards', 'venue-image', 'rest', 'takeover', 'page-title', 'city-intro', 'seo' ) as $f ) {
		require_once dirname( __DIR__ ) . '/plugins/dos-outdoor-seating/includes/class-' . $f . '.php';
	}
}

function osn_post( $id, $slug, $content, $parent = 0 ) {
	$p = new WP_Post();
	$p->ID = $id; $p->post_name = $slug; $p->post_content = $content; $p->post_parent = $parent;
	$GLOBALS['osn']['posts'][ $id ] = $p;
	return $p;
}
function osn_term( $id, $name, $slug, $tax, $parent = 0, $meta = array() ) {
	$t = new WP_Term();
	$t->term_id = $id; $t->name = $name; $t->slug = $slug; $t->taxonomy = $tax; $t->parent = $parent;
	$GLOBALS['osn']['terms'][ $id ]     = $t;
	$GLOBALS['osn']['term_meta'][ $id ] = $meta;
	return $t;
}

/** A fresh request on a fresh site with no venues. */
function osn_reset() {
	$GLOBALS['osn']    = array( 'options' => array(), 'transients' => array(), 'posts' => array(), 'terms' => array(), 'term_meta' => array(), 'obj_terms' => array(), 'meta' => array(), 'routes' => array(), 'calls' => array(), 'taxonomies' => array( 'osn_hood' ), 'query_posts' => array() );
	$GLOBALS['filters'] = array();
	$GLOBALS['actions'] = array();
	$GLOBALS['wp_query'] = new WP_Query();
	\OSN\Hoods::flush_memo();
	\OSN\Landmarks::flush_memo();
	\OSN\Takeover::flush_memo();
	\OSN\Cards::flush_memo();
	\OSN\Paging::flush_memo();
	\OSN\Empty_Cities::flush_memo();
	\OSN\Empty_Cities::$closed = null;
	\OSN\Source_Note::flush_memo();
}

/**
 * "Testville, TX" with $n venues (ids 1001+), taking over every city. Page 500 (/testville-tx/) is the city grid;
 * page 600 (/testville-tx/old-town/) is a neighborhood page whose hood holds every fourth venue. Amenities: every
 * venue has outdoor-seating, every 3rd dog-friendly, every 5th brunch.
 */
function osn_world( $n ) {
	osn_reset();
	$o = &$GLOBALS['osn'];
	$o['options']['osn_takeover_cities'] = '*';
	$city = osn_term( 10, 'Testville, TX', 'testville-tx', 'osn_city' );
	$dist = osn_term( 20, 'Downtown', 'testville-tx-downtown', 'osn_hood', 0, array( 'osn_city' => 'Testville, TX' ) );
	$hood = osn_term( 21, 'Old Town', 'testville-tx-old-town', 'osn_hood', 20, array( 'osn_city' => 'Testville, TX', 'osn_page_id' => 600 ) );
	osn_post( 500, 'testville-tx', '[table id=1 filter="Testville, TX"]' );
	osn_post( 600, 'old-town', '[table id=2 filter="Old Town, Testville"]', 500 );
	$amen = array(
		'outdoor-seating' => new WP_Term(), 'dog-friendly' => new WP_Term(), 'brunch' => new WP_Term(),
	);
	$i = 100;
	foreach ( $amen as $slug => $t ) {
		$t->term_id = ++$i; $t->slug = $slug; $t->name = ucwords( str_replace( '-', ' ', $slug ) ); $t->taxonomy = 'osn_amenity';
	}
	$ids = array();
	for ( $k = 1; $k <= $n; $k++ ) {
		$id    = 1000 + $k;
		$ids[] = $id;
		$terms = array( $amen['outdoor-seating'] );
		if ( 0 === $k % 3 ) { $terms[] = $amen['dog-friendly']; }
		if ( 0 === $k % 5 ) { $terms[] = $amen['brunch']; }
		$o['obj_terms'][ $id ]['osn_amenity'] = $terms;
		if ( 0 === $k % 4 ) { $o['obj_terms'][ $id ]['osn_hood'] = array( $hood ); }
		$o['meta'][ $id ]['osn_street'] = $k . ' Main St';
	}
	$o['transients']['osn_sorted2_10'] = $ids;
	$o['query_posts']                   = array( 1001 );
	return $ids;
}

/** Point the main query at page $id, page number $paged (0 = no page in the URL). */
function osn_request( $id, $paged = 0 ) {
	$p = $GLOBALS['osn']['posts'][ $id ];
	$GLOBALS['osn']['current'] = $p;
	$GLOBALS['osn']['queried'] = $p;
	$GLOBALS['osn']['is_page'] = true;
	$GLOBALS['osn']['paged']   = $paged;
	$GLOBALS['osn']['status']  = null;
	$GLOBALS['osn']['nocache'] = false;
	$GLOBALS['wp_query']       = new WP_Query();
	$GLOBALS['osn']['calls']   = array();
}
