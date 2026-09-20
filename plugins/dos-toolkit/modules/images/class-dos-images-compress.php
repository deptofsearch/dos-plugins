<?php
/**
 * Image compression, aimed at page weight rather than at a number.
 *
 * The quality setting is the smaller of the two levers. An oversized file
 * served into a small slot costs far more than the difference between quality
 * 82 and 75, which is why the audit ranks oversized images above over-quality
 * ones. The quality finder exists so the number that does get chosen is
 * chosen against measurements of the site's own photographs rather than
 * against advice from somewhere else.
 *
 * This host has GD and no ImageMagick. GD decompresses a whole image into raw
 * pixels — roughly width x height x 4 bytes, and two buffers are live at once
 * during a re-encode — so every path here estimates that first and declines
 * rather than discovering the limit by writing a truncated file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Images_Compress {

	const DEFAULT_QUALITY   = 82;
	const DEFAULT_THRESHOLD = 2560;

	/** Qualities the finder measures. */
	const SAMPLE_QUALITIES = array( 90, 85, 82, 78, 75, 70 );

	/** Bytes per pixel above which a JPEG is probably carrying more quality than it shows. */
	const HEAVY_BPP = 0.30;

	public static function quality() {
		$value = DOS_Settings::get( 'images_quality', null );

		return null === $value ? self::DEFAULT_QUALITY : max( 40, min( 100, (int) $value ) );
	}

	public static function threshold() {
		$value = DOS_Settings::get( 'images_threshold', null );

		return null === $value ? self::DEFAULT_THRESHOLD : max( 0, (int) $value );
	}

	public static function compress_uploads() {
		return (bool) DOS_Settings::get( 'images_compress_uploads', 0 );
	}

	public static function init() {
		add_filter( 'jpeg_quality', array( __CLASS__, 'filter_quality' ), 10, 2 );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_quality' ), 10, 2 );
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'filter_threshold' ) );

		if ( self::compress_uploads() ) {
			add_filter( 'wp_handle_upload', array( __CLASS__, 'on_upload' ), 10, 2 );
		}
	}

	public static function filter_quality( $quality, $context = '' ) {
		return self::quality();
	}

	public static function filter_threshold( $threshold ) {
		$ours = self::threshold();

		// 0 means "leave WordPress alone" rather than "never scale".
		return $ours ? $ours : $threshold;
	}

	/* ---------------------------------------------------------------------
	 * Memory
	 * ------------------------------------------------------------------- */

	/**
	 * Bytes GD will want for an image of this size.
	 *
	 * Four bytes a pixel for the decoded bitmap, and a re-encode holds a
	 * source and a destination at once. The margin covers GD's own overhead,
	 * which is not small.
	 */
	public static function memory_needed( $width, $height ) {
		return (int) ( $width * $height * 4 * 2.2 );
	}

	public static function memory_available() {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit < 1 ) {
			return PHP_INT_MAX; // No limit set.
		}

		return max( 0, $limit - memory_get_usage( true ) );
	}

	/**
	 * Whether this image can be opened without risking the process.
	 *
	 * @return true|string True, or why not.
	 */
	public static function can_process( $width, $height ) {
		$needed = self::memory_needed( $width, $height );

		// Leave a margin: something else has to run after this.
		if ( $needed > ( self::memory_available() * 0.7 ) ) {
			return sprintf(
				/* translators: 1: megabytes needed, 2: megabytes available */
				__( 'needs about %1$dMB and only %2$dMB is available', 'dos-toolkit' ),
				(int) round( $needed / MB_IN_BYTES ),
				(int) round( self::memory_available() / MB_IN_BYTES )
			);
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Re-encoding
	 * ------------------------------------------------------------------- */

	public static function compressible( $mime ) {
		return in_array( $mime, array( 'image/jpeg', 'image/png' ), true );
	}

	/**
	 * Re-encode a file at a given quality, keeping the result only if it is
	 * actually smaller.
	 *
	 * Written to a temporary file and moved into place, because a half-written
	 * image is a corrupt image rather than a failed job.
	 *
	 * @return array|WP_Error before, after, saved
	 */
	public static function compress_file( $path, $mime, $quality = 0 ) {
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'dos_missing', __( 'File not found.', 'dos-toolkit' ) );
		}

		if ( ! self::compressible( $mime ) ) {
			return new WP_Error( 'dos_type', __( 'Only JPEG and PNG are re-encoded.', 'dos-toolkit' ) );
		}

		$size = @getimagesize( $path );

		if ( ! $size ) {
			return new WP_Error( 'dos_unreadable', __( 'Not a readable image.', 'dos-toolkit' ) );
		}

		$can = self::can_process( $size[0], $size[1] );

		if ( true !== $can ) {
			return new WP_Error( 'dos_memory', $can );
		}

		wp_raise_memory_limit( 'image' );

		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor->set_quality( $quality ? max( 40, min( 100, (int) $quality ) ) : self::quality() );

		$temp  = $path . '.dos-tmp';
		$saved = $editor->save( $temp, $mime );

		if ( is_wp_error( $saved ) ) {
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}

			return $saved;
		}

		$before = (int) filesize( $path );
		$after  = (int) filesize( $saved['path'] );

		// A re-encode that grows the file is a worse file for no reason.
		if ( $after >= $before || $after < 1 ) {
			wp_delete_file( $saved['path'] );

			return array( 'before' => $before, 'after' => $before, 'saved' => 0 );
		}

		if ( ! @rename( $saved['path'], $path ) ) {
			wp_delete_file( $saved['path'] );

			return new WP_Error( 'dos_replace', __( 'Could not replace the original file.', 'dos-toolkit' ) );
		}

		return array( 'before' => $before, 'after' => $after, 'saved' => $before - $after );
	}

	/**
	 * Compress an upload as it arrives, before it becomes the canonical file.
	 */
	public static function on_upload( $upload, $context = 'upload' ) {
		if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}

		if ( ! self::compressible( $upload['type'] ) ) {
			return $upload;
		}

		$result = self::compress_file( $upload['file'], $upload['type'] );

		if ( is_wp_error( $result ) ) {
			DOS_Log::add( 'images', 'compress_skipped', sprintf( '%s: %s', wp_basename( $upload['file'] ), $result->get_error_message() ) );

			return $upload;
		}

		if ( $result['saved'] > 0 ) {
			DOS_Log::add(
				'images',
				'compressed',
				sprintf( '%s: %s saved', wp_basename( $upload['file'] ), size_format( $result['saved'] ) )
			);
		}

		return $upload;
	}

	/* ---------------------------------------------------------------------
	 * Where the weight is
	 * ------------------------------------------------------------------- */

	/**
	 * Classify one image by what is actually costing the page weight.
	 *
	 * Dimensions first: a file larger than anything that displays it is the
	 * expensive mistake, and no amount of quality tuning fixes it.
	 *
	 * @return array bucket, bytes, note
	 */
	public static function classify( $bytes, $width, $height, $mime ) {
		$bytes  = (int) $bytes;
		$pixels = max( 1, (int) $width * (int) $height );
		$bpp    = $bytes / $pixels;
		$limit  = self::threshold() ? self::threshold() : self::DEFAULT_THRESHOLD;

		if ( $width > $limit || $height > $limit ) {
			return array(
				'bucket' => 'oversized',
				'bytes'  => $bytes,
				'note'   => sprintf(
					/* translators: 1: width, 2: height, 3: threshold */
					__( '%1$dx%2$d, larger than the %3$dpx limit', 'dos-toolkit' ),
					(int) $width,
					(int) $height,
					(int) $limit
				),
			);
		}

		// A photograph saved as PNG is typically several times the size it
		// would be as a JPEG, and the format is the whole reason.
		if ( 'image/png' === $mime && $pixels > 250000 && $bpp > 1.0 ) {
			return array(
				'bucket' => 'wrong_format',
				'bytes'  => $bytes,
				'note'   => __( 'a large PNG, probably a photograph that should be a JPEG', 'dos-toolkit' ),
			);
		}

		if ( 'image/jpeg' === $mime && $bpp > self::HEAVY_BPP ) {
			return array(
				'bucket' => 'heavy',
				'bytes'  => $bytes,
				'note'   => sprintf(
					/* translators: %s: bytes per pixel */
					__( '%s bytes per pixel, higher than a well-compressed photograph needs', 'dos-toolkit' ),
					number_format_i18n( $bpp, 2 )
				),
			);
		}

		return array( 'bucket' => 'fine', 'bytes' => $bytes, 'note' => '' );
	}

	/* ---------------------------------------------------------------------
	 * Finding the balance
	 * ------------------------------------------------------------------- */

	/**
	 * Encode one image at several qualities and measure each.
	 *
	 * The difference score is a rough perceptual proxy, not a real metric:
	 * both versions are reduced to a small thumbnail and compared channel by
	 * channel. It is enough to separate "no visible change" from "visible on
	 * a gradient", which is the decision being made, and it is honest about
	 * being approximate.
	 *
	 * @return array|WP_Error
	 */
	public static function sample( $attachment_id, array $qualities = array() ) {
		$path = get_attached_file( (int) $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'dos_missing', __( 'That image is not on disk.', 'dos-toolkit' ) );
		}

		$mime = get_post_mime_type( (int) $attachment_id );

		if ( 'image/jpeg' !== $mime ) {
			return new WP_Error( 'dos_type', __( 'Choose a JPEG. Quality does not mean the same thing for a PNG, which is lossless.', 'dos-toolkit' ) );
		}

		$size = @getimagesize( $path );

		if ( ! $size ) {
			return new WP_Error( 'dos_unreadable', __( 'Not a readable image.', 'dos-toolkit' ) );
		}

		$can = self::can_process( $size[0], $size[1] );

		if ( true !== $can ) {
			return new WP_Error( 'dos_memory', $can );
		}

		wp_raise_memory_limit( 'image' );

		$qualities = $qualities ? $qualities : self::SAMPLE_QUALITIES;
		$original  = (int) filesize( $path );
		$rows      = array();

		foreach ( $qualities as $quality ) {
			$editor = wp_get_image_editor( $path );

			if ( is_wp_error( $editor ) ) {
				continue;
			}

			$editor->set_quality( (int) $quality );

			$temp  = $path . '.dos-sample-' . (int) $quality;
			$saved = $editor->save( $temp, $mime );

			if ( is_wp_error( $saved ) ) {
				continue;
			}

			$bytes = (int) filesize( $saved['path'] );

			$rows[] = array(
				'quality'    => (int) $quality,
				'bytes'      => $bytes,
				'saved'      => $original - $bytes,
				'percent'    => $original ? round( ( ( $original - $bytes ) / $original ) * 100, 1 ) : 0,
				'difference' => self::difference( $path, $saved['path'] ),
			);

			wp_delete_file( $saved['path'] );
		}

		return array(
			'attachment' => (int) $attachment_id,
			'name'       => wp_basename( $path ),
			'width'      => (int) $size[0],
			'height'     => (int) $size[1],
			'original'   => $original,
			'rows'       => $rows,
		);
	}

	/**
	 * Mean per-channel difference between two images, on a 0-255 scale.
	 *
	 * Both are reduced to a small thumbnail first, which is cheap and also
	 * approximates what a reader notices: compression artefacts that vanish
	 * at thumbnail size are rarely visible in a page.
	 */
	public static function difference( $a, $b, $side = 96 ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}

		$one = self::thumbnail( $a, $side );
		$two = self::thumbnail( $b, $side );

		if ( ! $one || ! $two ) {
			if ( $one ) { imagedestroy( $one ); }
			if ( $two ) { imagedestroy( $two ); }

			return null;
		}

		$total = 0;

		for ( $x = 0; $x < $side; $x++ ) {
			for ( $y = 0; $y < $side; $y++ ) {
				$p = imagecolorat( $one, $x, $y );
				$q = imagecolorat( $two, $x, $y );

				$total += abs( ( ( $p >> 16 ) & 0xFF ) - ( ( $q >> 16 ) & 0xFF ) );
				$total += abs( ( ( $p >> 8 ) & 0xFF ) - ( ( $q >> 8 ) & 0xFF ) );
				$total += abs( ( $p & 0xFF ) - ( $q & 0xFF ) );
			}
		}

		imagedestroy( $one );
		imagedestroy( $two );

		return round( $total / ( $side * $side * 3 ), 2 );
	}

	private static function thumbnail( $path, $side ) {
		$data = @file_get_contents( $path );

		if ( false === $data ) {
			return null;
		}

		$image = @imagecreatefromstring( $data );

		unset( $data );

		if ( ! $image ) {
			return null;
		}

		$small = imagecreatetruecolor( $side, $side );

		imagecopyresampled( $small, $image, 0, 0, 0, 0, $side, $side, imagesx( $image ), imagesy( $image ) );
		imagedestroy( $image );

		return $small;
	}

	/**
	 * What a difference score means, in words.
	 */
	public static function verdict( $difference ) {
		if ( null === $difference ) {
			return __( 'not measured', 'dos-toolkit' );
		}

		if ( $difference < 1.0 ) {
			return __( 'no visible change', 'dos-toolkit' );
		}

		if ( $difference < 2.0 ) {
			return __( 'very slight, unlikely to be noticed', 'dos-toolkit' );
		}

		if ( $difference < 3.5 ) {
			return __( 'slight, may show on gradients', 'dos-toolkit' );
		}

		return __( 'visible — too far', 'dos-toolkit' );
	}
}
