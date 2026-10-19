<?php
/**
 * DoS SAAB Toolkit: the main file boots on its own, and stands down (no fatal) while the old saab-toolkit is active.
 *
 * 0.6.0 guarded with class_exists( 'SAAB_Toolkit' ) alone. PHP declares the file's own SAAB_Toolkit before its first
 * line runs, so the guard was always true and the plugin stood down on every request: the restaurant post type was
 * never registered and every restaurant page was a 404. Each scenario runs in its own PHP process, because the
 * second one declares the class twice on purpose.
 */

$root = dirname( __DIR__ );
$main = $root . '/plugins/dos-saab-toolkit/dos-saab-toolkit.php';

if ( isset( $argv[1] ) && 'child' === $argv[1] ) {
	// Child process: stub just enough WordPress, optionally load an "old plugin" first, then the main file.
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	$GLOBALS['hooks'] = array();
	function add_action( $hook, $cb ) {
		if ( is_array( $cb ) ) {
			$name = ( is_object( $cb[0] ) ? get_class( $cb[0] ) : $cb[0] ) . '::' . $cb[1];
		} else {
			$name = is_string( $cb ) ? $cb : 'closure';
		}
		$GLOBALS['hooks'][] = $hook . ':' . $name;
	}
	function add_filter( $hook, $cb ) { add_action( $hook, $cb ); }
	function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
	function plugin_dir_url( $f ) { return 'https://example.com/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }
	function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
	function is_admin() { return false; }
	function get_option( $k, $d = false ) { return $d; }
	if ( 'old' === ( $argv[2] ?? '' ) ) {
		$old = sys_get_temp_dir() . '/saab-toolkit-old-' . getmypid() . '.php';
		file_put_contents( $old, "<?php\nclass SAAB_Toolkit { public static function boot() {} }\n" );
		require $old;
		unlink( $old );
	}
	require $argv[3];
	echo json_encode( array( 'hooks' => $GLOBALS['hooks'], 'version' => defined( 'SAAB_TOOLKIT_VERSION' ) ? SAAB_TOOLKIT_VERSION : null ) );
	exit( 0 );
}

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

function run_child( $mode, $main ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' child ' . escapeshellarg( $mode ) . ' ' . escapeshellarg( $main ) . ' 2>&1';
	exec( $cmd, $out, $code );
	return array( $code, implode( "\n", $out ) );
}

echo "--- on its own ---\n";
list( $code, $out ) = run_child( 'alone', $main );
$r = json_decode( $out, true );
check( 'loads without an error', 0 === $code && is_array( $r ), $out );
check( 'boots: SAAB_Toolkit::boot is hooked to plugins_loaded', is_array( $r ) && in_array( 'plugins_loaded:SAAB_Toolkit::boot', $r['hooks'], true ), $out );
check( 'defines its version', is_array( $r ) && null !== $r['version'] );
check( 'shows no "waiting" notice', is_array( $r ) && ! in_array( 'admin_notices:closure', $r['hooks'], true ), $out );

echo "--- with the old saab-toolkit already loaded ---\n";
list( $code, $out ) = run_child( 'old', $main );
$r = json_decode( $out, true );
check( 'no "cannot declare class" fatal', 0 === $code && is_array( $r ), $out );
check( 'stands down: does not hook boot', is_array( $r ) && ! in_array( 'plugins_loaded:SAAB_Toolkit::boot', $r['hooks'], true ), $out );
check( 'shows the "waiting" notice', is_array( $r ) && in_array( 'admin_notices:closure', $r['hooks'], true ), $out );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
