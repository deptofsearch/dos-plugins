<?php
/**
 * DoS - Agents: which filter chips are offered.
 *
 * A review tag nearly every agent has filters nothing; one almost none has filters to a lone
 * agent. Chips appear only between 5% and 70% of the city's agents, inclusive, and the profile
 * chips (Office in {City}, Team, reviewed in the last 12 months, 10+ years) follow the same bounds.
 *
 * The explicit percentages are asserted on small synthetic cities built here. The Boise fixture is
 * checked against expectations computed from it, so a refreshed fixture does not break the suite.
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

// Fixed "now": 2026-10-16 12:00 UTC, which is the morning of the 16th in Pacific time.
$NOW = strtotime( '2026-10-16 12:00:00 UTC' );

function chip_keys( $agents, $now = null ) { return array_column( dos_agents_chips( $agents, 'Boise', null === $now ? time() : $now ), 'key' ); }

/** $n of $of agents get the tag. */
function agents_with_tag( $n, $of, $slug = 'edge' ) {
	$out = array();
	for ( $i = 0; $i < $of; $i++ ) {
		$out[] = agents_make( array( 'tags' => $i < $n ? array( array( 'slug' => $slug, 'label' => ucfirst( $slug ), 'count' => 2 ) ) : array() ) );
	}
	return $out;
}

echo "--- review tags on a synthetic city of 100 ---\n";
// Tag "common" on 92, "mid" on 19, "rare" on 3, "edgelow" on 5, "edgehigh" on 70.
$city = array();
for ( $i = 0; $i < 100; $i++ ) {
	$tags = array();
	foreach ( array( 'common' => 92, 'mid' => 19, 'rare' => 3, 'edgelow' => 5, 'edgehigh' => 70 ) as $slug => $n ) {
		if ( $i < $n ) { $tags[] = array( 'slug' => $slug, 'label' => ucfirst( $slug ), 'count' => 2 ); }
	}
	$city[] = agents_make( array( 'tags' => $tags ) );
}
$keys = chip_keys( $city );
check( 'a tag on 92% of agents is not offered', ! in_array( 't:common', $keys, true ) );
check( 'a tag on 19% is offered', in_array( 't:mid', $keys, true ) );
check( 'a tag on 3% is not offered', ! in_array( 't:rare', $keys, true ) );
check( 'exactly 5% and exactly 70% are offered', in_array( 't:edgelow', $keys, true ) && in_array( 't:edgehigh', $keys, true ) );
$tags = array_values( array_filter( dos_agents_chips( $city, 'Boise', time() ), function ( $c ) { return 'tag' === $c['group']; } ) );
check( 'review tags come most agents first, with the agent count on the chip', array( 'edgehigh', 'mid', 'edgelow' ) === array_map( function ( $c ) { return substr( $c['key'], 2 ); }, $tags ) && array( 70, 19, 5 ) === array_column( $tags, 'count' ) );
check( 'the chip label is the tag label', 'Mid' === $tags[1]['label'] );
check( 'tags over the bound still appear on cards (the card lists every tag)', false !== strpos( dos_agents_card( $city[0], 'Boise', 'ID', 0 ), 'Common · 2' ) );

echo "\n--- the bounds are inclusive and filterable ---\n";
$has = function ( $agents ) { return in_array( 't:edge', chip_keys( $agents ), true ); };
check( '1 of 20 (exactly 5%) is offered', $has( agents_with_tag( 1, 20 ) ) );
check( '14 of 20 (exactly 70%) is offered', $has( agents_with_tag( 14, 20 ) ) );
check( '15 of 20 (75%) is not', ! $has( agents_with_tag( 15, 20 ) ) );
check( '1 of 25 (4%) is not', ! $has( agents_with_tag( 1, 25 ) ) );
check( 'a tag nobody has is not a chip', ! $has( agents_with_tag( 0, 20 ) ) );
add_filter( 'breanm_agents_chip_max', function () { return 0.95; } );
check( 'breanm_agents_chip_max raises the upper bound', $has( agents_with_tag( 15, 20 ) ) );
add_filter( 'breanm_agents_chip_min', function () { return 0.5; } );
check( 'breanm_agents_chip_min raises the lower bound', ! $has( agents_with_tag( 1, 20 ) ) && $has( agents_with_tag( 10, 20 ) ) );
$GLOBALS['filters'] = array();
check( 'no agents, no chips', array() === dos_agents_chips( array(), 'Boise', time() ) );

