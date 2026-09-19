<?php
/**
 * Conflict handling. The rule being pinned: a plugin this toolkit replaces is
 * deactivated only once the module replacing it is switched on, and a
 * third-party plugin is never touched.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'DOS_TOOLKIT_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['options']     = array();
$GLOBALS['installed']   = array();   // file => ['Name' => ...]
$GLOBALS['active']      = array();   // list of active plugin files
$GLOBALS['deactivated'] = array();
$GLOBALS['log']         = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return $s; } function esc_attr( $s ) { return $s; } function esc_url( $s ) { return $s; }
function esc_html_e( $s, $d = '' ) { echo $s; }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function current_user_can( $c ) { return $GLOBALS['can'] ?? true; }
function get_current_user_id() { return 1; }
function current_time() { return date( 'Y-m-d H:i:s' ); }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function wp_nonce_url( $u, $a ) { return $u; }
function add_query_arg( $k, $v = null, $u = null ) { return 'https://example.com/'; }

function get_plugins() { return $GLOBALS['installed']; }
function is_plugin_active( $file ) { return in_array( $file, $GLOBALS['active'], true ); }
function deactivate_plugins( $files, $silent = false ) {
    foreach ( (array) $files as $f ) {
        $GLOBALS['deactivated'][] = $f;
        $GLOBALS['active'] = array_values( array_diff( $GLOBALS['active'], array( $f ) ) );
    }
}

class DOS_Log { public static function add( $m, $a, $msg = '' ) { $GLOBALS['log'][] = $a; } public static function prune() {} }
class DOS_Admin { const MENU_SLUG = 'dos-tools'; }

require PLUGIN . '/includes/class-dos-settings.php';

// Stand in for the registry, so module state can be driven directly.
class DOS_Toolkit {
    public static function is_active( $key ) { return ! empty( $GLOBALS['modules'][ $key ] ); }
}

require PLUGIN . '/includes/class-dos-conflicts.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}
function reset_site() {
    $GLOBALS['installed'] = array(
        'saab-toolkit/saab-toolkit.php' => array( 'Name' => 'SAAB Toolkit' ),
        'media-usage-manager/media-usage-manager.php' => array( 'Name' => 'Media Usage Manager' ),
        'page-tags-tools/page-tags-tools.php' => array( 'Name' => 'Page Tags Tools' ),
        'akismet/akismet.php' => array( 'Name' => 'Akismet Anti-spam' ),
    );
    $GLOBALS['active']      = array_keys( $GLOBALS['installed'] );
    $GLOBALS['deactivated'] = array();
    $GLOBALS['log']         = array();
    $GLOBALS['modules']     = array();
    $GLOBALS['options']     = array();

    // DOS_Settings caches for the life of the process; a real request starts
    // clean, so clear the keys these tests set.
    DOS_Settings::delete( 'deactivate_superseded' );
}

echo "--- detection ---\n";
reset_site();
$found = DOS_Conflicts::active_superseded();
check( 'finds an active superseded plugin', isset( $found['saab-toolkit/saab-toolkit.php'] ) );
check( 'finds all three', 3 === count( $found ), implode( ',', array_keys( $found ) ) );
check( 'ignores an unrelated plugin', ! isset( $found['akismet/akismet.php'] ) );

$GLOBALS['active'] = array( 'akismet/akismet.php' );
check( 'ignores an installed but inactive one', array() === DOS_Conflicts::active_superseded() );

echo "\n--- renamed folder ---\n";
reset_site();
$GLOBALS['installed']['client-copy-of-mum/mum.php'] = array( 'Name' => 'Media Usage Manager' );
$GLOBALS['active'][] = 'client-copy-of-mum/mum.php';
$found = DOS_Conflicts::active_superseded();
check( 'matches on plugin name when the folder was renamed', isset( $found['client-copy-of-mum/mum.php'] ), implode( ',', array_keys( $found ) ) );
check( '  and still finds the original alongside it', isset( $found['media-usage-manager/media-usage-manager.php'] ), implode( ',', array_keys( $found ) ) );

echo "\n--- deactivation is gated on the module being on ---\n";
reset_site();
DOS_Conflicts::maybe_deactivate();
check( 'deactivates nothing while every module is off', array() === $GLOBALS['deactivated'], implode( ',', $GLOBALS['deactivated'] ) );
check( '  so a site is never left with neither', 4 === count( $GLOBALS['active'] ), (string) count( $GLOBALS['active'] ) );

$GLOBALS['modules']['images'] = true;
DOS_Conflicts::maybe_deactivate();
check( 'deactivates the media plugin once Images is on', in_array( 'media-usage-manager/media-usage-manager.php', $GLOBALS['deactivated'], true ) );
check( '  leaves the SEO one alone while SEO is off', ! in_array( 'saab-toolkit/saab-toolkit.php', $GLOBALS['deactivated'], true ) );
check( '  never touches an unrelated plugin', ! in_array( 'akismet/akismet.php', $GLOBALS['deactivated'], true ) );
check( '  logs what it did', in_array( 'superseded_deactivated', $GLOBALS['log'], true ) );
check( '  records it for the notice', ! empty( get_option( 'dos_conflicts_deactivated' ) ) );

$GLOBALS['modules']['seo'] = true;
DOS_Conflicts::maybe_deactivate();
check( 'deactivates the SEO one once SEO is on too', in_array( 'saab-toolkit/saab-toolkit.php', $GLOBALS['deactivated'], true ) );

echo "\n--- the setting is respected ---\n";
reset_site();
$GLOBALS['modules'] = array( 'images' => true, 'seo' => true, 'utilities' => true );
DOS_Settings::set( 'deactivate_superseded', 0 );
DOS_Conflicts::maybe_deactivate();
check( 'deactivates nothing when switched off', array() === $GLOBALS['deactivated'] );
check( '  but still reports the conflict', 3 === count( DOS_Conflicts::active_superseded() ) );

reset_site();
check( 'defaults to on', true === DOS_Conflicts::auto_deactivate_enabled() );

echo "\n--- permissions ---\n";
reset_site();
$GLOBALS['modules']['images'] = true;
$GLOBALS['can'] = false;
DOS_Conflicts::maybe_deactivate();
check( 'a user who cannot activate plugins deactivates nothing', array() === $GLOBALS['deactivated'] );
$GLOBALS['can'] = true;

echo "\n--- third-party plugins ---\n";
reset_site();
define( 'WPSEO_VERSION', '22.0' );
$third = DOS_Conflicts::active_third_party();
check( 'detects Yoast', isset( $third['WPSEO_VERSION'] ) );
check( '  attributes it to the SEO module', 'seo' === $third['WPSEO_VERSION']['module'] );

$GLOBALS['modules'] = array( 'seo' => true, 'images' => true, 'utilities' => true );
DOS_Conflicts::maybe_deactivate();
check( 'NEVER deactivates a third-party plugin', ! in_array( 'akismet/akismet.php', $GLOBALS['deactivated'], true ) );
check( '  and Yoast is not in the superseded list at all', ! array_key_exists( 'WPSEO_VERSION', DOS_Conflicts::superseded() ) );

echo "\n--- every superseded entry is well formed ---\n";
$ok = true;
foreach ( DOS_Conflicts::superseded() as $file => $entry ) {
    foreach ( array( 'name', 'module', 'replaced_by', 'severity', 'detail' ) as $k ) {
        if ( empty( $entry[ $k ] ) ) { $ok = false; echo "    missing $k on $file\n"; }
    }
    if ( ! in_array( $entry['module'], array( 'seo', 'images', 'utilities', 'ai' ), true ) ) { $ok = false; echo "    unknown module on $file\n"; }
}
check( 'each entry names a real module and explains itself', $ok );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
