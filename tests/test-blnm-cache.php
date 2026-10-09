<?php
/** BLNM cache purge: once per version, upgrade flag, capability and nonce checks on the button action. */

namespace BLNM {
	const VERSION     = '9.9.9';
	const PLUGIN_FILE = '/wp/plugins/dos-best-lenders/dos-best-lenders.php';
	class Rest { public static $flushed = 0; public static function flush_index() { self::$flushed++; } }
}

namespace {
	define( 'ABSPATH', '/tmp/' );
	$GLOBALS['opts'] = array(); $GLOBALS['actions'] = array(); $GLOBALS['can'] = true; $GLOBALS['nonce_ok'] = true; $GLOBALS['listeners'] = array();
	class Stop extends Exception {}
	function add_action( $h, $cb, $p = 10, $a = 1 ) {}
	function do_action( $h ) { $GLOBALS['actions'][] = $h; }
	function has_action( $h ) { return in_array( $h, $GLOBALS['listeners'], true ); }
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
	function plugin_basename( $f ) { return 'dos-best-lenders/dos-best-lenders.php'; }
	function current_user_can( $c ) { return $GLOBALS['can']; }
	function wp_die( $m ) { throw new Stop( 'die' ); }
	function check_admin_referer( $a ) { if ( ! $GLOBALS['nonce_ok'] ) { throw new Stop( 'nonce' ); } return 1; }
	function esc_html__( $s ) { return $s; }
	function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
	function add_query_arg( $k, $v, $u ) { return $u . '&' . $k . '=' . $v; }
	function wp_safe_redirect( $u ) { throw new Stop( 'redirect:' . $u ); }

	require dirname( __DIR__ ) . '/plugins/dos-best-lenders/includes/class-cache.php';
	use BLNM\Cache;

	$pass = 0; $fail = 0;
	function check( $label, $cond ) {
		global $pass, $fail;
		if ( $cond ) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; }
	}
	function purges() { return count( array_keys( $GLOBALS['actions'], 'litespeed_purge_all', true ) ); }

	check( 'first run purges', true === Cache::maybe_purge() && 1 === purges() );
	check( 'version recorded', '9.9.9' === get_option( 'blnm_purged_version' ) );
	check( 'transients flushed', 1 === BLNM\Rest::$flushed );
	check( 'second run does not purge', false === Cache::maybe_purge() && 1 === purges() );
	$GLOBALS['opts']['blnm_purged_version'] = '9.9.8';
	check( 'version change purges again, once', true === Cache::maybe_purge() && false === Cache::maybe_purge() && 2 === purges() );

	Cache::flag_after_upgrade( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'other/other.php' ) ) );
	check( 'other plugin upgrade ignored', false === Cache::maybe_purge() );
	Cache::flag_after_upgrade( null, array( 'type' => 'theme', 'action' => 'update', 'themes' => array( 'x' ) ) );
	check( 'theme upgrade ignored', false === Cache::maybe_purge() );
	Cache::flag_after_upgrade( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'dos-best-lenders/dos-best-lenders.php' ) ) );
	check( 'our upgrade sets flag and purges once', true === Cache::maybe_purge() && false === Cache::maybe_purge() && 3 === purges() );

	$GLOBALS['actions'] = array(); $GLOBALS['listeners'] = array( 'hostinger_clear_cache' );
	Cache::purge_all();
	check( 'hostinger hook fired only when listened to', in_array( 'hostinger_clear_cache', $GLOBALS['actions'], true ) && ! in_array( 'hostinger_purge_cache', $GLOBALS['actions'], true ) );

	$run = function () { try { Cache::handle_clear(); return 'none'; } catch ( Stop $e ) { return $e->getMessage(); } };
	$GLOBALS['actions'] = array();
	$GLOBALS['can'] = false;
	check( 'no capability: dies, no purge', 'die' === $run() && 0 === purges() );
	$GLOBALS['can'] = true; $GLOBALS['nonce_ok'] = false;
	check( 'bad nonce: rejected, no purge', 'nonce' === $run() && 0 === purges() );
	$GLOBALS['nonce_ok'] = true;
	$r = $run();
	check( 'valid request purges and redirects with notice flag', 1 === purges() && 0 === strpos( $r, 'redirect:' ) && false !== strpos( $r, 'blnm_cache_cleared=1' ) && false !== strpos( $r, 'page=dos-best-lenders' ) );

	echo "\n$pass passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}
