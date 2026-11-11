<?php
/**
 * DoS - Agents: city pages with no agents are noindex, follow and out of the sitemap.
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

$fails = 0;
function ec_check( $label, $cond ) {
	global $fails;
	if ( ! $cond ) {
		$fails++;
		echo "FAIL: $label\n";
	}
}

/** The live content of /in-vesta-ga/ (2026-11-10), as stored. */
$empty = '<h3 style="font-family: Verdana, Geneva, sans-serif; color: #000000; margin: 0 0 16px 0; font-size: 20px; line-height: 1.35;">Real Estate Agents in Vesta, GA</h3>' . "\n"
	. '<p style="font-family: Verdana, Geneva, sans-serif; color: #000000; margin: 0 0 16px 0; line-height: 1.55;">There are no agents we have found that service this area. If you are looking for the best real estate agent in Vesta, GA, please check neighboring areas, since brokerages in nearby cities and towns often serve clients here as well.</p>' . "\n"
	. '<p style="font-family: Verdana, Geneva, sans-serif; color: #000000; margin: 0 0 16px 0; line-height: 1.55;">This page is refreshed as new client review data becomes available, so the best real estate agent near me in Vesta, GA may be listed here in the future.</p>';
$cards = '<h2>Real Estate Agents in Boise, ID</h2>[breanm_agents city="boise-id"]';

/** wpdb that records the scan and returns fixed IDs. */
class EC_WPDB extends Agents_WPDB {
	public $posts      = 'wp_posts';
	public $last_error = '';
	public $scans      = 0;
	public $sql        = '';
	public $ids        = array( '501', '502' );
	public function get_col( $sql ) {
		$this->scans++;
		$this->sql = $sql;
		return $this->ids;
	}
}

function ec_robots( $post ) {
	$GLOBALS['wp']['page']        = true;
	$GLOBALS['wp']['queried_obj'] = $post;
	return dos_agents_empty_robots( array( 'max-image-preview' => 'large' ) );
}

// ---- detection --------------------------------------------------------------------------------
ec_check( 'live empty content is empty', dos_agents_is_empty_city_content( $empty ) );
ec_check( 'cards page is not empty', ! dos_agents_is_empty_city_content( $cards ) );
ec_check( 'sentence plus shortcode is not empty (cards arrived)', ! dos_agents_is_empty_city_content( $empty . "\n[breanm_agents city=\"vesta-ga\"]" ) );
ec_check( 'brokerage page is not empty', ! dos_agents_is_empty_city_content( '<h3>Top-Rated Real Estate Brokerages in X, AL: ...</h3><li>(Sales: 3; Rating: 5.0)</li>' ) );
ec_check( 'blank is not empty', ! dos_agents_is_empty_city_content( '' ) );

// ---- robots -----------------------------------------------------------------------------------
$p = agents_add_page( 'in-vesta-ga', $empty );
$r = ec_robots( $p );
ec_check( 'empty page: noindex', ! empty( $r['noindex'] ) && ! isset( $r['index'] ) );
ec_check( 'empty page: follow', ! empty( $r['follow'] ) );
ec_check( 'empty page: other directives kept', 'large' === ( $r['max-image-preview'] ?? null ) );

$c = agents_add_page( 'in-boise-id', $cards );
$r = ec_robots( $c );
ec_check( 'cards page: untouched', array( 'max-image-preview' => 'large' ) === $r );

$d = agents_add_page( 'in-draft-zz', $empty, 'draft' );
ec_check( 'draft with the sentence: untouched', array( 'max-image-preview' => 'large' ) === ec_robots( $d ) );

$post = new WP_Post( array( 'ID' => 9, 'post_type' => 'post', 'post_content' => $empty ) );
ec_check( 'a post quoting the sentence: untouched', array( 'max-image-preview' => 'large' ) === ec_robots( $post ) );

$GLOBALS['wp']['page'] = false;
$GLOBALS['wp']['queried_obj'] = $p;
ec_check( 'not is_page(): untouched', array( 'a' => 1 ) === dos_agents_empty_robots( array( 'a' => 1 ) ) );

