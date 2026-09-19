<?php
/**
 * Utilities module: what is allowed to cross between sites.
 *
 * The import filter is the only place this plugin takes a structured payload
 * from outside, so it is the part worth pinning.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'DOS_TOOLKIT_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['options'] = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return $s; } function esc_html( $s ) { return $s; } function esc_url( $s ) { return $s; }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function current_user_can() { return true; }
function get_current_user_id() { return 1; }
function home_url( $p = '/' ) { return 'https://example.com' . $p; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }

class DOS_Log { public static function add() {} public static function prune() {} }
class DOS_Admin { public static function notice() {} }
class DOS_Plugin_Download { const ACTION = 'x'; public static function init() {} public static function is_supported() { return true; } public static function installed_plugins() { return array(); } }
class DOS_Page_Tags { const ACTION = 'y'; public static function init() {} public static function is_enabled() { return false; } }

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/includes/class-dos-module.php';

// Load just the module class, not its siblings — they are stubbed above.
$src = file_get_contents( PLUGIN . '/modules/utilities/class-dos-utilities.php' );
$src = preg_replace( "#require_once __DIR__ . '/[^']+';#", '', $src );
eval( '?>' . $src );

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

echo "--- export ---\n";

DOS_Settings::update( array(
    'enable_seo'         => 1,
    'enable_images'      => 0,
    'seo_twitter'        => 'deptofsearch',
    'images_slot_width'  => 350,
    'dry_run_default'    => 1,
    'log_retention_days' => 90,
    'github_repo'        => 'deptofsearch/dos-plugins',
    'github_token'       => 'ghp_secret_value',
    'log_db_version'     => '1',
) );

$export = DOS_Module_Utilities::portable_settings();

check( 'exports module toggles', 1 === ( $export['enable_seo'] ?? null ) );
check( 'exports module settings', 'deptofsearch' === ( $export['seo_twitter'] ?? null ) );
check( 'exports the update repository', isset( $export['github_repo'] ) );
check( 'NEVER exports the access token', ! array_key_exists( 'github_token', $export ), json_encode( array_keys( $export ) ) );
check( 'omits the log schema version', ! array_key_exists( 'log_db_version', $export ) );

echo "\n--- import filtering ---\n";

$hostile = array(
    'settings' => array(
        'seo_twitter'        => 'newhandle',   // allowed
        'enable_images'      => 1,             // allowed
        'github_token'       => 'ghp_attacker',// must be dropped
        'log_db_version'     => '99',          // must be dropped
        'active_plugins'     => array( 'evil/evil.php' ), // not ours at all
        'siteurl'            => 'https://evil.example',   // not ours at all
        'admin_email'        => 'evil@example.com',
        'seo_nested'         => array( 'a' => array( 'deep' => 1 ) ), // nested
    ),
);

$clean = DOS_Module_Utilities::filter_import( $hostile );

check( 'keeps recognised settings', 'newhandle' === ( $clean['seo_twitter'] ?? null ) );
check( '  and module toggles', 1 === ( $clean['enable_images'] ?? null ) );
check( 'drops an access token', ! array_key_exists( 'github_token', $clean ) );
check( 'drops the log schema version', ! array_key_exists( 'log_db_version', $clean ) );
check( 'drops active_plugins', ! array_key_exists( 'active_plugins', $clean ) );
check( 'drops siteurl', ! array_key_exists( 'siteurl', $clean ) );
check( 'drops admin_email', ! array_key_exists( 'admin_email', $clean ) );
check( 'drops a nested structure even under an allowed prefix', ! array_key_exists( 'seo_nested', $clean ), json_encode( $clean ) );
check( 'keeps nothing else', 2 === count( $clean ), json_encode( array_keys( $clean ) ) );

echo "\n--- import shapes ---\n";

check( 'accepts a bare settings map', 1 === count( DOS_Module_Utilities::filter_import( array( 'seo_twitter' => 'x' ) ) ) );
check( 'rejects a non-array payload', array() === DOS_Module_Utilities::filter_import( 'nope' ) );
check( 'rejects null', array() === DOS_Module_Utilities::filter_import( null ) );
check( 'survives an empty payload', array() === DOS_Module_Utilities::filter_import( array() ) );
check( 'drops numeric keys', array() === DOS_Module_Utilities::filter_import( array( 0 => 'x', 1 => 'y' ) ) );
check( 'allows a flat array value', array( 'seo_list' => array( 1, 2 ) ) === DOS_Module_Utilities::filter_import( array( 'seo_list' => array( 1, 2 ) ) ) );

$round = DOS_Module_Utilities::filter_import( array( 'settings' => DOS_Module_Utilities::portable_settings() ) );
check( 'an export round-trips through import intact', $round === DOS_Module_Utilities::portable_settings(), json_encode( $round ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
