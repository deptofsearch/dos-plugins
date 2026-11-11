<?php
/**
 * DoS - Agents: the meta description, Open Graph and Twitter Card tags (includes/head-meta.php).
 *
 * Pins what each page kind says and that every claim is one the page shows, the trimming and
 * escaping, the image order, and every way the whole thing stands down or stays silent.
 */

require __DIR__ . '/wp-stubs-agents.php';

// Child mode: a constant (or the toolkit) cannot be un-defined, so each stand-down case runs this file
// again in its own process with DOS_META_STANDDOWN set, prints one result line and exits.
$standdown = getenv( 'DOS_META_STANDDOWN' );
if ( $standdown ) {
	if ( 0 === strpos( $standdown, 'toolkit-' ) ) {
		class DOS_Module_SEO { const KEY = 'seo'; }
		if ( 'toolkit-class-only' !== $standdown ) {
			class DOS_Toolkit {
				public static function is_active( $key ) { return 'toolkit-active' === getenv( 'DOS_META_STANDDOWN' ) && 'seo' === $key; }
			}
		}
	} elseif ( 'aioseo-function' === $standdown ) {
		function aioseo() { return null; }
	} elseif ( 0 === strpos( $standdown, 'jetpack-' ) ) {
		define( 'JETPACK__VERSION', '13.0' );
		if ( 'jetpack-module' === $standdown ) {
			class Jetpack { public static function is_module_active( $m ) { return 'publicize' === $m; } }
		} elseif ( 'jetpack-no-og' === $standdown ) {
			class Jetpack { public static function is_module_active( $m ) { return false; } }
		}
	} else {
		define( $standdown, '1.0' );
	}
}

require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

/** Stores a city with a published page holding the shortcode, the normal live state. */
function meta_city( $city, $state, $agents = 2 ) {
	$slug = dos_agents_city_slug( $city, $state );
	$rows = array();
	for ( $i = 0; $i < $agents; $i++ ) {
		$rows[] = agents_make( array( 'name' => "$city agent $i" ) );
	}
	update_option( dos_agents_option_name( $slug ), array( 'city' => $city, 'state' => $state, 'agents' => $rows ), false );
	$page = agents_add_page( 'in-' . $slug, '[breanm_agents city="' . $slug . '"]' );
	$page->post_title = 'in ' . $city . ', ' . $state;
	dos_agents_index_touch( $slug );
	return $page;
}

/** A published page with a title, not part of the city index. */
function meta_page( $slug, $content = '', $title = 'A page' ) {
	$p             = agents_add_page( $slug, $content );
	$p->post_title = $title;
	return $p;
}

/** A blog post. */
function meta_post( $slug, $title, $content = '', $excerpt = '' ) {
	static $next = 900;
	$p = new WP_Post( array( 'ID' => ++$next, 'post_name' => $slug, 'post_type' => 'post', 'post_title' => $title, 'post_content' => $content, 'post_excerpt' => $excerpt ) );
	$GLOBALS['wp']['pages'][ $slug ] = $p;
	return $p;
}

/** Views the stub site as a singular page or post. */
function meta_view( $post ) {
	$GLOBALS['wp']['singular'] = true;
	$GLOBALS['wp']['post']     = $post;
	$GLOBALS['wp']['page']     = 'page' === $post->post_type;
	$GLOBALS['wp']['queried']  = $post->ID;
}

/** What wp_head prints, as array( tag name => content ) plus the raw output under 'raw'. */
function meta_out() {
	ob_start();
	dos_agents_head_meta();
	$raw  = ob_get_clean();
	$tags = array( 'raw' => $raw );
	if ( preg_match_all( '/<meta (name|property)="([^"]+)" content="([^"]*)">/', $raw, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $t ) {
			$tags[ $t[2] ][] = html_entity_decode( $t[3], ENT_QUOTES, 'UTF-8' );
		}
	}
	return $tags;
}

/** First value of a tag, or null. */
function mv( $out, $k ) {
	return $out[ $k ][0] ?? null;
}

/** Seeds a site: Texas with three cities (counts 7, 5, 2) and one Idaho city. */
function meta_seed() {
	agents_reset();
	meta_city( 'Houston', 'TX', 7 );
	meta_city( 'Dallas', 'TX', 5 );
	meta_city( 'Abilene', 'TX', 2 );
	meta_city( 'Boise', 'ID', 3 );
}

