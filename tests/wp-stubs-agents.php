<?php
/**
 * Minimal WordPress stubs, enough to load plugins/dos-agents and run its sanitising, storage,
 * REST callbacks and rendering. $GLOBALS['wp'] holds the "site"; agents_reset() puts it back so
 * each scenario starts as a fresh request. Also used by plugins/dos-agents/tests/render-preview.php.
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
}

class WP_REST_Response {
	public $data;
	public $headers = array();
	public function __construct( $data = null ) { $this->data = $data; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}

/** A REST request: a JSON body and/or URL params. */
class WP_REST_Request implements ArrayAccess {
	public $json;
	public $params;
	public function __construct( $json = null, $params = array() ) { $this->json = $json; $this->params = $params; }
	public function get_json_params() { return $this->json; }
	public function get_params() { return $this->params; }
	public function offsetExists( $k ): bool { return isset( $this->params[ $k ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $k ) { return $this->params[ $k ] ?? null; }
	#[\ReturnTypeWillChange]
	public function offsetSet( $k, $v ) { $this->params[ $k ] = $v; }
	#[\ReturnTypeWillChange]
	public function offsetUnset( $k ) { unset( $this->params[ $k ] ); }
}

$GLOBALS['options']    = array();
$GLOBALS['autoload']   = array();   // option name => the autoload argument it was last written with
$GLOBALS['transients'] = array();
$GLOBALS['filters']    = array();
$GLOBALS['actions']    = array();
$GLOBALS['shortcodes'] = array();
$GLOBALS['routes']     = array();   // "namespace/route" => args
$GLOBALS['wp']         = array();

function agents_reset() {
	$GLOBALS['options']    = array();
	$GLOBALS['autoload']   = array();
	$GLOBALS['transients'] = array();
	$GLOBALS['filters']    = array();
	$GLOBALS['wp']         = array(
		'can'     => true,
		'tz'      => 'America/Los_Angeles',   // the site's timezone, for wp_date()
		'singular' => false,
		'post'    => null,                    // object with post_content, or null
		'style_calls' => 0,
		'styles'  => array(),   // handle => array( src, ver )
		'scripts' => array(),
		'pages'   => array(),   // page slug => WP_Post, the "site" the index reads
		'page'    => false,     // is_page()
		'queried' => 0,         // get_queried_object_id()
		'search'  => false,     // is_search()
		'query'   => array(),   // get_query_var()
		'redirect' => null,     // where wp_safe_redirect() was sent
		'site_icon' => false,
		'did_init'  => true,    // did_action('init'); the layout guard reads it
		'in_loop'   => true,    // in_the_loop() + is_main_query(), for the_content filters
		'cache_deletes' => array(),
	);
}
agents_reset();

// ---- options, transients, hooks --------------------------------------------------------------
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; $GLOBALS['autoload'][ $k ] = $a; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function add_filter( $t, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][ $t ][] = $cb; return true; }
function add_action( $t, $cb = null, $p = 10, $n = 1 ) { $GLOBALS['actions'][ $t ][ $p ][] = $cb; return true; }
function apply_filters( $t, $v ) {
	foreach ( $GLOBALS['filters'][ $t ] ?? array() as $cb ) { $v = call_user_func( $cb, $v ); }
	return $v;
}
function add_shortcode( $tag, $cb ) { $GLOBALS['shortcodes'][ $tag ] = $cb; }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) { return array_merge( $pairs, array_intersect_key( (array) $atts, $pairs ) ); }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ $ns . $route ] = $args; }
function rest_ensure_response( $d ) { return $d instanceof WP_REST_Response ? $d : new WP_REST_Response( $d ); }
function plugin_basename( $f ) { return 'dos-agents/dos-agents.php'; }
function plugins_url( $path = '', $plugin = '' ) { return 'https://example.com/wp-content/plugins/dos-agents/' . ltrim( $path, '/' ); }
function is_admin() { return false; }
function current_user_can( $cap ) { return $GLOBALS['wp']['can']; }
function wp_enqueue_style( $h, $src = '', $deps = array(), $ver = false ) { $GLOBALS['wp']['style_calls']++; $GLOBALS['wp']['styles'][ $h ] = array( $src, $ver ); }
function wp_style_is( $h, $list = 'enqueued' ) { return isset( $GLOBALS['wp']['styles'][ $h ] ); }
function is_singular() { return $GLOBALS['wp']['singular']; }
/** Like core's: no argument gives the current post, an ID is looked up, an object passes through. */
function get_post( $p = null ) {
	if ( null === $p ) { return $GLOBALS['wp']['post']; }
	if ( is_object( $p ) ) { return $p; }
	foreach ( $GLOBALS['wp']['pages'] as $page ) {
		if ( (int) $page->ID === (int) $p ) { return $page; }
	}
	return null;
}
function is_page() { return $GLOBALS['wp']['page']; }
function get_queried_object_id() { return $GLOBALS['wp']['queried']; }
function is_search() { return $GLOBALS['wp']['search']; }
function get_query_var( $k, $d = '' ) { return $GLOBALS['wp']['query'][ $k ] ?? $d; }
/** The code calls exit right after redirecting, which would end the test run; throw instead. */
class RedirectHalt extends Exception {
	public $url;
	public $status;
	public function __construct( $url, $status ) { $this->url = $url; $this->status = $status; parent::__construct( 'redirect' ); }
}
function wp_safe_redirect( $url, $status = 302 ) { $GLOBALS['wp']['redirect'] = array( $url, $status ); throw new RedirectHalt( $url, $status ); }
function is_feed() { return false; } function is_embed() { return false; } function wp_doing_ajax() { return false; }
function has_site_icon() { return $GLOBALS['wp']['site_icon']; }
function wp_is_post_revision( $id ) { return false; } function wp_is_post_autosave( $id ) { return false; }
function in_the_loop() { return $GLOBALS['wp']['in_loop']; }
function is_main_query() { return $GLOBALS['wp']['in_loop']; }
function get_the_ID() { return $GLOBALS['wp']['queried']; }
function did_action( $hook ) { return ( 'init' === $hook && $GLOBALS['wp']['did_init'] ) ? 1 : 0; }
function wp_cache_delete( $key, $group = '' ) { $GLOBALS['wp']['cache_deletes'][] = $key; return true; }
function __return_true() { return true; }
function __return_false() { return false; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.com/wp-content/plugins/dos-agents/'; }
function home_url( $p = '/' ) { return 'https://example.com' . $p; }
function rest_url( $p = '' ) { return 'https://example.com/wp-json/' . ltrim( $p, '/' ); }
function add_query_arg( $k, $v, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $k ) . '=' . rawurlencode( $v ); }
// Enough of core's for the city names the index holds; the real one folds the whole Latin-1 range.
function remove_accents( $s ) { return strtr( (string) $s, array( 'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','í'=>'i','ñ'=>'n','ó'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c' ) ); }

if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

class WP_Post {
	public $ID = 0;
	public $post_name = '';
	public $post_type = 'page';
	public $post_status = 'publish';
	public $post_content = '';
	public function __construct( $fields = array() ) { foreach ( $fields as $k => $v ) { $this->$k = $v; } }
}

/** Adds a page to the stub site. Returns it. */
function agents_add_page( $slug, $content = '', $status = 'publish', $id = 0 ) {
	static $next = 100;
	$post = new WP_Post(
		array(
			'ID'           => $id ?: ++$next,
			'post_name'    => $slug,
			'post_type'    => 'page',
			'post_status'  => $status,
			'post_content' => $content,
		)
	);
	$GLOBALS['wp']['pages'][ $slug ] = $post;
	return $post;
}

function get_page_by_path( $path, $output = OBJECT, $type = 'page' ) {
	return $GLOBALS['wp']['pages'][ $path ] ?? null;
}
function get_permalink( $post = null ) {
	$slug = is_object( $post ) ? $post->post_name : (string) $post;
	return 'https://example.com/' . $slug . '/';
}
function get_post_type( $p = null ) { return is_object( $p ) ? $p->post_type : 'page'; }

/** Enough of $wpdb for the one options scan the index rebuild does. */
class Agents_WPDB {
	public $options = 'wp_options';
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) { $sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 ); }
		return $sql;
	}
	/** Reads the stub option store rather than a database; only the LIKE prefix matters. */
	public function get_col( $sql ) {
		if ( ! preg_match( "/LIKE '([^']*)%'/", $sql, $m ) ) { return array(); }
		$like = stripslashes( $m[1] );
		return array_values( array_filter( array_keys( $GLOBALS['options'] ), static function ( $k ) use ( $like ) {
			return 0 === strpos( $k, $like );
		} ) );
	}
}
$GLOBALS['wpdb'] = new Agents_WPDB();
function has_shortcode( $content, $tag ) { return (bool) preg_match( '/\\[' . preg_quote( $tag, '/' ) . '[\\s\\]\\/]/', (string) $content ); }
// Formats in the site timezone, like core's wp_date().
function wp_date( $format, $ts = null ) { $d = new DateTime( '@' . ( null === $ts ? time() : (int) $ts ) ); $d->setTimezone( new DateTimeZone( $GLOBALS['wp']['tz'] ) ); return $d->format( $format ); }
function wp_enqueue_script( $h, $src = '', $deps = array(), $ver = false, $args = array() ) { $GLOBALS['wp']['scripts'][ $h ] = array( $src, $ver, $args ); }
function wp_register_style() {} function wp_register_script() {} function wp_add_inline_style() {} function wp_add_inline_script() {}

