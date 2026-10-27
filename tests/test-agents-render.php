<?php
/**
 * DoS - Agents: the rendered cards and list.
 *
 * Pins the "Local first" tier order, the 12-month recency flag against a fixed "now", escaping of
 * hostile text, that no card carries an image, the tier wording, and the shortcode wiring.
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

$NOW = strtotime( '2026-10-16 12:00:00 UTC' );
function agents_page( $agents ) { return array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $agents ); }
function agents_card_html( $over, $now = null ) { global $NOW; $a = agents_make( $over ); return dos_agents_card( $a, 'Boise', 'ID', 0, null === $now ? $NOW : $now ); }

echo "--- Local first ---\n";
$tiers = dos_agents_tiers();
check( 'the tier order is primary, served_with_sales, served, served_via_url, sales_only, none', array( 'primary', 'served_with_sales', 'served', 'served_via_url', 'sales_only', 'none' ) === $tiers );
$mixed = array();
foreach ( array_reverse( $tiers ) as $i => $t ) {
	$mixed[] = agents_make( array( 'name' => "$t low", 'tier' => $t, 'sales_in_city' => 5 ) );
	$mixed[] = agents_make( array( 'name' => "$t high", 'tier' => $t, 'sales_in_city' => 50 + $i ) );
}
$sorted = dos_agents_sort_local( $mixed );
$expect = array();
foreach ( $tiers as $t ) { $expect[] = "$t high"; $expect[] = "$t low"; }
check( 'tiers come in order, and within a tier the most sales in the city first', $expect === array_column( $sorted, 'name' ), json_encode( array_column( $sorted, 'name' ) ) );
$tie = dos_agents_sort_local( array( agents_make( array( 'name' => 'First', 'sales_in_city' => 7 ) ), agents_make( array( 'name' => 'Second', 'sales_in_city' => 7 ) ), agents_make( array( 'name' => 'Third', 'sales_in_city' => 7 ) ) ) );
check( 'equal tier and sales keep the payload order', array( 'First', 'Second', 'Third' ) === array_column( $tie, 'name' ) );

$fx   = dos_agents_sanitize_payload( agents_fixture() );
$nfx  = count( $fx['agents'] );
$html = dos_agents_render( agents_page( $fx['agents'] ), 12, $NOW );
preg_match_all( '/data-tier="(\d+)" data-sales="(\d+)"/', $html, $m );
$ok = true;
for ( $i = 1; $i < count( $m[1] ); $i++ ) {
	$prev = array( (int) $m[1][ $i - 1 ], (int) $m[2][ $i - 1 ] );
	$cur  = array( (int) $m[1][ $i ], (int) $m[2][ $i ] );
	if ( $cur[0] < $prev[0] || ( $cur[0] === $prev[0] && $cur[1] > $prev[1] ) ) { $ok = false; }
}
check( 'the rendered fixture is in Local first order, top to bottom', $ok && $nfx === count( $m[1] ) );
check( 'every fixture agent is in the HTML (nothing is held back for JS)', $nfx === substr_count( $html, 'data-breanm-card' ) );
check( 'the first card has the best tier any fixture agent has', (string) min( array_map( function ( $a ) { return dos_agents_tier_rank( $a['tier'] ); }, $fx['agents'] ) ) === $m[1][0] );
check( 'cards carry their original position for a stable sort', 1 === preg_match( '/data-breanm-card data-i="0" /', $html ) && false !== strpos( $html, 'data-i="' . ( $nfx - 1 ) . '" ' ) );

echo "\n--- the 12-month recency flag ---\n";
check( 'the cutoff is 365 days before the render time', '2025-10-16' === dos_agents_recent_cutoff( $NOW ) );
$a = agents_make( array( 'last_review' => '2025-10-16' ) ); check( 'a review on the cutoff date is recent', dos_agents_is_recent( $a, '2025-10-16' ) );
$a = agents_make( array( 'last_review' => '2025-10-15' ) ); check( 'a review a day earlier is not', ! dos_agents_is_recent( $a, '2025-10-16' ) );
$a = agents_make( array( 'last_review' => '2026-10-16' ) ); check( 'a review today is recent', dos_agents_is_recent( $a, '2025-10-16' ) );
$a = agents_make( array( 'last_review' => '' ) );           check( 'no review date is not recent', ! dos_agents_is_recent( $a, '2025-10-16' ) );
check( 'the same agents flip when "now" moves a year on', dos_agents_is_recent( agents_make( array( 'last_review' => '2026-01-10' ) ), dos_agents_recent_cutoff( $NOW ) ) && ! dos_agents_is_recent( agents_make( array( 'last_review' => '2026-01-10' ) ), dos_agents_recent_cutoff( strtotime( '2027-06-01 UTC' ) ) ) );
add_filter( 'breanm_agents_now', function () { return strtotime( '2026-10-16 UTC' ); } );
check( 'breanm_agents_now pins the render time', strtotime( '2026-10-16 UTC' ) === dos_agents_now() );
$GLOBALS['filters'] = array();
$html = dos_agents_render( agents_page( array( agents_make( array( 'last_review' => '2026-08-14' ) ), agents_make() ) ), 12, $NOW );
check( 'the page passes the cutoff and each card its review date for the browser', false !== strpos( $html, 'data-recent-cutoff="2025-10-16"' ) && false !== strpos( $html, 'data-last="20260814"' ) && false !== strpos( $html, 'data-last=""' ) );
check( 'the card keys leave p:recent to the browser, which computes it from the same cutoff', false === strpos( $html, 'p:recent' ) || 1 === preg_match( '/data-breanm-chip="p:recent"/', $html ) );

echo "\n--- escaping ---\n";
$hostile = '<script>alert(1)</script>';
$html    = agents_card_html( array(
	'name'        => $hostile,
	'brokerage'   => '<img src=x onerror=alert(2)>',
	'summary'     => '<b onmouseover=alert(3)>Great</b> "quoted" & more',
	'areas'       => array( '<svg onload=alert(4)>, ID' ),
	'office'      => array( 'city' => '<u>Eagle</u>', 'state' => 'ID', 'zip' => '' ),
	'tier'        => 'served',
	'tags'        => array( array( 'slug' => 'x', 'label' => '<script>tag()</script>', 'count' => 2 ) ),
) );
check( 'no raw script tag in the card', false === stripos( $html, '<script' ) );
check( 'the hostile name is shown as escaped text', false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) );
check( 'no raw tag from the brokerage, summary, area, office or tag label', false === strpos( $html, '<img' ) && false === strpos( $html, '<svg' ) && false === strpos( $html, '<b onmouseover' ) && false === strpos( $html, '<u>' ) );
check( 'an attribute cannot be broken out of (data-name is escaped)', 1 === preg_match( '/data-name="&lt;script&gt;alert\(1\)&lt;\/script&gt; &lt;img/', $html ) );
check( 'no card links to a profile page', false === strpos( $html, 'zillow.com' ) && false === strpos( $html, 'href="http' ) );
foreach ( array( 'javascript:alert(5)', '', 'http://www.zillow.com/profile/x', 'https://evil.example/profile/x' ) as $bad_url ) {
	check( 'a card with profile_url "' . $bad_url . '" is not rendered at all (no empty or hostile href)', '' === dos_agents_card( agents_make( array( 'profile_url' => $bad_url ) ), 'Boise', 'ID', 0 ) );
}
$page = dos_agents_render( agents_page( array( agents_make( array( 'name' => $hostile ) ) ) ), 12, $NOW );
check( 'the full list escapes it too', false === stripos( $page, '<script>' ) && false !== strpos( $page, '&lt;script&gt;alert(1)&lt;/script&gt;' ) );
$GLOBALS['options']['breanm_agents_disclosure'] = '<script>d()</script> "quote"';
$page = dos_agents_render( agents_page( array( agents_make() ) ), 12, $NOW );
check( 'the disclosure option is escaped', false === stripos( $page, '<script>' ) && false !== strpos( $page, '&lt;script&gt;d()&lt;/script&gt; &quot;quote&quot;' ) );
unset( $GLOBALS['options']['breanm_agents_disclosure'] );
$city_page = dos_agents_render( array( 'city' => '<i>Boi</i>"se', 'state' => 'ID', 'agents' => array( agents_make() ) ), 12, $NOW );
check( 'a hostile city name is escaped in the placeholder and sort menu', false === strpos( $city_page, '<i>' ) );

echo "\n--- no pictures ---\n";
$cards = dos_agents_render( agents_page( $fx['agents'] ), 12, $NOW );
check( 'no <img> in any fixture card', 0 === stripos_count( $cards, '<img' ) );
check( 'no <picture>, <svg>, <video> or background-image either', 0 === stripos_count( $cards, '<picture' ) + stripos_count( $cards, '<svg' ) + stripos_count( $cards, '<video' ) + stripos_count( $cards, 'background-image' ) );
function stripos_count( $h, $n ) { return substr_count( strtolower( $h ), $n ); }

echo "\n--- the card ---\n";
$h = agents_card_html( array( 'name' => 'Jane Doe', 'brokerage' => 'Acme Realty', 'is_team' => true, 'rating' => 4.9, 'rating_count' => 112, 'years' => 14, 'sales_in_city' => 41, 'last_review' => '2026-08-14', 'reviews_analyzed' => 20, 'summary' => 'Clients like her.',
	'tags' => array( array( 'slug' => 'a', 'label' => 'Alpha', 'count' => 2 ), array( 'slug' => 'b', 'label' => 'Bravo', 'count' => 9 ), array( 'slug' => 'c', 'label' => 'Charlie', 'count' => 5 ), array( 'slug' => 'd', 'label' => 'Delta', 'count' => 1 ), array( 'slug' => 'e', 'label' => 'Echo', 'count' => 7 ) ) ) );
check( 'the name is plain text, not a link', 1 === preg_match( '#<h3 class="breanm-agents__title">Jane Doe</h3>#', $h ) );
check( 'brokerage with a Team badge', false !== strpos( $h, 'Acme Realty <span class="breanm-agents__team">Team</span>' ) );
check( 'star rating with the review count', false !== strpos( $h, '&#9733;</span> 4.9 <span class="breanm-agents__rcount">(112)</span>' ) );
check( 'stats: sales in the city and years in the industry', false !== strpos( $h, '41 sales in Boise · 14 years in the industry' ), $h );
check( 'last review line with the number of reviews the tags are based on', false !== strpos( $h, 'Last review Aug 2026 · tags based on the 20 most recent reviews' ) );
check( 'the summary is there as a paragraph, and nothing in the CSS cuts it off', false !== strpos( $h, 'breanm-agents__sum">Clients like her.' ) && false === strpos( file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/assets/breanm-agents.css' ), 'line-clamp' ) );
check( 'at most four tag chips, most-mentioned first, as "label · count"', 4 === substr_count( $h, 'class="breanm-agents__chip"' ) && 1 === preg_match( '#>Bravo · 9 <span.*>Echo · 7 <span.*>Charlie · 5 <span.*>Alpha · 2 <span#s', $h ) && false === strpos( $h, 'Delta' ) );
check( 'each chip has a tooltip with the count out of the reviews analyzed', false !== strpos( $h, 'title="Mentioned in 9 of 20 recent reviews">Bravo · 9' ) );
check( 'the same sentence is in visually hidden text inside the chip, for screen readers', false !== strpos( $h, 'Bravo · 9 <span class="breanm-agents__sr">Mentioned in 9 of 20 recent reviews</span></li>' ) );
check( 'no "View Zillow profile" link on the card', false === strpos( $h, 'View Zillow' ) && false === strpos( $h, 'breanm-agents__link' ) );
$h1 = agents_card_html( array( 'years' => 1, 'sales_in_city' => 1, 'tier' => 'served' ) );
check( 'singular years and sales', false !== strpos( $h1, '1 sale in Boise · 1 year in the industry' ) );
$h0 = agents_card_html( array( 'years' => 0, 'sales_in_city' => 0, 'last_review' => '', 'rating_count' => 0, 'rating' => 0, 'summary' => '', 'tags' => array(), 'brokerage' => '' ) );
check( 'no years, no sales, no review date, no rating, no summary, no tags: those lines are left out', false === strpos( $h0, 'in the industry' ) && false === strpos( $h0, 'sales in' ) && false === strpos( $h0, 'Last review' ) && false === strpos( $h0, '&#9733;' ) && false === strpos( $h0, 'breanm-agents__sum' ) && false === strpos( $h0, 'breanm-agents__tags' ) && false === strpos( $h0, 'breanm-agents__co' ) );
$hn = agents_card_html( array( 'last_review' => '2026-08-14', 'reviews_analyzed' => 0 ) );
check( 'without a reviews_analyzed the line does not claim a number', false !== strpos( $hn, 'Last review Aug 2026</p>' ) && false === strpos( $hn, 'tags based on' ) );
$ht = agents_card_html( array( 'tags' => array( array( 'slug' => 'a', 'label' => 'Alpha', 'count' => 9 ) ), 'reviews_analyzed' => 0 ) );
check( 'and the tooltip drops the "of N"', false !== strpos( $ht, 'title="Mentioned in 9 recent reviews"' ) );

echo "\n--- the freshness line and the review window ---\n";
$fresh = function ( $h ) { return preg_match( '#<p class="breanm-agents__fresh">(.*?)</p>#', $h, $m ) ? $m[1] : null; };
check( 'one review, no window: "the 1 most recent review"', 'Last review Aug 2026 · tags based on the 1 most recent review' === $fresh( agents_card_html( array( 'last_review' => '2026-08-14', 'reviews_analyzed' => 1 ) ) ) );
check( 'several reviews, no window: "the 20 most recent reviews" as before', 'Last review Aug 2026 · tags based on the 20 most recent reviews' === $fresh( agents_card_html( array( 'last_review' => '2026-08-14', 'reviews_analyzed' => 20 ) ) ) );
check( 'a window of 0 or an out-of-range one is the same as none', 'Last review Aug 2026 · tags based on the 20 most recent reviews' === $fresh( agents_card_html( array( 'last_review' => '2026-08-14', 'reviews_analyzed' => 20, 'reviews_window_years' => 0 ) ) ) && 'Last review Aug 2026 · tags based on the 20 most recent reviews' === $fresh( agents_card_html( array( 'last_review' => '2026-08-14', 'reviews_analyzed' => 20, 'reviews_window_years' => 99 ) ) ) );
foreach ( array(
	'plural reviews, plural years'   => array( 12, 3, 'Last review Aug 2026 · tags based on 12 reviews from the last 3 years' ),
	'one review, plural years'       => array( 1, 3, 'Last review Aug 2026 · tags based on 1 review from the last 3 years' ),
	'plural reviews, one year'       => array( 12, 1, 'Last review Aug 2026 · tags based on 12 reviews from the last 1 year' ),
	'one review, one year'           => array( 1, 1, 'Last review Aug 2026 · tags based on 1 review from the last 1 year' ),
) as $label => $c ) {
	check( "windowed wording, $label", $c[2] === $fresh( agents_card_html( array( 'last_review' => '2026-08-14', 'reviews_analyzed' => $c[0], 'reviews_window_years' => $c[1] ) ) ), (string) $fresh( agents_card_html( array( 'last_review' => '2026-08-14', 'reviews_analyzed' => $c[0], 'reviews_window_years' => $c[1] ) ) ) );
}
$hw0 = agents_card_html( array( 'last_review' => '2026-08-14', 'reviews_analyzed' => 0, 'reviews_window_years' => 3 ) );
check( 'no reviews analyzed with a window: no tags clause, as today', 'Last review Aug 2026' === $fresh( $hw0 ) && false === strpos( $hw0, 'tags based on' ) );
$hns = agents_card_html( array( 'last_review' => '2026-08-14', 'reviews_analyzed' => 2, 'reviews_window_years' => 3, 'summary' => '', 'tags' => array( array( 'slug' => 'a', 'label' => 'Alpha', 'count' => 2 ) ) ) );
check( 'a window and no summary or parts: the muted note, with the window in it', false !== strpos( $hns, '<p class="breanm-agents__nosum">Not enough reviews from the last 3 years to write a summary.</p>' ) && 1 === substr_count( $hns, 'breanm-agents__nosum' ) && false === strpos( $hns, '<details' ) && false === strpos( $hns, 'breanm-agents__sum"' ) );
check( 'the note sits where the details block would: after the freshness line, before the tags', strpos( $hns, 'breanm-agents__nosum' ) > strpos( $hns, 'breanm-agents__fresh' ) && strpos( $hns, 'breanm-agents__nosum' ) < strpos( $hns, 'breanm-agents__tags' ) );
check( 'a window of one year says "year"', false !== strpos( agents_card_html( array( 'reviews_window_years' => 1 ) ), 'Not enough reviews from the last 1 year to write a summary.</p>' ) );
check( 'the note shows even with no reviews at all, since the window is what was asked for', false !== strpos( agents_card_html( array( 'reviews_window_years' => 5, 'reviews_analyzed' => 0, 'last_review' => '' ) ), 'Not enough reviews from the last 5 years to write a summary.' ) );
check( 'no window and no summary: no note', false === strpos( agents_card_html( array( 'summary' => '', 'reviews_analyzed' => 2, 'last_review' => '2026-08-14' ) ), 'breanm-agents__nosum' ) );
check( 'a window with a summary: no note, the summary paragraph as before', false === strpos( agents_card_html( array( 'summary' => 'Fine.', 'reviews_window_years' => 3 ) ), 'breanm-agents__nosum' ) && false !== strpos( agents_card_html( array( 'summary' => 'Fine.', 'reviews_window_years' => 3 ) ), 'breanm-agents__sum">Fine.</p>' ) );
$hwp = agents_card_html( array( 'summary' => '', 'reviews_window_years' => 3, 'summary_parts' => array( 'buyers' => 'Patient.' ) ) );
check( 'a window with parts: no note, the details block as before', false === strpos( $hwp, 'breanm-agents__nosum' ) && false !== strpos( $hwp, '<strong class="breanm-agents__plabel">Buyers</strong> Patient.</p>' ) );
$css_w = file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/assets/breanm-agents.css' );
check( 'the note is styled like the freshness line (small, muted token)', 1 === preg_match( '/\.breanm-agents__fresh, \.breanm-agents__nosum \{[^}]*font-size: \.78rem; color: var\(--ba-muted\)/', $css_w ) );
check( 'the part label is a block-level small label on its own line, tokens only', 1 === preg_match( '/\.breanm-agents__plabel \{ display: block; margin-bottom: 2px; font-size: 11px; font-weight: 500; line-height: 1; letter-spacing: \.14em; text-transform: uppercase; color: var\(--ba-muted\); \}/', $css_w ) );
preg_match( '/\.breanm-agents__plabel \{[^}]*\}/', $css_w, $plm );
check( 'and no literal color is in that rule', 0 === preg_match( '/#[0-9a-f]{3,8}\b|rgba?\(/i', $plm[0] ) );
check( 'the theme reset on buttons, inputs and selects does not name the label, so it keeps its uppercase', 1 === preg_match( '/\.breanm-agents button, \.breanm-agents input, \.breanm-agents select \{[^}]*\}/', $css_w, $rm ) && false === strpos( $rm[0], 'plabel' ) && false === strpos( $rm[0], 'strong' ) );

echo "\n--- the collapsible, labelled review summary ---\n";
$tags2 = array( array( 'slug' => 'a', 'label' => 'Alpha', 'count' => 2 ), array( 'slug' => 'b', 'label' => 'Bravo', 'count' => 9 ) );
$hp    = agents_card_html( array( 'summary' => 'Old paragraph.', 'tags' => $tags2, 'summary_parts' => array( 'working_style' => 'Calm and quick.', 'buyers' => 'Patient with first-timers.', 'sellers' => 'Priced it right.' ) ) );
check( 'a closed <details> titled "What reviewers say"', 1 === preg_match( '#<details class="breanm-agents__reviews">\s*<summary>What reviewers say</summary>#', $hp ) && false === strpos( $hp, '<details class="breanm-agents__reviews" open' ) && false === stripos( $hp, ' open>' ) );
check( 'one <p> per part, labelled in <strong>, in the order Buyers, Sellers, Working style whatever order the payload had', 1 === preg_match( '#</summary>\s*<p><strong class="breanm-agents__plabel">Buyers</strong> Patient with first-timers\.</p>\s*<p><strong class="breanm-agents__plabel">Sellers</strong> Priced it right\.</p>\s*<p><strong class="breanm-agents__plabel">Working style</strong> Calm and quick\.</p>\s*</details>#', $hp ), $hp );
check( 'the old summary paragraph is not printed when there are parts', false === strpos( $hp, 'breanm-agents__sum' ) && false === strpos( $hp, 'Old paragraph.' ) );
preg_match( '#<details.*?</details>#s', $hp, $dm );
check( 'the tag chips are visible below the details, not inside it', 2 === substr_count( $hp, 'class="breanm-agents__chip"' ) && false === strpos( $dm[0], 'breanm-agents__chip' ) && strpos( $hp, 'breanm-agents__tags' ) > strpos( $hp, '</details>' ) );
check( 'the block sits where the summary paragraph was: after the last-review line, before the tags', strpos( $hp, '<details' ) > strpos( $hp, 'breanm-agents__fresh' ) && strpos( $hp, '<details' ) < strpos( $hp, 'breanm-agents__tags' ) );
$hone = agents_card_html( array( 'summary_parts' => array( 'sellers' => 'Only sellers.' ) ) );
check( 'a single part prints a single paragraph, with only its label', 1 === substr_count( $hone, 'breanm-agents__plabel' ) && false !== strpos( $hone, '<strong class="breanm-agents__plabel">Sellers</strong> Only sellers.</p>' ) && false === strpos( $hone, '>Buyers<' ) );
$hx = agents_card_html( array( 'summary_parts' => array( 'buyers' => '<script>alert(1)</script> "q" & <b>x</b>', 'sellers' => '<img src=x onerror=alert(2)>ok' ) ) );
check( 'part text is escaped, and stripped of tags before it ever gets here', false === stripos( $hx, '<script' ) && false === strpos( $hx, '<img' ) && false === strpos( $hx, '<b>' ) && false !== strpos( $hx, '&quot;q&quot; &amp; x</p>' ), $hx );
// Straight to the renderer, skipping the store: a hand-fed or hand-edited payload is cleaned the same way.
$hraw = dos_agents_card( agents_make( array( 'summary_parts' => array( 'buyers' => str_repeat( 'b', 400 ), 'sellers' => array( 'x' ), 'working_style' => 7 ) ) ), 'Boise', 'ID', 0, $NOW );
check( 'the renderer also caps a part at 320 characters and ignores a non-string one', 1 === substr_count( $hraw, 'breanm-agents__plabel' ) && false !== strpos( $hraw, str_repeat( 'b', 320 ) . '</p>' ) && false === strpos( $hraw, str_repeat( 'b', 321 ) ) );
$hjunk = agents_card_html( array( 'summary' => 'Kept paragraph.', 'summary_parts' => 'not an object' ) );
check( 'summary_parts that is not an object is ignored and the summary paragraph is used', false === strpos( $hjunk, '<details' ) && false !== strpos( $hjunk, 'breanm-agents__sum">Kept paragraph.</p>' ) );
$hempty = agents_card_html( array( 'summary' => 'Kept paragraph.', 'summary_parts' => array( 'buyers' => '  ', 'sellers' => '' ) ) );
check( 'an object with only blank parts is no parts at all', false === strpos( $hempty, '<details' ) && false !== strpos( $hempty, 'breanm-agents__sum">Kept paragraph.</p>' ) );
$hold = agents_card_html( array( 'summary' => 'Whole old paragraph that is long enough to once have been cut after four lines.', 'tags' => $tags2 ) );
check( 'an agent with only the old summary still gets exactly one paragraph, no details', false !== strpos( $hold, '<p class="breanm-agents__sum">Whole old paragraph that is long enough to once have been cut after four lines.</p>' ) && 1 === substr_count( $hold, 'breanm-agents__sum' ) && false === strpos( $hold, '<details' ) && false === strpos( $hold, 'What reviewers say' ) );
check( 'no parts and no summary: nothing printed for either', false === strpos( agents_card_html( array( 'summary' => '' ) ), 'breanm-agents__sum' ) && false === strpos( agents_card_html( array( 'summary' => '' ) ), '<details' ) );
preg_match( '/data-name="([^"]*)" data-keys="([^"]*)"/', $hp, $dn );
check( 'the summary parts are not in the search text (data-name) or the filter keys, as the old summary never was', 2 === count( $dn ) - 1 && false === stripos( $dn[1] . ' ' . $dn[2], 'calm and quick' ) && false === stripos( $dn[1] . ' ' . $dn[2], 'first-timers' ) );
$css_all = file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/assets/breanm-agents.css' );
check( 'the stylesheet styles the details, its chevron, the labels and the old-reviews label, with no line-clamp left', false !== strpos( $css_all, '.breanm-agents__reviews > summary' ) && false !== strpos( $css_all, '.breanm-agents__reviews[open] > summary::before' ) && false !== strpos( $css_all, '::-webkit-details-marker' ) && false !== strpos( $css_all, '.breanm-agents__plabel' ) && false !== strpos( $css_all, '.breanm-agents__old' ) && false === strpos( $css_all, 'line-clamp' ) );
check( 'the grid aligns cards to the top so an open card does not stretch its neighbor', 1 === preg_match( '/\.breanm-agents__grid \{[^}]*align-items: start/', $css_all ) );
check( 'the summary toggle keeps the page font in sentence case against theme overrides', 1 === preg_match( '/\.breanm-agents__reviews > summary \{ font-family: inherit !important; text-transform: none !important; letter-spacing: normal !important; \}/', $css_all ) );

echo "\n--- the old-reviews label ---\n";
check( 'the threshold is 3 years by default', 3 === dos_agents_old_reviews_years() );
check( 'the cutoff is 3 x 365 days before the render time, as a local date', '2023-10-17' === dos_agents_old_cutoff( $NOW ), dos_agents_old_cutoff( $NOW ) );
$old_label = function ( $h ) { return preg_match( '#<span class="breanm-agents__old">(.*?)</span>#', $h, $m ) ? $m[1] : null; };
$h_old = agents_card_html( array( 'last_review' => '2022-05-09', 'reviews_analyzed' => 20 ) );
check( 'a last review more than 3 years old gets "Reviews on file are from 2022 or earlier"', 'Reviews on file are from 2022 or earlier' === $old_label( $h_old ), (string) $old_label( $h_old ) );
check( 'it comes right after the "Last review" line', 1 === preg_match( '#Last review May 2022 · tags based on the 20 most recent reviews</p>\s*<span class="breanm-agents__old">#', $h_old ) );
check( 'and the card is marked data-old="1"', false !== strpos( $h_old, ' data-old="1">' ) && 1 === substr_count( $h_old, 'data-old' ) );
foreach ( array( '2023-10-16' => true, '2023-10-17' => false, '2023-11-01' => false, '2024-10-16' => false, '2026-08-14' => false ) as $date => $is_old ) {
	$h = agents_card_html( array( 'last_review' => $date ) );
	check( "last review $date at 2026-10-16: " . ( $is_old ? 'old' : 'not old' ), $is_old === ( null !== $old_label( $h ) ) && $is_old === ( false !== strpos( $h, 'data-old' ) ), $date );
}
$h_no = agents_card_html( array( 'last_review' => '' ) );
check( 'no review date: no label and no data-old', null === $old_label( $h_no ) && false === strpos( $h_no, 'data-old' ) );
$h_new = agents_card_html( array( 'last_review' => '2026-08-14' ) );
check( 'a recent card has neither the label nor the attribute', false === strpos( $h_new, 'breanm-agents__old' ) && false === strpos( $h_new, 'data-old' ) );
check( 'the same review turns old as "now" moves on (the label follows the render time)', null === $old_label( agents_card_html( array( 'last_review' => '2024-02-01' ), $NOW ) ) && null !== $old_label( agents_card_html( array( 'last_review' => '2024-02-01' ), strtotime( '2027-06-01 UTC' ) ) ) );
check( 'the year in the label is the year of last_review (2019-12-31 says 2019, not 2020)', 'Reviews on file are from 2019 or earlier' === $old_label( agents_card_html( array( 'last_review' => '2019-12-31' ) ) ) );
add_filter( 'dos_agents_old_reviews_years', function () { return 1; } );
check( 'dos_agents_old_reviews_years changes the threshold', 1 === dos_agents_old_reviews_years() && null !== $old_label( agents_card_html( array( 'last_review' => '2025-06-01' ) ) ) );
$GLOBALS['filters'] = array();
add_filter( 'dos_agents_old_reviews_years', function () { return 0; } );
check( 'a nonsense filter value (0) falls back to one year, never to "everything is old"', 1 === dos_agents_old_reviews_years() );
$GLOBALS['filters'] = array();
check( 'the list passes its render time to each card', false !== strpos( dos_agents_render( agents_page( array( agents_make( array( 'last_review' => '2022-05-09' ) ) ) ), 12, $NOW ), ' data-old="1">' ) && false === strpos( dos_agents_render( agents_page( array( agents_make( array( 'last_review' => '2022-05-09' ) ) ) ), 12, strtotime( '2024-01-01 UTC' ) ), 'data-old' ) );
$evening2 = strtotime( '2026-10-17 03:30:00 UTC' ); // 20:30 on the 16th in Pacific time, so the cutoff is the local 2023-10-17.
check( 'the cutoff is the site-local date, like the 12-month one', '2023-10-17' === dos_agents_old_cutoff( $evening2 ) && '2023-10-18' === gmdate( 'Y-m-d', $evening2 - 3 * YEAR_IN_SECONDS ) );

echo "\n--- the area line by tier ---\n";
$cases = array(
	'primary'           => array( 'Office in Boise', array( 'sales_in_city' => 10 ) ),
	'served_with_sales' => array( 'Serves Boise · 12 sales here', array( 'sales_in_city' => 12, 'office' => array( 'city' => 'Meridian', 'state' => 'ID', 'zip' => '' ) ) ),
	'served'            => array( 'Serves Boise', array() ),
	'served_via_url'    => array( 'Serves areas in Boise', array() ),
	'sales_only'        => array( '7 sales in Boise', array( 'sales_in_city' => 7 ) ),
);
foreach ( $cases as $tier => $c ) {
	$h = agents_card_html( array_merge( array( 'tier' => $tier ), $c[1] ) );
	check( "$tier: badge reads \"{$c[0]}\"", false !== strpos( $h, '<p class="breanm-agents__badge">' . $c[0] . '</p>' ) );
}
check( 'none: no badge', false === strpos( agents_card_html( array( 'tier' => 'none' ) ), 'breanm-agents__badge' ) );
$h = agents_card_html( array( 'tier' => 'served_with_sales', 'sales_in_city' => 12 ) );
check( 'a badge that already states the sales is not repeated in the stats line', 1 === substr_count( $h, '12 sales' ) );
check( 'one sale reads "1 sale here"', false !== strpos( agents_card_html( array( 'tier' => 'served_with_sales', 'sales_in_city' => 1 ) ), 'Serves Boise · 1 sale here' ) );
$off = agents_card_html( array( 'tier' => 'served', 'office' => array( 'city' => 'Nampa', 'state' => 'ID', 'zip' => '' ) ) );
check( '"Office: Nampa, ID" shows when the tier is not primary', false !== strpos( $off, 'Office: Nampa, ID' ) );
check( 'and not for a primary-tier agent', false === strpos( agents_card_html( array( 'tier' => 'primary' ) ), 'Office:' ) );
$areas = agents_card_html( array( 'tier' => 'primary', 'areas' => array( 'Boise, ID', 'East End, Boise, ID', 'Meridian, ID', 'Eagle, ID', 'Nampa, ID', 'Kuna, ID', 'Star, ID', 'Caldwell, ID', 'Emmett, ID' ) ) );
check( '"Also serves" skips the city and its neighborhoods, lists five, and counts the rest', false !== strpos( $areas, 'Also serves: Meridian, ID · Eagle, ID · Nampa, ID · Kuna, ID · Star, ID +2' ), $areas );
check( 'no "Also serves" line when every area is the city', false === strpos( agents_card_html( array( 'areas' => array( 'Boise, ID', 'Southeast Boise, Boise, ID' ) ) ), 'Also serves' ) );

echo "\n--- the list ---\n";
$html = dos_agents_render( agents_page( $fx['agents'] ), 12, $NOW );
check( 'the count line is server-rendered, nothing hidden without JS', false !== strpos( $html, "Showing $nfx of $nfx agents" ) );
check( 'the sort menu lists the six orders, Local first selected', 1 === preg_match( '#<option value="local" selected>Local first</option>\s*<option value="sales">Most sales in Boise</option>\s*<option value="rating">Rating</option>\s*<option value="reviews">Number of reviews</option>\s*<option value="recent">Most recently reviewed</option>\s*<option value="years">Years in the industry</option>#', $html ) );
check( 'the sort menu, search and Show more start hidden', false !== strpos( $html, 'data-breanm-sortbox hidden' ) && false !== strpos( $html, 'data-breanm-filters hidden' ) && false !== strpos( $html, 'data-breanm-more hidden>Show more agents' ) );
check( 'the empty state is there, hidden', false !== strpos( $html, 'data-breanm-empty hidden>No agents match those filters.' ) );
check( 'initial and the page size are handed to the script', false !== strpos( $html, 'data-initial="12" data-step="12"' ) );
check( 'initial can be changed', false !== strpos( dos_agents_render( agents_page( $fx['agents'] ), 20, $NOW ), 'data-initial="20"' ) );
check( 'the search placeholder names the city', false !== strpos( $html, 'placeholder="Search Boise agents by name or brokerage"' ) );
check( 'the default disclosure sits under the list', 1 === preg_match( '#</ul>.*<p class="breanm-agents__empty".*<p class="breanm-agents__disc">Information only, not a ranking or endorsement\. Profile facts come from online or public agent profiles and may be out of date\. Review summaries and tags are generated from recent public reviews and checked against them\.</p>#s', $html ) );
$GLOBALS['options']['breanm_agents_disclosure'] = 'Custom words.';
check( 'the disclosure option overrides it', false !== strpos( dos_agents_render( agents_page( $fx['agents'] ), 12, $NOW ), '>Custom words.</p>' ) );
unset( $GLOBALS['options']['breanm_agents_disclosure'] );

// The interface copy, with the review-derived text taken out, never ranks or crowns anyone.
$bare = $fx['agents'];
foreach ( $bare as &$b ) { $b['summary'] = ''; $b['name'] = 'Name'; $b['brokerage'] = 'Brokerage'; $b['tags'] = array(); }
unset( $b );
$copy = strtolower( strip_tags( dos_agents_render( agents_page( $bare ), 12, $NOW ) ) );
check( 'nothing in the interface copy says "best", "top", "#1" or "ranked"', ! preg_match( '/\bbest\b|\btop\b|#\s?1\b|\branked\b|\bleading\b/', $copy ) );
check( 'cards have no position numbers (no ordered list, no counter)', false === strpos( $html, '<ol' ) && false === strpos( $html, 'data-position' ) );

echo "\n--- the shortcode ---\n";
$GLOBALS['shortcodes'] = array();
dos_agents_register_shortcode();
check( 'the shortcode is [breanm_agents]', isset( $GLOBALS['shortcodes']['breanm_agents'] ) );
$out = call_user_func( $GLOBALS['shortcodes']['breanm_agents'], array( 'city' => 'boise-id' ) );
check( 'a city with nothing stored gets a short neutral note', false !== strpos( $out, 'Agent information for this city is not available yet.' ) && false === strpos( $out, 'data-breanm-agents' ) );
check( 'and loads no CSS or JS', array() === $GLOBALS['wp']['styles'] && array() === $GLOBALS['wp']['scripts'] );
$out = call_user_func( $GLOBALS['shortcodes']['breanm_agents'], array() );
check( 'no city attribute gets the same note', false !== strpos( $out, 'not available yet' ) );
dos_agents_store( agents_fixture() );
$out = call_user_func( $GLOBALS['shortcodes']['breanm_agents'], array( 'city' => 'boise-id', 'initial' => '5' ) );
check( 'with data stored it renders the whole UI, with the initial attribute', false !== strpos( $out, 'data-breanm-agents' ) && false !== strpos( $out, 'data-initial="5"' ) && $nfx === substr_count( $out, 'data-breanm-card' ) );
check( 'the city attribute is matched by slug, so "Boise-ID" works too', false !== strpos( call_user_func( $GLOBALS['shortcodes']['breanm_agents'], array( 'city' => 'Boise-ID' ) ), 'data-breanm-agents' ) );
check( 'it enqueues the stylesheet and script, versioned for the CDN', isset( $GLOBALS['wp']['styles']['breanm-agents'], $GLOBALS['wp']['scripts']['breanm-agents'] ) && DOS_AGENTS_VERSION === $GLOBALS['wp']['styles']['breanm-agents'][1] && DOS_AGENTS_VERSION === $GLOBALS['wp']['scripts']['breanm-agents'][1] );
check( 'the assets are the plugin files', '/assets/breanm-agents.css' === substr( $GLOBALS['wp']['styles']['breanm-agents'][0], -25 ) && is_file( dirname( __DIR__ ) . '/plugins/dos-agents/assets/breanm-agents.js' ) && is_file( dirname( __DIR__ ) . '/plugins/dos-agents/assets/breanm-agents.css' ) );
check( 'the script loads in the footer, deferred', true === $GLOBALS['wp']['scripts']['breanm-agents'][2]['in_footer'] && 'defer' === $GLOBALS['wp']['scripts']['breanm-agents'][2]['strategy'] );

echo "\n--- the stylesheet ---\n";
$css = file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/assets/breanm-agents.css' );
$bad = array();
foreach ( explode( '}', preg_replace( '#/\*.*?\*/#s', '', $css ) ) as $rule ) {
	$sel = trim( substr( $rule, 0, (int) strpos( $rule, '{' ) ) );
	if ( '' === $sel || 0 === strpos( $sel, '@' ) ) { continue; }
	foreach ( explode( ',', $sel ) as $one ) { if ( false === strpos( $one, '.breanm-agents' ) ) { $bad[] = trim( $one ); } }
}
check( 'every selector is scoped under .breanm-agents', array() === $bad, implode( ' | ', $bad ) );
$colors = preg_match_all( '/#[0-9a-f]{3,6}\b/i', preg_replace( '/^\s*--ba-[a-z-]+:.*$/m', '', $css ) );
check( 'colors are defined once, as custom properties; none are written out in the rules', 0 === $colors, (string) $colors );
check( 'no external fonts or imports', false === stripos( $css, '@import' ) && false === stripos( $css, '@font-face' ) && false === stripos( $css, 'url(' ) );

