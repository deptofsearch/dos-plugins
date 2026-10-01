<?php
/**
 * Each plugin ships its own copy of the shared updater, because a plugin ZIP
 * can only hold its own files. That is only safe while the copies are
 * identical: the first plugin to load defines the class for all of them, so a
 * copy that has drifted would be silently ignored, or worse, win.
 *
 * Edit shared/class-dos-github-updater.php, then copy it into each plugin.
 */

$root   = dirname( __DIR__ );
$shared = $root . '/shared/class-dos-github-updater.php';
$pass   = 0;
$fail   = 0;

function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

check( 'the shared updater exists', is_file( $shared ) );

$copies = glob( $root . '/plugins/*/includes/class-dos-github-updater.php' );
$source = is_file( $shared ) ? file_get_contents( $shared ) : '';

foreach ( $copies as $copy ) {
	$rel = substr( $copy, strlen( $root ) + 1 );
	check( $rel . ' is identical to shared/', file_get_contents( $copy ) === $source, 'run: cp shared/class-dos-github-updater.php ' . $rel );
}

echo "\n" . count( $copies ) . " copies checked\n";
echo "$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
