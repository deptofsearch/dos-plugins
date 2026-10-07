<?php
/**
 * DoS Magazine Child — Department of Search.
 * Themify loads this theme's style.css on its own (class-themify-enqueue.php), so only the fonts are enqueued here.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_MAGAZINE_CHILD_VERSION', '0.1.0' );

function dos_magazine_child_fonts() {
	wp_enqueue_style(
		'dos-magazine-child-fonts',
		'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Inter+Tight:wght@500;600;700&display=swap',
		[],
		null
	);
}
add_action( 'wp_enqueue_scripts', 'dos_magazine_child_fonts' );

function dos_magazine_child_preconnect( $urls, $relation ) {
	if ( 'preconnect' === $relation ) {
		$urls[] = 'https://fonts.googleapis.com';
		$urls[] = [ 'href' => 'https://fonts.gstatic.com', 'crossorigin' ];
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'dos_magazine_child_preconnect', 10, 2 );
