<?php
require __DIR__ . '/wp-stubs-seo.php';

$pass = 0;
$fail = 0;

DOS_Settings::update( array(
    'seo_home_description' => 'The homepage description, set by hand.',
    'seo_fallback_image'   => 42,
    'seo_twitter'          => '@deptofsearch',
    'seo_noindex_author'   => 1,
) );

function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

function render( $label, array $state, array $expect = array() ) {
    $GLOBALS['state'] = $state;
    ob_start();
    DOS_Module_SEO::render_head();
    $out = ob_get_clean();

    echo "$label\n";

    preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $out, $m );
    $json = $m ? json_decode( $m[1], true ) : null;

    check( '  emits valid JSON-LD', is_array( $json ) && isset( $json['@graph'] ) );

    $types = $json ? array_map( function ( $n ) { return $n['@type']; }, $json['@graph'] ) : array();

    foreach ( $expect as $needle ) {
        check( "  contains: $needle", false !== strpos( $out, $needle ), substr( $out, 0, 0 ) );
    }

    if ( isset( $expect['__types'] ) ) {
        check( '  graph types', $types === $expect['__types'], implode( ',', $types ) );
    }

    echo "\n";
}

render( 'SINGULAR POST', array(
    'view'      => 'singular',
    'post_type' => 'post',
    'queried_id'=> 7,
    'thumb_id'  => 42,
    'post'      => (object) array(
        'ID' => 7,
        'post_excerpt' => '',
        'post_content' => 'A long body of text that runs well past one hundred and fifty five characters so that the truncation logic has something real to chew on, including trailing words that must be cut on a word boundary rather than mid-word.',
    ),
), array(
    'content="index, follow',
    '<link rel="canonical" href="https://example.com/hello-world/">',
    'property="og:type" content="article"',
    '"@type":"BlogPosting"',
    '"@type":"WebPage"',
    'chew on, including…',
) );

render( 'FRONT PAGE', array( 'view' => 'front', 'queried_id' => 2, 'post' => null ), array(
    'content="The homepage description, set by hand."',
    '<link rel="canonical" href="https://example.com/">',
) );
render( 'AUTHOR ARCHIVE', array( 'view' => 'author', 'queried_id' => 3 ), array(
    '<meta name="robots" content="noindex, follow">',
) );
render( 'SEARCH', array( 'view' => 'search' ), array(
    '<meta name="robots" content="noindex, follow">',
) );
// Page 2 must canonicalise to itself, not to page 1.
render( 'CATEGORY page 2', array(
    'view' => 'category',
    'paged' => 2,
    'queried_object' => (object) array( 'name' => 'News', 'description' => '' ),
), array(
    '<link rel="canonical" href="https://example.com/page/2/">',
    '<link rel="prev" href="https://example.com/page/1/">',
    '<link rel="next" href="https://example.com/page/3/">',
) );

echo "$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
