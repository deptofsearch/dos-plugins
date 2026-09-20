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
require_once __DIR__ . '/class-dos-images-compress.php';
require_once __DIR__ . '/class-dos-images-columns.php';

final class DOS_Module_Images extends DOS_Module {

	const KEY = 'images';

	public static function init() {
		DOS_Images_Markup::init();
		DOS_Images_Compress::init();
		DOS_Images_Columns::init();

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
			'images_weight_audit' => array(
				'label'       => __( 'Audit image weight', 'dos-toolkit' ),
				'description' => __( 'Reports where the library\'s weight actually is: images larger than anything that displays them, photographs saved as PNG, and files carrying more quality than they show. Changes nothing.', 'dos-toolkit' ),
				'batch_size'  => 100,
				'always_live' => true,
				'count'       => array( 'DOS_Images_Usage', 'count_attachments' ),
				'step'        => array( __CLASS__, 'weight_audit_step' ),
			),
			'images_compress_library' => array(
				'label'       => __( 'Compress the existing library', 'dos-toolkit' ),
				'description' => __( 'Re-encodes every JPEG and PNG already in the library at the quality set above, keeping the result only where it is genuinely smaller. The main file only; the generated sizes were made at this quality already. An image is re-encoded once and never again — the record kept on each attachment is what stops a second run degrading it. Dry run does the same encoding and measures it without replacing anything, so the figure it reports is the real one. Originals are overwritten and cannot be restored.', 'dos-toolkit' ),
				'batch_size'  => 20,
				'destructive' => true,
				'count'       => array( 'DOS_Images_Usage', 'count_attachments' ),
				'step'        => array( __CLASS__, 'compress_library_step' ),
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

	/**
	 * Walk the library and total up what each kind of problem is costing.
	 */
	public static function weight_audit_step( $offset, $size, $dry_run ) {
		if ( 0 === $offset ) {
			DOS_Settings::set( 'images_weight', array() );
		}

		$ids = get_posts(
			array(
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
			)
		);

		$totals = DOS_Settings::get( 'images_weight', array() );
		$totals = is_array( $totals ) ? $totals : array();

		$totals = wp_parse_args(
			$totals,
			array(
				'files'    => 0,
				'bytes'    => 0,
				'buckets'  => array(),
				'worst'    => array(),
			)
		);

		$notes = array();
		$flagged = 0;

		foreach ( $ids as $id ) {
			$path = get_attached_file( $id );

			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}

			$meta  = wp_get_attachment_metadata( $id );
			$bytes = (int) filesize( $path );

			$totals['files']++;
			$totals['bytes'] += $bytes;

			$class = DOS_Images_Compress::classify(
				$bytes,
				isset( $meta['width'] ) ? $meta['width'] : 0,
				isset( $meta['height'] ) ? $meta['height'] : 0,
				(string) get_post_mime_type( $id )
			);

			$bucket = $class['bucket'];

			if ( ! isset( $totals['buckets'][ $bucket ] ) ) {
				$totals['buckets'][ $bucket ] = array( 'count' => 0, 'bytes' => 0 );
			}

			$totals['buckets'][ $bucket ]['count']++;
			$totals['buckets'][ $bucket ]['bytes'] += $bytes;

			if ( 'fine' === $bucket ) {
				continue;
			}

			$flagged++;

			// Keep the heaviest offenders, which are where any effort should
			// start rather than whichever happened to be scanned first.
			$totals['worst'][] = array(
				'id'     => (int) $id,
				'name'   => wp_basename( $path ),
				'bytes'  => $bytes,
				'bucket' => $bucket,
				'note'   => $class['note'],
			);

			usort(
				$totals['worst'],
				function ( $a, $b ) {
					return $b['bytes'] - $a['bytes'];
				}
			);

			$totals['worst'] = array_slice( $totals['worst'], 0, 25 );

			if ( count( $notes ) < 40 ) {
				$notes[] = sprintf( '%s — %s (%s)', $class['note'], wp_basename( $path ), size_format( $bytes ) );
			}
		}

		DOS_Settings::set( 'images_weight', $totals );

		return array( 'processed' => count( $ids ), 'changed' => $flagged, 'notes' => $notes );
	}

	/**
	 * Walk the library re-encoding what is worth re-encoding.
	 *
	 * The query is over every image, unfiltered, and the already-compressed
	 * ones are skipped in PHP rather than excluded in SQL. That is deliberate:
	 * the runner advances by a fixed stride, so a result set that shrinks as
	 * the job progresses would step straight over images it never looked at.
	 */
	public static function compress_library_step( $offset, $size, $dry_run ) {
		$ids = get_posts(
			array(
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
			)
		);

		$notes   = array();
		$changed = 0;

		foreach ( $ids as $id ) {
			$result = DOS_Images_Compress::compress_attachment( $id, $dry_run );

			if ( 'compressed' !== $result['status'] ) {
				if ( 'failed' === $result['status'] && count( $notes ) < 40 ) {
					$notes[] = sprintf( '%s — skipped: %s', get_the_title( $id ), $result['note'] );
				}

				continue;
			}

			$changed++;

			if ( count( $notes ) < 40 ) {
				$notes[] = sprintf(
					'%s — %s (%s saved)',
					get_the_title( $id ),
					$result['note'],
					size_format( $result['saved'] )
				);
			}
		}

		return array( 'processed' => count( $ids ), 'changed' => $changed, 'notes' => $notes );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( empty( $_POST['dos_action'] ) || ! current_user_can( DOS_Settings::capability() ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['dos_action'] ) );

		if ( ! in_array( $action, array( 'save_images', 'sample_quality' ), true ) ) {
			return;
		}

		check_admin_referer( 'dos_save_images' );

		if ( 'sample_quality' === $action ) {
			$sample = DOS_Images_Compress::sample( isset( $_POST['sample_id'] ) ? (int) $_POST['sample_id'] : 0 );

			set_transient(
				'dos_images_sample',
				is_wp_error( $sample ) ? array( 'error' => $sample->get_error_message() ) : $sample,
				300
			);

			wp_safe_redirect( add_query_arg( array( 'page' => 'dos-images', 'dos_notice' => 'saved' ), admin_url( 'admin.php' ) ) . '#quality' );
			exit;
		}

		DOS_Settings::update(
			array(
				'images_markup_enabled' => empty( $_POST['markup_enabled'] ) ? 0 : 1,
				'images_markup_dry_run' => empty( $_POST['markup_live'] ) ? 1 : 0,
				'images_slot_width'     => isset( $_POST['slot_width'] ) ? absint( $_POST['slot_width'] ) : DOS_Images_Markup::DEFAULT_SLOT,
				'images_quality'        => isset( $_POST['quality'] ) ? max( 40, min( 100, (int) $_POST['quality'] ) ) : DOS_Images_Compress::DEFAULT_QUALITY,
				'images_threshold'      => isset( $_POST['threshold'] ) ? max( 0, (int) $_POST['threshold'] ) : DOS_Images_Compress::DEFAULT_THRESHOLD,
				'images_compress_uploads' => empty( $_POST['compress_uploads'] ) ? 0 : 1,
				'images_featured_column'  => empty( $_POST['featured_column'] ) ? 0 : 1,
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
						<th scope="row"><?php esc_html_e( 'Post and page lists', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="featured_column" value="1" <?php checked( DOS_Images_Columns::is_enabled() ); ?> />
								<?php esc_html_e( 'Show a Featured image column, with a filter for posts missing one', 'dos-toolkit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Reads data and changes nothing. A post with no featured image is invisible in a list of fifty until you open each one.', 'dos-toolkit' ); ?></p>
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
			<h2 id="quality"><?php esc_html_e( 'Compression', 'dos-toolkit' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Quality is the smaller lever. A file larger than anything that displays it costs far more than the difference between quality 82 and 75, which is what the weight audit below is for. Set the quality against measurements of your own photographs rather than a number from somewhere else.', 'dos-toolkit' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'dos_save_images' ); ?>
				<input type="hidden" name="dos_action" value="save_images" />
				<input type="hidden" name="markup_enabled" value="<?php echo DOS_Images_Markup::is_enabled() ? '1' : ''; ?>" />
				<input type="hidden" name="markup_live" value="<?php echo DOS_Images_Markup::is_dry_run() ? '' : '1'; ?>" />
				<input type="hidden" name="slot_width" value="<?php echo (int) DOS_Images_Markup::slot_width(); ?>" />
				<input type="hidden" name="featured_column" value="<?php echo DOS_Images_Columns::is_enabled() ? '1' : ''; ?>" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="quality"><?php esc_html_e( 'JPEG quality', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="number" id="quality" name="quality" min="40" max="100" class="small-text" value="<?php echo (int) DOS_Images_Compress::quality(); ?>" />
							<p class="description"><?php esc_html_e( 'Used for every size WordPress generates. Its own default is 82. Below about 70 shows on skies and gradients.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="threshold"><?php esc_html_e( 'Scale uploads wider than', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="number" id="threshold" name="threshold" min="0" class="small-text" value="<?php echo (int) DOS_Images_Compress::threshold(); ?>" /> px
							<p class="description"><?php esc_html_e( 'WordPress keeps the original and serves a scaled copy. 2560 is its default; 1920 is plenty for most sites. 0 leaves WordPress to decide.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'New uploads', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="compress_uploads" value="1" <?php checked( DOS_Images_Compress::compress_uploads() ); ?> />
								<?php esc_html_e( 'Re-encode JPEG and PNG uploads as they arrive', 'dos-toolkit' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Applies to new uploads only; nothing already in the library is touched. A re-encode that would make a file larger is discarded, and an image too large to open safely in memory is skipped and logged rather than half-written.', 'dos-toolkit' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save compression settings', 'dos-toolkit' ), 'secondary' ); ?>
			</form>

			<h3><?php esc_html_e( 'Find the balance', 'dos-toolkit' ); ?></h3>

			<p class="description">
				<?php esc_html_e( 'Encodes one of your own photographs at a range of qualities and measures each: what it saves, and how far it drifts from the original. Nothing is changed — every version is measured and thrown away.', 'dos-toolkit' ); ?>
			</p>

			<?php
			$samples = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image/jpeg',
					'posts_per_page' => 50,
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			?>

			<?php if ( ! $samples ) : ?>
				<p class="description"><?php esc_html_e( 'No JPEGs in the library to measure.', 'dos-toolkit' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'dos_save_images' ); ?>
					<input type="hidden" name="dos_action" value="sample_quality" />
					<select name="sample_id">
						<?php foreach ( $samples as $sample ) : ?>
							<option value="<?php echo (int) $sample->ID; ?>"><?php echo esc_html( wp_basename( get_attached_file( $sample->ID ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Measure', 'dos-toolkit' ); ?></button>
					<span class="description"><?php esc_html_e( 'Pick a photograph rather than a logo or a screenshot — they behave differently.', 'dos-toolkit' ); ?></span>
				</form>
			<?php endif; ?>

			<?php $measured = get_transient( 'dos_images_sample' ); ?>
			<?php if ( $measured ) : ?>
				<?php delete_transient( 'dos_images_sample' ); ?>

				<?php if ( ! empty( $measured['error'] ) ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( $measured['error'] ); ?></p></div>
				<?php else : ?>
					<p>
						<strong><?php echo esc_html( $measured['name'] ); ?></strong>
						<span class="description">
							<?php
							printf(
								/* translators: 1: width, 2: height, 3: current file size */
								esc_html__( '%1$dx%2$d · %3$s as it stands', 'dos-toolkit' ),
								(int) $measured['width'],
								(int) $measured['height'],
								esc_html( size_format( $measured['original'] ) )
							);
							?>
						</span>
					</p>

					<table class="widefat striped" style="max-width:60em">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Quality', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Size', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Saved', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Difference', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'How it looks', 'dos-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $measured['rows'] as $row ) : ?>
							<tr>
								<td><strong><?php echo (int) $row['quality']; ?></strong></td>
								<td><?php echo esc_html( size_format( $row['bytes'] ) ); ?></td>
								<td>
									<?php echo esc_html( size_format( max( 0, $row['saved'] ) ) ); ?>
									<span class="description"><?php echo esc_html( $row['percent'] ); ?>%</span>
								</td>
								<td><?php echo null === $row['difference'] ? '—' : esc_html( $row['difference'] ); ?></td>
								<td class="description"><?php echo esc_html( DOS_Images_Compress::verdict( $row['difference'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<p class="description">
						<?php esc_html_e( 'The difference figure is an approximation, not a formal metric: both versions are reduced to a thumbnail and compared channel by channel. It separates “no visible change” from “visible on a gradient”, which is the decision being made. Pick the lowest quality still reading as no visible change.', 'dos-toolkit' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<hr>
			<h2 id="compressed"><?php esc_html_e( 'What compression has saved', 'dos-toolkit' ); ?></h2>

			<?php
			$stats  = DOS_Images_Compress::stats();
			$recent = $stats['count'] ? DOS_Images_Compress::recent( 25 ) : array();
			?>

			<?php if ( ! $stats['count'] ) : ?>
				<p class="description">
					<?php esc_html_e( 'Nothing compressed yet. This counts work actually done, which is not the same thing as the weight audit below — that reports what could be saved. Two things feed it: re-encoding new uploads as they arrive, and the library job that walks what is already there.', 'dos-toolkit' ); ?>
				</p>
			<?php else : ?>
				<?php $percent = $stats['before'] ? round( ( $stats['saved'] / $stats['before'] ) * 100, 1 ) : 0; ?>

				<p>
					<?php
					printf(
						/* translators: 1: number of images, 2: bytes saved, 3: percentage, 4: size before, 5: size after */
						esc_html__( '%1$d images compressed. %2$s saved — %3$s%% off, %4$s down to %5$s.', 'dos-toolkit' ),
						(int) $stats['count'],
						'<strong>' . esc_html( size_format( (int) $stats['saved'] ) ) . '</strong>',
						esc_html( number_format_i18n( $percent, 1 ) ),
						esc_html( size_format( (int) $stats['before'] ) ),
						esc_html( size_format( (int) $stats['after'] ) )
					);
					?>
					<br>
					<span class="description">
						<?php
						printf(
							/* translators: 1: time ago of first compression, 2: time ago of most recent */
							esc_html__( 'First %1$s ago, most recent %2$s ago.', 'dos-toolkit' ),
							esc_html( human_time_diff( (int) $stats['first'] ) ),
							esc_html( human_time_diff( (int) $stats['last'] ) )
						);
						?>
					</span>
				</p>

				<?php if ( $recent ) : ?>
					<table class="widefat striped" style="max-width:70em">
						<thead>
							<tr>
								<th><?php esc_html_e( 'File', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Before', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'After', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Saved', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Quality', 'dos-toolkit' ); ?></th>
								<th><?php esc_html_e( 'When', 'dos-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<?php $record = $row['record']; ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><code><?php echo esc_html( $row['name'] ); ?></code></a>
									<span class="description">
										<?php
										echo 'upload' === $record['source']
											? esc_html__( 'on upload', 'dos-toolkit' )
											: esc_html__( 'library job', 'dos-toolkit' );
										?>
									</span>
								</td>
								<td><?php echo esc_html( size_format( (int) $record['before'] ) ); ?></td>
								<td><?php echo esc_html( size_format( (int) $record['after'] ) ); ?></td>
								<td>
									<?php echo esc_html( size_format( (int) $record['saved'] ) ); ?>
									<span class="description">
										<?php
										echo esc_html(
											number_format_i18n(
												$record['before'] ? round( ( $record['saved'] / $record['before'] ) * 100, 1 ) : 0,
												1
											)
										);
										?>%
									</span>
								</td>
								<td><?php echo (int) $record['quality']; ?></td>
								<td><?php echo esc_html( human_time_diff( (int) $record['time'] ) ); ?> <?php esc_html_e( 'ago', 'dos-toolkit' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the activity log */
							esc_html__( 'The twenty-five most recent. Every compression, and every image skipped because it was too large to open safely, is written to the %s.', 'dos-toolkit' ),
							'<a href="' . esc_url( add_query_arg( array( 'page' => 'dos-log', 'module' => 'images' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Activity Log', 'dos-toolkit' ) . '</a>'
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'Where the weight is', 'dos-toolkit' ); ?></h2>

			<?php
			$weight = DOS_Settings::get( 'images_weight', array() );
			$weight = is_array( $weight ) ? $weight : array();
			?>

			<?php if ( empty( $weight['files'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Run “Audit image weight” below to build this.', 'dos-toolkit' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: 1: number of images, 2: total size */
						esc_html__( '%1$d images, %2$s in total.', 'dos-toolkit' ),
						(int) $weight['files'],
						esc_html( size_format( (int) $weight['bytes'] ) )
					);
					?>
				</p>

				<?php
				$labels = array(
					'oversized'    => __( 'Larger than anything that displays them', 'dos-toolkit' ),
					'wrong_format' => __( 'Photographs saved as PNG', 'dos-toolkit' ),
					'heavy'        => __( 'Carrying more quality than they show', 'dos-toolkit' ),
					'fine'         => __( 'Nothing obviously wrong', 'dos-toolkit' ),
				);
				?>

				<table class="widefat striped" style="max-width:60em">
					<thead><tr><th><?php esc_html_e( 'Finding', 'dos-toolkit' ); ?></th><th><?php esc_html_e( 'Images', 'dos-toolkit' ); ?></th><th><?php esc_html_e( 'Weight', 'dos-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $labels as $key => $label ) : ?>
						<?php if ( empty( $weight['buckets'][ $key ]['count'] ) ) { continue; } ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo (int) $weight['buckets'][ $key ]['count']; ?></td>
							<td><?php echo esc_html( size_format( (int) $weight['buckets'][ $key ]['bytes'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $weight['worst'] ) ) : ?>
					<h3><?php esc_html_e( 'Heaviest offenders', 'dos-toolkit' ); ?></h3>
					<table class="widefat striped" style="max-width:70em">
						<thead><tr><th><?php esc_html_e( 'File', 'dos-toolkit' ); ?></th><th><?php esc_html_e( 'Size', 'dos-toolkit' ); ?></th><th><?php esc_html_e( 'Why', 'dos-toolkit' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $weight['worst'] as $worst ) : ?>
							<tr>
								<td><code><?php echo esc_html( $worst['name'] ); ?></code></td>
								<td><?php echo esc_html( size_format( (int) $worst['bytes'] ) ); ?></td>
								<td class="description"><?php echo esc_html( $worst['note'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Media library jobs', 'dos-toolkit' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Run the usage scan first. The delete job acts on what that scan recorded, so deleting against a stale scan is how a used image gets removed.', 'dos-toolkit' ); ?>
			</p>

			<?php
			foreach ( array( 'images_weight_audit', 'images_usage_scan', 'images_compress_library', 'images_alt_audit', 'images_clear_titles', 'images_delete_unused' ) as $job ) {
				DOS_Batch::render_runner( $job );
			}
			?>
		</div>
		<?php
	}
}
