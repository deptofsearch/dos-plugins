<?php
/**
 * OSN\State_Cities::data() and flush(): a venue change marks its own state only, an out-of-date copy is served while a
 * cron job rebuilds it, and a request builds in line only with no copy at all or when cron is not running.
 */

namespace OSN {
	// Unqualified time() inside the plugin's namespace resolves here: the tests own the clock.
	function time() { return $GLOBALS['osn_now']; }
}

namespace {

require __DIR__ . '/wp-stubs-osn.php';
osn_load();
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $cb ) { return true; } }
require_once dirname( __DIR__ ) . '/plugins/dos-outdoor-seating/includes/class-state-cities.php';

use OSN\State_Cities;

const T0 = 1800000000;

function sd_reset() {
	osn_reset();
	$GLOBALS['osn']['cron']  = array();
	$GLOBALS['osn']['locks'] = array();
	$GLOBALS['osn_now']      = T0;
	$GLOBALS['sd_builds']    = array();
	State_Cities::flush_memo();
	State_Cities::$builder = function ( $code ) {
		$GLOBALS['sd_builds'][] = $code;
		return array( 'n' => count( $GLOBALS['sd_builds'] ), 'code' => $code );
	};
}
function sd_builds() { return implode( ',', $GLOBALS['sd_builds'] ); }
function sd_at( $secs ) { $GLOBALS['osn_now'] = T0 + $secs; State_Cities::flush_memo(); } // A later request.
function sd_job( $code ) { return wp_next_scheduled( State_Cities::CRON, array( $code ) ); }
function sd_fl( $code ) { return $GLOBALS['osn']['options'][ 'osn_state_flush_' . $code ] ?? null; }
function sd_opts() { return array_keys( $GLOBALS['osn']['options'] ); }
function sd_term( $id, $name, $state = '' ) {
	$GLOBALS['osn']['terms'][ $id ] = (object) array( 'term_id' => $id, 'name' => $name );
	if ( '' !== $state ) {
		$GLOBALS['osn']['term_meta'][ $id ]['osn_state'] = $state;
	}
}

echo "--- no copy: build in the request, then serve it ---\n";
sd_reset();
$d = State_Cities::data( 'WA' );
check( 'the first read builds', 'WA' === sd_builds() && 1 === $d['n'] );
check( 'the copy records when it was built', T0 === $GLOBALS['osn']['transients']['osn_state_WA']['b'] );
sd_at( 60 );
State_Cities::data( 'WA' );
check( 'a fresh copy is served without building or scheduling', 'WA' === sd_builds() && false === sd_job( 'WA' ) );

echo "\n--- a venue change marks its own state only ---\n";
sd_reset();
sd_term( 11, 'Seattle, WA', 'WA' );
sd_term( 12, 'Portland, OR' ); // No osn_state meta: the state comes from the name.
State_Cities::data( 'WA' );
State_Cities::data( 'OR' );
State_Cities::data( 'CA' );
sd_at( 100 );
State_Cities::flush( 11 );
check( 'flush( term ) writes that state\'s own option only', array( 'osn_state_flush_WA' ) === sd_opts() && T0 + 100 === sd_fl( 'WA' ), json_encode( $GLOBALS['osn']['options'] ) );
sd_at( 200 );
$wa = State_Cities::data( 'WA' );
State_Cities::data( 'OR' );
State_Cities::data( 'CA' );
check( 'nothing is rebuilt in the request', 'WA,OR,CA' === sd_builds(), sd_builds() );
check( 'the flushed state serves its old copy', 1 === $wa['n'] );
check( 'only the flushed state gets a rebuild job', false !== sd_job( 'WA' ) && false === sd_job( 'OR' ) && false === sd_job( 'CA' ) );
check( 'the job is no sooner than 15 minutes after the last build', T0 + 900 === sd_job( 'WA' ), (string) sd_job( 'WA' ) );
State_Cities::flush( 12 );
check( 'a term with no osn_state meta resolves from ", ST" in its name', null !== sd_fl( 'OR' ) && null === sd_fl( 'all' ) );
State_Cities::flush( 'ca' );
check( 'a state code is accepted in any case', null !== sd_fl( 'CA' ) );