echo "\n--- the Office line is tidied for display ---\n";
$office_of = function ( $city, $state = 'ID' ) { return agents_card_html( array( 'tier' => 'served', 'office' => array( 'city' => $city, 'state' => $state, 'zip' => '' ) ) ); };
check( '"Boise, ID 83716" keeps the city only and does not gain a second state', false !== strpos( $office_of( 'Boise, ID 83716' ), '>Office: Boise</p>' ) );
check( '"meridian" is capitalised and gets the state', false !== strpos( $office_of( 'meridian' ), '>Office: Meridian, ID</p>' ) );
check( '"Meridian" stays as it is and gets the state', false !== strpos( $office_of( 'Meridian' ), '>Office: Meridian, ID</p>' ) );
check( '"MERIDIAN, ID 83642" does not become "Meridian, ID 83642, ID"', false !== strpos( $office_of( 'MERIDIAN, ID 83642' ), '>Office: Meridian</p>' ) && false === strpos( $office_of( 'MERIDIAN, ID 83642' ), '83642' ) );
check( 'a city with no state is shown without one', false !== strpos( $office_of( 'meridian', '' ), '>Office: Meridian</p>' ) );
check( 'no office city, no Office line', false === strpos( $office_of( '' ), 'Office:' ) );

echo "\n--- the 12-month cutoff is the site-local date ---\n";
// 2026-10-16 03:30 UTC is 20:30 on the 15th in Pacific time. A year back (365 days) is 2025-10-15 20:30 Pacific.
$evening = strtotime( '2026-10-16 03:30:00 UTC' );
check( 'UTC says the 16th, the site (Pacific) says the 15th', '2026-10-16' === gmdate( 'Y-m-d', $evening ) && '2026-10-15' === wp_date( 'Y-m-d', $evening ) );
check( 'the cutoff is 2025-10-15, the local date, not 2025-10-16', '2025-10-15' === dos_agents_recent_cutoff( $evening ) && '2025-10-16' === gmdate( 'Y-m-d', $evening - YEAR_IN_SECONDS ) );
$edge = array( agents_make( array( 'last_review' => '2025-10-15' ) ), agents_make( array( 'last_review' => '2025-10-14' ) ) );
$cut  = dos_agents_recent_cutoff( $evening );
check( 'a review on the local cutoff date is recent, the day before is not', dos_agents_is_recent( $edge[0], $cut ) && ! dos_agents_is_recent( $edge[1], $cut ) );
check( 'the page carries that same cutoff for the script', false !== strpos( dos_agents_render( agents_page( $edge ), 12, $evening ), 'data-recent-cutoff="2025-10-15"' ) );
$GLOBALS['wp']['tz'] = 'Pacific/Auckland';
check( 'it follows the site timezone setting', '2025-10-16' === dos_agents_recent_cutoff( $evening ) || '2025-10-17' === dos_agents_recent_cutoff( $evening ) );
$GLOBALS['wp']['tz'] = 'America/Los_Angeles';

