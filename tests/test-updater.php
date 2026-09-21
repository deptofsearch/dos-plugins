<?php
/**
 * Updater caching. The bug this pins: a failed lookup used to be cached for
 * as long as a successful one, so a rate limit or a timeout read as "no
 * updates exist" for six hours.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'DOS_TOOLKIT_VERSION', '0.5.2' );
define( 'DOS_TOOLKIT_BASENAME', 'dos-toolkit/dos-toolkit.php' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();   // key => [value, ttl]
$GLOBALS['http']       = null;      // next wp_remote_get result
$GLOBALS['calls']      = 0;

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function get_site_transient( $k ) { return $GLOBALS['transients'][ $k ][0] ?? false; }
function set_site_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = array( $v, $ttl ); return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function ttl_of( $k ) { return $GLOBALS['transients'][ $k ][1] ?? null; }
function __( $s, $d = '' ) { return $s; }
function add_filter() {} function add_action() {}
function get_bloginfo( $w ) { return '6.5'; }
function wpautop( $s ) { return $s; } function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_url( $s ) { return $s; }
function esc_attr( $s ) { return $s; }

class WP_Error {
    private $msg;
    public function __construct( $c = '', $m = '' ) { $this->msg = $m; }
    public function get_error_message() { return $this->msg; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['calls']++; return $GLOBALS['http']; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/includes/class-dos-updater.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

function releases_json( array $tags ) {
    $out = array();
    foreach ( $tags as $t ) {
        $out[] = array(
            'tag_name' => $t, 'draft' => false, 'prerelease' => false,
            'body' => 'notes', 'published_at' => '2026-01-01T00:00:00Z',
            'html_url' => 'https://example.com/r/' . $t,
            'assets' => array( array( 'name' => 'dos-toolkit.zip', 'browser_download_url' => 'https://example.com/' . $t . '.zip' ) ),
        );
    }
    return json_encode( $out );
}
function ok_response( array $tags ) { return array( 'response' => array( 'code' => 200 ), 'body' => releases_json( $tags ) ); }

echo "--- a successful lookup ---\n";
$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.6.0', 'dos-toolkit-v0.5.2' ) );
$s = DOS_Updater::status( true );
check( 'finds the newest release', '0.6.0' === $s['latest'], json_encode( $s ) );
check( 'reports an update is available', true === $s['update'] );
check( 'records no failure', null === $s['miss'] );
check( 'caches the success for the full TTL', 6 * HOUR_IN_SECONDS === ttl_of( 'dos_toolkit_release' ), (string) ttl_of( 'dos_toolkit_release' ) );

echo "\n--- a rate-limited lookup ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = array( 'response' => array( 'code' => 403 ), 'body' => '{"message":"rate limit exceeded"}' );
$s = DOS_Updater::status( true );
check( 'reports no release', '' === $s['latest'] );
check( 'records why', $s['miss'] && false !== strpos( $s['miss']['reason'], '403' ), json_encode( $s['miss'] ) );
check( '  and says what a 403 usually means', false !== strpos( $s['miss']['reason'], 'rate limited' ) );
check( 'caches the failure for 15 minutes, NOT 6 hours', 15 * MINUTE_IN_SECONDS === ttl_of( 'dos_toolkit_release' ), (string) ttl_of( 'dos_toolkit_release' ) );

echo "\n--- a failure does not become permanent ---\n";
$GLOBALS['calls'] = 0;
$GLOBALS['http']  = ok_response( array( 'dos-toolkit-v0.6.0' ) );
// Simulate the miss transient having expired, which is all 15 minutes means.
$GLOBALS['transients'] = array();
$s = DOS_Updater::status();
check( 'the next lookup after expiry succeeds', '0.6.0' === $s['latest'], json_encode( $s ) );
check( '  and it did call out again', $GLOBALS['calls'] > 0 );

echo "\n--- a network error ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$s = DOS_Updater::status( true );
check( 'surfaces the transport error verbatim', $s['miss'] && false !== strpos( $s['miss']['reason'], 'timed out' ), json_encode( $s['miss'] ) );
check( 'caches it briefly', 15 * MINUTE_IN_SECONDS === ttl_of( 'dos_toolkit_release' ) );

echo "\n--- releases that do not match this plugin ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'some-other-plugin-v9.9.9' ) );
$s = DOS_Updater::status( true );
check( 'ignores another plugin in the same repository', '' === $s['latest'], json_encode( $s ) );
check( '  and explains why rather than failing silently', $s['miss'] && false !== strpos( $s['miss']['reason'], 'dos-toolkit-v' ), json_encode( $s['miss'] ) );

echo "\n--- cache is honoured between checks ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.6.0' ) );
DOS_Updater::status( true );
$GLOBALS['calls'] = 0;
DOS_Updater::status();
check( 'a cached success does not call the API again', 0 === $GLOBALS['calls'] );
$GLOBALS['calls'] = 0;
DOS_Updater::status( true );
check( 'a forced check does', $GLOBALS['calls'] > 0 );

echo "\n--- injection into the WordPress transient ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.6.0' ) );
$t = DOS_Updater::inject_update( (object) array( 'response' => array() ) );
check( 'adds the plugin under its basename', isset( $t->response[ DOS_TOOLKIT_BASENAME ] ) );
check( '  with the new version', '0.6.0' === $t->response[ DOS_TOOLKIT_BASENAME ]->new_version );
check( '  and a package URL', false !== strpos( $t->response[ DOS_TOOLKIT_BASENAME ]->package, '.zip' ) );

check( '  and marks it update-supported, which is what shows the auto-update toggle', ! empty( $t->response[ DOS_TOOLKIT_BASENAME ]->{'update-supported'} ) );

$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.5.2' ) );
$t = DOS_Updater::inject_update( (object) array( 'response' => array(), 'no_update' => array() ) );
check( 'offers nothing when the newest release is the installed one', ! isset( $t->response[ DOS_TOOLKIT_BASENAME ] ) );
check( '  but still lists it as up to date', isset( $t->no_update[ DOS_TOOLKIT_BASENAME ] ) );
check( '    so WordPress keeps offering auto-updates', ! empty( $t->no_update[ DOS_TOOLKIT_BASENAME ]->{'update-supported'} ) );
check( '    reporting the installed version', DOS_TOOLKIT_VERSION === $t->no_update[ DOS_TOOLKIT_BASENAME ]->new_version );

// A stale entry on the wrong side must not survive a later check.
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.5.2' ) );
$stale = (object) array( 'response' => array( DOS_TOOLKIT_BASENAME => 'stale' ), 'no_update' => array() );
$t = DOS_Updater::inject_update( $stale );
check( 'clears a stale pending update once it is no longer newer', ! isset( $t->response[ DOS_TOOLKIT_BASENAME ] ), 'phantom update survived' );

$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.6.0' ) );
$stale = (object) array( 'response' => array(), 'no_update' => array( DOS_TOOLKIT_BASENAME => 'stale' ) );
$t = DOS_Updater::inject_update( $stale );
check( 'moves it out of no_update when a release does arrive', ! isset( $t->no_update[ DOS_TOOLKIT_BASENAME ] ) && isset( $t->response[ DOS_TOOLKIT_BASENAME ] ) );

echo "\n--- cache is dropped when this plugin is updated ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.6.0' ) );
DOS_Updater::status( true );
set_site_transient( 'update_plugins', (object) array( 'response' => array( DOS_TOOLKIT_BASENAME => 'stale' ) ), 0 );

DOS_Updater::after_update( null, array( 'type' => 'plugin', 'plugin' => DOS_TOOLKIT_BASENAME ) );
check( 'clears the cached release', false === get_site_transient( 'dos_toolkit_release' ) );
check( "  and WordPress's stale update entry", false === get_site_transient( 'update_plugins' ) );

$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.6.0' ) );
DOS_Updater::status( true );
DOS_Updater::after_update( null, array( 'type' => 'plugin', 'plugins' => array( 'other/other.php' ) ) );
check( 'leaves the cache alone when a different plugin updates', false !== get_site_transient( 'dos_toolkit_release' ) );

DOS_Updater::after_update( null, array( 'type' => 'theme', 'themes' => array( 'twentytwentyfive' ) ) );
check( 'ignores theme updates', false !== get_site_transient( 'dos_toolkit_release' ) );

check( 'handles a bulk update that includes this plugin', ( function () {
    DOS_Updater::after_update( null, array( 'type' => 'plugin', 'plugins' => array( 'a/a.php', DOS_TOOLKIT_BASENAME ) ) );
    return false === get_site_transient( 'dos_toolkit_release' );
} )() );

echo "\n--- the View details screen ---\n";

$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.6.0' ) );

$info = DOS_Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'dos-toolkit' ) );
check( 'answers for its own slug', is_object( $info ), gettype( $info ) );
check( '  with the version on offer', '0.6.0' === $info->version, json_encode( $info->version ?? null ) );
check( '  a changelog section', ! empty( $info->sections['changelog'] ) );
check( '  and a description, which the screen opens on', ! empty( $info->sections['description'] ) );

check( 'leaves other plugins alone', false === DOS_Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
check( 'leaves other actions alone', false === DOS_Updater::plugin_info( false, 'query_plugins', (object) array( 'slug' => 'dos-toolkit' ) ) );

// The failure that produced "Plugin not found." on the canary: with nothing
// cached and the lookup failing, this used to hand the question to
// wordpress.org, which has never heard of this plugin.
$GLOBALS['transients'] = array();
$GLOBALS['http'] = array( 'response' => array( 'code' => 403 ), 'body' => '{}' );

$info = DOS_Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'dos-toolkit' ) );
check( 'still answers when the release cannot be read', is_object( $info ), gettype( $info ) );
check( '  falling back to the installed version', DOS_TOOLKIT_VERSION === $info->version, json_encode( $info->version ?? null ) );
check( '  and saying why the notes are missing', false !== strpos( $info->sections['changelog'], '403' ), $info->sections['changelog'] );

// Some callers pass an array rather than an object.
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-toolkit-v0.6.0' ) );
check( 'accepts an array of arguments as well as an object', is_object( DOS_Updater::plugin_info( false, 'plugin_information', array( 'slug' => 'dos-toolkit' ) ) ) );

check( 'and the plugin file, since not every caller passes the slug', is_object( DOS_Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => DOS_TOOLKIT_BASENAME ) ) ) );
check( '  but still nothing that is neither', false === DOS_Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'dos-toolkit/other.php' ) ) );

echo "\n--- naming whoever else answered ---\n";
// The details modal has room for one line and cannot say who took the
// question. Reading the hook back is the only way to name the culprit.
define( 'WP_PLUGIN_DIR', '/wp-content/plugins' );
check( 'a plain function', 'some_updater_filter' === DOS_Updater::callable_name( 'some_updater_filter' ) );
check( 'a static method', 'DOS_Updater::plugin_info' === DOS_Updater::callable_name( array( 'DOS_Updater', 'plugin_info' ) ) );

class Fake_Updater { public function api() {} }
check( 'a method on an instance names its class', 'Fake_Updater::api' === DOS_Updater::callable_name( array( new Fake_Updater(), 'api' ) ) );

$closure = DOS_Updater::callable_name( function () {} );
check( 'a closure is located rather than called unknown', false !== strpos( $closure, 'line' ), $closure );
check( '  and names the file it came from', false !== strpos( $closure, 'test-updater.php' ), $closure );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