echo "\n--- flush of everything ---\n";
sd_reset();
sd_term( 13, 'Somewhere' ); // State cannot be told.
State_Cities::data( 'WA' );
State_Cities::data( 'OR' );
sd_at( 50 );
State_Cities::flush( 13 );
check( 'a term whose state is unknown marks every state', T0 + 50 === sd_fl( 'all' ) );
sd_at( 60 );
State_Cities::flush();
check( 'flush() with no argument marks every state', T0 + 60 === sd_fl( 'all' ) );
State_Cities::data( 'WA' );
State_Cities::data( 'OR' );
check( 'both states are served stale and scheduled', 'WA,OR' === sd_builds() && sd_job( 'WA' ) && sd_job( 'OR' ) );
State_Cities::flush( 'WA' );
check( 'after a flush of everything, a state flush in the same second writes nothing', null === sd_fl( 'WA' ) );

echo "\n--- at most one write per state per second, and never a lost flush ---\n";
sd_reset();
State_Cities::flush( 'WA' );
$GLOBALS['osn']['options']['osn_state_flush_WA'] = 5;
State_Cities::flush( 'WA' );
check( 'a second flush of the same state in the same second does not write again', 5 === sd_fl( 'WA' ) );
State_Cities::flush( 'OR' );
check( 'another state still writes', T0 === sd_fl( 'OR' ) );
$GLOBALS['osn_now'] = T0 + 20; // Same request, 20 seconds on (a long batch); a cron rebuild landed in between.
State_Cities::flush( 'WA' );
check( 'a later flush in the same long request is written', T0 + 20 === sd_fl( 'WA' ) );

echo "\n--- a venue saved while the queries run ---\n";
sd_reset();
State_Cities::$builder = function ( $code ) {
	$GLOBALS['sd_builds'][] = $code;
	$GLOBALS['osn_now']    += 2; // Two seconds into a five-second build, another request saves a venue.
	State_Cities::flush( $code );
	$GLOBALS['osn_now']    += 3;
	return array( 'n' => count( $GLOBALS['sd_builds'] ) );
};
State_Cities::data( 'WA' );
check( 'the copy is stamped with the time the build started', T0 === $GLOBALS['osn']['transients']['osn_state_WA']['b'] );
sd_at( 30 );
State_Cities::data( 'WA' );
check( 'so the flush during the build still schedules a rebuild', false !== sd_job( 'WA' ) );
sd_reset();
State_Cities::data( 'WA' );
State_Cities::flush( 'WA' ); // Same second as the build.
sd_at( 1 );
State_Cities::data( 'WA' );
check( 'a flush in the same second as the build counts as newer', false !== sd_job( 'WA' ) );

echo "\n--- the cron job ---\n";
sd_reset();
State_Cities::data( 'WA' );
sd_at( 10 );
State_Cities::flush( 'WA' );
sd_at( 20 );
State_Cities::data( 'WA' );
sd_at( 900 );
unset( $GLOBALS['osn']['cron'][ State_Cities::CRON . '|["WA"]' ] ); // WP removes the event before it runs the hook.
State_Cities::cron_rebuild( 'WA' );
check( 'the job rebuilds the state', 'WA,WA' === sd_builds() );
sd_at( 910 );
$d = State_Cities::data( 'WA' );
check( 'the next read gets the new copy with no build and no job', 2 === $d['n'] && 'WA,WA' === sd_builds() && false === sd_job( 'WA' ) );
State_Cities::cron_rebuild( 'ZZ' );
State_Cities::cron_rebuild( '' );
check( 'the job ignores a code that is not a state', 'WA,WA' === sd_builds() );
$GLOBALS['osn']['locks'] = array();
State_Cities::cron_rebuild( 'wa' );
$GLOBALS['osn_now'] += 5;
State_Cities::cron_rebuild( 'WA' );
check( 'a job that finds a copy younger than 15 minutes is a duplicate and does nothing', 'WA,WA' === sd_builds(), sd_builds() );
sd_at( 2000 );
State_Cities::cron_rebuild( 'wa' );
check( 'a job for an old enough copy builds', 'WA,WA,WA' === sd_builds(), sd_builds() );
sd_at( 3000 );
State_Cities::cron_rebuild( 'WA' ); // The lock from T0 + 2000 is still in the stub cache.
check( 'and a job that cannot take the lock does not', 'WA,WA,WA' === sd_builds(), sd_builds() );