echo "\n--- profile chips follow the same bounds ---\n";
// $of agents; the first $n of each property.
$mk = function ( $of, $n_office, $n_team, $n_senior, $n_recent ) {
	$out = array();
	for ( $i = 0; $i < $of; $i++ ) {
		$out[] = agents_make( array(
			'tier'        => $i < $n_office ? 'primary' : 'served',
			'is_team'     => $i < $n_team,
			'years'       => $i < $n_senior ? 12 : 2,
			'last_review' => $i < $n_recent ? '2026-06-01' : '2020-01-01',
		) );
	}
	return $out;
};
$k = function ( $agents ) use ( $NOW ) { return chip_keys( $agents, $NOW ); };
$all = array( 'p:office', 'p:team', 'p:years10', 'p:recent' );
$in  = $k( $mk( 20, 1, 1, 14, 14 ) );
check( 'Office, Team, 10+ years and reviewed-recently are all offered at the 5% and 70% edges', array() === array_diff( $all, $in ), json_encode( $in ) );
$out = $k( $mk( 20, 0, 0, 15, 15 ) );
check( 'none are offered at 0% (office, team) or 75% (years, recent)', array() === array_intersect( $all, $out ), json_encode( $out ) );
check( 'Office in Boise at 1 of 100 (1%) is not offered: no exemption from the bounds', ! in_array( 'p:office', $k( $mk( 100, 1, 0, 0, 0 ) ), true ) );
check( 'Office at 5 of 100 is', in_array( 'p:office', $k( $mk( 100, 5, 0, 0, 0 ) ), true ) );
check( 'Office when every agent has an office there (100%) is not', ! in_array( 'p:office', $k( $mk( 10, 10, 0, 0, 0 ) ), true ) );
check( 'exactly 10 years counts as 10+', in_array( 'p:years10', $k( array_map( function ( $y ) { return agents_make( array( 'years' => $y ) ); }, array( 10, 9, 9, 9, 9, 9, 9, 9, 9, 9 ) ) ), true ) );
$c = array_column( dos_agents_chips( $mk( 20, 10, 4, 6, 8 ), 'Boise', $NOW ), null, 'key' );
check( 'the chip counts are the number of matching agents, and Office carries the city name', 10 === $c['p:office']['count'] && 'Office in Boise' === $c['p:office']['label'] && 4 === $c['p:team']['count'] && 6 === $c['p:years10']['count'] && 8 === $c['p:recent']['count'] );

echo "\n--- the 12-month chip follows the render date ---\n";
$recent_n = function ( $agents, $now ) { $c = array_column( dos_agents_chips( $agents, 'Boise', $now ), null, 'key' ); return isset( $c['p:recent'] ) ? $c['p:recent']['count'] : 0; };
$dated = array();
foreach ( array( '2026-09-01', '2026-03-01', '2025-11-01', '2025-05-01', '2024-01-01', '', '2026-08-01', '2026-02-02' ) as $d ) { $dated[] = agents_make( array( 'last_review' => $d ) ); }
check( 'at 2026-10-16 the cutoff is 2025-10-16, so five of eight are recent', 5 === $recent_n( $dated, $NOW ) );
check( 'a year and a half later fewer are', $recent_n( $dated, strtotime( '2027-04-01 12:00 UTC' ) ) < 5 );
check( 'two years on, nobody is, so the chip goes away', 0 === $recent_n( $dated, strtotime( '2029-01-01 UTC' ) ) );

echo "\n--- the Boise fixture, against expectations computed from it ---\n";
$fx     = dos_agents_sanitize_payload( agents_fixture() );
$agents = $fx['agents'];
$total  = count( $agents );
$chips  = dos_agents_chips( $agents, 'Boise', $NOW );
$by     = array_column( $chips, null, 'key' );
$cut    = dos_agents_recent_cutoff( $NOW );
$within = function ( $n ) use ( $total ) { return $n / $total >= 0.05 && $n / $total <= 0.70; };

