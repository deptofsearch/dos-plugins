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

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
