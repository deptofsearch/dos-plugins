<?php
/**
 * DoS - Agents: 301s from the old __trashed-NN slugs to the renamed city pages.
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

$fails = 0;
function os_check( $label, $cond ) {
	global $fails;
	if ( ! $cond ) {
		$fails++;
		echo "FAIL: $label\n";
	}
}

/** Runs the hook for this request; returns [ url, status ] or null when it did not redirect. */
function os_run( $uri, $is_404 = true ) {
	$GLOBALS['wp']['is_404']   = $is_404;
	$GLOBALS['wp']['redirect'] = null;
	$_SERVER['REQUEST_URI']    = $uri;
	try {
		dos_agents_old_slug_redirect();
	} catch ( RedirectHalt $e ) {
		return array( $e->url, $e->status );
	}
	return null;
}

// ---- the map ----------------------------------------------------------------------------------
$map = dos_agents_old_slug_map();
os_check( '343 entries', 343 === count( $map ) );
os_check( '343 distinct page IDs', 343 === count( array_unique( $map ) ) );
os_check( 'every key is __trashed or __trashed-N', count( preg_grep( '/^__trashed(-\d+)?$/', array_keys( $map ) ) ) === count( $map ) );
os_check( '__trashed-75 is Abernant AL (20581)', 20581 === ( $map['__trashed-75'] ?? 0 ) );
os_check( 'hook registered at priority 1', in_array( 'dos_agents_old_slug_redirect', $GLOBALS['actions']['template_redirect'][1] ?? array(), true ) );

// ---- renamed page -----------------------------------------------------------------------------
agents_add_page( 'in-abernant-al', '', 'publish', 20581 );
$r = os_run( '/__trashed-75/' );
os_check( 'renamed page: 301 to its new URL', array( 'https://example.com/in-abernant-al/', 301 ) === $r );
os_check( 'no trailing slash', array( 'https://example.com/in-abernant-al/', 301 ) === os_run( '/__trashed-75' ) );
os_check( 'query string ignored for matching', array( 'https://example.com/in-abernant-al/', 301 ) === os_run( '/__trashed-75/?nc=1' ) );
os_check( 'case folded', array( 'https://example.com/in-abernant-al/', 301 ) === os_run( '/__TRASHED-75/' ) );

// ---- no redirect ------------------------------------------------------------------------------
os_check( 'not a 404 (page still at old slug): nothing', null === os_run( '/__trashed-75/', false ) );
os_check( 'unknown __trashed number: nothing', null === os_run( '/__trashed-999999/' ) );
os_check( 'nested path: nothing', null === os_run( '/blog/__trashed-75/' ) );
os_check( 'other 404: nothing', null === os_run( '/in-nowhere-zz/' ) );
os_check( 'root: nothing', null === os_run( '/' ) );

// Still holding the old slug (rename not done yet, yet a 404 for some other reason): no loop.
agents_reset();
agents_add_page( '__trashed-75', '', 'publish', 20581 );
os_check( 'page still at old slug: no self-redirect', null === os_run( '/__trashed-75/' ) );

// Sent back to the trash or drafted: no redirect to a page that would 404 itself.
agents_reset();
agents_add_page( 'in-abernant-al', '', 'trash', 20581 );
os_check( 'trashed target: nothing', null === os_run( '/__trashed-75/' ) );
agents_reset();
agents_add_page( 'in-abernant-al', '', 'draft', 20581 );
os_check( 'draft target: nothing', null === os_run( '/__trashed-75/' ) );

// Target missing entirely.
agents_reset();
os_check( 'missing target: nothing', null === os_run( '/__trashed-75/' ) );

// A later slug change still lands, because the target is read from the ID.
agents_reset();
agents_add_page( 'in-abernant-al-2', '', 'publish', 20581 );
os_check( 'later slug change followed', array( 'https://example.com/in-abernant-al-2/', 301 ) === os_run( '/__trashed-75/' ) );

if ( $fails ) {
	echo "$fails failure(s)\n";
	exit( 1 );
}
echo "old slugs: all passed\n";
