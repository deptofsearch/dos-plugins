<?php
/**
 * Shared GitHub updater, run as an instance for dos-city-search. Updater caching. The bug this pins: a failed lookup used to be cached for
 * as long as a successful one, so a rate limit or a timeout read as "no
 * updates exist" for six hours.
 */

define( 'ABSPATH', '/tmp/' );
define( 'SHARED', dirname( __DIR__ ) . '/shared' );
define( 'TEST_VERSION', '0.5.2' );
define( 'TEST_BASENAME', 'dos-city-search/dos-city-search.php' );
define( 'TEST_SLUG', 'dos-city-search' );
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
function add_filter() {}
function add_action( $tag, $cb = null ) { $GLOBALS['actions'][ $tag ][] = $cb; }
function apply_filters( $tag, $value ) { return isset( $GLOBALS['filters'][ $tag ] ) ? $GLOBALS['filters'][ $tag ] : $value; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
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
$GLOBALS['pages'] = null;   // when set, answer per page instead
function wp_remote_get( $url, $args = array() ) {
    $GLOBALS['calls']++;
    $GLOBALS['last_args'] = $args;
    if ( is_array( $GLOBALS['pages'] ) ) {
        preg_match( '/[?&]page=(\\d+)/', $url, $m );
        $page = isset( $m[1] ) ? (int) $m[1] : 1;
        $GLOBALS['requested'][] = $page;
        return $GLOBALS['pages'][ $page ] ?? array( 'response' => array( 'code' => 200 ), 'body' => '[]' );
    }
    return $GLOBALS['http'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require SHARED . '/class-dos-github-updater.php';

function new_updater( $slug = TEST_SLUG, $basename = null, $extra = array() ) {
    return new DOS_GitHub_Updater( array_merge( array(
        'slug'        => $slug,
        'basename'    => $basename ?? $slug . '/' . $slug . '.php',
        'version'     => TEST_VERSION,
        'name'        => 'DoS - City Search',
        'description' => 'A description.',
    ), $extra ) );
}

$updater = new_updater();
$LIST_KEY = 'dos_github_releases_' . md5( 'deptofsearch/dos-plugins' );
$REL_KEY  = 'dos_release_' . TEST_SLUG;

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
            'assets' => array( array( 'name' => 'dos-city-search.zip', 'browser_download_url' => 'https://example.com/' . $t . '.zip' ) ),
        );
    }
    return json_encode( $out );
}
function ok_response( array $tags ) { return array( 'response' => array( 'code' => 200 ), 'body' => releases_json( $tags ) ); }

echo "--- a successful lookup ---\n";
$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.6.0', 'dos-city-search-v0.5.2' ) );
$s = $updater->status( true );
check( 'finds the newest release', '0.6.0' === $s['latest'], json_encode( $s ) );
check( 'reports an update is available', true === $s['update'] );
check( 'records no failure', null === $s['miss'] );
check( 'caches the success for the full TTL', 6 * HOUR_IN_SECONDS === ttl_of( $GLOBALS['LIST_KEY'] ), (string) ttl_of( $GLOBALS['LIST_KEY'] ) );

echo "\n--- a rate-limited lookup ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = array( 'response' => array( 'code' => 403 ), 'body' => '{"message":"rate limit exceeded"}' );
$s = $updater->status( true );
check( 'reports no release', '' === $s['latest'] );
check( 'records why', $s['miss'] && false !== strpos( $s['miss']['reason'], '403' ), json_encode( $s['miss'] ) );
check( '  and says what a 403 usually means', false !== strpos( $s['miss']['reason'], 'rate limited' ) );
check( 'caches the failure for 15 minutes, NOT 6 hours', 15 * MINUTE_IN_SECONDS === ttl_of( $GLOBALS['LIST_KEY'] ), (string) ttl_of( $GLOBALS['LIST_KEY'] ) );

echo "\n--- a failure does not become permanent ---\n";
$GLOBALS['calls'] = 0;
$GLOBALS['http']  = ok_response( array( 'dos-city-search-v0.6.0' ) );
// Simulate the miss transient having expired, which is all 15 minutes means.
$GLOBALS['transients'] = array();
$s = $updater->status();
check( 'the next lookup after expiry succeeds', '0.6.0' === $s['latest'], json_encode( $s ) );
check( '  and it did call out again', $GLOBALS['calls'] > 0 );

echo "\n--- a network error ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$s = $updater->status( true );
check( 'surfaces the transport error verbatim', $s['miss'] && false !== strpos( $s['miss']['reason'], 'timed out' ), json_encode( $s['miss'] ) );
check( 'caches it briefly', 15 * MINUTE_IN_SECONDS === ttl_of( $GLOBALS['LIST_KEY'] ) );