echo "\n--- assets load in <head> where the content has the shortcode ---\n";
$post = function ( $content ) { return (object) array( 'post_content' => $content ); };
agents_reset();
check( 'the hook is registered on wp_enqueue_scripts', isset( $GLOBALS['actions']['wp_enqueue_scripts'][10] ) && in_array( 'dos_agents_maybe_enqueue', $GLOBALS['actions']['wp_enqueue_scripts'][10], true ) );
$GLOBALS['wp']['singular'] = false; $GLOBALS['wp']['post'] = $post( '[breanm_agents city="boise-id"]' );
dos_agents_maybe_enqueue();
check( 'not on an archive, even with a post in hand', array() === $GLOBALS['wp']['styles'] );
$GLOBALS['wp']['singular'] = true; $GLOBALS['wp']['post'] = $post( 'No shortcode here, only [other_code].' );
dos_agents_maybe_enqueue();
check( 'not on a singular page without the shortcode', array() === $GLOBALS['wp']['styles'] && array() === $GLOBALS['wp']['scripts'] );
$GLOBALS['wp']['post'] = null;
dos_agents_maybe_enqueue();
check( 'no post, no error, nothing enqueued', array() === $GLOBALS['wp']['styles'] );
$GLOBALS['wp']['post'] = $post( "Intro\n[breanm_agents city=\"boise-id\" initial=\"12\"]\nOutro" );
dos_agents_maybe_enqueue();
check( 'on a singular page with the shortcode both assets are enqueued', isset( $GLOBALS['wp']['styles']['breanm-agents'], $GLOBALS['wp']['scripts']['breanm-agents'] ) );
dos_agents_store( agents_fixture() );
call_user_func( $GLOBALS['shortcodes']['breanm_agents'], array( 'city' => 'boise-id' ) );
dos_agents_maybe_enqueue();
check( 'the shortcode fallback and a second hook run do not enqueue them again', 1 === $GLOBALS['wp']['style_calls'], (string) $GLOBALS['wp']['style_calls'] );
agents_reset();
$GLOBALS['wp']['singular'] = true; $GLOBALS['wp']['post'] = $post( 'Page-builder content the post body does not show' );
dos_agents_store( agents_fixture() );
dos_agents_maybe_enqueue();
check( 'builder-stored content: the hook enqueues nothing', 0 === $GLOBALS['wp']['style_calls'] );
call_user_func( $GLOBALS['shortcodes']['breanm_agents'], array( 'city' => 'boise-id' ) );
check( 'and the shortcode enqueues them as the fallback', 1 === $GLOBALS['wp']['style_calls'] && isset( $GLOBALS['wp']['scripts']['breanm-agents'] ) );

