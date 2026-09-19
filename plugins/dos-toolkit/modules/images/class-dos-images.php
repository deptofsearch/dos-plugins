<?php
/**
 * Images & Media module.
 *
 * Two jobs, kept deliberately separate: rewriting the markup a theme emits,
 * and working out which uploads are actually referenced. Neither ever
 * rewrites an image file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-dos-images-markup.php';
require_once __DIR__ . '/class-dos-images-usage.php';

final class DOS_Module_Images extends DOS_Module {

	const KEY = 'images';

	public static function init() {
		DOS_Images_Markup::init();

		add_action( 'admin_init', array( __CLASS__, 'handle_post' ), 20 );
	}

	public static function pages() {
		return array(
			array(
				'slug'     => 'dos-images',
				'title'    => __( 'Images & Media', 'dos-toolkit' ),
				'callback' => array( __CLASS__, 'render_page' ),
			),
		);
	}

	public static function jobs() {
		return array(
			'images_usage_scan' => array(
				'label'       => __( 'Scan media usage', 'dos-toolkit' ),
				'description' => __( 'Walks every post, page and custom post type and records which images are referenced, in post content and in post meta, so page-builder layouts are covered. Run this before anything else on this screen — the other jobs read what it writes.', 'dos-toolkit' ),
				'batch_size'  => DOS_Images_Usage::SCAN_BATCH,
				'always_live' => true,
				'count'       => array( 'DOS_Images_Usage', 'scan_total' ),
				'step'        => array( 'DOS_Images_Usage', 'scan_step' ),
			),
			'images_alt_audit' => array(
				'label'       => __( 'Audit alt text', 'dos-toolkit' ),
				'description' => __( 'Reports images with no alt text. Reads only; it never invents alt text, because a wrong description is worse than none.', 'dos-toolkit' ),
				'batch_size'  => 100,
				'count'       => array( 'DOS_Images_Usage', 'count_attachments' ),
				'step'        => array( 'DOS_Images_Usage', 'alt_audit_step' ),
			),
			'images_clear_titles' => array(
				'label'       => __( 'Clear image titles', 'dos-toolkit' ),
				'description' => __( 'Empties the Title field on every image, so WordPress stops echoing filename-derived titles into markup. Cannot be undone.', 'dos-toolkit' ),
				'batch_size'  => 100,
				'destructive' => true,
				'count'       => array( 'DOS_Images_Usage', 'count_attachments' ),
				'step'        => array( 'DOS_Images_Usage', 'clear_titles_step' ),
			),
			'images_delete_unused' => array(
				'label'       => __( 'Delete unused images', 'dos-toolkit' ),
				'description' => __( 'Permanently deletes every image the last scan found no reference to, and its files on disk. Site logo, site icon, header image and the SEO share image are kept regardless. The scan reads posts and their meta; it does not read widgets, menus, theme options or stylesheets, so an image used only in one of those reads as unused. Read the dry run before confirming.', 'dos-toolkit' ),
				'batch_size'  => 50,
				'destructive' => true,
				'count'       => array( 'DOS_Images_Usage', 'count_unused' ),
				'step'        => array( 'DOS_Images_Usage', 'delete_step' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( empty( $_POST['dos_action'] ) || 'save_images' !== sanitize_key( wp_unslash( $_POST['dos_action'] ) ) ) {
			return;
		}

		if ( ! current_user_can( DOS_Settings::capability() ) ) {
			return;
		}

		check_admin_referer( 'dos_save_images' );

		DOS_Settings::update(
			array(
				'images_markup_enabled' => empty( $_POST['markup_enabled'] ) ? 0 : 1,
				'images_markup_dry_run' => empty( $_POST['markup_live'] ) ? 1 : 0,
				'images_slot_width'     => isset( $_POST['slot_width'] ) ? absint( $_POST['slot_width'] ) : DOS_Images_Markup::DEFAULT_SLOT,
			)
		);

		self::log( 'settings_saved', 'Image settings updated.' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'dos-images', 'dos_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		$log = DOS_Images_Markup::last_page_log();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Images & Media', 'dos-toolkit' ); ?></h1>

			<?php DOS_Admin::notice(); ?>

			<h2><?php esc_html_e( 'Responsive markup', 'dos-toolkit' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Rewrites bare <img> tags on the way out: swaps the src for the smallest registered size that covers the slot, and adds the srcset and sizes WordPress would have produced. Image files are never modified — compression and WebP stay with your host or optimizer.', 'dos-toolkit' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'dos_save_images' ); ?>
				<input type="hidden" name="dos_action" value="save_images" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Rewriting', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="markup_enabled" value="1" <?php checked( DOS_Images_Markup::is_enabled() ); ?> />
								<?php esc_html_e( 'Inspect front-end pages', 'dos-toolkit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. While on but not live, pages are served exactly as the theme rendered them and only the report below is recorded.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="markup_live" value="1" <?php checked( ! DOS_Images_Markup::is_dry_run() ); ?> />
								<strong><?php esc_html_e( 'Live — actually rewrite the markup', 'dos-toolkit' ); ?></strong>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: query string that disables rewriting */
									esc_html__( 'Append %s to any URL to see the untouched markup while live.', 'dos-toolkit' ),
									'<code>?dos_no_rewrite=1</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slot_width"><?php esc_html_e( 'Thumbnail slot width', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="number" id="slot_width" name="slot_width" value="<?php echo (int) DOS_Images_Markup::slot_width(); ?>" class="small-text"> px
							<p class="description"><?php esc_html_e( 'Used only when the theme writes no width attribute. Doubled for retina when picking a size.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save image settings', 'dos-toolkit' ) ); ?>
			</form>

			<h3><?php esc_html_e( 'Last page inspected', 'dos-toolkit' ); ?></h3>

			<?php if ( ! $log ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing recorded yet. Load a front-end page, then come back.', 'dos-toolkit' ); ?></p>
			<?php else : ?>
				<p>
					<code><?php echo esc_html( $log['url'] ); ?></code><br>
					<?php
					printf(
						/* translators: 1: images scanned, 2: images rewritten, 3: mode, 4: time ago */
						esc_html__( '%1$d scanned, %2$d rewritten (%3$s, %4$s ago)', 'dos-toolkit' ),
						(int) $log['scanned'],
						(int) $log['changed'],
						$log['dry_run'] ? esc_html__( 'dry run', 'dos-toolkit' ) : esc_html__( 'live', 'dos-toolkit' ),
						esc_html( human_time_diff( (int) $log['time'] ) )
					);
					?>
				</p>

				<table class="widefat striped" style="max-width:820px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'From', 'dos-toolkit' ); ?></th>
							<th><?php esc_html_e( 'To', 'dos-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Size', 'dos-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Target', 'dos-toolkit' ); ?></th>
							<th><?php esc_html_e( 'srcset', 'dos-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $log['rows'] as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row['from'] ); ?></code></td>
							<td><code><?php echo esc_html( $row['to'] ); ?></code></td>
							<td><?php echo esc_html( $row['size'] ); ?></td>
							<td><?php echo (int) $row['target']; ?>px</td>
							<td><?php echo (int) $row['srcset']; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Media library jobs', 'dos-toolkit' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Run the usage scan first. The delete job acts on what that scan recorded, so deleting against a stale scan is how a used image gets removed.', 'dos-toolkit' ); ?>
			</p>

			<?php
			foreach ( array( 'images_usage_scan', 'images_alt_audit', 'images_clear_titles', 'images_delete_unused' ) as $job ) {
				DOS_Batch::render_runner( $job );
			}
			?>
		</div>
		<?php
	}
}
