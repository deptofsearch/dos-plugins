<?php
/**
 * DoS - Agents: the document title of hub and city pages (dos_agents_head_document_title_parts()).
 *
 * Pins that each kind of hub or city page gets its heading as the title, that only the 'title' part changes,
 * that every other page keeps WordPress's title, and that it stands down for an SEO plugin but not for Jetpack.
 */

require __DIR__ . '/wp-stubs-agents.php';

// Child mode, as in test-agents-head-meta.php: a constant cannot be un-defined, so each stand-down case runs
// in its own process and prints one line.
$standdown = getenv( 'DOS_TITLE_STANDDOWN' );
if ( 'jetpack' === $standdown ) {
	define( 'JETPACK__VERSION', '13.0' );
	class Jetpack { public static function is_module_active( $m ) { return 'publicize' === $m; } }
} elseif ( $standdown ) {
	define( $standdown, '1.0' );
}

require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

function t_view( $post ) {
	$GLOBALS['wp']['singular'] = true;
	$GLOBALS['wp']['post']     = $post;
	$GLOBALS['wp']['page']     = 'page' === $post->post_type;
	$GLOBALS['wp']['queried']  = $post->ID;
}

/** The parts WordPress would pass, after the filter. */
function t_parts( $title = 'in Somewhere XX' ) {
	return dos_agents_head_document_title_parts( array( 'title' => $title, 'page' => 'Page 2', 'site' => 'Best Real Estate Agents Near Me' ) );
}

function t_city( $city, $state ) {
	$slug = dos_agents_city_slug( $city, $state );
	update_option( dos_agents_option_name( $slug ), array( 'city' => $city, 'state' => $state, 'agents' => array( agents_make( array( 'name' => 'A' ) ) ) ), false );
	$p             = agents_add_page( 'in-' . $slug, '[breanm_agents city="' . $slug . '"]' );
	$p->post_title = 'in ' . $city . ' ' . $state;
	dos_agents_index_touch( $slug );
	return $p;
}

$empty_html = '<h3 style="font-family: Verdana, Geneva, sans-serif; color: #000000; margin: 0 0 16px 0; font-size: 20px; line-height: 1.35;">Real Estate Agents in Vesta, GA</h3>' . "\n"
	. '<p style="margin: 0 0 16px 0;">There are no agents we have found that service this area. If you are looking for the best real estate agent in Vesta, GA, please check neighboring areas.</p>';

if ( $standdown ) {
	agents_reset();
	t_view( t_city( 'Boise', 'ID' ) );
	echo 'title:' . $standdown . ':' . t_parts( 'in Boise ID' )['title'] . "\n";
	exit( 0 );
}

echo "--- Hub and city pages get their heading ---\n";
agents_reset();
$c = t_city( 'Abilene', 'TX' );
t_view( $c );
$p = t_parts( 'in Abilene TX' );
check( 'city with cards', 'Real Estate Agents in Abilene, TX' === $p['title'], $p['title'] );
check( 'only the title part changes', 'Page 2' === $p['page'] && 'Best Real Estate Agents Near Me' === $p['site'] && 3 === count( $p ) );

$bare = agents_add_page( 'in-nowhere-tx', '[breanm_agents city="nowhere-tx"]' );
t_view( $bare );
check( 'city page with the shortcode but no stored agents', 'Real Estate Agents in Nowhere, TX' === t_parts()['title'], t_parts()['title'] );

$hub = agents_add_page( 'in-washington', 'A table of every city.' );
t_view( $hub );
check( 'state hub ("In Washington" on the live site)', 'Real Estate Agents in Washington' === t_parts( 'In Washington' )['title'], t_parts()['title'] );
$hub2 = agents_add_page( 'in-new-york', 'A table.' );
t_view( $hub2 );
check( 'two-word state hub', 'Real Estate Agents in New York' === t_parts()['title'], t_parts()['title'] );

$leg = agents_add_page( 'in-bancroft-wi', (string) file_get_contents( __DIR__ . '/fixtures-legacy/in-bancroft-wi.html' ) );
t_view( $leg );
check( 'older brokerage page (real fixture)', 'Real Estate Brokerages in Bancroft, WI' === t_parts()['title'], t_parts()['title'] );