// ---- i18n ------------------------------------------------------------------------------------
function __( $s, $d = 'default' ) { return $s; }
function _n( $single, $plural, $n, $d = 'default' ) { return 1 === (int) $n ? $single : $plural; }
function esc_html__( $s, $d = 'default' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = 'default' ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = 'default' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = 'default' ) { echo esc_attr( $s ); }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, $dec ); }

// ---- escaping and sanitising -----------------------------------------------------------------
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
// Like core: only http(s), mailto etc. pass; anything else (javascript:, data:) comes back empty.
function esc_url( $s ) {
	$s = (string) $s;
	if ( ! preg_match( '#^(https?|mailto|tel):#i', $s ) && ! preg_match( '#^[/\#?]#', $s ) ) { return ''; }
	return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url_raw( $s ) { return preg_match( '#^https?://#i', (string) $s ) ? (string) $s : ''; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( strip_tags( (string) $t ) ) ), '-' ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

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

/** The Boise fixture, decoded. */
function agents_fixture() {
	return json_decode( file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/tests/fixtures/boise-payload.json' ), true );
}

/** A minimal valid agent, with overrides. */
function agents_make( $over = array() ) {
	static $n = 0;
	++$n;
	return array_merge(
		array(
			'zuid'             => 'X1-' . $n,
			'name'             => 'Agent ' . $n,
			'profile_url'      => 'https://www.zillow.com/profile/agent' . $n,
			'brokerage'        => 'Brokerage ' . $n,
			'is_team'          => false,
			'is_top_agent'     => false,
			'rating'           => 4.8,
			'rating_count'     => 10,
			'sales_in_city'    => 5,
			'sales_12mo'       => 2,
			'price_range'      => '$100K-$500K',
			'avg_price'        => '$300K',
			'years'            => 4,
			'specialties'      => array(),
			'tier'             => 'primary',
			'office'           => array( 'city' => 'Boise', 'state' => 'ID', 'zip' => '83702' ),
			'areas'            => array( 'Boise, ID' ),
			'area_evidence'    => '',
			'zip_sales'        => array(),
			'summary'          => '',
			'tags'             => array(),
			'reviews_analyzed' => 20,
			'last_review'      => '',
			'tagged_at'        => '',
		),
		$over
	);
}
