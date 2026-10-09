<?php
/**
 * DoS Department — Department of Search block theme.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_DEPT_VERSION', '0.2.0' );

require_once __DIR__ . '/inc/posters.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/blocks.php';

/**
 * Theme setup.
 */
function dos_dept_setup() {
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/editor.css' );
	add_image_size( 'dos-poster', 960, 540, true );
}
add_action( 'after_setup_theme', 'dos_dept_setup' );

/**
 * Cache-busting version for a theme file: filemtime, falling back to the theme version.
 */
function dos_dept_asset_ver( $rel ) {
	$path = get_theme_file_path( $rel );
	return file_exists( $path ) ? (string) filemtime( $path ) : DOS_DEPT_VERSION;
}

function dos_dept_enqueue() {
	wp_enqueue_style( 'dos-bundle', get_theme_file_uri( 'assets/css/bundle.css' ), array(), dos_dept_asset_ver( 'assets/css/bundle.css' ) );
	wp_enqueue_style( 'dos-site', get_theme_file_uri( 'assets/css/site.css' ), array( 'dos-bundle' ), dos_dept_asset_ver( 'assets/css/site.css' ) );
}
add_action( 'wp_enqueue_scripts', 'dos_dept_enqueue' );

/**
 * Skip link, first thing in <body>.
 */
function dos_dept_skip_link() {
	echo '<a class="dos-skip" href="#main">' . esc_html__( 'Skip to content', 'dos-department' ) . '</a>';
}
add_action( 'wp_body_open', 'dos_dept_skip_link' );

/**
 * Body classes: `dos` hooks the design-system base, `dos-is-landing` marks pages whose
 * raw content carries its own hero (.dos-landing), so the template skips the duplicate title.
 */
function dos_dept_body_class( $classes ) {
	$classes[] = 'dos';
	if ( dos_dept_is_landing() ) {
		$classes[] = 'dos-is-landing';
	}
	return $classes;
}
add_filter( 'body_class', 'dos_dept_body_class' );

function dos_dept_is_landing() {
	static $cache = array();
	if ( ! is_singular() ) {
		return false;
	}
	$id = get_queried_object_id();
	if ( ! isset( $cache[ $id ] ) ) {
		$post          = get_post( $id );
		$cache[ $id ] = $post && false !== strpos( $post->post_content, 'dos-landing' );
	}
	return $cache[ $id ];
}

/**
 * Landing pages already have a hero with an h1: drop the template's post title block.
 */
function dos_dept_hide_landing_title( $block_content ) {
	return dos_dept_is_landing() ? '' : $block_content;
}
add_filter( 'render_block_core/post-title', 'dos_dept_hide_landing_title' );
add_filter( 'render_block_core/post-featured-image', 'dos_dept_hide_landing_title' );

/**
 * Pattern category.
 */
function dos_dept_pattern_category() {
	register_block_pattern_category( 'dos-department', array( 'label' => __( 'Department of Search', 'dos-department' ) ) );
}
add_action( 'init', 'dos_dept_pattern_category' );

/**
 * Block editor: register the server-rendered dos/* blocks on the JS side so the Site Editor
 * shows a server-side preview instead of "doesn't include support".
 */
function dos_dept_enqueue_editor() {
	wp_enqueue_script(
		'dos-editor',
		get_theme_file_uri( 'assets/js/editor.js' ),
		array( 'wp-blocks', 'wp-server-side-render', 'wp-element' ),
		dos_dept_asset_ver( 'assets/js/editor.js' ),
		true
	);
	wp_localize_script( 'dos-editor', 'dosBlocks', array( 'names' => dos_dept_block_names() ) );
}
add_action( 'enqueue_block_editor_assets', 'dos_dept_enqueue_editor' );
