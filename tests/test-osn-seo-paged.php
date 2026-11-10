<?php
/**
 * Page 2+ of a grid: the document title, the H1 and the meta description say which page it is. Page 1, pages
 * that are not takeover grids and the hooks' ordering are unchanged.
 */

require __DIR__ . '/wp-stubs-osn.php';
osn_load();

use OSN\Page_Title;

// The stubbed get_post_metadata filter chain: core runs these before reading the meta table.
function get_post_meta_filtered( $id, $key, $single ) {
	$v = apply_filters( 'get_post_metadata', null, $id, $key, $single );
	return null !== $v ? $v : get_post_meta( $id, $key, $single );
}

osn_world( 600 );
Page_Title::hooks();
$GLOBALS['osn']['meta'][500]['_dos_seo_description'] = 'Patios, rooftops and dog-friendly spots in Testville.';
$TITLE = 'Outdoor Seating in Testville, TX | Outdoor Seating Near Me';

echo "--- hooks ---\n";
$pri = function ( $tag ) { return array_keys( $GLOBALS['filters'][ $tag ] ?? array() ); };
check( 'the title filter is on pre_get_document_title at 20, after the Toolkit (10)', array( 20 ) === $pri( 'pre_get_document_title' ) );
check( 'and on wp_title at 20', array( 20 ) === $pri( 'wp_title' ) );
check( 'the H1 filter keeps its priority 5', array( 5 ) === $pri( 'the_content' ) );

echo "\n--- document title ---\n";
osn_request( 500, 0 );
check( 'page 1: unchanged', $TITLE === apply_filters( 'pre_get_document_title', $TITLE ) );
osn_request( 500, 1 );
check( '/page/1/ is page 1: unchanged', $TITLE === apply_filters( 'pre_get_document_title', $TITLE ) );
osn_request( 500, 2 );
check( 'page 2: " – Page 2" goes before the first " | "', 'Outdoor Seating in Testville, TX – Page 2 | Outdoor Seating Near Me' === apply_filters( 'pre_get_document_title', $TITLE ) );
check( 'wp_title gets the same treatment', 'Outdoor Seating in Testville, TX – Page 2 | Outdoor Seating Near Me' === apply_filters( 'wp_title', $TITLE ) );
check( 'a title with no " | " gets it appended', 'Testville Patios – Page 2' === apply_filters( 'pre_get_document_title', 'Testville Patios' ) );
check( 'an empty title stays empty (core then builds its own)', '' === apply_filters( 'pre_get_document_title', '' ) );
osn_request( 500, 3 );
check( 'page 3 says Page 3', false !== strpos( apply_filters( 'pre_get_document_title', $TITLE ), '– Page 3 |' ) );
osn_post( 700, 'about', 'Just words.' );
osn_request( 700, 2 );
check( 'a page that is not a takeover grid keeps its title even with /page/2/', $TITLE === apply_filters( 'pre_get_document_title', $TITLE ) );
osn_request( 500, 2 );
$GLOBALS['osn']['is_page'] = false;
check( 'a request that is not a page keeps its title', $TITLE === apply_filters( 'pre_get_document_title', $TITLE ) );

echo "\n--- H1 ---\n";
osn_request( 500, 0 );
$h = Page_Title::prepend( '<p>body</p>' );
check( 'page 1: "Outdoor Seating in Testville, TX", no suffix', 0 === strpos( $h, '<h1 class="osn-page-title">Outdoor Seating in Testville, TX</h1>' ), $h );
osn_request( 500, 2 );
$h = Page_Title::prepend( '<p>body</p>' );
check( 'page 2: " – Page 2" appended inside the H1', 0 === strpos( $h, '<h1 class="osn-page-title">Outdoor Seating in Testville, TX – Page 2</h1>' ), $h );
check( 'the body follows the H1 untouched', '<p>body</p>' === substr( $h, -11 ) );
osn_request( 600, 0 );
$h = Page_Title::prepend( '' );
check( 'a neighborhood page, page 1: no suffix', false !== strpos( $h, '>Outdoor Seating in Old Town, Testville</h1>' ), $h );
osn_world( 1200 );
osn_request( 600, 2 );
$h = Page_Title::prepend( '' );
check( 'a neighborhood page, page 2: suffixed too', false !== strpos( $h, '>Outdoor Seating in Old Town, Testville – Page 2</h1>' ), $h );
osn_post( 700, 'about', 'Just words, no table.' );
osn_request( 700, 2 );
check( 'a page that is not a takeover grid gets no H1 from this code at all', '' === Page_Title::prepend( '' ) );

echo "\n--- meta description ---\n";
osn_world( 600 );
Page_Title::hooks();
$GLOBALS['osn']['meta'][500]['_dos_seo_description'] = 'Patios, rooftops and dog-friendly spots in Testville.';
osn_request( 500, 0 );
check( 'page 1: the stored description, unchanged', 'Patios, rooftops and dog-friendly spots in Testville.' === get_post_meta_filtered( 500, '_dos_seo_description', true ) );
osn_request( 500, 2 );
check( 'page 2: "Page 2 of 3. " in front', 'Page 2 of 3. Patios, rooftops and dog-friendly spots in Testville.' === get_post_meta_filtered( 500, '_dos_seo_description', true ) );
check( 'a list read (single false) gets an array', array( 'Page 2 of 3. Patios, rooftops and dog-friendly spots in Testville.' ) === get_post_meta_filtered( 500, '_dos_seo_description', false ) );
check( 'another meta key is untouched', 'x' === ( function () { $GLOBALS['osn']['meta'][500]['other'] = 'x'; return get_post_meta_filtered( 500, 'other', true ); } )() );
check( 'another post\'s description is untouched', 'Other.' === ( function () { $GLOBALS['osn']['meta'][600]['_dos_seo_description'] = 'Other.'; return get_post_meta_filtered( 600, '_dos_seo_description', true ); } )() );
check( 'the stored value itself is not changed', 'Patios, rooftops and dog-friendly spots in Testville.' === get_post_meta( 500, '_dos_seo_description', true ) );
$GLOBALS['osn']['meta'][500]['_dos_seo_description'] = '';
check( 'no stored description: nothing is invented', '' === get_post_meta_filtered( 500, '_dos_seo_description', true ) );

finish();
