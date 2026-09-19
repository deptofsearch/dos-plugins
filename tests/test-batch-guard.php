<?php
define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['options'] = array();
$GLOBALS['log']     = array();
$GLOBALS['writes']  = 0;   // stands in for rows a destructive job would delete

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return $s; } function esc_html( $s ) { return $s; }
function add_action() {} function add_filter() {} function do_action() {}
function apply_filters( $t, $v ) { return $v; }
function current_user_can() { return true; }
function get_current_user_id() { return 1; }
function current_time() { return date( 'Y-m-d H:i:s' ); }
function human_time_diff( $a, $b = 0 ) { return '1 minute'; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function check_ajax_referer() { return true; }   // assume the attacker has a valid nonce
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : array() ); }
function wp_create_nonce() { return 'x'; }
function wp_enqueue_style() {} function wp_enqueue_script() {} function wp_localize_script() {}
function plugin_dir_url() { return ''; }

class JsonHalt extends Exception { public $payload; public $ok;
    public function __construct( $ok, $payload, $code = 200 ) { $this->ok = $ok; $this->payload = $payload; parent::__construct( 'halt', $code ); } }
function wp_send_json_error( $d, $c = 200 ) { throw new JsonHalt( false, $d, $c ); }
function wp_send_json_success( $d, $c = 200 ) { throw new JsonHalt( true, $d, $c ); }

class DOS_Log {
    public static function add( $m, $a, $msg = '', $o = 0, $dry = false ) { $GLOBALS['log'][] = $a; }
    public static function prune() {}
}
class DOS_Toolkit { public static function modules() { return array(); } public static function is_active( $k ) { return false; } }

define( 'DOS_TOOLKIT_VERSION', 'test' );
define( 'DOS_TOOLKIT_URL', '' );
require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/includes/class-dos-batch.php';

DOS_Batch::register( 'media_delete', array(
    'label'       => 'Delete unused media',
    'module'      => 'images',
    'destructive' => true,
    'batch_size'  => 10,
    'count'       => function () { return 10; },
    'step'        => function ( $offset, $size, $dry_run ) {
        if ( ! $dry_run ) { $GLOBALS['writes'] += 10; }   // live run destroys
        return array( 'processed' => $offset >= 10 ? 0 : 10, 'changed' => 10, 'notes' => array() );
    },
) );

function attempt( array $post ) {
    $_POST = $post;
    try { DOS_Batch::handle_step(); }
    catch ( JsonHalt $h ) { return array( 'ok' => $h->ok, 'code' => $h->getCode(), 'data' => $h->payload ); }
    return array( 'ok' => null, 'code' => 0, 'data' => null );
}

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

echo "--- forged requests, valid nonce, admin capability ---\n";

$r = attempt( array( 'job' => 'media_delete', 'restart' => '1' ) );   // live, no confirm
check( 'live run with no confirmation is refused', false === $r['ok'] && 403 === $r['code'], json_encode( $r ) );
check( '  nothing was destroyed', 0 === $GLOBALS['writes'], 'writes=' . $GLOBALS['writes'] );

$r = attempt( array( 'job' => 'media_delete', 'restart' => '1', 'confirm' => 'RUN' ) );  // no dry run yet
check( 'live run with confirmation but no dry run is refused', false === $r['ok'] && 403 === $r['code'], json_encode( $r ) );
check( '  nothing was destroyed', 0 === $GLOBALS['writes'] );

$r = attempt( array( 'job' => 'media_delete', 'restart' => '1', 'confirm' => 'run' ) );  // wrong case
check( 'confirmation is case-sensitive', false === $r['ok'], json_encode( $r ) );

echo "\n--- forged continuation: start a dry run, then drop dry_run mid-run ---\n";
attempt( array( 'job' => 'media_delete', 'restart' => '1', 'dry_run' => '1' ) );
$r = attempt( array( 'job' => 'media_delete' ) );   // no dry_run field at all
check( 'continuation cannot flip a dry run to live', true === $r['ok'] && true === $r['data']['dryRun'], json_encode( $r['data'] ) );
check( '  nothing was destroyed', 0 === $GLOBALS['writes'], 'writes=' . $GLOBALS['writes'] );

echo "\n--- the intended path ---\n";
attempt( array( 'job' => 'media_delete', 'restart' => '1', 'dry_run' => '1' ) );
$r = attempt( array( 'job' => 'media_delete' ) );
check( 'dry run completes', true === $r['ok'] && true === $r['data']['done'], json_encode( $r['data'] ) );
check( '  a receipt was written', null !== DOS_Batch::receipt( 'media_delete' ) );
check( '  still nothing destroyed', 0 === $GLOBALS['writes'] );

$r = attempt( array( 'job' => 'media_delete', 'restart' => '1', 'confirm' => 'RUN' ) );
check( 'live run is now allowed', true === $r['ok'], json_encode( $r ) );
attempt( array( 'job' => 'media_delete' ) );
check( '  the live run did its work', 20 === $GLOBALS['writes'], 'writes=' . $GLOBALS['writes'] );
check( '  a live start was logged distinctly', in_array( 'batch_live_start', $GLOBALS['log'], true ) );

echo "\n--- receipt is single-use and expires ---\n";
$r = attempt( array( 'job' => 'media_delete', 'restart' => '1', 'confirm' => 'RUN' ) );
check( 'a second live run needs a fresh dry run', false === $r['ok'] && 403 === $r['code'], json_encode( $r ) );

attempt( array( 'job' => 'media_delete', 'restart' => '1', 'dry_run' => '1' ) );
attempt( array( 'job' => 'media_delete' ) );
$stale = get_option( 'dos_batch_receipt_media_delete' );
$stale['at'] = time() - ( 25 * 3600 );
update_option( 'dos_batch_receipt_media_delete', $stale );
$r = attempt( array( 'job' => 'media_delete', 'restart' => '1', 'confirm' => 'RUN' ) );
check( 'a dry run older than 24h is refused', false === $r['ok'] && 403 === $r['code'], json_encode( $r ) );

echo "\n--- a non-destructive job is unaffected ---\n";
DOS_Batch::register( 'alt_audit', array(
    'label' => 'Audit alt text', 'module' => 'images', 'destructive' => false, 'batch_size' => 10,
    'count' => function () { return 10; },
    'step'  => function ( $o, $s, $d ) { return array( 'processed' => $o >= 10 ? 0 : 10, 'changed' => 3, 'notes' => array() ); },
) );
$r = attempt( array( 'job' => 'alt_audit', 'restart' => '1' ) );
check( 'ordinary live job runs with no confirmation', true === $r['ok'], json_encode( $r ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