echo "\n--- releases that do not match this plugin ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'some-other-plugin-v9.9.9' ) );
$s = $updater->status( true );
check( 'ignores another plugin in the same repository', '' === $s['latest'], json_encode( $s ) );
check( '  and explains why rather than failing silently', $s['miss'] && false !== strpos( $s['miss']['reason'], 'dos-city-search-v' ), json_encode( $s['miss'] ) );

echo "\n--- cache is honoured between checks ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.6.0' ) );
$updater->status( true );
$GLOBALS['calls'] = 0;
$updater->status();
check( 'a cached success does not call the API again', 0 === $GLOBALS['calls'] );
$GLOBALS['calls'] = 0;
$updater->status( true );
check( 'a forced check does', $GLOBALS['calls'] > 0 );

echo "\n--- injection into the WordPress transient ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.6.0' ) );
$t = $updater->inject_update( (object) array( 'response' => array() ) );
check( 'adds the plugin under its basename', isset( $t->response[ TEST_BASENAME ] ) );
check( '  with the new version', '0.6.0' === $t->response[ TEST_BASENAME ]->new_version );
check( '  and a package URL', false !== strpos( $t->response[ TEST_BASENAME ]->package, '.zip' ) );

check( '  and marks it update-supported, which is what shows the auto-update toggle', ! empty( $t->response[ TEST_BASENAME ]->{'update-supported'} ) );

$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.5.2' ) );
$t = $updater->inject_update( (object) array( 'response' => array(), 'no_update' => array() ) );
check( 'offers nothing when the newest release is the installed one', ! isset( $t->response[ TEST_BASENAME ] ) );
check( '  but still lists it as up to date', isset( $t->no_update[ TEST_BASENAME ] ) );
check( '    so WordPress keeps offering auto-updates', ! empty( $t->no_update[ TEST_BASENAME ]->{'update-supported'} ) );
check( '    reporting the installed version', TEST_VERSION === $t->no_update[ TEST_BASENAME ]->new_version );

// A stale entry on the wrong side must not survive a later check.
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.5.2' ) );
$stale = (object) array( 'response' => array( TEST_BASENAME => 'stale' ), 'no_update' => array() );
$t = $updater->inject_update( $stale );
check( 'clears a stale pending update once it is no longer newer', ! isset( $t->response[ TEST_BASENAME ] ), 'phantom update survived' );

$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.6.0' ) );
$stale = (object) array( 'response' => array(), 'no_update' => array( TEST_BASENAME => 'stale' ) );
$t = $updater->inject_update( $stale );
check( 'moves it out of no_update when a release does arrive', ! isset( $t->no_update[ TEST_BASENAME ] ) && isset( $t->response[ TEST_BASENAME ] ) );

echo "\n--- cache is dropped when this plugin is updated ---\n";
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.6.0' ) );
$updater->status( true );
set_site_transient( 'update_plugins', (object) array( 'response' => array( TEST_BASENAME => 'stale' ) ), 0 );

$updater->after_update( null, array( 'type' => 'plugin', 'plugin' => TEST_BASENAME ) );
check( 'clears the cached release', false === get_site_transient( $GLOBALS['REL_KEY'] ) );
check( "  and WordPress's stale update entry", false === get_site_transient( 'update_plugins' ) );

$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.6.0' ) );
$updater->status( true );
$updater->after_update( null, array( 'type' => 'plugin', 'plugins' => array( 'other/other.php' ) ) );
check( 'leaves the cache alone when a different plugin updates', false !== get_site_transient( $GLOBALS['REL_KEY'] ) );

$updater->after_update( null, array( 'type' => 'theme', 'themes' => array( 'twentytwentyfive' ) ) );
check( 'ignores theme updates', false !== get_site_transient( $GLOBALS['REL_KEY'] ) );

check( 'handles a bulk update that includes this plugin', ( function () use ( $updater ) {
    $updater->after_update( null, array( 'type' => 'plugin', 'plugins' => array( 'a/a.php', TEST_BASENAME ) ) );
    return false === get_site_transient( $GLOBALS['REL_KEY'] );
} )() );

echo "\n--- the View details screen ---\n";

$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.6.0' ) );

$info = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'dos-city-search' ) );
check( 'answers for its own slug', is_object( $info ), gettype( $info ) );
check( '  with the version on offer', '0.6.0' === $info->version, json_encode( $info->version ?? null ) );
check( '  a changelog section', ! empty( $info->sections['changelog'] ) );
check( '  and a description, which the screen opens on', ! empty( $info->sections['description'] ) );

