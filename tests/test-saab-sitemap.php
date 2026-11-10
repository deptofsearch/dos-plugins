<?php
/**
 * DoS SAAB Toolkit: the sitemap index stays reachable.
 *
 * On 2026-11-10 shotandabeer.com/wp-sitemap.xml returned the homepage: a rule left by an old sitemap plugin matched
 * the path and emptied the query. SAAB_Sitemap answers the path itself at parse_request, redirects /sitemap.xml,
 * drops the noindexed author sitemap, and flushes the stored rules once.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['options']  = array();
$GLOBALS['flushes']  = 0;
$GLOBALS['enabled']  = true;
$GLOBALS['hooks']    = array();

function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][] = array( $cb, $prio ); }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { add_action( $hook, $cb, $prio, $args ); }
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; }
function flush_rewrite_rules() { $GLOBALS['flushes']++; }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function wp_safe_redirect( $url, $status = 302 ) { throw new Exception( $status . ' ' . $url ); }
function wp_sitemaps_get_server() {
	return new class() {
		public function sitemaps_enabled() { return $GLOBALS['enabled']; }
	};
}

require dirname( __DIR__ ) . '/plugins/dos-saab-toolkit/includes/class-saab-sitemap.php';

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

/** A request as WP::parse_request leaves it: the path, and whatever the matched rule produced. */
function request( $path, $query_vars = array() ) {
	$wp             = new stdClass();
	$wp->request    = $path;
	$wp->query_vars = $query_vars;
	return $wp;
}

echo "--- hooks ---\n";
SAAB_Sitemap::init();
check( 'routes at parse_request', isset( $GLOBALS['hooks']['parse_request'] ) );
check( 'filters the sitemap providers', isset( $GLOBALS['hooks']['wp_sitemaps_add_provider'] ) );
check( 'flushes after every other init callback', 1000 === ( $GLOBALS['hooks']['init'][0][1] ?? 0 ) );

echo "--- the index ---\n";
// What the stray rule leaves: its own query variable is unregistered, so nothing survives.
$wp = request( 'wp-sitemap.xml' );
SAAB_Sitemap::route( $wp );
check( 'an emptied /wp-sitemap.xml becomes the sitemap index', array( 'sitemap' => 'index' ) === $wp->query_vars, json_encode( $wp->query_vars ) );

$wp = request( 'WP-Sitemap.xml/' );
SAAB_Sitemap::route( $wp );
check( 'case and a trailing slash do not matter', array( 'sitemap' => 'index' ) === $wp->query_vars );

$wp = request( 'wp-sitemap-posts-post-1.xml', array( 'sitemap' => 'posts', 'sitemap-subtype' => 'post', 'paged' => '1' ) );
SAAB_Sitemap::route( $wp );
check( 'a sitemap core already matched is left alone', 'posts' === $wp->query_vars['sitemap'] && 3 === count( $wp->query_vars ) );

$wp = request( 'wp-sitemap.xml', array( 'sitemap' => 'index' ) );
SAAB_Sitemap::route( $wp );
check( 'a working index is left alone', array( 'sitemap' => 'index' ) === $wp->query_vars );

foreach ( array( '', 'restaurants', 'thai-elephant-kennewick-wa', 'news-sitemap.xml', 'wp-sitemap.xml/extra' ) as $path ) {
	$wp = request( $path, array( 'name' => 'x' ) );
	SAAB_Sitemap::route( $wp );
	check( 'other paths untouched: "' . $path . '"', array( 'name' => 'x' ) === $wp->query_vars );
}

echo "--- /sitemap.xml ---\n";
$redirect = '';
try {
	SAAB_Sitemap::route( request( 'sitemap.xml' ) );
} catch ( Exception $e ) {
	$redirect = $e->getMessage();
}
check( '/sitemap.xml redirects to the index, permanently', '301 https://example.com/wp-sitemap.xml' === $redirect, $redirect );

echo "--- sitemaps switched off ---\n";
$GLOBALS['enabled'] = false;
$wp                 = request( 'wp-sitemap.xml' );
SAAB_Sitemap::route( $wp );
check( 'nothing is routed when core sitemaps are disabled', array() === $wp->query_vars );
$redirect = '';
try {
	SAAB_Sitemap::route( request( 'sitemap.xml' ) );
} catch ( Exception $e ) {
	$redirect = $e->getMessage();
}
check( 'and /sitemap.xml is not redirected', '' === $redirect );
$GLOBALS['enabled'] = true;

echo "--- providers ---\n";
$provider = new stdClass();
check( 'the author sitemap is dropped (the archive is noindex)', false === SAAB_Sitemap::filter_provider( $provider, 'users' ) );
check( 'posts stay', $provider === SAAB_Sitemap::filter_provider( $provider, 'posts' ) );
check( 'taxonomies stay', $provider === SAAB_Sitemap::filter_provider( $provider, 'taxonomies' ) );

echo "--- flush ---\n";
SAAB_Sitemap::maybe_flush_rewrite_rules();
SAAB_Sitemap::maybe_flush_rewrite_rules();
check( 'the stored rules are rebuilt once, not on every request', 1 === $GLOBALS['flushes'], (string) $GLOBALS['flushes'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
