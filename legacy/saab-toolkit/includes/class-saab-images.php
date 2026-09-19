<?php
/**
 * Image markup module.
 *
 * Themify Builder renders its post modules with a bare <img src>, pointing at
 * whatever size the module was configured with — in practice the full file, so
 * 2560px originals land in ~348px slots and no srcset is offered at all.
 *
 * This module does not touch image files. It rewrites the markup on the way
 * out: resolve each <img> back to its attachment, swap the src for the
 * smallest registered size that still covers the slot, and attach the srcset
 * and sizes that WordPress would have produced on its own.
 *
 * Byte-level work — compression, WebP, Accept negotiation — stays with the
 * host's optimizer, which does it at a layer PHP can't reach.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SAAB_Images {

	/** Slot width in CSS pixels that homepage/archive thumbnails render into. */
	const DEFAULT_SLOT = 350;

	/** Retina multiplier applied to the slot width when choosing a size. */
	const DENSITY = 2;

	/** How many rewrites the dry run records before it stops collecting. */
	const LOG_LIMIT = 60;

	/** Transient holding the dry-run log. */
	const LOG_KEY = 'saab_images_dry_run';

	private static $log     = array();
	private static $scanned = 0;
	private static $changed = 0;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_settings() {
		register_setting( 'saab_toolkit', 'saab_images_dry_run', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'saab_toolkit', 'saab_images_slot_width', array( 'sanitize_callback' => 'absint' ) );
	}

	public static function is_dry_run() {
		// Default to dry run. Rewriting output on a live site is opt-in.
		$value = get_option( 'saab_images_dry_run', null );
		return null === $value ? true : (bool) $value;
	}

	/* ---------------------------------------------------------------------
	 * Buffering
	 * ------------------------------------------------------------------- */

	public static function start_buffer() {
		if ( is_admin() || is_feed() || is_embed() || is_preview() ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! empty( $_GET['saab_no_rewrite'] ) ) {
			return; // Escape hatch: append ?saab_no_rewrite=1 to see the untouched page.
		}

		ob_start( array( __CLASS__, 'rewrite' ) );
	}

	/**
	 * Runs once per page render. On a cached site that means once per cache
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
			set_transient( self::LOG_KEY, array(
				'time'    => time(),
				'url'     => home_url( add_query_arg( array() ) ),
				'scanned' => self::$scanned,
				'changed' => self::$changed,
				'dry_run' => self::is_dry_run(),
				'rows'    => self::$log,
			), DAY_IN_SECONDS );
		}

		return self::is_dry_run() ? $html : $out;
	}

	/* ---------------------------------------------------------------------
	 * One <img> at a time
	 * ------------------------------------------------------------------- */

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

		// IMG_2261-1024x768.jpeg and IMG_2261-scaled.jpeg both belong to the
		// same attachment as IMG_2261.jpeg.
		$base = preg_replace( '/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $url );

		$cache_key = 'saab_img_' . md5( $base );
		$cached    = wp_cache_get( $cache_key, 'saab_toolkit' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$id = attachment_url_to_postid( $base );
		if ( ! $id && $base !== $url ) {
			$id = attachment_url_to_postid( $url );
		}

		wp_cache_set( $cache_key, (int) $id, 'saab_toolkit', HOUR_IN_SECONDS );

		return (int) $id;
	}

	/**
	 * The width we actually need: an explicit width attribute if the theme
	 * wrote one, otherwise the configured slot width at retina density.
	 */
	private static function target_width( $tag ) {
		if ( preg_match( '/\swidth=(["\'])(\d+)\1/i', $tag, $m ) ) {
			$declared = (int) $m[2];
			if ( $declared > 0 ) {
				return $declared * self::DENSITY;
			}
		}

		$slot = (int) get_option( 'saab_images_slot_width', self::DEFAULT_SLOT );

		return ( $slot > 0 ? $slot : self::DEFAULT_SLOT ) * self::DENSITY;
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

	/* ---------------------------------------------------------------------
	 * Settings UI
	 * ------------------------------------------------------------------- */

	public static function render_settings_fields() {
		$log = get_transient( self::LOG_KEY );
		?>
		<tr>
			<th scope="row">Mode</th>
			<td>
				<label>
					<input type="radio" name="saab_images_dry_run" value="1" <?php checked( self::is_dry_run() ); ?>>
					<strong>Dry run</strong> — record what would change, serve the page untouched
				</label><br>
				<label>
					<input type="radio" name="saab_images_dry_run" value="0" <?php checked( ! self::is_dry_run() ); ?>>
					<strong>Live</strong> — rewrite the markup
				</label>
				<p class="description">
					Add <code>?saab_no_rewrite=1</code> to any URL to see the original markup while live.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="saab_images_slot_width">Thumbnail slot width</label></th>
			<td>
				<input type="number" id="saab_images_slot_width" name="saab_images_slot_width"
					value="<?php echo (int) get_option( 'saab_images_slot_width', self::DEFAULT_SLOT ); ?>"
					class="small-text"> px
				<p class="description">
					Used when the theme writes no width attribute. Measured at ~348px on the homepage,
					doubled for retina when picking a size.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Last page scanned</th>
			<td>
				<?php if ( ! $log ) : ?>
					<p class="description">Nothing recorded yet. Load a front-end page, then come back.</p>
				<?php else : ?>
					<p>
						<code><?php echo esc_html( $log['url'] ); ?></code><br>
						<?php echo (int) $log['scanned']; ?> images scanned,
						<strong><?php echo (int) $log['changed']; ?></strong> would be rewritten
						(<?php echo $log['dry_run'] ? 'dry run' : 'live'; ?>,
						<?php echo esc_html( human_time_diff( $log['time'] ) ); ?> ago)
					</p>
					<table class="widefat striped" style="max-width:820px">
						<thead><tr><th>From</th><th>To</th><th>Size</th><th>Target</th><th>srcset</th></tr></thead>
						<tbody>
						<?php foreach ( $log['rows'] as $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( $row['from'] ); ?></code></td>
								<td><code><?php echo esc_html( $row['to'] ); ?></code></td>
								<td><?php echo esc_html( $row['size'] ); ?></td>
								<td><?php echo (int) $row['target']; ?>px</td>
								<td><?php echo (int) $row['srcset']; ?> entries</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
