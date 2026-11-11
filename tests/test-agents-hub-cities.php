<?php
/**
 * DoS - Agents: a state hub's city table also links the state's city pages it was missing (includes/hub-cities.php).
 *
 * Uses the live Alabama hub (tests/fixtures-hubs/in-alabama.html, content.rendered on 2026-11-10, 276 links) and
 * the 298 Alabama pages renamed from `__trashed` that day (renamed-al.csv), none of which it links.
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

/** wpdb whose page scan returns fixed rows. */
class HC_WPDB extends Agents_WPDB {
	public $posts      = 'wp_posts';
	public $last_error = '';
	public $scans      = 0;
	public $sql        = '';
	public $rows       = array();
	public function get_results( $sql ) {
		$this->scans++;
		$this->sql = $sql;
		return $this->rows;
	}
}

function hc_row( $id, $slug, $title ) {
	return (object) array( 'ID' => $id, 'post_name' => $slug, 'post_title' => $title );
}

/** The links of the city table: array of array( href, text ), in document order. */
function hc_links( $html ) {
	preg_match( '/<table\b[^>]*breanm-city-table.*?<\/table>/is', $html, $t );
	preg_match_all( '/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', $t[0] ?? '', $m, PREG_SET_ORDER );
	return array_map( static function ( $x ) { return array( $x[1], html_entity_decode( $x[2], ENT_QUOTES, 'UTF-8' ) ); }, $m );
}

/** The table read back down its columns. */
function hc_colmajor( $html ) {
	preg_match( '/<table\b[^>]*breanm-city-table.*?<\/table>/is', $html, $t );
	preg_match_all( '/<tr>(.*?)<\/tr>/is', $t[0], $rows );
	$grid = array_map( static function ( $r ) { preg_match_all( '/>([^<>]+)<\/a>/', $r, $c ); return $c[1]; }, $rows[1] );
	$out  = array();
	for ( $k = 0; $k < 3; $k++ ) {
		foreach ( $grid as $g ) {
			if ( isset( $g[ $k ] ) ) { $out[] = html_entity_decode( $g[ $k ], ENT_QUOTES, 'UTF-8' ); }
		}
	}
	return array( $out, $grid );
}

$hub_html = (string) file_get_contents( __DIR__ . '/fixtures-hubs/in-alabama.html' );
$renamed  = array_map( static function ( $l ) { return str_getcsv( $l, ',', '"', '' ); }, array_slice( file( __DIR__ . '/fixtures-hubs/renamed-al.csv', FILE_IGNORE_NEW_LINES ), 1 ) );
$before   = hc_links( $hub_html );

/** The Alabama pages as the scan would return them: every page the hub links, plus the 298 renamed. */
function hc_al_rows( $before, $renamed ) {
	$rows = array();
	$id   = 100000;
	foreach ( $before as list( $href, $text ) ) {
		preg_match( '#/(in-[a-z0-9-]+-al)/#', $href, $m );
		$rows[] = hc_row( ++$id, $m[1], 'in ' . $text . ' AL' );
	}
	foreach ( $renamed as $r ) {
		$rows[] = hc_row( (int) $r[0], $r[1], 'in ' . $r[2] . ' AL' );
	}
	return $rows;
}

echo "--- The live Alabama hub with the 298 renamed pages ---\n";
agents_reset();
$db       = new HC_WPDB();
$db->rows = hc_al_rows( $before, $renamed );
$GLOBALS['wpdb'] = $db;
// in-sasser-al is the one renamed page that is empty (noindex), so it is not added.
$sasser = 0;
foreach ( $renamed as $r ) { if ( 'in-sasser-al' === $r[1] ) { $sasser = (int) $r[0]; } }
$GLOBALS['filters']['dos_agents_pre_empty_city_ids'][] = static function () use ( $sasser ) { return array( $sasser ); };