if ( $standdown ) {
	agents_reset();
	if ( 'jetpack-filter' === $standdown ) {
		$GLOBALS['filters']['jetpack_enable_open_graph'][] = '__return_true';
	}
	$p = meta_post( 'child', 'Child', '', 'An excerpt.' );
	meta_view( $p );
	$raw = meta_out()['raw'];
	echo 'standdown:' . $standdown . ':' . ( '' === $raw ? 'empty' : 'printed' ) . ':' . ( '' === dos_agents_head_meta_other_layer() ? 'none' : 'layer' ) . "\n";
	exit( 0 );
}

echo "--- City page ---\n";
meta_seed();
$city = $GLOBALS['wp']['pages']['in-abilene-tx'];
meta_view( $city );
$out = meta_out();
echo '  example description: ' . mv( $out, 'description' ) . "\n  example og:title: " . mv( $out, 'og:title' ) . "\n";
check( 'description names the stored count and the city', 'Compare 2 real estate agents in Abilene, TX: ratings, local sales, what reviewers say about each one, and how recent the reviews are.' === mv( $out, 'description' ), (string) mv( $out, 'description' ) );
check( 'description does not claim a Zillow link the cards do not show', false === stripos( (string) mv( $out, 'description' ), 'zillow' ) );
check( 'og:description is the same text', mv( $out, 'description' ) === mv( $out, 'og:description' ) && mv( $out, 'description' ) === mv( $out, 'twitter:description' ) );
check( 'og:title is the H1 text', 'Real Estate Agents in Abilene, TX' === mv( $out, 'og:title' ) && 'Real Estate Agents in Abilene, TX' === mv( $out, 'twitter:title' ) );
check( 'og:type website, og:url is the page URL', 'website' === mv( $out, 'og:type' ) && 'https://example.com/in-abilene-tx/' === mv( $out, 'og:url' ), (string) mv( $out, 'og:url' ) );
check( 'og:site_name and og:locale', 'Best Real Estate Agents Near Me' === mv( $out, 'og:site_name' ) && 'en_US' === mv( $out, 'og:locale' ) );
check( 'og:url uses the canonical when WordPress reports a different one', ( function () use ( $city ) {
	$GLOBALS['wp']['canonical'][ $city->ID ] = 'https://example.com/in-abilene-tx/?x=1';
	$u = mv( meta_out(), 'og:url' );
	unset( $GLOBALS['wp']['canonical'][ $city->ID ] );
	return 'https://example.com/in-abilene-tx/?x=1' === $u;
} )() );
check( 'each tag is printed once', 1 === count( $out['description'] ) && 1 === count( $out['og:title'] ) && 1 === count( $out['og:url'] ) && 1 === count( $out['twitter:card'] ) );

// One agent.
meta_city( 'Solo', 'ID', 1 );
meta_view( $GLOBALS['wp']['pages']['in-solo-id'] );
check( 'a city with one agent is not "1 agents"', 0 === strpos( (string) mv( meta_out(), 'description' ), 'The real estate agent in Solo, ID' ), (string) mv( meta_out(), 'description' ) );

// Page holds the shortcode but nothing is stored: slug-derived name, no count.
$bare = agents_add_page( 'in-nowhere-tx', '[breanm_agents city="nowhere-tx"]' );
$bare->post_title = 'in Nowhere, TX';
meta_view( $bare );
$out = meta_out();
check( 'no stored data: generic city sentence, no number', 'Real estate agents in Nowhere, TX: ratings, local sales, what reviewers say, and how recent the reviews are.' === mv( $out, 'description' ), (string) mv( $out, 'description' ) );
check( 'no stored data: og:title still the city H1', 'Real Estate Agents in Nowhere, TX' === mv( $out, 'og:title' ) );

// A page named like a city but with no shortcode and no index row is just a page.
$plain = meta_page( 'in-house-financing', 'Words.', 'In-house financing' );
meta_view( $plain );
$out = meta_out();
check( 'in-house-financing is not read as a city page', null === mv( $out, 'description' ) && 'In-house financing' === mv( $out, 'og:title' ) );

