<?php
/**
 * POST /saab/v1/restaurants/image: n8n finds an image on a restaurant's own website (og:image, twitter:image, JSON-LD,
 * image_src); this class downloads, validates and sideloads it, then sets it as the page's featured image, credited to
 * that website. Ported from DoS Outdoor Seating's /osn/v1/venues/image (2026-11-03), without its optimize/repair
 * routes. Google Maps photos are deliberately not used: they are customers' uploads, not the restaurant's.
 *
 * @package SAAB_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'SAAB_Restaurant_Image', false ) ) {
	return;
}

class SAAB_Restaurant_Image {

	const REST_NAMESPACE = 'saab/v1';
	const MAX_ITEMS      = 5;
	const MAX_BYTES      = 8388608; // 8 MB.
	const MIN_WIDTH      = 600;
	const MIN_HEIGHT     = 300;
	const MIN_RATIO      = 1.0;
	const MAX_RATIO      = 3.2;
	const MAX_WIDTH      = 1600;
	const QUALITY        = 82;
	const TIMEOUT        = 20;
	const MIN_BYTES      = 1024;
	const URL_META       = '_saab_source_image_url';
	const CREDIT_URL     = 'saab_photo_credit_url';
	const CREDIT_TEXT    = 'saab_photo_credit_text';
	const BAD_NAME_RE    = '/logo|icon|favicon|sprite|placeholder|avatar/i';
	const MIMES          = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ), 11 );
	}

	public static function register_meta() {
		foreach ( array( self::CREDIT_URL, self::CREDIT_TEXT ) as $key ) {
			register_post_meta( SAAB_Restaurants::POST_TYPE, $key, array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			) );
		}
	}

	public static function register_route() {
		register_rest_route( self::REST_NAMESPACE, '/restaurants/image', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => function () {
				return current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'items'   => array(
					'description' => 'Array of { id, image_url, credit_url, credit_text?, force? } (max ' . self::MAX_ITEMS . '). The first item that passes wins; later items for the same page are skipped.',
					'type'        => 'array',
					'required'    => true,
				),
				'dry_run' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		) );
	}

	public static function handle( WP_REST_Request $request ) {
		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'saab_bad_items', 'items must be an array.', array( 'status' => 400 ) );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return new WP_Error( 'saab_batch_too_large', sprintf( 'Send at most %d items per request.', self::MAX_ITEMS ), array( 'status' => 400 ) );
		}
		$dry = (bool) $request->get_param( 'dry_run' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! $dry ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$counts  = array_fill_keys( array( 'set', 'skipped', 'rejected', 'error' ), 0 );
		$results = array();
		$done    = array(); // Page IDs that got a photo in this request: their later candidates are skipped.
		foreach ( $items as $raw ) {
			$id = is_array( $raw ) && ! empty( $raw['id'] ) && is_numeric( $raw['id'] ) ? (int) $raw['id'] : 0;
			if ( $id && isset( $done[ $id ] ) ) {
				$row = self::result( $id, 'skipped', 'An earlier candidate in this request was set.' );
			} else {
				$row = self::process( $raw, $dry );
				if ( 'set' === $row['action'] && $id ) {
					$done[ $id ] = true;
				}
			}
			++$counts[ $row['action'] ];
			$results[] = $row;
		}
		return rest_ensure_response( array(
			'dry_run' => $dry,
			'counts'  => $counts,
			'results' => $results,
		) );
	}

	private static function result( $id, $action, $reason = '', $att = null, $w = null, $h = null ) {
		return array(
			'id'            => $id,
			'action'        => $action,
			'reason'        => $reason,
			'attachment_id' => $att,
			'width'         => $w,
			'height'        => $h,
		);
	}

	/**
	 * Whether a candidate image's size and type are usable: jpeg/png/webp, at least 600x300, aspect 1:1 to 3.2:1
	 * (the photo strip crops wide). Pure, so the tests can pin it.
	 *
	 * @return string '' when fine, else the reason.
	 */
	public static function check_dimensions( $w, $h, $mime ) {
		if ( ! isset( self::MIMES[ $mime ] ) ) {
			return 'Unsupported image type (' . ( '' !== (string) $mime ? $mime : 'unknown' ) . '); jpeg, png and webp only.';
		}
		if ( $w < self::MIN_WIDTH || $h < self::MIN_HEIGHT ) {
			return sprintf( 'Too small (%dx%d); need at least %dx%d.', $w, $h, self::MIN_WIDTH, self::MIN_HEIGHT );
		}
		$ratio = $w / $h;
		if ( $ratio < self::MIN_RATIO || $ratio > self::MAX_RATIO ) {
			return sprintf( 'Aspect ratio %.2f:1 is outside %.1f:1 to %.1f:1.', $ratio, self::MIN_RATIO, self::MAX_RATIO );
		}
		return '';
	}

	/** @return array One result row. */
	private static function process( $raw, $dry ) {
		if ( ! is_array( $raw ) ) {
			return self::result( null, 'error', 'Each item must be an object.' );
		}
		$id   = ! empty( $raw['id'] ) && is_numeric( $raw['id'] ) ? (int) $raw['id'] : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || SAAB_Restaurants::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return self::result( $id ? $id : null, 'error', 'Restaurant page not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return self::result( $id, 'error', 'You are not allowed to edit this page.' );
		}
		$force = ! empty( $raw['force'] ) && filter_var( $raw['force'], FILTER_VALIDATE_BOOLEAN );
		if ( ! $force && get_post_thumbnail_id( $id ) ) {
			return self::result( $id, 'skipped', 'Page already has a featured image (send force to replace).', (int) get_post_thumbnail_id( $id ) );
		}

		$url  = isset( $raw['image_url'] ) && is_string( $raw['image_url'] ) ? esc_url_raw( trim( $raw['image_url'] ), array( 'http', 'https' ) ) : '';
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || '' === $host || false !== strpos( $host, ':' ) || ! wp_http_validate_url( $url ) ) {
			return self::result( $id, 'rejected', 'image_url is missing or not a public http(s) URL.' );
		}
		if ( preg_match( self::BAD_NAME_RE, rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ) {
			return self::result( $id, 'rejected', 'URL looks like a logo, icon, sprite or placeholder.' );
		}

		$title = get_the_title( $id );
		$existing = self::attachment_for_url( $url );
		if ( $existing ) {
			$meta = wp_get_attachment_metadata( $existing );
			$w    = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : null;
			$h    = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : null;
			if ( $dry ) {
				return self::result( $id, 'set', 'dry_run: would reuse existing attachment.', $existing, $w, $h );
			}
			self::apply( $id, $existing, $raw, $title );
			return self::result( $id, 'set', 'Reused existing attachment from the same URL.', $existing, $w, $h );
		}

		$cap = static function ( $args, $u ) use ( $url ) {
			if ( $u === $url ) {
				$args['limit_response_size'] = self::MAX_BYTES + 1;
			}
			return $args;
		};
		add_filter( 'http_request_args', $cap, 10, 2 );
		$tmp = download_url( $url, self::TIMEOUT );
		remove_filter( 'http_request_args', $cap, 10 );
		if ( is_wp_error( $tmp ) ) {
			return self::result( $id, 'error', 'Download failed: ' . $tmp->get_error_message() );
		}
		if ( filesize( $tmp ) > self::MAX_BYTES ) {
			wp_delete_file( $tmp );
			return self::result( $id, 'rejected', 'Image is larger than 8MB.' );
		}
		$info = wp_getimagesize( $tmp );
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			wp_delete_file( $tmp );
			return self::result( $id, 'rejected', 'Not a readable image.' );
		}
		$w    = (int) $info[0];
		$h    = (int) $info[1];
		$mime = isset( $info['mime'] ) ? (string) $info['mime'] : '';
		$bad  = self::check_dimensions( $w, $h, $mime );
		if ( '' !== $bad ) {
			wp_delete_file( $tmp );
			return self::result( $id, 'rejected', $bad, null, $w, $h );
		}
		if ( $dry ) {
			wp_delete_file( $tmp );
			return self::result( $id, 'set', 'dry_run: image passed validation, nothing saved.', null, $w, $h );
		}

		// Rename with an extension, then re-encode: EXIF-rotate, shrink to 1600 wide, WebP q82 (JPEG where WebP can't be written).
		$file = $tmp . '.' . self::MIMES[ $mime ];
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$file = $tmp;
		}
		$enc = self::reencode( $file );
		if ( $enc ) {
			if ( $enc['path'] !== $file ) {
				wp_delete_file( $file );
			}
			$file = $enc['path'];
			$w    = $enc['width'];
			$h    = $enc['height'];
		}
		if ( ! file_exists( $file ) || filesize( $file ) <= self::MIN_BYTES ) {
			wp_delete_file( $file );
			return self::result( $id, 'error', 'The downloaded image could not be saved; nothing linked.' );
		}

		$slug = sanitize_title( $title );
		$att  = media_handle_sideload(
			array(
				'name'     => ( '' !== $slug ? $slug : 'restaurant-' . $id ) . '.' . strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ),
				'tmp_name' => $file,
			),
			$id,
			null,
			array( 'post_title' => $title . ' — photo' )
		);
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $file );
			return self::result( $id, 'error', 'Sideload failed: ' . $att->get_error_message() );
		}
		update_post_meta( $att, self::URL_META, $url );
		$city = SAAB_Restaurants::get_city_name( $id );
		update_post_meta( $att, '_wp_attachment_image_alt', wp_slash( $city ? sprintf( '%s in %s', $title, $city ) : $title ) );
		self::apply( $id, (int) $att, $raw, $title );
		return self::result( $id, 'set', '', (int) $att, $w, $h );
	}

	/** EXIF-rotate, shrink to MAX_WIDTH, save as WebP (else JPEG) at QUALITY. Null when the editor can't. */
	private static function reencode( $file ) {
		$formats = array();
		if ( wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$formats[] = array( 'image/webp', 'webp' );
		}
		$formats[] = array( 'image/jpeg', 'jpg' );
		foreach ( $formats as $f ) {
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				return null;
			}
			$editor->maybe_exif_rotate();
			$size = $editor->get_size();
			if ( ! empty( $size['width'] ) && (int) $size['width'] > self::MAX_WIDTH ) {
				$editor->resize( self::MAX_WIDTH, null, false );
			}
			$editor->set_quality( self::QUALITY );
			$dir   = dirname( $file );
			$dest  = trailingslashit( $dir ) . wp_unique_filename( $dir, pathinfo( $file, PATHINFO_FILENAME ) . '.' . $f[1] );
			$saved = $editor->save( $dest, $f[0] );
			if ( is_wp_error( $saved ) ) {
				continue;
			}
			$path = ! empty( $saved['path'] ) ? $saved['path'] : $dest;
			if ( file_exists( $path ) && filesize( $path ) > self::MIN_BYTES ) {
				return array( 'path' => $path, 'width' => (int) $saved['width'], 'height' => (int) $saved['height'] );
			}
			if ( file_exists( $path ) && $path !== $file ) {
				wp_delete_file( $path );
			}
		}
		return null;
	}

	/** An attachment already sideloaded from this exact URL (a chain of locations shares one image). */
	private static function attachment_for_url( $url ) {
		$q = get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_key'         => self::URL_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		$att = $q ? (int) $q[0] : 0;
		if ( $att ) {
			$file = get_attached_file( $att );
			if ( ! $file || ! file_exists( $file ) || filesize( $file ) <= self::MIN_BYTES ) {
				return 0;
			}
		}
		return $att;
	}

	/** Featured image + credit meta. Plain meta writes, so the page's modified date is not bumped. */
	private static function apply( $id, $att, array $raw, $title ) {
		update_post_meta( $id, '_thumbnail_id', $att );
		$credit_url = isset( $raw['credit_url'] ) && is_string( $raw['credit_url'] ) ? esc_url_raw( trim( $raw['credit_url'] ), array( 'http', 'https' ) ) : '';
		if ( '' === $credit_url ) {
			$credit_url = (string) get_post_meta( $id, 'saab_website', true );
		}
		$credit_text = isset( $raw['credit_text'] ) && is_string( $raw['credit_text'] ) ? sanitize_text_field( $raw['credit_text'] ) : '';
		if ( '' === $credit_text ) {
			$credit_text = sprintf( "Photo: %s's website", $title );
		}
		update_post_meta( $id, self::CREDIT_URL, $credit_url );
		update_post_meta( $id, self::CREDIT_TEXT, $credit_text );
		clean_post_cache( $id );
	}
}
