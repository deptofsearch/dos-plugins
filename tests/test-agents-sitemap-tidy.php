<?php
/**
 * DoS - Agents: author and tag archives leave the core sitemap and are noindex, follow (includes/sitemap-tidy.php).
 */

require __DIR__ . '/wp-stubs-agents.php';

$GLOBALS['wp_flags'] = array( 'author' => false, 'tag' => false );
function is_author() { return $GLOBALS['wp_flags']['author']; }
function is_tag() { return $GLOBALS['wp_flags']['tag']; }

require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

echo "--- Wiring ---\n";
$main = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php' );
check( 'hooked on wp_sitemaps_add_provider', false !== strpos( $main, "add_filter( 'wp_sitemaps_add_provider', 'dos_agents_sitemap_providers', 10, 2 );" ) );
check( 'hooked on wp_sitemaps_taxonomies', false !== strpos( $main, "add_filter( 'wp_sitemaps_taxonomies', 'dos_agents_sitemap_taxonomies' );" ) );
check( 'hooked on wp_robots', false !== strpos( $main, "add_filter( 'wp_robots', 'dos_agents_sitemap_robots', 99 );" ) );
agents_reset();

echo "\n--- sitemap ---\n";
$prov = new stdClass();
check( 'the users provider is removed', false === dos_agents_sitemap_providers( $prov, 'users' ) );
check( 'the posts provider stays', $prov === dos_agents_sitemap_providers( $prov, 'posts' ) );
check( 'the taxonomies provider stays', $prov === dos_agents_sitemap_providers( $prov, 'taxonomies' ) );
$tax = dos_agents_sitemap_taxonomies( array( 'category' => 'c', 'post_tag' => 't', 'genre' => 'g' ) );
check( 'post tags leave the taxonomy sitemap, categories and others stay', array( 'category' => 'c', 'genre' => 'g' ) === $tax, print_r( $tax, true ) );
check( 'a non-array taxonomies value passes through', 'x' === dos_agents_sitemap_taxonomies( 'x' ) );

echo "\n--- robots ---\n";
$base = array( 'index' => true, 'follow' => true, 'max-image-preview' => 'large' );
check( 'an ordinary page keeps its robots', $base === dos_agents_sitemap_robots( $base ) );
$GLOBALS['wp_flags']['author'] = true;
$r = dos_agents_sitemap_robots( $base );
check( 'an author archive is noindex, follow, other directives kept', ! isset( $r['index'] ) && true === $r['noindex'] && true === $r['follow'] && 'large' === $r['max-image-preview'], print_r( $r, true ) );
$GLOBALS['wp_flags'] = array( 'author' => false, 'tag' => true );
$r = dos_agents_sitemap_robots( $base );
check( 'a tag archive is noindex, follow', ! isset( $r['index'] ) && true === $r['noindex'] && true === $r['follow'] );

echo "\n--- turned off ---\n";
$GLOBALS['filters']['dos_agents_sitemap_tidy'] = array( function () { return false; } );
check( 'off: users provider kept', $prov === dos_agents_sitemap_providers( $prov, 'users' ) );
check( 'off: post tags kept', array( 'post_tag' => 't' ) === dos_agents_sitemap_taxonomies( array( 'post_tag' => 't' ) ) );
check( 'off: a tag archive keeps its robots', $base === dos_agents_sitemap_robots( $base ) );

finish();