echo "\n--- State hub ---\n";
meta_seed();
$hub = meta_page( 'in-texas', 'A table of every city.', 'in Texas' );
meta_view( $hub );
$out = meta_out();
echo '  example description: ' . mv( $out, 'description' ) . "\n  example og:title: " . mv( $out, 'og:title' ) . "\n";
check( 'hub description: state, count of cities with cards, the three with the most agents', 'Real estate agents in Texas: 3 cities with agent cards, including Houston, Dallas and Abilene. Pick a city to compare local agents.' === mv( $out, 'description' ), (string) mv( $out, 'description' ) );
check( 'hub og:title', 'Real Estate Agents in Texas' === mv( $out, 'og:title' ) );
// Four cities: the smallest is the one left out.
meta_city( 'Austin', 'TX', 1 );
meta_view( $hub );
check( 'with four cities the smallest is left out of the sample', false === strpos( (string) mv( meta_out(), 'description' ), 'Austin' ) && 0 === strpos( (string) mv( meta_out(), 'description' ), 'Real estate agents in Texas: 4 cities with agent cards, including Houston, Dallas and Abilene. Pick a city to compare local agents.' ) );
// Two, one, none.
agents_reset();
meta_city( 'Houston', 'TX', 7 );
meta_city( 'Dallas', 'TX', 5 );
$hub = meta_page( 'in-texas', 'x', 'in Texas' );
meta_view( $hub );
check( 'two cities', 'Real estate agents in Texas: 2 cities with agent cards: Houston and Dallas. Pick a city to compare local agents and what reviewers say about them.' === mv( meta_out(), 'description' ), (string) mv( meta_out(), 'description' ) );
agents_reset();
meta_city( 'Houston', 'TX', 7 );
$hub = meta_page( 'in-texas', 'x', 'in Texas' );
meta_view( $hub );
check( 'one city', 'Real estate agents in Texas: 1 city with agent cards: Houston. Pick the city to compare local agents and what reviewers say about them.' === mv( meta_out(), 'description' ), (string) mv( meta_out(), 'description' ) );
agents_reset();
$hub = meta_page( 'in-texas', 'x', 'in Texas' );
meta_view( $hub );
check( 'no cities with cards: generic state sentence, no count', 'Real estate agents in Texas. Pick a city to compare local agents and what reviewers say about them.' === mv( meta_out(), 'description' ), (string) mv( meta_out(), 'description' ) );
// Two-word state, and only that state's cities.
agents_reset();
meta_city( 'Albuquerque', 'NM', 4 );
meta_city( 'Boise', 'ID', 9 );
$hub = meta_page( 'in-new-mexico', 'x', 'in New Mexico' );
meta_view( $hub );
check( 'a two-word state counts only its own cities', 0 === strpos( (string) mv( meta_out(), 'description' ), 'Real estate agents in New Mexico: 1 city with agent cards: Albuquerque.' ), (string) mv( meta_out(), 'description' ) );

echo "\n--- Homepage and /by-state/ ---\n";
meta_seed();
$front = meta_page( 'home', '[breanm_city_search]', 'Home' );
meta_view( $front );
$GLOBALS['wp']['front'] = true;
$out = meta_out();
echo '  example description: ' . mv( $out, 'description' ) . "\n  example og:title: " . mv( $out, 'og:title' ) . "\n";
check( 'home description uses the live city and state counts', 'Compare real estate agents in 4 cities across 2 states: what reviewers say about each agent, how recent the reviews are, and sales in the city.' === mv( $out, 'description' ), (string) mv( $out, 'description' ) );
check( 'home og:title is the site name, og:url is the site root', 'Best Real Estate Agents Near Me' === mv( $out, 'og:title' ) && 'https://example.com/' === mv( $out, 'og:url' ) );
$bs = meta_page( 'by-state', '[breanm_state_index layout="grid"]', 'By state' );
$GLOBALS['wp']['front'] = false;
meta_view( $bs );
$out = meta_out();
echo '  example by-state description: ' . mv( $out, 'description' ) . "\n  example by-state og:title: " . mv( $out, 'og:title' ) . "\n";
check( '/by-state/ description uses the live counts', 'Browse real estate agents by state: 2 states and 4 cities with agent cards. Pick a state, then a city, to compare local agents.' === mv( $out, 'description' ), (string) mv( $out, 'description' ) );
check( '/by-state/ og:title is the page title', 'By state' === mv( $out, 'og:title' ) );
agents_reset();
$front = meta_page( 'home', '[breanm_city_search]', 'Home' );
meta_view( $front );
$GLOBALS['wp']['front'] = true;
check( 'an empty index gives a sentence with no numbers', preg_match( '/^Compare real estate agents city by city/', (string) mv( meta_out(), 'description' ) ) && ! preg_match( '/\d/', (string) mv( meta_out(), 'description' ) ) );