echo "\n--- bad stored data never reaches the page ---\n";
agents_reset();
dos_agents_store( agents_fixture() );
$opt = $GLOBALS['options']['breanm_agents_boise-id'];
$opt['agents'][0]['profile_url'] = '';
$GLOBALS['options']['breanm_agents_boise-id'] = $opt;
$out = call_user_func( $GLOBALS['shortcodes']['breanm_agents'], array( 'city' => 'boise-id' ) );
check( 'an agent whose stored profile_url was blanked is not rendered', $nfx - 1 === substr_count( $out, 'data-breanm-card' ) );
check( 'and there is no empty href', false === strpos( $out, 'href=""' ) );
check( 'the count line follows the agents actually rendered', false !== strpos( $out, 'Showing ' . ( $nfx - 1 ) . ' of ' . ( $nfx - 1 ) . ' agents' ) );

// A partial agent handed straight to the renderer: no warnings, no notices, still a sensible card.
$warnings = array();
set_error_handler( function ( $no, $str ) use ( &$warnings ) { $warnings[] = $str; return true; } );
$partial = array( 'name' => 'Partial Agent', 'profile_url' => 'https://www.zillow.com/profile/partial' );
$page    = dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( $partial, array( 'name' => 'No Link' ), 'junk', array( 'name' => 'Odd', 'profile_url' => 'https://www.zillow.com/profile/odd', 'tags' => 'x', 'office' => null, 'areas' => 5, 'rating' => 'n/a' ) ) ), 12, $NOW );
restore_error_handler();
check( 'an agent with only a name and a link renders, with no PHP warnings or notices', array() === $warnings && false !== strpos( $page, 'Partial Agent' ), implode( ' | ', $warnings ) );
check( 'an agent with no profile link, and a non-array entry, are skipped', false === strpos( $page, 'No Link' ) && 2 === substr_count( $page, 'data-breanm-card' ) );
check( 'wrong-typed fields are tolerated too', false !== strpos( $page, 'Odd' ) );
$warnings = array();
set_error_handler( function ( $no, $str ) use ( &$warnings ) { $warnings[] = $str; return true; } );
$noroot = dos_agents_render( array( 'agents' => array( $partial ) ), 12, $NOW );
restore_error_handler();
check( 'a payload without a city or state does not warn either', array() === $warnings, implode( ' | ', $warnings ) );