echo "\n--- a long import: one rebuild per 15 minutes ---\n";
sd_reset();
State_Cities::data( 'WA' );
for ( $t = 30; $t <= 3600; $t += 30 ) {
	sd_at( $t );
	$GLOBALS['osn']['locks'] = array(); // The lock is two minutes; jobs here are 15 apart.
	State_Cities::flush( 'WA' );        // A venue saved every 30 seconds for an hour...
	State_Cities::data( 'WA' );         // ...and a visitor every 30 seconds.
	$job = sd_job( 'WA' );
	if ( $job && $job <= $GLOBALS['osn_now'] ) { // Cron fires what is due.
		unset( $GLOBALS['osn']['cron'][ State_Cities::CRON . '|["WA"]' ] );
		State_Cities::cron_rebuild( 'WA' );
	}
}
check( 'an hour of changes and visits costs the first build plus 4 background rebuilds', 5 === count( $GLOBALS['sd_builds'] ), (string) count( $GLOBALS['sd_builds'] ) );

echo "\n--- age alone ---\n";
sd_reset();
State_Cities::data( 'WA' );
sd_at( 12 * 3600 - 1 );
State_Cities::data( 'WA' );
check( 'just under 12 hours old with no flush is fresh', false === sd_job( 'WA' ) );
sd_at( 12 * 3600 );
$d = State_Cities::data( 'WA' );
check( '12 hours old is served and scheduled for now', 1 === $d['n'] && T0 + 12 * 3600 === sd_job( 'WA' ) );

echo "\n--- cron is not running ---\n";
sd_reset();
State_Cities::data( 'WA' );
sd_at( 10 );
State_Cities::flush( 'WA' );
State_Cities::data( 'WA' ); // Job at T0 + 900.
sd_at( 900 + 599 );
$d = State_Cities::data( 'WA' );
check( 'a job under 10 minutes late: still the old copy', 1 === $d['n'] && 'WA' === sd_builds() );
sd_at( 900 + 600 );
$GLOBALS['osn']['locks']['osn:osn_state_lock_WA'] = 1;
$d = State_Cities::data( 'WA' );
check( '10 minutes late but another request holds the lock: the old copy', 1 === $d['n'] && 'WA' === sd_builds() );
$GLOBALS['osn']['locks'] = array();
$d = State_Cities::data( 'WA' );
check( '10 minutes late and the lock is free: built in the request', 2 === $d['n'] && 'WA,WA' === sd_builds() );
check( 'and the dead job is cleared', false === sd_job( 'WA' ) );
sd_at( 900 + 660 );
State_Cities::data( 'WA' );
check( 'after which the copy is fresh', 'WA,WA' === sd_builds() && false === sd_job( 'WA' ) );
$GLOBALS['osn']['locks'] = array(); // No shared object cache: the lock never holds across requests.
$GLOBALS['osn']['cron'][ State_Cities::CRON . '|["WA"]' ] = T0 + 100; // A job a visitor scheduled during that build, never run.
sd_at( 900 + 700 );
State_Cities::flush( 'WA' );
$d = State_Cities::data( 'WA' );
check( 'a leftover dead job does not cause a rebuild inside 15 minutes of the last one', 2 === $d['n'] && 'WA,WA' === sd_builds() );
check( 'it is cleared instead', false === sd_job( 'WA' ) );
State_Cities::data( 'WA' );
check( 'and the next read schedules a new job for 15 minutes after the last build', T0 + 1500 + 900 === sd_job( 'WA' ), (string) sd_job( 'WA' ) );

echo "\n--- the job cannot be scheduled ---\n";
sd_reset();
State_Cities::data( 'WA' );
sd_at( 10 );
State_Cities::flush( 'WA' );
$GLOBALS['osn']['cron_fails'] = true;
$d = State_Cities::data( 'WA' );
check( 'scheduling failed inside 15 minutes of the last build: the old copy', 1 === $d['n'] && 'WA' === sd_builds() );
sd_at( 900 );
$d = State_Cities::data( 'WA' );
check( 'at 15 minutes: built in the request under the lock', 2 === $d['n'] && 'WA,WA' === sd_builds() );
sd_at( 2000 );
State_Cities::flush( 'WA' );
$d = State_Cities::data( 'WA' );
check( 'and while the lock is held the old copy is served', 2 === $d['n'] && 'WA,WA' === sd_builds() );