// Long Texas-style case: long city names push the sentence past 155 characters.
agents_reset();
meta_city( 'San Antonio Heights', 'TX', 9 );
meta_city( 'Corpus Christi Bay', 'TX', 8 );
meta_city( 'Wichita Falls Junction', 'TX', 7 );
for ( $i = 0; $i < 40; $i++ ) { meta_city( 'Town' . chr( 65 + $i % 26 ) . chr( 97 + intdiv( $i, 26 ) ), 'TX', 1 ); }
$hub = meta_page( 'in-texas', 'x', 'in Texas' );
meta_view( $hub );
$d = (string) mv( meta_out(), 'description' );
echo '  example long hub description: ' . $d . "\n";
check( 'a long hub sentence fits 155, drops the call to action rather than cutting it', dos_agents_head_len( $d ) <= 155 && false !== strpos( $d, '43 cities with agent cards, including San Antonio Heights, Corpus Christi Bay and Wichita Falls Junction.' ) && '.' === substr( $d, -1 ) && false === strpos( $d, "\xe2\x80\xa6" ), $d );

echo "\n--- One city, one state; queried object; protected titles; latest-posts front page ---\n";
agents_reset();
meta_city( 'Boise', 'ID', 3 );
$front = meta_page( 'home', '[breanm_city_search]', 'Home' );
meta_view( $front );
$GLOBALS['wp']['front'] = true;
check( '1 city across 1 state reads right on the homepage', 'Compare real estate agents in 1 city across 1 state: what reviewers say about each agent, how recent the reviews are, and sales in the city.' === mv( meta_out(), 'description' ), (string) mv( meta_out(), 'description' ) );
$GLOBALS['wp']['front'] = false;
$bs = meta_page( 'by-state', '[breanm_state_index layout="grid"]', 'By state' );
meta_view( $bs );
check( '1 state and 1 city read right on /by-state/', 'Browse real estate agents by state: 1 state and 1 city with agent cards. Pick a state, then a city, to compare local agents.' === mv( meta_out(), 'description' ), (string) mv( meta_out(), 'description' ) );
meta_city( 'Meridian', 'ID', 2 );
meta_view( $bs );
check( '1 state with 2 cities', false !== strpos( (string) mv( meta_out(), 'description' ), '1 state and 2 cities with agent cards' ) );

agents_reset();
$real  = meta_post( 'real', 'The real one', '', 'Real excerpt.' );
$other = meta_post( 'other', 'Some widget loop post', '', 'Other excerpt.' );
meta_view( $other );
$GLOBALS['wp']['queried_obj'] = $real;
$out = meta_out();
check( 'the queried object wins over a different global post', 'The real one' === mv( $out, 'og:title' ) && 'Real excerpt.' === mv( $out, 'description' ) && 'https://example.com/real/' === mv( $out, 'og:url' ) );
$GLOBALS['wp']['queried_obj'] = null;
check( 'with no queried object it falls back to the global post', 'Some widget loop post' === mv( meta_out(), 'og:title' ) );

$prot = meta_post( 'prot', 'Q&A - private notes', 'Hidden.' );
$prot->post_password = 'pw';
meta_view( $prot );
$out = meta_out();
check( 'a protected post title has no "Protected: " prefix, decoded then escaped once', "Q&A \xe2\x80\x93 private notes" === mv( $out, 'og:title' ) && false === strpos( $out['raw'], 'Protected' ) && false !== strpos( $out['raw'], 'Q&amp;A', 0 ), $out['raw'] );