echo "\n--- strings the script writes come from PHP ---\n";
$html = dos_agents_render( agents_page( $fx['agents'] ), 12, $NOW );
check( 'the root carries the singular and plural count templates', false !== strpos( $html, 'data-text-showing-one="Showing %1$d of %2$d agent"' ) && false !== strpos( $html, 'data-text-showing-many="Showing %1$d of %2$d agents"' ) );
check( 'and the More filters and Fewer filters labels', false !== strpos( $html, 'data-text-more-filters="More filters (%d)"' ) && false !== strpos( $html, 'data-text-fewer-filters="Fewer filters"' ) );
$js   = file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/assets/breanm-agents.js' );
$code = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $js );
check( 'the script has no hard-coded English for the count line or the filter toggle', ! preg_match( '/Showing| of |More filters|Fewer filters|Clear filters|Show more|\bagents\b(?!\])/', $code ) );
check( 'it reads those templates from the root element', false !== strpos( $js, 'data-text-showing-one' ) && false !== strpos( $js, 'data-text-more-filters' ) && false !== strpos( $js, 'data-text-fewer-filters' ) );

echo "\n--- accessibility details in the assets ---\n";
$css = file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/assets/breanm-agents.css' );
check( 'the count inside a pressed chip is not faded', 1 === preg_match( '/\[aria-pressed="true"\] \.breanm-agents__chip-count \{ opacity: 1; \}/', $css ) );
check( 'Show more agents hands focus to the first revealed card before it hides', 1 === preg_match( '/first = matched\[limit\].*?moreBtn\.hidden && first.*?\.focus\(\)/s', $js ) );
check( 'the Rating sort rounds to one decimal and puts agents under 5 reviews last', false !== strpos( $js, 'MIN_REVIEWS = 5' ) && 1 === preg_match( '/rating: function.*?\(a\.reviews < MIN_REVIEWS\) - \(b\.reviews < MIN_REVIEWS\).*?round1\(b\.rating\) - round1\(a\.rating\).*?b\.reviews - a\.reviews/s', $js ) );

