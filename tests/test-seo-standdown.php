<?php
/**
 * The SEO module stands down for another SEO plugin — and only for the head
 * output. Enabling the module beside All in One SEO would otherwise print a
 * second description, canonical and schema graph on every page.
 *
 * A constant cannot be undefined, so each scenario runs in its own PHP
 * process. With a scenario name as the argument this file is the child: it
 * defines what that plugin defines, runs init(), and prints a JSON report.
 * With no argument it is the parent that drives and judges them.
 */
require __DIR__ . '/wp-stubs-seo.php';
require dirname( __DIR__ ) . '/plugins/dos-toolkit/includes/class-dos-conflicts.php';

$head_hooks = array( 'wp_head', 'wp_robots', 'pre_get_document_title', 'add_meta_boxes', 'save_post' );

if ( isset( $argv[1] ) ) {
    $scenario = $argv[1];

    if ( 'function:aioseo' === $scenario ) {
        // A build that never defines AIOSEO_VERSION, or defines it late.
        function aioseo() { return null; }
    } elseif ( 0 === strpos( $scenario, 'const:' ) ) {
        define( substr( $scenario, 6 ), '1.0' );
    }

    DOS_Module_SEO::init();

    echo json_encode( array(
        'conflict'    => DOS_Module_SEO::conflicting_plugin(),
        'hooks'       => array_merge( array_keys( $GLOBALS['actions'] ), array_keys( $GLOBALS['filters'] ) ),
        'jobs'        => array_keys( DOS_Module_SEO::jobs() ),
        'pages'       => array_column( DOS_Module_SEO::pages(), 'slug' ),
        'third_party' => array_keys( DOS_Conflicts::active_third_party() ),
    ) );
    exit( 0 );
}

$pass = 0;
$fail = 0;

function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

function run_scenario( $scenario ) {
    $cmd    = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1';
    $out    = shell_exec( $cmd );
    $report = json_decode( (string) $out, true );

    return is_array( $report ) ? $report : array( 'error' => $out );
}

echo "--- no other SEO plugin ---\n";
$r = run_scenario( 'none' );
check( 'no conflict is reported', '' === ( $r['conflict'] ?? null ), json_encode( $r ) );
foreach ( $head_hooks as $hook ) {
    check( "  registers $hook", in_array( $hook, $r['hooks'] ?? array(), true ), json_encode( $r['hooks'] ?? null ) );
}

echo "\n--- All in One SEO ---\n";
foreach ( array( 'const:AIOSEO_VERSION' => 'AIOSEO_VERSION defined', 'function:aioseo' => 'aioseo() defined, no constant' ) as $scenario => $label ) {
    $r = run_scenario( $scenario );
    check( "$label: reported as a conflict", 'All in One SEO' === ( $r['conflict'] ?? null ), json_encode( $r ) );
    foreach ( $head_hooks as $hook ) {
        check( "  does not register $hook", ! in_array( $hook, $r['hooks'] ?? array( $hook ), true ), json_encode( $r['hooks'] ?? null ) );
    }
    check( '  the conflicts screen lists it too', in_array( 'AIOSEO_VERSION', $r['third_party'] ?? array(), true ), json_encode( $r['third_party'] ?? null ) );
}

echo "\n--- the screen and the import jobs survive a conflict ---\n";
// The usual order is import first, deactivate second, so the importer has to
// be there while the other plugin is still running.
$r = run_scenario( 'const:AIOSEO_VERSION' );
check( 'the module page is still registered', array( 'dos-seo' ) === ( $r['pages'] ?? null ), json_encode( $r['pages'] ?? null ) );
check( 'both import jobs are still registered', array( 'seo_import_yoast', 'seo_import_aioseo' ) === ( $r['jobs'] ?? null ), json_encode( $r['jobs'] ?? null ) );
check( 'the settings save handler still runs', in_array( 'admin_init', $r['hooks'] ?? array(), true ) );

$r = run_scenario( 'const:WPSEO_VERSION' );
check( 'the same holds beside Yoast', 'Yoast SEO' === ( $r['conflict'] ?? null ) && array( 'seo_import_yoast', 'seo_import_aioseo' ) === ( $r['jobs'] ?? null ), json_encode( $r ) );

echo "\n--- every plugin the conflicts screen lists is one the module stands down for ---\n";
// The two lists are kept by hand in two files. This is what stops them
// drifting apart again.
foreach ( DOS_Conflicts::third_party() as $constant => $entry ) {
    if ( 'seo' !== $entry['module'] ) { continue; }
    $r = run_scenario( 'const:' . $constant );
    check( "{$entry['name']} ($constant)", ( $r['conflict'] ?? '' ) === $entry['name'], json_encode( $r ) );
}

$r = run_scenario( 'const:THE_SEO_FRAMEWORK_VERSION' );
check( 'The SEO Framework registers no head output either', ! in_array( 'wp_head', $r['hooks'] ?? array( 'wp_head' ), true ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
