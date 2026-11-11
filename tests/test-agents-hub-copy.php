<?php
/**
 * DoS - Agents: the hub copy fixes and the blog index heading (includes/hub-copy.php).
 *
 * Uses the live content of the Washington, Oregon and District of Columbia hubs (content.rendered, 2026-11-11) and
 * the live blog index page.
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

function hcp( $name ) {
	return (string) file_get_contents( __DIR__ . '/fixtures-hubs/' . $name );
}
function hcp_paras( $html ) {
	preg_match_all( '#<p\b[^>]*>(.*?)</p>#s', substr( $html, (int) strrpos( $html, '</table>' ) ), $m );
	return array_map( static function ( $p ) { return trim( html_entity_decode( strip_tags( $p ), ENT_QUOTES, 'UTF-8' ) ); }, $m[1] );
}

echo "--- The stock paragraph ---\n";
$wa  = hcp( 'in-washington.html' );
$out = dos_agents_hub_trim_copy( $wa, 'WA' );
$p   = hcp_paras( $out );
check( 'Washington: "we help connect you with trusted" paragraph gone', false === stripos( $out, 'we help connect' ) && false === stripos( $out, 'trusted real estate agents' ) );
check( 'Washington: the top-cities paragraph stays, with its links', 1 === count( $p ) && 0 === strpos( $p[0], 'Some of the top cities in Washington include Seattle' ) && false !== strpos( $out, 'href="https://bestrealestateagentsnearme.com/in-seattle-wa/">Seattle</a>' ), json_encode( $p ) );
check( 'Washington: everything before the closing copy is unchanged', substr( $wa, 0, strrpos( $wa, '</table>' ) ) === substr( $out, 0, strrpos( $out, '</table>' ) ) );

$or  = hcp( 'in-oregon.html' );
$out = dos_agents_hub_trim_copy( $or, 'OR' );
$p   = hcp_paras( $out );
echo '  Oregon closing paragraphs: ' . count( hcp_paras( $or ) ) . ' -> ' . count( $p ) . "\n";
check( 'Oregon: both "we help connect" paragraphs gone', false === stripos( $out, 'we help connect' ) );
check( 'Oregon: the first top-cities paragraph kept, the second dropped', 1 === substr_count( $out, 'Some of the top cities in Oregon' ) && false !== strpos( $out, 'Portland, Eugene, Salem, Bend' ) || 1 === substr_count( $out, 'Some of the top cities in Oregon' ), json_encode( $p ) );
check( 'Oregon: the "searching for the best real estate agent" and "Finding the best" paragraphs stay', 2 === count( array_filter( $p, static function ( $x ) { return 0 === strpos( $x, 'If you are searching' ) || 0 === strpos( $x, 'Finding the best real estate agent in Oregon' ); } ) ), json_encode( $p ) );
check( 'Oregon: 6 closing paragraphs become 3', 3 === count( $p ), (string) count( $p ) );

$dc = hcp( 'in-district-of-columbia.html' );
check( 'District of Columbia (no stock paragraph): unchanged', $dc === dos_agents_hub_trim_copy( $dc, 'DC' ) );

echo "\n--- Plain paragraphs (no <p>, as wpautop sees them) ---\n";
$plain = "<table class=\"breanm-city-table\"><tr><td>x</td></tr></table>\n\nAt Best Real Estate Agents Near Me, we help connect you with trusted real estate agents in Idaho.\nA second line of the same paragraph.\n\nSome of the top cities in Idaho include Boise.\n\nFinding the best real estate agent in Idaho matters.\n\nSome of the top cities in Idaho include Meridian.";
$o = dos_agents_hub_trim_copy( $plain, 'ID' );
check( 'plain: the stock paragraph and its second line are gone', false === strpos( $o, 'we help connect' ) && false === strpos( $o, 'A second line' ), json_encode( $o ) );
check( 'plain: first top-cities kept, second dropped, others kept', false !== strpos( $o, 'include Boise' ) && false === strpos( $o, 'include Meridian' ) && false !== strpos( $o, 'Finding the best real estate agent in Idaho' ), json_encode( $o ) );
check( 'plain: the paragraphs stay separated by blank lines', false !== strpos( $o, "Boise.\n\nFinding" ), json_encode( $o ) );
check( 'another state\'s top-cities paragraph is not touched', $plain !== dos_agents_hub_trim_copy( $plain, 'WA' ) && 2 === substr_count( dos_agents_hub_trim_copy( $plain, 'WA' ), 'Some of the top cities in Idaho' ) );
$GLOBALS['filters']['dos_agents_hub_copy'][] = '__return_false';
check( 'dos_agents_hub_copy false: unchanged', $wa === dos_agents_hub_trim_copy( $wa, 'WA' ) );
unset( $GLOBALS['filters']['dos_agents_hub_copy'] );

echo "\n--- Next step and anchor ---\n";
$a = dos_agents_hub_table_anchor( $wa );
check( 'the city table gets id="all-cities", once', 1 === substr_count( $a, 'id="all-cities"' ) && false !== strpos( $a, '<table id="all-cities" ' ) );
check( 'a table that already has an id keeps it', '<table id="x" class="breanm-city-table">' === dos_agents_hub_table_anchor( '<table id="x" class="breanm-city-table">' ) );
check( 'no city table: unchanged', 'words' === dos_agents_hub_table_anchor( 'words' ) );
agents_reset();
update_option( dos_agents_option_name( 'seattle-wa' ), array( 'city' => 'Seattle', 'state' => 'WA', 'agents' => array( agents_make() ) ), false );
agents_add_page( 'in-seattle-wa', '[breanm_agents city="seattle-wa"]' );
dos_agents_index_touch( 'seattle-wa' );
$n = dos_agents_render_hub_next( 'WA', 470, true );
check( 'next step with the link', '<p class="breanm-hub-next">Pick a city below to compare its agents, or find yours among <a href="#all-cities">all 470 cities in Washington</a>.</p>' === $n, $n );
check( 'next step without an anchor: first half only', '<p class="breanm-hub-next">Pick a city below to compare its agents.</p>' === dos_agents_render_hub_next( 'WA', 470, false ) );
check( 'a state with no agent lists: no next step', '' === dos_agents_render_hub_next( 'AL', 573, true ) );

echo "\n--- On the hub ---\n";
$GLOBALS['filters']['dos_agents_pre_empty_city_ids'][] = static function () { return array(); };
$GLOBALS['filters']['dos_agents_pre_hub_city_pages'][] = static function () { return array(); };
$hub = agents_add_page( 'in-washington', $wa );
$GLOBALS['wp']['page']     = true;
$GLOBALS['wp']['singular'] = true;
$GLOBALS['wp']['queried']  = $hub->ID;
$GLOBALS['wp']['post']     = $hub;
$o  = dos_agents_hub_the_content( $wa );
$at = array_map( static function ( $s ) use ( $o ) { return strpos( $o, $s ); }, array( 'breanm-hub-title', 'breanm-hub-facts', 'breanm-hub-next', 'breanm-home__hub', 'breanm-hub-source', 'id="all-cities"', 'Some of the top cities' ) );
$sorted = $at;
sort( $sorted );
check( 'order: heading, facts, next step, city boxes, source line, table, closing copy', ! in_array( false, $at, true ) && $at === $sorted, json_encode( $at ) );
check( 'no stock paragraph on the hub', false === stripos( $o, 'we help connect' ) );
check( 'the next step counts the hub\'s own cities', false !== strpos( $o, 'all 470 cities in Washington' ) );

echo "\n--- Blog index heading ---\n";
$blog = hcp( 'blog-page.html' );
$b    = dos_agents_blog_add_head( $blog );
check( 'one H1 added, inside the page content', 1 === substr_count( $b, '<h1' ) && false !== strpos( $b, '<div class="page-content entry-content"><header class="breanm-blog-head"><h1 class="breanm-blog-title">Articles on choosing a real estate agent</h1>' ) );
check( 'the rest of the page is unchanged', str_replace( dos_agents_blog_head_html(), '', $b ) === $blog );
check( 'a page whose main content already has an H1: unchanged', ( function () use ( $blog ) { $x = str_replace( '<div class="page-content entry-content">', '<div class="page-content entry-content"><h1>Mine</h1>', $blog ); return $x === dos_agents_blog_add_head( $x ); } )() );
check( 'no Themify content markup: unchanged', '<html><body>hi</body></html>' === dos_agents_blog_add_head( '<html><body>hi</body></html>' ) );
check( 'intro has no em dash', false === strpos( dos_agents_blog_head_html(), "\u{2014}" ) );
$GLOBALS['wp']['page']        = true;
$GLOBALS['wp']['queried_obj'] = new WP_Post( array( 'ID' => 149, 'post_type' => 'page', 'post_name' => 'blog', 'post_parent' => 0 ) );
check( 'the top-level blog page is the blog index', dos_agents_is_blog_index() );
$GLOBALS['wp']['queried_obj'] = new WP_Post( array( 'ID' => 150, 'post_type' => 'page', 'post_name' => 'blog', 'post_parent' => 7 ) );
check( 'a child page called blog is not', ! dos_agents_is_blog_index() );
echo "\n--- Review fixes (0.3.8) ---\n";
$stock = 'At Best Real Estate Agents Near Me, we help connect you with trusted real estate agents in Idaho.';
$o = dos_agents_hub_trim_copy( $stock . "\n<p>Some of the top cities in Idaho include Boise.</p>\n<p>Finding the best agent.</p>\n<h2>Keep me</h2>\n<div>and me</div>", 'ID' );
check( 'raw stock paragraph followed by <p> on the next line: only it goes', false === strpos( $o, 'we help connect' ) && false !== strpos( $o, 'include Boise' ) && false !== strpos( $o, 'Finding the best agent' ) && false !== strpos( $o, 'Keep me' ) && false !== strpos( $o, 'and me' ), json_encode( $o ) );
$o = dos_agents_hub_trim_copy( $stock . "\n<h2>Cities</h2>\n<table class=\"breanm-city-table\"><tr><td>x</td></tr></table>\n<p>end</p>", 'ID' );
check( 'raw stock paragraph followed by a heading and the table: both kept', false === strpos( $o, 'we help connect' ) && false !== strpos( $o, '<h2>Cities</h2>' ) && false !== strpos( $o, 'breanm-city-table' ) && false !== strpos( $o, '<p>end</p>' ), json_encode( $o ) );
$long = $stock . ' ' . str_repeat( 'More words in the same run. ', 100 );
check( 'a match over 2,000 characters is left alone', $long === dos_agents_hub_trim_copy( $long, 'ID' ) );
$crlf = "<table class=\"breanm-city-table\"></table>\r\n\r\n" . $stock . "\r\n\r\nFinding the best real estate agent in Idaho.";
$o = dos_agents_hub_trim_copy( $crlf, 'ID' );
check( 'CRLF line endings: stock paragraph gone, the next one kept', false === strpos( $o, 'we help connect' ) && false !== strpos( $o, 'Finding the best real estate agent in Idaho.' ), json_encode( $o ) );
$al = hcp( 'in-alabama.html' );
$o  = dos_agents_hub_trim_copy( $al, 'AL' );
check( 'the live Alabama hub: stock paragraph gone, city table and other paragraphs intact', false === stripos( $o, 'we help connect' ) && substr_count( $o, 'href=' ) === substr_count( $al, 'href=' ) && false !== strpos( $o, 'Some of the top cities in Alabama' ) );
check( 'data-id on the table does not count as an id', 1 === substr_count( dos_agents_hub_table_anchor( '<table data-id="5" class="breanm-city-table">' ), 'id="all-cities"' ) );
$chunk = substr( $blog, 0, strpos( $blog, '</main>' ) );
check( 'a flushed chunk without </main>: no heading added', $chunk === dos_agents_blog_add_head( $chunk ) );
$GLOBALS['wp']['queried_obj'] = new WP_Post( array( 'ID' => 149, 'post_type' => 'page', 'post_name' => 'blog', 'post_parent' => 0 ) );
$GLOBALS['filters']['dos_agents_blog_head'][] = '__return_false';
$level = ob_get_level();
dos_agents_blog_start_buffer();
check( 'dos_agents_blog_head false: no buffer started', ob_get_level() === $level );
unset( $GLOBALS['filters']['dos_agents_blog_head'] );

$main = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php' );
check( 'blog buffer hooked on template_redirect', false !== strpos( $main, "add_action( 'template_redirect', 'dos_agents_blog_start_buffer', 2 );" ) );

finish();