echo "\n--- the disclosure from the payload, then the option, then the default ---\n";
$one   = array( agents_make() );
$disc  = function ( $html ) { return preg_match( '#<div class="breanm-agents__disc">.*?</div>|<p class="breanm-agents__disc">.*?</p>#s', $html, $m ) ? $m[0] : ''; };
$hostile_disc = array( 'title' => '<script>alert(1)</script> Title', 'paragraphs' => array( 'First <script>alert(1)</script> & "more"', '<img src=x onerror=alert(2)>', 'Third.' ) );
$html = dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $one, 'disclosure' => $hostile_disc ), 12, $NOW );
$box  = $disc( $html );
check( 'the title is in <strong>, escaped', false !== strpos( $box, '<strong>&lt;script&gt;alert(1)&lt;/script&gt; Title</strong>' ), $box );
check( 'each paragraph is its own <p>, escaped', 3 === substr_count( $box, '<p>' ) && false !== strpos( $box, '<p>First &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;more&quot;</p>' ) && false !== strpos( $box, '<p>&lt;img src=x onerror=alert(2)&gt;</p>' ) && false !== strpos( $box, '<p>Third.</p>' ), $box );
check( 'no raw tag from it reaches the page', false === stripos( $html, '<script' ) && false === strpos( $html, '<img' ) );
check( 'it uses the existing disclosure class, under the list, and replaces the one-paragraph version', false !== strpos( $html, '<div class="breanm-agents__disc">' ) && false === strpos( $html, '<p class="breanm-agents__disc">' ) && strpos( $html, 'breanm-agents__disc' ) > strpos( $html, '</ul>' ) && 1 === substr_count( $html, 'breanm-agents__disc"' ) );
check( 'the title is optional: paragraphs alone print no <strong>', false === strpos( $disc( dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $one, 'disclosure' => array( 'title' => '', 'paragraphs' => array( 'Only text.' ) ) ), 12, $NOW ) ), '<strong>' ) );

