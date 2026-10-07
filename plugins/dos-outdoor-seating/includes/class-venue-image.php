<?php
/**
 * POST /osn/v1/venues/image: n8n finds an image URL on a venue's own website (og:image etc.); this class downloads,
 * validates and sideloads it, then sets it as the venue's featured image.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Venue_Image {

	const MAX_ITEMS   = 5;
	const MAX_BYTES   = 8 * MB_IN_BYTES;
	const MIN_WIDTH   = 600;
	const MIN_HEIGHT  = 300;
	const MIN_RATIO   = 1.0;
	const MAX_RATIO   = 3.2;
	const MAX_WIDTH   = 1600;
	const QUALITY     = 82;
	const TIMEOUT     = 20;
	const URL_META    = '_osn_source_image_url';
	const BROKEN_META = '_osn_broken';
	const MIN_BYTES   = 1024; // A stored photo of this size or less is treated as empty.
	const MIN_STORED_WIDTH = 300; // Stored photos narrower than this (per file or metadata) are treated as broken.
	const BAD_NAME_RE = '/logo|icon|favicon|sprite|placeholder/i';
	const MIMES       = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	public static function register() {
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/venues/image',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'items'   => array(
						'description' => 'Array of { id | source + source_id, image_url, credit_url, credit_text?, force? } (max ' . self::MAX_ITEMS . ').',
						'type'        => 'array',
						'required'    => true,
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/venues/images/optimize',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'optimize' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'offset'  => array( 'type' => 'integer', 'default' => 0 ),
					'limit'   => array( 'type' => 'integer', 'default' => 20 ),
					'dry_run' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/venues/images/repair',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'repair' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'offset'  => array( 'type' => 'integer', 'default' => 0 ),
					'limit'   => array( 'type' => 'integer', 'default' => 10 ),
					'dry_run' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
	}

	public static function handle( \WP_REST_Request $request ) {
		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			return new \WP_Error( 'osn_bad_items', 'items must be an array.', array( 'status' => 400 ) );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return new \WP_Error( 'osn_batch_too_large', sprintf( 'Send at most %d items per request.', self::MAX_ITEMS ), array( 'status' => 400 ) );
		}
		$dry = (bool) $request->get_param( 'dry_run' );
		if ( ! $dry ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php'; // download_url() for dry runs too.

		$t0      = microtime( true );
		$budget  = (float) apply_filters( 'osn_image_time_budget', 60 );
		$counts  = array_fill_keys( array( 'set', 'skipped', 'rejected', 'error' ), 0 );
		$results = array();
		foreach ( $items as $raw ) {
			if ( microtime( true ) - $t0 > $budget ) {
				$rid = is_array( $raw ) && ! empty( $raw['id'] ) && is_numeric( $raw['id'] ) ? (int) $raw['id'] : null;
				$row = self::result( $rid, 'error', 'Deferred: request time budget reached; resend this item.' );
			} else {
				$row = self::process( $raw, $dry );
			}
			++$counts[ $row['action'] ];
			$results[] = $row;
		}
		return rest_ensure_response(
			array(
				'dry_run' => $dry,
				'counts'  => $counts,
				'results' => $results,
			)
		);
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

	/** @return array One result row. */
	private static function process( $raw, $dry ) {
		if ( ! is_array( $raw ) ) {
			return self::result( null, 'error', 'Each item must be an object.' );
		}
		// Find the venue.
		$id = 0;
		if ( ! empty( $raw['id'] ) && is_numeric( $raw['id'] ) ) {
			$id = (int) $raw['id'];
		} elseif ( ! empty( $raw['source'] ) && isset( $raw['source_id'] ) && is_scalar( $raw['source'] ) && is_scalar( $raw['source_id'] ) && '' !== (string) $raw['source_id'] ) {
			$id = (int) Repository::find( sanitize_text_field( (string) $raw['source'] ), sanitize_text_field( (string) $raw['source_id'] ) );
		}
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || Data_Model::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return self::result( $id ? $id : null, 'error', 'Venue not found (send id, or source + source_id).' );
		}
		$id    = (int) $post->ID;
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return self::result( $id, 'error', 'You are not allowed to edit this venue.' );
		}
		$force = ! empty( $raw['force'] ) && filter_var( $raw['force'], FILTER_VALIDATE_BOOLEAN );
		if ( ! $force && Util::thumb_id( $id ) ) {
			return self::result( $id, 'skipped', 'Venue already has a featured image (send force to replace).', Util::thumb_id( $id ) );
		}

		// URL checks.
		$url = isset( $raw['image_url'] ) && is_string( $raw['image_url'] ) ? esc_url_raw( trim( $raw['image_url'] ), array( 'http', 'https' ) ) : '';
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || '' === $host || false !== strpos( $host, ':' ) || ! wp_http_validate_url( $url ) ) { // No IPv6 literal hosts.
			return self::result( $id, 'rejected', 'image_url is missing or not a public http(s) URL.' );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( self::BAD_NAME_RE, rawurldecode( $path ) ) ) {
			return self::result( $id, 'rejected', 'URL looks like a logo, icon, sprite or placeholder.' );
		}

		$title = get_the_title( $id );
		$term  = Util::first_term( $id, Data_Model::CITY );
		$city  = $term ? Util::city_label( $term->name ) : (string) Util::meta( $id, 'city_name' );

		// Reuse an attachment already sideloaded from this exact URL (chains share images).
		$existing = self::attachment_for_url( $url, ! $dry );
		if ( $existing ) {
			$meta = wp_get_attachment_metadata( $existing );
			$w    = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : null;
			$h    = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : null;
			if ( $dry ) {
				return self::result( $id, 'set', 'dry_run: would reuse existing attachment.', $existing, $w, $h );
			}
			self::apply( $id, $existing, $url, $raw, $title );
			return self::result( $id, 'set', 'Reused existing attachment from the same URL.', $existing, $w, $h );
		}

		// Download (20s, 8MB cap enforced by asking for one byte more than the cap).
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
		if ( ! isset( self::MIMES[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return self::result( $id, 'rejected', 'Unsupported image type (' . ( '' !== $mime ? $mime : 'unknown' ) . '); jpeg, png and webp only.', null, $w, $h );
		}
		if ( $w < self::MIN_WIDTH || $h < self::MIN_HEIGHT ) {
			wp_delete_file( $tmp );
			return self::result( $id, 'rejected', sprintf( 'Too small (%dx%d); need at least %dx%d.', $w, $h, self::MIN_WIDTH, self::MIN_HEIGHT ), null, $w, $h );
		}
		$ratio = $w / $h;
		// Cards crop to 16:10 and the hero to 21:9, so wide banners and squares still frame well.
		$min_r = (float) apply_filters( 'osn_image_min_ratio', self::MIN_RATIO );
		$max_r = (float) apply_filters( 'osn_image_max_ratio', self::MAX_RATIO );
		if ( $ratio < $min_r || $ratio > $max_r ) {
			wp_delete_file( $tmp );
			return self::result( $id, 'rejected', sprintf( 'Aspect ratio %.2f:1 is outside %.1f:1 to %.1f:1.', $ratio, $min_r, $max_r ), null, $w, $h );
		}
		if ( $dry ) {
			wp_delete_file( $tmp );
			return self::result( $id, 'set', 'dry_run: image passed validation, nothing saved.', null, $w, $h );
		}

		// Name the temp file with a proper extension, then re-encode: rotate, shrink to 1600 wide, WebP q82
		// (JPEG q82 where the server has no WebP), so the original and every sub-size are small.
		$ext  = self::MIMES[ $mime ];
		$file = $tmp . '.' . $ext;
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$file = $tmp;
		}
		$why = '';
		$enc = self::reencode( $file, dirname( $file ), pathinfo( $file, PATHINFO_FILENAME ), $why );
		if ( $enc ) {
			if ( $enc['path'] !== $file ) {
				wp_delete_file( $file );
			}
			$file = $enc['path'];
			$ext  = $enc['ext'];
			$w    = $enc['width'];
			$h    = $enc['height'];
		} else {
			// WebP and JPEG both failed: fall back to the downloaded original, but never sideload an empty file.
			$ok = self::verify_file( $file, $mime );
			if ( true !== $ok ) {
				wp_delete_file( $file );
				return self::result( $id, 'error', 'Re-encode failed (' . $why . ') and the downloaded original is unusable (' . $ok . '); nothing saved.' );
			}
		}

		$slug = sanitize_title( $title );
		$att  = media_handle_sideload(
			array(
				'name'     => ( '' !== $slug ? $slug : 'venue-' . $id ) . '.' . $ext,
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
		$bad = self::attachment_problem( (int) $att );
		if ( '' !== $bad ) { // Never link a venue to a broken attachment; flag it for the repair endpoint.
			update_post_meta( $att, self::URL_META, $url );
			update_post_meta( $att, self::BROKEN_META, '1' );
			return self::result( $id, 'error', 'Sideloaded file failed verification (' . $bad . '); not linked. Run /venues/images/repair.', (int) $att );
		}
		update_post_meta( $att, self::URL_META, $url );
		update_post_meta( $att, '_wp_attachment_image_alt', wp_slash( '' !== $city ? sprintf( '%s in %s', $title, $city ) : $title ) );
		self::apply( $id, (int) $att, $url, $raw, $title );
		return self::result( $id, 'set', '', (int) $att, $w, $h );
	}

	/** Output format for stored photos: WebP where the image editor can write it, else JPEG. */
	public static function target() {
		$webp = (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		return $webp ? array( 'mime' => 'image/webp', 'ext' => 'webp' ) : array( 'mime' => 'image/jpeg', 'ext' => 'jpg' );
	}

	/**
	 * Check a freshly written image before anything depends on it: exists, more than MIN_BYTES, readable by
	 * wp_getimagesize with width >= MIN_STORED_WIDTH and (when given) the expected mime.
	 *
	 * @return true|string True when fine, else the reason.
	 */
	public static function verify_file( $path, $mime = '' ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return 'file is missing';
		}
		$n = (int) filesize( $path );
		if ( $n <= self::MIN_BYTES ) {
			return sprintf( 'file is only %d bytes', $n );
		}
		$i = wp_getimagesize( $path );
		if ( ! is_array( $i ) || empty( $i[0] ) || empty( $i[1] ) ) {
			return 'not a readable image';
		}
		if ( (int) $i[0] < self::MIN_STORED_WIDTH ) {
			return sprintf( 'width %d is under %d', (int) $i[0], self::MIN_STORED_WIDTH );
		}
		if ( '' !== $mime && ( ! isset( $i['mime'] ) || $i['mime'] !== $mime ) ) {
			return sprintf( 'mime is %s, expected %s', isset( $i['mime'] ) ? $i['mime'] : 'unknown', $mime );
		}
		return true;
	}

	/**
	 * Rotate per EXIF, shrink to MAX_WIDTH wide, and save as WebP (or JPEG) at quality 82 into $dir as "$name.ext"
	 * (made unique when taken). Photos only: transparency is not preserved. Every output is verified (verify_file);
	 * a WebP that fails is deleted and JPEG q82 is tried instead.
	 *
	 * @param string      $reason Out: why no format worked ("verification: ..." / "editor: ..." per format).
	 * @return array|null { path, ext, mime, width, height, fallback }, or null when nothing verified (caller keeps the file as is).
	 */
	public static function reencode( $file, $dir, $name, &$reason = null ) {
		$t       = self::target();
		$formats = array( $t );
		if ( 'image/jpeg' !== $t['mime'] ) {
			$formats[] = array( 'mime' => 'image/jpeg', 'ext' => 'jpg' );
		}
		$reasons = array();
		foreach ( $formats as $f ) {
			$err = '';
			$enc = self::encode_as( $file, $dir, $name, $f, $err );
			if ( $enc ) {
				$enc['fallback'] = $f['mime'] !== $t['mime'];
				return $enc;
			}
			$reasons[] = $f['mime'] . ' ' . $err;
		}
		$reason = implode( '; ', $reasons );
		return null;
	}

	/** One verified encode into a new file; a file that fails verification is deleted (never the source). */
	private static function encode_as( $file, $dir, $name, array $f, &$err ) {
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			$err = 'editor: ' . $editor->get_error_message();
			return null;
		}
		$editor->maybe_exif_rotate();
		$size = $editor->get_size();
		if ( ! empty( $size['width'] ) && (int) $size['width'] > self::MAX_WIDTH ) {
			$resized = $editor->resize( self::MAX_WIDTH, null, false );
			if ( is_wp_error( $resized ) ) {
				$err = 'editor: ' . $resized->get_error_message();
				return null;
			}
		}
		$editor->set_quality( self::QUALITY );
		$dest  = trailingslashit( $dir ) . wp_unique_filename( $dir, $name . '.' . $f['ext'] );
		$saved = $editor->save( $dest, $f['mime'] );
		if ( is_wp_error( $saved ) ) {
			$err = 'editor: ' . $saved->get_error_message();
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			return null;
		}
		$path = ! empty( $saved['path'] ) ? $saved['path'] : $dest;
		$ok   = self::verify_file( $path, $f['mime'] );
		if ( true !== $ok ) {
			if ( $path !== $file && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			$err = 'verification: ' . $ok;
			return null;
		}
		return array(
			'path'   => $path,
			'ext'    => strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ),
			'mime'   => $f['mime'],
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
		);
	}

	/**
	 * Why a stored attachment cannot be shown ('' when healthy): file missing, 1 KB or less, or metadata width under 300.
	 * Does not touch the flag meta.
	 */
	private static function attachment_problem( $att ) {
		$file = get_attached_file( $att );
		if ( ! $file || ! file_exists( $file ) ) {
			return 'attached file is missing';
		}
		$n = (int) filesize( $file );
		if ( $n <= self::MIN_BYTES ) {
			return sprintf( 'attached file is %d bytes', $n );
		}
		$meta = wp_get_attachment_metadata( $att );
		$w    = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		if ( $w < self::MIN_STORED_WIDTH ) {
			return sprintf( 'metadata width is %d', $w );
		}
		return '';
	}

	/* ---------------------------------------------------------------- POST /venues/images/optimize */

	/**
	 * Convert photos sideloaded before 0.3.6 (any attachment with _osn_source_image_url that is not WebP) in place:
	 * same attachment ID, new file and sub-sizes, old files deleted. Converted rows leave the pending set, so
	 * next_offset only counts the rows that stayed (dry run, skipped, error).
	 */
	public static function optimize( \WP_REST_Request $req ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$limit  = max( 1, min( 100, (int) $req['limit'] ) );
		$offset = max( 0, (int) $req['offset'] );
		$dry    = (bool) $req['dry_run'];
		$q      = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/png', 'image/jpeg', 'image/gif' ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => self::URL_META, 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$t0      = microtime( true );
		$budget  = (float) apply_filters( 'osn_image_time_budget', 60 );
		$counts  = array_fill_keys( array( 'converted', 'would_convert', 'skipped', 'failed_verification', 'error' ), 0 );
		$results = array();
		$stayed  = 0;
		foreach ( $q->posts as $att ) {
			if ( microtime( true ) - $t0 > $budget ) {
				$row = array( 'id' => (int) $att, 'action' => 'error', 'reason' => 'Deferred: request time budget reached; call again.' );
			} else {
				$row = self::optimize_one( (int) $att, $dry );
			}
			++$counts[ $row['action'] ];
			if ( 'converted' !== $row['action'] ) {
				++$stayed;
			}
			$results[] = $row;
		}
		return rest_ensure_response(
			array(
				'dry_run'     => $dry,
				'pending'     => (int) $q->found_posts,
				'counts'      => $counts,
				'next_offset' => count( $q->posts ) < $limit ? null : $offset + $stayed,
				'results'     => $results,
			)
		);
	}

	/** All files an attachment owns: original, sub-sizes, the pre-scaled original and edit backups. */
	private static function attachment_files( $att ) {
		$file  = get_attached_file( $att );
		$dir   = $file ? dirname( $file ) : '';
		$files = $file ? array( $file ) : array();
		$meta  = wp_get_attachment_metadata( $att );
		foreach ( ( is_array( $meta ) && ! empty( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $s ) {
			if ( ! empty( $s['file'] ) ) {
				$files[] = $dir . '/' . $s['file'];
			}
		}
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . '/' . $meta['original_image'];
		}
		$backup = get_post_meta( $att, '_wp_attachment_backup_sizes', true );
		foreach ( is_array( $backup ) ? $backup : array() as $s ) {
			if ( ! empty( $s['file'] ) ) {
				$files[] = $dir . '/' . $s['file'];
			}
		}
		return array_values( array_unique( $files ) );
	}

	private static function bytes( array $files ) {
		$n = 0;
		foreach ( $files as $f ) {
			$n += file_exists( $f ) ? (int) filesize( $f ) : 0;
		}
		return $n;
	}

	private static function optimize_one( $att, $dry ) {
		$row  = array( 'id' => $att, 'action' => 'skipped', 'reason' => '', 'before_bytes' => 0, 'after_bytes' => 0, 'before_total' => 0, 'after_total' => 0 );
		$file = get_attached_file( $att );
		if ( ! $file || ! file_exists( $file ) ) {
			$row['action'] = 'error';
			$row['reason'] = 'Attached file is missing.';
			return $row;
		}
		$old                = self::attachment_files( $att );
		$row['before_bytes'] = (int) filesize( $file );
		$row['before_total'] = self::bytes( $old );
		$t                   = self::target();
		if ( get_post_mime_type( $att ) === $t['mime'] ) {
			$row['reason'] = 'Already ' . $t['mime'] . '.';
			return $row;
		}
		if ( $dry ) {
			$row['action'] = 'would_convert';
			return $row;
		}
		// Safety: reencode() verifies every new file (exists, > 1 KB, readable, width >= 300, right mime) and deletes
		// only the NEW file when it fails; the original file and its metadata are untouched until this passes.
		$why = '';
		$enc = self::reencode( $file, dirname( $file ), pathinfo( $file, PATHINFO_FILENAME ), $why );
		if ( ! $enc ) {
			$row['action'] = false !== strpos( $why, 'verification:' ) ? 'failed_verification' : 'error';
			$row['reason'] = ( 'failed_verification' === $row['action'] ? 'Converted file failed verification, original kept: ' : 'Image editor could not re-encode the file, original kept: ' ) . $why;
			return $row;
		}
		if ( $enc['mime'] === get_post_mime_type( $att ) ) { // JPEG fallback of a JPEG: nothing gained, don't churn it.
			wp_delete_file( $enc['path'] );
			$row['reason'] = 'WebP failed verification and the file is already JPEG; left unchanged.';
			return $row;
		}
		// Only now (the new file is verified) touch the attachment: point it at the new file, rebuild every sub-size.
		$old_mime   = (string) get_post_mime_type( $att );
		$old_meta   = wp_get_attachment_metadata( $att );
		$old_backup = get_post_meta( $att, '_wp_attachment_backup_sizes', true );
		update_attached_file( $att, $enc['path'] );
		delete_post_meta( $att, '_wp_attachment_backup_sizes' );
		wp_update_post(
			array(
				'ID'             => $att,
				'post_mime_type' => $enc['mime'],
			)
		);
		$meta = wp_generate_attachment_metadata( $att, $enc['path'] );
		if ( ! is_array( $meta ) || empty( $meta ) || ( isset( $meta['width'] ) ? (int) $meta['width'] : 0 ) < self::MIN_STORED_WIDTH ) {
			// Roll back: the attachment keeps its old file, metadata and mime; only the new files go.
			update_attached_file( $att, $file );
			wp_update_post( array( 'ID' => $att, 'post_mime_type' => $old_mime ) );
			if ( is_array( $old_meta ) ) {
				wp_update_attachment_metadata( $att, $old_meta );
			}
			if ( is_array( $old_backup ) ) {
				update_post_meta( $att, '_wp_attachment_backup_sizes', $old_backup );
			}
			foreach ( is_array( $meta ) && ! empty( $meta['sizes'] ) ? $meta['sizes'] : array() as $sz ) {
				$p = dirname( $enc['path'] ) . '/' . ( isset( $sz['file'] ) ? $sz['file'] : '' );
				if ( ! empty( $sz['file'] ) && ! in_array( $p, $old, true ) ) {
					wp_delete_file( $p );
				}
			}
			wp_delete_file( $enc['path'] );
			$row['action'] = 'error';
			$row['reason'] = 'Could not generate metadata; left unchanged.';
			return $row;
		}
		wp_update_attachment_metadata( $att, $meta );
		$new = self::attachment_files( $att );
		foreach ( array_diff( $old, $new ) as $f ) {
			wp_delete_file( $f );
		}
		$row['action']      = 'converted';
		$row['after_bytes'] = (int) filesize( $enc['path'] );
		$row['after_total'] = self::bytes( $new );

		// Photo URLs changed: refresh caches for every venue that uses this attachment.
		$venues = get_posts(
			array(
				'post_type'      => Data_Model::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $att, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$terms = array();
		foreach ( $venues as $vid ) {
			clean_post_cache( $vid );
			$t_list = get_the_terms( $vid, Data_Model::CITY );
			foreach ( ( $t_list && ! is_wp_error( $t_list ) ) ? $t_list : array() as $term ) {
				$terms[] = (int) $term->term_id;
			}
		}
		Repository::purge_cities( $terms );
		return $row;
	}

	/* ---------------------------------------------------------------- POST /venues/images/repair */

	/**
	 * Rebuild broken venue photos in place (same attachment ID). Broken = carries _osn_source_image_url and the file
	 * is missing or 1 KB or less, metadata width is under 300, or the _osn_broken flag is set. Each is re-downloaded
	 * from its source URL (same checks as the sideload), re-encoded (verified WebP, JPEG fallback, then the verified
	 * original) into the uploads month dir, and venues that lost the photo are linked back. Never deletes an attachment.
	 * Repaired rows leave the broken set, so next_offset only counts rows that stayed (dry run, ok, unrecoverable).
	 */
	public static function repair( \WP_REST_Request $req ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$limit  = max( 1, min( 50, (int) $req['limit'] ) );
		$offset = max( 0, (int) $req['offset'] );
		$dry    = (bool) $req['dry_run'];
		$all    = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::URL_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare'     => 'EXISTS', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$broken = array();
		foreach ( $all as $att ) {
			if ( '' !== self::attachment_problem( (int) $att ) || '1' === (string) get_post_meta( $att, self::BROKEN_META, true ) ) {
				$broken[] = (int) $att;
			}
		}
		$page    = array_slice( $broken, $offset, $limit );
		$t0      = microtime( true );
		$budget  = (float) apply_filters( 'osn_image_time_budget', 60 );
		$counts  = array_fill_keys( array( 'repaired', 'would_repair', 'ok', 'unrecoverable' ), 0 );
		$results = array();
		$stayed  = 0;
		foreach ( $page as $att ) {
			if ( microtime( true ) - $t0 > $budget ) {
				$row = array( 'id' => $att, 'action' => 'unrecoverable', 'reason' => 'Deferred: request time budget reached; call again.', 'bytes' => 0, 'width' => 0, 'venues_relinked' => array() );
			} else {
				$row = self::repair_one( $att, $dry );
			}
			++$counts[ $row['action'] ];
			if ( 'repaired' !== $row['action'] ) {
				++$stayed;
			}
			$results[] = $row;
		}
		$more = $offset + count( $page ) < count( $broken );
		return rest_ensure_response(
			array(
				'dry_run'     => $dry,
				'broken'      => count( $broken ),
				'counts'      => $counts,
				'next_offset' => $more ? $offset + $stayed : null,
				'results'     => $results,
			)
		);
	}

	/** Venue IDs (not trashed) that should show this attachment: current thumbnail, source-URL credit, or parent. */
	private static function venues_for_attachment( $att, $url ) {
		$base = array(
			'post_type'      => Data_Model::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'fields'         => 'ids',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		);
		$cur  = get_posts( $base + array( 'meta_key' => '_thumbnail_id', 'meta_value' => $att ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$orph = array();
		if ( '' !== $url ) {
			$orph = get_posts( $base + array( 'meta_key' => Fields::key( 'photo_source_url' ), 'meta_value' => $url ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$parent = (int) wp_get_post_parent_id( $att );
		if ( $parent && Data_Model::POST_TYPE === get_post_type( $parent ) && 'trash' !== get_post_status( $parent ) ) {
			$orph[] = $parent;
		}
		return array( array_map( 'intval', $cur ), array_values( array_unique( array_map( 'intval', $orph ) ) ) );
	}

	private static function repair_row( $att, $action, $reason = '', $bytes = 0, $w = 0, $relinked = array() ) {
		return array(
			'id'              => $att,
			'action'          => $action,
			'reason'          => $reason,
			'bytes'           => $bytes,
			'width'           => $w,
			'venues_relinked' => $relinked,
		);
	}

	/** Point venues that have no photo (thumbnail 0 or dangling) at this attachment; purge their cities' caches. */
	private static function relink( $att, $url, $dry ) {
		list( $cur, $orph ) = self::venues_for_attachment( $att, $url );
		$relinked = array();
		$terms    = array();
		foreach ( $orph as $vid ) {
			if ( ! Util::thumb_id( $vid ) ) {
				$relinked[] = $vid;
			}
		}
		if ( $dry ) {
			return $relinked;
		}
		foreach ( $relinked as $vid ) {
			update_post_meta( $vid, '_thumbnail_id', $att );
		}
		foreach ( array_unique( array_merge( $cur, $relinked ) ) as $vid ) {
			clean_post_cache( $vid );
			$t_list = get_the_terms( $vid, Data_Model::CITY );
			foreach ( ( $t_list && ! is_wp_error( $t_list ) ) ? $t_list : array() as $term ) {
				$terms[] = (int) $term->term_id;
			}
		}
		Repository::purge_cities( $terms );
		return $relinked;
	}

	private static function repair_one( $att, $dry ) {
		$url  = (string) get_post_meta( $att, self::URL_META, true );
		$prob = self::attachment_problem( $att );
		if ( '' === $prob ) { // Flag only: the file is fine, so clear the flag and make sure venues are linked.
			if ( ! $dry ) {
				delete_post_meta( $att, self::BROKEN_META );
			}
			$file = get_attached_file( $att );
			$meta = wp_get_attachment_metadata( $att );
			return self::repair_row( $att, 'ok', $dry ? 'dry_run: flagged but file is healthy.' : 'Flagged but file is healthy; flag cleared.', (int) filesize( $file ), (int) $meta['width'], self::relink( $att, $url, $dry ) );
		}
		if ( $dry ) {
			return self::repair_row( $att, 'would_repair', 'dry_run: ' . $prob . '; would re-download ' . $url, 0, 0, self::relink( $att, $url, true ) );
		}
		update_post_meta( $att, self::BROKEN_META, '1' );
		$fail = static function ( $reason ) use ( $att ) {
			return self::repair_row( $att, 'unrecoverable', $reason );
		};

		// Same URL checks as the sideload: public http(s) host, no IPv6 literal, wp_http_validate_url.
		$u    = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		$host = (string) wp_parse_url( $u, PHP_URL_HOST );
		if ( '' === $u || '' === $host || false !== strpos( $host, ':' ) || ! wp_http_validate_url( $u ) ) {
			return $fail( 'Source URL is missing or not a public http(s) URL.' );
		}
		$cap = static function ( $args, $x ) use ( $u ) {
			if ( $x === $u ) {
				$args['limit_response_size'] = self::MAX_BYTES + 1;
			}
			return $args;
		};
		add_filter( 'http_request_args', $cap, 10, 2 );
		$tmp = download_url( $u, self::TIMEOUT );
		remove_filter( 'http_request_args', $cap, 10 );
		if ( is_wp_error( $tmp ) ) {
			return $fail( 'Download failed: ' . $tmp->get_error_message() );
		}
		if ( filesize( $tmp ) > self::MAX_BYTES ) {
			wp_delete_file( $tmp );
			return $fail( 'Image is larger than 8MB.' );
		}
		$info = wp_getimagesize( $tmp );
		$mime = is_array( $info ) && isset( $info['mime'] ) ? (string) $info['mime'] : '';
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) || ! isset( self::MIMES[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return $fail( 'Download is not a readable jpeg, png or webp image.' );
		}
		if ( (int) $info[0] < self::MIN_STORED_WIDTH ) {
			wp_delete_file( $tmp );
			return $fail( sprintf( 'Downloaded image is only %d px wide.', (int) $info[0] ) );
		}
		$ext  = self::MIMES[ $mime ];
		$file = $tmp . '.' . $ext;
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$file = $tmp;
		}

		// Encode straight into the current uploads month dir.
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			wp_delete_file( $file );
			return $fail( 'Uploads dir not writable: ' . $up['error'] );
		}
		$parent = (int) wp_get_post_parent_id( $att );
		$title  = $parent ? get_the_title( $parent ) : get_the_title( $att );
		$slug   = sanitize_title( $title );
		$name   = '' !== $slug ? $slug : 'venue-photo-' . $att;
		$why    = '';
		$enc    = self::reencode( $file, $up['path'], $name, $why );
		if ( $enc ) {
			wp_delete_file( $file );
			$new = $enc['path'];
			$nm  = $enc['mime'];
		} else { // Fall back to the downloaded original, verified.
			$ok = self::verify_file( $file, $mime );
			if ( true !== $ok ) {
				wp_delete_file( $file );
				return $fail( 'Re-encode failed (' . $why . ') and the download is unusable (' . $ok . ').' );
			}
			$new = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], $name . '.' . $ext );
			$moved = @rename( $file, $new ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $moved ) {
				$moved = @copy( $file, $new ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				wp_delete_file( $file );
			}
			if ( ! $moved ) {
				return $fail( 'Could not move the download into the uploads folder.' );
			}
			$nm = $mime;
		}

		// Write into the SAME attachment ID.
		$old        = self::attachment_files( $att );
		$old_mime   = (string) get_post_mime_type( $att );
		$old_file   = get_attached_file( $att, true );
		$old_meta   = wp_get_attachment_metadata( $att );
		$old_backup = get_post_meta( $att, '_wp_attachment_backup_sizes', true );
		update_attached_file( $att, $new );
		delete_post_meta( $att, '_wp_attachment_backup_sizes' );
		wp_update_post( array( 'ID' => $att, 'post_mime_type' => $nm ) );
		$meta = wp_generate_attachment_metadata( $att, $new );
		if ( ! is_array( $meta ) || empty( $meta ) || (int) ( isset( $meta['width'] ) ? $meta['width'] : 0 ) < self::MIN_STORED_WIDTH ) {
			if ( $old_file ) {
				update_attached_file( $att, $old_file );
			}
			wp_update_post( array( 'ID' => $att, 'post_mime_type' => $old_mime ) );
			if ( is_array( $old_meta ) ) {
				wp_update_attachment_metadata( $att, $old_meta );
			}
			if ( is_array( $old_backup ) ) {
				update_post_meta( $att, '_wp_attachment_backup_sizes', $old_backup );
			}
			wp_delete_file( $new );
			return $fail( 'Could not generate metadata for the new file; attachment left as it was.' );
		}
		wp_update_attachment_metadata( $att, $meta );
		delete_post_meta( $att, self::BROKEN_META );
		$now = self::attachment_files( $att );
		foreach ( array_diff( $old, $now ) as $f ) {
			if ( file_exists( $f ) ) { // Stale broken files of this attachment only.
				wp_delete_file( $f );
			}
		}
		$relinked = self::relink( $att, $url, false );
		return self::repair_row( $att, 'repaired', '', (int) filesize( $new ), (int) $meta['width'], $relinked );
	}

	/**
	 * Attachment sideloaded earlier from exactly this URL, or 0. Attachments whose file is missing, 1 KB or less, or
	 * whose metadata width is under 300 (or that carry the flag) are never reused; they get the _osn_broken flag
	 * (when $mark) so the repair endpoint finds them, and a fresh image is sideloaded instead.
	 */
	private static function attachment_for_url( $url, $mark = true ) {
		$q = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 20,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::URL_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $q as $att ) {
			if ( '' !== self::attachment_problem( (int) $att ) || '1' === (string) get_post_meta( $att, self::BROKEN_META, true ) ) {
				if ( $mark ) {
					update_post_meta( $att, self::BROKEN_META, '1' );
				}
				continue;
			}
			return (int) $att;
		}
		return 0;
	}

	/** Thumbnail + credit meta + cache purge. Plain post meta writes, so post_modified is not bumped. */
	private static function apply( $id, $att, $url, array $raw, $title ) {
		update_post_meta( $id, '_thumbnail_id', $att );
		update_post_meta( $id, 'hide_post_image', 'yes' ); // Themify: the plugin prints its own hero.
		$credit_url = isset( $raw['credit_url'] ) && is_string( $raw['credit_url'] ) ? esc_url_raw( trim( $raw['credit_url'] ), array( 'http', 'https' ) ) : '';
		if ( '' === $credit_url ) {
			$credit_url = (string) Util::meta( $id, 'website' );
		}
		$credit_text = isset( $raw['credit_text'] ) && is_string( $raw['credit_text'] ) ? sanitize_text_field( $raw['credit_text'] ) : '';
		if ( '' === $credit_text ) {
			/* translators: %s: venue name */
			$credit_text = sprintf( __( "Photo: %s's website", 'dos-outdoor-seating' ), $title );
		}
		update_post_meta( $id, Fields::key( 'photo_credit_url' ), $credit_url );
		update_post_meta( $id, Fields::key( 'photo_credit_text' ), $credit_text );
		update_post_meta( $id, Fields::key( 'photo_source_url' ), $url );
		// Card grids sort photos first and the state pages show them: refresh caches for the venue's cities.
		$terms = get_the_terms( $id, Data_Model::CITY );
		$ids   = array();
		foreach ( ( $terms && ! is_wp_error( $terms ) ) ? $terms : array() as $t ) {
			$ids[] = $t->term_id;
		}
		Repository::purge_cities( $ids );
		clean_post_cache( $id );
	}
}
