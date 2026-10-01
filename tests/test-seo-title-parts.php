<?php
/**
 * The "Add the site name to page titles" setting. Pinned: the default leaves
 * core's titles alone, turning it off drops only the site-name part, the
 * homepage keeps its name, and og:title follows the <title>.
 */
require __DIR__ . '/wp-stubs-seo.php';

$pass = 0;
$fail = 0;

function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

$GLOBALS['options']['blog_public'] = 1;
DOS_Module_SEO::init();

function view( array $state ) {
    DOS_Module_SEO::reset_context_cache();
    $GLOBALS['state'] = $state;
}

function head() {
    ob_start();
    DOS_Module_SEO::render_head();
    return ob_get_clean();
}

function og_title( $out ) {
    return preg_match( '#property="og:title" content="([^"]*)"#', $out, $m ) ? $m[1] : null;
}

// What core hands the filter on a page and on the front page.
$page_parts  = array( 'title' => 'Kennewick WA', 'site' => 'Real Estate Values Near Me' );
$front_parts = array( 'title' => 'Real Estate Values Near Me', 'tagline' => 'What&#039;s My Home Worth' );

function page_view( $parts ) {
    return array(
        'view' => 'singular', 'post_type' => 'page', 'queried_id' => 9, 'title_parts' => $parts,
        'post' => (object) array( 'ID' => 9, 'post_title' => 'Kennewick WA', 'post_excerpt' => 'An excerpt.', 'post_content' => '' ),
    );
}

echo "--- hooked ---\n";
check( 'init() registers document_title_parts', ! empty( $GLOBALS['filters']['document_title_parts'] ) );

echo "\n--- default: unchanged ---\n";
view( page_view( $page_parts ) );
check( 'with the setting never saved, the site name stays', 'Kennewick WA &#8211; Real Estate Values Near Me' === wp_get_document_title(), wp_get_document_title() );

DOS_Settings::update( array( 'seo_title_site_name' => 1 ) );
view( page_view( $page_parts ) );
check( 'with it saved on, the site name stays', 'Kennewick WA &#8211; Real Estate Values Near Me' === wp_get_document_title(), wp_get_document_title() );

echo "\n--- off: suffix dropped ---\n";
DOS_Settings::update( array( 'seo_title_site_name' => 0 ) );
view( page_view( $page_parts ) );
check( 'the page title loses the site name', 'Kennewick WA' === wp_get_document_title(), wp_get_document_title() );
check( 'og:title follows', 'Kennewick WA' === og_title( head() ), (string) og_title( head() ) );

$parts = DOS_Module_SEO::filter_title_parts( array( 'title' => 'Archives', 'page' => 'Page 2', 'site' => 'X' ) );
check( 'only the site part goes; page number survives', array( 'title' => 'Archives', 'page' => 'Page 2' ) === $parts, json_encode( $parts ) );

view( array( 'view' => 'front', 'queried_id' => 0, 'title_parts' => $front_parts ) );
check( 'the homepage keeps its name and tagline', 'Real Estate Values Near Me &#8211; What&#039;s My Home Worth' === wp_get_document_title(), wp_get_document_title() );

echo "\n--- a custom SEO title still wins ---\n";
$GLOBALS['pmeta'][9][ DOS_Module_SEO::TITLE_KEY ] = 'Kennewick Home Values';
view( page_view( $page_parts ) );
check( 'the hand-written title is used whole', 'Kennewick Home Values' === wp_get_document_title(), wp_get_document_title() );
unset( $GLOBALS['pmeta'][9] );

echo "\n--- non-array input is passed through ---\n";
check( 'a string from a misbehaving filter is returned untouched', 'x' === DOS_Module_SEO::filter_title_parts( 'x' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
