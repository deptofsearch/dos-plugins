<?php
/**
 * Internal Links module.
 *
 * Rules are written into post content rather than applied as the page
 * renders, which is what makes the two destructive jobs destructive. Both go
 * through the shared batch runner and inherit its dry-run-first requirement.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-dos-links-engine.php';
require_once __DIR__ . '/class-dos-links-rules.php';

final class DOS_Module_Links extends DOS_Module {

	const KEY = 'links';

	/** Items per pass for jobs that walk the content tables. */
	const BATCH = 20;

	public static function init() {
		add_action( 'admin_init', array( 'DOS_Links_Rules', 'maybe_install' ), 4 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ), 20 );
	}

	public static function pages() {
		return array(
			array(
				'slug'     => 'dos-links',
				'title'    => __( 'Internal Links', 'dos-toolkit' ),
				'callback' => array( __CLASS__, 'render_page' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * What gets walked
	 * ------------------------------------------------------------------- */

	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );

		unset( $types['attachment'] );

		return apply_filters( 'dos_toolkit_link_post_types', array_values( $types ) );
	}

	public static function count_posts() {
		$query = new WP_Query(
			array(
				'post_type'      => self::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return (int) $query->found_posts;
	}

	private static function page_of_posts( $offset, $size ) {
		return get_posts(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => $size,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Jobs
	 * ------------------------------------------------------------------- */

	public static function jobs() {
		return array(
			'links_scan' => array(
				'label'       => __( 'Scan for link opportunities', 'dos-toolkit' ),
				'description' => __( 'Counts where every enabled rule could place a link, and how many links are already in place. Changes nothing. Run it before applying, and again afterwards to see the result.', 'dos-toolkit' ),
				'batch_size'  => self::BATCH,
				'always_live' => true,
				'count'       => array( __CLASS__, 'count_posts' ),
				'step'        => array( __CLASS__, 'scan_step' ),
			),
			'links_apply' => array(
				'label'       => __( 'Apply links', 'dos-toolkit' ),
				'description' => __( 'Writes the links into the content of published posts and pages. The dry run lists every page it would change and how many links it would add. Links are marked so they can be removed again.', 'dos-toolkit' ),
				'batch_size'  => self::BATCH,
				'destructive' => true,
				'count'       => array( __CLASS__, 'count_posts' ),
				'step'        => array( __CLASS__, 'apply_step' ),
			),
			'links_remove' => array(
				'label'       => __( 'Remove all links this module made', 'dos-toolkit' ),
				'description' => __( 'Strips every link carrying this module\'s marker and leaves the text behind. Links added by hand are untouched.', 'dos-toolkit' ),
				'batch_size'  => self::BATCH,
				'destructive' => true,
				'count'       => array( __CLASS__, 'count_posts' ),
				'step'        => array( __CLASS__, 'remove_step' ),
			),
		);
	}

	public static function scan_step( $offset, $size, $dry_run ) {
		if ( 0 === $offset ) {
			DOS_Links_Rules::reset_stats();
		}

		$rules = DOS_Links_Rules::all( true );
		$posts = self::page_of_posts( $offset, $size );
		$notes = array();
		$total = 0;

		foreach ( $posts as $post ) {
			foreach ( $rules as $rule ) {
				$placements = DOS_Links_Engine::placements( $post->post_content, $rule, $post->ID );
				$existing   = substr_count( $post->post_content, 'data-dos-link="' . (int) $rule['id'] . '"' );

				if ( ! $placements && ! $existing ) {
					continue;
				}

				DOS_Links_Rules::add_stats( $rule['id'], count( $placements ), $existing, 1 );

				$total += count( $placements );
			}
		}

		if ( $total && count( $notes ) < 50 ) {
			$notes[] = sprintf(
				/* translators: 1: number of opportunities, 2: number of posts */
				__( '%1$d opportunities across %2$d posts in this batch.', 'dos-toolkit' ),
				$total,
				count( $posts )
			);
		}

		return array( 'processed' => count( $posts ), 'changed' => $total, 'notes' => $notes );
	}

	public static function apply_step( $offset, $size, $dry_run ) {
		$rules = DOS_Links_Rules::all( true );
		$posts = self::page_of_posts( $offset, $size );
		$notes = array();
		$count = 0;

		foreach ( $posts as $post ) {
			$result = DOS_Links_Engine::apply( $post->post_content, $rules, $post->ID );

			if ( ! $result['added'] ) {
				continue;
			}

			$added  = array_sum( $result['added'] );
			$count += $added;

			if ( count( $notes ) < 60 ) {
				$notes[] = sprintf(
					/* translators: 1: number of links, 2: post title */
					$dry_run ? __( 'Would add %1$d link(s) to "%2$s"', 'dos-toolkit' ) : __( 'Added %1$d link(s) to "%2$s"', 'dos-toolkit' ),
					$added,
					get_the_title( $post->ID )
				);
			}

			if ( ! $dry_run ) {
				self::write_content( $post->ID, $result['html'] );

				DOS_Log::add( 'links', 'links_added', sprintf( '%d added to "%s"', $added, get_the_title( $post->ID ) ), $post->ID );
			}
		}

		return array( 'processed' => count( $posts ), 'changed' => $count, 'notes' => $notes );
	}

	public static function remove_step( $offset, $size, $dry_run ) {
		$posts = self::page_of_posts( $offset, $size );
		$notes = array();
		$count = 0;

		foreach ( $posts as $post ) {
			$result = DOS_Links_Engine::strip( $post->post_content );

			if ( ! $result['removed'] ) {
				continue;
			}

			$count += $result['removed'];

			if ( count( $notes ) < 60 ) {
				$notes[] = sprintf(
					/* translators: 1: number of links, 2: post title */
					$dry_run ? __( 'Would remove %1$d link(s) from "%2$s"', 'dos-toolkit' ) : __( 'Removed %1$d link(s) from "%2$s"', 'dos-toolkit' ),
					$result['removed'],
					get_the_title( $post->ID )
				);
			}

			if ( ! $dry_run ) {
				self::write_content( $post->ID, $result['html'] );

				DOS_Log::add( 'links', 'links_removed', sprintf( '%d removed from "%s"', $result['removed'], get_the_title( $post->ID ) ), $post->ID );
			}
		}

		return array( 'processed' => count( $posts ), 'changed' => $count, 'notes' => $notes );
	}

	/**
	 * Write content without touching post_modified.
	 *
	 * wp_update_post() would stamp every post as modified today. The SEO
	 * module publishes dateModified, and the Utilities module shows a Last
	 * Updated column, so an automated pass over the whole site would tell
	 * search engines and editors alike that every page had just been revised.
	 * Adding a link is not a revision of the article.
	 */
	private static function write_content( $post_id, $html ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $html ),
			array( 'ID' => (int) $post_id ),
			array( '%s' ),
			array( '%d' )
		);

		clean_post_cache( (int) $post_id );
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( empty( $_POST['dos_action'] ) || ! current_user_can( DOS_Settings::capability() ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['dos_action'] ) );

		if ( 0 !== strpos( $action, 'links_' ) ) {
			return;
		}

		check_admin_referer( 'dos_links' );

		switch ( $action ) {
			case 'links_add':
				$result = DOS_Links_Rules::add(
					isset( $_POST['phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['phrase'] ) ) : '',
					isset( $_POST['target_id'] ) ? (int) $_POST['target_id'] : 0,
					array(
						'max_per_page'   => isset( $_POST['max_per_page'] ) ? (int) $_POST['max_per_page'] : 1,
						'first_instance' => isset( $_POST['first_instance'] ) ? sanitize_key( wp_unslash( $_POST['first_instance'] ) ) : 'link',
						'throttle'       => isset( $_POST['throttle'] ) ? (int) $_POST['throttle'] : 100,
					)
				);

				if ( is_wp_error( $result ) ) {
					set_transient( 'dos_links_error', $result->get_error_message(), 60 );
				} else {
					self::log( 'rule_saved', 'Link rule saved.' );
				}
				break;

			case 'links_delete':
				DOS_Links_Rules::delete( (int) $_POST['id'] );
				self::log( 'rule_deleted', 'Link rule deleted.' );
				break;

			case 'links_toggle':
				DOS_Links_Rules::set_enabled( (int) $_POST['id'], ! empty( $_POST['enabled'] ) );
				break;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'dos-links', 'dos_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		$error = get_transient( 'dos_links_error' );

		if ( $error ) {
			delete_transient( 'dos_links_error' );
		}

		$rules   = DOS_Links_Rules::all();
		$targets = get_posts(
			array(
				'post_type'      => self::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Internal Links', 'dos-toolkit' ); ?></h1>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php else : ?>
				<?php DOS_Admin::notice(); ?>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'A phrase, a page it should point at, and limits on how often it is used. Phrases are never linked inside headings, bold text, lists, tables, existing links, code or shortcodes, and a page never links to itself.', 'dos-toolkit' ); ?>
			</p>

			<h2><?php esc_html_e( 'Add a phrase', 'dos-toolkit' ); ?></h2>

			<form method="post">
				<?php wp_nonce_field( 'dos_links' ); ?>
				<input type="hidden" name="dos_action" value="links_add" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="phrase"><?php esc_html_e( 'Keyword phrase', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="text" id="phrase" name="phrase" class="regular-text" required placeholder="<?php esc_attr_e( 'roof repair phoenix', 'dos-toolkit' ); ?>" />
							<p class="description"><?php esc_html_e( 'Two words or more. Matched whole and without regard to case, so it will not match inside a longer word.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="target_id"><?php esc_html_e( 'Links to', 'dos-toolkit' ); ?></label></th>
						<td>
							<select id="target_id" name="target_id" required>
								<option value=""><?php esc_html_e( '— choose a page —', 'dos-toolkit' ); ?></option>
								<?php foreach ( $targets as $target ) : ?>
									<option value="<?php echo (int) $target->ID; ?>">
										<?php echo esc_html( $target->post_title ? $target->post_title : __( '(no title)', 'dos-toolkit' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_per_page"><?php esc_html_e( 'Links per page', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="number" id="max_per_page" name="max_per_page" value="1" min="1" max="20" class="small-text" />
							<p class="description"><?php esc_html_e( 'At most this many links from one page, however often the phrase appears on it.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'First occurrence', 'dos-toolkit' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:.4em">
								<input type="radio" name="first_instance" value="link" checked />
								<?php esc_html_e( 'Link the first occurrence on the page', 'dos-toolkit' ); ?>
							</label>
							<label style="display:block">
								<input type="radio" name="first_instance" value="skip" />
								<?php esc_html_e( 'Leave the first alone and link a later one', 'dos-toolkit' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="throttle"><?php esc_html_e( 'Use at most', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="number" id="throttle" name="throttle" value="100" min="0" max="100" class="small-text" /> %
							<p class="description"><?php esc_html_e( 'Of the places this phrase could be linked, link only this share of them. Lower it when one phrase is doing too much of the work. The same places are chosen every time, so the result does not move around between runs.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save phrase', 'dos-toolkit' ) ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Phrases in use', 'dos-toolkit' ); ?></h2>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Phrase', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Links to', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Limits', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Links made', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Share of all links', 'dos-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Still available', 'dos-toolkit' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rules ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No phrases yet.', 'dos-toolkit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rules as $rule ) : ?>
						<?php $share = DOS_Links_Rules::share( $rule, $rules ); ?>
						<tr<?php echo $rule['enabled'] ? '' : ' style="opacity:.5"'; ?>>
							<td><strong><?php echo esc_html( $rule['phrase'] ); ?></strong></td>
							<td><?php echo esc_html( get_the_title( (int) $rule['target_id'] ) ); ?></td>
							<td class="description">
								<?php
								printf(
									/* translators: 1: links per page, 2: first-occurrence behaviour, 3: throttle percentage */
									esc_html__( '%1$d per page, %2$s, %3$d%%', 'dos-toolkit' ),
									(int) $rule['max_per_page'],
									'skip' === $rule['first_instance'] ? esc_html__( 'not the first', 'dos-toolkit' ) : esc_html__( 'first allowed', 'dos-toolkit' ),
									(int) $rule['throttle']
								);
								?>
							</td>
							<td><strong><?php echo (int) $rule['links_made']; ?></strong></td>
							<td>
								<?php echo esc_html( $share ); ?>%
								<?php if ( $share >= 40 && count( $rules ) > 1 ) : ?>
									<br><span class="dos-media-warning"><?php esc_html_e( 'doing most of the work', 'dos-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo (int) $rule['opportunities']; ?></td>
							<td>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'dos_links' ); ?>
									<input type="hidden" name="dos_action" value="links_toggle" />
									<input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>" />
									<input type="hidden" name="enabled" value="<?php echo $rule['enabled'] ? '0' : '1'; ?>" />
									<button type="submit" class="button-link"><?php echo $rule['enabled'] ? esc_html__( 'Disable', 'dos-toolkit' ) : esc_html__( 'Enable', 'dos-toolkit' ); ?></button>
								</form>
								&nbsp;
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'dos_links' ); ?>
									<input type="hidden" name="dos_action" value="links_delete" />
									<input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>" />
									<button type="submit" class="button-link"><?php esc_html_e( 'Delete', 'dos-toolkit' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( '"Links made" counts links currently in your content. "Still available" is how many more the rule could place if applied now. Both come from the scan, so run it after any change to see current numbers.', 'dos-toolkit' ); ?>
			</p>

			<hr>
			<h2><?php esc_html_e( 'Run', 'dos-toolkit' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Scan first, read what it found, then apply. Applying writes links into your posts and pages; the dry run names every page it would change before anything is written.', 'dos-toolkit' ); ?>
			</p>

			<?php
			foreach ( array( 'links_scan', 'links_apply', 'links_remove' ) as $job ) {
				DOS_Batch::render_runner( $job );
			}
			?>
		</div>
		<?php
	}
}