check( 'leaves other plugins alone', false === $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
check( 'leaves other actions alone', false === $updater->plugin_info( false, 'query_plugins', (object) array( 'slug' => 'dos-city-search' ) ) );

// The failure that produced "Plugin not found." on the canary: with nothing
// cached and the lookup failing, this used to hand the question to
// wordpress.org, which has never heard of this plugin.
$GLOBALS['transients'] = array();
$GLOBALS['http'] = array( 'response' => array( 'code' => 403 ), 'body' => '{}' );

$info = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'dos-city-search' ) );
check( 'still answers when the release cannot be read', is_object( $info ), gettype( $info ) );
check( '  falling back to the installed version', TEST_VERSION === $info->version, json_encode( $info->version ?? null ) );
check( '  and saying why the notes are missing', false !== strpos( $info->sections['changelog'], '403' ), $info->sections['changelog'] );

// Some callers pass an array rather than an object.
$GLOBALS['transients'] = array();
$GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.6.0' ) );
check( 'accepts an array of arguments as well as an object', is_object( $updater->plugin_info( false, 'plugin_information', array( 'slug' => 'dos-city-search' ) ) ) );

check( 'and the plugin file, since not every caller passes the slug', is_object( $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => TEST_BASENAME ) ) ) );
check( '  but still nothing that is neither', false === $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'dos-city-search/other.php' ) ) );

echo "\n--- more releases than fit on one page ---\n";
// A monorepo's release list holds every plugin's tags at once and GitHub does
// not order it by version. Once the newest release for this plugin falls past
// the end of the first page it stops being seen, and the symptom is "no
// update available" — a plausible answer, and so the wrong one to give.
function full_page( $prefix, $count ) {
    $tags = array();
    for ( $i = 0; $i < $count; $i++ ) { $tags[] = $prefix . $i; }
    return array( 'response' => array( 'code' => 200 ), 'body' => releases_json( $tags ) );
}

$GLOBALS['transients'] = array();
$GLOBALS['requested']  = array();
$GLOBALS['pages'] = array(
    // A full page of another plugin's releases, which this one must skip.
    1 => full_page( 'other-plugin-v1.0.', 100 ),
    2 => ok_response( array( 'dos-city-search-v0.20.1', 'dos-city-search-v0.9.3' ) ),
);

$s = $updater->status( true );
check( 'a release on the second page is still found', '0.20.1' === $s['latest'], json_encode( $s ) );
check( '  and the first page was fetched before it', in_array( 1, $GLOBALS['requested'], true ) );
check( '  and the short second page ended the walk', ! in_array( 3, $GLOBALS['requested'], true ), json_encode( $GLOBALS['requested'] ) );

// Walking must stop somewhere, or a repository with thousands of releases
// would make an admin page load wait on all of them.
$GLOBALS['transients'] = array();
$GLOBALS['requested']  = array();
$GLOBALS['pages'] = array_fill( 1, 20, full_page( 'other-plugin-v1.0.', 100 ) );

$s = $updater->status( true );
check( 'the walk is capped rather than following every page', count( $GLOBALS['requested'] ) <= 5, json_encode( $GLOBALS['requested'] ) );
check( '  and says it found nothing rather than claiming to be up to date', '' === $s['latest'] && $s['miss'], json_encode( $s ) );

$GLOBALS['pages'] = null;

echo "\n--- the cached list holds only what is used ---\n";
$GLOBALS['transients'] = array();
$full = json_decode( releases_json( array( 'dos-city-search-v0.6.0' ) ), true );
$full[0]['author']            = array( 'login' => 'someone' );
$full[0]['reactions']         = array( 'total_count' => 3 );
$full[0]['assets'][0]['uploader'] = array( 'login' => 'github-actions[bot]' );
$full[0]['assets'][0]['download_count'] = 12;
$full[0]['body'] = str_repeat( 'a', 30000 );
$GLOBALS['http'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $full ) );
$s = $updater->status( true );
$cached = get_site_transient( $LIST_KEY );
check( 'the release still resolves', '0.6.0' === $s['latest'], json_encode( $s ) );
check( 'no author or reactions are cached', ! isset( $cached[0]['author'] ) && ! isset( $cached[0]['reactions'] ) );
check( 'assets keep only name and download URL', array( 'name', 'browser_download_url' ) === array_keys( $cached[0]['assets'][0] ), json_encode( $cached[0]['assets'][0] ) );
check( 'the notes are capped, not dropped', 4096 === strlen( $cached[0]['body'] ) );