$out   = dos_agents_hub_merge_cities( $hub_html, 'AL' );
$after = hc_links( $out );
$slugs = array_map( static function ( $l ) { preg_match( '#/(in-[a-z0-9-]+-al)/#', $l[0], $m ); return $m[1] ?? ''; }, $after );
echo '  links before ' . count( $before ) . ', after ' . count( $after ) . "\n";
check( '276 links before', 276 === count( $before ) );
check( '573 links after: 276 kept + 297 renamed (Sasser is empty)', 573 === count( $after ), (string) count( $after ) );
check( 'every renamed page but Sasser is linked', ! array_diff( array_diff( array_column( $renamed, 1 ), array( 'in-sasser-al' ) ), $slugs ) );
check( 'Sasser is not linked', ! in_array( 'in-sasser-al', $slugs, true ) );
check( 'every link the table had is still there, same href and text', ! array_udiff( $before, $after, static function ( $x, $y ) { return strcmp( $x[0] . '|' . $x[1], $y[0] . '|' . $y[1] ); } ) );
check( 'no link twice', count( $slugs ) === count( array_unique( $slugs ) ) );
check( 'new links are absolute, like the stored ones', in_array( array( 'https://example.com/in-abernant-al/', 'Abernant' ), $after, true ) );
list( $col, $grid ) = hc_colmajor( $out );
$sorted = $col;
usort( $sorted, static function ( $x, $y ) { return strtolower( $x ) <=> strtolower( $y ); } );
check( 'alphabetical down three columns, as stored', $col === $sorted && 573 === count( $col ) );
check( '191 rows of three (573 = 191 x 3)', 191 === count( $grid ) && array( 3 ) === array_values( array_unique( array_map( 'count', $grid ) ) ) );
check( 'heading count follows: All Cities in Alabama (573)', false !== strpos( $out, 'All Cities in Alabama (573)' ) && false === strpos( $out, 'All Cities in Alabama (276)' ) );
preg_match( '/<td\b[^>]*>/', $hub_html, $td0 );
preg_match( '/<a\b[^>]*>/', substr( $hub_html, strpos( $hub_html, 'breanm-city-table' ) ), $a0 );
preg_match_all( '/<td\b[^>]*>/', $out, $tds );
check( 'every cell has the stored <td> tag', array( $td0[0] ) === array_values( array_unique( array_slice( $tds[0], 0 ) ) ) );
check( 'new cells have the stored <a> styling', false !== strpos( $out, preg_replace( '/href="[^"]*"/', 'href="https://example.com/in-abernant-al/"', $a0[0] ) ) );
$cut = static function ( $h ) { return preg_replace( '/<table\b[^>]*breanm-city-table.*?<\/table>/is', '', preg_replace( '/All Cities in Alabama \(\d+\)/', '', $h ) ); };
check( 'everything outside the table and the count is unchanged', $cut( $hub_html ) === $cut( $out ) );
check( 'one scan', 1 === $db->scans );
check( 'scan: published top-level pages with slug in-%-al', false !== strpos( $db->sql, "post_type = 'page'" ) && false !== strpos( $db->sql, "post_status = 'publish'" ) && false !== strpos( $db->sql, 'post_parent = 0' ) && false !== strpos( $db->sql, "LIKE 'in-%-al'" ), $db->sql );
check( 'scan cached for the state: every city page, empty or not (574)', is_array( get_transient( 'breanm_agents_hub_cities_v1_al' ) ) && 574 === count( get_transient( 'breanm_agents_hub_cities_v1_al' ) ) );

echo "\n--- Nothing missing: printed exactly as stored ---\n";
agents_reset();
$db       = new HC_WPDB();
$db->rows = hc_al_rows( $before, array() );
$GLOBALS['wpdb'] = $db;
$GLOBALS['filters']['dos_agents_pre_empty_city_ids'][] = static function () { return array(); };
check( 'a hub that links every page is byte-identical', $hub_html === dos_agents_hub_merge_cities( $hub_html, 'AL' ) );

agents_reset();
$GLOBALS['filters']['dos_agents_pre_empty_city_ids'][] = static function () { return array(); };
$GLOBALS['filters']['dos_agents_pre_hub_city_pages'][] = static function () { return array( array( 7, 'in-new-place-al', 'New Place' ) ); };
check( 'no city table: unchanged', 'Just words.' === dos_agents_hub_merge_cities( 'Just words.', 'AL' ) );
$broken = '<table class="breanm-city-table"><tbody><tr><td>no links</td></tr></tbody></table>';
check( 'a table with no links: unchanged', $broken === dos_agents_hub_merge_cities( $broken, 'AL' ) );
$GLOBALS['filters']['dos_agents_hub_add_missing'][] = '__return_false';
check( 'dos_agents_hub_add_missing false: unchanged', $hub_html === dos_agents_hub_merge_cities( $hub_html, 'AL' ) );

echo "\n--- A small table ---\n";
agents_reset();
$GLOBALS['filters']['dos_agents_pre_empty_city_ids'][] = static function () { return array(); };
$GLOBALS['filters']['dos_agents_pre_hub_city_pages'][] = static function () {
	return array( array( 1, 'in-delta-ri', 'Delta' ), array( 2, 'in-alpha-ri', 'Alpha' ), array( 3, 'in-coeur-d-ri', "Coeur d\u{2019}Ville" ) );
};
$small = '<h3>All Cities in Rhode Island (2)</h3><table class="breanm-city-table" style="x"><tbody><tr>'
	. '<td style="w"><a href="https://example.com/in-bravo-ri/" style="s">Bravo</a></td>'
	. '<td style="w"><a href="https://example.com/in-echo-ri/" style="s">Echo &amp; Co</a></td></tr></tbody></table><p>After.</p>';
