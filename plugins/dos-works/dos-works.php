<?php
/**
 * Plugin Name: DoS Works
 * Plugin URI:  https://departmentofsearch.com
 * Description: Registers the Works (dos_work) post type, its case-file meta fields and a simple meta box for the Department of Search portfolio.
 * Version:     0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Author:      Department of Search
 * License:     GPL-2.0-or-later
 * Text Domain: dos-works
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_WORKS_VERSION', '0.1.0' );

/**
 * Post type. has_archive is false: a PAGE with the slug `works` hosts the archive.
 */
function dos_works_register_post_type() {
	register_post_type(
		'dos_work',
		array(
			'labels'       => array(
				'name'               => __( 'Works', 'dos-works' ),
				'singular_name'      => __( 'Work', 'dos-works' ),
				'add_new'            => __( 'Add New', 'dos-works' ),
				'add_new_item'       => __( 'Add New Work', 'dos-works' ),
				'edit_item'          => __( 'Edit Work', 'dos-works' ),
				'new_item'           => __( 'New Work', 'dos-works' ),
				'view_item'          => __( 'View Work', 'dos-works' ),
				'view_items'         => __( 'View Works', 'dos-works' ),
				'search_items'       => __( 'Search Works', 'dos-works' ),
				'not_found'          => __( 'No works found.', 'dos-works' ),
				'not_found_in_trash' => __( 'No works found in Trash.', 'dos-works' ),
				'all_items'          => __( 'All Works', 'dos-works' ),
				'menu_name'          => __( 'Works', 'dos-works' ),
			),
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => false,
			'menu_icon'    => 'dashicons-portfolio',
			'menu_position' => 21,
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ), // custom-fields is required for meta in REST.
			'rewrite'      => array( 'slug' => 'works', 'with_front' => false ),
		)
	);
}
add_action( 'init', 'dos_works_register_post_type' );

/**
 * Meta fields (single strings, exposed in REST). dos_featured is a boolean.
 */
function dos_works_meta_fields() {
	return array(
		'dos_case_no'  => array( 'label' => __( 'Case number', 'dos-works' ), 'help' => __( 'e.g. 001. Shown as WORK NO. 001.', 'dos-works' ) ),
		'dos_client'   => array( 'label' => __( 'Client', 'dos-works' ), 'help' => '' ),
		'dos_year'     => array( 'label' => __( 'Year', 'dos-works' ), 'help' => '' ),
		'dos_stack'    => array( 'label' => __( 'Stack', 'dos-works' ), 'help' => __( 'Comma-separated, e.g. n8n, WordPress, Claude. The first one gets the navy tag.', 'dos-works' ) ),
		'dos_status'   => array( 'label' => __( 'Status', 'dos-works' ), 'help' => __( 'e.g. Live, Underway.', 'dos-works' ) ),
		'dos_result'   => array( 'label' => __( 'Result (one line)', 'dos-works' ), 'help' => '' ),
		'dos_featured' => array( 'label' => __( 'Featured', 'dos-works' ), 'help' => __( 'Show first on the Works page and in its case-file band.', 'dos-works' ) ),
	);
}

function dos_works_register_meta() {
	$auth = static function () {
		return current_user_can( 'edit_posts' );
	};
	foreach ( array_keys( dos_works_meta_fields() ) as $key ) {
		register_post_meta(
			'dos_work',
			$key,
			array(
				'type'              => 'dos_featured' === $key ? 'boolean' : 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => 'dos_featured' === $key ? false : '',
				'sanitize_callback' => 'dos_featured' === $key ? 'rest_sanitize_boolean' : 'sanitize_text_field',
				'auth_callback'     => $auth,
			)
		);
	}
}
add_action( 'init', 'dos_works_register_meta' );

/**
 * Classic meta box.
 */
function dos_works_add_meta_box() {
	add_meta_box( 'dos-works-case-file', __( 'Case file', 'dos-works' ), 'dos_works_render_meta_box', 'dos_work', 'normal', 'high' );
	// The Case file box covers these fields; hide the generic Custom Fields box.
	remove_meta_box( 'postcustom', 'dos_work', 'normal' );
}
add_action( 'add_meta_boxes', 'dos_works_add_meta_box' );

function dos_works_render_meta_box( $post ) {
	wp_nonce_field( 'dos_works_save', 'dos_works_nonce' );
	echo '<table class="form-table" role="presentation"><tbody>';
	foreach ( dos_works_meta_fields() as $key => $field ) {
		$val = get_post_meta( $post->ID, $key, true );
		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
		if ( 'dos_featured' === $key ) {
			echo '<label><input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( rest_sanitize_boolean( $val ), true, false ) . '> ' . esc_html__( 'Featured work', 'dos-works' ) . '</label>';
		} else {
			echo '<input type="text" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( is_scalar( $val ) ? (string) $val : '' ) . '">';
		}
		if ( $field['help'] ) {
			echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

function dos_works_save_meta( $post_id ) {
	if ( ! isset( $_POST['dos_works_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dos_works_nonce'] ) ), 'dos_works_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( array_keys( dos_works_meta_fields() ) as $key ) {
		if ( 'dos_featured' === $key ) {
			update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? '1' : '' );
			continue;
		}
		$val = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		update_post_meta( $post_id, $key, $val );
	}
}
add_action( 'save_post_dos_work', 'dos_works_save_meta' );

/**
 * 301 the old page URLs to their new homes: /blog/ (and /blog/page/N/) to /dispatches/, /about/ to
 * /personnel-file/. Skipped whenever a real page exists at the old path.
 */
function dos_works_legacy_redirects() {
	if ( is_admin() ) {
		return;
	}
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$qs   = (string) wp_parse_url( $uri, PHP_URL_QUERY );
	$path = '/' . trim( $path, '/' );

	$target = '';
	if ( '/blog' === $path ) {
		$target = '/dispatches/';
	} elseif ( preg_match( '#^/blog/page/(\d+)$#', $path, $m ) ) {
		$target = '/dispatches/page/' . (int) $m[1] . '/';
	} elseif ( '/about' === $path ) {
		$target = '/personnel-file/';
	}
	if ( '' === $target ) {
		return;
	}
	$old = ltrim( preg_replace( '#/page/\d+$#', '', $path ), '/' );
	if ( get_page_by_path( $old ) ) {
		return; // a real page lives here; never shadow it.
	}
	wp_safe_redirect( home_url( $target . ( '' !== $qs ? '?' . $qs : '' ) ), 301 );
	exit;
}
add_action( 'template_redirect', 'dos_works_legacy_redirects' );

/**
 * Activation / deactivation: flush rewrite rules so /works/<slug>/ resolves.
 */
function dos_works_activate() {
	dos_works_register_post_type();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'dos_works_activate' );

function dos_works_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'dos_works_deactivate' );