$tagn = array();
foreach ( $agents as $a ) { foreach ( array_unique( array_column( $a['tags'], 'slug' ) ) as $s ) { $tagn[ $s ] = ( $tagn[ $s ] ?? 0 ) + 1; } }
$bad = array();
foreach ( $tagn as $slug => $n ) {
	if ( $within( $n ) !== isset( $by[ 't:' . $slug ] ) ) { $bad[] = $slug; }
	if ( isset( $by[ 't:' . $slug ] ) && $by[ 't:' . $slug ]['count'] !== $n ) { $bad[] = $slug . ' count'; }
}
check( 'every review tag is a chip exactly when its share is within 5%-70%, with its agent count', array() === $bad, implode( ',', $bad ) );
$prof = array(
	'p:office'  => count( array_filter( $agents, function ( $a ) { return 'primary' === $a['tier']; } ) ),
	'p:team'    => count( array_filter( $agents, function ( $a ) { return $a['is_team']; } ) ),
	'p:years10' => count( array_filter( $agents, function ( $a ) { return $a['years'] >= 10; } ) ),
	'p:recent'  => count( array_filter( $agents, function ( $a ) use ( $cut ) { return '' !== $a['last_review'] && $a['last_review'] >= $cut; } ) ),
);
$bad = array();
foreach ( $prof as $key => $n ) {
	if ( $within( $n ) !== isset( $by[ $key ] ) ) { $bad[] = $key; }
	if ( isset( $by[ $key ] ) && $by[ $key ]['count'] !== $n ) { $bad[] = $key . ' count'; }
}
check( 'Office, Team, 10+ years and recent are chips exactly when within the bounds, with their counts', array() === $bad, implode( ',', $bad ) );
$tag_counts = array_column( array_filter( $chips, function ( $c ) { return 'tag' === $c['group']; } ), 'count' );
$sorted = $tag_counts; rsort( $sorted );
check( 'review tags come most agents first, then the profile chips', $tag_counts === $sorted && array_slice( array_column( $chips, 'group' ), -count( array_filter( $chips, function ( $c ) { return 'profile' === $c['group']; } ) ) ) === array_fill( 0, count( array_filter( $chips, function ( $c ) { return 'profile' === $c['group']; } ) ), 'profile' ) );

echo "\n--- the chips as rendered ---\n";
$html = dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $agents ), 12, $NOW );
check( 'one button per chip on the fixture', count( $chips ) === substr_count( $html, 'data-breanm-chip=' ) );
check( 'the recent-review cutoff is passed to the browser', false !== strpos( $html, 'data-recent-cutoff="' . $cut . '"' ) );
check( 'the filters stay hidden until the script runs', 1 === preg_match( '/<div class="breanm-agents__filters" data-breanm-filters hidden>/', $html ) );
check( 'Clear filters starts hidden', 1 === preg_match( '/data-breanm-clear hidden>Clear filters</', $html ) );
check( 'a chip shows its label and its agent count', 1 === preg_match( '#data-breanm-chip="t:mid">Mid <span class="breanm-agents__chip-count">19</span>#', dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $city ), 12, $NOW ) ) );

// Twelve tags at 30% each: eight shown, four behind "More filters (4)".
$twelve = array();
for ( $i = 0; $i < 10; $i++ ) {
	$tg = array();
	for ( $t = 0; $t < 12; $t++ ) {
		if ( ( $i + $t ) % 10 < 3 ) { $tg[] = array( 'slug' => 'tag-' . $t, 'label' => 'Tag ' . $t, 'count' => 2 ); }
	}
	$twelve[] = agents_make( array( 'tags' => $tg ) );
}
$html = dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $twelve ), 12, $NOW );
check( 'twelve tag chips are rendered', 12 === substr_count( $html, 'data-breanm-chip=' ) );
check( 'the rest sit behind a dashed "More filters (4)" chip', false !== strpos( $html, 'data-more="4">More filters (4)</button>' ) );
check( 'the first eight are not marked extra, the other four are', 4 === substr_count( $html, 'breanm-agents__chip--extra' ) );
preg_match_all( '/data-breanm-(chip(?==)|more-filters)/', $html, $order );
check( 'the dashed chip sits right after the eighth chip', 'more-filters' === $order[1][8] && 13 === count( $order[1] ), json_encode( $order[1] ) );
check( 'aria-controls points at a wrapper that holds only the four extra chips', 1 === preg_match( '#aria-controls="(breanm-agents-\d+-extra)".*?<div class="breanm-agents__extra" id="\1">((?:(?!</div>).)*)</div>#s', $html, $w ) && 4 === substr_count( $w[2], 'data-breanm-chip=' ) && false === strpos( $w[2], 'data-breanm-clear' ) );
check( 'the wrapper is only there when there are extra chips', false === strpos( dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $city ), 12, $NOW ), 'breanm-agents__extra' ) );

finish();