$o = dos_agents_hub_merge_cities( $small, 'RI' );
list( $col, $grid ) = hc_colmajor( $o );
check( 'five cells sorted down the columns', array( 'Alpha', 'Bravo', "Coeur d\u{2019}Ville", 'Delta', 'Echo & Co' ) === $col, json_encode( $col ) );
check( 'two rows: 3 then 2 cells', array( 3, 2 ) === array_map( 'count', $grid ), json_encode( array_map( 'count', $grid ) ) );
check( 'entities in a stored name are kept once', false !== strpos( $o, '>Echo &amp; Co</a>' ) && false === strpos( $o, '&amp;amp;' ) );
check( 'count updated, text after the table kept', false !== strpos( $o, 'All Cities in Rhode Island (5)' ) && '<p>After.</p>' === substr( $o, -13 ) );

echo "\n--- City names from titles ---\n";
check( '"in Mc Kee KY"', 'Mc Kee' === dos_agents_hub_city_name( 'in Mc Kee KY', 'KY' ) );
check( '"in Boise, ID"', 'Boise' === dos_agents_hub_city_name( 'in Boise, ID', 'ID' ) );
check( 'entities decoded', "Coeur d\u{2019}Alene" === dos_agents_hub_city_name( 'in Coeur d&#8217;Alene ID', 'ID' ) );
check( 'another state code: no name', '' === dos_agents_hub_city_name( 'in Boise ID', 'AL' ) );
check( 'not a city title: no name', '' === dos_agents_hub_city_name( 'Real deal', 'AL' ) );

echo "\n--- The scan keeps only city pages ---\n";
agents_reset();
$db       = new HC_WPDB();
$db->rows = array(
	hc_row( 1, 'in-abernant-al', 'in Abernant AL' ),
	hc_row( 2, 'in-the-real-deal', 'The real deal' ),
	hc_row( 3, 'in-weird-title-al', 'Weird title' ),
	hc_row( 4, 'in-sub-al-extra', 'in Sub AL' ),
);
$GLOBALS['wpdb'] = $db;
check( 'only "in-<city>-al" with an "in {City} AL" title', array( array( 1, 'in-abernant-al', 'Abernant' ) ) === dos_agents_hub_city_pages( 'AL' ), json_encode( dos_agents_hub_city_pages( 'AL' ) ) );
agents_reset();
$db             = new HC_WPDB();
$db->last_error = 'boom';
$db->rows       = array( hc_row( 1, 'in-abernant-al', 'in Abernant AL' ) );
$GLOBALS['wpdb'] = $db;
check( 'a failed scan adds nothing and is not cached', array() === dos_agents_hub_city_pages( 'AL' ) && false === get_transient( 'breanm_agents_hub_cities_v1_al' ) );

echo "\n--- Cache cleared when a city page changes ---\n";
set_transient( 'breanm_agents_hub_cities_v1_al', array( 'x' ) );
set_transient( 'breanm_agents_hub_cities_v1_ma', array( 'x' ) );
dos_agents_hub_cities_on_save( 5, new WP_Post( array( 'ID' => 5, 'post_type' => 'page', 'post_name' => 'in-boston-ma' ) ) );
check( 'saving a MA city page clears MA only', false === get_transient( 'breanm_agents_hub_cities_v1_ma' ) && false !== get_transient( 'breanm_agents_hub_cities_v1_al' ) );
dos_agents_hub_cities_on_save( 6, new WP_Post( array( 'ID' => 6, 'post_type' => 'page', 'post_name' => 'about' ) ) );
check( 'saving another page clears nothing', false !== get_transient( 'breanm_agents_hub_cities_v1_al' ) );
dos_agents_hub_cities_on_save( 7, new WP_Post( array( 'ID' => 7, 'post_type' => 'post', 'post_name' => 'in-abernant-al' ) ) );
check( 'a post clears nothing', false !== get_transient( 'breanm_agents_hub_cities_v1_al' ) );
set_transient( 'breanm_agents_hub_cities_v1_ma', array( 'x' ) );
dos_agents_hub_cities_on_update( 8, new WP_Post( array( 'ID' => 8, 'post_type' => 'page', 'post_name' => 'in-abernant-al' ) ), new WP_Post( array( 'ID' => 8, 'post_type' => 'page', 'post_name' => '__trashed-75' ) ) );
check( 'a rename into AL clears AL', false === get_transient( 'breanm_agents_hub_cities_v1_al' ) && false !== get_transient( 'breanm_agents_hub_cities_v1_ma' ) );
set_transient( 'breanm_agents_hub_cities_v1_al', array( 'x' ) );
dos_agents_hub_cities_on_update( 9, new WP_Post( array( 'ID' => 9, 'post_type' => 'page', 'post_name' => 'in-x-zz' ) ), new WP_Post( array( 'ID' => 9, 'post_type' => 'page', 'post_name' => 'in-x-al' ) ) );
check( 'a rename out of AL clears AL', false === get_transient( 'breanm_agents_hub_cities_v1_al' ) );