$option_text = 'Words from the option.';
$GLOBALS['options']['breanm_agents_disclosure'] = $option_text;
$with_payload = dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $one, 'disclosure' => array( 'title' => 'From data', 'paragraphs' => array( 'Payload words.' ) ) ), 12, $NOW );
check( 'payload disclosure beats the option', false !== strpos( $with_payload, 'Payload words.' ) && false === strpos( $with_payload, $option_text ) );
$no_payload = dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $one ), 12, $NOW );
check( 'without one in the payload the option is used', '<p class="breanm-agents__disc">' . $option_text . '</p>' === $disc( $no_payload ) );
$empty_payload = dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $one, 'disclosure' => array( 'title' => 'T', 'paragraphs' => array( '', '  ' ) ) ), 12, $NOW );
check( 'a payload disclosure with no paragraph falls through to the option', '<p class="breanm-agents__disc">' . $option_text . '</p>' === $disc( $empty_payload ) );
unset( $GLOBALS['options']['breanm_agents_disclosure'] );
$default = dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $one ), 12, $NOW );
check( 'with neither, the built-in default', '<p class="breanm-agents__disc">' . esc_html( dos_agents_default_disclosure() ) . '</p>' === $disc( $default ) );
$GLOBALS['options']['breanm_agents_disclosure'] = '   ';
check( 'a blank option is the same as none', '<p class="breanm-agents__disc">' . esc_html( dos_agents_default_disclosure() ) . '</p>' === $disc( dos_agents_render( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $one ), 12, $NOW ) ) );
unset( $GLOBALS['options']['breanm_agents_disclosure'] );

// End to end through storage and the shortcode.
agents_reset();
$fxp = agents_fixture();
$fxp['disclosure'] = array( 'title' => 'How to read this', 'paragraphs' => array( 'Stored paragraph one.', 'Stored paragraph two.' ) );
dos_agents_store( $fxp );
$out = call_user_func( $GLOBALS['shortcodes']['breanm_agents'], array( 'city' => 'boise-id' ) );
check( 'pushed through the upsert and read by the shortcode, it appears', false !== strpos( $out, '<strong>How to read this</strong>' ) && false !== strpos( $out, '<p>Stored paragraph two.</p>' ) );
$css = file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/assets/breanm-agents.css' );
check( 'the stylesheet spaces the title and paragraphs, scoped under .breanm-agents', false !== strpos( $css, '.breanm-agents__disc > strong' ) && false !== strpos( $css, '.breanm-agents__disc p + p' ) );

finish();
