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

// Default WordPress install: search engines are welcome.
$GLOBALS['options']['blog_public'] = 1;

function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

function render( $label, array $state, array $expect = array() ) {
    // context() now memoizes; each render() call here stands in for a fresh
    // request, so the cache from the previous one must not leak into it.
    DOS_Module_SEO::reset_context_cache();
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
// Robots directives moved to the wp_robots filter — see the ROBOTS FILTER
// block below — so noindex views are no longer asserted from render_head()
// output here.
render( 'AUTHOR ARCHIVE', array( 'view' => 'author', 'queried_id' => 3 ) );
render( 'SEARCH', array( 'view' => 'search' ) );
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

// WordPress returns titles containing HTML entities. That is right for
// markup and wrong for JSON-LD, which is data.
render( 'TITLE WITH AN ENTITY', array(
    'view'      => 'singular',
    'post_type' => 'page',
    'queried_id'=> 9,
    'title'     => 'Open Houses &#8211; Phoenix',
    'post'      => (object) array( 'ID' => 9, 'post_excerpt' => 'Short excerpt.', 'post_content' => '' ),
), array(
    '"name":"Open Houses – Phoenix"',
) );

$GLOBALS['state'] = array(
    'view' => 'singular', 'post_type' => 'page', 'queried_id' => 9,
    'title' => 'Open Houses &#8211; Phoenix',
    'post' => (object) array( 'ID' => 9, 'post_excerpt' => 'Short excerpt.', 'post_content' => '' ),
);
ob_start(); DOS_Module_SEO::render_head(); $out = ob_get_clean();
check( '  and the raw entity never reaches the schema', false === strpos( $out, '&#8211;' ), $out );

echo "AMPERSAND IN SITE NAME\n";
// hvactricities.com: get_bloginfo('name') returns display-filtered text, where
// an "&" is already the entity "&amp;" — right for an HTML attribute, wrong
// inside JSON-LD, where it is literal text a consumer reads back as "&amp;".
DOS_Module_SEO::reset_context_cache();
$GLOBALS['state'] = array(
    'view'      => 'singular',
    'post_type' => 'page',
    'queried_id'=> 11,
    'title'     => 'Emergency Repairs | Acme Heating &amp; Air',
    'post'      => (object) array( 'ID' => 11, 'post_excerpt' => '', 'post_content' => '' ),
);
ob_start(); DOS_Module_SEO::render_head(); $out = ob_get_clean();

preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $out, $m );
$json  = $m ? json_decode( $m[1], true ) : array( '@graph' => array() );
$nodes = array();
foreach ( $json['@graph'] as $node ) {
    $nodes[ $node['@type'] ] = $node;
}

check( '  Organization name is decoded, not the raw entity', ( $nodes['Organization']['name'] ?? null ) === 'Acme Heating & Air', $nodes['Organization']['name'] ?? 'missing' );
check( '  WebSite name is decoded too', ( $nodes['WebSite']['name'] ?? null ) === 'Acme Heating & Air', $nodes['WebSite']['name'] ?? 'missing' );

preg_match( '#<meta property="og:site_name" content="([^"]*)">#', $out, $m );
$site_name_attr = $m[1] ?? '';
check(
    '  og:site_name reads &amp; exactly once, not double-encoded',
    1 === substr_count( $site_name_attr, '&amp;' ) && false === strpos( $site_name_attr, '&amp;amp;' ),
    $site_name_attr
);

preg_match( '#<meta name="description" content="([^"]*)">#', $out, $m );
$description_attr = $m[1] ?? '';
check(
    '  floor_description strips " | Acme Heating & Air" off the fallback title, ampersand and all',
    'Emergency Repairs — A site about things' === $description_attr,
    $description_attr
);
echo "\n";

echo "SCHEMA MODE\n";
DOS_Settings::set( 'seo_schema_mode', 'minimal' );
ob_start(); DOS_Module_SEO::render_head(); $out = ob_get_clean();
check( '  minimal mode keeps Organization', false !== strpos( $out, '"@type":"Organization"' ) );
check( '  minimal mode drops WebSite, which a theme may already emit', false === strpos( $out, '"@type":"WebSite"' ), $out );
check( '  minimal mode drops WebPage too', false === strpos( $out, '"@type":"WebPage"' ) );
DOS_Settings::set( 'seo_schema_mode', 'full' );
ob_start(); DOS_Module_SEO::render_head(); $out = ob_get_clean();
check( '  full mode restores them', false !== strpos( $out, '"@type":"WebSite"' ) && false !== strpos( $out, '"@type":"WebPage"' ) );
echo "\n";