ec_check( 'wp_robots hooked', in_array( 'dos_agents_empty_robots', $GLOBALS['filters']['wp_robots'] ?? array(), true ) );
ec_check( 'sitemap args hooked', in_array( 'dos_agents_empty_sitemap_args', $GLOBALS['filters']['wp_sitemaps_posts_query_args'] ?? array(), true ) );

// ---- sitemap: the scan, cached --------------------------------------------------------------
$db = new EC_WPDB();
$GLOBALS['wpdb'] = $db;
$args = dos_agents_empty_sitemap_args( array( 'post_type' => 'page', 'post__not_in' => array( 7 ) ), 'page' );
ec_check( 'pages sitemap: empty IDs excluded, existing exclusion kept', array( 7, 501, 502 ) === $args['post__not_in'] );
ec_check( 'one scan', 1 === $db->scans );
ec_check( 'scan is published pages with the sentence and without the shortcode',
	false !== strpos( $db->sql, "post_type = 'page'" ) && false !== strpos( $db->sql, "post_status = 'publish'" )
	&& false !== strpos( $db->sql, "LIKE '%There are no agents we have found that service this area%'" )
	&& false !== strpos( $db->sql, "NOT LIKE '%[breanm\\_agents%'" ) );
ec_check( 'result cached for 12 hours', array( 501, 502 ) === get_transient( DOS_AGENTS_EMPTY_TRANSIENT ) );
dos_agents_empty_sitemap_args( array(), 'page' );
ec_check( 'memo: no second scan in the request', 1 === $db->scans );
ec_check( 'posts sitemap: untouched', array( 'post_type' => 'post' ) === dos_agents_empty_sitemap_args( array( 'post_type' => 'post' ), 'post' ) );

// ---- invalidation ---------------------------------------------------------------------------
set_transient( DOS_AGENTS_EMPTY_TRANSIENT, array( 501, 502 ) );
$still = new WP_Post( array( 'ID' => 501, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $empty ) );
dos_agents_empty_on_save( 501, $still );
ec_check( 'empty page saved still empty: cache kept', false !== get_transient( DOS_AGENTS_EMPTY_TRANSIENT ) );

$filled = new WP_Post( array( 'ID' => 501, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $cards ) );
dos_agents_empty_on_save( 501, $filled );
ec_check( 'cards arrive: cache cleared', false === get_transient( DOS_AGENTS_EMPTY_TRANSIENT ) );

set_transient( DOS_AGENTS_EMPTY_TRANSIENT, array( 501, 502 ) );
$other = new WP_Post( array( 'ID' => 600, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $cards ) );
dos_agents_empty_on_save( 600, $other );
ec_check( 'ordinary cards page saved: cache kept', false !== get_transient( DOS_AGENTS_EMPTY_TRANSIENT ) );

$newly = new WP_Post( array( 'ID' => 601, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $empty ) );
dos_agents_empty_on_save( 601, $newly );
ec_check( 'page becomes empty: cache cleared', false === get_transient( DOS_AGENTS_EMPTY_TRANSIENT ) );

set_transient( DOS_AGENTS_EMPTY_TRANSIENT, array( 501, 502 ) );
$trashed = new WP_Post( array( 'ID' => 502, 'post_type' => 'page', 'post_status' => 'trash', 'post_content' => $empty ) );
dos_agents_empty_on_save( 502, $trashed );
ec_check( 'empty page trashed: cache cleared', false === get_transient( DOS_AGENTS_EMPTY_TRANSIENT ) );

set_transient( DOS_AGENTS_EMPTY_TRANSIENT, array( 501, 502 ) );
dos_agents_empty_on_delete( 501 );
ec_check( 'empty page deleted: cache cleared', false === get_transient( DOS_AGENTS_EMPTY_TRANSIENT ) );

set_transient( DOS_AGENTS_EMPTY_TRANSIENT, array( 501, 502 ) );
dos_agents_empty_on_delete( 999 );
ec_check( 'other page deleted: cache kept', false !== get_transient( DOS_AGENTS_EMPTY_TRANSIENT ) );

if ( $fails ) {
	echo "$fails failure(s)\n";
	exit( 1 );
}
echo "empty cities: all passed\n";