$renamed = agents_add_page( 'in-abernant-al', '<h3>Top-Rated Real Estate Brokerages in Abernant, AL: Client Reviews and Performance Summary</h3><ul><li>X (Sales: 3; Rating: 5)</li></ul>' );
t_view( $renamed );
check( 'renamed __trashed brokerage page', 'Real Estate Brokerages in Abernant, AL' === t_parts()['title'], t_parts()['title'] );

$empty = agents_add_page( 'in-vesta-ga', $empty_html );
t_view( $empty );
check( 'empty city page: heading read from its h3', 'Real Estate Agents in Vesta, GA' === t_parts( 'in Vesta GA' )['title'], t_parts()['title'] );
check( 'empty city page: kind empty, og:title follows', array( 'empty', $empty ) === dos_agents_head_meta_view() && 'Real Estate Agents in Vesta, GA' === dos_agents_head_og_title( 'empty', $empty ) );
check( 'empty city page: still no description', '' === dos_agents_head_description( 'empty', $empty ) );

$amp = agents_add_page( 'in-coeur-dalene-id', '<h3>Real Estate Agents in Coeur d&#8217;Alene, ID</h3><p>There are no agents we have found that service this area.</p>' );
t_view( $amp );
check( 'empty city page: entities in the heading are decoded', "Real Estate Agents in Coeur d\u{2019}Alene, ID" === t_parts()['title'], t_parts()['title'] );

echo "\n--- Everything else keeps its title ---\n";
$odd = agents_add_page( 'in-odd-xx', '<h3>Something else</h3><p>There are no agents we have found that service this area.</p>' );
t_view( $odd );
check( 'empty-sentence page without the heading is an ordinary page', 'in Somewhere XX' === t_parts()['title'] && 'page' === dos_agents_head_meta_view()[0] );
$about = agents_add_page( 'about', 'Who we are.' );
$about->post_title = 'About';
t_view( $about );
check( 'ordinary page', 'About' === t_parts( 'About' )['title'] );
$plain = agents_add_page( 'in-house-financing', 'Words.' );
t_view( $plain );
check( 'in-house-financing is not a city', 'In-house financing' === t_parts( 'In-house financing' )['title'] );
$GLOBALS['wp']['front'] = true;
check( 'front page', 'Best Real Estate Agents Near Me' === t_parts( 'Best Real Estate Agents Near Me' )['title'] );
$GLOBALS['wp']['front'] = false;
$GLOBALS['wp']['is_404'] = true;
check( '404', 'Page not found' === t_parts( 'Page not found' )['title'] );
$GLOBALS['wp']['is_404'] = false;
check( 'a non-array is passed through', 'x' === dos_agents_head_document_title_parts( 'x' ) );

echo "\n--- Switches ---\n";
t_view( $c );
$GLOBALS['filters']['dos_agents_document_title'][] = '__return_false';
check( 'dos_agents_document_title false turns it off', 'in Abilene TX' === t_parts( 'in Abilene TX' )['title'] );
unset( $GLOBALS['filters']['dos_agents_document_title'] );
$self = escapeshellarg( __FILE__ );
foreach ( array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION' ) as $const ) {
	$res = trim( (string) shell_exec( 'DOS_TITLE_STANDDOWN=' . $const . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . $self . ' 2>&1' ) );
	check( "stands down for $const", "title:$const:in Boise ID" === $res, $res );
}
$res = trim( (string) shell_exec( 'DOS_TITLE_STANDDOWN=jetpack ' . escapeshellarg( PHP_BINARY ) . ' ' . $self . ' 2>&1' ) );
check( 'does not stand down for Jetpack share tags', 'title:jetpack:Real Estate Agents in Boise, ID' === $res, $res );

echo "\n--- Wiring ---\n";
// agents_reset() clears the stub's filter table, so the wiring is read from the main file.
check( 'hooked on document_title_parts', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php' ), "add_filter( 'document_title_parts', 'dos_agents_head_document_title_parts' );" ) );

finish();