agents_reset();
$GLOBALS['wp']['site_icon'] = true;
$GLOBALS['wp']['front'] = true;
$GLOBALS['wp']['home']  = true;
$GLOBALS['wp']['singular'] = false;
$GLOBALS['wp']['post']  = null;
$out = meta_out();
check( 'front page set to latest posts: kind home, root URL, site name title, Site Icon image', 'Best Real Estate Agents Near Me' === mv( $out, 'og:title' ) && 'https://example.com/' === mv( $out, 'og:url' ) && 'https://example.com/wp-content/uploads/icon-512.png' === mv( $out, 'og:image' ) && 'website' === mv( $out, 'og:type' ) && 'summary' === mv( $out, 'twitter:card' ) && null !== mv( $out, 'description' ), $out['raw'] );
$GLOBALS['wp']['front'] = false;
$GLOBALS['wp']['home']  = false;

echo "\n--- Blog posts and the blog index ---\n";
agents_reset();
$post = meta_post( 'tips', 'Ten tips', 'Body text that would be the fallback.', 'The manual excerpt wins.' );
meta_view( $post );
$out = meta_out();
echo '  example post description: ' . mv( $out, 'description' ) . "\n  example post og:title: " . mv( $out, 'og:title' ) . "\n";
check( 'a post uses its manual excerpt', 'The manual excerpt wins.' === mv( $out, 'description' ) );
check( 'a post is og:type article, titled by the post', 'article' === mv( $out, 'og:type' ) && 'Ten tips' === mv( $out, 'og:title' ) );
$post = meta_post( 'lead', 'Lead', '<!-- wp:paragraph --><p>First sentence of the post is here and it runs long enough to matter. Second sentence follows right after it &amp; carries on for a while longer than that. Third sentence should never appear in the description at all.</p><!-- /wp:paragraph -->[breanm_agents city="x"]' );
meta_view( $post );
$d = (string) mv( meta_out(), 'description' );
echo '  example content-derived description: ' . $d . "\n";
check( 'no excerpt: first sentences of the content, tags, comments and shortcodes gone', 0 === strpos( $d, 'First sentence of the post' ) && false === strpos( $d, '<' ) && false === strpos( $d, '[breanm' ) && false === strpos( $d, 'Third sentence' ), $d );
check( 'content-derived description ends on a sentence and fits the limit', '.' === substr( $d, -1 ) && dos_agents_head_len( $d ) <= 155, $d );
$post = meta_post( 'protected', 'Secret', 'Private words that must not leak.' );
$post->post_password = 'pw';
meta_view( $post );
check( 'a password-protected post gives no description', null === mv( meta_out(), 'description' ) );
$post = meta_post( 'empty', 'Empty', '' );
meta_view( $post );
check( 'a post with no content and no excerpt prints no description tag', null === mv( meta_out(), 'description' ) && null === mv( meta_out(), 'og:description' ) && null !== mv( meta_out(), 'og:title' ) );

$blog = meta_page( 'blog', '', 'The Blog' );
update_option( 'page_for_posts', $blog->ID );
$GLOBALS['wp']['singular'] = false;
$GLOBALS['wp']['post']     = null;
$GLOBALS['wp']['home']     = true;
$out = meta_out();
echo '  example blog description: ' . mv( $out, 'description' ) . "\n  example blog og:title: " . mv( $out, 'og:title' ) . "\n";
check( 'blog index: the fixed sentence with the site name', 'Articles from Best Real Estate Agents Near Me about working with a real estate agent.' === mv( $out, 'description' ), (string) mv( $out, 'description' ) );
check( 'blog index: title is the posts page title, url its permalink', 'The Blog' === mv( $out, 'og:title' ) && 'https://example.com/blog/' === mv( $out, 'og:url' ), (string) mv( $out, 'og:url' ) );

