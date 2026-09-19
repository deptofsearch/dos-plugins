<?php
/**
 * Media usage: which attachments are referenced anywhere, and deletion of the
 * ones that are not.
 *
 * Ported from Media Usage Manager. The detection logic is carried over intact,
 * because it is the part that took the longest to get right: an image counts
 * as used if it is a featured image, carries a wp-image-N class, appears in a
 * gallery shortcode, is referenced by ID in block or builder JSON, or its
 * upload path appears anywhere in post content — at any size, on any domain.
 *
 * Deletion runs as a destructive DOS_Batch job, so it inherits the typed
 * confirmation and the dry-run-first requirement.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Images_Usage {

	const META_STATUS  = '_dos_usage_status';
	const META_USED_IN = '_dos_usage_used_in';

	/* ---------------------------------------------------------------------
	 * What the scan walks
	 * ------------------------------------------------------------------- */

	public static function scannable_post_types() {
		$public = get_post_types( array( 'public' => true ), 'names' );
		$ui     = get_post_types( array( 'show_ui' => true ), 'names' );
		$types  = array_merge( array( 'post', 'page' ), $public, $ui );

		$excluded = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block' );

		return apply_filters( 'dos_toolkit_scannable_post_types', array_values( array_diff( array_unique( $types ), $excluded ) ) );
	}

	public static function count_attachments() {
		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		return (int) $q->found_posts;
	}

	public static function count_posts() {
		$q = new WP_Query( array(
			'post_type'      => self::scannable_post_types(),
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		return (int) $q->found_posts;
	}

	public static function count_unused() {
		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::META_STATUS, 'value' => 'unused' ) ),
		) );

		return (int) $q->found_posts;
	}

	/* ---------------------------------------------------------------------
	 * The scan, as one offset space
	 * ------------------------------------------------------------------- */

	/**
	 * Phase one resets every attachment to "unused"; phase two walks post
	 * content and marks the ones it finds. Expressing both as a single offset
	 * range lets the shared batch runner drive it without knowing about
	 * phases: offsets below the attachment count are phase one, the rest are
	 * phase two.
	 */
	public static function scan_total() {
		return self::count_attachments() + self::count_posts();
	}

	public static function scan_step( $offset, $size, $dry_run ) {
		$attachments = self::count_attachments();
		$notes       = array();

		if ( $offset < $attachments ) {
			if ( $dry_run ) {
				// Nothing to report from the reset phase, and it must not
				// write. Skip straight through it.
				return array( 'processed' => $size, 'changed' => 0, 'notes' => array() );
			}

			$ids = get_posts( array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'fields'                 => 'ids',
				'posts_per_page'         => $size,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );

			foreach ( $ids as $attachment_id ) {
				update_post_meta( $attachment_id, self::META_STATUS, 'unused' );
				update_post_meta( $attachment_id, self::META_USED_IN, array() );
			}

			return array( 'processed' => count( $ids ) ?: $size, 'changed' => 0, 'notes' => array() );
		}

		$posts = get_posts( array(
			'post_type'              => self::scannable_post_types(),
			'post_status'            => 'any',
			'posts_per_page'         => $size,
			'offset'                 => $offset - $attachments,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		$marked = 0;

		foreach ( $posts as $post ) {
			$featured = (int) get_post_thumbnail_id( $post->ID );

			if ( $featured ) {
				$marked += self::mark_used( $featured, $post->ID, 'featured', $dry_run ) ? 1 : 0;
			}

			foreach ( self::attachment_ids_in_content( $post->post_content ) as $attachment_id ) {
				$marked += self::mark_used( $attachment_id, $post->ID, 'content', $dry_run ) ? 1 : 0;
			}
		}

		if ( $dry_run && $marked ) {
			$notes[] = sprintf(
				/* translators: 1: number of references, 2: number of posts */
				__( '%1$d image references found across %2$d posts.', 'dos-toolkit' ),
				$marked,
				count( $posts )
			);
		}

		return array( 'processed' => count( $posts ), 'changed' => $marked, 'notes' => $notes );
	}

	private static function mark_used( $attachment_id, $post_id, $type, $dry_run = false ) {
		$attachment_id = absint( $attachment_id );
		$post_id       = absint( $post_id );

		if ( ! $attachment_id || ! $post_id ) {
			return false;
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		if ( 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
			return false;
		}

		if ( $dry_run ) {
			return true;
		}

		$used_in = get_post_meta( $attachment_id, self::META_USED_IN, true );
		$used_in = is_array( $used_in ) ? $used_in : array();

		$map = array();

		foreach ( $used_in as $usage ) {
			if ( empty( $usage['post_id'] ) ) {
				continue;
			}

			$usage_type = ! empty( $usage['type'] ) ? sanitize_key( $usage['type'] ) : 'content';

			$map[ $usage_type . '_' . absint( $usage['post_id'] ) ] = array(
				'post_id' => absint( $usage['post_id'] ),
				'type'    => $usage_type,
			);
		}

		$map[ sanitize_key( $type ) . '_' . $post_id ] = array(
			'post_id' => $post_id,
			'type'    => sanitize_key( $type ),
		);

		update_post_meta( $attachment_id, self::META_STATUS, 'used' );
		update_post_meta( $attachment_id, self::META_USED_IN, array_values( $map ) );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Detection
	 * ------------------------------------------------------------------- */

	public static function attachment_ids_in_content( $content ) {
		$found = array();

		if ( empty( $content ) ) {
			return $found;
		}

		$text = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$text = str_replace( '\\/', '/', $text );
		$text = rawurldecode( $text );

		// The editor's own marker.
		if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$found[] = absint( $id );
			}
		}

		// [gallery ids="1,2,3"]
		if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\'][^\]]*\]/', $text, $m ) ) {
			foreach ( $m[1] as $ids ) {
				foreach ( explode( ',', $ids ) as $id ) {
					$found[] = absint( trim( $id ) );
				}
			}
		}

		// Block attributes and page-builder JSON.
		if ( preg_match_all( '/"(?:id|mediaId)"\s*:\s*(\d+)/', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$found[] = absint( $id );
			}
		}

		// Anything referenced only by URL.
		foreach ( self::upload_paths_in_content( $text ) as $path ) {
			foreach ( self::attachment_ids_by_path( $path ) as $id ) {
				$found[] = absint( $id );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $found ) ) ) );
	}

	private static function upload_paths_in_content( $content ) {
		$paths     = array();
		$uploads   = wp_get_upload_dir();
		$base_path = '';

		if ( ! empty( $uploads['baseurl'] ) ) {
			$base_path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
			$base_path = $base_path ? '/' . trim( $base_path, '/' ) : '';
		}

		if ( preg_match_all( '/["\']([^"\']+\.(?:jpe?g|png|gif|webp|avif|svg)(?:\?[^"\']*)?)["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $url ) {
				$path = wp_parse_url( $url, PHP_URL_PATH );
				$path = $path ? $path : $url;

				$relative = self::strip_upload_base( $path );

				if ( ( $relative && $relative !== ltrim( $path, '/' ) ) || ( $base_path && 0 === strpos( '/' . ltrim( $path, '/' ), $base_path . '/' ) ) ) {
					$paths[] = $relative;
				}
			}
		}

		if ( preg_match_all( '/\/wp-content\/uploads\/([^\s"\'<>\)]+\.(?:jpe?g|png|gif|webp|avif|svg))(?:\?[^\s"\'<>\)]*)?/i', $content, $m ) ) {
			foreach ( $m[1] as $relative ) {
				$paths[] = $relative;
			}
		}

		$paths = array_map( array( __CLASS__, 'normalize_token' ), $paths );
		$paths = array_map(
			function ( $path ) {
				return ltrim( strtok( $path, '?' ), '/' );
			},
			$paths
		);

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	private static function attachment_ids_by_path( $upload_path ) {
		global $wpdb;

		static $cache = array();

		$upload_path = ltrim( self::normalize_token( $upload_path ), '/' );

		if ( '' === $upload_path ) {
			return array();
		}

		if ( isset( $cache[ $upload_path ] ) ) {
			return $cache[ $upload_path ];
		}

		$variants = array_values( array_unique( array_filter( array(
			$upload_path,
			self::strip_size_suffix( $upload_path ),
		) ) ) );

		$ids = array();

		foreach ( $variants as $variant ) {
			$exact = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 20",
					$variant
				)
			);

			foreach ( $exact as $id ) {
				$ids[] = absint( $id );
			}
		}

		// Last resort: the file may be referenced at a size whose path is
		// recorded only inside the serialised attachment metadata.
		if ( empty( $ids ) ) {
			$basename = wp_basename( $upload_path );

			if ( $basename ) {
				$like = '%' . $wpdb->esc_like( $basename ) . '%';

				$meta_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s LIMIT 20",
						$like
					)
				);

				foreach ( $meta_ids as $id ) {
					$ids[] = absint( $id );
				}
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		$cache[ $upload_path ] = $ids;

		return $ids;
	}

	private static function strip_upload_base( $path ) {
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['baseurl'] ) ) {
			return ltrim( (string) $path, '/' );
		}

		$base = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		$base = $base ? '/' . trim( $base, '/' ) . '/' : '';
		$path = '/' . ltrim( (string) $path, '/' );

		if ( $base && 0 === strpos( $path, $base ) ) {
			return substr( $path, strlen( $base ) );
		}

		return ltrim( $path, '/' );
	}

	private static function strip_size_suffix( $path ) {
		return preg_replace( '/-\d+x\d+(?=\.(?:jpe?g|png|gif|webp|avif|svg)$)/i', '', $path );
	}

	private static function normalize_token( $token ) {
		return trim( str_replace( '\\', '/', (string) $token ) );
	}

	/* ---------------------------------------------------------------------
	 * Deletion
	 * ------------------------------------------------------------------- */

	/**
	 * Attachments that must survive a cleanup regardless of what the scan
	 * concluded. The scan reads post content; none of these live there, so
	 * without this list a site logo is exactly the kind of thing that looks
	 * unused and is not.
	 */
	public static function protected_ids() {
		$ids = array();

		$logo = (int) get_theme_mod( 'custom_logo' );

		if ( $logo ) {
			$ids[] = $logo;
		}

		$icon = (int) get_option( 'site_icon' );

		if ( $icon ) {
			$ids[] = $icon;
		}

		$share = (int) DOS_Settings::get( 'seo_fallback_image', 0 );

		if ( $share ) {
			$ids[] = $share;
		}

		$header = get_theme_mod( 'header_image_data' );

		if ( is_object( $header ) && ! empty( $header->attachment_id ) ) {
			$ids[] = (int) $header->attachment_id;
		}

		return array_values( array_unique( array_filter( apply_filters( 'dos_toolkit_protected_attachments', $ids ) ) ) );
	}

	public static function delete_step( $offset, $size, $dry_run ) {
		// A live pass removes rows from the very set it is paging through, so
		// it always takes the first page. A dry run changes nothing, so it
		// must advance by offset or it would loop over the same images.
		$ids = get_posts( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'fields'                 => 'ids',
			'posts_per_page'         => $size,
			'offset'                 => $dry_run ? $offset : 0,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( array( 'key' => self::META_STATUS, 'value' => 'unused' ) ),
		) );

		$protected = self::protected_ids();
		$notes     = array();
		$changed   = 0;

		foreach ( $ids as $attachment_id ) {
			$name = wp_basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );

			if ( in_array( (int) $attachment_id, $protected, true ) ) {
				$notes[] = sprintf(
					/* translators: %s: image file name */
					__( 'Kept %s — in use as a site logo, icon or share image.', 'dos-toolkit' ),
					$name
				);

				// A protected image would be returned again on the next live
				// page, stalling the run. Record it as used so the query
				// stops offering it.
				if ( ! $dry_run ) {
					update_post_meta( $attachment_id, self::META_STATUS, 'used' );
				}

				continue;
			}

			if ( $dry_run ) {
				$notes[] = sprintf(
					/* translators: %s: image file name */
					__( 'Would delete %s', 'dos-toolkit' ),
					$name
				);

				$changed++;

				continue;
			}

			if ( wp_delete_attachment( $attachment_id, true ) ) {
				DOS_Log::add( 'images', 'attachment_deleted', $name, $attachment_id );

				$changed++;
			}
		}

		return array( 'processed' => count( $ids ), 'changed' => $changed, 'notes' => $notes );
	}

	/* ---------------------------------------------------------------------
	 * Alt text
	 * ------------------------------------------------------------------- */

	public static function alt_audit_step( $offset, $size, $dry_run ) {
		$ids = get_posts( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'fields'                 => 'ids',
			'posts_per_page'         => $size,
			'offset'                 => $offset,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		) );

		$notes   = array();
		$changed = 0;

		foreach ( $ids as $attachment_id ) {
			$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

			if ( '' !== $alt ) {
				continue;
			}

			$changed++;

			if ( count( $notes ) < 50 ) {
				$notes[] = sprintf(
					/* translators: 1: attachment ID, 2: image file name */
					__( 'No alt text: #%1$d %2$s', 'dos-toolkit' ),
					$attachment_id,
					wp_basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) )
				);
			}
		}

		return array( 'processed' => count( $ids ), 'changed' => $changed, 'notes' => $notes );
	}

	/* ---------------------------------------------------------------------
	 * Titles
	 * ------------------------------------------------------------------- */

	/**
	 * Clearing the Title field stops WordPress echoing a filename-derived
	 * title into markup. It cannot be undone, so it runs as a destructive job.
	 */
	public static function clear_titles_step( $offset, $size, $dry_run ) {
		$ids = get_posts( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'fields'                 => 'ids',
			'posts_per_page'         => $size,
			'offset'                 => $offset,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		) );

		$notes   = array();
		$changed = 0;

		foreach ( $ids as $attachment_id ) {
			$title = get_the_title( $attachment_id );

			if ( '' === trim( (string) $title ) ) {
				continue;
			}

			$changed++;

			if ( count( $notes ) < 50 ) {
				$notes[] = $dry_run
					/* translators: %s: current attachment title */
					? sprintf( __( 'Would clear title: %s', 'dos-toolkit' ), $title )
					/* translators: %s: cleared attachment title */
					: sprintf( __( 'Cleared title: %s', 'dos-toolkit' ), $title );
			}

			if ( ! $dry_run ) {
				wp_update_post( array( 'ID' => $attachment_id, 'post_title' => '' ) );

				DOS_Log::add( 'images', 'title_cleared', $title, $attachment_id );
			}
		}

		return array( 'processed' => count( $ids ), 'changed' => $changed, 'notes' => $notes );
	}
}