echo "\n--- Review fixes ---\n";
agents_reset();
$scans = 0;
$GLOBALS['filters']['dos_agents_pre_empty_city_ids'][] = static function () use ( &$scans ) { $scans++; return array(); };
$GLOBALS['filters']['dos_agents_pre_hub_city_pages'][] = static function () { return array( array( 1, 'in-bravo-ri', 'Bravo' ) ); };
$one = '<table class="breanm-city-table"><tbody><tr><td><a href="https://example.com/in-bravo-ri/">Bravo</a></td></tr></tbody></table>';
check( 'nothing missing: the empty-page scan is not run', $one === dos_agents_hub_merge_cities( $one, 'RI' ) && 0 === $scans );

agents_reset();
$GLOBALS['filters']['dos_agents_pre_empty_city_ids'][] = static function () { return array(); };
$GLOBALS['filters']['dos_agents_pre_hub_city_pages'][] = static function () { return array( array( 1, 'in-bravo-ri', 'Bravo' ), array( 2, 'in-alpha-ri', 'Alpha' ) ); };
$bad = "<p>Bad byte \xC3 here.</p><h3>All Cities in Rhode Island (1)</h3>" . $one;
$o   = dos_agents_hub_merge_cities( $bad, 'RI' );
check( 'an invalid UTF-8 byte in the page does not blank it, and the count still follows', false !== strpos( $o, "Bad byte \xC3 here." ) && false !== strpos( $o, '/in-alpha-ri/' ) && false !== strpos( $o, 'All Cities in Rhode Island (2)' ) );

$rel = '<table class="breanm-city-table"><tr><td class=\'c\'><a href=\'in-bravo-ri/\'>Bravo</a></td></tr></table>';
$o   = dos_agents_hub_merge_cities( $rel, 'RI' );
check( 'a relative href without a leading slash counts as linked (no duplicate), no <tbody> needed', 1 === substr_count( $o, 'in-bravo-ri' ) && false !== strpos( $o, '/in-alpha-ri/' ) );
check( 'a stored href is printed as stored', false !== strpos( $o, 'href="in-bravo-ri/"' ) );

$qs = '<table class="breanm-city-table"><tr><td><a href="https://example.com/in-bravo-ri/?a=1&amp;b=2">Bravo</a></td></tr></table>';
check( 'a stored query-string href is not escaped twice', false !== strpos( dos_agents_hub_merge_cities( $qs, 'RI' ), 'href="https://example.com/in-bravo-ri/?a=1&amp;b=2"' ) );
check( 'a capitalized "In" title still gives the name', 'Mc Kee' === dos_agents_hub_city_name( 'In Mc Kee KY', 'KY' ) );

echo "\n--- Wiring ---\n";
agents_reset();
$GLOBALS['filters']['dos_agents_pre_empty_city_ids'][] = static function () { return array(); };
$GLOBALS['filters']['dos_agents_pre_hub_city_pages'][] = static function () { return array( array( 1, 'in-alpha-ri', 'Alpha' ) ); };
$hub = agents_add_page( 'in-rhode-island', $small );
$GLOBALS['wp']['page']     = true;
$GLOBALS['wp']['singular'] = true;
$GLOBALS['wp']['queried']  = $hub->ID;
$GLOBALS['wp']['post']     = $hub;
$o = dos_agents_hub_the_content( $small );
check( 'the hub content filter merges the table', false !== strpos( $o, 'breanm-hub-title' ) && false !== strpos( $o, '/in-alpha-ri/' ) && false !== strpos( $o, 'All Cities in Rhode Island (3)' ) );
$main = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php' );
check( 'save, rename and delete hooks', false !== strpos( $main, "add_action( 'save_post', 'dos_agents_hub_cities_on_save', 10, 2 );" ) && false !== strpos( $main, "add_action( 'post_updated', 'dos_agents_hub_cities_on_update', 10, 3 );" ) && false !== strpos( $main, "add_action( 'deleted_post', 'dos_agents_hub_cities_on_save', 10, 2 );" ) );

finish();
