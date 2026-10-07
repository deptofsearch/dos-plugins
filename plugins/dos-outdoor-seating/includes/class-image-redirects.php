<?php
/**
 * 301s requests for venue photos that were converted to WebP (0.3.6 optimizer) to the new file.
 *
 * The optimizer keeps the attachment ID but deletes the old .png/.jpg original and sub-sizes.
 * Cached pages, image search and social shares can still ask for those URLs; on WP.com Atomic a
 * missing upload falls through to WordPress as a 404, so we catch it here and send the visitor to
 * the WebP of the same size (or the closest one).
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Image_Redirects {

	public static function hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	public static function maybe_redirect() {
		if ( ! is_404() ) {
			return;
		}
		$path    = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$uploads = wp_get_upload_dir();
		$base    = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( '' === $base || 0 !== strpos( $path, trailingslashit( $base ) ) ) {
			return;
		}
		$rel = substr( $path, strlen( trailingslashit( $base ) ) ); // e.g. 2026/10/cork-craft-768x499.png
		if ( ! preg_match( '#^(.+?)(?:-(\d+)x(\d+))?\.(png|jpe?g)$#i', $rel, $m ) ) {
			return;
		}
		$stem = $m[1];
		$want = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;

		$id = self::attachment_for( $stem . '.webp' );
		if ( ! $id ) {
			return;
		}
		$url = self::sized_url( $id, $want );
		if ( $url ) {
			wp_safe_redirect( $url, 301, 'dos-outdoor-seating' );
			exit;
		}
	}

	/** Attachment ID whose stored file is $file and that came from a venue website. */
	private static function attachment_for( $file ) {
		global $wpdb;
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.post_id FROM {$wpdb->postmeta} p
				 INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.post_id AND s.meta_key = '_osn_source_image_url'
				 WHERE p.meta_key = '_wp_attached_file' AND p.meta_value = %s LIMIT 1",
				$file
			)
		);
		return $id;
	}

	/** URL of the size whose width is closest to $want (0 = the full image). */
	private static function sized_url( $id, $want ) {
		$full = wp_get_attachment_url( $id );
		if ( ! $want ) {
			return $full;
		}
		$meta  = wp_get_attachment_metadata( $id );
		$best  = '';
		$delta = PHP_INT_MAX;
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( empty( $size['file'] ) || empty( $size['width'] ) ) {
				continue;
			}
			$d = abs( (int) $size['width'] - $want );
			if ( $d < $delta ) {
				$delta = $d;
				$best  = $size['file'];
			}
		}
		if ( '' === $best ) {
			return $full;
		}
		return trailingslashit( dirname( $full ) ) . $best;
	}
}
