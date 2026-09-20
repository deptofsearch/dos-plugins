<?php
/**
 * Moving between posts in the editor. What is pinned is the position logic:
 * the arrows must follow the list somebody arrived from, and stop at its ends
 * rather than wrapping or pointing at nothing.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options'] = array();
$GLOBALS['meta']    = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function add_action() {} function add_filter() {}
function get_current_user_id() { return 1; }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['meta'][ $k ] ?? ''; }
function update_user_meta( $u, $k, $v ) { $GLOBALS['meta'][ $k ] = $v; return true; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function add_query_arg( $k, $v = null, $u = null ) { return ( $u ? $u : 'x' ) . '&' . $k . '=' . $v; }
function current_user_can( $c, $id = 0 ) { return empty( $GLOBALS['blocked'] ) || $id !== $GLOBALS['blocked']; }
function esc_url_raw( $u ) { return $u; }

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/modules/utilities/class-dos-editor-nav.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}
function remember( array $ids, $filtered = false ) {
    $GLOBALS['meta']['_dos_editor_list'] = array(
        'ids' => $ids, 'post_type' => 'page', 'url' => 'https://example.com/wp-admin/edit.php?post_type=page',
        'filtered' => $filtered, 'captured' => time(),
    );
}

echo "--- position within the remembered list ---\n";
remember( array( 10, 11, 12, 13 ) );

$p = DOS_Editor_Nav::place( 11 );
check( 'knows where it is', 2 === $p['position'] && 4 === $p['total'], json_encode( $p ) );
check( '  and what comes before', 10 === $p['previous'] );
check( '  and after', 12 === $p['next'] );

$first = DOS_Editor_Nav::place( 10 );
check( 'the first has nothing before it', 0 === $first['previous'] );
check( '  but does have a next', 11 === $first['next'] );

$last = DOS_Editor_Nav::place( 13 );
check( 'the last has nothing after it', 0 === $last['next'] );
check( '  rather than wrapping to the start', 12 === $last['previous'] );

check( 'a post outside the list gets no arrows at all', null === DOS_Editor_Nav::place( 99 ) );

$GLOBALS['meta'] = array();
check( 'no remembered list means no arrows', null === DOS_Editor_Nav::place( 10 ) );

echo "\n--- order follows the list, not the post IDs ---\n";
// A list sorted by title puts them in an order the IDs do not imply.
remember( array( 13, 10, 12, 11 ) );
$p = DOS_Editor_Nav::place( 10 );
check( 'next is the next in the list', 12 === $p['next'], json_encode( $p ) );
check( 'previous is the previous in the list', 13 === $p['previous'] );

echo "\n--- a filtered list says so ---\n";
remember( array( 10, 11 ), true );
check( 'filtered is carried through', true === DOS_Editor_Nav::place( 10 )['filtered'] );
remember( array( 10, 11 ), false );
check( '  and an unfiltered one is not claimed to be', false === DOS_Editor_Nav::place( 10 )['filtered'] );

echo "\n--- update and open the next ---\n";
remember( array( 10, 11, 12 ) );
$_POST = array();
check( 'an ordinary save goes where it always did', 'original' === DOS_Editor_Nav::redirect_after_save( 'original', 10 ) );

$_POST['dos_save_next'] = '1';
$to = DOS_Editor_Nav::redirect_after_save( 'original', 10 );
check( 'asking for the next one goes there', false !== strpos( $to, 'post=11' ), $to );

$to = DOS_Editor_Nav::redirect_after_save( 'original', 12 );
check( 'the last one stays put rather than going nowhere', 'original' === $to, $to );

$GLOBALS['blocked'] = 11;
check( 'never sends anyone to a post they cannot edit', 'original' === DOS_Editor_Nav::redirect_after_save( 'original', 10 ) );
unset( $GLOBALS['blocked'] );

$GLOBALS['meta'] = array();
check( 'with no remembered list it stays put', 'original' === DOS_Editor_Nav::redirect_after_save( 'original', 10 ) );

echo "\n--- the setting ---\n";
check( 'on by default', DOS_Editor_Nav::is_enabled() );
DOS_Settings::set( 'utilities_editor_nav', 0 );
check( 'can be switched off', ! DOS_Editor_Nav::is_enabled() );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