echo "\n--- a build that fails ---\n";
sd_reset();
State_Cities::data( 'WA' );
sd_at( 1000 );
State_Cities::flush( 'WA' );
State_Cities::$builder = function ( $code ) { $GLOBALS['sd_builds'][] = $code; return null; }; // A query failed.
State_Cities::cron_rebuild( 'WA' );
$hit = $GLOBALS['osn']['transients']['osn_state_WA'];
check( 'the copy we had is kept, with its own build time', 1 === $hit['d']['n'] && T0 === $hit['b'] && T0 + 1000 === $hit['t'] );
sd_at( 1010 );
$d = State_Cities::data( 'WA' );
check( 'it is served, and the retry waits 15 minutes from the attempt', 1 === $d['n'] && T0 + 1900 === sd_job( 'WA' ), (string) sd_job( 'WA' ) );
State_Cities::cron_rebuild( 'WA' );
check( 'a job before then does nothing', 'WA,WA' === sd_builds() );
State_Cities::$builder = function ( $code ) { $GLOBALS['sd_builds'][] = $code; return array(); };
sd_at( 1900 );
$GLOBALS['osn']['locks'] = array();
State_Cities::cron_rebuild( 'WA' );
check( 'an empty result is a real answer (a state with no venues) and is stored', array() === $GLOBALS['osn']['transients']['osn_state_WA']['d'] && T0 + 1900 === $GLOBALS['osn']['transients']['osn_state_WA']['b'] );

sd_reset();
State_Cities::$builder = function ( $code ) { $GLOBALS['sd_builds'][] = $code; return null; };
$d   = State_Cities::data( 'WA' );
$hit = $GLOBALS['osn']['transients']['osn_state_WA'];
check( 'a failed build with no copy to keep stores an empty one with no build time', array() === $d && 0 === $hit['b'] && T0 === $hit['t'] );
sd_at( 5 );
State_Cities::data( 'WA' );
check( 'which is out of date at once, and retried in 15 minutes rather than on every view', 'WA' === sd_builds() && T0 + 900 === sd_job( 'WA' ) );

echo "\n--- copies from before 0.6.13 ---\n";
sd_reset();
$GLOBALS['osn']['options']['osn_state_data_ver']  = 37; // The old counter: no longer read.
$GLOBALS['osn']['transients']['osn_state_WA'] = array( 'v' => 37, 'd' => array( 'old' => true ) );
$d = State_Cities::data( 'WA' );
check( 'an old copy is served, not rebuilt in the request', array( 'old' => true ) === $d && '' === sd_builds() );
check( 'and a rebuild is scheduled for now', T0 === sd_job( 'WA' ) );
unset( $GLOBALS['osn']['cron'][ State_Cities::CRON . '|["WA"]' ] );
State_Cities::cron_rebuild( 'WA' );
check( 'which the job carries out', 'WA' === sd_builds() );
$GLOBALS['osn']['transients']['osn_state_OR'] = 'garbage';
State_Cities::data( 'OR' );
check( 'a value that is not a copy at all is rebuilt in the request', 'WA,OR' === sd_builds() );

echo "\n--- wiring ---\n";
sd_reset();
sd_term( 11, 'Seattle, WA', 'WA' );
\OSN\Util::purge_sorted( 11 );
check( 'Util::purge_sorted() flushes the city\'s state, not every state', array( 'osn_state_flush_WA' ) === sd_opts(), json_encode( sd_opts() ) );
sd_reset();
State_Cities::hooks();
$cb = $GLOBALS['actions']['osn_city_venues_changed'][10][0] ?? null;
check( 'osn_city_venues_changed is hooked', is_callable( $cb ) );
call_user_func( $cb, 11 ); // WordPress passes one argument (the term ID) at the default accepted_args.
sd_term( 11, 'Seattle, WA', 'WA' );
$GLOBALS['osn_now']++;
call_user_func( $cb, 11 );
check( 'and marks the state of the term it is given', T0 + 1 === sd_fl( 'WA' ) );
$cb = $GLOBALS['actions'][ State_Cities::CRON ][10][0] ?? null;
check( 'the cron hook is registered', is_callable( $cb ) );
call_user_func( $cb, 'WA' );
check( 'and rebuilds', 'WA' === sd_builds() );

finish();

}
