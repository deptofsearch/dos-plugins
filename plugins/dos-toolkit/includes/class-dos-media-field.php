<?php
/**
 * A media-library picker for any setting that stores an attachment ID.
 *
 * The storage format is unchanged — still a bare attachment ID — so fields
 * that previously asked the operator to type one keep working, and nothing
 * needs migrating. Only the input changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Media_Field {

	private static $enqueued = false;

	/**
	 * Load the media modal and this field's script. Safe to call more than
	 * once; modules call it from their own admin_enqueue_scripts handler,
	 * because only they know which screens they render a field on.
	 */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}

		self::$enqueued = true;

		wp_enqueue_media();

		wp_enqueue_style( 'dos-toolkit-admin', DOS_TOOLKIT_URL . 'assets/admin.css', array(), DOS_TOOLKIT_VERSION );

		wp_enqueue_script( 'dos-toolkit-media-field', DOS_TOOLKIT_URL . 'assets/media-field.js', array( 'jquery' ), DOS_TOOLKIT_VERSION, true );

		wp_localize_script(
			'dos-toolkit-media-field',
			'dosMediaField',
			array(
				'title'  => __( 'Choose an image', 'dos-toolkit' ),
				'button' => __( 'Use this image', 'dos-toolkit' ),
				'remove' => __( 'Remove', 'dos-toolkit' ),
				'choose' => __( 'Choose image', 'dos-toolkit' ),
				'change' => __( 'Change image', 'dos-toolkit' ),
				'small'  => __( 'Smaller than %1$d×%2$d — social networks may crop or refuse it.', 'dos-toolkit' ),
			)
		);
	}

	/**
	 * @param string $name  Form field name; the attachment ID posts under it.
	 * @param int    $value Current attachment ID, 0 for none.
	 * @param array  $args  {
	 *     @type string $description Help text under the control.
	 *     @type int    $min_width   Warn below this width. 0 disables.
	 *     @type int    $min_height  Warn below this height. 0 disables.
	 * }
	 */
	public static function render( $name, $value, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'description' => '',
				'min_width'   => 0,
				'min_height'  => 0,
			)
		);

		$value = (int) $value;
		$image = $value ? wp_get_attachment_image_src( $value, 'medium' ) : false;
		$full  = $value ? wp_get_attachment_image_src( $value, 'full' ) : false;

		// An ID can outlive the attachment it points at — someone deletes the
		// image in the media library and the setting is left dangling. Say so
		// rather than rendering a broken thumbnail.
		$missing = $value && ! $image;
		?>
		<div class="dos-media-field"
			data-min-width="<?php echo (int) $args['min_width']; ?>"
			data-min-height="<?php echo (int) $args['min_height']; ?>">

			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo $value ? (int) $value : ''; ?>" class="dos-media-id" />

			<div class="dos-media-preview">
				<?php if ( $image ) : ?>
					<img src="<?php echo esc_url( $image[0] ); ?>" alt="" />
				<?php endif; ?>
			</div>

			<?php if ( $missing ) : ?>
				<p class="dos-media-warning">
					<?php
					printf(
						/* translators: %d: attachment ID that no longer exists */
						esc_html__( 'Image #%d is no longer in the media library.', 'dos-toolkit' ),
						$value
					);
					?>
				</p>
			<?php endif; ?>

			<p class="dos-media-actions">
				<button type="button" class="button dos-media-choose">
					<?php echo $value ? esc_html__( 'Change image', 'dos-toolkit' ) : esc_html__( 'Choose image', 'dos-toolkit' ); ?>
				</button>
				<button type="button" class="button-link dos-media-remove" <?php echo $value ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Remove', 'dos-toolkit' ); ?>
				</button>
			</p>

			<p class="dos-media-meta">
				<?php if ( $full && ! empty( $full[1] ) ) : ?>
					<?php
					printf(
						'%d×%d',
						(int) $full[1],
						(int) $full[2]
					);
					?>
					<?php if ( $args['min_width'] && (int) $full[1] < (int) $args['min_width'] ) : ?>
						<span class="dos-media-warning">
							<?php
							printf(
								/* translators: 1: minimum width, 2: minimum height */
								esc_html__( 'Smaller than %1$d×%2$d — social networks may crop or refuse it.', 'dos-toolkit' ),
								(int) $args['min_width'],
								(int) $args['min_height']
							);
							?>
						</span>
					<?php endif; ?>
				<?php endif; ?>
			</p>

			<?php if ( $args['description'] ) : ?>
				<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
