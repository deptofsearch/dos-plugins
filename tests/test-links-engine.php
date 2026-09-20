<?php
/**
 * The internal-linking engine. It writes into post content, so the rules
 * about what it must never touch are the whole point.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );

function esc_url( $u ) { return $u; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function get_permalink( $id ) { return $id ? 'https://example.com/target/' : false; }

require PLUGIN . '/modules/links/class-dos-links-engine.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}
function found( $html, $phrase = 'roof repair' ) {
    return count( DOS_Links_Engine::occurrences( $html, $phrase ) );
}
function rule( $over = array() ) {
    return array_merge( array(
        'id' => 1, 'phrase' => 'roof repair', 'target_id' => 99,
        'max_per_page' => 1, 'first_instance' => 'link', 'throttle' => 100,
    ), $over );
}

echo "--- what it links ---\n";
check( 'plain paragraph text', 1 === found( '<p>We do roof repair here.</p>' ) );
check( 'case-insensitively', 1 === found( '<p>We do Roof Repair here.</p>' ) );
check( 'several occurrences', 3 === found( '<p>roof repair</p><p>roof repair and roof repair</p>' ) );
check( 'text with no markup at all', 1 === found( 'roof repair' ) );

echo "\n--- what it must never touch ---\n";
$cases = array(
    'a heading'            => '<h2>roof repair</h2>',
    'a deeper heading'     => '<h4>roof repair</h4>',
    'bold'                 => '<p><b>roof repair</b></p>',
    'strong'               => '<p><strong>roof repair</strong></p>',
    'a list item'          => '<ul><li>roof repair</li></ul>',
    'a numbered list'      => '<ol><li>roof repair</li></ol>',
    'a table cell'         => '<table><tr><td>roof repair</td></tr></table>',
    'a table header'       => '<table><tr><th>roof repair</th></tr></table>',
    'an existing link'     => '<p><a href="/x">roof repair</a></p>',
    'code'                 => '<p><code>roof repair</code></p>',
    'a preformatted block' => '<pre>roof repair</pre>',
    'a script'             => '<script>var s = "roof repair";</script>',
    'a shortcode'          => '<p>[gallery title="roof repair"]</p>',
    'an attribute'         => '<p><img src="x.jpg" alt="roof repair"></p>',
    'a figure caption'     => '<figure><figcaption>roof repair</figcaption></figure>',
);
foreach ( $cases as $label => $html ) {
    check( "skips $label", 0 === found( $html ), $html );
}

check( 'links text beside an excluded element', 1 === found( '<h2>roof repair</h2><p>roof repair</p>' ) );
check( 'links after a list closes', 1 === found( '<ul><li>roof repair</li></ul><p>roof repair</p>' ) );
check( 'survives unbalanced markup', 1 === found( '<div><p>roof repair</div>' ) );

echo "\n--- word boundaries ---\n";
check( 'does not match inside a longer word', 0 === found( '<p>roof repairing</p>' ) );
check( 'does not match a hyphenated extension', 0 === found( '<p>roof repair-service</p>' ) );
check( 'matches before punctuation', 1 === found( '<p>We do roof repair, fast.</p>' ) );
check( 'matches at the end of a sentence', 1 === found( '<p>We do roof repair.</p>' ) );

echo "\n--- rule limits ---\n";
$html = '<p>roof repair one</p><p>roof repair two</p><p>roof repair three</p>';

check( 'max per page caps the count', 2 === count( DOS_Links_Engine::placements( $html, rule( array( 'max_per_page' => 2 ) ), 5 ) ) );
check( 'a page never links to itself', array() === DOS_Links_Engine::placements( $html, rule(), 99 ) );

$first = DOS_Links_Engine::placements( $html, rule( array( 'max_per_page' => 1, 'first_instance' => 'link' ) ), 5 );
$skip  = DOS_Links_Engine::placements( $html, rule( array( 'max_per_page' => 1, 'first_instance' => 'skip' ) ), 5 );
check( 'first instance linked when asked', $first[0]['offset'] < $skip[0]['offset'], json_encode( array( $first[0]['offset'], $skip[0]['offset'] ) ) );
check( '  and skipped when asked', count( $skip ) === 1 );

echo "\n--- throttle ---\n";
check( '100 per cent keeps everything', DOS_Links_Engine::passes_throttle( 1, 5, 0, 100 ) && DOS_Links_Engine::passes_throttle( 1, 5, 9, 100 ) );
check( '0 per cent keeps nothing', ! DOS_Links_Engine::passes_throttle( 1, 5, 0, 0 ) );

$decisions = array();
for ( $i = 0; $i < 400; $i++ ) { $decisions[] = DOS_Links_Engine::passes_throttle( 1, $i, 0, 50 ) ? 1 : 0; }
$kept = array_sum( $decisions );
check( '50 per cent keeps roughly half of 400', $kept > 150 && $kept < 250, "kept $kept" );

$again = array();
for ( $i = 0; $i < 400; $i++ ) { $again[] = DOS_Links_Engine::passes_throttle( 1, $i, 0, 50 ) ? 1 : 0; }
check( '  and makes the same decisions every time', $decisions === $again );

echo "\n--- writing ---\n";
$html   = '<h2>Roof repair</h2><p>We offer roof repair in Phoenix.</p><ul><li>roof repair</li></ul>';
$result = DOS_Links_Engine::apply( $html, array( rule() ), 5 );

check( 'adds exactly one link', 1 === substr_count( $result['html'], '<a href="https://example.com/target/"' ), $result['html'] );
check( '  marked so it can be found later', false !== strpos( $result['html'], 'data-dos-link="1"' ) );
check( '  leaves the heading untouched', false !== strpos( $result['html'], '<h2>Roof repair</h2>' ), $result['html'] );
check( '  leaves the list untouched', false !== strpos( $result['html'], '<li>roof repair</li>' ), $result['html'] );
check( '  reports what it did', array( 1 => 1 ) === $result['added'], json_encode( $result['added'] ) );

$plain = strip_tags( $result['html'] );
check( '  the readable text is unchanged', strip_tags( $html ) === $plain, $plain );

$none = DOS_Links_Engine::apply( '<h2>roof repair</h2>', array( rule() ), 5 );
check( 'changes nothing when there is nothing to link', '<h2>roof repair</h2>' === $none['html'] );

echo "\n--- overlapping rules ---\n";
$two = array(
    rule( array( 'id' => 1, 'phrase' => 'roof repair', 'target_id' => 99 ) ),
    rule( array( 'id' => 2, 'phrase' => 'roof repair phoenix', 'target_id' => 98 ) ),
);
$out = DOS_Links_Engine::apply( '<p>Try roof repair phoenix today.</p>', $two, 5 );
check( 'never nests one link inside another', 1 === substr_count( $out['html'], '<a ' ), $out['html'] );

echo "\n--- removing ---\n";
$mixed = '<p><a href="/manual">roof repair</a> and <a href="/t" class="dos-ilink" data-dos-link="1">roof repair</a></p>';
$strip = DOS_Links_Engine::strip( $mixed );
check( 'removes links it created', false === strpos( $strip['html'], 'data-dos-link' ) );
check( '  leaves a hand-made link alone', false !== strpos( $strip['html'], '<a href="/manual">roof repair</a>' ), $strip['html'] );
check( '  counts what it removed', 1 === $strip['removed'] );

echo "\n--- why a rule produced nothing ---\n";

// The single-occurrence case from the canary: one post, one mention.
$one = '<p>this is a post about open houses in Phoenix.</p>';
$r   = rule( array( 'phrase' => 'open houses in Phoenix', 'target_id' => 99 ) );

$a = DOS_Links_Engine::analyse( $one, $r, 205 );
check( 'a straightforward match reports a placement', 1 === $a['placements'] && 1 === $a['occurrences'], json_encode( $a ) );
check( '  and needs no explanation', '' === $a['reason'], json_encode( $a ) );

$a = DOS_Links_Engine::analyse( $one, $r, 99 );
check( 'pointing a phrase at the only page containing it reports self', 'self' === $a['reason'], json_encode( $a ) );

$a = DOS_Links_Engine::analyse( $one, rule( array( 'phrase' => 'open houses in Phoenix', 'first_instance' => 'skip' ) ), 205 );
check( 'skipping the first when there is only one reports first_only', 'first_only' === $a['reason'], json_encode( $a ) );
check( '  and still admits it found the phrase', 1 === $a['occurrences'] );

$a = DOS_Links_Engine::analyse( $one, rule( array( 'phrase' => 'open houses in Phoenix', 'throttle' => 0 ) ), 205 );
check( 'a throttle that excludes everything reports throttled', 'throttled' === $a['reason'], json_encode( $a ) );

$a = DOS_Links_Engine::analyse( '<h2>open houses in Phoenix</h2>', $r, 205 );
check( 'a phrase only inside a heading reports absent', 'absent' === $a['reason'], json_encode( $a ) );
check( '  and reports no occurrences, since none were linkable', 0 === $a['occurrences'] );

$a = DOS_Links_Engine::analyse( '<p>nothing relevant here</p>', $r, 205 );
check( 'a phrase that appears nowhere reports absent', 'absent' === $a['reason'] );

echo "\n--- one page linking out to several different pages ---\n";

$page = '<p>We handle roof repair across the valley.</p>'
      . '<p>Ask about gutter cleaning too.</p>'
      . '<p>And our solar panel install service.</p>';

$three = array(
    rule( array( 'id' => 1, 'phrase' => 'roof repair',         'target_id' => 101 ) ),
    rule( array( 'id' => 2, 'phrase' => 'gutter cleaning',     'target_id' => 102 ) ),
    rule( array( 'id' => 3, 'phrase' => 'solar panel install', 'target_id' => 103 ) ),
);

$out = DOS_Links_Engine::apply( $page, $three, 5 );

check( 'places one link per phrase on the same page', 3 === substr_count( $out['html'], '<a ' ), $out['html'] );
check( '  each attributed to its own rule', false !== strpos( $out['html'], 'data-dos-link="1"' ) && false !== strpos( $out['html'], 'data-dos-link="2"' ) && false !== strpos( $out['html'], 'data-dos-link="3"' ) );
check( '  and reports all three', array( 1 => 1, 2 => 1, 3 => 1 ) === $out['added'], json_encode( $out['added'] ) );

// Each phrase keeps its own per-page allowance rather than sharing one.
$repeated = '<p>roof repair here and roof repair there.</p><p>gutter cleaning as well.</p>';
$out = DOS_Links_Engine::apply( $repeated, array(
    rule( array( 'id' => 1, 'phrase' => 'roof repair', 'target_id' => 101, 'max_per_page' => 2 ) ),
    rule( array( 'id' => 2, 'phrase' => 'gutter cleaning', 'target_id' => 102, 'max_per_page' => 1 ) ),
), 5 );
check( 'per-page limits are per phrase, not shared', 3 === substr_count( $out['html'], '<a ' ), $out['html'] );
check( '  two for the phrase allowed two', 2 === $out['added'][1], json_encode( $out['added'] ) );
check( '  one for the phrase allowed one', 1 === $out['added'][2] );

// A page that is the destination of one rule still links out via the others.
$out = DOS_Links_Engine::apply( $page, $three, 102 );
check( 'the destination page still links out on its other phrases', 2 === substr_count( $out['html'], '<a ' ), $out['html'] );
check( '  but not to itself', false === strpos( $out['html'], 'data-dos-link="2"' ), $out['html'] );

echo "\n--- the per-page limit ---\n";

// Twelve phrases, one occurrence each, on one page.
$body = '';
$many = array();
for ( $i = 1; $i <= 12; $i++ ) {
    $body  .= "<p>Talking about widget number {$i} today.</p>";
    $many[] = rule( array( 'id' => $i, 'phrase' => "widget number {$i}", 'target_id' => 100 + $i, 'links_made' => 0 ) );
}

$out = DOS_Links_Engine::apply( $body, $many, 5, 10 );
check( 'stops at the limit', 10 === substr_count( $out['html'], '<a ' ), substr_count( $out['html'], '<a ' ) . ' links' );
check( '  and says it was reached', ! empty( $out['capped'] ) );
check( '  counts add up to the limit', 10 === array_sum( $out['added'] ), json_encode( $out['added'] ) );

$out = DOS_Links_Engine::apply( $body, array_slice( $many, 0, 4 ), 5, 10 );
check( 'leaves a page under the limit alone', 4 === substr_count( $out['html'], '<a ' ) );
check( '  and does not claim it was capped', empty( $out['capped'] ) );

$out = DOS_Links_Engine::apply( $body, $many, 5, 0 );
check( 'a limit of zero means no limit', 12 === substr_count( $out['html'], '<a ' ) );

echo "\n--- links already placed count against the limit ---\n";
$half = '<p>' . str_repeat( '<a href="/x" class="dos-ilink" data-dos-link="99">x</a> ', 8 ) . '</p>' . $body;
$out  = DOS_Links_Engine::apply( $half, $many, 5, 10 );
check( 'only the remaining allowance is used', 2 === count( $out['added'] ) ? true : 2 === array_sum( $out['added'] ), json_encode( $out['added'] ) );

$full = '<p>' . str_repeat( '<a href="/x" class="dos-ilink" data-dos-link="99">x</a> ', 10 ) . '</p>' . $body;
$out  = DOS_Links_Engine::apply( $full, $many, 5, 10 );
check( 'a page already at the limit gains nothing', array() === $out['added'], json_encode( $out['added'] ) );
check( '  and is reported as capped', ! empty( $out['capped'] ) );

echo "\n--- the limit favours the least-used phrases ---\n";

// Three phrases competing for two slots: one has placed 500 links across the
// site already, the others almost none.
$grouped = array(
    1 => array( array( 'offset' => 0, 'rule_id' => 1 ), array( 'offset' => 10, 'rule_id' => 1 ) ),
    2 => array( array( 'offset' => 20, 'rule_id' => 2 ) ),
    3 => array( array( 'offset' => 30, 'rule_id' => 3 ) ),
);
$weights = array( 1 => 500, 2 => 3, 3 => 0 );

$kept = DOS_Links_Engine::select( $grouped, $weights, 2 );
$ids  = array_column( $kept, 'rule_id' );
sort( $ids );
check( 'the two quietest phrases win the slots', array( 2, 3 ) === $ids, json_encode( $ids ) );
check( '  and the busiest gets none', ! in_array( 1, $ids, true ) );

$kept = DOS_Links_Engine::select( $grouped, $weights, 3 );
$ids  = array_column( $kept, 'rule_id' );
sort( $ids );
check( 'a third slot goes to the busiest only once the others are served', array( 1, 2, 3 ) === $ids, json_encode( $ids ) );

// Every phrase gets a first link before any gets a second.
$grouped = array(
    1 => array( array( 'offset' => 0, 'rule_id' => 1 ), array( 'offset' => 5, 'rule_id' => 1 ), array( 'offset' => 9, 'rule_id' => 1 ) ),
    2 => array( array( 'offset' => 20, 'rule_id' => 2 ) ),
);
$kept = DOS_Links_Engine::select( $grouped, array( 1 => 0, 2 => 0 ), 2 );
$ids  = array_column( $kept, 'rule_id' );
sort( $ids );
check( 'one phrase cannot take the whole allowance', array( 1, 2 ) === $ids, json_encode( $ids ) );

$a = DOS_Links_Engine::select( $grouped, array( 1 => 0, 2 => 0 ), 3 );
$b = DOS_Links_Engine::select( $grouped, array( 1 => 0, 2 => 0 ), 3 );
check( 'the same page is decided the same way every run', array_column( $a, 'offset' ) === array_column( $b, 'offset' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