// The live site's /blog/ is an ordinary page (a posts module on it), not the posts page.
update_option( 'page_for_posts', 0 );
$GLOBALS['wp']['home'] = false;
$plain_blog = meta_page( 'blog', '[themify_posts]', 'Blog' );
meta_view( $plain_blog );
$out = meta_out();
check( 'a top-level page with the blog slug gets the blog index sentence', 'Articles from Best Real Estate Agents Near Me about working with a real estate agent.' === mv( $out, 'description' ), (string) mv( $out, 'description' ) );
check( 'blog slug page: og:title is its title, og:url its canonical', 'Blog' === mv( $out, 'og:title' ) && 'https://example.com/blog/' === mv( $out, 'og:url' ), (string) mv( $out, 'og:title' ) . ' ' . (string) mv( $out, 'og:url' ) );
$plain_blog->post_excerpt = 'Guides to choosing an agent.';
meta_view( $plain_blog );
check( 'blog slug page: its own excerpt wins', 'Guides to choosing an agent.' === mv( meta_out(), 'description' ), (string) mv( meta_out(), 'description' ) );
$plain_blog->post_excerpt = '';
$plain_blog->post_parent  = 77;
meta_view( $plain_blog );
check( 'a child page with the blog slug is an ordinary page (no description)', null === mv( meta_out(), 'description' ), (string) mv( meta_out(), 'description' ) );
$plain_blog->post_parent = 0;

echo "\n--- Other pages ---\n";
agents_reset();
$about = meta_page( 'about', 'About us.', 'About' );
meta_view( $about );
$out = meta_out();
check( 'a page with no excerpt gets no description, but still og:title and og:url', null === mv( $out, 'description' ) && 'About' === mv( $out, 'og:title' ) && 'https://example.com/about/' === mv( $out, 'og:url' ) );
$about->post_excerpt = 'Who we are.';
check( 'a page with a manual excerpt uses it', 'Who we are.' === mv( meta_out(), 'description' ) );

echo "\n--- Trimming ---\n";
$long = str_repeat( 'alpha beta gamma ', 20 );
$t    = dos_agents_head_trim( $long, 155 );
check( 'a long text is cut to at most 155 characters', dos_agents_head_len( $t ) <= 155 && dos_agents_head_len( $t ) > 140, (string) dos_agents_head_len( $t ) );
check( 'the cut is on a word boundary, ending in an ellipsis', 1 === preg_match( '/(alpha|beta|gamma)\x{2026}$/u', $t ), $t );
check( 'no dangling comma before the ellipsis', '' !== ( $x = dos_agents_head_trim( str_repeat( 'word, ', 40 ), 31 ) ) && 1 !== preg_match( '/[,;:\-]\x{2026}$/u', $x ), $x );
check( 'a text under the limit is untouched', 'Short text.' === dos_agents_head_trim( 'Short text.', 155 ) );
check( 'exactly at the limit is untouched', 155 === dos_agents_head_len( dos_agents_head_trim( str_repeat( 'a', 155 ), 155 ) ) && false === strpos( dos_agents_head_trim( str_repeat( 'a', 155 ), 155 ), "\xe2\x80\xa6" ) );
$post = meta_post( 'longexcerpt', 'Long', '', str_repeat( 'Plenty of words here. ', 30 ) );
meta_view( $post );
check( 'a long manual excerpt is trimmed in the tag', dos_agents_head_len( (string) mv( meta_out(), 'description' ) ) <= 155 );
$post = meta_post( 'multibyte', 'MB', '', str_repeat( 'caf' . "\xc3\xa9" . ' ', 60 ) );
meta_view( $post );
check( 'multibyte text is cut on characters, not bytes', 1 === preg_match( '//u', (string) mv( meta_out(), 'description' ) ) && dos_agents_head_len( (string) mv( meta_out(), 'description' ) ) <= 155 );