echo "\n--- Dashboard -> Updates -> Check again ---\n";
$updater->boot();
$cb = $GLOBALS['actions']['load-update-core.php'][0] ?? null;
check( 'boot hooks load-update-core.php', is_array( $cb ) );
set_site_transient( $LIST_KEY, array( 'stale' ), 100 );
set_site_transient( 'dos_release_' . TEST_SLUG, array( 'stale' ), 100 );
unset( $_GET['force-check'] );
if ( $cb ) { call_user_func( $cb ); }
check( 'a plain visit to the Updates page keeps the cache', false !== get_site_transient( $LIST_KEY ) && false !== get_site_transient( 'dos_release_' . TEST_SLUG ) );
$_GET['force-check'] = '1';
if ( $cb ) { call_user_func( $cb ); }
check( '?force-check drops this plugin\'s release and the shared list', false === get_site_transient( $LIST_KEY ) && false === get_site_transient( 'dos_release_' . TEST_SLUG ) );
unset( $_GET['force-check'] );

echo "\n--- a tag for this plugin whose ZIP belongs to another ---\n";
// A monorepo release can carry several ZIPs. A matching tag with no asset
// named for this plugin must not be installed from someone else's package.
$GLOBALS['transients'] = array();
$GLOBALS['http'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( array(
        'tag_name' => 'dos-city-search-v9.0.0', 'draft' => false, 'prerelease' => false,
        'assets'   => array( array( 'name' => 'dos-toolkit.zip', 'browser_download_url' => 'https://example.com/dos-toolkit.zip' ) ),
    ) ) ),
);
$s = $updater->status( true );
check( 'the release is skipped', '' === $s['latest'], json_encode( $s ) );
check( '  and says nothing matched', $s['miss'] && false !== strpos( $s['miss']['reason'], 'dos-city-search-v' ), json_encode( $s['miss'] ) );

echo "\n--- drafts and prereleases ---\n";
$GLOBALS['transients'] = array();
$draft = json_decode( releases_json( array( 'dos-city-search-v7.0.0' ) ), true );
$pre   = json_decode( releases_json( array( 'dos-city-search-v8.0.0' ) ), true );
$draft[0]['draft'] = true;
$pre[0]['prerelease'] = true;
$GLOBALS['http'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array_merge( $draft, $pre, json_decode( releases_json( array( 'dos-city-search-v0.6.0' ) ), true ) ) ) );
$s = $updater->status( true );
check( 'neither is offered', '0.6.0' === $s['latest'], json_encode( $s ) );

echo "\n--- two plugins, one walk of the API ---\n";
// Every DoS plugin on a site asks the same question of the same repository.
$GLOBALS['transients'] = array();
$GLOBALS['calls'] = 0;
$GLOBALS['http'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array_merge(
        json_decode( releases_json( array( 'dos-city-search-v0.6.0' ) ), true ),
        array( array(
            'tag_name' => 'dos-market-images-v2.0.0', 'draft' => false, 'prerelease' => false,
            'assets'   => array( array( 'name' => 'dos-market-images.zip', 'browser_download_url' => 'https://example.com/dos-market-images.zip' ) ),
        ) )
    ) ),
);
$cs = new_updater( 'dos-city-search' );
$mi = new_updater( 'dos-market-images' );
$t1 = $cs->inject_update( (object) array( 'response' => array(), 'no_update' => array() ) );
$t2 = $mi->inject_update( (object) array( 'response' => array(), 'no_update' => array() ) );
check( 'one API call served both', 1 === $GLOBALS['calls'], (string) $GLOBALS['calls'] );
check( '  each got its own release', '0.6.0' === $t1->response['dos-city-search/dos-city-search.php']->new_version && '2.0.0' === $t2->response['dos-market-images/dos-market-images.php']->new_version );
check( '  and its own derived cache entry', false !== get_site_transient( 'dos_release_dos-city-search' ) && false !== get_site_transient( 'dos_release_dos-market-images' ) );

echo "\n--- which token is sent ---\n";
function sent_auth() {
    $GLOBALS['transients'] = array();
    $GLOBALS['http'] = ok_response( array( 'dos-city-search-v0.6.0' ) );
    new_updater()->status( true );
    return $GLOBALS['last_args']['headers']['Authorization'] ?? null;
}
check( 'no token, no Authorization header', null === sent_auth() );

$GLOBALS['filters']['dos_github_updater_token'] = 'from-filter';
check( 'the filter supplies one when no constant does', 'Bearer from-filter' === sent_auth(), (string) sent_auth() );

define( 'DOS_TOOLKIT_GITHUB_TOKEN', 'toolkit-token' );
check( 'the Toolkit constant beats the filter', 'Bearer toolkit-token' === sent_auth(), (string) sent_auth() );

define( 'DOS_GITHUB_TOKEN', 'shared-token' );
check( 'DOS_GITHUB_TOKEN beats DOS_TOOLKIT_GITHUB_TOKEN', 'Bearer shared-token' === sent_auth(), (string) sent_auth() );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
