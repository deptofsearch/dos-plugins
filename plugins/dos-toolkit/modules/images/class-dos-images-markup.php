<?php
/**
 * Front-end image markup rewriting.
 *
 * Page builders routinely render a bare <img src> pointing at whatever size
 * the module was configured with — in practice the full file, so a 2560px
 * original lands in a ~350px slot with no srcset offered at all.
 *
 * This rewrites the markup on the way out: resolve each <img> back to its
 * attachment, swap the src for the smallest registered size that still covers
 * the slot, and attach the srcset and sizes WordPress would have produced on
 * its own.
 *
 * It never touches an image file. Byte-level work — compression, WebP, Accept
 * negotiation — stays with the host's optimizer, which does it at a layer PHP
 * cannot reach.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Images_Markup {

	/** Slot width in CSS pixels that archive thumbnails typically render into. */
	const DEFAULT_SLOT = 350;

	/** Retina multiplier applied to the slot width when choosing a size. */
	const DENSITY = 2;

	/** How many rewrites a page render records before it stops collecting. */
	const LOG_LIMIT = 60;

	const LOG_KEY = 'dos_images_last_page';

	private static $log     = array();
	private static $scanned = 0;
	private static $changed = 0;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
	}

	/**
	 * Rewriting output on a live site is opt-in: unset means dry run.
	 */
	public static function is_dry_run() {
		$value = DOS_Settings::get( 'images_markup_dry_run', null );

		return null === $value ? true : (bool) $value;
	}

	public static function is_enabled() {
		return (bool) DOS_Settings::get( 'images_markup_enabled', 0 );
	}

	public static function slot_width() {
		$slot = (int) DOS_Settings::get( 'images_slot_width', self::DEFAULT_SLOT );

		return $slot > 0 ? $slot : self::DEFAULT_SLOT;
	}

	public static function start_buffer() {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( is_admin() || is_feed() || is_embed() || is_preview() ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Escape hatch: append ?dos_no_rewrite=1 to see the untouched page.
		if ( ! empty( $_GET['dos_no_rewrite'] ) ) {
			return;
		}

		ob_start( array( __CLASS__, 'rewrite' ) );
	}

	/**
	 * Runs once per page render. On a cached site that is once per cache
	 * fill, not once per visitor.
	 */
	public static function rewrite( $html ) {
		// Only touch full HTML documents. Anything else passes through intact.
		if ( '' === trim( $html ) || false === stripos( $html, '<img' ) || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		self::$log     = array();
		self::$scanned = 0;
		self::$changed = 0;

		$out = preg_replace_callback( '/<img\s[^>]*>/i', array( __CLASS__, 'rewrite_tag' ), $html );

		// A preg failure (backtrack limit on a huge page) returns null. Serve
		// the original rather than a blank page.
		if ( null === $out ) {
			return $html;
		}

		if ( self::$log ) {
			set_transient(
				self::LOG_KEY,
				array(
					'time'    => time(),
					'url'     => home_url( add_query_arg( array() ) ),
					'scanned' => self::$scanned,
					'changed' => self::$changed,
					'dry_run' => self::is_dry_run(),
					'rows'    => self::$log,
				),
				DAY_IN_SECONDS
			);
		}

		return self::is_dry_run() ? $html : $out;
	}

	private static function rewrite_tag( $matches ) {
		$tag = $matches[0];

		self::$scanned++;

		// Already responsive, or a data: placeholder the theme swaps in.
		if ( false !== stripos( $tag, 'srcset=' ) ) {
			return $tag;
		}

		if ( ! preg_match( '/\ssrc=(["\'])(.*?)\1/i', $tag, $m ) ) {
			return $tag;
		}

		$src = $m[2];

		if ( 0 === stripos( $src, 'data:' ) ) {
			return $tag;
		}

		$attachment_id = self::attachment_id_for_url( $src );

		if ( ! $attachment_id ) {
			return $tag;
		}

		$target = self::target_width( $tag );
		$size   = self::best_size( $attachment_id, $target );

		if ( ! $size ) {
			return $tag;
		}

		$new_src = $size['url'];
		$srcset  = wp_get_attachment_image_srcset( $attachment_id, $size['name'] );
		$sizes   = wp_get_attachment_image_sizes( $attachment_id, $size['name'] );

		if ( ! $srcset && $new_src === $src ) {
			return $tag; // Nothing to add and nothing to swap.
		}

		$new = $tag;

		if ( $new_src !== $src ) {
			$new = preg_replace( '/(\ssrc=)(["\'])(.*?)\2/i', '$1$2' . self::escape_replacement( $new_src ) . '$2', $new, 1 );
		}

		$add = '';

		if ( $srcset ) {
			$add .= ' srcset="' . esc_attr( $srcset ) . '"';

			if ( $sizes && false === stripos( $new, ' sizes=' ) ) {
				$add .= ' sizes="' . esc_attr( $sizes ) . '"';
			}
		}

		if ( false === stripos( $new, ' decoding=' ) ) {
			$add .= ' decoding="async"';
		}

		if ( $add ) {
			$new = preg_replace( '/\s*\/?>$/', $add . '>', $new, 1 );
		}

		self::$changed++;

		if ( count( self::$log ) < self::LOG_LIMIT ) {
			self::$log[] = array(
				'from'   => wp_basename( $src ),
				'to'     => wp_basename( $new_src ),
				'size'   => $size['name'],
				'target' => $target,
				'srcset' => $srcset ? substr_count( $srcset, ',' ) + 1 : 0,
			);
		}

		return $new;
	}

	/**
	 * Resolve an uploaded file URL back to its attachment, including sized
	 * variants (-1024x768) and WordPress's big-image -scaled files.
	 */
	private static function attachment_id_for_url( $url ) {
		$uploads = wp_get_upload_dir();

		if ( false === strpos( $url, $uploads['baseurl'] ) ) {
			return 0;
		}

		// IMG_2261-1024x768.jpeg and IMG_2261.jpeg are the same attachment.
		$base = preg_replace( '/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $url );

		$cache_key = 'dos_img_' . md5( $base );
		$cached    = wp_cache_get( $cache_key, 'dos_toolkit' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$id = attachment_url_to_postid( $base );

		if ( ! $id && $base !== $url ) {
			$id = attachment_url_to_postid( $url );
		}

		wp_cache_set( $cache_key, (int) $id, 'dos_toolkit', HOUR_IN_SECONDS );

		return (int) $id;
	}

	/**
	 * The width actually needed: an explicit width attribute if the theme
	 * wrote one, otherwise the configured slot width at retina density.
	 */
	private static function target_width( $tag ) {
		if ( preg_match( '/\swidth=(["\'])(\d+)\1/i', $tag, $m ) ) {
			$declared = (int) $m[2];

			if ( $declared > 0 ) {
				return $declared * self::DENSITY;
			}
		}

		return self::slot_width() * self::DENSITY;
	}

	/**
	 * Smallest registered size that still covers the target width. Falls back
	 * to the largest available when every size is too small.
	 */
	private static function best_size( $attachment_id, $target ) {
		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return null;
		}

		$candidates = array();

		foreach ( $meta['sizes'] as $name => $data ) {
			if ( empty( $data['width'] ) ) {
				continue;
			}

			$candidates[ $name ] = (int) $data['width'];
		}

		if ( ! empty( $meta['width'] ) ) {
			$candidates['full'] = (int) $meta['width'];
		}

		if ( ! $candidates ) {
			return null;
		}

		asort( $candidates );

		$chosen = null;

		foreach ( $candidates as $name => $width ) {
			if ( $width >= $target ) {
				$chosen = $name;
				break;
			}
		}

		if ( null === $chosen ) {
			end( $candidates );
			$chosen = key( $candidates );
		}

		$src = wp_get_attachment_image_src( $attachment_id, $chosen );

		if ( ! $src ) {
			return null;
		}

		return array(
			'name'  => $chosen,
			'url'   => $src[0],
			'width' => (int) $src[1],
		);
	}

	/** $1/$2 in a replacement string would be read as backreferences. */
	private static function escape_replacement( $value ) {
		return str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $value );
	}

	public static function last_page_log() {
		return get_transient( self::LOG_KEY );
	}
}