echo "\n--- Entities and escaping ---\n";
agents_reset();
$o = meta_city( "O'Fallon", 'MO', 3 );
meta_view( $o );
$out = meta_out();
check( 'a quote in the city name is escaped in the markup', false === strpos( $out['raw'], 'content="Compare 3 real estate agents in O\'Fallon' ) && false !== strpos( $out['raw'], 'O&#039;Fallon, MO' ), $out['raw'] );
check( 'and reads back as the quote', 0 === strpos( (string) mv( $out, 'description' ), "Compare 3 real estate agents in O'Fallon, MO" ) );
$amp = meta_post( 'amp', 'Q&A: "Buying" & selling - a guide', '', 'Fish & chips &amp; <b>bold</b> "quotes" it\'s.' );
meta_view( $amp );
$out = meta_out();
check( 'a post title with & and quotes decodes once and escapes once', 'Q&A: "Buying" & selling – a guide' === mv( $out, 'og:title' ), (string) mv( $out, 'og:title' ) );
check( 'in the markup the & is &amp; and the quote is &quot;, never double-escaped', false !== strpos( $out['raw'], 'Q&amp;A: &quot;Buying&quot; &amp; selling' ) && false === strpos( $out['raw'], '&amp;amp;' ) && false === strpos( $out['raw'], '&#038;' ), $out['raw'] );
check( 'excerpt tags are stripped and entities decoded once', 'Fish & chips & bold "quotes" it\'s.' === mv( $out, 'description' ), (string) mv( $out, 'description' ) );
$GLOBALS['wp']['sitename'] = 'Tom &amp; Jerry\'s Agents';
check( 'site name with an entity decodes then escapes', "Tom & Jerry's Agents" === mv( meta_out(), 'og:site_name' ) );
$GLOBALS['wp']['sitename'] = 'Best Real Estate Agents Near Me';
$xss = meta_post( 'xss', '"><script>alert(1)</script>', '', '"><script>alert(2)</script> end' );
meta_view( $xss );
$out = meta_out();
check( 'markup in a title or excerpt cannot break out of the attribute', false === strpos( $out['raw'], '<script' ) && 1 === preg_match( '/^<!-- DoS Agents head meta -->\n(<meta [^>]*>\n)+<!-- \/DoS Agents head meta -->\n$/', $out['raw'] ), $out['raw'] );

echo "\n--- og:image order ---\n";
agents_reset();
$p = meta_post( 'pic', 'Pic', '', 'Has a picture.' );
meta_view( $p );
$out = meta_out();
check( 'no featured image and no Site Icon: no image tags at all', ! isset( $out['og:image'] ) && ! isset( $out['og:image:width'] ) && ! isset( $out['twitter:image'] ), $out['raw'] );
check( 'and the plain summary card', 'summary' === mv( $out, 'twitter:card' ) );
$GLOBALS['wp']['site_icon'] = true;
$out = meta_out();
check( 'Site Icon at 512px when there is no featured image', 'https://example.com/wp-content/uploads/icon-512.png' === mv( $out, 'og:image' ) && '512' === mv( $out, 'og:image:width' ) && '512' === mv( $out, 'og:image:height' ) && 'Best Real Estate Agents Near Me' === mv( $out, 'og:image:alt' ) );
check( 'the brand SVG favicon is never offered', false === strpos( $out['raw'], '.svg' ) );
$GLOBALS['wp']['thumbs'][ $p->ID ] = array( 77, 'https://example.com/wp-content/uploads/hero.jpg', 1200, 630, 'A kitchen "island"' );
$out = meta_out();
check( 'a featured image wins over the Site Icon', 'https://example.com/wp-content/uploads/hero.jpg' === mv( $out, 'og:image' ) && '1200' === mv( $out, 'og:image:width' ) && '630' === mv( $out, 'og:image:height' ) && 'A kitchen "island"' === mv( $out, 'og:image:alt' ) && 'https://example.com/wp-content/uploads/hero.jpg' === mv( $out, 'twitter:image' ), $out['raw'] );
check( 'twitter:card is summary_large_image for a wide image', 'summary_large_image' === mv( $out, 'twitter:card' ) );
$GLOBALS['wp']['site_icon'] = true;
unset( $GLOBALS['wp']['thumbs'][ $p->ID ] );
check( 'twitter:card falls back to summary for the square Site Icon', 'summary' === mv( meta_out(), 'twitter:card' ) );
$GLOBALS['wp']['thumbs'][ $p->ID ] = array( 78, 'https://example.com/wp-content/uploads/logo.svg', 800, 800, '' );
check( 'an SVG featured image is dropped (no raster image, no tags)', ! isset( meta_out()['og:image'] ) );
unset( $GLOBALS['wp']['thumbs'][ $p->ID ] );
$GLOBALS['filters']['dos_agents_og_image'][] = function ( $img ) { return array( 'url' => 'https://cdn.example.com/x.png', 'width' => 1000, 'height' => 500, 'alt' => 'x' ); };
check( 'the dos_agents_og_image filter can replace the image', 'https://cdn.example.com/x.png' === mv( meta_out(), 'og:image' ) );
$GLOBALS['filters']['dos_agents_og_image'] = array( function () { return array( 'url' => 'https://cdn.example.com/y.svg' ); } );
check( 'a filter result pointing at an SVG is dropped too', ! isset( meta_out()['og:image'] ) );
$GLOBALS['filters']['dos_agents_og_image'] = array( function () { return array(); } );
check( 'a filter returning empty removes the image', ! isset( meta_out()['og:image'] ) );
unset( $GLOBALS['filters']['dos_agents_og_image'] );
$GLOBALS['filters']['dos_agents_og_title']            = array( function () { return 'Custom title'; } );
$GLOBALS['filters']['dos_agents_meta_description']    = array( function () { return 'Custom description.'; } );
$out = meta_out();
check( 'the og_title and meta_description filters apply', 'Custom title' === mv( $out, 'og:title' ) && 'Custom description.' === mv( $out, 'description' ) && 'Custom description.' === mv( $out, 'og:description' ) );
$GLOBALS['filters'] = array();

