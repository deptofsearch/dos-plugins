<?php
/**
 * DoS SAAB Toolkit: the main file boots on its own, and stands down (no fatal) while the old saab-toolkit is active,
 * in either load order.
 *
 * 0.6.0 guarded with class_exists( 'SAAB_Toolkit' ) alone. PHP declares the file's own SAAB_Toolkit before its first
 * line runs, so the guard was always true and the plugin stood down on every request: the restaurant post type was
 * never registered and every restaurant page was a 404. Up to 0.7.2 the check covered only the old copy loading first,
 * which happens only during the click on Activate; on every later request WordPress loads dos-saab-toolkit first
 * (alphabetical), and the old copy's "cannot declare class" took shotandabeer.com down. Each scenario runs in its own
 * PHP process, because some declare the class twice on purpose.
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
	function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
	function is_multisite() { return false; }
	function get_site_option( $k, $d = false ) { return $d; }

	// A plugins folder holding the old copy, under whatever folder name the scenario gives it.
	$mode = $argv[2] ?? '';
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/saab-boot-' . getmypid() );
	$GLOBALS['active_plugins'] = array( 'dos-saab-toolkit/dos-saab-toolkit.php' );
	$old_rel = null;
	if ( in_array( $mode, array( 'old', 'old-after', 'old-renamed-folder' ), true ) ) {
		$old_rel = ( 'old-renamed-folder' === $mode ? 'saab-toolkit-0.5.6' : 'saab-toolkit' ) . '/saab-toolkit.php';
		@mkdir( dirname( WP_PLUGIN_DIR . '/' . $old_rel ), 0777, true );
		file_put_contents( WP_PLUGIN_DIR . '/' . $old_rel, "<?php\nclass SAAB_Toolkit { public static function boot() {} }\n" );
		$GLOBALS['active_plugins'][] = $old_rel;
	} elseif ( 'old-missing' === $mode ) {
		// Still listed active, but the folder was renamed to get the site back up.
		$GLOBALS['active_plugins'][] = 'saab-toolkit/saab-toolkit.php';
	}
	function get_option( $k, $d = false ) { return 'active_plugins' === $k ? $GLOBALS['active_plugins'] : $d; }

	if ( 'old' === $mode ) {
		require WP_PLUGIN_DIR . '/' . $old_rel;
	}
	require $argv[3];
	if ( in_array( $mode, array( 'old-after', 'old-renamed-folder' ), true ) ) {
		// What WordPress does on a normal request: the old copy loads after this one.
		require WP_PLUGIN_DIR . '/' . $old_rel;
	}
	if ( $old_rel ) {
		unlink( WP_PLUGIN_DIR . '/' . $old_rel );
		rmdir( dirname( WP_PLUGIN_DIR . '/' . $old_rel ) );
		rmdir( WP_PLUGIN_DIR );
	}
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

foreach ( array(
	'old-after'          => 'with the old saab-toolkit loading after it (every request after Activate)',
	'old-renamed-folder' => 'with the old copy in a renamed folder, loading after it',
) as $mode => $title ) {
	echo "--- $title ---\n";
	list( $code, $out ) = run_child( $mode, $main );
	$r = json_decode( $out, true );
	check( 'no "cannot declare class" fatal', 0 === $code && is_array( $r ), $out );
	check( 'stands down: does not hook boot', is_array( $r ) && ! in_array( 'plugins_loaded:SAAB_Toolkit::boot', $r['hooks'], true ), $out );
	check( 'shows the "waiting" notice', is_array( $r ) && in_array( 'admin_notices:closure', $r['hooks'], true ), $out );
}

echo "--- old copy still listed active, but its folder was renamed away ---\n";
list( $code, $out ) = run_child( 'old-missing', $main );
$r = json_decode( $out, true );
check( 'loads without an error', 0 === $code && is_array( $r ), $out );
check( 'boots: a missing old copy does not keep it stood down', is_array( $r ) && in_array( 'plugins_loaded:SAAB_Toolkit::boot', $r['hooks'], true ), $out );
check( 'shows no "waiting" notice', is_array( $r ) && ! in_array( 'admin_notices:closure', $r['hooks'], true ), $out );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