echo "ROBOTS FILTER (wp_robots)\n";

// Indexable view: filter_robots() sets the four directives render_head() used to print.
$GLOBALS['options']['blog_public'] = 1;
DOS_Module_SEO::reset_context_cache();
$GLOBALS['state'] = array(
    'view' => 'singular', 'post_type' => 'post', 'queried_id' => 7,
    'post' => (object) array( 'ID' => 7, 'post_excerpt' => 'An excerpt.', 'post_content' => '' ),
);
$robots = DOS_Module_SEO::filter_robots( array() );
check( '  index case sets index', true === ( $robots['index'] ?? null ) );
check( '  index case sets follow', true === ( $robots['follow'] ?? null ) );
check( '  index case sets max-image-preview:large', 'large' === ( $robots['max-image-preview'] ?? null ) );
check( '  index case sets max-snippet:-1', -1 === ( $robots['max-snippet'] ?? null ) );

// Noindex view: no snippet directives belong on a page search engines are told to skip.
DOS_Module_SEO::reset_context_cache();
$GLOBALS['state'] = array( 'view' => 'search' );
$robots = DOS_Module_SEO::filter_robots( array() );
check( '  noindex case sets noindex', true === ( $robots['noindex'] ?? null ) );
check( '  noindex case sets follow', true === ( $robots['follow'] ?? null ) );
check( '  noindex case drops index', ! array_key_exists( 'index', $robots ) );
check( '  noindex case drops max-image-preview', ! array_key_exists( 'max-image-preview', $robots ) );
check( '  noindex case drops max-snippet', ! array_key_exists( 'max-snippet', $robots ) );

// A noindex this module did not set is never overruled. Core marks oEmbed
// iframes and the login screen that way before this filter runs, and turning
// such a page back to indexable would leave no trace on the page itself.
DOS_Module_SEO::reset_context_cache();
$GLOBALS['state'] = array(
    'view' => 'singular', 'post_type' => 'post', 'queried_id' => 7,
    'post' => (object) array( 'ID' => 7, 'post_excerpt' => 'An excerpt.', 'post_content' => '' ),
);
$already = array( 'noindex' => true, 'follow' => true );
$out     = DOS_Module_SEO::filter_robots( $already );
check( '  an existing noindex survives an indexable view', $already === $out, json_encode( $out ) );
check( '    and the page is not made indexable behind its back', ! array_key_exists( 'index', $out ) );

DOS_Module_SEO::reset_context_cache();
$GLOBALS['state'] = array(
    'view' => 'singular', 'post_type' => 'post', 'queried_id' => 7,
    'post' => (object) array( 'ID' => 7, 'post_excerpt' => 'An excerpt.', 'post_content' => '' ),
);
$out = DOS_Module_SEO::filter_robots( array( 'noindex' => false ) );
check( '    but a noindex of false is not an objection', true === ( $out['index'] ?? null ), json_encode( $out ) );

// A site discouraging search engines is never overridden, even on a view
// that would otherwise be indexable.
$GLOBALS['options']['blog_public'] = 0;
DOS_Module_SEO::reset_context_cache();
$GLOBALS['state'] = array(
    'view' => 'singular', 'post_type' => 'post', 'queried_id' => 7,
    'post' => (object) array( 'ID' => 7, 'post_excerpt' => 'An excerpt.', 'post_content' => '' ),
);
$seed = array( 'noindex' => true );
check( '  blog_public off returns $robots untouched', $seed === DOS_Module_SEO::filter_robots( $seed ) );
$GLOBALS['options']['blog_public'] = 1;

// render_head() used to print its own tag alongside this filter's — the bug this fixes.
DOS_Module_SEO::reset_context_cache();
$GLOBALS['state'] = array(
    'view' => 'singular', 'post_type' => 'post', 'queried_id' => 7,
    'post' => (object) array( 'ID' => 7, 'post_excerpt' => 'An excerpt.', 'post_content' => '' ),
);
ob_start(); DOS_Module_SEO::render_head(); $out = ob_get_clean();
check( '  render_head() no longer prints its own robots tag', false === strpos( $out, 'name="robots"' ), $out );
echo "\n";

echo "$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