echo "\n--- Standing down ---\n";
agents_reset();
$p = meta_post( 'sd', 'Stand down', '', 'An excerpt.' );
meta_view( $p );
check( 'prints by default', '' !== meta_out()['raw'] );
$GLOBALS['filters']['dos_agents_head_meta'] = array( '__return_false' );
check( 'the dos_agents_head_meta filter turns it all off', '' === meta_out()['raw'] );
$GLOBALS['filters'] = array();

// Constants cannot be undefined, so each plugin is checked in its own process.
$self = escapeshellarg( __FILE__ );
foreach ( array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION', 'aioseo-function', 'jetpack-filter', 'jetpack-module' ) as $const ) {
	$res = trim( (string) shell_exec( 'DOS_META_STANDDOWN=' . $const . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . $self . ' 2>&1' ) );
	check( "stands down for $const (and says which layer)", 'standdown:' . $const . ':empty:layer' === $res, $res );
}
$res = trim( (string) shell_exec( 'DOS_META_STANDDOWN=jetpack-no-og ' . escapeshellarg( PHP_BINARY ) . ' ' . $self . ' 2>&1' ) );
check( 'Jetpack present with Open Graph off (no filter, no module): still prints', 'standdown:jetpack-no-og:printed:none' === $res, $res );
foreach ( array( 'toolkit-active', 'toolkit-disabled', 'toolkit-class-only' ) as $mode ) {
	$res = trim( (string) shell_exec( 'DOS_META_STANDDOWN=' . $mode . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . $self . ' 2>&1' ) );
	$want = 'toolkit-disabled' === $mode ? 'standdown:' . $mode . ':printed:none' : 'standdown:' . $mode . ':empty:layer';
	check( "DoS Toolkit SEO module, $mode", $want === $res, $res );
}

echo "\n--- Silent on views that get nothing ---\n";
agents_reset();
$p = meta_post( 'quiet', 'Quiet', '', 'An excerpt.' );
meta_view( $p );
$GLOBALS['wp']['is_404'] = true;
check( 'nothing on a 404', '' === meta_out()['raw'] );
$GLOBALS['wp']['is_404'] = false;
$GLOBALS['wp']['search'] = true;
check( 'nothing on search', '' === meta_out()['raw'] );
$GLOBALS['wp']['search'] = false;
$GLOBALS['wp']['feed'] = true;
check( 'nothing on a feed', '' === meta_out()['raw'] );
$GLOBALS['wp']['feed'] = false;
$GLOBALS['wp']['singular'] = false;
$GLOBALS['wp']['post']     = null;
check( 'nothing on an archive (not singular, not the front page, not the posts page)', '' === meta_out()['raw'] );
meta_view( $p );
$GLOBALS['wp']['attachment'] = true;
check( 'nothing on an attachment page', '' === meta_out()['raw'] );
$GLOBALS['wp']['attachment'] = false;
check( 'and prints again once the view is a post', '' !== meta_out()['raw'] );

echo "\n--- Wiring ---\n";
check( 'hooked on wp_head at priority 1', in_array( 'dos_agents_head_meta', $GLOBALS['actions']['wp_head'][1] ?? array(), true ) );
check( 'version is 0.3.2 in the constant and the header', '0.3.2' === DOS_AGENTS_VERSION && false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php' ), 'Version: 0.3.2' ) );

finish();
